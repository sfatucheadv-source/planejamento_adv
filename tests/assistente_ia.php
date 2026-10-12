<?php
/**
 * Assistente com IA (OpenRouter): leitura de documentos, perguntas só do que
 * falta, respostas a mensagens livres e configuração (modelo e chave).
 * Usa um simulador local da API (tests/mock_openrouter.php): sem rede e sem custo.
 *
 * Uso: php tests/assistente_ia.php
 *
 * (cabeçalho comum copiado de tests/atendimento_inicial.php)
 * Percorre pela interface HTTP: entrada pelo assistente, coleta, conferência,
 * análise, reunião, kit, decisão, assinatura, execução, encerramento,
 * carência de 72 horas, retorno vinculado, isolamento e migração.
 *
 * */
declare(strict_types=1);

$root = dirname(__DIR__);
$db = sys_get_temp_dir() . '/fatuche-ia-' . getmypid() . '.sqlite';
@unlink($db);
putenv('FATUCHE_DB=' . $db);
require $root . '/app/bootstrap.php';

$codeFile = config('storage_dir') . '/codigo-instalacao.txt';
@unlink($codeFile);

$port = 20000 + random_int(0, 900);
$cmd = sprintf('FATUCHE_DB=%s php -S 127.0.0.1:%d -t %s %s > /dev/null 2>&1 & echo $!', escapeshellarg($db), $port, escapeshellarg($root . '/public'), escapeshellarg($root . '/public/index.php'));
$pid = (int) shell_exec($cmd);
$mockPort = $port + 1000;
$mockPid = (int) shell_exec(sprintf('php -S 127.0.0.1:%d %s > /dev/null 2>&1 & echo $!', $mockPort, escapeshellarg($root . '/tests/mock_openrouter.php')));
$mockLog = sys_get_temp_dir() . '/mock-openrouter-' . $mockPort . '.log';
@unlink($mockLog);
register_shutdown_function(function () use ($pid, $db, $mockPid, $mockLog) {
    if ($pid) {
        posix_kill($pid, SIGTERM);
    }
    if ($mockPid) {
        posix_kill($mockPid, SIGTERM);
    }
    @unlink($mockLog);
    @unlink($db);
    @unlink($db . '-wal');
    @unlink($db . '-shm');
});
$base = "http://127.0.0.1:$port";
for ($i = 0; $i < 50 && !@file_get_contents($base . '/robots.txt'); $i++) {
    usleep(100000);
}

$fails = 0;
$passes = 0;
function ok(bool $cond, string $msg): void
{
    global $fails, $passes;
    if ($cond) {
        $passes++;
        echo "  ✓ $msg\n";
    } else {
        $fails++;
        echo "  ✗ $msg\n";
    }
}

final class Browser
{
    public string $html = '';
    public int $status = 0;
    public string $location = '';
    public string $url = '';
    private string $jar;

    public function __construct(private string $base)
    {
        $this->jar = tempnam(sys_get_temp_dir(), 'jar');
    }

    public function get(string $path): string
    {
        return $this->req('GET', $path);
    }

    public function post(string $path, array $data, bool $follow = true, array $files = []): string
    {
        if (!isset($data['_csrf'])) {
            $data['_csrf'] = $this->csrf();
        }
        foreach ($files as $k => $f) {
            $data[$k] = new CURLFile($f[0], $f[1], basename($f[0]));
        }
        return $this->req('POST', $path, $data, $follow, (bool) $files);
    }

    public function csrf(): string
    {
        if (preg_match('/name="_csrf" value="([^"]+)"/', $this->html, $m)) {
            return $m[1];
        }
        $this->get('/comecar');
        preg_match('/name="_csrf" value="([^"]+)"/', $this->html, $m);
        return $m[1] ?? '';
    }

    private function req(string $method, string $path, array $data = [], bool $follow = true, bool $multipart = false): string
    {
        $ch = curl_init($this->base . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar,
            CURLOPT_FOLLOWLOCATION => $follow, CURLOPT_HEADER => false,
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $multipart ? $data : http_build_query($data));
        }
        $this->html = (string) curl_exec($ch);
        $this->status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $this->location = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        $this->url = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        return $this->html;
    }
}

function c(int $id): array
{
    return one('SELECT * FROM cases WHERE id = ?', [$id]);
}

function field(int $id, string $f): ?array
{
    return one('SELECT * FROM case_fields WHERE case_id = ? AND field = ?', [$id, $f]);
}

/** Ação na ficha do operador. */
function op(Browser $b, int $id, array $data, array $files = []): void
{
    $b->get('/painel/atendimento/' . $id);
    if (!isset($data['row_version']) && preg_match('/name="row_version" value="(\d+)"/', $b->html, $m)) {
        $data['row_version'] = $m[1];
    }
    $b->post('/painel/atendimento/' . $id, $data, true, $files);
}

function flash_error(Browser $b): string
{
    return preg_match('/flash-error">([^<]+)/', $b->html, $m) ? html_entity_decode($m[1]) : '';
}

/** Pergunta da vez no chat do cliente: [chave, tipo, id do documento]. */
function current_q(Browser $b): ?array
{
    $b->get('/cliente?area=conversa');
    if (preg_match('#cliente/conversa/documento/(\d+)"#', $b->html, $m) && str_contains($b->html, 'id="pergunta"') && preg_match('#id="pergunta".*?conversa/documento/(\d+)#s', $b->html, $n)) {
        return ['doc', 'doc', (int) $n[1]];
    }
    if (preg_match('#id="pergunta".*?name="q" value="([a-z_]+)"#s', $b->html, $m)) {
        return [$m[1], 'q', 0];
    }
    return null;
}

$pdf = tempnam(sys_get_temp_dir(), 'doc') . '.pdf';
file_put_contents($pdf, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
$txt = tempnam(sys_get_temp_dir(), 'doc') . '.txt';
file_put_contents($txt, "texto simples, não é PDF\n");
$jpg = tempnam(sys_get_temp_dir(), 'img') . '.jpg';
$im = imagecreatetruecolor(20, 20);
imagejpeg($im, $jpg);

echo "\nConfiguração da IA (modelo e chave)\n";
$sid = new Browser($base);
$sid->get('/painel/instalar');
$code = trim((string) file_get_contents($codeFile));
$sid->post('/painel/instalar', ['code' => $code, 'name' => 'Sidney Teste', 'email' => 'sidney@teste.local', 'password' => 'senha-do-sidney-1', 'password2' => 'senha-do-sidney-1']);
set_setting('llm_endpoint', "http://127.0.0.1:$mockPort/api/v1");
set_setting('agent_enabled', '0'); // este teste cobre o roteiro com botões + leitura pela IA; a conversa com IA está em assistente_conversa.php
for ($i = 0; $i < 30 && !@file_get_contents("http://127.0.0.1:$mockPort/api/v1/models"); $i++) {
    usleep(100000);
}
$sid->get('/painel/configuracoes');
ok(str_contains($sid->html, 'Inteligência artificial') && str_contains($sid->html, 'Google Gemini (API direta)') && str_contains($sid->html, '(não configurada)'), 'Configurações mostram a IA com os dois fornecedores, sem chave');
$cfg = ['operator_user_id' => (string) scalar('SELECT id FROM users LIMIT 1'), 'intake_workflow' => 'simple_v1', 'office_address' => 'Rua X, 1', 'llm_enabled' => '1', 'llm_provider' => 'openrouter', 'llm_model' => 'google/gemini-2.5-flash'];
$sid->post('/painel/configuracoes', $cfg + ['llm_api_key' => 'chave-errada']);
setting('__reset');
ok(llm_key() === null && str_contains($sid->html, 'sk-or-'), 'chave em formato inválido é recusada');
$sid->post('/painel/configuracoes', $cfg + ['llm_api_key' => 'sk-or-v1-invalida-1111', 'llm_test' => '1']);
ok(str_contains($sid->html, 'Teste da IA') && str_contains($sid->html, '401'), 'teste de conexão mostra o erro do OpenRouter para chave inválida');
$sid->post('/painel/configuracoes', $cfg + ['llm_api_key' => 'sk-or-v1-teste-valido-0000', 'llm_test' => '1']);
setting('__reset');
ok(llm_enabled() && llm_model() === 'google/gemini-2.5-flash' && str_contains($sid->html, 'Conexão funcionando'), 'chave válida salva, modelo escolhido e teste aprovado');
ok(!str_contains($sid->html, 'sk-or-v1-teste-valido-0000') && str_contains($sid->html, '…0000'), 'a chave nunca volta para a tela (só o final)');
$sid->post('/painel/configuracoes', array_merge($cfg, ['llm_model' => 'anthropic/claude-sonnet-4.5', 'llm_api_key' => '']));
setting('__reset');
ok(llm_model() === 'anthropic/claude-sonnet-4.5' && llm_key() === 'sk-or-v1-teste-valido-0000', 'trocar o modelo mantém a chave quando o campo fica em branco');
$sid->post('/painel/configuracoes', $cfg + ['llm_api_key' => '']);
$sid->get('/painel/configuracoes?modelos=1');
ok(str_contains($sid->html, 'value="google/gemini-2.5-flash"') && !str_contains($sid->html, 'texto/so-texto'), 'lista de modelos do OpenRouter traz só os que leem imagem ou arquivo');

echo "\nAssistente: documentos primeiro e leitura pela IA\n";
$cli = new Browser($base);
$cli->get('/comecar');
ok(str_contains($cli->html, 'inteligência artificial'), 'aviso de uso de IA no consentimento');
preg_match('/name="form_key" value="([^"]+)"/', $cli->html, $fk);
sleep(3);
$cli->post('/comecar', ['nome' => 'Maria Souza', 'telefone' => '(41) 98888-1111', 'email' => 'maria@teste.local', 'consent' => '1', 'website' => '', 'form_key' => $fk[1]]);
$id = (int) scalar('SELECT MAX(id) FROM cases');
$asked = [];
$answers = [
    'aviso' => ['value' => 'nenhum'], 'objetivo_prioridade' => ['value' => 'reduzir_parcela'], 'objetivo_texto' => ['mode' => 'skip'],
    'resumo_confirmado' => ['value' => 'sim'], 'situacao_contrato' => ['value' => 'ativo'], 'atraso' => ['value' => 'nao'],
    'processo_existente' => ['value' => 'nao'], 'outro_advogado' => ['value' => 'nao'], 'relacao_contrato' => ['value' => 'titular'],
    'profissao' => ['value' => 'Motorista'], 'gratuidade' => ['value' => 'nao'], 'reuniao_canal' => ['value' => 'site_chat'],
    'reuniao_disponibilidade' => ['mode' => 'skip'], 'final' => [],
];
$firstDocAfter = null;
$messageSent = false;
for ($guard = 0; $guard < 50; $guard++) {
    $q = current_q($cli);
    if (!$q) {
        break;
    }
    if ($q[0] === 'doc') {
        $kind = scalar('SELECT kind FROM documents WHERE id = ?', [$q[2]]);
        $firstDocAfter ??= end($asked);
        $asked[] = 'doc_' . $kind;
        preg_match('#conversa/documento/' . $q[2] . '".*?name="k" value="([^"]+)"#s', $cli->html, $km);
        $file = $kind === 'residencia' ? [$jpg, 'image/jpeg'] : [$pdf, 'application/pdf'];
        $cli->post('/cliente/conversa/documento/' . $q[2], ['choice' => 'enviar', 'k' => $km[1] ?? ''], true, ['arquivo[]' => $file]);
        continue;
    }
    $key = $q[0];
    $asked[] = $key;
    if (!isset($answers[$key])) {
        echo "    (pergunta feita: $key)\n";
        $cli->post('/cliente/conversa/responder', ['q' => $key, 'mode' => 'later', 'value' => '']);
        $cli->post('/cliente/conversa/responder', ['q' => $key, 'mode' => 'unknown', 'value' => '']);
        continue;
    }
    if ($key === 'situacao_contrato' && !$messageSent) {
        $cli->post('/cliente/conversa/mensagem', ['message' => 'Tenho duas parcelas atrasadas, isso é ilegal?', 'k' => 'm1']);
        $messageSent = true;
    }
    preg_match('#name="q" value="' . $key . '"><input type="hidden" name="k" value="([^"]+)"#', $cli->html, $km);
    $cli->post('/cliente/conversa/responder', ['q' => $key, 'k' => $km[1] ?? ''] + $answers[$key]);
}
$f = fn(string $k) => one('SELECT * FROM case_fields WHERE case_id = ? AND field = ?', [$id, $k]);
ok($firstDocAfter === 'resumo_confirmado' && ($asked[array_search('resumo_confirmado', $asked, true) + 1] ?? '') === 'doc_contrato', 'com IA, o contrato é pedido logo depois do objetivo');
ok($f('capital')['value'] === '4000000' && $f('capital')['source'] === 'ia' && (int) $f('capital')['document_id'] > 0, 'valor financiado extraído do contrato, com origem “IA” e documento de referência');
ok($f('taxa_mensal')['value'] === '1.99' && $f('contrato_numero')['value'] === '000123ABC' && $f('cpf')['value'] === '52998224725', 'taxa, número do contrato e CPF normalizados');
ok($f('logradouro')['value'] === 'Rua das Flores' && $f('uf')['value'] === 'PR', 'endereço lido da foto do comprovante');
ok(!array_intersect(['banco', 'capital', 'prazo', 'parcela', 'taxa_mensal', 'cet', 'contrato_data', 'nome_completo', 'cpf', 'estado_civil', 'endereco', 'parcelas_pagas'], $asked), 'o assistente não pergunta o que já estava nos documentos');
ok(in_array('profissao', $asked, true), 'pergunta só o que os documentos não trazem (profissão)');
ok(!$f('instrucao_maliciosa') && $f('parcela')['value'] === '125000', 'chaves fora da lista são ignoradas; valor de outro documento não sobrescreve o do contrato');
ok((bool) scalar("SELECT 1 FROM chat_messages WHERE case_id = ? AND author = 'bot' AND body LIKE 'Li o documento e já anotei%'", [$id]), 'cliente vê o que foi lido e pode corrigir');
ok((bool) scalar("SELECT 1 FROM events WHERE case_id = ? AND title LIKE 'IA leu:%' AND body LIKE '%Divergências%'", [$id]), 'divergência entre documentos vira aviso para Sidney');
$log = array_map(fn($l) => json_decode($l, true), file($mockLog, FILE_IGNORE_NEW_LINES));
$ext = array_values(array_filter($log, fn($l) => $l['parts']));
ok($ext && in_array('file', $ext[0]['parts'], true) && in_array('image_url', array_merge(...array_column($ext, 'parts')), true), 'PDF enviado como arquivo e foto como imagem ao modelo');
ok($ext[0]['model'] === 'google/gemini-2.5-flash', 'usa o modelo escolhido nas Configurações');

echo "\nMensagem livre respondida pela IA\n";
ok((bool) scalar("SELECT 1 FROM chat_messages WHERE case_id = ? AND author = 'bot' AND body LIKE 'Entendi. Sidney vai responder%'", [$id]), 'mensagem fora do roteiro recebe resposta da IA, sem orientação jurídica');
ok($f('atraso_qtd') && $f('atraso_qtd')['value'] === '2', 'dado dito na conversa vai para a ficha');
ok(!$f('proposta_honorarios'), 'IA não preenche campos de Sidney');
ok((int) scalar('SELECT COUNT(*) FROM llm_log WHERE case_id = ? AND ok = 1', [$id]) >= 5 && (float) scalar('SELECT SUM(cost) FROM llm_log') > 0, 'chamadas e custo registrados');

echo "\nPainel: conferência dos dados da IA\n";
$sid->get('/painel/atendimento/' . $id);
ok(str_contains($sid->html, 'IA · conferir') && str_contains($sid->html, 'Ler com IA'), 'ficha marca os dados da IA e permite reler um documento');
preg_match('/name="row_version" value="(\d+)"/', $sid->html, $rv);
$sid->post('/painel/atendimento/' . $id, ['op' => 'ia_conferir', 'group' => 'financiamento', 'row_version' => $rv[1]]);
ok($f('capital')['source'] === 'contrato' && $f('cpf')['source'] === 'ia', 'Sidney confirma por grupo; os demais continuam “conferir”');
$sid->post('/painel/atendimento/' . $id, ['op' => 'ia_extrair', 'doc_id' => scalar("SELECT id FROM documents WHERE case_id = ? AND kind = 'contrato'", [$id])]);
ok(str_contains($sid->html, 'IA leu o documento') && $f('capital')['source'] === 'contrato', 'reler não sobrescreve dado já conferido');

echo "\nFornecedor Google Gemini (API direta)\n";
set_setting('gemini_endpoint', "http://127.0.0.1:$mockPort/v1beta");
$gcfg = array_merge($cfg, ['llm_provider' => 'gemini', 'gemini_model' => 'models/gemini-2.5-flash']);
$sid->post('/painel/configuracoes', $gcfg + ['gemini_api_key' => 'AQ.chave-errada-0000000000000', 'llm_test' => '1']);
setting('__reset');
ok(llm_provider() === 'gemini' && llm_model() === 'gemini-2.5-flash' && str_contains($sid->html, 'API key not valid'), 'Gemini selecionado; chave inválida mostra o erro do Google');
$sid->post('/painel/configuracoes', $gcfg + ['gemini_api_key' => 'AQ.teste-gemini-valido-0000000000', 'llm_test' => '1']);
setting('__reset');
ok(str_contains($sid->html, 'Conexão funcionando: Google Gemini') && llm_key('openrouter') === 'sk-or-v1-teste-valido-0000', 'chave do Gemini aprovada; a do OpenRouter continua guardada');
ok(!str_contains($sid->html, 'AQ.teste-gemini-valido-0000000000') && str_contains($sid->html, 'em uso'), 'chave do Gemini não volta para a tela');
$sid->get('/painel/configuracoes?modelos=gemini');
ok(str_contains($sid->html, 'value="gemini-2.5-flash"') && !str_contains($sid->html, 'text-embedding-004'), 'lista do Gemini traz só modelos de geração de conteúdo');
$before = count(file($mockLog));
$cliG = new Browser($base);
$cliG->get('/comecar');
preg_match('/name="form_key" value="([^"]+)"/', $cliG->html, $fk);
sleep(3);
$cliG->post('/comecar', ['nome' => 'Rita Souza', 'telefone' => '(41) 96666-1111', 'email' => 'rita@teste.local', 'consent' => '1', 'website' => '', 'form_key' => $fk[1], 'aviso' => 'nenhum']);
$idG = (int) scalar('SELECT MAX(id) FROM cases');
$docG = (int) scalar("SELECT id FROM documents WHERE case_id = ? AND kind = 'contrato'", [$idG]);
$cliG->post('/cliente/conversa/documento/' . $docG, ['choice' => 'enviar', 'k' => 'g1'], true, ['arquivo[]' => [$pdf, 'application/pdf']]);
$gl = array_map(fn($l) => json_decode($l, true), array_slice(file($mockLog, FILE_IGNORE_NEW_LINES), $before));
$gx = array_values(array_filter($gl, fn($l) => $l['provider'] === 'gemini' && $l['parts']));
ok($gx && in_array('inline:application/pdf', $gx[0]['parts'], true) && $gx[0]['json'] === true && $gx[0]['model'] === 'gemini-2.5-flash', 'contrato enviado ao Gemini como PDF (inline_data), com resposta em JSON');
$fg = one("SELECT * FROM case_fields WHERE case_id = ? AND field = 'capital'", [$idG]);
ok($fg && $fg['value'] === '4000000' && $fg['source'] === 'ia', 'Gemini extrai os dados do contrato para a ficha');
$cliG->post('/cliente/conversa/mensagem', ['message' => 'Tenho 2 atrasadas', 'k' => 'g2']);
ok((bool) scalar("SELECT 1 FROM chat_messages WHERE case_id = ? AND author = 'bot' AND body LIKE 'Entendi. Sidney vai responder%'", [$idG]) && (bool) scalar("SELECT 1 FROM llm_log WHERE model = 'gemini:gemini-2.5-flash' AND kind = 'conversa' AND ok = 1"), 'mensagem livre respondida pelo Gemini e registrada com o fornecedor');
set_setting('llm_provider', 'openrouter');

echo "\nSem IA (ou com falha) o roteiro continua\n";
set_setting('llm_api_key', 'sk-or-v1-invalida-2222');
$cli2 = new Browser($base);
$cli2->get('/comecar');
preg_match('/name="form_key" value="([^"]+)"/', $cli2->html, $fk);
sleep(3);
$cli2->post('/comecar', ['nome' => 'João Souza', 'telefone' => '(41) 97777-1111', 'email' => 'joao@teste.local', 'consent' => '1', 'website' => '', 'form_key' => $fk[1], 'aviso' => 'nenhum']);
$cli2->post('/cliente/conversa/mensagem', ['message' => 'Oi, posso falar com alguém?', 'k' => 'x1']);
$id2 = (int) scalar('SELECT MAX(id) FROM cases');
ok((bool) scalar("SELECT 1 FROM chat_messages WHERE case_id = ? AND body LIKE 'Obrigado. Sua mensagem foi registrada%'", [$id2]), 'falha da IA usa a resposta padrão; o cliente não fica sem retorno');
set_setting('llm_enabled', '0');
$q = current_q($cli2);
ok($q && $q[0] === 'objetivo_prioridade', 'IA desligada: volta o roteiro sem IA');

echo "\n" . ($fails ? "FALHAS: $fails" : 'TUDO OK') . " · $passes verificações aprovadas\n";
exit($fails ? 1 : 0);
