<?php
declare(strict_types=1);

/**
 * Conversas do cliente: cada atendimento da conta aparece como uma conversa,
 * com marcação do que é novo e aviso por e-mail quando há novidade que a
 * pessoa ainda não viu. O e-mail nunca leva o conteúdo da conversa, só o link.
 */

const CLIENT_OPEN_CASES_MAX = 3;
const ACCESS_LINK_DAYS = 7;

/* ---------- Endereço público (links dos e-mails) ---------- */

/** Raiz pública do sistema: o endereço desta requisição; fora dela, o último visto. */
function public_root(): string
{
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    if ($host !== '' && preg_match('/^[a-z0-9.-]+(:\d+)?$/i', $host)) {
        $https = ($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        $local = preg_match('/^(localhost|127\.0\.0\.1)(:\d+)?$/', $host);
        return ($https || !$local ? 'https://' : 'http://') . $host . base_path();
    }
    return rtrim((string) (setting('public_url') ?: office('base_url')), '/');
}

function public_link(string $path): string
{
    return rtrim(public_root(), '/') . '/' . ltrim($path, '/');
}

/* ---------- Lista de conversas ---------- */

/** Nome curto do atendimento na lista: o banco e o veículo, quando já informados. */
function client_case_title(array $c): string
{
    $id = (int) $c['id'];
    $banco = trim((string) (fv($id, 'banco') ?? $c['bank'] ?? ''));
    $veiculo = trim(implode(' ', array_filter([(string) (fv($id, 'veiculo_marca') ?? ''), (string) (fv($id, 'veiculo_modelo') ?? '')])));
    if ($banco !== '' || $veiculo !== '') {
        return mb_strimwidth(implode(' · ', array_filter([$banco, $veiculo])), 0, 60, '…');
    }
    return is_initial($c) && $c['initial_status'] === 'collecting' ? 'Novo atendimento' : 'Atendimento ' . $c['protocol'];
}

/** Mensagens e avisos que a pessoa ainda não viu neste atendimento. */
function client_unread(array $c): int
{
    $n = (int) scalar("SELECT COUNT(*) FROM chat_messages WHERE case_id = ? AND author <> 'client' AND id > ?", [$c['id'], (int) $c['client_seen_msg_id']]);
    if (!is_initial($c) || !empty($c['execution_started_at'])) {
        // Na fase contratada, as novidades aparecem também como movimentações do caso.
        $n += (int) scalar("SELECT COUNT(*) FROM events WHERE case_id = ? AND visible_to_client = 1 AND actor <> 'cliente' AND at > ?", [$c['id'], (string) ($c['client_seen_at'] ?: '0')]);
    }
    return $n;
}

/** A pessoa abriu a conversa: o que estava na tela deixa de ser novidade. */
function mark_case_seen(int $caseId, ?int $upToMsg = null): void
{
    $max = $upToMsg ?? (int) scalar('SELECT COALESCE(MAX(id), 0) FROM chat_messages WHERE case_id = ?', [$caseId]);
    q('UPDATE cases SET client_seen_msg_id = MAX(client_seen_msg_id, ?), client_seen_at = ? WHERE id = ?', [$max, now_str(), $caseId]);
}

/** Atendimentos que a pessoa pode abrir agora, como conversas (mais recente primeiro). */
function client_conversations(?array $current = null): array
{
    $acc = portal_account_id();
    $cases = $acc ? account_cases($acc) : [];
    if ($current && !array_filter($cases, fn($x) => (int) $x['id'] === (int) $current['id'])) {
        $cases[] = $current;
    }
    $rows = [];
    foreach ($cases as $c) {
        $last = one('SELECT author, body, created_at FROM chat_messages WHERE case_id = ? ORDER BY id DESC LIMIT 1', [$c['id']]);
        $at = $last['created_at'] ?? $c['created_at'];
        $rows[] = [
            'id' => (int) $c['id'],
            'protocol' => $c['protocol'],
            'title' => client_case_title($c),
            'status' => is_initial($c) && empty($c['execution_started_at']) ? initial_status_label($c, true) : 'Caso em andamento',
            'preview' => $last ? ($last['author'] === 'client' ? 'Você: ' : '') . mb_strimwidth(preg_replace('/\s+/u', ' ', (string) $last['body']), 0, 80, '…') : '',
            'at' => $at,
            'unread' => client_unread($c),
            'current' => $current && (int) $current['id'] === (int) $c['id'],
        ];
    }
    usort($rows, fn($a, $b) => strcmp($b['at'], $a['at']) ?: $b['id'] <=> $a['id']);
    return $rows;
}

/** Hora curta na lista: “14:05”, “ontem” ou a data. */
function conv_when(string $at): string
{
    $d = dt($at);
    $today = now()->format('Y-m-d');
    if ($d->format('Y-m-d') === $today) {
        return $d->format('H:i');
    }
    if ($d->format('Y-m-d') === now()->modify('-1 day')->format('Y-m-d')) {
        return 'ontem';
    }
    return $d->format('d/m/y');
}

/* ---------- Novo atendimento ---------- */

/**
 * Abre o atendimento e a conversa: saudação, identificação da pessoa e, com a
 * IA ligada, a primeira pergunta.
 */
function start_client_case(array $a, ?int $accountId, string $intro, ?string $agentStart = null): int
{
    $c = create_initial_case($a, 'site', $accountId);
    $id = (int) $c['id'];
    $digits = only_digits((string) $a['telefone']);
    chat_post($id, 'bot', bot_greeting());
    chat_post($id, 'client', $intro !== '' ? $intro : 'Sou ' . $a['nome'] . '. Meu contato é ' . format_phone($digits) . ' e ' . mb_strtolower((string) $a['email']) . '.');
    set_field($id, 'nome_completo', (string) $a['nome']);
    if (isset(NOTICE_OPTIONS[$a['aviso'] ?? ''])) {
        // Reaproveita a seleção feita na página inicial (B02).
        set_field($id, 'aviso', $a['aviso']);
        update_urgency_signal($id);
    }
    sync_intake_documents($id);
    if (agent_enabled()) {
        // A IA conduz: cumprimenta pelo nome e faz a primeira pergunta.
        set_case_data($id, 'agent_started', now_str());
        agent_turn(find_case($id), $agentStart ?? 'Início da conversa: a pessoa acabou de se identificar no site (nome completo, e-mail e telefone já registrados). Cumprimente pelo primeiro nome, diga em uma frase como funciona (ela pode escrever, mandar áudio, fotos ou PDFs) e faça a primeira pergunta.');
    }
    mark_case_seen($id);
    return $id;
}

/** Atendimentos da conta ainda em acolhimento ou análise (sem contrato em execução). */
function account_open_cases(int $accountId): array
{
    return array_values(array_filter(account_cases($accountId), fn($c) => is_initial($c) && $c['initial_status'] !== 'completed' && empty($c['execution_started_at'])));
}

/**
 * Segundo (ou terceiro) atendimento aberto pela própria pessoa, para outro
 * contrato. Usa a identificação já registrada; nada do outro atendimento vai
 * para a conversa nova.
 * @return int|string id do atendimento ou o motivo da recusa
 */
function open_client_case(int $accountId): int|string
{
    $open = account_open_cases($accountId);
    if (count($open) >= CLIENT_OPEN_CASES_MAX) {
        return 'Você já tem ' . count($open) . ' atendimentos em andamento. Continue por eles ou peça a Sidney na conversa para abrir outro.';
    }
    if (too_many_attempts('novo-atendimento:' . $accountId, 4, 60 * 24)) {
        return 'Você abriu vários atendimentos hoje. Se precisar de outro, peça a Sidney em uma das conversas.';
    }
    $last = one('SELECT * FROM cases WHERE account_id = ? ORDER BY id DESC LIMIT 1', [$accountId]);
    $acc = one('SELECT * FROM client_accounts WHERE id = ?', [$accountId]);
    if (!$last && !$acc) {
        return 'Não encontramos seus dados. Entre de novo na área do cliente.';
    }
    $nome = (string) (($last ? fv((int) $last['id'], 'nome_completo') : null) ?: ($last['client_name'] ?? $acc['name'] ?? ''));
    $a = [
        'nome' => $nome,
        'telefone' => (string) ($last['client_phone'] ?? $acc['phone'] ?? ''),
        'email' => (string) (($last['client_email'] ?? null) ?: ($acc['email'] ?? '')),
        'origem' => 'area do cliente',
    ];
    record_attempt('novo-atendimento:' . $accountId);
    $id = start_client_case($a, $accountId, 'Quero tratar de outro contrato ou assunto em um novo atendimento.',
        'Início de um novo atendimento: a pessoa já é cliente do escritório e abriu esta conversa para tratar de outro contrato ou assunto (nome completo, e-mail e telefone já registrados). Cumprimente pelo primeiro nome, diga que este é um atendimento separado, que ela pode escrever, mandar áudio, fotos ou PDFs, e pergunte do que se trata.');
    if ($last) {
        add_event($id, 'vinculo', 'Atendimento aberto pelo cliente na área do cliente', 'O mesmo cliente tem o atendimento ' . $last['protocol'] . '.');
    }
    return $id;
}

/* ---------- Link de acesso por e-mail ---------- */

function client_access_link(int $accountId, ?int $caseId = null): string
{
    $token = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
    insert('client_access_links', [
        'account_id' => $accountId,
        'case_id' => $caseId,
        'token_hash' => hash('sha256', $token),
        'expires_at' => now()->modify('+' . ACCESS_LINK_DAYS . ' days')->format('Y-m-d H:i:s'),
        'created_at' => now_str(),
    ]);
    q('DELETE FROM client_access_links WHERE expires_at < ?', [now_str()]);
    return public_link('cliente/acesso/' . $token);
}

/** @return array{0:int,1:?int}|null conta e atendimento do link válido */
function client_access_consume(string $token): ?array
{
    if (!preg_match('/^[A-Za-z0-9_-]{20,64}$/', $token)) {
        return null;
    }
    $l = one('SELECT * FROM client_access_links WHERE token_hash = ? AND expires_at > ?', [hash('sha256', $token), now_str()]);
    if (!$l) {
        return null;
    }
    q('UPDATE client_access_links SET used_at = COALESCE(used_at, ?) WHERE id = ?', [now_str(), $l['id']]);
    return [(int) $l['account_id'], $l['case_id'] ? (int) $l['case_id'] : null];
}

/* ---------- Envio de e-mail ---------- */

const MAIL_DRIVERS = ['smtp' => 'Servidor SMTP (recomendado)', 'mail' => 'Função de e-mail da hospedagem', 'off' => 'Desligado'];

function mail_driver(): string
{
    $d = (string) setting('mail_driver', 'mail');
    return isset(MAIL_DRIVERS[$d]) || $d === 'arquivo' ? $d : 'mail';
}

function mail_from(): array
{
    $addr = (string) (setting('mail_from') ?: (mail_driver() === 'smtp' ? setting('smtp_user') : '') ?: office('email'));
    return [$addr, (string) (setting('mail_from_name') ?: office('brand'))];
}

function mail_header_text(string $s): string
{
    return preg_match('/[^\x20-\x7E]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
}

/** Mensagem MIME (texto e HTML) pronta para envio. @return array{0:string,1:string} cabeçalhos e corpo */
function mail_build(string $to, string $subject, string $text, string $html): array
{
    [$from, $name] = mail_from();
    $b = 'b' . bin2hex(random_bytes(8));
    $domain = substr(strrchr($from, '@') ?: '@localhost', 1);
    $headers = [
        'From: ' . mail_header_text($name) . ' <' . $from . '>',
        'Reply-To: ' . office('email'),
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $domain . '>',
        'Date: ' . date('r'),
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $b . '"',
    ];
    $body = "--$b\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($text))
        . "--$b\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html))
        . "--$b--\r\n";
    return [implode("\r\n", $headers), $body];
}

/** @return array{0:bool,1:?string} enviado? e o erro */
function send_mail(string $to, string $subject, string $text, string $html, ?int $caseId = null, string $kind = 'aviso'): array
{
    $to = trim($to);
    if (!filter_var($to, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $to)) {
        return [false, 'E-mail do destinatário inválido.'];
    }
    $driver = mail_driver();
    [$headers, $body] = mail_build($to, $subject, $text, $html);
    $err = null;
    try {
        if ($driver === 'off') {
            $err = 'Envio de e-mails desligado nas configurações.';
        } elseif ($driver === 'arquivo') {
            // Testes: grava a mensagem em vez de enviar.
            $dir = config('storage_dir') . '/mail';
            is_dir($dir) || mkdir($dir, 0775, true);
            file_put_contents($dir . '/' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.eml', "To: $to\r\nSubject: " . mail_header_text($subject) . "\r\n$headers\r\n\r\n$body");
        } elseif ($driver === 'smtp') {
            $err = smtp_send($to, $subject, $headers, $body);
        } else {
            [$from] = mail_from();
            $ok = @mail($to, mail_header_text($subject), $body, $headers, '-f' . $from);
            $err = $ok ? null : 'A hospedagem recusou o envio pela função mail().';
        }
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
    insert('mail_log', ['case_id' => $caseId, 'to_addr' => $to, 'subject' => mb_substr($subject, 0, 200), 'kind' => $kind, 'ok' => $err === null ? 1 : 0, 'error' => $err ? mb_substr($err, 0, 500) : null, 'created_at' => now_str()]);
    return [$err === null, $err];
}

/** Envio por SMTP autenticado (SSL na 465 ou STARTTLS na 587). @return ?string erro */
function smtp_send(string $to, string $subject, string $headers, string $body): ?string
{
    $host = trim((string) setting('smtp_host'));
    $port = (int) (setting('smtp_port') ?: 465);
    $user = (string) setting('smtp_user');
    $pass = (string) setting('smtp_pass');
    if ($host === '' || $user === '' || $pass === '') {
        return 'SMTP incompleto: informe servidor, usuário e senha.';
    }
    [$from] = mail_from();
    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true, 'peer_name' => $host]]);
    $fp = @stream_socket_client(($port === 465 ? 'ssl://' : 'tcp://') . $host . ':' . $port, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) {
        return 'Não foi possível conectar a ' . $host . ':' . $port . ' (' . $errstr . ').';
    }
    stream_set_timeout($fp, 20);
    $read = function () use ($fp): string {
        $out = '';
        while (($line = fgets($fp, 1024)) !== false) {
            $out .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }
        return $out;
    };
    $cmd = function (?string $c, array $expect) use ($fp, $read): string {
        if ($c !== null) {
            fwrite($fp, $c . "\r\n");
        }
        $r = $read();
        if (!in_array((int) substr($r, 0, 3), $expect, true)) {
            throw new RuntimeException('SMTP: ' . trim(preg_replace('/\s+/', ' ', $r) ?: 'sem resposta'));
        }
        return $r;
    };
    try {
        $cmd(null, [220]);
        $ehlo = 'EHLO ' . (preg_replace('/[^a-z0-9.-]/i', '', (string) ($_SERVER['SERVER_NAME'] ?? '')) ?: 'localhost');
        $caps = $cmd($ehlo, [250]);
        $local = in_array($host, ['localhost', '127.0.0.1'], true);
        if ($port !== 465 && !stripos($caps, 'STARTTLS') && !$local) {
            throw new RuntimeException('SMTP: o servidor não oferece conexão segura nesta porta. Use a porta 465.');
        }
        if ($port !== 465 && stripos($caps, 'STARTTLS')) {
            $cmd('STARTTLS', [220]);
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                throw new RuntimeException('SMTP: falha ao iniciar a conexão segura (STARTTLS).');
            }
            $cmd($ehlo, [250]);
        }
        $cmd('AUTH LOGIN', [334]);
        $cmd(base64_encode($user), [334]);
        $cmd(base64_encode($pass), [235]);
        $cmd('MAIL FROM:<' . $from . '>', [250]);
        $cmd('RCPT TO:<' . $to . '>', [250, 251]);
        $cmd('DATA', [354]);
        $data = "To: <$to>\r\nSubject: " . mail_header_text($subject) . "\r\n" . $headers . "\r\n\r\n" . $body;
        $data = preg_replace('/^\./m', '..', str_replace(["\r\n", "\n"], ["\n", "\r\n"], $data));
        fwrite($fp, $data . "\r\n.\r\n");
        $cmd(null, [250]);
        fwrite($fp, "QUIT\r\n");
        return null;
    } catch (Throwable $e) {
        return $e->getMessage();
    } finally {
        fclose($fp);
    }
}

/** HTML simples do e-mail, com o botão de acesso. */
function mail_html(string $title, array $paras, ?string $button = null, ?string $href = null): string
{
    $p = implode('', array_map(fn($x) => '<p style="margin:0 0 14px">' . e($x) . '</p>', $paras));
    $btn = $href ? '<p style="margin:22px 0"><a href="' . e($href) . '" style="background:#B8893B;color:#fff;text-decoration:none;padding:12px 22px;border-radius:999px;font-weight:600;display:inline-block">' . e((string) $button) . '</a></p>' : '';
    return '<!doctype html><html lang="pt-BR"><body style="margin:0;background:#F4F1EA;font:16px/1.55 Arial,Helvetica,sans-serif;color:#1d2b2e">'
        . '<div style="max-width:560px;margin:0 auto;padding:28px 18px"><div style="background:#fff;border-radius:14px;padding:26px 24px">'
        . '<p style="margin:0 0 6px;color:#0F3B43;font-weight:700">' . e(office('brand')) . '</p>'
        . '<h1 style="font-size:20px;margin:0 0 16px;color:#0F3B43">' . e($title) . '</h1>' . $p . $btn
        . '<p style="margin:18px 0 0;color:#6b7678;font-size:13px">Por segurança, o conteúdo da conversa fica só na área do cliente. Nunca pedimos senha do banco ou do Gov.br. Se você não reconhece este atendimento, ignore este e-mail.</p>'
        . '</div></div></body></html>';
}

/* ---------- Avisos de novidade ---------- */

/** Minutos de espera antes de avisar (se a pessoa estiver com a conversa aberta, ela já vê). */
function notify_delay_min(): int
{
    $m = (int) setting('notify_delay_min', '10');
    return max(1, min(240, $m ?: 10));
}

/**
 * Atendimentos com novidade ainda não vista nem avisada, mais antiga que a espera.
 * @return array<int,string> id do atendimento => momento da primeira novidade
 */
function notify_candidates(): array
{
    $cutoff = now()->modify('-' . notify_delay_min() . ' minutes')->format('Y-m-d H:i:s');
    $out = [];
    $rows = all("SELECT c.id, MIN(m.created_at) AS first_new FROM cases c
        JOIN client_accounts a ON a.id = c.account_id AND a.notify_email = 1
        JOIN chat_messages m ON m.case_id = c.id AND m.author <> 'client' AND m.id > c.client_seen_msg_id
            AND (c.client_notified_at IS NULL OR m.created_at > c.client_notified_at)
        GROUP BY c.id HAVING MIN(m.created_at) <= ?", [$cutoff]);
    $rows = array_merge($rows, all("SELECT c.id, MIN(ev.at) AS first_new FROM cases c
        JOIN client_accounts a ON a.id = c.account_id AND a.notify_email = 1
        JOIN events ev ON ev.case_id = c.id AND ev.visible_to_client = 1 AND ev.actor <> 'cliente'
            AND ev.at > COALESCE(c.client_seen_at, '0') AND (c.client_notified_at IS NULL OR ev.at > c.client_notified_at)
        WHERE (c.workflow_version <> 'simple_v1' OR c.execution_started_at IS NOT NULL)
        GROUP BY c.id HAVING MIN(ev.at) <= ?", [$cutoff]));
    foreach ($rows as $r) {
        $out[(int) $r['id']] = min($out[(int) $r['id']] ?? $r['first_new'], $r['first_new']);
    }
    return $out;
}

/** Envia o aviso de novidade de um atendimento. */
function notify_case(int $caseId): bool
{
    $c = one('SELECT * FROM cases WHERE id = ?', [$caseId]);
    if (!$c || !$c['account_id'] || case_access_expired($c)) {
        return false;
    }
    $acc = one('SELECT * FROM client_accounts WHERE id = ?', [$c['account_id']]);
    $to = (string) (($acc['email'] ?? '') ?: ($c['client_email'] ?? ''));
    // Marca antes de enviar: uma falha não vira uma enxurrada de tentativas.
    q('UPDATE cases SET client_notified_at = ? WHERE id = ?', [now_str(), $caseId]);
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    $n = client_unread($c);
    if ($n < 1) {
        return false;
    }
    $link = client_access_link((int) $c['account_id'], $caseId);
    $first = first_name((string) $c['client_name']);
    $title = client_case_title($c);
    $subject = 'Novidade no seu atendimento ' . $c['protocol'];
    $lines = [
        'Olá, ' . $first . '!',
        'Há ' . plural($n, 'novidade', 'novidades') . ' no seu atendimento “' . $title . '” (protocolo ' . $c['protocol'] . ').',
        'Toque no botão para abrir a conversa. O link vale por ' . ACCESS_LINK_DAYS . ' dias e é só seu: não encaminhe este e-mail.',
    ];
    $text = implode("\n\n", $lines) . "\n\nAbrir a conversa: " . $link . "\n\n" . office('brand') . "\nPor segurança, o conteúdo da conversa fica só na área do cliente.";
    [$ok] = send_mail($to, $subject, $text, mail_html('Há novidade no seu atendimento', $lines, 'Abrir a conversa', $link), $caseId);
    if ($ok) {
        add_event($caseId, 'aviso', 'Cliente avisado por e-mail de novidade na conversa', plural($n, 'novidade', 'novidades') . ' não vistas.', false, 'sistema');
    }
    return $ok;
}

/** Rodada de avisos: no máximo uma por minuto, disparada pelo próprio tráfego ou pelo cron. */
function notify_tick(bool $force = false): int
{
    if (mail_driver() === 'off') {
        return 0;
    }
    $now = now_str();
    if (!$force) {
        $limit = now()->modify('-1 minute')->format('Y-m-d H:i:s');
        if ((string) scalar("SELECT value FROM settings WHERE key = 'notify_last_run'") >= $limit) {
            return 0;
        }
        q("INSERT OR IGNORE INTO settings (key, value, updated_at) VALUES ('notify_last_run', '0', ?)", [$now]);
        $ok = q("UPDATE settings SET value = ?, updated_at = ? WHERE key = 'notify_last_run' AND value < ?", [$now, $now, now()->modify('-1 minute')->format('Y-m-d H:i:s')])->rowCount();
        if (!$ok) {
            return 0;
        }
    }
    if (isset($_SERVER['HTTP_HOST']) && setting('public_url') !== public_root()) {
        set_setting('public_url', public_root());
    }
    $sent = 0;
    foreach (array_slice(notify_candidates(), 0, 20, true) as $id => $_) {
        $sent += notify_case($id) ? 1 : 0;
    }
    return $sent;
}

/** Envio dos links de acesso para quem perdeu a sessão e não tem senha. */
function send_access_links(string $email): void
{
    $email = mb_strtolower(trim($email));
    $accs = all('SELECT DISTINCT a.id, a.name FROM client_accounts a LEFT JOIN cases c ON c.account_id = a.id
        WHERE lower(a.email) = ? OR lower(c.client_email) = ? ORDER BY a.id DESC LIMIT 3', [$email, $email]);
    foreach ($accs as $a) {
        if (!account_cases((int) $a['id'])) {
            continue;
        }
        $link = client_access_link((int) $a['id']);
        $lines = ['Olá, ' . first_name((string) $a['name']) . '!', 'Use o botão abaixo para entrar na área do cliente e ver seus atendimentos. O link vale por ' . ACCESS_LINK_DAYS . ' dias.', 'Se não foi você quem pediu, ignore este e-mail: ninguém entra sem este link.'];
        send_mail($email, 'Seu acesso à área do cliente', implode("\n\n", $lines) . "\n\nEntrar: " . $link, mail_html('Seu acesso à área do cliente', $lines, 'Entrar na área do cliente', $link), null, 'acesso');
    }
}

/* ---------- Conta na sessão ---------- */

function portal_has_password(): bool
{
    $acc = portal_account_id();
    return $acc && (bool) scalar('SELECT pass_hash IS NOT NULL FROM client_accounts WHERE id = ?', [$acc]);
}

function portal_notify_on(): bool
{
    $acc = portal_account_id();
    return $acc && (bool) scalar('SELECT notify_email FROM client_accounts WHERE id = ?', [$acc]);
}
