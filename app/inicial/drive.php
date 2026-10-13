<?php
declare(strict_types=1);

/**
 * Pasta do atendimento no Google Drive do escritório e o arquivo .md do caso.
 *
 * Cada atendimento ganha uma pasta com o protocolo como nome (ex.: SF-65HPP2)
 * dentro de "Meu Drive/000 - Sites/Atendimentos Site" (caminho ajustável em
 * Configurações › Google). Lá vão os arquivos enviados e um "<protocolo>.md"
 * com tudo o que se sabe do caso: variáveis, contexto, necessidades, pontos
 * relevantes, documentos, reunião e a conversa. O .md também é baixado pelo
 * painel. Usa a mesma conexão OAuth da agenda (escopo do Drive).
 */

const DRIVE_PATH_DEFAULT = '000 - Sites/Atendimentos Site';
const DRIVE_API = 'https://www.googleapis.com/drive/v3';
const DRIVE_UPLOAD = 'https://www.googleapis.com/upload/drive/v3';
const DRIVE_FOLDER_MIME = 'application/vnd.google-apps.folder';

function drive_path(): string
{
    return trim((string) setting('google_drive_pasta', ''), " /\\") ?: DRIVE_PATH_DEFAULT;
}

/** O Drive está liberado? (conectado e com permissão do Drive concedida) */
function drive_enabled(): bool
{
    return google_connected() && setting('drive_off') !== '1'
        && str_contains((string) setting('google_scopes', ''), 'auth/drive');
}

/** Executa depois de responder ao cliente (não atrasa a tela). */
function after_response(callable $fn): void
{
    static $queued = [];
    if (!$queued) {
        register_shutdown_function(function () use (&$queued) {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            } elseif (function_exists('litespeed_finish_request')) {
                litespeed_finish_request();
            }
            @set_time_limit(300);
            foreach ($queued as $f) {
                try {
                    $f();
                } catch (Throwable $e) {
                    error_log('[fatuche][depois] ' . $e->getMessage());
                }
            }
        });
    }
    $queued[] = $fn;
}

/** Atualiza contexto e Drive do atendimento depois da resposta (uma vez por requisição). */
function case_sync_later(int $id, bool $context = true): void
{
    static $done = [];
    if (isset($done[$id])) {
        return;
    }
    $done[$id] = true;
    after_response(function () use ($id, $context) {
        if ($context) {
            case_context_refresh($id);
        }
        drive_sync_case($id);
    });
}

/* ---------- Drive: pastas e arquivos ---------- */

function drive_q(string $s): string
{
    return str_replace(['\\', "'"], ['\\\\', "\\'"], $s);
}

/** Procura (ou cria) uma pasta dentro de outra. [id, erro]. */
function drive_folder(string $name, string $parent): array
{
    $q = "name = '" . drive_q($name) . "' and '" . drive_q($parent) . "' in parents and mimeType = '" . DRIVE_FOLDER_MIME . "' and trashed = false";
    [$ok, $d, $err] = google_api_url('GET', DRIVE_API . '/files?' . http_build_query(['q' => $q, 'fields' => 'files(id,name)', 'pageSize' => 1, 'supportsAllDrives' => 'true', 'includeItemsFromAllDrives' => 'true']));
    if (!$ok) {
        return [null, $err];
    }
    if (!empty($d['files'][0]['id'])) {
        return [(string) $d['files'][0]['id'], null];
    }
    [$ok, $d, $err] = google_api_url('POST', DRIVE_API . '/files?supportsAllDrives=true', ['name' => $name, 'mimeType' => DRIVE_FOLDER_MIME, 'parents' => [$parent]]);
    return $ok && !empty($d['id']) ? [(string) $d['id'], null] : [null, $err ?: 'O Drive não criou a pasta.'];
}

/** Pasta "Atendimentos Site" (o caminho configurado a partir de Meu Drive). [id, erro]. */
function drive_root(): array
{
    $cache = (string) setting('drive_root_id', '');
    if ($cache !== '' && str_starts_with($cache, drive_path() . '|')) {
        return [substr($cache, strlen(drive_path()) + 1), null];
    }
    $parent = 'root';
    foreach (array_filter(array_map('trim', preg_split('#[/\\\\]#', drive_path()))) as $part) {
        if (in_array(mb_strtolower($part), ['meu drive', 'my drive'], true)) {
            continue;
        }
        [$parent, $err] = drive_folder($part, $parent);
        if (!$parent) {
            return [null, $err];
        }
    }
    set_setting('drive_root_id', drive_path() . '|' . $parent);
    return [$parent, null];
}

/** Pasta do atendimento (nome = protocolo). [id, erro]. */
function drive_case_folder(array $c): array
{
    $id = (int) $c['id'];
    if ($f = case_data($c, 'drive_pasta')) {
        return [(string) $f, null];
    }
    [$root, $err] = drive_root();
    if (!$root) {
        return [null, $err];
    }
    [$f, $err] = drive_folder((string) $c['protocol'], $root);
    if ($f) {
        set_case_data($id, 'drive_pasta', $f);
    }
    return [$f, $err];
}

/** Envio multipart (metadados + conteúdo). [id, erro]. */
function drive_upload(array $meta, string $content, string $mime, ?string $replaceId = null): array
{
    if ($replaceId) {
        [$ok, $d, $err] = google_api_url('PATCH', DRIVE_UPLOAD . '/files/' . rawurlencode($replaceId) . '?uploadType=media&supportsAllDrives=true', ['_raw' => $content, '_type' => $mime]);
        if ($ok) {
            return [$replaceId, null];
        }
        // apagado no Drive: cria de novo
    }
    $b = 'fatuche' . bin2hex(random_bytes(8));
    $body = "--$b\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n" . json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        . "\r\n--$b\r\nContent-Type: $mime\r\n\r\n" . $content . "\r\n--$b--";
    [$ok, $d, $err] = google_api_url('POST', DRIVE_UPLOAD . '/files?uploadType=multipart&supportsAllDrives=true&fields=id', ['_raw' => $body, '_type' => 'multipart/related; boundary=' . $b]);
    return $ok && !empty($d['id']) ? [(string) $d['id'], null] : [null, $err ?: 'O Drive não recebeu o arquivo.'];
}

/** Nome do arquivo no Drive: "Documento - nome original". */
function drive_file_name(array $f): string
{
    $label = $f['document_id'] ? (string) scalar('SELECT label FROM documents WHERE id = ?', [$f['document_id']]) : ($f['kind'] === 'audio' ? 'Áudio' : 'Arquivo');
    return mb_substr(preg_replace('#[\\\\/:*?"<>|]+#u', '-', $label . ' - ' . $f['original_name']), 0, 180);
}

/**
 * Leva ao Drive os arquivos ainda não enviados e regrava o .md do caso.
 * Retorna [arquivos enviados, erro ou null]. Erros só ficam no histórico.
 */
function drive_sync_case(int $id): array
{
    if (!drive_enabled()) {
        return [0, 'Drive não conectado.'];
    }
    $c = find_case_or_null($id);
    if (!$c) {
        return [0, 'Atendimento não encontrado.'];
    }
    [$folder, $err] = drive_case_folder($c);
    if (!$folder) {
        add_event($id, 'drive', 'Não foi possível gravar no Google Drive', $err);
        return [0, $err];
    }
    $sent = 0;
    foreach (all('SELECT * FROM files WHERE case_id = ? AND drive_id IS NULL AND internal = 0 AND replaced_at IS NULL ORDER BY id', [$id]) as $f) {
        $path = config('storage_dir') . '/uploads/' . $id . '/' . $f['stored_name'];
        if (!is_file($path)) {
            continue;
        }
        [$did, $err] = drive_upload(['name' => drive_file_name($f), 'parents' => [$folder]], (string) file_get_contents($path), (string) $f['mime']);
        if (!$did) {
            add_event($id, 'drive', 'Arquivo não foi para o Google Drive', $f['original_name'] . ': ' . $err);
            return [$sent, $err];
        }
        q('UPDATE files SET drive_id = ? WHERE id = ?', [$did, $f['id']]);
        $sent++;
    }
    $c = find_case($id);
    [$mdId, $err] = drive_upload(['name' => $c['protocol'] . '.md', 'parents' => [$folder], 'mimeType' => 'text/markdown'], case_markdown($id), 'text/markdown', case_data($c, 'drive_md') ?: null);
    if ($mdId) {
        set_case_data($id, 'drive_md', $mdId);
        set_case_data($id, 'drive_at', now_str());
    } else {
        add_event($id, 'drive', 'Resumo do caso (.md) não foi para o Google Drive', $err);
    }
    return [$sent, $err];
}

/* ---------- Contexto do caso (IA) ---------- */

/**
 * Contexto, necessidades e pontos relevantes, montados pela IA a partir da
 * ficha, das leituras dos documentos e da conversa. Só é refeito quando algo
 * mudou. Sem IA, o .md usa o texto montado da ficha.
 */
function case_context_refresh(int $id): ?array
{
    $c = find_case_or_null($id);
    if (!$c || !llm_enabled()) {
        return null;
    }
    $facts = case_facts_text($id);
    $recent = array_reverse(all("SELECT author, body FROM chat_messages WHERE case_id = ? AND author IN ('client', 'operator') ORDER BY id DESC LIMIT 30", [$id]));
    $talk = implode("\n", array_map(fn($m) => ($m['author'] === 'client' ? 'Cliente' : 'Sidney') . ': ' . mb_substr(str_replace("\n", ' ', $m['body']), 0, 400), $recent));
    $hash = sha1($facts . $talk);
    $cur = (array) case_data($c, 'contexto_ia', []);
    if (($cur['hash'] ?? '') === $hash) {
        return $cur;
    }
    $res = llm_chat([
        ['role' => 'system', 'content' => 'Você organiza o caso para o advogado Sidney Fatuche (financiamento de veículo: revisão de juros, atraso, busca e apreensão). '
            . 'Use só os fatos informados; nunca invente nem dê parecer jurídico. Responda SOMENTE com JSON: '
            . '{"contexto": "3 a 6 frases sobre a situação da pessoa e o que ela busca", "necessidades": ["principal necessidade"], "relevante": ["ponto relevante para o processo (prazos, urgência, divergências, documentos que faltam, capacidade de pagamento)"]}'],
        ['role' => 'user', 'content' => "FICHA E DOCUMENTOS:\n" . $facts . "\n\nCONVERSA:\n" . ($talk ?: '- sem mensagens')],
    ], $id, 'contexto', 900, 60);
    $json = $res['ok'] ? llm_json($res['content']) : null;
    if (!$json || trim((string) ($json['contexto'] ?? '')) === '') {
        return $cur ?: null;
    }
    $clean = fn($l) => array_values(array_slice(array_filter(array_map(fn($x) => trim(is_scalar($x) ? (string) $x : ''), (array) $l)), 0, 8));
    $out = ['contexto' => mb_substr(trim((string) $json['contexto']), 0, 2000), 'necessidades' => $clean($json['necessidades'] ?? []), 'relevante' => $clean($json['relevante'] ?? []), 'at' => now_str(), 'hash' => $hash];
    set_case_data($id, 'contexto_ia', $out);
    return $out;
}

/** Fatos em texto simples (para a IA): todas as variáveis conhecidas e as leituras. */
function case_facts_text(int $id): string
{
    $lines = [];
    foreach (case_fields($id, true) as $k => $r) {
        $def = field_def($k);
        if ($def && field_display($k, $r) !== '') {
            $lines[] = '- ' . $def['label'] . ': ' . str_replace("\n", ' ', field_display($k, $r));
        }
    }
    foreach ((array) case_data(find_case($id), 'doc_notas', []) as $n) {
        if (!empty($n['obs']) || !empty($n['divergencias'])) {
            $lines[] = '- Leitura de ' . $n['label'] . ': ' . trim(($n['obs'] ?? '') . ' ' . implode('; ', (array) ($n['divergencias'] ?? [])));
        }
    }
    foreach (docs_client_list($id) as $x) {
        $lines[] = '- Documento ' . $x['doc']['label'] . ': ' . ($x['st'] === 'ok' ? 'recebido' : 'falta');
    }
    return implode("\n", $lines);
}

/* ---------- Arquivo .md ---------- */

function md_esc(string $s): string
{
    return str_replace(['|', "\r"], ['\\|', ''], $s);
}

/** Tudo o que se sabe do caso, em Markdown. */
function case_markdown(int $id): string
{
    $c = find_case($id);
    $fields = case_fields($id, true);
    $ctx = (array) case_data($c, 'contexto_ia', []);
    $cap = case_data($c, 'capacidade');
    $o = [];
    $o[] = '# Atendimento ' . $c['protocol'] . ' — ' . $c['client_name'];
    $o[] = '';
    $o[] = '- **Veículo:** ' . client_case_title($c);
    $o[] = '- **' . ucfirst(client_case_meta($c)) . '**';
    $o[] = '- **Situação:** ' . initial_status_label($c, false);
    $o[] = '- **Andamento da coleta:** ' . agent_progress_pct($id) . '%';
    if ($c['urgency_signal']) {
        $o[] = '- **Urgência:** ' . (URGENCY_SIGNALS[$c['urgency_signal']] ?? $c['urgency_signal']) . ($c['urgency_reason'] ? ' — ' . $c['urgency_reason'] : '');
    }
    if (is_array($cap) && !empty($cap['nivel'])) {
        $o[] = '- **Capacidade de contratação (interno):** ' . $cap['nivel'] . (!empty($cap['obs']) ? ' — ' . $cap['obs'] : '');
    }
    $o[] = '- **Atualizado em:** ' . fmt_datetime(now_str());
    $o[] = '';
    $o[] = '## Contexto do caso';
    $o[] = '';
    $o[] = !empty($ctx['contexto']) ? $ctx['contexto'] : str_replace("\n", "\n\n", case_story($id));
    if (!empty($ctx['necessidades'])) {
        $o[] = '';
        $o[] = '## Principais necessidades';
        $o[] = '';
        foreach ($ctx['necessidades'] as $n) {
            $o[] = '- ' . $n;
        }
    }
    $rel = (array) ($ctx['relevante'] ?? []);
    foreach ((array) case_data($c, 'doc_notas', []) as $n) {
        foreach ((array) ($n['divergencias'] ?? []) as $dv) {
            $rel[] = 'Divergência na leitura de ' . $n['label'] . ': ' . $dv;
        }
    }
    if ($rel) {
        $o[] = '';
        $o[] = '## Pontos relevantes para o processo';
        $o[] = '';
        foreach (array_unique($rel) as $n) {
            $o[] = '- ' . $n;
        }
    }
    $o[] = '';
    $o[] = '## Dados conhecidos';
    $groups = [];
    foreach ($fields as $k => $r) {
        $def = field_def($k);
        if (!$def || in_array($k, ['resumo_confirmado', 'resumo_correcao'], true)) {
            continue;
        }
        $v = field_display($k, $r);
        if ($v === '') {
            continue;
        }
        $src = match ($r['source'] ?? '') { 'ia' => 'documento', 'cliente' => 'cliente', default => 'Sidney' };
        $groups[$def['g'] ?? 'outros'][] = '| ' . md_esc($def['label']) . ' | ' . md_esc(str_replace("\n", ' ', $v)) . ' | ' . $src . ' |';
    }
    $gname = ['abertura' => 'Abertura', 'urgencia' => 'Urgência', 'situacao' => 'Situação', 'qualificacao' => 'Qualificação', 'endereco' => 'Endereço', 'relacao' => 'Relação com o contrato',
        'financiamento' => 'Financiamento', 'veiculo' => 'Veículo', 'pagamentos' => 'Pagamentos', 'contexto' => 'Contexto', 'gratuidade' => 'Justiça gratuita', 'reuniao' => 'Reunião', 'analise' => 'Análise'];
    foreach ($groups as $g => $rows) {
        $o[] = '';
        $o[] = '### ' . ($gname[$g] ?? ucfirst($g));
        $o[] = '';
        $o[] = '| Dado | Valor | Fonte |';
        $o[] = '| --- | --- | --- |';
        array_push($o, ...$rows);
    }
    $missing = agent_missing_fields($id);
    if ($missing) {
        $o[] = '';
        $o[] = '### Ainda não informados';
        $o[] = '';
        $o[] = implode(', ', array_map(fn($k) => field_def($k)['label'], $missing)) . '.';
    }
    $o[] = '';
    $o[] = '## Documentos';
    $o[] = '';
    $notas = (array) case_data($c, 'doc_notas', []);
    foreach (docs_client_list($id) as $x) {
        $d = $x['doc'];
        $files = all('SELECT original_name FROM files WHERE document_id = ? AND replaced_at IS NULL ORDER BY id', [$d['id']]);
        $o[] = '- **' . $d['label'] . ':** ' . ($x['st'] === 'ok' ? 'recebido' : 'falta')
            . ($files ? ' (' . implode(', ', array_column($files, 'original_name')) . ')' : '')
            . (!empty($notas[$d['kind']]['obs']) ? ' — leitura: ' . $notas[$d['kind']]['obs'] : '');
    }
    $m = current_meeting($id);
    $o[] = '';
    $o[] = '## Reunião com Sidney';
    $o[] = '';
    $o[] = $m ? fmt_datetime($m['starts_at']) . ' · ' . MEETING_CHANNELS[$m['channel']]['short'] . ($m['url'] ? ' · ' . $m['url'] : '') . ' · ' . (int) $m['duration_min'] . ' min' : 'Ainda não marcada.';
    $o[] = '';
    $o[] = '## Conversa';
    $o[] = '';
    $who = ['bot' => ASSISTANT_NAME, 'client' => 'Cliente', 'operator' => 'Sidney'];
    foreach (all('SELECT author, body, created_at FROM chat_messages WHERE case_id = ? ORDER BY id', [$id]) as $msg) {
        $o[] = '**' . ($who[$msg['author']] ?? $msg['author']) . '** (' . fmt_datetime($msg['created_at']) . '): ' . str_replace("\n", "  \n", trim((string) $msg['body']));
        $o[] = '';
    }
    return implode("\n", $o) . "\n";
}
