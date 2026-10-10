<?php
/**
 * Teste de ponta a ponta: percorre o workflow inteiro pela interface HTTP,
 * do formulário do site ao encerramento do caso, usando um banco descartável.
 *
 * Uso: php tests/fluxo_completo.php
 * (sobe um servidor PHP embutido em porta livre e o encerra ao final)
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$db = sys_get_temp_dir() . '/fatuche-teste-' . getmypid() . '.sqlite';
@unlink($db);
putenv('FATUCHE_DB=' . $db);
require $root . '/app/bootstrap.php';

$codeFile = config('storage_dir') . '/codigo-instalacao.txt';
@unlink($codeFile);

$port = 18000 + random_int(0, 900);
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
        $this->get('/robots.txt');
        $this->get('/comecar');
        preg_match('/name="_csrf" value="([^"]+)"/', $this->html, $m);
        return $m[1] ?? '';
    }

    private function req(string $method, string $path, array $data = [], bool $follow = true, bool $multipart = false): string
    {
        $ch = curl_init($this->base . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEJAR => $this->jar,
            CURLOPT_COOKIEFILE => $this->jar,
            CURLOPT_FOLLOWLOCATION => $follow,
            CURLOPT_HEADER => false,
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $multipart ? $data : http_build_query($data));
        }
        $this->html = (string) curl_exec($ch);
        $this->status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $this->location = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        return $this->html;
    }
}

function case_row(): array
{
    return one('SELECT * FROM cases ORDER BY id DESC LIMIT 1');
}

function act(Browser $b, int $id, array $data, array $files = []): void
{
    $b->get('/painel/caso/' . $id);
    $b->post('/painel/caso/' . $id, $data, true, $files);
}

echo "\n0. Instalação pelo navegador (sem terminal)\n";
$team = new Browser($base);
$team->get('/painel/entrar');
ok(str_contains($team->html, 'painel/instalar'), 'tela de login aponta para a instalação quando não há usuários');
$team->get('/painel/instalar');
ok($team->status === 200 && is_file($codeFile), 'instalador cria o arquivo de código em storage/');
$installCode = trim((string) file_get_contents($codeFile));
$team->post('/painel/instalar', ['code' => 'ERRADO1234', 'name' => 'Advogada Teste', 'email' => 'adv@teste.local', 'password' => 'senha-de-teste-1', 'password2' => 'senha-de-teste-1']);
ok((int) scalar('SELECT COUNT(*) FROM users') === 0, 'código errado não cria usuário');
$team->get('/painel/instalar');
$team->post('/painel/instalar', ['code' => strtolower($installCode), 'name' => 'Advogada Teste', 'email' => 'adv@teste.local', 'password' => 'senha-de-teste-1', 'password2' => 'senha-de-teste-1']);
ok((int) scalar('SELECT COUNT(*) FROM users') === 1 && str_contains($team->html, 'casos ativos'), 'código certo cria o primeiro acesso e entra no painel');
ok(!is_file($codeFile), 'arquivo de código apagado após a instalação');
$late = new Browser($base);
$late->get('/painel/instalar');
$late->post('/painel/instalar', ['code' => $installCode, 'name' => 'Intruso', 'email' => 'x@x.local', 'password' => 'outra-senha-123', 'password2' => 'outra-senha-123']);
ok((int) scalar('SELECT COUNT(*) FROM users') === 1, 'instalador fica desativado depois do primeiro acesso');

echo "\n1. Site: triagem conversacional\n";
$client = new Browser($base);
$client->get('/comecar?aviso=banco');
ok($client->status === 200 && str_contains($client->html, 'Vamos entender o seu caso'), 'formulário abre');
sleep(4); // proteção antiautomação exige alguns segundos de preenchimento
$client->post('/comecar', [
    'notice' => 'banco', 'notice_date' => '', 'bank' => 'Banco Teste', 'contract_status' => 'ativo', 'installment_value' => 'R$ 1.450,00',
    'overdue' => 'sim', 'overdue_count' => '2 ou 3 parcelas', 'has_contract' => 'nao', 'city' => 'Curitiba', 'uf' => 'PR',
    'priority' => 'manter_veiculo', 'budget_installment' => 'R$ 950,00', 'description' => 'Uso o carro para trabalhar.',
    'other_lawyer' => 'nao', 'contact_pref' => 'whatsapp', 'contact_time' => 'Tarde', 'accessibility' => '',
    'name' => 'Cliente Teste', 'phone' => '(41) 98888-7777', 'email' => 'cliente@teste.local', 'consent' => '1', 'summary_ok' => '1', 'website' => '',
]);
$c = case_row();
ok($c && $c['client_name'] === 'Cliente Teste', 'caso criado com registro único');
ok(str_contains($client->html, $c['protocol']), 'protocolo exibido ao cliente');
ok((int) $c['urgent'] === 1 && $c['urgency_class'] === 'notificacao', 'notificação do banco gera triagem de urgência');
ok(str_contains((string) $c['summary_sentence'], 'manter o veículo'), 'resumo em uma frase gerado');
ok((int) scalar("SELECT COUNT(*) FROM tasks WHERE case_id = ? AND kind = 'primeiro_contato'", [$c['id']]) === 1, 'tarefa de primeiro contato (meta 1 h útil)');
ok((int) scalar("SELECT COUNT(*) FROM documents WHERE case_id = ? AND kind = 'contrato' AND status = 'ajuda'", [$c['id']]) === 1, 'contrato marcado como “vamos ajudar a obter”');
$id = (int) $c['id'];

echo "\n2. Área do cliente\n";
$client->post('/comecar/acesso', ['password' => 'minha-senha-123']);
ok(str_contains($client->html, 'Seu caso em uma olhada'), 'cliente cria senha e entra');
ok(str_contains($client->html, 'Entendendo seu caso'), 'cliente vê a situação “Entendendo seu caso”');
$pdf = tempnam(sys_get_temp_dir(), 'doc') . '.pdf';
file_put_contents($pdf, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
$docId = (int) scalar("SELECT id FROM documents WHERE case_id = ? AND kind = 'historico'", [$id]);
$client->get('/cliente');
$client->post('/cliente/documento/' . $docId, [], true, ['files[]' => [$pdf, 'application/pdf']]);
ok(scalar('SELECT status FROM documents WHERE id = ?', [$docId]) === 'recebido', 'upload do cliente grava e marca “recebido”');
$fileId = (int) scalar('SELECT id FROM files WHERE document_id = ?', [$docId]);
$client->get('/arquivo/' . $fileId);
ok($client->status === 200, 'cliente abre o próprio arquivo');
$stranger = new Browser($base);
$stranger->get('/arquivo/' . $fileId);
ok($stranger->status === 403, 'visitante sem login não acessa o arquivo');
$client->get('/cliente');
$client->post('/cliente/mensagem', ['message' => 'Recebi outra carta hoje.']);
ok((int) scalar("SELECT COUNT(*) FROM tasks WHERE case_id = ? AND kind = 'recado'", [$id]) === 1, 'recado do cliente vira tarefa para o gestor');

echo "\n3. Painel: acolhimento e urgência (etapas 1 e 2)\n";
$team = new Browser($base);
$team->get('/painel/entrar');
$team->post('/painel/entrar', ['email' => 'adv@teste.local', 'password' => 'senha-de-teste-1']);
ok(str_contains($team->html, 'A fazer agora'), 'equipe entra no painel pela fila de trabalho');
act($team, $id, ['op' => 'etapa', 'to' => 'urgencia', 'reason' => '']);
ok(case_row()['stage'] === 'acolhimento', 'avanço bloqueado sem primeiro contato, objetivo e condições');
act($team, $id, ['op' => 'primeiro_contato', 'channel' => 'WhatsApp']);
act($team, $id, ['op' => 'objetivo', 'objective' => 'Manter o carro com parcela de até R$ 950', 'objective_measure' => 'Primeiro boleto renegociado correto', 'summary_confirmed' => '1']);
act($team, $id, ['op' => 'conflito', 'conflict' => 'sem_conflito', 'note' => 'Sem processos com o banco']);
act($team, $id, ['op' => 'check', 'stage' => 'acolhimento', 'item' => 'cliente_compreende', 'done' => '1']);
act($team, $id, ['op' => 'urgencia', 'urgency_class' => 'notificacao', 'note' => 'Notificação extrajudicial do banco, sem ação ajuizada.']);
act($team, $id, ['op' => 'etapa', 'to' => 'urgencia', 'reason' => '']);
ok(case_row()['stage'] === 'urgencia', 'avança para triagem de urgência quando as condições são cumpridas');
act($team, $id, ['op' => 'orientacao_escrita', 'text' => 'Continue pagando pelo boleto oficial. Se chegar nova carta, envie no mesmo dia.']);
act($team, $id, ['op' => 'prazo', 'description' => 'Prazo da notificação', 'trigger_event' => 'Recebimento da carta', 'due_at' => now()->modify('+10 days')->format('Y-m-d\T18:00'), 'owner_id' => '1', 'substitute_id' => '1']);
act($team, $id, ['op' => 'etapa', 'to' => 'documentos', 'reason' => '']);
ok(case_row()['stage'] === 'documentos', 'etapa 3: documentos');

echo "\n4. Documentos e auditoria (etapas 3 e 4)\n";
act($team, $id, ['op' => 'doc_status', 'doc_id' => $docId, 'status' => 'conferido']);
ok(scalar('SELECT status FROM documents WHERE id = ?', [$docId]) === 'conferido', 'documento conferido');
act($team, $id, ['op' => 'pedido_banco', 'channel' => 'SAC', 'protocol' => 'P-1', 'requested_at' => now()->format('Y-m-d'), 'bank_deadline' => now()->modify('+5 days')->format('Y-m-d'), 'items' => 'Contrato']);
ok((int) scalar("SELECT COUNT(*) FROM tasks WHERE case_id = ? AND kind = 'cobrar_banco'", [$id]) === 1, 'pedido ao banco gera cobrança 2 dias úteis após o prazo');
act($team, $id, ['op' => 'etapa', 'to' => 'auditoria', 'reason' => '']);
ok(case_row()['stage'] === 'documentos', 'auditoria bloqueada sem suficiência avaliada pelo advogado');
act($team, $id, ['op' => 'check', 'stage' => 'documentos', 'item' => 'dossie_conferido', 'done' => '1']);
act($team, $id, ['op' => 'check', 'stage' => 'documentos', 'item' => 'suficiencia', 'done' => '1']);
act($team, $id, ['op' => 'etapa', 'to' => 'auditoria', 'reason' => '']);
ok(case_row()['stage'] === 'auditoria', 'etapa 4: auditoria');
act($team, $id, ['op' => 'etapa', 'to' => 'decisao', 'reason' => '']);
ok(case_row()['stage'] === 'auditoria', 'decisão bloqueada sem cálculo validado');
act($team, $id, ['op' => 'auditoria', 'principal' => '50000', 'installments' => '48', 'installment_value' => '1626,78', 'monthly_rate' => '1,99', 'bcb_rate' => '1,60', 'paid_installments' => '12', 'conclusion' => 'negociacao', 'summary_client' => 'Taxa acima da média; negociação prioritária.', 'validate' => '1']);
$calc = audit_calc(case_data(case_row(), 'audit'));
ok($calc['ratio'] !== null && abs($calc['ratio'] - 1.99 / 1.6) < 0.001, 'razão taxa contratada ÷ média calculada');
ok($calc['implied_rate'] !== null && abs($calc['implied_rate'] - 1.99) < 0.01 && $calc['reproduces'] === true, 'cálculo reproduz a operação (taxa implícita = contratada)');
act($team, $id, ['op' => 'etapa', 'to' => 'decisao', 'reason' => '']);
ok(case_row()['stage'] === 'decisao', 'etapa 5: decisão do cliente');

echo "\n5. Decisão informada e contratação (etapas 5 e 6)\n";
act($team, $id, ['op' => 'decisao_nova', 'kind' => 'caminho', 'question' => 'Qual caminho você prefere seguir?', 'options' => "Negociar com o credor\nRevisão judicial", 'context' => 'Recomendação: negociar.', 'answer' => '']);
act($team, $id, ['op' => 'check', 'stage' => 'decisao', 'item' => 'objetivo_mensuravel', 'done' => '1']);
act($team, $id, ['op' => 'etapa', 'to' => 'contratacao', 'reason' => '']);
ok(case_row()['stage'] === 'decisao', 'contratação bloqueada sem decisão documentada');
$decId = (int) scalar("SELECT id FROM decisions WHERE case_id = ? AND kind = 'caminho'", [$id]);
$client->get('/cliente');
ok(str_contains($client->html, 'Decisões que dependem de você'), 'cliente vê a decisão pendente');
$client->post('/cliente/decisao/' . $decId, ['answer' => 'Negociar com o credor', 'reason' => 'Quero resolver rápido']);
ok(scalar('SELECT answer FROM decisions WHERE id = ?', [$decId]) === 'Negociar com o credor', 'decisão registrada pelo cliente, por escrito');
act($team, $id, ['op' => 'etapa', 'to' => 'contratacao', 'reason' => '']);
ok(case_row()['stage'] === 'contratacao', 'etapa 6: contratação');
act($team, $id, ['op' => 'contratacao', 'scope' => 'Negociação', 'excluded' => 'Ação judicial', 'fees' => 'Conforme contrato', 'example' => '—', 'expenses' => '—', 'signed_at' => now()->format('Y-m-d')]);
act($team, $id, ['op' => 'check', 'stage' => 'contratacao', 'item' => 'escopo_compreendido', 'done' => '1']);
act($team, $id, ['op' => 'etapa', 'to' => 'negociacao', 'reason' => '']);
ok(case_row()['stage'] === 'negociacao', 'etapa 7: negociação');

echo "\n6. Negociação e acordo (etapas 7 e 8)\n";
act($team, $id, ['op' => 'limites', 'max_down' => '1000', 'max_installment' => '950', 'pay_date' => 'dia 10', 'priority' => 'Manter o carro', 'creditor' => 'Banco Teste', 'authorized_at' => now()->format('Y-m-d')]);
act($team, $id, ['op' => 'oferta', 'origin' => 'credor', 'round' => '1', 'creditor' => 'Banco Teste', 'ref_balance' => '40000', 'down_payment' => '800', 'installments' => '48', 'installment_value' => '920', 'valid_until' => now()->modify('+5 days')->format('Y-m-d')]);
$offerId = (int) scalar('SELECT id FROM offers WHERE case_id = ?', [$id]);
ok(abs(offer_total(one('SELECT * FROM offers WHERE id = ?', [$offerId])) - (800 + 48 * 920)) < 0.01, 'total da oferta = entrada + parcelas');
act($team, $id, ['op' => 'oferta_status', 'offer_id' => $offerId, 'status' => 'aceita', 'confirm_via' => '']);
ok(scalar('SELECT status FROM offers WHERE id = ?', [$offerId]) === 'recebida', 'não aceita oferta sem autorização do cliente');
act($team, $id, ['op' => 'oferta_comunicar', 'offer_id' => $offerId, 'recommendation' => 'Cabe no orçamento autorizado']);
$offerDec = (int) scalar("SELECT id FROM decisions WHERE case_id = ? AND kind = 'oferta'", [$id]);
$client->get('/cliente');
$client->post('/cliente/decisao/' . $offerDec, ['answer' => 'Autorizo aceitar a proposta']);
act($team, $id, ['op' => 'oferta_status', 'offer_id' => $offerId, 'status' => 'aceita', 'confirm_via' => '']);
ok(scalar('SELECT status FROM offers WHERE id = ?', [$offerId]) === 'aceita', 'oferta aceita após autorização registrada');
act($team, $id, ['op' => 'etapa', 'to' => 'acordo', 'reason' => '']);
ok(case_row()['stage'] === 'acordo', 'etapa 8: acordo');
act($team, $id, ['op' => 'parcela_add', 'description' => 'Parcela do acordo', 'due_date' => now()->modify('+20 days')->format('Y-m-d'), 'amount' => '920', 'repeat' => '3']);
ok((int) scalar('SELECT COUNT(*) FROM schedule WHERE case_id = ?', [$id]) === 3, 'calendário de parcelas criado');
act($team, $id, ['op' => 'acordo', 'signed_at' => now()->format('Y-m-d'), 'instrument' => 'Termo', 'balance' => '40000', 'discount' => '', 'releases' => 'Boletos mensais', 'vehicle' => '', 'boleto_checked' => '1', 'homologation' => 'nao_ha_processo']);
act($team, $id, ['op' => 'etapa', 'to' => 'conferencia', 'reason' => '']);
ok(case_row()['stage'] === 'conferencia', 'etapa 13: conferência');

echo "\n7. Conferência e encerramento (etapas 13 e 14)\n";
act($team, $id, ['op' => 'etapa', 'to' => 'encerramento', 'reason' => '']);
ok(case_row()['stage'] === 'conferencia', 'encerramento bloqueado sem evidência da solução');
act($team, $id, ['op' => 'solucao', 'applies' => ['renegociada' => '1'], 'evidence' => ['renegociada' => 'Termo + 1º boleto de R$ 920 conferido'], 'done' => ['renegociada' => '1'], 'scr' => 'Sem divergência', 'practical_test' => 'Cliente confirmou o boleto']);
act($team, $id, ['op' => 'etapa', 'to' => 'encerramento', 'reason' => '']);
ok(case_row()['stage'] === 'encerramento', 'etapa 14: encerramento');
act($team, $id, ['op' => 'encerrar', 'outcome' => 'monitoramento', 'summary' => 'Prestação renegociada.', 'client_duties' => 'Pagar até o dia 10']);
ok(case_row()['status'] === 'ativo', 'não encerra sem a lista obrigatória');
foreach (array_keys(CLOSING_LIST) as $k) {
    act($team, $id, ['op' => 'check', 'stage' => 'encerramento', 'item' => $k, 'done' => '1']);
}
act($team, $id, ['op' => 'encerrar', 'outcome' => 'monitoramento', 'summary' => 'Prestação renegociada; acordo em cumprimento.', 'client_duties' => 'Pagar até o dia 10']);
$c = case_row();
ok($c['status'] === 'encerrado' && $c['outcome'] === 'monitoramento', 'caso encerrado com categoria de desfecho');
ok((int) scalar("SELECT COUNT(*) FROM tasks WHERE case_id = ? AND kind = 'pos_30_dias'", [$id]) === 1, 'contato de 30 dias agendado');
$client->get('/cliente');
ok(str_contains($client->html, 'Como foi resolver com a gente?'), 'cliente recebe pedido de avaliação');
$client->post('/cliente/avaliacao', ['ease' => '5', 'simplify' => 'Nada']);
ok((int) scalar('SELECT ease FROM feedback WHERE case_id = ?', [$id]) === 5, 'avaliação registrada');
$team->get('/painel/indicadores');
ok($team->status === 200 && str_contains($team->html, 'Tempo até resposta humana'), 'indicadores calculados');

echo "\n8. Modo guiado: fila e passo a passo\n";
$gid = insert('users', ['name' => 'Gestora Guiada', 'email' => 'gestora@teste.local', 'phone' => null, 'roles' => 'gestor', 'pass_hash' => password_hash('senha-gestora-1', PASSWORD_DEFAULT), 'active' => 1, 'created_at' => now_str()]);
$advId = (int) scalar("SELECT id FROM users WHERE email = 'adv@teste.local'");
$g = create_case_from_triage([
    'notice' => 'nenhum', 'notice_date' => '', 'bank' => 'Banco Guiado', 'contract_status' => 'ativo', 'installment_value' => 1200.0,
    'overdue' => 'nao', 'overdue_count' => '', 'has_contract' => 'sim', 'city' => 'Curitiba', 'uf' => 'PR', 'priority' => 'reduzir_parcela',
    'priority_text' => '', 'description' => '', 'budget_installment' => 900.0, 'other_lawyer' => 'nao', 'contact_pref' => 'whatsapp',
    'contact_time' => '', 'accessibility' => '', 'name' => 'Cliente Guiado', 'phone' => '(41) 97777-6666', 'email' => '', 'origin' => 'teste',
    'summary' => 'Quer reduzir a parcela.', 'summary_confirmed' => false,
], 'whatsapp');
$gcid = (int) $g['id'];
q('UPDATE cases SET gestor_id = ?, advogado_id = ? WHERE id = ?', [$gid, $advId, $gcid]);
$ges = new Browser($base);
$ges->get('/painel/entrar');
$ges->post('/painel/entrar', ['email' => 'gestora@teste.local', 'password' => 'senha-gestora-1']);
ok(str_contains($ges->html, 'Cliente Guiado') && str_contains($ges->html, 'Fazer o primeiro contato'), 'fila da gestora mostra o caso e o próximo passo');
$ges->get('/painel/executar/' . $gcid);
ok(str_contains($ges->html, 'O que fazer') && str_contains($ges->html, 'O que dizer ao cliente'), 'passo abre com instruções e mensagem pronta');
$volta = fn(string $k) => 'executar/' . $gcid . '?passo=' . $k . '&apos=1';
$ges->post('/painel/caso/' . $gcid, ['op' => 'primeiro_contato', 'channel' => 'WhatsApp', 'volta' => $volta('contato')]);
ok(str_contains($ges->html, 'Conferir o que o cliente contou'), 'ao salvar, o fluxo avança sozinho para o próximo passo');
$ges->post('/painel/executar/' . $gcid, ['op' => 'escolha', 'passo' => 'entender', 'escolha' => 'ok']);
ok(str_contains($ges->html, 'Registrar o objetivo'), 'botão de conclusão marca o passo e segue');
$ges->post('/painel/caso/' . $gcid, ['op' => 'objetivo', 'objective' => 'Parcela de até R$ 900', 'objective_measure' => 'Boleto novo', 'summary_confirmed' => '1', 'volta' => $volta('objetivo')]);
$ges->post('/painel/caso/' . $gcid, ['op' => 'conflito', 'conflict' => 'sem_conflito', 'note' => '', 'volta' => $volta('conflito')]);
$ges->post('/painel/executar/' . $gcid, ['op' => 'escolha', 'passo' => 'urgencia', 'escolha' => 'sem']);
$ges->post('/painel/executar/' . $gcid, ['op' => 'escolha', 'passo' => 'proximo', 'escolha' => 'ok']);
ok(str_contains($ges->html, 'Passar o caso adiante') && str_contains($ges->html, 'sugerido'), 'etapa concluída leva à tela de passar adiante, com sugestão');
$ges->post('/painel/executar/' . $gcid, ['op' => 'avancar', 'to' => 'documentos', 'reason' => '']);
ok(find_case($gcid)['stage'] === 'documentos' && str_contains($ges->html, 'Enviar a lista de documentos'), 'caso avança e a gestora continua na nova etapa');
$ges->post('/painel/executar/' . $gcid, ['op' => 'escolha', 'passo' => 'suficiencia', 'escolha' => 'ok']);
ok(str_contains($ges->html, 'Este passo é de') || !find_case($gcid)['docs_sufficient_at'], 'gestora não executa passo do advogado');
$ges->post('/painel/executar/' . $gcid, ['op' => 'escolha', 'passo' => 'pedir', 'escolha' => 'ok']);
$ges->post('/painel/executar/' . $gcid, ['op' => 'escolha', 'passo' => 'conferir', 'escolha' => 'ok']);
ok(str_contains($ges->html, 'A fazer agora') && str_contains($ges->html, 'Sua parte'), 'quando a parte dela termina, volta para a fila');
$team->get('/painel/fila');
ok(str_contains($team->html, 'Cliente Guiado') && str_contains($team->html, 'Avaliar se os documentos bastam'), 'caso aparece na fila do advogado');
$team->post('/painel/executar/' . $gcid, ['op' => 'escolha', 'passo' => 'suficiencia', 'escolha' => 'ok']);
ok((bool) find_case($gcid)['docs_sufficient_at'], 'advogado conclui o passo dele');
$ges->get('/painel/fila');
ok(str_contains($ges->html, 'Passar o caso para a próxima etapa'), 'gestora recebe o caso de volta para avançar a etapa');

echo "\n9. Segurança básica\n";
$anon = new Browser($base);
$anon->get('/painel');
ok(str_contains($anon->location . $anon->html, 'Entrar'), 'painel exige login');
$anon->get('/cliente');
ok(str_contains($anon->html, 'Acompanhe o seu caso'), 'área do cliente exige login');
$anon->get('/comecar');
$anon->post('/comecar', ['_csrf' => 'invalido', 'notice' => 'banco']);
ok($anon->status === 419, 'POST sem CSRF válido é recusado');

echo "\n" . ($fails ? "FALHAS: $fails" : 'TUDO OK') . " · $passes verificações aprovadas\n";
exit($fails ? 1 : 0);
