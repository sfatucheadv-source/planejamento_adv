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
ok($m && $m['channel'] === 'meet' && !$m['url'] && str_contains((string) $m['google_error'], 'Google não conectado') && $m['origin'] === 'assistente', 'sem Google: continua pelo Meet (padrão do escritório), com o aviso para conectar');
$last = (string) scalar("SELECT body FROM chat_messages WHERE case_id = ? AND author = 'bot' ORDER BY id DESC LIMIT 1", [$id]);
ok(str_contains($last, 'marquei uma conversa de 30 minutos com Sidney hoje às 11h40') && str_contains($last, 'Google Meet') && str_contains($last, 'link aparece'), 'cliente recebe o horário no chat');
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
ok(str_contains($last, 'https://meet.google.com/abc-defg-hij') && str_contains($last, 'confirmação com todos os detalhes vai para o seu e-mail'), 'cliente recebe o link do Meet no chat');
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
ok($m && $m['channel'] === 'meet' && !$m['google_event_id'] && str_contains((string) $m['google_error'], 'Backend Error'), 'Google fora do ar: horário reservado pelo Meet, com o erro para tentar de novo');
ok((bool) scalar("SELECT 1 FROM events WHERE case_id = ? AND title LIKE 'Google Agenda indisponível%'", [$cid]), 'Sidney vê o aviso na ficha');
$GLOBALS['google_http'] = fn($m, $u) => [400, ['error' => 'invalid_grant', 'error_description' => 'Token has been expired or revoked.']];
set_setting('google_access_expires', '0');
[, $err] = google_access_token();
ok($err !== null && !google_connected(), 'acesso revogado no Google: painel pede nova conexão');
unset($GLOBALS['google_http']);

echo "\nA6. Agendamento liberado a partir de 70% e escolha entre dois horários\n";
$GLOBALS['FAKE_NOW'] = '2026-10-15 10:00';
set_setting('google_refresh_token', null);
$dd = create_initial_case(['nome' => 'Diana Teste', 'telefone' => '(41) 94444-4444', 'email' => 'diana@t.local', 'adaptacao' => ''], 'site');
$did = (int) $dd['id'];
foreach (['contrato', 'identidade', 'residencia'] as $k) {
    if (!scalar('SELECT 1 FROM documents WHERE case_id = ? AND kind = ?', [$did, $k])) {
        insert('documents', ['case_id' => $did, 'kind' => $k, 'label' => DOC_CATALOG[$k]['label'], 'status' => 'falta', 'essential' => 1, 'requested_at' => now_str()]);
    }
}
q("UPDATE documents SET status = 'dispensado' WHERE case_id = ? AND kind NOT IN ('contrato', 'identidade', 'residencia')", [$did]);
q("UPDATE documents SET status = 'recebido' WHERE case_id = ? AND kind = 'contrato'", [$did]);
set_setting('agent_required_fields', json_encode([]));
set_setting('agent_required_docs', json_encode(['contrato', 'identidade', 'residencia']));
foreach (['nome_completo', 'telefone', 'email', 'veiculo_marca', 'veiculo_modelo', 'veiculo_placa', 'banco'] as $k) {
    q('DELETE FROM case_fields WHERE case_id = ? AND field = ?', [$did, $k]);
}
case_fields($did, true);
ok(agent_progress_pct($did) < MEETING_UNLOCK_PCT && !meeting_ready($did), 'menos de 70% das informações: agendamento ainda bloqueado');
foreach (['nome_completo' => 'Diana Teste', 'telefone' => '41944444444', 'email' => 'diana@t.local', 'veiculo_marca' => 'Fiat', 'veiculo_modelo' => 'Argo', 'veiculo_placa' => 'ABC1D23'] as $k => $v) {
    set_field($did, $k, $v, 'informado', 'cliente');
}
ok(agent_progress_pct($did) === 70 && meeting_ready($did) && !find_case($did)['intake_done_at'], '70% (faltam o banco e dois documentos): agendamento liberado, sem precisar do resto');
$st = array_column(array_map(fn($x) => ['k' => $x['doc']['kind'], 's' => $x['st']], docs_client_list($did)), 's', 'k');
ok($st === ['contrato' => 'ok', 'identidade' => 'falta', 'residencia' => 'falta'], 'documentos: recebido ou falta');
[$op] = agenda_options();
ok(array_map(fn($o) => $o->format('Y-m-d H:i'), $op) === ['2026-10-15 11:30', '2026-10-16 09:00'], 'duas opções: hoje (mais rápido) e o dia útil seguinte');
$GLOBALS['FAKE_NOW'] = '2026-10-16 17:00';
[$op] = agenda_options();
ok(array_map(fn($o) => $o->format('Y-m-d H:i'), $op) === ['2026-10-19 09:00', '2026-10-20 09:00'], 'sexta no fim do dia: segunda e terça');
$GLOBALS['FAKE_NOW'] = '2026-10-15 10:00';
insert('case_meetings', ['case_id' => $bid, 'status' => 'confirmada', 'channel' => 'phone', 'starts_at' => '2026-10-15 14:00:00', 'duration_min' => 30, 'confirmed_at' => now_str(), 'created_at' => now_str()]);
ok(!agenda_slot_ok(at('2026-10-15 10:30')) && !agenda_slot_ok(at('2026-10-15 19:00')) && !agenda_slot_ok(at('2026-10-15 14:45')) && agenda_slot_ok(at('2026-10-15 11:30')) && agenda_slot_ok(at('2026-10-15 15:30')), 'horário escolhido é conferido de novo (antecedência, expediente e 1 hora depois de outra reunião)');
q("DELETE FROM case_meetings WHERE case_id = ? AND starts_at = '2026-10-15 14:00:00'", [$bid]);
$m = agenda_book(find_case($did), 'coleta', at('2026-10-16 09:00'));
ok($m && $m['origin'] === 'coleta' && $m['starts_at'] === '2026-10-16 09:00:00', 'cliente escolhe a segunda opção: conversa marcada nesse horário');
$last = (string) scalar("SELECT body FROM chat_messages WHERE case_id = ? AND author = 'bot' ORDER BY id DESC LIMIT 1", [$did]);
ok(str_contains($last, 'Conversa confirmada') && str_contains($last, 'documento de identificação pessoal'), 'cliente recebe o horário e o lembrete do que falta');
ok(!meeting_ready($did), 'com a conversa marcada, não oferece de novo');
[$p1, $itens, $p2] = meeting_pitch($did);
ok(count($itens) === 4 && str_contains($p2, 'sem compromisso'), 'texto de engajamento: o que acontece na conversa');

echo "\nA7. Meet criado sozinho, e-mail ao cliente, painel e transcrição (Google simulado)\n";
$GLOBALS['FAKE_NOW'] = '2026-10-20 08:00';
set_setting('google_refresh_token', 'rt-9');
set_setting('google_access_token', 'at-9');
set_setting('google_access_expires', (string) (now()->getTimestamp() + 3000));
set_setting('google_scopes', GOOGLE_SCOPES);
set_setting('mail_driver', 'arquivo');
$gcalls = [];
$transcriptState = 'FILE_GENERATED';
$GLOBALS['google_http'] = function (string $method, string $url, ?array $body) use (&$gcalls, &$transcriptState) {
    $gcalls[] = [$method, $url, $body];
    $path = (string) parse_url($url, PHP_URL_PATH);
    return match (true) {
        str_ends_with($path, '/token') => [200, ['access_token' => 'at-10', 'expires_in' => 3599]],
        str_ends_with($path, '/freeBusy') => [200, ['calendars' => ['primary' => ['busy' => []]]]],
        $method === 'POST' && str_ends_with($path, '/events') => [200, ['id' => 'ev900', 'hangoutLink' => 'https://meet.google.com/xyz-abcd-efg']],
        $method === 'GET' && str_ends_with($path, '/events/ev901') => [200, ['id' => 'ev901', 'attachments' => [['fileId' => 'doc88', 'title' => 'Conversa - Transcrição']]]],
        $method === 'GET' && $path === '/v2/spaces/xyz-abcd-efg' => [200, ['name' => 'spaces/s1', 'meetingCode' => 'xyz-abcd-efg']],
        $method === 'PATCH' && $path === '/v2/spaces/s1' => [200, ['name' => 'spaces/s1']],
        $method === 'GET' && $path === '/v2/conferenceRecords' => [200, ['conferenceRecords' => [['name' => 'conferenceRecords/r1']]]],
        $method === 'GET' && $path === '/v2/conferenceRecords/r1/transcripts' => [200, ['transcripts' => [['name' => 'conferenceRecords/r1/transcripts/t1', 'state' => $transcriptState, 'docsDestination' => ['document' => 'doc77']]]]],
        $method === 'GET' && str_ends_with($path, '/files/doc77/export') => [200, ['_raw' => "Sidney Fatuche: Bom dia, Elisa.\nElisa: Bom dia! Meu contrato é do Banco Exemplo."]],
        $method === 'GET' && str_ends_with($path, '/files/doc88/export') => [200, ['_raw' => 'Transcrição pelo anexo do compromisso.']],
        $method === 'POST' && str_contains($url, '/upload/drive/v3/files') => [200, ['id' => 'drv1']],
        default => [404, ['error' => ['message' => 'não simulado: ' . $method . ' ' . $path]]],
    };
};
$ee = create_initial_case(['nome' => 'Elisa Teste', 'telefone' => '(41) 95555-5555', 'email' => 'elisa@t.local', 'adaptacao' => ''], 'site');
$eid = (int) $ee['id'];
set_case_data($eid, 'drive_pasta', 'pasta-elisa');
ok(find_case($eid)['initial_status'] === 'collecting', 'atendimento ainda em coleta (análise pendente)');
$m = agenda_book(find_case($eid), 'coleta', at('2026-10-20 10:00'));
ok($m && $m['channel'] === 'meet' && $m['url'] === 'https://meet.google.com/xyz-abcd-efg' && $m['google_event_id'] === 'ev900' && !$m['google_error'], 'cliente escolhe o horário: compromisso no Google Agenda com o link do Meet, sem informar URL');
$ins = array_values(array_filter($gcalls, fn($c) => $c[0] === 'POST' && str_contains($c[1], '/events?')))[0] ?? null;
ok($ins && str_contains($ins[2]['description'], 'painel/atendimento/' . $eid) && $ins[2]['attendees'][0]['email'] === 'elisa@t.local', 'horário bloqueado na agenda de Sidney, com o link da ficha e convite à cliente');
$patch = array_values(array_filter($gcalls, fn($c) => $c[0] === 'PATCH'))[0] ?? null;
ok($patch && str_contains($patch[1], 'autoTranscriptionGeneration') && $patch[2]['config']['artifactConfig']['transcriptionConfig']['autoTranscriptionGeneration'] === 'ON' && str_contains((string) $m['transcript_note'], 'ligada'), 'transcrição automática ligada na sala do Meet');
$dir = getenv('FATUCHE_STORAGE') . '/mail';
@array_map('unlink', glob($dir . '/*.eml') ?: []);
ok(meeting_notify_client($m) === null, 'e-mail de confirmação enviado');
$eml = implode('', array_map('file_get_contents', glob($dir . '/*.eml') ?: []));
preg_match_all('/base64\r?\n\r?\n([A-Za-z0-9+\/=\r\n]+)/', $eml, $parts);
$eml .= implode('', array_map(fn($b) => base64_decode(preg_replace('/\s+/', '', $b)), $parts[1]));
ok(str_contains($eml, 'elisa@t.local') && str_contains($eml, 'https://meet.google.com/xyz-abcd-efg') && str_contains($eml, '20/10/2026') && str_contains($eml, '10h00') && str_contains($eml, 'calendar.google.com/calendar/render'), 'e-mail com data, hora, link do Meet e “adicionar à agenda”');
ok((bool) one('SELECT client_mail_at FROM case_meetings WHERE id = ?', [$m['id']])['client_mail_at'], 'envio registrado na reunião');
ok(!transcript_due(one('SELECT * FROM case_meetings WHERE id = ?', [$m['id']])), 'antes do fim da reunião, não procura a transcrição');
$GLOBALS['FAKE_NOW'] = '2026-10-20 10:20';
$transcriptState = 'STARTED';
ok(transcripts_sweep() === 0, 'reunião ainda em andamento: nada a buscar');
$GLOBALS['FAKE_NOW'] = '2026-10-20 10:40';
ok(transcripts_sweep() === 1 && !one('SELECT transcript_file_id FROM case_meetings WHERE id = ?', [$m['id']])['transcript_file_id'], 'transcrição ainda sendo gerada: tenta de novo depois');
$transcriptState = 'FILE_GENERATED';
ok(transcripts_sweep() === 0, 'espera 10 minutos entre as buscas');
$GLOBALS['FAKE_NOW'] = '2026-10-20 10:55';
ok(transcripts_sweep() === 1, 'nova busca');
$mt = one('SELECT * FROM case_meetings WHERE id = ?', [$m['id']]);
$tf = $mt['transcript_file_id'] ? one('SELECT * FROM files WHERE id = ?', [$mt['transcript_file_id']]) : null;
$txt = $tf ? (string) file_get_contents(getenv('FATUCHE_STORAGE') . '/uploads/' . $eid . '/' . $tf['stored_name']) : '';
ok($tf && $tf['kind'] === 'transcricao' && (int) $tf['internal'] === 1 && str_contains($txt, 'Meu contrato é do Banco Exemplo') && str_contains($txt, $ee['protocol']), 'transcrição salva nos arquivos do atendimento (só o escritório vê)');
$up = array_values(array_filter($gcalls, fn($c) => $c[0] === 'POST' && str_contains($c[1], '/upload/drive/v3/files')));
ok($tf && $tf['drive_id'] === 'drv1' && $up && str_contains((string) end($up)[2]['_raw'], '"parents":["pasta-elisa"]') && str_contains((string) end($up)[2]['_raw'], 'Transcrição da reunião'), 'e gravada na pasta do atendimento no Google Drive');
ok((bool) scalar("SELECT 1 FROM events WHERE case_id = ? AND title = 'Transcrição da reunião salva'", [$eid]) && transcripts_sweep() === 0, 'registrada no histórico; não busca de novo');
// Registro pela IA a partir da transcrição (IA simulada)
$mockPort = 24500 + random_int(0, 400);
$mockPid = (int) shell_exec(sprintf('php -S 127.0.0.1:%d %s > /dev/null 2>&1 & echo $!', $mockPort, escapeshellarg(dirname(__DIR__) . '/tests/mock_openrouter.php')));
register_shutdown_function(fn() => $mockPid && posix_kill($mockPid, SIGTERM));
for ($i = 0; $i < 50 && !@file_get_contents("http://127.0.0.1:$mockPort/api/v1/models"); $i++) {
    usleep(100000);
}
set_setting('llm_endpoint', "http://127.0.0.1:$mockPort/api/v1");
set_setting('llm_enabled', '1');
set_setting('llm_api_key', 'sk-or-v1-teste-valido-0000');
set_setting('llm_model', 'google/gemini-2.5-flash');
[$dr, $derr] = meeting_ai_draft(one('SELECT * FROM case_meetings WHERE id = ?', [$m['id']]));
ok($derr === null && str_contains($dr['summary'], 'Banco Exemplo') && $dr['next_action'] === 'Enviar a proposta por escrito' && $dr['corrections'] === 'Contrato é do Banco Exemplo' && $dr['confirmed_info'] === true, 'IA completa o registro pela transcrição (resumo, correções, próxima ação)');
ok(!isset($dr['reaction']) && !isset($dr['return_at']) && (case_data(find_case($eid), 'reuniao_ia_' . $m['id'])['summary'] ?? '') === $dr['summary'], 'reação e retorno ficam com Sidney; rascunho guardado para o formulário');
set_setting('llm_enabled', '0');
// Registro sem nenhum campo preenchido
$gcalls = [];
ok(initial_command(find_case($eid), 'registrar_reuniao', ['held' => 'sim']) === null, 'registrar a reunião sem preencher nada');
$mr = one('SELECT * FROM case_meetings WHERE id = ?', [$m['id']]);
ok($mr['status'] === 'realizada' && $mr['reaction'] === 'pensar' && $mr['summary'] === null && find_case($eid)['initial_status'] === 'awaiting_decision', 'gravado como realizada, sem resumo');
ok(!array_filter($gcalls, fn($c) => $c[0] === 'POST' && str_contains($c[1], '/events')) && case_data(find_case($eid), 'reuniao_ia_' . $m['id']) === null, 'sem data de retorno: nada na agenda; rascunho da IA descartado');
// Com reação e retorno: compromisso na agenda de Sidney
$hh = create_initial_case(['nome' => 'Helena Teste', 'telefone' => '(41) 98888-0000', 'email' => 'helena@t.local', 'adaptacao' => ''], 'site');
$hid = (int) $hh['id'];
insert('case_meetings', ['case_id' => $hid, 'status' => 'confirmada', 'channel' => 'meet', 'starts_at' => '2026-10-20 09:00:00', 'duration_min' => 30, 'url' => 'https://meet.google.com/aaa-bbbb-ccc', 'confirmed_at' => now_str(), 'created_at' => now_str()]);
$gcalls = [];
ok(initial_command(find_case($hid), 'registrar_reuniao', ['held' => 'sim', 'reaction' => 'aceitou', 'next_action' => 'Mandar o contrato', 'return_at' => '2026-10-23T14:30']) === null, 'registro com reação e retorno');
$ret = array_values(array_filter($gcalls, fn($c) => $c[0] === 'POST' && str_contains($c[1], '/events')))[0] ?? null;
ok($ret && str_contains($ret[1], 'sendUpdates=none') && empty($ret[2]['attendees']) && $ret[2]['start']['dateTime'] === '2026-10-23T14:30:00-03:00' && str_contains($ret[2]['summary'], 'Retorno: Helena Teste') && str_contains($ret[2]['description'], 'Mandar o contrato'), 'retorno vira compromisso só na agenda de Sidney (sem convidar o cliente)');
ok(find_case($hid)['decision'] === 'aceitou' && (string) find_case($hid)['next_action_due'] === '2026-10-23 14:30:00' && case_data(find_case($hid), 'retorno_evento') === 'ev900', 'reação “aceitou” e próxima ação na data do retorno');

// Sem a permissão do Meet: acha pelo anexo do compromisso
set_setting('google_scopes', 'openid email https://www.googleapis.com/auth/calendar.events https://www.googleapis.com/auth/drive');
$ff = create_initial_case(['nome' => 'Fabio Teste', 'telefone' => '(41) 96666-6666', 'email' => 'fabio@t.local', 'adaptacao' => ''], 'site');
$fid2 = (int) $ff['id'];
set_case_data($fid2, 'drive_pasta', 'pasta-fabio');
$mf = insert('case_meetings', ['case_id' => $fid2, 'status' => 'confirmada', 'channel' => 'meet', 'starts_at' => '2026-10-20 09:00:00', 'duration_min' => 30, 'url' => 'https://meet.google.com/aaa-bbbb-ccc', 'google_event_id' => 'ev901', 'confirmed_at' => now_str(), 'created_at' => now_str()]);
ok(str_starts_with(meeting_transcript_fetch(one('SELECT * FROM case_meetings WHERE id = ?', [$mf]), true), 'Transcrição salva'), 'sem a permissão do Meet: acha a transcrição anexada ao compromisso');
ok(str_contains((string) meet_auto_transcript('https://meet.google.com/aaa-bbbb-ccc'), 'reconecte o Google'), 'sem a permissão do Meet: avisa para reconectar');
// Sidney confirma pelo painel sem link: o sistema cria o Meet
set_setting('google_scopes', GOOGLE_SCOPES);
$GLOBALS['FAKE_NOW'] = '2026-10-21 08:00';
$gg = create_initial_case(['nome' => 'Gabi Teste', 'telefone' => '(41) 97777-0000', 'email' => 'gabi@t.local', 'adaptacao' => ''], 'site');
$gid = (int) $gg['id'];
$err = initial_command(find_case($gid), 'confirmar_reuniao', ['channel' => 'meet', 'starts_at' => '2026-10-21T15:00', 'duration' => '30', 'url' => '']);
$mg = current_meeting($gid);
ok($err === null && $mg && $mg['url'] === 'https://meet.google.com/xyz-abcd-efg' && $mg['google_event_id'] === 'ev900' && $mg['origin'] === 'painel', 'painel: confirmar com a análise pendente e sem URL cria o Meet no Google');
ok(find_case($gid)['initial_status'] === 'collecting' && str_contains((string) scalar("SELECT body FROM chat_messages WHERE case_id = ? AND author = 'operator' ORDER BY id DESC LIMIT 1", [$gid]), 'https://meet.google.com/xyz-abcd-efg'), 'cliente recebe o link na conversa; a análise segue pendente');
ok(initial_command(find_case($gid), 'registrar_reuniao', ['held' => 'nao', 'no_show_reason' => 'teste']) === null, 'a reunião pode ser registrada mesmo com a análise pendente');
unset($GLOBALS['google_http']);

echo "\n" . ($fails ? "FALHAS: $fails" : 'TUDO OK') . " · $passes verificações aprovadas\n";
exit($fails ? 1 : 0);
