<?php
/**
 * Agenda de Sidney: regra do horário (antecedência, intervalo depois de
 * compromisso, expediente, feriados), os três pedidos para falar com Sidney e
 * a integração com o Google Agenda (simulada, sem rede).
 *
 * Uso: php tests/agenda.php
 */
declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/fatuche-agenda-' . getmypid();
@mkdir($tmp, 0700, true);
putenv('FATUCHE_DB=' . $tmp . '/fatuche.sqlite');
putenv('FATUCHE_STORAGE=' . $tmp);
require dirname(__DIR__) . '/app/bootstrap.php';
register_shutdown_function(fn() => shell_exec('rm -rf ' . escapeshellarg($tmp)));

$fails = 0;
$passes = 0;
function ok(bool $cond, string $msg): void
{
    global $fails, $passes;
    $cond ? $passes++ : $fails++;
    echo ($cond ? '  ✓ ' : '  ✗ ') . $msg . "\n";
}
function at(string $s): DateTimeImmutable
{
    return new DateTimeImmutable($s);
}
function slot(string $now, array $busy = []): string
{
    $GLOBALS['FAKE_NOW'] = $now;
    $s = agenda_find_slot(now(), array_map(fn($b) => [at($b[0]), at($b[1])], $busy));
    return $s ? $s->format('Y-m-d H:i') : 'nenhum';
}

echo "A1. Regra do horário (expediente 9h–18h, 30 min, antecedência 90 min, intervalo 60 min)\n";
ok((int) scalar('PRAGMA user_version') >= 14, 'migração 14 aplicada');
ok(in_array('google_event_id', array_column(all('PRAGMA table_info(case_meetings)'), 'name'), true), 'reunião guarda o ID do compromisso no Google');
ok(slot('2026-10-13 10:07') === '2026-10-13 11:40', 'agenda livre: daqui a 1h30 (arredondado para 5 min)');
ok(slot('2026-10-13 10:00', [['2026-10-13 11:00', '2026-10-13 12:00']]) === '2026-10-13 13:00', 'compromisso até 12h: 1 hora depois do fim');
ok(slot('2026-10-13 10:00', [['2026-10-13 12:00', '2026-10-13 13:00']]) === '2026-10-13 11:30', 'cabe antes do próximo compromisso');
ok(slot('2026-10-13 10:00', [['2026-10-13 11:00', '2026-10-13 12:00'], ['2026-10-13 13:15', '2026-10-13 14:50']]) === '2026-10-13 15:50', 'compromissos seguidos: depois do último');
ok(slot('2026-10-13 09:00', [['2026-10-13 08:00', '2026-10-13 10:45']]) === '2026-10-13 11:45', 'compromisso que termina depois da antecedência');
ok(slot('2026-10-13 17:00') === '2026-10-14 09:00', 'sem janela hoje: primeira hora do dia seguinte');
ok(slot('2026-10-13 06:00') === '2026-10-13 09:00', 'antes do expediente: abre às 9h');
ok(slot('2026-10-16 17:30') === '2026-10-19 09:00', 'sexta no fim do dia: segunda-feira');
ok(slot('2026-10-11 20:00') === '2026-10-13 09:00', 'feriado (12/10) é pulado');
ok(slot('2026-10-13 17:00', [['2026-10-14 00:00', '2026-10-15 00:00']]) === '2026-10-15 09:00', 'dia inteiro ocupado: próximo dia livre');
ok(slot('2026-10-13 16:00', [['2026-10-13 16:30', '2026-10-13 17:10']]) === '2026-10-14 09:00', 'a conversa não passa do fim do expediente');
set_setting('agenda_antecedencia', '60');
set_setting('agenda_inicio', '08:30');
ok(slot('2026-10-13 07:00') === '2026-10-13 08:30', 'expediente configurável');
ok(slot('2026-10-13 10:02') === '2026-10-13 11:05', 'antecedência configurável');
set_setting('agenda_antecedencia', null);
set_setting('agenda_inicio', null);

echo "\nA2. O que conta como pedido para falar com Sidney\n";
foreach (['Quero falar com o Sidney', 'posso falar com um atendente?', 'Quero falar com uma pessoa', 'me passa pro advogado', 'não quero falar com robô', 'Preciso falar com o Dr. Sidney urgente', 'cadê o advogado?', 'quero conversar com alguém'] as $t) {
    ok(human_ask_detect($t), 'pedido: “' . $t . '”');
}
foreach (['O Sidney vai analisar meu contrato?', 'Meu carro é um Onix 2020', 'o banco ligou para mim ontem', 'Falei com o gerente do banco', 'sim', 'Meu nome é Ana Pereira'] as $t) {
    ok(!human_ask_detect($t), 'não é pedido: “' . $t . '”');
}

echo "\nA3. Três pedidos, sem o Google conectado (reserva como ligação)\n";
$GLOBALS['FAKE_NOW'] = '2026-10-13 10:07';
$uid = insert('users', ['name' => 'Sidney', 'email' => 's@t.local', 'roles' => 'supervisor', 'pass_hash' => 'x', 'active' => 1, 'created_at' => now_str()]);
set_setting('operator_user_id', (string) $uid);
seed_templates();
$a = create_initial_case(['nome' => 'Ana Teste', 'telefone' => '(41) 91111-1111', 'email' => 'ana@t.local', 'adaptacao' => ''], 'site');
$id = (int) $a['id'];
ok(!google_connected(), 'Google não conectado');
$r1 = human_ask_handle(find_case($id), 'Quero falar com o Sidney');
ok(($r1['n'] ?? 0) === 1 && str_contains($r1['instruction'], HUMAN_ASK_MARK) && str_contains($r1['instruction'], 'Ainda não marque'), '1º pedido: a IA pede o que falta, sem marcar');
ok(!current_meeting($id), '1º pedido: nada marcado');
ok(str_contains(human_ask_reply($id, 1), 'Sidney'), '1º pedido sem IA: resposta pede as informações');
$r2 = human_ask_handle(find_case($id), 'quero falar com uma pessoa');
ok(($r2['n'] ?? 0) === 2 && str_contains($r2['instruction'], 'mais concreta'), '2º pedido: insiste de forma mais concreta');
ok(str_contains(human_ask_reply($id, 2), 'preciso antes de'), '2º pedido sem IA: lista o que falta');
ok(human_ask_handle(find_case($id), 'Meu carro é um Onix') === null, 'mensagem comum não conta');
$r3 = human_ask_handle(find_case($id), 'Já falei: quero falar com o advogado!');
$m = current_meeting($id);
ok(!empty($r3['reply']) && $m !== null, '3º pedido: conversa marcada');
ok($m && $m['starts_at'] === '2026-10-13 11:40:00' && (int) $m['duration_min'] === 30, '3º pedido: 30 min no primeiro horário (11h40)');
ok($m && $m['channel'] === 'phone' && $m['phone'] === '41911111111' && $m['origin'] === 'assistente', 'sem Google: ligação para o telefone do cliente');
$last = (string) scalar("SELECT body FROM chat_messages WHERE case_id = ? AND author = 'bot' ORDER BY id DESC LIMIT 1", [$id]);
ok(str_contains($last, 'marquei uma conversa de 30 minutos com Sidney hoje às 11h40') && str_contains($last, '(41) 91111-1111'), 'cliente recebe o horário no chat');
ok((int) case_data(find_case($id), 'pedidos_sidney') === 3, 'três pedidos registrados');
ok((string) find_case($id)['next_action_due'] === '2026-10-13 11:40:00', 'próxima ação de Sidney no horário marcado');
human_ask_handle(find_case($id), 'quero falar com o Sidney');
$last = (string) scalar("SELECT body FROM chat_messages WHERE case_id = ? AND author = 'bot' ORDER BY id DESC LIMIT 1", [$id]);
ok(str_contains($last, 'já está marcada') && (int) scalar('SELECT COUNT(*) FROM case_meetings WHERE case_id = ?', [$id]) === 1, 'novo pedido: lembra o horário, não marca outro');
ok(str_contains(agent_context($id, 'teste'), 'CONVERSA COM SIDNEY JÁ MARCADA'), 'a IA sabe que a conversa já está marcada');
ok(str_contains(agent_system_prompt(), 'Não ofereça conversa com Sidney'), 'a IA não oferece a conversa por conta própria');

echo "\nA4. Google Agenda (simulado): conexão, horário livre, Meet e convite\n";
$calls = [];
$busyGoogle = [['start' => '2026-10-14T13:00:00Z', 'end' => '2026-10-14T15:00:00Z']]; // 10h–12h em Brasília
$GLOBALS['google_http'] = function (string $method, string $url, ?array $body, bool $form) use (&$calls, &$busyGoogle) {
    $calls[] = [$method, $url, $body];
    if (str_ends_with($url, '/token')) {
        if (($body['grant_type'] ?? '') === 'authorization_code') {
            $claims = rtrim(strtr(base64_encode(json_encode(['email' => 'sidney@gmail.com'])), '+/', '-_'), '=');
            return [200, ['access_token' => 'at-1', 'expires_in' => 3599, 'refresh_token' => 'rt-1', 'id_token' => 'x.' . $claims . '.y']];
        }
        return [200, ['access_token' => 'at-2', 'expires_in' => 3599]];
    }
    if (str_ends_with($url, '/freeBusy')) {
        return [200, ['calendars' => ['primary' => ['busy' => $busyGoogle]]]];
    }
    if ($method === 'POST' && str_contains($url, '/events?')) {
        return [200, ['id' => 'ev123', 'hangoutLink' => 'https://meet.google.com/abc-defg-hij']];
    }
    if ($method === 'DELETE') {
        return [204, []];
    }
    return [404, ['error' => ['message' => 'não simulado']]];
};
set_setting('google_client_id', '123-abc.apps.googleusercontent.com');
set_setting('google_client_secret', 'GOCSPX-teste');
ok(str_contains(google_auth_url('st'), 'access_type=offline') && str_contains(google_auth_url('st'), 'calendar.events') && str_contains(google_auth_url('st'), urlencode('painel/google/retorno')), 'link de autorização pede acesso permanente à agenda');
ok(google_exchange_code('codigo') === null, 'código trocado pelos tokens');
ok(google_connected() && setting('google_account_email') === 'sidney@gmail.com', 'conectado, com o e-mail da conta');
$GLOBALS['FAKE_NOW'] = '2026-10-14 09:00';
set_setting('google_access_expires', (string) (now()->getTimestamp() + 3000));
$calls = [];
[$s] = agenda_next_slot();
ok($s && $s->format('Y-m-d H:i') === '2026-10-14 13:00', 'compromisso do Google 10h–12h: conversa às 13h');
ok(count($calls) === 1 && str_ends_with($calls[0][1], '/freeBusy') && $calls[0][2]['items'][0]['id'] === 'primary', 'consulta só o livre/ocupado da agenda (token ainda válido)');
$b = create_initial_case(['nome' => 'Bruno Teste', 'telefone' => '(41) 92222-2222', 'email' => 'bruno@t.local', 'adaptacao' => ''], 'site');
$bid = (int) $b['id'];
set_case_data($bid, 'pedidos_sidney', 2);
$calls = [];
human_ask_handle(find_case($bid), 'quero falar com um humano');
$m = current_meeting($bid);
$ins = array_values(array_filter($calls, fn($c) => $c[0] === 'POST' && str_contains($c[1], '/events?')))[0] ?? null;
ok($m && $m['channel'] === 'meet' && $m['url'] === 'https://meet.google.com/abc-defg-hij' && $m['google_event_id'] === 'ev123', 'reunião no Meet, ligada ao compromisso do Google');
ok($ins && str_contains($ins[1], 'conferenceDataVersion=1') && str_contains($ins[1], 'sendUpdates=all'), 'compromisso criado com sala do Meet e convite');
ok($ins && $ins[2]['attendees'][0]['email'] === 'bruno@t.local' && $ins[2]['start']['dateTime'] === '2026-10-14T13:00:00-03:00' && $ins[2]['end']['dateTime'] === '2026-10-14T13:30:00-03:00', 'convidado e horário corretos (13h–13h30)');
ok($ins && $ins[2]['conferenceData']['createRequest']['conferenceSolutionKey']['type'] === 'hangoutsMeet', 'pede o link do Google Meet');
$last = (string) scalar("SELECT body FROM chat_messages WHERE case_id = ? AND author = 'bot' ORDER BY id DESC LIMIT 1", [$bid]);
ok(str_contains($last, 'https://meet.google.com/abc-defg-hij') && str_contains($last, 'convite também foi para o seu e-mail'), 'cliente recebe o link do Meet no chat');
// A reunião do Bruno (13h) ocupa a agenda do sistema: o próximo pedido respeita 1 hora depois.
$busyGoogle = [];
[$s] = agenda_next_slot();
ok($s && $s->format('H:i') === '10:30', 'antes da reunião marcada ainda cabe uma conversa');
$GLOBALS['FAKE_NOW'] = '2026-10-14 11:50';
[$s] = agenda_next_slot();
ok($s && $s->format('H:i') === '14:30', 'reunião do sistema conta como compromisso (13h30 + 1h)');
set_setting('google_access_expires', '0');
$calls = [];
agenda_next_slot();
ok(($calls[0][2]['grant_type'] ?? '') === 'refresh_token' && setting('google_access_token') === 'at-2', 'token vencido é renovado sozinho');
$calls = [];
ok(case_close(find_case($bid), 'Teste de fechamento', $uid) === null, 'atendimento fechado');
ok((bool) array_filter($calls, fn($c) => $c[0] === 'DELETE' && str_contains($c[1], '/events/ev123')), 'fechar o atendimento desmarca no Google (e avisa o cliente)');

echo "\nA5. Falhas do Google não travam o atendimento\n";
$GLOBALS['google_http'] = fn($m, $u) => str_ends_with($u, '/token') ? [200, ['access_token' => 'at-3', 'expires_in' => 3599]] : [500, ['error' => ['message' => 'Backend Error']]];
set_setting('google_access_expires', '0');
$cc = create_initial_case(['nome' => 'Carla Teste', 'telefone' => '(41) 93333-3333', 'email' => 'carla@t.local', 'adaptacao' => ''], 'site');
$cid = (int) $cc['id'];
set_case_data($cid, 'pedidos_sidney', 2);
human_ask_handle(find_case($cid), 'quero falar com o Sidney');
$m = current_meeting($cid);
ok($m && $m['channel'] === 'phone' && !$m['google_event_id'], 'Google fora do ar: horário reservado no sistema como ligação');
ok((bool) scalar("SELECT 1 FROM events WHERE case_id = ? AND title LIKE 'Google Agenda indisponível%'", [$cid]), 'Sidney vê o aviso na ficha');
$GLOBALS['google_http'] = fn($m, $u) => [400, ['error' => 'invalid_grant', 'error_description' => 'Token has been expired or revoked.']];
set_setting('google_access_expires', '0');
[, $err] = google_access_token();
ok($err !== null && !google_connected(), 'acesso revogado no Google: painel pede nova conexão');
unset($GLOBALS['google_http']);

echo "\n" . ($fails ? "FALHAS: $fails" : 'TUDO OK') . " · $passes verificações aprovadas\n";
exit($fails ? 1 : 0);
