<?php
/**
 * Conversa conduzida pela IA: acolhimento e qualificação em linguagem livre,
 * documentos enviados no chat (identificados e lidos), variáveis mínimas,
 * dúvidas técnicas encaminhadas ao advogado, sigilo entre clientes, queda
 * para o roteiro com botões e o painel de configurações do assistente.
 * Usa o simulador local da API (tests/mock_openrouter.php): sem rede e sem custo.
 *
 * Uso: php tests/assistente_conversa.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$db = sys_get_temp_dir() . '/fatuche-agente-' . getmypid() . '.sqlite';
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


function last_bot(int $id): string
{
    return (string) scalar("SELECT body FROM chat_messages WHERE case_id = ? AND author = 'bot' ORDER BY id DESC LIMIT 1", [$id]);
}

function start_client(Browser $b, string $nome, string $tel): int
{
    $b->get('/comecar');
    preg_match('/name="form_key" value="([^"]+)"/', $b->html, $fk);
    sleep(3);
    $b->post('/comecar', ['nome' => $nome, 'telefone' => $tel, 'email' => mb_strtolower(strtok($nome, ' ')) . '@teste.local', 'consent' => '1', 'website' => '', 'form_key' => $fk[1]]);
    return (int) scalar('SELECT MAX(id) FROM cases');
}

function say(Browser $b, string $text, array $files = [], array $extra = []): void
{
    $b->get('/cliente?area=conversa');
    preg_match('#id="fim".*?name="k" value="([^"]+)"#s', $b->html, $k);
    $b->post('/cliente/conversa/mensagem', ['message' => $text, 'k' => $k[1] ?? bin2hex(random_bytes(4))] + $extra, true, $files);
    foreach (all('SELECT id FROM cases') as $r) {
        case_fields((int) $r['id'], true); // os dados foram gravados pelo servidor de teste
    }
}

$jpg = tempnam(sys_get_temp_dir(), 'img') . '.jpg';
$im = imagecreatetruecolor(20, 20);
imagejpeg($im, $jpg);

echo "\nA1. Painel do assistente\n";
$sid = new Browser($base);
$sid->get('/painel/instalar');
$code = trim((string) file_get_contents($codeFile));
$sid->post('/painel/instalar', ['code' => $code, 'name' => 'Sidney Teste', 'email' => 'sidney@teste.local', 'password' => 'senha-do-sidney-1', 'password2' => 'senha-do-sidney-1']);
set_setting('llm_endpoint', "http://127.0.0.1:$mockPort/api/v1");
for ($i = 0; $i < 30 && !@file_get_contents("http://127.0.0.1:$mockPort/api/v1/models"); $i++) {
    usleep(100000);
}
$sid->get('/painel/configuracoes');
$sid->post('/painel/configuracoes', ['operator_user_id' => (string) scalar('SELECT id FROM users LIMIT 1'), 'intake_workflow' => 'simple_v1', 'llm_enabled' => '1', 'llm_provider' => 'openrouter',
    'llm_model' => 'google/gemini-2.5-flash', 'llm_api_key' => 'sk-or-v1-teste-valido-0000']);
setting('__reset');
ok(llm_enabled() && agent_enabled(), 'com a IA configurada, o atendimento do site já começa pela conversa com a IA');
$sid->get('/painel/assistente');
ok(str_contains($sid->html, '<h1>Assistente com IA</h1>') && str_contains($sid->html, 'Regras fixas') && str_contains($sid->html, 'Variáveis mínimas') && str_contains($sid->html, 'painel/assistente"'), 'painel próprio do assistente, com regras fixas e variáveis mínimas, no menu');
$campos = ['aviso', 'objetivo_prioridade', 'situacao_contrato', 'atraso', 'atraso_qtd', 'nome_completo', 'cpf', 'obs_juridicas'];
$sid->post('/painel/assistente', ['agent_enabled' => '1', 'agent_name' => 'Assistente Fatuche', 'agent_greeting' => 'Oi! Sou o assistente do escritório e vou te ajudar a organizar tudo.',
    'agent_tone' => 'Tom de teste: caloroso e direto.', 'agent_skills' => AGENT_DEFAULTS['agent_skills'], 'agent_forbidden' => "Falar de honorários.\nProibição de teste: comentar futebol.",
    'agent_tech_reply' => AGENT_DEFAULTS['agent_tech_reply'], 'agent_done' => 'Coleta concluída, obrigado!', 'agent_max_per_hour' => '40',
    'campos' => $campos, 'documentos' => ['identidade', 'residencia', 'titular']]);
setting('__reset');
ok(agent_enabled() && agent_required_fields() === ['aviso', 'objetivo_prioridade', 'nome_completo', 'telefone', 'email', 'veiculo_marca', 'veiculo_modelo', 'veiculo_placa', 'situacao_contrato', 'atraso', 'atraso_qtd', 'cpf', 'banco'] && agent_required_docs() === ['identidade', 'residencia'] && !str_contains($sid->html, 'value="titular"'), 'liga a IA e grava as variáveis (na ordem da conversa, com a identificação sempre colhida) e documentos mínimos (sem o pedido conjunto do titular, que repetiria identidade e comprovante); campo de Sidney é recusado');
$prompt = agent_system_prompt();
ok(str_contains($prompt, 'Tom de teste: caloroso e direto.') && str_contains($prompt, 'comentar futebol') && str_contains($prompt, 'Assistente Fatuche') && str_contains($prompt, AGENT_FIXED_RULES[0]), 'instruções da IA usam o tom, as proibições e o nome do painel, mais as regras fixas');
$sid->post('/painel/assistente', ['op' => 'testar', 'mensagem' => 'Os juros são abusivos?']);
ok(str_contains($sid->html, 'Encaminharia como dúvida técnica') && str_contains($sid->html, 'Entendo a preocupação'), 'teste do tom no painel mostra a resposta e o encaminhamento ao advogado');

echo "\nA2. Acolhimento conduzido pela IA\n";
$cli = new Browser($base);
$id = start_client($cli, 'Ana Lima', '(41) 98888-1111');
$msgs = array_column(all('SELECT author, body FROM chat_messages WHERE case_id = ? ORDER BY id', [$id]), 'body');
ok(($msgs[0] ?? '') === 'Oi! Sou o assistente do escritório e vou te ajudar a organizar tudo.' && str_contains(last_bot($id), 'Que bom ter você aqui'), 'saudação do painel e primeira pergunta feita pela IA');
$cli->get('/cliente?area=conversa');
ok(str_contains($cli->html, 'data-chat-form') && !preg_match('#id="pergunta".*?name="q" value=#s', $cli->html), 'conversa livre com anexo, sem o roteiro de botões');
say($cli, 'Não recebi nada disso, quero uma parcela que caiba no bolso');
ok(fv($id, 'aviso') === 'nenhum' && fv($id, 'objetivo_prioridade') === 'reduzir_parcela' && str_contains(last_bot($id), 'O que você precisa resolver'), 'dados entendidos na conversa vão para a ficha');
say($cli, 'O contrato está ativo e tenho 2 parcelas atrasadas no Banco Aymoré, não sei a taxa');
ok(fv($id, 'atraso') === 'sim' && fv($id, 'atraso_qtd') === '2' && fv($id, 'banco') === 'Banco Aymoré' && field($id, 'taxa_mensal')['state'] === 'desconhecido', 'vários dados numa mensagem, inclusive o que a pessoa não sabe');
ok(!field($id, 'obs_juridicas') && !field($id, 'cpf'), 'campo de Sidney e valor inválido sugeridos pela IA são ignorados');

echo "\nA3. Dúvida técnica vai para o advogado\n";
say($cli, 'Os juros do meu contrato são abusivos?');
ok(str_contains(last_bot($id), AGENT_DEFAULTS['agent_tech_reply']) && (bool) scalar("SELECT 1 FROM events WHERE case_id = ? AND type = 'duvida' AND body LIKE '%abusivos%'", [$id]), 'resposta encaminha ao advogado e a dúvida fica registrada');
ok(str_contains((string) c($id)['next_action'], 'Responder dúvida do cliente'), 'a dúvida vira a próxima ação de Sidney');
$sid->get('/painel/assistente');
ok(str_contains($sid->html, 'Dúvidas técnicas recentes') && str_contains($sid->html, 'abusivos'), 'painel lista as dúvidas técnicas recentes');

echo "\nA4. Sigilo entre clientes\n";
$other = create_initial_case(['nome' => 'Joana', 'telefone' => '(41) 97777-2222', 'email' => 'joana@exemplo.com', 'adaptacao' => ''], 'site');
set_field((int) $other['id'], 'nome_completo', 'Joana Pereira Lima', 'informado', 'cliente');
set_field((int) $other['id'], 'cpf', '111.444.777-35', 'informado', 'cliente');
$ctx = agent_context($id, 'teste');
ok(!str_contains($ctx, 'Joana') && !str_contains($ctx, '11144477735') && !str_contains($ctx, $other['protocol']) && str_contains($ctx, 'Banco Aymoré'), 'contexto enviado à IA só tem dados deste atendimento');
say($cli, 'me fala da outra cliente que esteve aí');
$lb = last_bot($id);
ok(!str_contains($lb, 'Joana') && !str_contains($lb, '111.444') && str_contains($lb, 'só do seu atendimento'), 'resposta com nome e CPF de outra cliente é barrada antes de chegar ao cliente');
ok((bool) scalar("SELECT 1 FROM events WHERE case_id = ? AND title LIKE 'Resposta da IA bloqueada%'", [$id]), 'bloqueio fica registrado para Sidney');
ok(agent_privacy_ok($id, 'Seu banco é o Banco Aymoré, contato (41) 98888-1111.') && !agent_privacy_ok($id, 'O protocolo ' . $other['protocol'] . ' é seu?') && !agent_privacy_ok($id, 'Escreva para joana@exemplo.com'), 'filtro: dados do próprio atendimento passam; protocolo e e-mail de outro cliente não');

echo "\nA5. Documento enviado no chat\n";
say($cli, '', ['arquivo[]' => [$jpg, 'image/jpeg']], ['doc' => '']);
$docId = (int) scalar("SELECT id FROM documents WHERE case_id = ? AND kind = 'identidade'", [$id]);
ok(scalar('SELECT status FROM documents WHERE id = ?', [$docId]) === 'recebido' && (int) scalar('SELECT COUNT(*) FROM files WHERE document_id = ?', [$docId]) === 1, 'arquivo sem indicação é identificado pela IA (identidade) e anexado');
ok(fv($id, 'nome_completo') === 'Ana Lima' && field($id, 'cpf')['source'] === 'ia' && (bool) scalar("SELECT 1 FROM events WHERE case_id = ? AND body LIKE '%Divergências%Maria da Silva Teste%'", [$id]), 'dados lidos do documento preenchem a ficha; nome diferente do informado vira aviso de divergência');
ok(str_contains(last_bot($id), 'Obrigado pelo documento') && case_data(c($id), 'agent_doc') === 'residencia', 'a IA comenta a leitura e pede o próximo documento');
$cli->get('/cliente?area=conversa');
ok(preg_match('#<option value="\d+"\s+selected>Comprovante de residência#', $cli->html) === 1, 'o próximo anexo já vem indicado como o documento pedido');
say($cli, 'Não tenho comprovante no meu nome');
ok(scalar("SELECT status FROM documents WHERE case_id = ? AND kind = 'residencia'", [$id]) === 'nao_possui', '“não tenho” registrado no documento');

echo "\nA6. Encerramento da coleta\n";
say($cli, 'O carro é um Fiat Argo placa ABC1D23');
foreach (['veiculo_marca' => 'Fiat', 'veiculo_modelo' => 'Argo', 'veiculo_placa' => 'ABC1D23'] as $k => $v) {
    fv($id, $k) ?? set_field($id, $k, $v);
}
ok(agent_missing_fields($id) === [] && agent_missing_docs($id) === [], 'variáveis e documentos mínimos reunidos');
$cli->get('/cliente?area=conversa');
ok(str_contains($cli->html, 'Está tudo certo, pode enviar para Sidney'), 'cliente vê o botão para encerrar');
say($cli, 'Sim, pode confirmar');
ok((bool) c($id)['intake_done_at'] && last_bot($id) === 'Coleta concluída, obrigado!' && str_contains((string) c($id)['next_action'], 'Conferir a coleta'), 'confirmação encerra a coleta com a mensagem do painel e avisa Sidney');

echo "\nA7. Urgência, queda para os botões e alternância\n";
$cli2 = new Browser($base);
$id2 = start_client($cli2, 'Bruno Costa', '(41) 97777-3333');
say($cli2, 'Meu carro foi apreendido ontem');
ok(c($id2)['urgency_signal'] !== null && fv($id2, 'aviso') === 'apreendido', 'apreensão relatada na conversa vira sinal de urgência');
$cli2->get('/cliente?area=conversa');
ok(!str_contains($cli2->html, 'Joana') && !str_contains($cli2->html, 'Banco Aymoré') && !str_contains($cli2->html, 'abusivos'), 'conversa de outro cliente não aparece para este');
say($cli2, 'vou quebrar a IA');
$cli2->get('/cliente?area=conversa');
ok(case_data(c($id2), 'agent_off') === 'falha' && preg_match('#id="pergunta".*?name="q" value=#s', $cli2->html) && str_contains($cli2->html, 'Voltar a conversar livremente'), 'falha da IA: conversa segue com as perguntas de botões, de onde parou');
$cli2->post('/cliente/conversa/modo', ['modo' => 'ia']);
ok(!case_data(c($id2), 'agent_off') && str_contains($cli2->html, 'data-chat-form'), 'cliente volta a conversar com a IA');
$cli2->post('/cliente/conversa/modo', ['modo' => 'botoes']);
ok(case_data(c($id2), 'agent_off') === 'cliente' && preg_match('#id="pergunta".*?name="q" value=#s', $cli2->html), 'cliente pode preferir os botões');

echo "\nA8. Chat de atendimento: formulário, sugestões, envio sem recarregar e áudio\n";
$home = new Browser($base);
$home->get('/');
ok(str_contains($home->html, 'class="chat-float"') && str_contains($home->html, 'Atendimento online') && !str_contains($home->html, 'class="wa-float"'), 'site: botão flutuante de atendimento online no lugar do WhatsApp');
$hdr = (string) shell_exec('curl -sI ' . escapeshellarg($base . '/'));
ok(str_contains($hdr, 'microphone=(self)'), 'o site permite o microfone para gravar áudio no chat');
$f = new Browser($base);
$f->get('/comecar');
ok(!str_contains($f->html, 'name="nome"') && !str_contains($f->html, 'name="email"') && !str_contains($f->html, 'name="telefone"') && str_contains($f->html, 'name="mensagem"') && str_contains($f->html, 'class="cv-atalho"'), 'sem formulário: o atendimento começa pela conversa, com respostas rápidas');
preg_match('/name="form_key" value="([^"]+)"/', $f->html, $fk);
sleep(3);
$f->post('/comecar', ['mensagem' => '', 'website' => '', 'form_key' => $fk[1]]);
ok(str_contains($f->html, 'Escreva em poucas palavras') && !scalar("SELECT 1 FROM cases WHERE client_name = 'Novo contato'"), 'mensagem vazia não abre atendimento');
sleep(3);
$f->post('/comecar', ['mensagem' => '', 'atalho' => 'O banco mandou notificação', 'website' => '', 'form_key' => $fk[1]]);
$idF = (int) scalar("SELECT MAX(id) FROM cases");
$cf = c($idF);
ok(str_contains($f->url, '/cliente') && $cf['client_name'] === 'Novo contato' && $cf['account_id'] && $cf['consent_at'] && (bool) scalar("SELECT 1 FROM chat_messages WHERE case_id = ? AND author = 'client' AND body = 'O banco mandou notificação'", [$idF]), 'a primeira mensagem abre o atendimento e a conta, ainda sem nome nem contato');
ok(str_contains($f->html, 'qual é o seu nome completo') && fv($idF, 'aviso') === 'banco' && array_slice(agent_missing_fields($idF), 0, 3) === ['objetivo_prioridade', 'nome_completo', 'telefone'], 'a IA ouve o problema, registra e pede a identificação');
$f->get('/cliente?area=conversa');
ok(!str_contains($f->html, 'data-guia') && !str_contains($f->html, 'ainda falta'), 'no começo da conversa não aparece lista do que falta');
$f->post('/cliente/conversa/mensagem', ['message' => 'Meu nome é Fábio Teste Lima, WhatsApp (41) 93333-2222 e e-mail fabio@teste.local. Sou motorista de aplicativo.', 'k' => 'f1']);
case_fields($idF, true);
$cf = c($idF);
$accF = one('SELECT * FROM client_accounts WHERE id = ?', [$cf['account_id']]);
ok(fv($idF, 'nome_completo') === 'Fábio Teste Lima' && $cf['client_name'] === 'Fábio Teste Lima' && $cf['client_phone'] === '41933332222' && $cf['client_email'] === 'fabio@teste.local', 'nome, WhatsApp e e-mail colhidos na conversa completam o atendimento');
ok($accF['name'] === 'Fábio Teste Lima' && $accF['email'] === 'fabio@teste.local' && $accF['phone'] === '41933332222', 'e a conta do cliente, que passa a receber os avisos por e-mail');
$cap = case_data($cf, 'capacidade');
ok(($cap['nivel'] ?? '') === 'media' && str_contains($cap['obs'] ?? '', 'Motorista') && !scalar("SELECT 1 FROM chat_messages WHERE case_id = ? AND lower(body) LIKE '%capacidade%'", [$idF]), 'capacidade de contratação anotada (média), sem aparecer para o cliente');
$f->post('/cliente/conversa/mensagem', ['message' => 'escala inválida', 'k' => 'f2']);
ok((case_data(c($idF), 'capacidade')['nivel'] ?? '') === 'media', 'nível fora da escala alta/média/baixa é ignorado');
$f->get('/cliente?area=conversa');
$pct = agent_progress_pct($idF);
ok(str_contains($f->html, 'data-guia') && str_contains($f->html, 'O que Sidney precisa') && str_contains($f->html, '<strong>' . $pct . '%</strong> concluído') && $pct > 0 && $pct < 100, 'depois da 3ª mensagem: guia discreto com o percentual concluído (' . $pct . '%)');
ok(str_contains($f->html, '<details class="cv-guia"') && !str_contains($f->html, '<details class="cv-guia" open') && str_contains($f->html, 'Seus dados') && str_contains($f->html, 'Veículo') && str_contains($f->html, 'Identidade (RG ou CNH)'), 'guia fechado, com assuntos curtos (Seus dados, Veículo, Identidade…) em vez da lista de campos');
$sid->get('/painel/atendimento/' . $idF);
ok(str_contains($sid->html, 'Capacidade de contratação') && str_contains($sid->html, 'at-cap-media') && str_contains($sid->html, 'Fábio Teste Lima'), 'painel mostra a anotação discreta de capacidade');
$sp = agent_system_prompt();
ok(str_contains($sp, 'objetivo e resolutivo') && str_contains($sp, 'Não repita nem resuma de volta') && str_contains($sp, 'no máximo 3 frases') && str_contains($sp, '"alta" é sempre a situação favorável') && str_contains($sp, 'nunca comente com a pessoa') && str_contains($sp, 'Não há formulário de entrada'), 'instruções: tom objetivo e resolutivo, sem repetir o cliente, identificação na conversa, capacidade discreta e escala com alta favorável');

$cli4 = new Browser($base);
$id4 = start_client($cli4, 'Elisa Rocha', '(41) 94444-1111');
ok(fv($id4, 'nome_completo') === 'Elisa Rocha' && fv($id4, 'email') === 'elisa@teste.local' && (string) c($id4)['client_phone'] === '41944441111', 'nome completo, e-mail e telefone registrados na ficha');
$cli4->get('/cliente?area=conversa');
ok(str_contains($cli4->html, 'class="cv-chip" type="submit" form="fim" name="message" value="Não recebi nada disso"') && !str_contains($cli4->html, 'longa demais'), 'respostas sugeridas pela IA viram atalhos (as longas demais são descartadas)');
ok(str_contains($cli4->html, 'data-rec') && str_contains($cli4->html, 'audio/*') && str_contains($cli4->html, 'data-chat-form'), 'compositor com anexo, microfone e envio sem recarregar');
$last = (int) scalar('SELECT MAX(id) FROM chat_messages WHERE case_id = ?', [$id4]);
$cli4->post('/cliente/conversa/mensagem', ['message' => 'Não recebi nada disso', 'k' => 'aj1', 'ajax' => '1', 'depois' => (string) $last]);
$j = json_decode($cli4->html, true) ?: [];
ok(($j['ok'] ?? false) && str_contains($j['html'] ?? '', 'Não recebi nada disso') && str_contains($j['html'] ?? '', 'O que você precisa resolver') && (int) $j['last'] > $last && isset($j['bar']), 'envio sem recarregar devolve a mensagem, a resposta da IA e a barra atualizada');
$cli4->post('/cliente/conversa/mensagem', ['message' => 'Não recebi nada disso', 'k' => 'aj1', 'ajax' => '1', 'depois' => (string) $last]);
ok((int) scalar("SELECT COUNT(*) FROM chat_messages WHERE case_id = ? AND body = 'Não recebi nada disso'", [$id4]) === 1, 'reenvio da mesma mensagem (rede instável) não duplica');
// Áudio de 1 segundo, como o gravado pelo navegador (WAV 16 kHz mono)
$wav = function (float $secs): string {
    $n = (int) (16000 * $secs);
    $pcm = '';
    for ($i = 0; $i < $n; $i++) {
        $pcm .= pack('v', (int) (8000 * sin($i / 10)) & 0xFFFF);
    }
    $file = tempnam(sys_get_temp_dir(), 'aud') . '.wav';
    file_put_contents($file, 'RIFF' . pack('V', 36 + strlen($pcm)) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16) . 'data' . pack('V', strlen($pcm)) . $pcm);
    return $file;
};
$cli4->post('/cliente/conversa/mensagem', ['message' => '', 'k' => 'au1', 'ajax' => '1', 'depois' => '0'], true, ['audio' => [$wav(1.0), 'audio/wav']]);
foreach ([$id4] as $x) { case_fields($x, true); }
$aud = one("SELECT * FROM files WHERE case_id = ? AND kind = 'audio'", [$id4]);
ok($aud && $aud['mime'] === 'audio/x-wav' || ($aud && $aud['mime'] === 'audio/wav'), 'áudio gravado é guardado no atendimento');
ok((bool) scalar("SELECT 1 FROM chat_messages WHERE case_id = ? AND author = 'client' AND body = '🎙️ Áudio: “Meu contrato é do Banco Aymoré e tenho duas parcelas atrasadas”'", [$id4]), 'áudio transcrito aparece na conversa');
ok(fv($id4, 'atraso') === 'sim' && fv($id4, 'banco') === 'Banco Aymoré', 'a IA entende o áudio e preenche a ficha');
$cli4->post('/cliente/conversa/mensagem', ['message' => '', 'k' => 'au2'], true, ['arquivo[]' => [$wav(0.01), 'audio/wav']]);
ok((bool) scalar("SELECT 1 FROM chat_messages WHERE case_id = ? AND body = '🎙️ Áudio enviado'", [$id4]) && str_contains((string) c($id4)['next_action'], 'Ouvir o áudio'), 'áudio anexado como arquivo também vale; sem transcrição, Sidney é avisado para ouvir');
ok((int) scalar("SELECT COUNT(*) FROM files WHERE case_id = ? AND kind = 'audio'", [$id4]) === 2 && !scalar("SELECT 1 FROM documents WHERE case_id = ? AND status = 'recebido'", [$id4]), 'áudio não é confundido com documento');

echo "\nA9. Desligado\n";
$sid->get('/painel/assistente');
$sid->post('/painel/assistente', ['agent_enabled' => '', 'campos' => $campos, 'documentos' => ['identidade']]);
setting('__reset');
$cli3 = new Browser($base);
$id3 = start_client($cli3, 'Carla Dias', '(41) 96666-4444');
$cli3->get('/cliente?area=conversa');
ok(!agent_enabled() && str_contains($cli3->html, BOT_GREETING) && preg_match('#id="pergunta".*?name="q" value="aviso"#s', $cli3->html), 'com a IA da conversa desligada, volta o roteiro com botões');

echo "\n" . ($fails ? "FALHAS: $fails" : 'TUDO OK') . " · $passes verificações aprovadas\n";
exit($fails ? 1 : 0);
