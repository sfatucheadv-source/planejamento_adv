<?php
declare(strict_types=1);

/**
 * Agenda de Sidney. O chat não oferece "falar com Sidney": o cliente precisa
 * chegar qualificado. Nos dois primeiros pedidos para falar com ele (ou com uma
 * pessoa), o assistente pede as informações e os documentos que faltam; no
 * terceiro, marca uma conversa de 30 minutos no primeiro horário livre do
 * Google Agenda, com link do Meet e convite para o cliente.
 *
 * Regra do horário: dentro do expediente (dias úteis, sem feriados), no mínimo
 * "antecedência" minutos a partir de agora e "intervalo" minutos depois do fim
 * de qualquer compromisso. Sem janela hoje, vale a mais próxima.
 * Sem o Google conectado, o horário é reservado no sistema como ligação.
 */

const HUMAN_ASKS_TO_SCHEDULE = 3;
const HUMAN_ASK_MARK = 'PEDIDO PARA FALAR COM SIDNEY';

const AGENDA_DEFAULTS = [
    'agenda_duracao' => 30,      // minutos da conversa
    'agenda_antecedencia' => 90, // a partir de agora
    'agenda_intervalo' => 60,    // depois do fim de um compromisso
    'agenda_dias' => 14,         // até onde procurar
];

const GOOGLE_SCOPES = 'openid email https://www.googleapis.com/auth/calendar.events https://www.googleapis.com/auth/calendar.freebusy https://www.googleapis.com/auth/drive';
const GOOGLE_AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
const GOOGLE_TOKEN_URL = 'https://oauth2.googleapis.com/token';
const GOOGLE_REVOKE_URL = 'https://oauth2.googleapis.com/revoke';
const GOOGLE_API_URL = 'https://www.googleapis.com/calendar/v3';

function agenda_int(string $key): int
{
    $v = setting($key);
    return $v === null || $v === '' ? AGENDA_DEFAULTS[$key] : max(0, (int) $v);
}

/** Expediente em minutos desde a meia-noite: [início, fim]. */
function agenda_hours(): array
{
    $m = function (string $key, int $hour): int {
        $v = (string) setting($key, '');
        return preg_match('/^(\d{1,2}):(\d{2})$/', $v, $x) ? (int) $x[1] * 60 + (int) $x[2] : $hour * 60;
    };
    return [$m('agenda_inicio', open_hour()), $m('agenda_fim', close_hour())];
}

function agenda_hhmm(int $minutes): string
{
    return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
}

/** Arredonda para cima, de 5 em 5 minutos. */
function agenda_round(DateTimeImmutable $t): DateTimeImmutable
{
    $t = $t->setTime((int) $t->format('G'), (int) $t->format('i'));
    $over = (int) $t->format('i') % 5;
    return $over ? $t->modify('+' . (5 - $over) . ' minutes') : $t;
}

/**
 * Primeiro horário livre. $busy: lista de [início, fim] (DateTimeImmutable).
 * Retorna o início da conversa ou null se não houver janela no período.
 */
function agenda_find_slot(DateTimeImmutable $from, array $busy): ?DateTimeImmutable
{
    $dur = max(10, agenda_int('agenda_duracao'));
    $gap = agenda_int('agenda_intervalo');
    [$hi, $hf] = agenda_hours();
    $earliest = $from->modify('+' . agenda_int('agenda_antecedencia') . ' minutes');
    usort($busy, fn($a, $b) => $a[0] <=> $b[0]);
    for ($d = 0; $d <= max(1, agenda_int('agenda_dias')); $d++) {
        $day = $from->setTime(0, 0)->modify("+$d days");
        if (!is_business_day($day)) {
            continue;
        }
        $close = $day->modify("+$hf minutes");
        $t = agenda_round(max($day->modify("+$hi minutes"), $earliest));
        do {
            $moved = false;
            foreach ($busy as [$bs, $be]) {
                // ocupa a conversa inteira ou termina menos de $gap minutos antes dela
                if ($bs < $t->modify("+$dur minutes") && $be->modify("+$gap minutes") > $t) {
                    $t = agenda_round($be->modify("+$gap minutes"));
                    $moved = true;
                }
            }
        } while ($moved && $t < $close);
        if ($t->modify("+$dur minutes") <= $close) {
            return $t;
        }
    }
    return null;
}

/* ---------- Google Agenda (OAuth com refresh token guardado no servidor) ---------- */

function google_client_id(): string
{
    return trim((string) setting('google_client_id', ''));
}

function google_calendar_id(): string
{
    return trim((string) setting('google_calendar_id', '')) ?: 'primary';
}

function google_connected(): bool
{
    return google_client_id() !== '' && (string) setting('google_client_secret', '') !== '' && (string) setting('google_refresh_token', '') !== '';
}

function google_redirect_uri(): string
{
    return public_link('painel/google/retorno');
}

function google_auth_url(string $state): string
{
    return GOOGLE_AUTH_URL . '?' . http_build_query([
        'client_id' => google_client_id(), 'redirect_uri' => google_redirect_uri(), 'response_type' => 'code',
        'scope' => GOOGLE_SCOPES, 'access_type' => 'offline', 'prompt' => 'consent', 'include_granted_scopes' => 'true', 'state' => $state,
    ]);
}

/**
 * Chamada HTTP ao Google. Retorna [status, dados]. Nos testes,
 * $GLOBALS['google_http'] substitui a rede: fn($method, $url, $body, $form) => [status, array].
 */
function google_http(string $method, string $url, ?array $body = null, bool $form = false, ?string $token = null): array
{
    if (isset($GLOBALS['google_http']) && is_callable($GLOBALS['google_http'])) {
        return ($GLOBALS['google_http'])($method, $url, $body, $form);
    }
    if (!function_exists('curl_init')) {
        return [0, ['error' => 'O servidor não tem a extensão cURL.']];
    }
    $ch = curl_init($url);
    $headers = ['Accept: application/json', 'User-Agent: FatucheAtendimento/1.0'];
    if ($token) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    if ($body !== null && isset($body['_raw'])) {
        // Corpo pronto (upload de arquivo para o Drive): ['_raw' => bytes, '_type' => content-type]
        $headers[] = 'Content-Type: ' . $body['_type'];
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body['_raw']);
    } elseif ($body !== null) {
        $headers[] = $form ? 'Content-Type: application/x-www-form-urlencoded' : 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, $form ? http_build_query($body) : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 60, CURLOPT_HTTPHEADER => $headers]);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) {
        return [0, ['error' => 'Falha de conexão com o Google: ' . $err]];
    }
    return [$status, is_string($raw) ? (json_decode($raw, true) ?: []) : []];
}

function google_error(int $status, array $data): string
{
    $msg = $data['error_description'] ?? ($data['error']['message'] ?? (is_string($data['error'] ?? null) ? $data['error'] : ''));
    return 'Google respondeu ' . $status . ($msg ? ': ' . $msg : '');
}

/** Troca o código da autorização pelos tokens. Retorna erro legível ou null. */
function google_exchange_code(string $code): ?string
{
    [$st, $d] = google_http('POST', GOOGLE_TOKEN_URL, [
        'code' => $code, 'client_id' => google_client_id(), 'client_secret' => (string) setting('google_client_secret'),
        'redirect_uri' => google_redirect_uri(), 'grant_type' => 'authorization_code',
    ], true);
    if ($st !== 200 || empty($d['access_token'])) {
        return google_error($st, $d);
    }
    if (empty($d['refresh_token'])) {
        return 'O Google não enviou a autorização permanente. Remova o acesso do app em myaccount.google.com › Segurança › Apps de terceiros e conecte de novo.';
    }
    set_setting('google_refresh_token', (string) $d['refresh_token']);
    google_store_access($d);
    $email = null;
    if (!empty($d['id_token']) && count($p = explode('.', (string) $d['id_token'])) === 3) {
        $claims = json_decode((string) base64_decode(strtr($p[1], '-_', '+/')), true);
        $email = is_array($claims) ? ($claims['email'] ?? null) : null;
    }
    set_setting('google_account_email', $email ? (string) $email : null);
    return null;
}

function google_store_access(array $d): void
{
    if (!empty($d['scope'])) {
        set_setting('google_scopes', (string) $d['scope']);
    }
    set_setting('google_access_token', (string) $d['access_token']);
    set_setting('google_access_expires', (string) (now()->getTimestamp() + max(60, (int) ($d['expires_in'] ?? 3600)) - 60));
}

/** Token de acesso válido (renova com o refresh token). [token, erro]. */
function google_access_token(): array
{
    if (!google_connected()) {
        return [null, 'Google Agenda não conectado.'];
    }
    $tok = (string) setting('google_access_token', '');
    if ($tok !== '' && (int) setting('google_access_expires', '0') > now()->getTimestamp()) {
        return [$tok, null];
    }
    [$st, $d] = google_http('POST', GOOGLE_TOKEN_URL, [
        'client_id' => google_client_id(), 'client_secret' => (string) setting('google_client_secret'),
        'refresh_token' => (string) setting('google_refresh_token'), 'grant_type' => 'refresh_token',
    ], true);
    if ($st !== 200 || empty($d['access_token'])) {
        if (($d['error'] ?? '') === 'invalid_grant') {
            set_setting('google_refresh_token', null); // acesso revogado: pede nova conexão
        }
        return [null, google_error($st, $d)];
    }
    google_store_access($d);
    return [(string) $d['access_token'], null];
}

function google_api(string $method, string $path, ?array $body = null): array
{
    return google_api_url($method, GOOGLE_API_URL . $path, $body);
}

/** Chamada autenticada a qualquer API do Google (Agenda ou Drive). [ok, dados, erro]. */
function google_api_url(string $method, string $url, ?array $body = null): array
{
    $path = parse_url($url, PHP_URL_PATH);
    [$tok, $err] = google_access_token();
    if (!$tok) {
        return [false, [], $err];
    }
    [$st, $d] = google_http($method, $url, $body, false, $tok);
    $ok = $st >= 200 && $st < 300;
    if (!$ok) {
        error_log('[fatuche][google] ' . $method . ' ' . $path . ': ' . google_error($st, $d));
    }
    return [$ok, $d, $ok ? null : google_error($st, $d)];
}

function google_time(DateTimeImmutable $t): string
{
    return $t->format(DATE_RFC3339);
}

/** Compromissos ocupados no Google Agenda. [lista de [início, fim], erro]. */
function google_busy(DateTimeImmutable $from, DateTimeImmutable $to): array
{
    $cal = google_calendar_id();
    [$ok, $d, $err] = google_api('POST', '/freeBusy', [
        'timeMin' => google_time($from), 'timeMax' => google_time($to), 'timeZone' => 'America/Sao_Paulo', 'items' => [['id' => $cal]],
    ]);
    if (!$ok) {
        return [[], $err];
    }
    $c = $d['calendars'][$cal] ?? (count($d['calendars'] ?? []) === 1 ? reset($d['calendars']) : null);
    if (!is_array($c) || !empty($c['errors'])) {
        return [[], 'O Google não liberou a agenda “' . $cal . '”' . (!empty($c['errors'][0]['reason']) ? ' (' . $c['errors'][0]['reason'] . ')' : '') . '.'];
    }
    $out = [];
    $tz = new DateTimeZone(date_default_timezone_get());
    foreach ((array) ($c['busy'] ?? []) as $b) {
        try {
            $out[] = [(new DateTimeImmutable((string) $b['start']))->setTimezone($tz), (new DateTimeImmutable((string) $b['end']))->setTimezone($tz)];
        } catch (Exception) {
        }
    }
    return [$out, null];
}

/** Cria o compromisso com Meet e convite. [evento, link do Meet, erro]. */
function google_create_meeting(array $c, DateTimeImmutable $start, int $dur, string $description): array
{
    $email = (string) ($c['client_email'] ?? '');
    $body = [
        'summary' => 'Conversa com ' . $c['client_name'] . ' · ' . $c['protocol'],
        'description' => $description,
        'start' => ['dateTime' => google_time($start), 'timeZone' => 'America/Sao_Paulo'],
        'end' => ['dateTime' => google_time($start->modify("+$dur minutes")), 'timeZone' => 'America/Sao_Paulo'],
        'conferenceData' => ['createRequest' => ['requestId' => 'fatuche-' . $c['id'] . '-' . bin2hex(random_bytes(6)), 'conferenceSolutionKey' => ['type' => 'hangoutsMeet']]],
        'reminders' => ['useDefault' => true],
    ];
    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $body['attendees'] = [['email' => $email, 'displayName' => $c['client_name']]];
    }
    [$ok, $ev, $err] = google_api('POST', '/calendars/' . rawurlencode(google_calendar_id()) . '/events?conferenceDataVersion=1&sendUpdates=all', $body);
    if (!$ok || empty($ev['id'])) {
        return [null, null, $err ?: 'O Google não devolveu o compromisso.'];
    }
    $link = google_meet_link($ev);
    if (!$link) { // a sala do Meet pode ficar pronta logo depois
        [$ok2, $ev2] = google_api('GET', '/calendars/' . rawurlencode(google_calendar_id()) . '/events/' . rawurlencode((string) $ev['id']));
        $link = $ok2 ? google_meet_link($ev2) : null;
    }
    return [$ev, $link, null];
}

function google_meet_link(array $ev): ?string
{
    $l = (string) ($ev['hangoutLink'] ?? '');
    foreach ((array) ($ev['conferenceData']['entryPoints'] ?? []) as $p) {
        if ($l === '' && ($p['entryPointType'] ?? '') === 'video') {
            $l = (string) ($p['uri'] ?? '');
        }
    }
    return $l !== '' && str_starts_with($l, 'https://meet.google.com/') ? $l : null;
}

/** Desmarca no Google (e avisa o convidado). Erros só vão para o log. */
function google_cancel_meeting(?array $m): void
{
    if (!$m || empty($m['google_event_id']) || !google_connected()) {
        return;
    }
    google_api('DELETE', '/calendars/' . rawurlencode(google_calendar_id()) . '/events/' . rawurlencode((string) $m['google_event_id']) . '?sendUpdates=all');
}

/* ---------- Agendamento ---------- */

/** Compromissos ocupados no período: Google Agenda + reuniões confirmadas no sistema. [lista, erro do Google]. */
function agenda_busy(DateTimeImmutable $from, DateTimeImmutable $to): array
{
    $busy = [];
    $err = null;
    if (google_connected()) {
        [$busy, $err] = google_busy($from, $to);
    }
    foreach (all("SELECT starts_at, duration_min FROM case_meetings WHERE status = 'confirmada' AND starts_at < ?", [$to->format('Y-m-d H:i:s')]) as $m) {
        $s = dt($m['starts_at']);
        $e = $s->modify('+' . (int) $m['duration_min'] . ' minutes');
        if ($e > $from) {
            $busy[] = [$s, $e];
        }
    }
    return [$busy, $err];
}

/**
 * Duas opções para o cliente escolher: o primeiro horário livre (de preferência
 * hoje, pela regra da antecedência) e o primeiro horário livre do dia útil
 * seguinte a ele. [[início, início], erro do Google].
 */
function agenda_options(): array
{
    $from = now();
    [$busy, $err] = agenda_busy($from, $from->modify('+' . (max(1, agenda_int('agenda_dias')) + 2) . ' days'));
    $a = agenda_find_slot($from, $busy);
    if (!$a) {
        return [[], $err];
    }
    $out = [$a];
    $d = $a->setTime(0, 0)->modify('+1 day');
    while (!is_business_day($d)) {
        $d = $d->modify('+1 day');
    }
    // No dia seguinte, a antecedência já está cumprida: parte da abertura.
    $b = agenda_find_slot($d->modify('-' . agenda_int('agenda_antecedencia') . ' minutes'), $busy);
    if ($b && $b > $a) {
        $out[] = $b;
    }
    return [$out, $err];
}

/** O horário escolhido ainda está livre e dentro das regras? */
function agenda_slot_ok(DateTimeImmutable $t): bool
{
    [$opts] = agenda_options();
    foreach ($opts as $o) {
        if ($o == $t) {
            return true;
        }
    }
    // Fora das duas opções (ex.: a lista mudou): vale se a regra aceitar exatamente esse início.
    $from = now();
    [$busy] = agenda_busy($from, $t->modify('+1 day'));
    $dur = max(10, agenda_int('agenda_duracao'));
    $gap = agenda_int('agenda_intervalo');
    [$hi, $hf] = agenda_hours();
    $min = (int) $t->format('G') * 60 + (int) $t->format('i');
    if (!is_business_day($t) || $min < $hi || $min + $dur > $hf || $t < $from->modify('+' . agenda_int('agenda_antecedencia') . ' minutes')) {
        return false;
    }
    foreach ($busy as [$bs, $be]) {
        if ($bs < $t->modify("+$dur minutes") && $be->modify("+$gap minutes") > $t) {
            return false;
        }
    }
    return true;
}

/** Próximo horário que o assistente marcaria agora. [início ou null, erro do Google]. */
function agenda_next_slot(): array
{
    $from = now();
    [$busy, $err] = agenda_busy($from, $from->modify('+' . (max(1, agenda_int('agenda_dias')) + 1) . ' days'));
    return [agenda_find_slot($from, $busy), $err];
}

function agenda_when(DateTimeImmutable $t): string
{
    return fmt_when($t);
}

/** Textos de cada motivo de agendamento: pedido insistente ou coleta concluída. */
function agenda_reason(string $origin): array
{
    return $origin === 'coleta' ? [
        'why' => 'Coleta concluída e documentos resolvidos: o cliente escolheu o horário para Sidney apresentar a análise.',
        'note' => 'Horário escolhido por você depois de enviar as informações e os documentos.',
        'event' => 'Cliente escolheu o horário da conversa com Sidney',
        'next' => 'Conversa com o cliente (análise do caso)',
        'none' => 'Tudo certo com seus dados e documentos. Não encontrei um horário livre na agenda de Sidney nos próximos dias; ele vai entrar em contato para combinar a conversa.',
        'intro' => 'Conversa confirmada: %d minutos com Sidney %s, %s',
    ] : [
        'why' => 'Conversa marcada pelo assistente: o cliente pediu ' . HUMAN_ASKS_TO_SCHEDULE . ' vezes para falar com Sidney.',
        'note' => 'Marcada pelo assistente porque você pediu para falar com Sidney.',
        'event' => 'Conversa com Sidney marcada pelo assistente',
        'next' => 'Conversa com o cliente (pediu para falar com Sidney)',
        'none' => 'Registrei o seu pedido para falar com Sidney. Não encontrei um horário livre na agenda dele nos próximos dias; ele vai entrar em contato para combinar.',
        'intro' => 'Combinado: marquei uma conversa de %d minutos com Sidney %s, %s',
    ];
}

/**
 * Marca a conversa de 30 minutos com Sidney e avisa o cliente no chat.
 * $origin: "pedido" (cliente insistiu em falar com Sidney) ou "coleta"
 * (informações confirmadas e documentos resolvidos: próxima etapa).
 * Retorna a reunião (já existente ou nova) ou null se não houver horário.
 */
function agenda_book(array $c, string $origin = 'pedido', ?DateTimeImmutable $at = null): ?array
{
    $id = (int) $c['id'];
    if ($m = current_meeting($id)) {
        return $m;
    }
    $R = agenda_reason($origin);
    $dur = max(10, agenda_int('agenda_duracao'));
    [$start, $gerr] = $at ? [$at, null] : agenda_next_slot();
    if ($gerr) {
        add_event($id, 'reuniao', 'Google Agenda indisponível ao marcar a conversa', $gerr . ' O horário foi calculado só com as reuniões do sistema.');
    }
    if (!$start) {
        chat_post($id, 'bot', $R['none']);
        add_event($id, 'reuniao', $R['event'] . ': sem horário livre na agenda', null, true);
        set_next($id, 'Combinar horário: ' . mb_strtolower($R['next']), in_business_days(1));
        return null;
    }
    $c = find_case($id);
    $desc = $R['why']
        . "\nProtocolo " . $c['protocol'] . ' · ' . client_case_title($c)
        . "\nTelefone: " . ($c['client_phone'] ? format_phone((string) $c['client_phone']) : 'não informado')
        . "\nFicha: " . public_link('painel/atendimento/' . $id);
    $ev = null;
    $link = null;
    if (google_connected() && !$gerr) {
        [$ev, $link, $err] = google_create_meeting($c, $start, $dur, $desc);
        if ($err) {
            add_event($id, 'reuniao', 'Não foi possível criar o compromisso no Google Agenda', $err . ' O horário ficou reservado no sistema.');
        }
    }
    $phone = only_digits((string) $c['client_phone']);
    $channel = $link ? 'meet' : (strlen($phone) >= 10 ? 'phone' : 'site_chat');
    $mid = insert('case_meetings', [
        'case_id' => $id, 'status' => 'confirmada', 'channel' => $channel, 'starts_at' => $start->format('Y-m-d H:i:s'),
        'duration_min' => $dur, 'url' => $link, 'phone' => $channel === 'phone' ? $phone : null,
        'note' => $R['note'], 'confirmed_at' => now_str(),
        'confirmed_by' => null, 'created_at' => now_str(), 'google_event_id' => $ev['id'] ?? null, 'origin' => $origin === 'coleta' ? 'coleta' : 'assistente',
    ]);
    if ($channel === 'site_chat') {
        bump_case($id, ['chat_mode' => 'human']);
    }
    $when = agenda_when($start) . ' (horário de Brasília)';
    $how = match ($channel) {
        'meet' => 'por vídeo no Google Meet. Link: ' . $link . (filter_var((string) $c['client_email'], FILTER_VALIDATE_EMAIL) ? "\nO convite também foi para o seu e-mail." : ''),
        'phone' => 'por ligação: Sidney vai ligar para ' . format_phone($phone) . '.',
        default => 'aqui mesmo, neste chat.',
    };
    $falta = $origin === 'coleta' ? docs_pending_labels($id) : agent_guide_missing($id);
    chat_post($id, 'bot', sprintf($R['intro'], $dur, $when, $how)
        . ($falta ? "\nAté lá, se encontrar, mande por aqui: " . implode(', ', array_slice($falta, 0, 4)) . '. Assim Sidney já chega preparado.' : ''));
    add_event($id, 'reuniao', $R['event'], $R['why'] . ' '
        . MEETING_CHANNELS[$channel]['short'] . ' ' . fmt_datetime($start->format('Y-m-d H:i:s')) . ($ev ? ' · Google Agenda' : ' · só no sistema') . '.', true);
    set_next($id, $R['next'], $start->format('Y-m-d H:i:s'));
    case_sync_later($id, false);
    return one('SELECT * FROM case_meetings WHERE id = ?', [$mid]);
}

/* ---------- Documentos depois da coleta: anexar ou se comprometer a procurar ---------- */

/** Documentos do cliente com a situação simplificada: recebido, vai procurar ou falta. */
function docs_client_list(int $id): array
{
    $c = find_case($id);
    $later = (array) case_data($c, 'doc_later', []);
    $out = [];
    foreach (all("SELECT * FROM documents WHERE case_id = ? AND status <> 'dispensado' ORDER BY essential DESC, id", [$id]) as $d) {
        $st = match (true) {
            in_array($d['status'], ['recebido', 'conferido'], true) => 'ok',
            $d['status'] === 'pendencia' => 'reenviar',
            $d['status'] === 'nao_possui' || isset($later[$d['kind']]) => 'procurar',
            default => 'falta',
        };
        $out[] = ['doc' => $d, 'st' => $st];
    }
    return $out;
}

/** Nomes dos documentos que o cliente ainda vai procurar ou reenviar. */
function docs_pending_labels(int $id): array
{
    return array_map(fn($x) => mb_strtolower($x['doc']['label']), array_values(array_filter(docs_client_list($id), fn($x) => $x['st'] !== 'ok')));
}

/** Todos os documentos foram anexados ou têm o compromisso de procurar? */
function docs_all_resolved(int $id): bool
{
    foreach (docs_client_list($id) as $x) {
        if (in_array($x['st'], ['falta', 'reenviar'], true)) {
            return false;
        }
    }
    return true;
}

const MEETING_UNLOCK_PCT = 70;

/**
 * O agendamento com Sidney é liberado a partir de 70% das informações e
 * documentos mínimos: o que falta não impede a conversa.
 */
function meeting_ready(int $id): bool
{
    $c = find_case($id);
    return $c['initial_status'] !== 'completed' && !current_meeting($id)
        && ($c['intake_done_at'] || agent_progress_pct($id) >= MEETING_UNLOCK_PCT);
}

/** Texto de engajamento da reunião, com os dados do caso. */
function meeting_pitch(int $id): array
{
    $banco = fv($id, 'banco');
    $veic = trim((string) fv($id, 'veiculo_marca') . ' ' . (string) fv($id, 'veiculo_modelo'));
    $prio = fv($id, 'objetivo_prioridade');
    return [
        'Sidney já está estudando o seu contrato' . ($banco ? ' com ' . $banco : '') . ($veic !== '' ? ' do ' . $veic : '') . '. Na conversa, você recebe a análise pronta, sem juridiquês:',
        [
            'o que encontramos no contrato: juros, tarifas e seguros que podem estar acima do permitido;',
            'os caminhos possíveis para ' . ($prio && isset(PRIORITY_OPTIONS[$prio]) ? mb_strtolower(PRIORITY_OPTIONS[$prio]['label']) : 'resolver a sua situação') . ', com prós e contras de cada um;',
            'quanto custa, como funciona e o que acontece em cada etapa;',
            'espaço para tirar todas as suas dúvidas.',
        ],
        'São ' . agenda_int('agenda_duracao') . ' minutos, ' . (google_connected() ? 'por vídeo no Google Meet' : 'por ligação') . ', sem compromisso de contratar. Quanto antes conversarmos, mais opções você tem.',
    ];
}

/* ---------- Pedidos para falar com Sidney ---------- */

/** A mensagem pede para falar com Sidney, com o advogado ou com uma pessoa? */
function human_ask_detect(string $text): bool
{
    $t = mb_strtolower(trim($text));
    if ($t === '' || mb_strlen($t) > 600) {
        return false;
    }
    $quem = '(o |a |com o |com a |com )?(sidney|sydney|sidnei|dr\.?|doutor|advogad[oa]|humano|ser humano|pessoa|atendente|algu[eé]m)';
    return (bool) (preg_match('/\b(falar|conversar|fala|converso|falo|atendimento|atender|ligar|liga|ligação|contato|chamar|passa|passar|transferir)\b[^.?!]{0,40}?\b' . $quem . '\b/u', $t)
        || preg_match('/\b(quero|prefiro|preciso de|cadê|cade|chama|chame)\b[^.?!]{0,25}?\b(o sidney|o advogado|o doutor|o dr|um humano|uma pessoa|um atendente|atendente|humano)\b/u', $t)
        || preg_match('/\b(pessoa de verdade|pessoa real|ser humano|não quero (falar com )?(robô|robo|bot|máquina))\b/u', $t));
}

function human_asks(array $c): int
{
    return (int) case_data($c, 'pedidos_sidney', 0);
}

/** Conta mais um pedido; retorna o total. */
function human_ask_register(int $id): int
{
    $n = human_asks(find_case($id)) + 1;
    set_case_data($id, 'pedidos_sidney', $n);
    add_event($id, 'atendimento', 'Cliente pediu para falar com Sidney (' . $n . 'º pedido)');
    return $n;
}

/** O que ainda falta, em palavras curtas (para pedir ao cliente). */
function agent_guide_missing(int $id): array
{
    $out = [];
    foreach (agent_guide($id) as $g) {
        if (!$g['done'] && $g['label'] !== AGENT_GUIDE_TOPICS['conversa'][0]) {
            $out[] = mb_strtolower(mb_substr($g['label'], 0, 1)) . mb_substr($g['label'], 1);
        }
    }
    return $out;
}

/** Instrução para a IA nos pedidos que ainda não marcam horário. */
function human_ask_instruction(int $id, int $n): string
{
    $falta = agent_guide_missing($id);
    return HUMAN_ASK_MARK . ' (' . $n . 'º pedido). Ainda não marque horário nem prometa ligação ou contato. '
        . 'Em até 2 frases, com gentileza, diga que para Sidney atender bem ele precisa antes das informações certas do caso e '
        . ($n >= 2 ? 'insista de forma mais concreta: peça uma coisa específica que falta, ' : 'peça ')
        . ($falta ? 'principalmente: ' . implode(', ', array_slice($falta, 0, 3)) . '. ' : 'que explique melhor o que está acontecendo. ')
        . 'Não diga quantos pedidos faltam para marcar.';
}

/** Resposta sem IA (roteiro com botões) nos pedidos que ainda não marcam horário. */
function human_ask_reply(int $id, int $n): string
{
    $falta = array_slice(agent_guide_missing($id), 0, 3);
    $lista = $falta ? implode(', ', $falta) : 'um resumo do que está acontecendo';
    return $n >= 2
        ? 'Entendo que quer falar com Sidney. Para a conversa com ele já resolver, preciso antes de: ' . $lista . '. Pode mandar por aqui agora?'
        : 'Para Sidney atender você bem, ele precisa antes das informações do caso. Me ajude com: ' . $lista . '.';
}

/** Lembrete quando já há conversa marcada. */
function human_ask_booked_reply(array $m): string
{
    return 'Sua conversa com Sidney já está marcada para ' . agenda_when(dt($m['starts_at'])) . ' (horário de Brasília), por ' . MEETING_CHANNELS[$m['channel']]['short'] . '.'
        . ($m['url'] ? ' Link: ' . $m['url'] : '') . ' Até lá, sigo por aqui com o que faltar.';
}

/**
 * Trata o pedido na mensagem do cliente. Retorna:
 * - null quando não é pedido (segue a conversa normal);
 * - ['reply' => texto] quando o sistema já respondeu (marcou ou lembrou o horário);
 * - ['instruction' => texto, 'n' => int] para a IA responder pedindo o que falta.
 */
function human_ask_handle(array $c, string $text): ?array
{
    if (!human_ask_detect($text)) {
        return null;
    }
    $id = (int) $c['id'];
    if ($m = current_meeting($id)) {
        chat_post($id, 'bot', human_ask_booked_reply($m));
        return ['reply' => true];
    }
    $n = human_ask_register($id);
    if ($n >= HUMAN_ASKS_TO_SCHEDULE) {
        agenda_book(find_case($id));
        return ['reply' => true];
    }
    return ['instruction' => human_ask_instruction($id, $n), 'n' => $n];
}
