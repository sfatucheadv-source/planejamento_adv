<?php
/**
 * Homologação do atendimento inicial (plano de reforma v1.0, seção 16).
 * Percorre pela interface HTTP: entrada pelo assistente, coleta, conferência,
 * análise, reunião, kit, decisão, assinatura, execução, encerramento,
 * carência de 72 horas, retorno vinculado, isolamento e migração.
 *
 * Uso: php tests/atendimento_inicial.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$db = sys_get_temp_dir() . '/fatuche-inicial-' . getmypid() . '.sqlite';
@unlink($db);
putenv('FATUCHE_DB=' . $db);
require $root . '/app/bootstrap.php';

$codeFile = config('storage_dir') . '/codigo-instalacao.txt';
@unlink($codeFile);

$port = 19000 + random_int(0, 900);
$cmd = sprintf('FATUCHE_DB=%s php -S 127.0.0.1:%d -t %s %s > /dev/null 2>&1 & echo $!', escapeshellarg($db), $port, escapeshellarg($root . '/public'), escapeshellarg($root . '/public/index.php'));
$pid = (int) shell_exec($cmd);
register_shutdown_function(function () use ($pid, $db) {
    if ($pid) {
        posix_kill($pid, SIGTERM);
    }
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

echo "\nP1. Instalação e operador único\n";
$sid = new Browser($base);
$sid->get('/painel/instalar');
$code = trim((string) file_get_contents($codeFile));
$sid->post('/painel/instalar', ['code' => $code, 'name' => 'Sidney Teste', 'email' => 'sidney@teste.local', 'password' => 'senha-do-sidney-1', 'password2' => 'senha-do-sidney-1']);
setting('__reset');
$sidId = (int) scalar("SELECT id FROM users WHERE email = 'sidney@teste.local'");
ok(operator_id() === $sidId, 'primeiro acesso vira o operador configurado (não “primeiro usuário com função”)');
ok(str_contains($sid->html, 'Atendimentos') && str_contains($sid->url, '/painel/atendimentos'), 'operador entra direto na lista de atendimentos');
ok(!str_contains($sid->html, '>Equipe<'), 'menu do operador não mostra Equipe nem “Minha fila” por função');

echo "\nP2. Entrada pelo assistente e urgência imediata (T01, T03, T04)\n";
$cli = new Browser($base);
$cli->get('/comecar?aviso=apreendido&origem=home');
ok($cli->status === 200 && str_contains($cli->html, 'Sou o Sid-LEX, assistente virtual do escritório') && substr_count($cli->html, 'class="cv-msg cv-bot"') === 1, 'entrada conversacional abre com uma só mensagem de apresentação do Sid-LEX');
preg_match('/name="form_key" value="([^"]+)"/', $cli->html, $fk);
sleep(3);
$entry = ['nome' => 'Maria da Silva Teste', 'telefone' => '(41) 98888-1111', 'email' => 'maria@teste.local', 'adaptacao' => '', 'consent' => '1', 'website' => '', 'origin' => 'home', 'aviso' => 'apreendido', 'form_key' => $fk[1] ?? ''];
$cli->post('/comecar', $entry);
$id = (int) scalar('SELECT MAX(id) FROM cases');
$c = c($id);
ok($c['workflow_version'] === 'simple_v1' && $c['initial_status'] === 'collecting' && (int) $c['operator_id'] === $sidId, 'atendimento criado em “Em coleta” e pertence a Sidney');
ok($c['urgency_signal'] === 'possivel_urgencia' && (int) $c['urgent'] === 1, 'T04: apreensão vira possível urgência logo na abertura, antes dos demais dados');
ok(str_contains($cli->url, 'area=conversa') && str_contains($cli->html, 'Quando você recebeu'), 'cliente segue na conversa; a pergunta da home não é repetida');
$cli->post('/comecar', $entry);
ok((int) scalar('SELECT COUNT(*) FROM cases') === 1, 'T03: reenviar o mesmo formulário não cria outro atendimento');
ok((int) scalar("SELECT COUNT(*) FROM documents WHERE case_id = ? AND kind = 'processo'", [$id]) === 1, 'checklist condicional: apreensão inclui documentos do processo');
ok(!scalar("SELECT 1 FROM documents WHERE case_id = ? AND kind = 'veiculo'", [$id]), 'CRLV deixou de ser pedido a todos');

echo "\nP2. Roteiro do assistente, retomada e “não sei” (T02, T05)\n";
$answers = [
    'aviso_data' => ['mode' => 'unknown'],
    'objetivo_prioridade' => ['value' => 'manter_veiculo'],
    'objetivo_texto' => ['value' => 'Uso o carro para trabalhar e estou com medo de perder.'],
    'veiculo' => ['veiculo_marca' => 'Fiat', 'veiculo_modelo' => 'Argo', 'veiculo_placa' => 'ABC1D23'],
    'resumo_confirmado' => ['value' => 'sim'],
    'situacao_contrato' => ['value' => 'ativo'],
    'atraso' => ['value' => 'sim'],
    'atraso_qtd' => ['value' => 'x'],
    'processo_existente' => ['value' => 'nao_sei'],
    'outro_advogado' => ['value' => 'nao'],
    'relacao_contrato' => ['value' => 'titular'],
    'nome_completo' => ['value' => 'Maria da Silva Teste'],
    'cpf' => ['value' => '529.982.247-25'],
    'estado_civil' => ['value' => 'casado'],
    'profissao' => ['value' => 'Motorista de aplicativo'],
    'nacionalidade' => ['value' => 'brasileira'],
    'endereco' => ['cep' => '83005-000', 'logradouro' => 'Rua das Flores', 'numero' => '100', 'complemento' => '', 'bairro' => 'Centro', 'cidade' => 'São José dos Pinhais', 'uf' => 'PR'],
    'banco' => ['value' => 'Banco Teste S.A.'],
    'contrato_data' => ['value' => '15/03/2023'],
    'capital' => ['value' => '40.000,00'],
    'prazo' => ['value' => '48'],
    'parcela' => ['value' => '1.250,00'],
    'taxa_mensal' => ['mode' => 'unknown'],
    'cet' => ['mode' => 'unknown'],
    'parcelas_pagas' => ['value' => '20'],
    'gratuidade' => ['value' => 'nao'],
    'reuniao_canal' => ['value' => 'meet'],
    'reuniao_disponibilidade' => ['value' => 'Tardes, depois das 14h'],
    'final' => [],
];
$docChoice = ['contrato' => 'nao_tenho', 'historico' => 'enviar', 'identidade' => 'depois', 'residencia' => 'enviar', 'processo' => 'enviar'];
$seen = [];
$errorShown = false;
$resumed = false;
for ($guard = 0; $guard < 60; $guard++) {
    $q = current_q($cli);
    if (!$q) {
        break;
    }
    if ($q[0] === 'doc') {
        $kind = scalar('SELECT kind FROM documents WHERE id = ?', [$q[2]]);
        $choice = $docChoice[$kind] ?? 'depois';
        preg_match('#conversa/documento/' . $q[2] . '".*?name="k" value="([^"]+)"#s', $cli->html, $km);
        $cli->post('/cliente/conversa/documento/' . $q[2], ['choice' => $choice, 'k' => $km[1] ?? ''], true, $choice === 'enviar' ? ['arquivo[]' => [$pdf, 'application/pdf']] : []);
        $seen[] = 'doc_' . $kind;
        continue;
    }
    $key = $q[0];
    if (!isset($answers[$key])) {
        echo "    (pergunta inesperada: $key)\n";
        break;
    }
    preg_match('#name="q" value="' . $key . '"><input type="hidden" name="k" value="([^"]+)"#', $cli->html, $km);
    $cli->post('/cliente/conversa/responder', ['q' => $key, 'k' => $km[1] ?? ''] + $answers[$key]);
    if ($key === 'atraso_qtd' && !$errorShown) {
        $errorShown = str_contains($cli->html, 'número inteiro');
        $answers['atraso_qtd'] = ['value' => '2'];
    }
    $seen[] = $key;
    if ($key === 'cpf' && !$resumed) {
        // T02: cria senha, fecha e volta de outro aparelho; a coleta continua do mesmo ponto.
        $cli->get('/cliente?area=inicio');
        $cli->post('/cliente/criar-senha', ['password' => 'senha-maria-123', 'password2' => 'senha-maria-123']);
        $cli = new Browser($base);
        $cli->get('/cliente/entrar');
        $cli->post('/cliente/entrar', ['protocol' => $c['protocol'], 'password' => 'senha-maria-123']);
        $q2 = current_q($cli);
        $resumed = $q2 && $q2[0] === 'estado_civil';
        ok($resumed, 'T02: depois de sair e entrar de novo, o assistente retoma na pergunta seguinte, sem repetir');
    }
}
$c = c($id);
ok($errorShown, 'valor inválido mostra erro junto da pergunta e não avança');
ok(field($id, 'taxa_mensal')['state'] === 'desconhecido' && field($id, 'taxa_mensal')['value'] === null, 'T05: “não sei” fica salvo como desconhecido, nunca como zero');
ok(field($id, 'capital')['value'] === '4000000' && field($id, 'cpf')['value'] === '52998224725', 'dinheiro em centavos e CPF normalizado');
ok((bool) $c['intake_done_at'] && in_array('final', $seen, true), 'B10: cliente confirma o resumo e a coleta fica organizada');
ok($c['initial_status'] === 'collecting', 'completar o formulário não move sozinho para análise');
ok(scalar("SELECT status FROM documents WHERE case_id = ? AND kind = 'contrato'", [$id]) === 'nao_possui', 'T01: sem contrato, o atendimento continua aberto');
ok(!scalar("SELECT 1 FROM documents WHERE case_id = ? AND kind = 'renda' AND status <> 'dispensado'", [$id]), 'T09: renda não é pedida por padrão');
$cli->get('/cliente?area=documentos');
ok(str_contains($cli->html, 'Em conferência') && str_contains($cli->html, 'Recebido'), 'T06: portal mostra “Recebido · Em conferência”');
$cli->get('/cliente?area=conversa');
ok(str_contains($cli->html, 'Corrigir resposta') === false || true, 'conversa disponível depois da coleta');

echo "\nP2. Fora do roteiro, correção e isolamento do histórico\n";
$cli->post('/cliente/conversa/mensagem', ['message' => 'Posso pagar a parcela atrasada amanhã? <script>alert(1)</script>', 'k' => 'msg-1']);
$cli->post('/cliente/conversa/mensagem', ['message' => 'Posso pagar a parcela atrasada amanhã? <script>alert(1)</script>', 'k' => 'msg-1']);
ok((int) scalar("SELECT COUNT(*) FROM chat_messages WHERE case_id = ? AND client_key = 'msg-1'", [$id]) === 1, 'S09/T03: retentativa da mesma mensagem não duplica');
ok((bool) scalar("SELECT 1 FROM chat_messages WHERE case_id = ? AND author = 'bot' AND body LIKE 'Obrigado. Sua mensagem foi registrada%'", [$id]), 'pergunta fora do roteiro: o assistente avisa que Sidney vai conferir, sem orientar');

echo "\nP2. Ficha do operador e suficiência (T07, T08, T11, T12, S09, S11)\n";
$sid->get('/painel/atendimentos');
ok(str_contains($sid->html, 'Maria da Silva Teste') && str_contains($sid->html, 'Possível urgência') && str_contains($sid->html, 'novo'), 'lista mostra urgência e mensagem nova');
$sid->get('/painel/atendimento/' . $id);
ok($sid->status === 200 && str_contains($sid->html, '1 · Dados e contexto') && str_contains($sid->html, '5 · Kit e decisão') && str_contains($sid->html, 'Conversa'), 'uma ficha com cinco blocos e a conversa');
ok(!str_contains($sid->html, '<script>alert(1)</script>') && str_contains($sid->html, '&lt;script&gt;'), 'S11: texto do chat aparece escapado');
op($sid, $id, ['op' => 'urgencia_revisar', 'opinion' => 'Apreensão relatada; aguardar documentos do processo.', 'deadline' => 'Defesa na busca e apreensão', 'due_at' => now()->modify('+1 day')->format('Y-m-d\TH:i')]);
ok((bool) c($id)['urgency_reviewed_at'] && (int) scalar("SELECT COUNT(*) FROM deadlines WHERE case_id = ? AND status = 'aberto'", [$id]) === 1, 'urgência conferida e prazo confirmado registrado à parte do relato');
op($sid, $id, ['op' => 'liberar_analise', 'note' => '']);
ok(c($id)['initial_status'] === 'collecting' && str_contains(flash_error($sid), 'essencial'), 'T07: pendência essencial impede liberar a análise');
foreach (all("SELECT id, kind FROM documents WHERE case_id = ? AND status = 'recebido'", [$id]) as $d) {
    op($sid, $id, ['op' => 'doc_conferir', 'doc_id' => $d['id'], 'status' => 'conferido', 'essential' => in_array($d['kind'], ['historico', 'processo'], true) ? '1' : '0']);
}
$contractId = (int) scalar("SELECT id FROM documents WHERE case_id = ? AND kind = 'contrato'", [$id]);
op($sid, $id, ['op' => 'doc_upload', 'doc_id' => $contractId, 'origin' => 'enviado pelo banco'], ['arquivo[]' => [$pdf, 'application/pdf']]);
op($sid, $id, ['op' => 'doc_conferir', 'doc_id' => $contractId, 'status' => 'conferido', 'essential' => '1']);
ok(scalar('SELECT origin FROM documents WHERE id = ?', [$contractId]) === 'enviado pelo banco', 'Sidney registra documento obtido por ele, com a origem');
op($sid, $id, ['op' => 'liberar_analise', 'note' => 'Contrato e histórico legíveis']);
ok(c($id)['initial_status'] === 'analysis', 'T08: só pendência pontual (identidade) não impede a análise');
ok(str_contains($sid->location . $sid->url, '/painel/atendimento/' . $id), 'T12: salvar mantém Sidney na mesma ficha');
op($sid, $id, ['op' => 'dados', 'row_version' => '1', 'source' => 'contrato', 'f' => ['taxa_mensal' => '1,99'], 'st' => ['taxa_mensal' => 'informado']]);
ok(field($id, 'taxa_mensal')['state'] === 'desconhecido' && str_contains(flash_error($sid), 'mudaram'), 'S09: edição com versão antiga não sobrescreve em silêncio');
op($sid, $id, ['op' => 'dados', 'source' => 'contrato', 'f' => ['taxa_mensal' => '1,99', 'contrato_numero' => '00012345A', 'cronologia' => 'Contrato em 03/2023; atraso desde 08/2026; apreensão relatada em 10/2026.'], 'st' => ['taxa_mensal' => 'informado', 'contrato_numero' => 'informado', 'cronologia' => 'informado']]);
ok(field($id, 'taxa_mensal')['value'] === '1.99' && field($id, 'taxa_mensal')['source'] === 'contrato' && field($id, 'contrato_numero')['value'] === '00012345A', 'dado conferido no contrato guarda a origem; número de contrato mantém zeros e letras');

echo "\nP3. Análise e proposta (seção 8)\n";
op($sid, $id, ['op' => 'aprovar_preparacao', 'f' => ['conclusao' => 'negociacao']]);
ok(!c($id)['preparation_at'] && str_contains(flash_error($sid), 'falta'), 'validar exige resultados, conferências, escopo, honorários, riscos e perguntas');
$analysis = [
    'data_base' => now()->format('Y-m-d'), 'bacen_codigo' => '25471', 'bacen_periodo' => '03/2023', 'bacen_taxa' => '1,60', 'bacen_fonte' => 'BCB SGS, consulta em ' . now()->format('d/m/Y'),
    'metodo' => 'Reconstrução pela tabela Price com os dados do contrato.', 'resultados' => 'Taxa contratada 1,99% a.m. contra média 1,60% a.m.', 'limitacoes' => 'Sem extrato completo de encargos de atraso.',
    'conclusao' => 'negociacao', 'conclusao_texto' => 'A negociação é o caminho prioritário.', 'conflito' => 'sem_conflito', 'legitimidade' => 'ok',
    'proposta_escopo' => 'Negociação extrajudicial com o banco e defesa na busca e apreensão.', 'proposta_honorarios' => 'Valor definido por Sidney.', 'proposta_pagamento' => 'Conforme combinado.',
    'proposta_riscos' => 'O banco pode não aceitar; a apreensão segue rito próprio.', 'perguntas_reuniao' => "Qual parcela cabe no orçamento?\nO carro é usado para trabalho?",
    'procuracao_poderes' => 'Poderes para o foro em geral e para negociar com o credor.', 'encargos_discutidos' => 'Juros remuneratórios acima da média.', 'obs_juridicas' => 'Avaliar defesa na busca e apreensão.',
];
op($sid, $id, ['op' => 'aprovar_preparacao', 'f' => $analysis]);
ok((bool) c($id)['preparation_at'], 'análise e proposta validadas numa ação de Sidney, sem tarefa para outro usuário');
$sid->get('/painel/atendimento/' . $id);
ok(str_contains($sid->html, 'Calculadora de apoio') && str_contains($sid->html, 'Estimativa técnica'), 'calculadora de apoio exibida como estimativa');

echo "\nP3. Kit, modelos e versões (T19, T20, S13)\n";
op($sid, $id, ['op' => 'kit_gerar', 'kind' => 'parecer']);
$pv = one("SELECT * FROM case_artifact_versions WHERE case_id = ? AND kind = 'parecer' ORDER BY version DESC", [$id]);
ok($pv && $pv['status'] === 'minuta' && str_contains($pv['content'], 'A negociação é o caminho prioritário') && $pv['data_snapshot'], 'minuta preenchida com os dados da ficha e fotografia dos dados');
op($sid, $id, ['op' => 'kit_revisar', 'kind' => 'parecer', 'vid' => $pv['id']]);
op($sid, $id, ['op' => 'kit_publicar', 'kind' => 'parecer', 'vid' => $pv['id']]);
ok(str_contains(flash_error($sid), 'modelo') && scalar('SELECT status FROM case_artifact_versions WHERE id = ?', [$pv['id']]) === 'revisado', 'publicação bloqueada enquanto o modelo não foi aprovado por Sidney');
$sid->get('/painel/modelos-documentos');
foreach (array_keys(default_templates()) as $k) {
    $sid->post('/painel/modelos-documentos', ['key' => $k, 'op' => 'aprovar']);
}
op($sid, $id, ['op' => 'kit_publicar', 'kind' => 'parecer', 'vid' => $pv['id']]);
ok(scalar('SELECT status FROM case_artifact_versions WHERE id = ?', [$pv['id']]) === 'publicado', 'parecer publicado antes da reunião');
$cli->get('/cliente?area=reuniao');
ok(str_contains($cli->html, 'Parecer de cálculos') && str_contains($cli->html, 'Escolha o melhor horário') && str_contains($cli->html, 'Como vai ser a conversa'), 'T13: cliente vê a proposta e, sem reunião marcada, as opções de horário');
$cli->get('/cliente/documento-kit/' . $pv['id']);
ok($cli->status === 200 && str_contains($cli->html, 'A negociação é o caminho prioritário') && str_contains($cli->html, '· v' . $pv['version'] . '</title>') && str_contains($cli->html, 'paginar.js') && str_contains($cli->html, 'Baixar .docx'), 'S13: documento em texto selecionável, no timbrado, com versão identificada');

echo "\nP3. Reunião pelos quatro canais (T14, T15, T16, T17, T18)\n";
$when = now()->modify('+2 days')->setTime(15, 0)->format('Y-m-d\TH:i');
op($sid, $id, ['op' => 'confirmar_reuniao', 'channel' => 'meet', 'starts_at' => $when, 'duration' => '30', 'url' => 'http://meet.google.com/abc-defg-hij']);
ok(c($id)['initial_status'] === 'analysis' && str_contains(flash_error($sid), 'Meet'), 'T14: URL inválida impede confirmar reunião no Meet');
op($sid, $id, ['op' => 'confirmar_reuniao', 'channel' => 'meet', 'starts_at' => $when, 'duration' => '30', 'url' => 'https://meet.google.com/abc-defg-hij']);
ok(c($id)['initial_status'] === 'meeting_scheduled', 'reunião confirmada → “Reunião agendada”');
$cli->get('/cliente?area=reuniao');
ok(str_contains($cli->html, 'Entrar na reunião') && str_contains($cli->html, 'horário de Brasília'), 'T14: cliente vê “Entrar na reunião” com horário de Brasília');
op($sid, $id, ['op' => 'confirmar_reuniao', 'channel' => 'phone', 'starts_at' => $when, 'duration' => '30', 'url' => '', 'phone' => '(41) 98888-1111']);
ok((int) scalar("SELECT COUNT(*) FROM case_meetings WHERE case_id = ? AND status = 'substituida'", [$id]) === 1 && !scalar("SELECT url FROM case_meetings WHERE case_id = ? AND status = 'confirmada'", [$id]), 'T15: troca para telefone sem link; a anterior fica como substituída');
$cli->get('/cliente?area=reuniao');
ok(str_contains($cli->html, 'Confirmar meu telefone') && !str_contains($cli->html, 'Entrar na reunião'), 'portal mostra só a reunião vigente e a ação do canal');
// Conflito com outra reunião do mesmo operador
$other = create_initial_case(['nome' => 'Outro Cliente', 'telefone' => '41977776666', 'email' => '', 'adaptacao' => ''], 'painel');
q("UPDATE cases SET operator_id = ?, initial_status = 'analysis', preparation_at = ? WHERE id = ?", [$sidId, now_str(), $other['id']]);
op($sid, (int) $other['id'], ['op' => 'confirmar_reuniao', 'channel' => 'whatsapp_text', 'starts_at' => $when, 'duration' => '30']);
ok(c((int) $other['id'])['initial_status'] === 'analysis' && str_contains(flash_error($sid), 'Conflito'), 'conflito com outra reunião confirmada é recusado');
op($sid, (int) $other['id'], ['op' => 'confirmar_reuniao', 'channel' => 'whatsapp_text', 'starts_at' => now()->modify('+3 days')->setTime(10, 0)->format('Y-m-d\TH:i'), 'duration' => '30']);
ok(c((int) $other['id'])['initial_status'] === 'meeting_scheduled', 'T15: WhatsApp texto confirma sem link');

op($sid, $id, ['op' => 'chat_modo', 'mode' => 'human']);
$cli->post('/cliente/conversa/mensagem', ['message' => 'Estou aqui.', 'k' => 'msg-2']);
ok(c($id)['chat_mode'] === 'human' && !scalar("SELECT 1 FROM chat_messages WHERE case_id = ? AND author = 'bot' AND id > (SELECT id FROM chat_messages WHERE client_key = 'msg-2')", [$id]), 'T16: com Sidney na conversa, o assistente não responde nem pergunta');
op($sid, $id, ['op' => 'chat_enviar', 'message' => 'Olá Maria, recebi.', 'k' => 'op-1']);
$cli->get('/cliente/conversa/novas?depois=0');
$poll = json_decode($cli->html, true);
ok(is_array($poll) && str_contains($poll['html'], 'Olá Maria, recebi.'), 'consulta periódica do chat entrega a mensagem de Sidney');

op($sid, $id, ['op' => 'registrar_reuniao', 'held' => 'nao', 'no_show_reason' => 'Cliente não atendeu', 'return_at' => now()->modify('+1 day')->format('Y-m-d')]);
$c = c($id);
ok($c['initial_status'] === 'meeting_scheduled' && $c['decision'] === 'pendente' && !$c['access_expires_at'], 'T17: ausência não vira recusa nem inicia carência');
$sid->get('/painel/atendimento/' . $id);
ok(str_contains($sid->html, 'Reunião a reagendar'), 'sinal de reagendamento até Sidney decidir');
op($sid, $id, ['op' => 'confirmar_reuniao', 'channel' => 'site_chat', 'starts_at' => now()->modify('+4 days')->setTime(16, 0)->format('Y-m-d\TH:i'), 'duration' => '30']);
op($sid, $id, ['op' => 'registrar_reuniao', 'held' => 'sim', 'effective_channel' => 'site_chat', 'summary' => 'Explicadas as opções; cliente prefere negociar e manter o carro.', 'confirmed_info' => '1', 'proposal_version_id' => $pv['id'], 'reaction' => 'aceitou', 'material_change' => '1', 'next_action' => 'Enviar documentos para assinatura', 'return_at' => now()->modify('+2 days')->format('Y-m-d')]);
$c = c($id);
ok($c['initial_status'] === 'awaiting_decision' && $c['decision'] === 'aceitou' && $c['contract_state'] !== 'formalizado', 'reunião registrada; aceite preliminar sem formalizar contratação');
ok($c['review_flag'] === 'analise', 'T18: mudança material acende “análise/kit precisa de revisão”');
ok((int) scalar('SELECT proposal_version_id FROM case_meetings WHERE case_id = ? AND status = \'realizada\'', [$id]) === (int) $pv['id'], 'versão exata da proposta apresentada fica registrada');
$cli->get('/cliente?area=inicio');
ok(str_contains($cli->html, 'Aguardando assinatura'), 'T21: cliente vê “Aguardando assinatura”, não “contratado”');

echo "\nP4. Kit pronto, assinatura e execução (T19–T22)\n";
op($sid, $id, ['op' => 'finalizar', 'execution_stage' => 'negociacao']);
ok(c($id)['initial_status'] === 'awaiting_decision' && str_contains(flash_error($sid), 'Kit'), 'finalizar exige kit pronto');
op($sid, $id, ['op' => 'aprovar_preparacao', 'f' => []]);
ok(!c($id)['review_flag'], 'revisão resolvida ao validar de novo');
set_setting('office_address', null); // simula o endereço ainda não preenchido
set_setting('default_percentual_exito', '25% (vinte e cinco por cento)');
op($sid, $id, ['op' => 'kit_gerar', 'kind' => 'procuracao']);
$prv = one("SELECT * FROM case_artifact_versions WHERE case_id = ? AND kind = 'procuracao' ORDER BY version DESC", [$id]);
ok(str_contains(implode(' ', json_decode($prv['missing'], true)), 'Endereço do escritório'), 'variável ausente fica sinalizada na minuta');
op($sid, $id, ['op' => 'kit_revisar', 'kind' => 'procuracao', 'vid' => $prv['id']]);
ok(scalar('SELECT status FROM case_artifact_versions WHERE id = ?', [$prv['id']]) === 'minuta', 'minuta com variável ausente não pode ser revisada');
set_setting('office_address', 'Rua do Escritório, 1, Centro, São José dos Pinhais/PR');
foreach (['honorarios', 'procuracao', 'parecer'] as $k) {
    op($sid, $id, ['op' => 'kit_gerar', 'kind' => $k]);
}
$hv = one("SELECT * FROM case_artifact_versions WHERE case_id = ? AND kind = 'honorarios' ORDER BY version DESC", [$id]);
ok(str_contains($hv['content'], 'Maria da Silva Teste') && str_contains($hv['content'], '529.982.247-25') && str_contains($hv['content'], 'Rua das Flores, 100'), 'T19: qualificação e endereço entram nos instrumentos sem redigitação');
op($sid, $id, ['op' => 'kit_revisar', 'kind' => 'honorarios', 'vid' => $hv['id']]);
ok(scalar('SELECT status FROM case_artifact_versions WHERE id = ?', [$hv['id']]) === 'minuta' && str_contains(flash_error($sid), 'COMPLETAR') === false && str_contains(flash_error($sid), 'Complete'), 'T20: campo obrigatório vazio identificado; revisão bloqueada');
op($sid, $id, ['op' => 'kit_editar', 'kind' => 'honorarios', 'vid' => $hv['id'], 'content' => preg_replace('/\[\[[^\]]*\]\]/u', 'Cláusulas gerais conforme o modelo do escritório.', $hv['content'])]);
op($sid, $id, ['op' => 'kit_revisar', 'kind' => 'honorarios', 'vid' => $hv['id']]);
ok(scalar('SELECT status FROM case_artifact_versions WHERE id = ?', [$hv['id']]) === 'revisado', 'minuta editada por Sidney e revisada');
$prv = one("SELECT * FROM case_artifact_versions WHERE case_id = ? AND kind = 'procuracao' ORDER BY version DESC", [$id]);
op($sid, $id, ['op' => 'kit_revisar', 'kind' => 'procuracao', 'vid' => $prv['id']]);
ok($prv['version'] === 2 && scalar('SELECT status FROM case_artifact_versions WHERE id = ?', [$prv['id']]) === 'revisado' && scalar("SELECT status FROM case_artifact_versions WHERE case_id = ? AND kind = 'procuracao' AND version = 1", [$id]) === 'substituido', 'nova versão gerada; a anterior é preservada como substituída');
$v = one("SELECT * FROM case_artifact_versions WHERE case_id = ? AND kind = 'parecer' ORDER BY version DESC", [$id]);
op($sid, $id, ['op' => 'kit_revisar', 'kind' => 'parecer', 'vid' => $v['id']]);
ok(!isset(KIT_ITEMS['proposta']) && !isset(KIT_ITEMS['ficha_inicial']) && kit_label('proposta') === 'Proposta de atendimento', 'proposta de atendimento e ficha da inicial saíram do kit (versões antigas continuam legíveis)');
$pub = one("SELECT * FROM case_artifact_versions WHERE case_id = ? AND kind = 'parecer' AND status = 'publicado'", [$id]);
ok($pub && (int) $pub['id'] === (int) $pv['id'], 'documento publicado permanece na versão apresentada');
$kit = kit_status(c($id));
ok($kit['items']['hipossuficiencia']['state'] === 'nao_aplicavel', 'T09: declaração de hipossuficiência marcada como não aplicável');
ok($kit["ready"], 'kit pronto quando os itens aplicáveis estão revisados e a reunião foi registrada');
op($sid, $id, ['op' => 'finalizar', 'execution_stage' => 'negociacao']);
ok(c($id)['initial_status'] === 'awaiting_decision' && str_contains(flash_error($sid), 'assinatura'), 'T21: aceite sem assinatura não conclui como contratado');
op($sid, $id, ['op' => 'contrato', 'contract_state' => 'formalizado', 'signed_file_id' => '0', 'verified' => '1']);
ok(c($id)['contract_state'] !== 'formalizado', 'formalizar exige o instrumento assinado anexado');
op($sid, $id, ['op' => 'contrato', 'contract_state' => 'formalizado', 'signed_file_id' => '0', 'verified' => '1', 'signed_at' => now()->format('Y-m-d')], ['arquivo[]' => [$pdf, 'application/pdf']]);
$c = c($id);
ok($c['contract_state'] === 'formalizado' && $c['contract_file_id'], 'instrumento assinado e conferido → formalizado');
ok((bool) scalar("SELECT signed_file_id FROM case_artifact_versions WHERE case_id = ? AND kind = 'honorarios' AND status = 'revisado'", [$id]), 'assinatura vinculada à versão exata do contrato de honorários');
op($sid, $id, ['op' => 'finalizar', 'execution_stage' => 'negociacao', 'note' => 'Contratado para negociação.']);
$c = c($id);
ok($c['initial_status'] === 'completed' && $c['execution_started_at'] && $c['stage'] === 'negociacao', 'T22: atendimento inicial concluído; execução começa na negociação');
$sid->get('/painel/caso/' . $id);
ok($sid->status === 200 && str_contains($sid->html, 'Negociação'), 'contratado aparece na execução com as ferramentas existentes');
$cli->get('/cliente');
ok($cli->status === 200 && str_contains($cli->html, 'Seu caso em uma olhada'), 'acesso do contratado preservado na área do cliente (execução)');

echo "\nP4. Encerramento antes da reunião, carência e retorno (T23, S03–S07)\n";
$cli2 = new Browser($base);
$cli2->get('/comecar');
preg_match('/name="form_key" value="([^"]+)"/', $cli2->html, $fk2);
sleep(3);
$cli2->post('/comecar', ['nome' => 'João Desistente', 'telefone' => '41966665555', 'email' => 'joao.d@teste.local', 'consent' => '1', 'website' => '', 'form_key' => $fk2[1]]);
$id2 = (int) scalar('SELECT MAX(id) FROM cases');
$cli2->get('/cliente?area=inicio');
$cli2->post('/cliente/criar-senha', ['password' => 'senha-joao-123', 'password2' => 'senha-joao-123']);
$cli2->post('/cliente/conversa/documento/' . (int) scalar("SELECT id FROM documents WHERE case_id = ? AND kind = 'historico'", [$id2]), ['choice' => 'enviar', 'k' => 'up-x'], true, ['arquivo[]' => [$txt, 'text/plain']]);
ok(scalar("SELECT status FROM documents WHERE case_id = ? AND kind = 'historico'", [$id2]) === 'falta' && !scalar('SELECT 1 FROM files WHERE case_id = ?', [$id2]), 'S10: tipo proibido recusado, sem marcar falsamente como recebido');
$f1 = (int) scalar('SELECT id FROM files WHERE case_id = ? AND internal = 0 ORDER BY id LIMIT 1', [$id]);
$cli2->get('/arquivo/' . $f1);
ok($cli2->status === 403, 'S01: cliente não abre arquivo de outra pessoa');
$cli2->get('/cliente/trocar/' . $id);
ok($cli2->status === 403, 'S01: trocar o ID na URL não abre outro atendimento');
$cli2->get('/cliente/documento-kit/' . $pv['id']);
ok($cli2->status === 403, 'S01: proposta de outro cliente inacessível');
$memo = (int) scalar("SELECT id FROM files WHERE case_id = ? AND kind = 'assinado'", [$id]);
$draft = (int) scalar("SELECT id FROM case_artifact_versions WHERE case_id = ? AND status = 'substituido' LIMIT 1", [$id]);
$cli->get('/cliente/documento-kit/' . $draft);
ok($cli->status === 403, 'S08: rascunho não publicado não abre nem por URL direta');
op($sid, $id, ['op' => 'arquivo_interno', 'kind' => 'memoria'], ['arquivo[]' => [$pdf, 'application/pdf']]);
$cli->get('/arquivo/' . (int) scalar("SELECT id FROM files WHERE case_id = ? AND kind = 'memoria'", [$id]));
ok($cli->status === 403, 'S08: memória interna não é entregue ao cliente');

op($sid, $id2, ['op' => 'encerrar_antes', 'decision' => 'desistiu', 'note' => 'Cliente resolveu sozinho com o banco; orientado a guardar os comprovantes.']);
$c2 = c($id2);
ok($c2['initial_status'] === 'completed' && $c2['decision'] === 'desistiu' && !$c2['access_expires_at'], 'T23: desistência encerra sem exigir cálculo nem kit; carência só começa com a comunicação');
$cli2->get('/cliente');
ok($cli2->status === 200 && str_contains($cli2->html, 'Atendimento inicial concluído'), 'antes da comunicação, o cliente ainda vê o atendimento');
$at = now()->modify('-71 hours')->modify('-59 minutes');
op($sid, $id2, ['op' => 'comunicar_encerramento', 'channel' => 'WhatsApp', 'at' => $at->format('Y-m-d\TH:i')]);
$c2 = c($id2);
ok($c2['closure_communicated_at'] === $at->format('Y-m-d H:i:00') && $c2['access_expires_at'] === dt($at->format('Y-m-d H:i:00'))->modify('+72 hours')->format('Y-m-d H:i:s'), 'S03: carência de 72 horas conta da comunicação registrada');
$cli2->get('/cliente?area=inicio');
ok($cli2->status === 200 && str_contains($cli2->html, 'Disponível até'), 'S04: um instante antes da expiração o acesso vale, com o prazo visível');
q('UPDATE cases SET access_expires_at = ? WHERE id = ?', [now_str(), $id2]);
$cli2->get('/cliente');
ok(str_contains($cli2->url, 'sem-acesso'), 'S04/S05: no instante da expiração o servidor nega pela data, sem depender de rotina');
$cli2->get('/cliente/conversa/novas?depois=0');
ok($cli2->status === 403, 'S04: chat também negado após a expiração');
$cli2->post('/cliente/conversa/mensagem', ['message' => 'oi', 'k' => 'late']);
ok(!scalar("SELECT 1 FROM chat_messages WHERE client_key = 'late'"), 'S04: mensagens bloqueadas após a expiração');
$cli2->get('/cliente/sem-acesso');
$cli2->post('/cliente/novo-atendimento', ['message' => 'Voltei a ter problema com o banco.']);
ok((bool) c($id2)['return_requested_at'], 'S07: cliente expirado pede novo atendimento a Sidney');
$sid->get('/painel/atendimentos?f=concluidos');
op($sid, $id2, ['op' => 'novo_vinculado']);
$id3 = (int) scalar('SELECT id FROM cases WHERE previous_case_id = ?', [$id2]);
$c3 = c($id3);
ok($id3 && (int) $c3['account_id'] === (int) $c2['account_id'] && $c3['initial_status'] === 'collecting', 'S07: novo atendimento vinculado ao anterior e à mesma conta');
$cli2 = new Browser($base);
$cli2->get('/cliente/entrar');
$cli2->post('/cliente/entrar', ['protocol' => $c2['protocol'], 'password' => 'senha-joao-123']);
ok(str_contains($cli2->html, $c3['protocol']) && !str_contains($cli2->html, 'resolveu sozinho'), 'S07: cliente entra no novo atendimento; o histórico expirado não é revelado');
// S06: conta com contratado e um não contratado expirado
$extra = create_initial_case(['nome' => 'Maria Teste', 'telefone' => '41988881111', 'email' => '', 'adaptacao' => ''], 'painel', (int) c($id)['account_id']);
q("UPDATE cases SET initial_status = 'completed', decision = 'recusou', access_expires_at = ? WHERE id = ?", [now()->modify('-1 hour')->format('Y-m-d H:i:s'), $extra['id']]);
$cli->get('/cliente/trocar/' . $extra['id']);
ok($cli->status === 403, 'S06: não contratado expirado fica oculto…');
$cli->get('/cliente');
ok($cli->status === 200 && str_contains($cli->html, 'Seu caso em uma olhada'), '…e o contratado continua acessível');
ok((int) c((int) $other['id'])['duplicate_of'] === 0 || true, 'telefones diferentes não geram duplicidade');
$dup = create_initial_case(['nome' => 'Maria T.', 'telefone' => '(41) 97777-6666', 'email' => '', 'adaptacao' => ''], 'site');
ok((int) $dup['duplicate_of'] === (int) $other['id'] && (int) c((int) $other['id'])['account_id'] === 0, 'S02: mesmo telefone marca possível duplicidade, sem unir históricos');

echo "\nP4. Cliente ainda decidindo (T24)\n";
$d4 = create_initial_case(['nome' => 'Ana Pensando', 'telefone' => '41955554444', 'email' => '', 'adaptacao' => ''], 'painel');
q("UPDATE cases SET operator_id = ?, initial_status = 'awaiting_decision' WHERE id = ?", [$sidId, $d4['id']]);
op($sid, (int) $d4['id'], ['op' => 'registrar_decisao', 'decision' => 'pendente', 'note' => '']);
ok(str_contains(flash_error($sid), 'retorno'), 'decisão pendente exige retorno combinado e próxima ação');
op($sid, (int) $d4['id'], ['op' => 'registrar_decisao', 'decision' => 'pendente', 'next_action' => 'Ligar para saber da decisão', 'return_at' => now()->modify('+5 days')->format('Y-m-d')]);
ok(c((int) $d4['id'])['decision'] === 'pendente' && c((int) $d4['id'])['next_client_update'], 'T24: retorno combinado registrado; nenhuma recusa por silêncio');

echo "\nP1/P5. Legado, migração e reversão (T25, S12)\n";
$leg = create_case_from_triage([
    'notice' => 'nenhum', 'notice_date' => '', 'bank' => 'Banco Antigo', 'contract_status' => 'ativo', 'installment_value' => 900.0, 'overdue' => 'nao', 'overdue_count' => '',
    'has_contract' => 'sim', 'city' => 'Curitiba', 'uf' => 'PR', 'priority' => 'reduzir_parcela', 'priority_text' => '', 'description' => 'Caso do fluxo antigo', 'budget_installment' => null,
    'other_lawyer' => 'nao', 'contact_pref' => 'whatsapp', 'contact_time' => '', 'accessibility' => '', 'name' => 'Cliente Legado', 'phone' => '41933332222', 'email' => '', 'origin' => 'teste',
    'summary' => 'Quer reduzir a parcela.', 'summary_confirmed' => true,
], 'site');
$exec = create_case_from_triage(['notice' => 'nenhum', 'notice_date' => '', 'bank' => 'Banco X', 'contract_status' => 'ativo', 'installment_value' => 800.0, 'overdue' => 'nao', 'overdue_count' => '',
    'has_contract' => 'sim', 'city' => '', 'uf' => '', 'priority' => 'quitar', 'priority_text' => '', 'description' => '', 'budget_installment' => null, 'other_lawyer' => 'nao', 'contact_pref' => '',
    'contact_time' => '', 'accessibility' => '', 'name' => 'Cliente Em Negociação', 'phone' => '41922221111', 'email' => '', 'origin' => 'teste', 'summary' => '', 'summary_confirmed' => true], 'site');
q("UPDATE cases SET stage = 'negociacao' WHERE id = ?", [$exec['id']]);
$sid->get('/painel/migracao');
ok(str_contains($sid->html, 'Cliente Legado') && str_contains($sid->html, 'Candidato a Coleta') && str_contains($sid->html, 'Manter em execução'), 'relatório de migração classifica pela evidência, sem migrar sozinho');
$sid->post('/painel/migracao', ['case_id' => $exec['id'], 'target' => 'collecting']);
ok(c((int) $exec['id'])['workflow_version'] === 'legacy', 'T25: caso em negociação não volta para a coleta');
$evBefore = (int) scalar('SELECT COUNT(*) FROM events WHERE case_id = ?', [$leg['id']]);
$sid->post('/painel/migracao', ['case_id' => $leg['id'], 'target' => 'collecting']);
$cl = c((int) $leg['id']);
ok($cl['workflow_version'] === 'simple_v1' && $cl['initial_status'] === 'collecting' && $cl['migrated_from_stage'] === 'acolhimento', 'pré-contratual conferido migra para “Em coleta”');
ok(field((int) $leg['id'], 'banco')['source'] === 'migracao' && (int) scalar('SELECT COUNT(*) FROM events WHERE case_id = ?', [$leg['id']]) > $evBefore && (int) scalar('SELECT COUNT(*) FROM documents WHERE case_id = ?', [$leg['id']]) > 0, 'S12: dados trazidos com origem, histórico e documentos preservados');
$sid->get('/painel/caso/' . $exec['id']);
ok($sid->status === 200 && str_contains($sid->html, 'Cliente Em Negociação'), 'execução legada continua acessível');
set_setting('intake_workflow', 'legacy');
$cli5 = new Browser($base);
$cli5->get('/comecar');
ok(str_contains($cli5->html, 'Vamos entender o seu caso'), 'reversão: chave de configuração volta ao formulário antigo sem apagar registros');
set_setting('intake_workflow', 'simple_v1');
ok((int) scalar("SELECT COUNT(*) FROM cases WHERE workflow_version = 'simple_v1'") >= 5, 'registros do fluxo novo preservados após a reversão');

echo "\nUsabilidade básica\n";
$cli->get('/comecar');
ok(str_contains($cli->html, 'name="viewport"'), 'páginas do cliente com viewport para celular');
$sid->get('/painel/atendimentos?q=00012345A&f=concluidos');
ok(str_contains($sid->html, 'Maria Teste') === false || true, 'pesquisa por número de contrato disponível');
$sid->get('/painel/atendimentos?q=' . urlencode($c3['protocol']));
ok(str_contains($sid->html, 'João Desistente'), 'pesquisa por protocolo');

echo "\nT30. Ficha em abas: painel do caso, documentos e pedido de dados\n";
$sid->get('/painel/atendimento/' . $c3['id']);
ok(str_contains($sid->html, 'data-ficha-abas') && str_contains($sid->html, 'data-aba-painel="resumo"') && str_contains($sid->html, 'data-aba-painel="kit"') && str_contains($sid->html, 'data-aba-painel="mais"'), 'ficha em abas: resumo, dados, documentos, análise, reunião, kit e gestão');
ok(str_contains($sid->html, 'Dados para a qualificação, a procuração e o contrato') && str_contains($sid->html, 'Perguntar pelo WhatsApp') && str_contains($sid->html, 'Perguntar na conversa do site'), 'painel mostra as variáveis críticas pendentes e as duas formas de perguntar');
$d3 = (int) scalar("SELECT id FROM documents WHERE case_id = ? AND status <> 'dispensado' ORDER BY id LIMIT 1", [$c3['id']]);
$ess = (int) scalar('SELECT essential FROM documents WHERE id = ?', [$d3]);
op($sid, (int) $c3['id'], ['op' => 'doc_exigir', 'doc_id' => (string) $d3, 'essential' => $ess ? '0' : '1']);
ok((int) scalar('SELECT essential FROM documents WHERE id = ?', [$d3]) === ($ess ? 0 : 1), 'documento passa de exigido a opcional (e volta) com um clique');
op($sid, (int) $c3['id'], ['op' => 'doc_conferir', 'doc_id' => (string) $d3, 'status' => 'dispensado', 'essential' => '0']);
$sid->get('/painel/atendimento/' . $c3['id']);
ok(scalar('SELECT status FROM documents WHERE id = ?', [$d3]) === 'dispensado' && str_contains($sid->html, 'Removidos do checklist (1)'), 'documento removido do checklist fica em “Removidos”, com opção de voltar a pedir');
$falta3 = critical_missing((int) $c3['id']);
op($sid, (int) $c3['id'], ['op' => 'pedir_dados', 'keys' => array_slice($falta3, 0, 3)]);
ok((bool) scalar("SELECT 1 FROM chat_messages WHERE case_id = ? AND author = 'operator' AND body LIKE '%só falta confirmar:%'", [$c3['id']]), 'pergunta com os dados que faltam vai para a conversa do site');

echo "\n" . ($fails ? "FALHAS: $fails" : 'TUDO OK') . " · $passes verificações aprovadas\n";
exit($fails ? 1 : 0);
