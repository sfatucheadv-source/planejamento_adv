<?php
/**
 * Conversas do cliente: conta criada no formulário, vários atendimentos
 * (um por contrato) abertos pela própria pessoa, tela de conversas, marcação
 * de novidades, avisos por e-mail com link de acesso e sigilo entre contas.
 *
 * Uso: php tests/conversas.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$db = sys_get_temp_dir() . '/fatuche-conversas-' . getmypid() . '.sqlite';
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


$mailDir = config('storage_dir') . '/mail';
$clearMail = function () use ($mailDir) {
    foreach (glob($mailDir . '/*.eml') ?: [] as $f) {
        unlink($f);
    }
};
$mails = fn() => array_map(fn($f) => quoted_printable_decode((string) file_get_contents($f)), glob($mailDir . '/*.eml') ?: []);
$mailText = function (string $raw): string {
    // Corpo em base64: devolve o texto legível das partes.
    $out = $raw;
    if (preg_match_all('/Content-Transfer-Encoding: base64\r\n\r\n([A-Za-z0-9+\/=\r\n]+)/', $raw, $m)) {
        foreach ($m[1] as $b) {
            $out .= "\n" . base64_decode(preg_replace('/\s+/', '', $b));
        }
    }
    return $out;
};
$opMsg = function (int $caseId, string $body, string $ago = '-5 minutes'): void {
    insert('chat_messages', ['case_id' => $caseId, 'author' => 'operator', 'body' => $body, 'created_at' => now()->modify($ago)->format('Y-m-d H:i:s')]);
};

echo "\nC1. Instalação\n";
$sid = new Browser($base);
$sid->get('/painel/instalar');
$code = trim((string) file_get_contents($codeFile));
$sid->post('/painel/instalar', ['code' => $code, 'name' => 'Sidney Teste', 'email' => 'sidney@teste.local', 'password' => 'senha-do-sidney-1', 'password2' => 'senha-do-sidney-1']);
ok(str_contains($sid->url, '/painel'), 'painel instalado');
set_setting('mail_driver', 'arquivo');
// As rodadas automáticas do servidor ficam paradas; o teste chama notify_tick() quando quer.
set_setting('notify_last_run', '9999-12-31 00:00:00');
$clearMail();

echo "\nC2. Formulário cria a conta e abre a conversa\n";
$ana = new Browser($base);
$id1 = start_client($ana, 'Ana Paula Souza', '41999990001');
$c1 = c($id1);
ok((bool) $c1['account_id'], 'o atendimento já nasce ligado a uma conta (sem senha)');
$acc = (int) $c1['account_id'];
ok(scalar('SELECT pass_hash FROM client_accounts WHERE id = ?', [$acc]) === null && scalar('SELECT email FROM client_accounts WHERE id = ?', [$acc]) === 'ana@teste.local', 'conta com o e-mail informado e ainda sem senha');
$ana->get('/cliente');
ok($ana->status === 200 && str_contains($ana->html, 'class="app ') && str_contains($ana->html, 'data-chat-log'), 'a área do cliente abre direto na conversa, no formato de aplicativo');
ok(str_contains($ana->html, 'class="app-body"') && str_contains($ana->html, 'class="app-chat"') && str_contains($ana->html, 'class="app-compose"'), 'tela fixa: só a conversa e a lista rolam');
ok(substr_count($ana->html, 'data-conv="') === 1 && str_contains($ana->html, 'is-on'), 'a lista mostra o atendimento aberto');
ok(str_contains($ana->html, 'cliente/criar-senha') && str_contains($ana->html, 'Avisos por e-mail'), 'criar senha e avisos ficam no rodapé da lista');
$ana->get('/comecar');
ok(str_contains($ana->url, '/cliente'), 'cliente já identificado que clica em “Atendimento online” volta para as conversas');

echo "\nC3. Novo atendimento pelo chat\n";
$ana->get('/cliente/conversas');
ok($ana->status === 200 && str_contains($ana->html, 'app--list') && str_contains($ana->html, 'Novo atendimento'), 'lista de conversas com o botão de novo atendimento');
$ana->post('/cliente/novo', []);
$id2 = (int) scalar('SELECT MAX(id) FROM cases');
$c2 = c($id2);
ok($id2 !== $id1 && (int) $c2['account_id'] === $acc, 'o segundo atendimento fica na mesma conta');
ok($c2['duplicate_of'] === null, 'não é marcado como duplicidade (mesmo telefone, mesma conta)');
ok($c2['client_name'] === 'Ana Paula Souza' && $c2['client_email'] === 'ana@teste.local' && only_digits((string) $c2['client_phone']) === '41999990001', 'nome, e-mail e telefone reaproveitados, sem perguntar de novo');
ok(str_contains($ana->url, 'area=conversa') && str_contains($ana->html, $c2['protocol']), 'abre direto a conversa nova');
ok(!str_contains($ana->html, 'Protocolo ' . $c1['protocol']) && substr_count($ana->html, 'data-conv="') === 2, 'a conversa nova é separada; as duas aparecem na lista');
$chat2 = implode("\n", array_column(all('SELECT body FROM chat_messages WHERE case_id = ?', [$id2]), 'body'));
ok(!str_contains($chat2, $c1['protocol']), 'nada do primeiro atendimento é levado para a conversa nova');
ok((bool) one("SELECT 1 FROM events WHERE case_id = ? AND type = 'vinculo'", [$id2]), 'o painel registra que o mesmo cliente tem outro atendimento');
$ana->post('/cliente/novo', []);
$id3 = (int) scalar('SELECT MAX(id) FROM cases');
$ana->post('/cliente/novo', []);
ok((int) scalar('SELECT MAX(id) FROM cases') === $id3 && str_contains(flash_error($ana), 'atendimentos em andamento'), 'limite de 3 atendimentos em andamento por conta');
$ana->get('/cliente/trocar/' . $id1);
ok(str_contains($ana->html, '· ' . $c1['protocol'] . ' ·') && str_contains($ana->html, 'aria-current="page"'), 'entrar e sair das conversas pela lista');

echo "\nC4. Sigilo entre contas\n";
$bia = new Browser($base);
$idB = start_client($bia, 'Beatriz Lima Costa', '41999990002');
ok((int) c($idB)['account_id'] !== $acc, 'outra pessoa ganha outra conta');
$bia->get('/cliente/trocar/' . $id1);
ok($bia->status === 403, 'não abre atendimento de outra conta');
$bia->get('/cliente/conversas');
ok(substr_count($bia->html, 'data-conv="') === 1 && !str_contains($bia->html, $c1['protocol']), 'a lista mostra só os atendimentos da própria conta');
$bia->get('/cliente/conversas/novidades');
$j = json_decode($bia->html, true);
ok(is_array($j) && array_keys($j['badges']) === [$idB], 'a consulta de novidades só devolve os próprios atendimentos');

echo "\nC5. Marcação de novidades\n";
$opMsg($id1, 'Ana, recebi seu contrato. Pode me mandar também o extrato?');
$ana->get('/cliente/trocar/' . $id2);
ok(preg_match('#data-conv="' . $id1 . '"[^>]*>.*?data-badge aria-label="1#s', $ana->html) === 1 && preg_match('#class="conv-item has-new"[^>]*data-conv="' . $id1 . '"#', $ana->html) === 1, 'o atendimento com mensagem nova fica marcado na lista, com o número');
ok(str_starts_with(preg_match('#<title>([^<]+)#', $ana->html, $t) ? $t[1] : '', '(1)'), 'o título da aba mostra quantas novidades há');
$ana->get('/cliente/conversas/novidades');
$j = json_decode($ana->html, true);
ok((int) ($j['badges'][$id1] ?? -1) === 1 && (int) ($j['badges'][$id2] ?? -1) === 0, 'a lista se atualiza sozinha pela consulta de novidades');
$ana->get('/cliente/trocar/' . $id1);
ok(str_contains($ana->html, 'Pode me mandar também o extrato?'), 'ao abrir, a mensagem aparece na conversa');
$ana->get('/cliente/conversas/novidades');
$j = json_decode($ana->html, true);
ok((int) $j['badges'][$id1] === 0, 'depois de vista, a marcação some');
$opMsg($id1, 'Mais uma coisa: qual é a placa?', '-1 minutes');
$ana->get('/cliente/conversa/novas?depois=0&visivel=0');
ok((int) c($id1)['client_seen_msg_id'] < (int) scalar('SELECT MAX(id) FROM chat_messages WHERE case_id = ?', [$id1]), 'com a aba escondida, a consulta não marca como visto');
$ana->get('/cliente/conversa/novas?depois=0');
ok((int) c($id1)['client_seen_msg_id'] === (int) scalar('SELECT MAX(id) FROM chat_messages WHERE case_id = ?', [$id1]), 'com a conversa aberta na tela, a mensagem nova já conta como vista');

echo "\nC6. Aviso por e-mail\n";
$clearMail();
set_setting('notify_delay_min', '3');
$opMsg($id2, 'Ana, sobre o segundo contrato: qual é o banco?', '-10 minutes');
$opMsg($idB, 'Beatriz, recebemos seus dados.', '-1 minutes');
ok(notify_tick(true) === 1, 'uma rodada envia só o que passou da espera (a mensagem de 1 minuto espera)');
$m = $mails();
ok(count($m) === 1 && str_contains($m[0], 'To: ana@teste.local'), 'o e-mail vai para o endereço da conta');
$txt = $mailText($m[0] ?? '');
ok(str_contains($txt, c($id2)['protocol']) && str_contains($txt, '/cliente/acesso/'), 'o e-mail traz o protocolo e o link de acesso');
ok(!str_contains($txt, 'qual é o banco'), 'o e-mail não leva o conteúdo da conversa');
ok(notify_tick(true) === 0, 'a mesma novidade não gera um segundo e-mail');
q('UPDATE cases SET client_notified_at = ? WHERE id = ?', [now()->modify('-8 minutes')->format('Y-m-d H:i:s'), $id2]); // o aviso anterior foi há 8 minutos
$opMsg($id2, 'Também preciso do CPF.', '-4 minutes');
ok(notify_tick(true) === 1, 'novidade posterior ao aviso gera um novo e-mail');
q('UPDATE client_accounts SET notify_email = 0 WHERE id = ?', [(int) c($idB)['account_id']]);
$opMsg($idB, 'Beatriz, outra mensagem.', '-20 minutes');
ok(notify_tick(true) === 0, 'quem desligou os avisos não recebe e-mail');
ok((int) scalar("SELECT COUNT(*) FROM mail_log WHERE kind = 'aviso' AND ok = 1") === 2, 'os envios ficam registrados');
$bia->post('/cliente/avisos', ['avisos' => '1']);
ok((int) scalar('SELECT notify_email FROM client_accounts WHERE id = ?', [(int) c($idB)['account_id']]) === 1, 'o cliente liga e desliga os avisos pela área do cliente');

echo "\nC7. Link de acesso\n";
preg_match('#/cliente/acesso/([A-Za-z0-9_-]+)#', $mailText($mails()[0] ?? ''), $lk);
$novo = new Browser($base);
$novo->get('/cliente/acesso/' . ($lk[1] ?? 'x'));
ok($novo->status === 200 && str_contains($novo->html, 'data-chat-log') && str_contains($novo->html, c($id2)['protocol']), 'o link do e-mail entra na conta e abre o atendimento da novidade');
ok(substr_count($novo->html, 'data-conv="') === 3, 'com as demais conversas da conta na lista');
$x = new Browser($base);
$x->get('/cliente/acesso/token-que-nao-existe-123456');
ok(str_contains($x->url, '/cliente/entrar') && str_contains(flash_error($x), 'link venceu'), 'link inválido não entra');
q("UPDATE client_access_links SET expires_at = '2000-01-01 00:00:00'");
$y = new Browser($base);
$y->get('/cliente/acesso/' . ($lk[1] ?? 'x'));
ok(str_contains($y->url, '/cliente/entrar'), 'link vencido não entra');

echo "\nC8. Pedir link pelo e-mail e criar senha\n";
$clearMail();
$z = new Browser($base);
$z->get('/cliente/entrar');
ok(str_contains($z->html, 'cliente/link'), 'a tela de entrada oferece o link por e-mail');
$z->post('/cliente/link', ['email' => 'ana@teste.local']);
$m = $mails();
ok(count($m) === 1 && str_contains($mailText($m[0]), '/cliente/acesso/'), 'quem tem atendimento recebe o link');
$z->post('/cliente/link', ['email' => 'ninguem@teste.local']);
ok(count($mails()) === 1 && str_contains($z->html, 'Se este e-mail estiver'), 'e-mail desconhecido: mesma resposta, nenhum envio');
$ana->post('/cliente/criar-senha', ['password' => 'senha-da-ana-1', 'password2' => 'senha-da-ana-1', 'volta' => 'conversas']);
ok(password_verify('senha-da-ana-1', (string) scalar('SELECT pass_hash FROM client_accounts WHERE id = ?', [$acc])), 'senha criada pela lista de conversas vale para a conta');
$w = new Browser($base);
$w->get('/cliente/entrar');
$w->post('/cliente/entrar', ['protocol' => c($id2)['protocol'], 'password' => 'senha-da-ana-1']);
ok(str_contains($w->html, 'data-chat-log') && substr_count($w->html, 'data-conv="') === 3, 'entrando com o protocolo de qualquer atendimento, a pessoa vê todas as conversas');

echo "\nC9. Painel: configuração de e-mail\n";
$sid->get('/painel/configuracoes/email');
ok(str_contains($sid->html, 'id="email"') && str_contains($sid->html, 'smtp_host'), 'seção de e-mails na tela de configurações');
preg_match('/name="_csrf" value="([^"]+)"/', $sid->html, $cs);
$sid->post('/painel/configuracoes', ['_csrf' => $cs[1], 'intake_workflow' => 'simple_v1', 'mail_driver' => 'smtp', 'smtp_host' => 'smtp.exemplo.com', 'smtp_port' => '465', 'smtp_user' => 'avisos@exemplo.com', 'smtp_pass' => 'segredo-smtp-1', 'notify_delay_min' => '15']);
setting('__reset');
ok(setting('smtp_host') === 'smtp.exemplo.com' && setting('smtp_pass') === 'segredo-smtp-1' && (int) setting('notify_delay_min') === 15, 'SMTP salvo');
$sid->get('/painel/configuracoes/email');
ok(!str_contains($sid->html, 'segredo-smtp-1') && str_contains($sid->html, '(configurada)'), 'a senha SMTP nunca volta para a tela');
preg_match('/name="_csrf" value="([^"]+)"/', $sid->html, $cs);
$sid->post('/painel/configuracoes', ['_csrf' => $cs[1], 'intake_workflow' => 'simple_v1', 'mail_driver' => 'smtp', 'smtp_host' => 'smtp.exemplo.com', 'smtp_port' => '465', 'smtp_user' => 'avisos@exemplo.com', 'smtp_pass' => '']);
setting('__reset');
ok(setting('smtp_pass') === 'segredo-smtp-1', 'campo em branco mantém a senha');

echo "\nC10. Painel de controles\n";
$sid->get('/painel/configuracoes');
ok(str_contains($sid->html, 'Painel de controles') && substr_count($sid->html, 'role="switch"') === 4 && str_contains($sid->html, 'cfg-tile'), 'Configurações abre como painel de controles: 4 chaves e um quadro por área');
ok(str_contains($sid->html, 'O que precisa de atenção') && str_contains($sid->html, 'painel/configuracoes/asaas'), 'lista o que precisa de atenção, com o atalho para resolver');
foreach (['atendimento', 'ia', 'email', 'asaas'] as $sec) {
    $sid->get('/painel/configuracoes/' . $sec);
    ok($sid->status === 200 && str_contains($sid->html, 'name="secao" value="' . $sec . '"') && str_contains($sid->html, 'Painel de controles'), "página própria: $sec, com volta ao painel");
}
$sid->get('/painel/configuracoes');
preg_match('/name="_csrf" value="([^"]+)"/', $sid->html, $cs);
$sid->post('/painel/configuracoes', ['_csrf' => $cs[1], 'op' => 'alternar', 'controle' => 'avisos', 'ligar' => '0']);
setting('__reset');
ok(mail_driver() === 'off' && str_contains($sid->html, 'Avisos por e-mail desligados'), 'chave desliga os avisos por e-mail');
$sid->post('/painel/configuracoes', ['_csrf' => $cs[1], 'op' => 'alternar', 'controle' => 'avisos', 'ligar' => '1']);
setting('__reset');
ok(mail_driver() === 'smtp', 'ao religar, volta ao envio que estava em uso (SMTP)');
$sid->post('/painel/configuracoes', ['_csrf' => $cs[1], 'op' => 'alternar', 'controle' => 'ia', 'ligar' => '1']);
setting('__reset');
ok(setting('llm_enabled') !== '1' && str_contains(flash_error($sid), 'chave'), 'não liga a IA sem chave');
$before = [setting('operator_user_id'), setting('llm_enabled'), setting('asaas_env')];
$sid->post('/painel/configuracoes', ['_csrf' => $cs[1], 'secao' => 'email', 'mail_driver' => 'smtp', 'smtp_host' => 'smtp.exemplo.com', 'smtp_port' => '587', 'smtp_user' => 'avisos@exemplo.com', 'smtp_pass' => '', 'notify_delay_min' => '20']);
setting('__reset');
ok((int) setting('smtp_port') === 587 && [setting('operator_user_id'), setting('llm_enabled'), setting('asaas_env')] === $before && str_contains($sid->url, '/painel/configuracoes/email'), 'salvar uma área não mexe nas outras e volta para a mesma página');

echo "\nC11. Cada atendimento identificado pelo veículo; navegação e gestão no painel\n";
foreach (['veiculo_marca' => 'Fiat', 'veiculo_modelo' => 'Argo', 'veiculo_placa' => 'ABC1D23', 'banco' => 'Banco Aymoré'] as $k => $v) {
    set_field($id1, $k, $v);
}
$ana->get('/cliente/conversas');
$c1 = c($id1);
ok(str_contains($ana->html, '<strong>Fiat Argo · ' . field_display('veiculo_placa', case_fields($id1, true)['veiculo_placa']) . '</strong>') && str_contains($ana->html, 'Banco Aymoré · início ' . fmt_date(substr($c1['created_at'], 0, 10))), 'na lista do cliente: veículo e placa no título, banco e data de início embaixo');
ok(str_contains($ana->html, '<strong>Veículo a informar</strong>') && !str_contains($ana->html, '<strong>Novo atendimento</strong>'), 'sem veículo ainda: “Veículo a informar” no lugar de “Novo atendimento”');
$ana->get('/cliente/trocar/' . $id2);
ok(!str_contains($ana->html, 'Fiat Argo') || !str_contains(substr($ana->html, strpos($ana->html, 'app-bar-title')), 'Fiat Argo'), 'cada conversa com o seu próprio histórico e cabeçalho');
$sid->get('/painel/atendimentos');
ok(substr_count($sid->html, 'class="at-row') >= 3 && str_contains($sid->html, 'atendimentos do cliente'), 'no painel, cada atendimento aparece como item próprio, marcado como do mesmo cliente');
$sid->get('/painel/atendimento/' . $id2);
ok(str_contains($sid->html, 'atendimentos deste cliente') && str_contains($sid->html, 'href="' . '/painel/atendimento/' . $id1 . '"') || str_contains($sid->html, 'painel/atendimento/' . $id1 . '"'), 'na ficha, navegação entre os atendimentos do mesmo cliente');
ok(str_contains($sid->html, 'id="gestao"') && str_contains($sid->html, 'Excluir atendimento') && str_contains($sid->html, 'Arquivar atendimento'), 'gestor/administrador vê fechar, arquivar e excluir');
preg_match('/name="_csrf" value="([^"]+)"/', $sid->html, $cs);
$sid->post('/painel/atendimento/' . $id2 . '/gestao', ['_csrf' => $cs[1], 'op' => 'fechar', 'motivo' => '']);
ok(c($id2)['initial_status'] !== 'completed' && str_contains(flash_error($sid), 'motivo'), 'fechar exige o motivo');
$sid->post('/painel/atendimento/' . $id2 . '/gestao', ['_csrf' => $cs[1], 'op' => 'fechar', 'motivo' => 'Cliente resolveu direto com o banco']);
ok(c($id2)['initial_status'] === 'completed' && c($id2)['closure_reason'] === 'Cliente resolveu direto com o banco', 'atendimento fechado com o motivo');
$sid->post('/painel/atendimento/' . $id2 . '/gestao', ['_csrf' => $cs[1], 'op' => 'arquivar']);
$sid->get('/painel/atendimentos?f=concluidos');
ok(c($id2)['archived_at'] && !str_contains($sid->html, c($id2)['protocol']), 'arquivado some das listas');
$sid->get('/painel/atendimentos?f=arquivados');
ok(str_contains($sid->html, c($id2)['protocol']), 'e aparece no filtro “Arquivados”');
$sid->post('/painel/atendimento/' . $id2 . '/gestao', ['_csrf' => $cs[1], 'op' => 'desarquivar']);
ok(!c($id2)['archived_at'], 'desarquivar devolve às listas');
$p2 = c($id2)['protocol'];
$sid->post('/painel/atendimento/' . $id2 . '/gestao', ['_csrf' => $cs[1], 'op' => 'excluir', 'confirmar' => 'errado']);
ok((bool) one('SELECT 1 FROM cases WHERE id = ?', [$id2]), 'excluir sem digitar o protocolo não apaga');
$sid->post('/painel/atendimento/' . $id2 . '/gestao', ['_csrf' => $cs[1], 'op' => 'excluir', 'confirmar' => strtolower($p2)]);
ok(!one('SELECT 1 FROM cases WHERE id = ?', [$id2]) && !scalar('SELECT 1 FROM chat_messages WHERE case_id = ?', [$id2]) && str_contains($sid->url, '/painel/atendimentos') && (bool) scalar("SELECT 1 FROM audit WHERE action = 'atendimento_excluido' AND detail LIKE ?", [$p2 . '%']), 'excluído de vez, com registro na auditoria');
ok((bool) one('SELECT 1 FROM cases WHERE id = ?', [$id1]) && (bool) one('SELECT 1 FROM client_accounts WHERE id = ?', [$c1['account_id']]), 'os outros atendimentos e a conta do cliente continuam');
$ana->get('/cliente/conversas');
ok(!str_contains($ana->html, $p2) && str_contains($ana->html, $c1['protocol']), 'o cliente deixa de ver o atendimento excluído');
$uid2 = insert('users', ['name' => 'Calc', 'email' => 'calc@teste.local', 'roles' => 'calculos', 'pass_hash' => password_hash('senha-calc-12345', PASSWORD_DEFAULT), 'active' => 1, 'created_at' => now_str()]);
$calc = new Browser($base);
$calc->get('/painel/entrar');
$calc->post('/painel/entrar', ['email' => 'calc@teste.local', 'password' => 'senha-calc-12345']);
$calc->get('/painel/atendimento/' . $id1);
preg_match('/name="_csrf" value="([^"]+)"/', $calc->html, $cs2);
$calc->post('/painel/atendimento/' . $id1 . '/gestao', ['_csrf' => $cs2[1] ?? '', 'op' => 'excluir', 'confirmar' => $c1['protocol']]);
ok((bool) one('SELECT 1 FROM cases WHERE id = ?', [$id1]) && !str_contains($calc->html, 'Excluir atendimento'), 'quem não é gestor nem administrador não exclui');

$clearMail();
echo "\n" . ($fails ? "FALHAS: $fails · " : 'TUDO OK · ') . "$passes verificações aprovadas\n";
exit($fails ? 1 : 0);
