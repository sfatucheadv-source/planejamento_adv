<?php
declare(strict_types=1);

/**
 * Atendimento inicial — entrada pública pelo assistente (seção 5) e área do
 * cliente em quatro áreas (seção 12.1). Todas as rotas revalidam o vínculo
 * com o atendimento e a carência de acesso no servidor.
 */

/** @var Router $router */

/* ---------- Entrada pública ---------- */

function intake_start_page(array $errors = [], array $old = []): never
{
    $c = portal_case();
    if ($c && is_initial($c) && $c['initial_status'] !== 'completed') {
        redirect('cliente?area=conversa#pergunta');
    }
    $acc = portal_account_id();
    if ($acc) {
        $active = array_filter(account_cases($acc), fn($x) => is_initial($x) && $x['initial_status'] !== 'completed');
        if ($active) {
            $_SESSION['portal_case'] = (int) reset($active)['id'];
            redirect('cliente?area=conversa#pergunta');
        }
        if (all('SELECT id FROM cases WHERE account_id = ?', [$acc])) {
            redirect('cliente/sem-acesso');
        }
    }
    $_SESSION['triage_started'] = time();
    $_SESSION['intake_form_key'] ??= bin2hex(random_bytes(12));
    $aviso = (string) ($old['aviso'] ?? input('aviso'));
    view('site/conversa_inicio', [
        'meta_title' => 'Conte seu caso · Sidney Fatuche Advocacia',
        'meta_desc'  => 'Conte seu caso pelo assistente. Você pode enviar o que tiver e continuar depois.',
        'errors' => $errors,
        'old' => $old + ['origin' => (string) input('origem'), 'aviso' => isset(NOTICE_OPTIONS[$aviso]) ? $aviso : ''],
        'form_key' => $_SESSION['intake_form_key'],
    ], 'site', $errors ? 422 : 200);
}

function intake_start_post(): never
{
    $a = [
        'nome' => trim(mb_substr((string) input('nome'), 0, 120)),
        'telefone' => (string) input('telefone'),
        'email' => trim((string) input('email')),
        'adaptacao' => trim(mb_substr((string) input('adaptacao'), 0, 300)),
        'origem' => (string) input('origin'),
        'aviso' => (string) input('aviso'),
    ];
    // Mesmo envio repetido (ou sessão já com atendimento ativo) não cria outro caso.
    $existing = portal_case();
    if ($existing && is_initial($existing) && $existing['initial_status'] !== 'completed') {
        redirect('cliente?area=conversa#pergunta');
    }
    $errors = [];
    if (mb_strlen($a['nome']) < 2) {
        $errors['nome'] = 'Como podemos te chamar?';
    }
    $digits = only_digits($a['telefone']);
    if (strlen($digits) < 10 || strlen($digits) > 13) {
        $errors['telefone'] = 'Informe um WhatsApp ou telefone com DDD.';
    }
    if ($a['email'] !== '' && !filter_var($a['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Confira o e-mail ou deixe em branco.';
    }
    if (input('consent') !== '1') {
        $errors['consent'] = 'Precisamos da sua autorização para usar esses dados no atendimento.';
    }
    $bot = input('website') !== '' || (time() - (int) ($_SESSION['triage_started'] ?? 0)) < 3;
    if (!$errors && !$bot && too_many_attempts('triage:' . client_ip(), 6, 60)) {
        $errors['form'] = 'Recebemos muitos envios deste aparelho em pouco tempo. Fale com Sidney pelo WhatsApp, por favor.';
    }
    if ($errors) {
        intake_start_page($errors, $_POST);
    }
    if ($bot) {
        redirect('comecar');
    }
    $key = (string) input('form_key');
    if ($key !== '' && ($done = $_SESSION['intake_done_keys'][$key] ?? null)) {
        $_SESSION['intake_case'] = $done;
        redirect('cliente?area=conversa#pergunta');
    }
    record_attempt('triage:' . client_ip());
    $acc = portal_account_id();
    $c = create_initial_case($a, 'site', $acc);
    $id = (int) $c['id'];
    if ($key !== '') {
        $_SESSION['intake_done_keys'][$key] = $id;
    }
    unset($_SESSION['intake_form_key']);
    chat_post($id, 'bot', BOT_GREETING);
    chat_post($id, 'client', 'Sou ' . $a['nome'] . '. Meu contato é ' . format_phone($digits) . ($a['email'] ? ' e ' . mb_strtolower($a['email']) : '') . '.');
    if (isset(NOTICE_OPTIONS[$a['aviso']])) {
        // Reaproveita a seleção feita na página inicial (B02).
        set_field($id, 'aviso', $a['aviso']);
        update_urgency_signal($id);
    }
    sync_intake_documents($id);
    session_regenerate_id(true);
    $_SESSION['intake_case'] = $id;
    if ($acc) {
        $_SESSION['portal_case'] = $id;
    }
    redirect('cliente?area=conversa#pergunta');
}

/* ---------- Área do cliente: atendimento inicial ---------- */

function portal_initial_view(array $c, array $extra = []): never
{
    $id = (int) $c['id'];
    $area = (string) input('area', 'inicio');
    if (!in_array($area, ['inicio', 'conversa', 'documentos', 'reuniao'], true)) {
        $area = 'inicio';
    }
    $published = all("SELECT * FROM case_artifact_versions WHERE case_id = ? AND status = 'publicado' ORDER BY kind, version DESC", [$id]);
    $latestPub = [];
    foreach ($published as $v) {
        $latestPub[$v['kind']] ??= $v;
    }
    view('portal/inicial', $extra + [
        'meta_title' => 'Seu atendimento · Área do cliente',
        'noindex' => true,
        'c' => $c,
        'area' => $area,
        'q' => bot_current($c),
        'chat' => all('SELECT * FROM chat_messages WHERE case_id = ? ORDER BY id', [$id]),
        'docs' => all("SELECT * FROM documents WHERE case_id = ? ORDER BY CASE WHEN status IN ('falta','ajuda','pendencia','nao_possui') THEN 0 ELSE 1 END, essential DESC, id", [$id]),
        'files' => all('SELECT * FROM files WHERE case_id = ? AND internal = 0 AND replaced_at IS NULL ORDER BY uploaded_at DESC', [$id]),
        'pendings' => open_pendings($id, true),
        'meeting' => current_meeting($id),
        'held' => held_meeting($id),
        'kitPub' => $latestPub,
        'fields' => case_fields($id),
        'has_account' => (bool) $c['account_id'] && portal_account_id(),
        'others' => portal_account_id() ? array_values(array_filter(account_cases(portal_account_id()), fn($o) => (int) $o['id'] !== $id)) : [],
        'form_key' => bin2hex(random_bytes(10)),
    ]);
}

function portal_back(string $area, string $anchor = ''): never
{
    redirect('cliente?area=' . $area . ($anchor ? '#' . $anchor : ''));
}

$router->post('/cliente/conversa/responder', function () {
    $c = require_portal();
    $err = bot_answer($c, (string) input('q'), $_POST, (string) input('k'));
    if ($err) {
        flash('error', $err);
        $_SESSION['bot_old'] = ['q' => (string) input('q'), 'value' => (string) input('value'), 'post' => array_intersect_key($_POST, array_flip(['cep', 'logradouro', 'numero', 'complemento', 'bairro', 'cidade', 'uf', 'correction']))];
    } else {
        flash('ok', 'Resposta salva.');
    }
    portal_back('conversa', 'pergunta');
});

$router->post('/cliente/conversa/documento/{id}', function (string $id) {
    $c = require_portal();
    $err = bot_doc_answer($c, (int) $id, (string) input('choice'), uploaded_files('arquivo'), (string) input('k'));
    flash($err ? 'error' : 'ok', $err ?: 'Salvo.');
    $back = (string) input('volta') === 'documentos' ? 'documentos' : 'conversa';
    portal_back($back, $back === 'conversa' ? 'pergunta' : 'doc-' . (int) $id);
});

$router->post('/cliente/conversa/corrigir', function () {
    $c = require_portal();
    $key = (string) input('q');
    $allowed = array_column(array_filter(bot_questions((int) $c['id']), fn($q) => $q['kind'] !== 'doc' && $q['kind'] !== 'final'), 'key');
    if ($c['chat_mode'] !== 'bot' || !in_array($key, $allowed, true)) {
        flash('error', 'Para corrigir esta informação, escreva para Sidney aqui no chat.');
    } else {
        bump_case((int) $c['id'], ['intake_edit' => $key]);
    }
    portal_back('conversa', 'pergunta');
});

$router->post('/cliente/conversa/mensagem', function () {
    $c = require_portal();
    $text = trim((string) input('message'));
    if ($text === '') {
        flash('error', 'Escreva a mensagem.');
        portal_back('conversa', 'mensagem');
    }
    $id = (int) $c['id'];
    $new = chat_post($id, 'client', $text, null, (string) input('k') ?: null);
    if ($new) {
        mark_client_activity($id);
        if ($c['chat_mode'] === 'bot') {
            chat_post($id, 'bot', ia_reply(find_case($id), $text) ?? BOT_OFFSCRIPT);
        }
    }
    if (input('ajax') === '1') {
        json_out(['ok' => true]);
    }
    portal_back('conversa', 'fim');
});

/** Consulta periódica do chat ativo (sem WebSocket). */
$router->get('/cliente/conversa/novas', function () {
    $c = portal_case();
    if (!$c) {
        json_out(['error' => 'acesso'], 403);
    }
    $after = (int) input('depois');
    $msgs = all('SELECT * FROM chat_messages WHERE case_id = ? AND id > ? ORDER BY id', [$c['id'], $after]);
    json_out([
        'html' => render('partials/chat_msgs', ['msgs' => $msgs, 'operator' => false, 'c' => $c]),
        'last' => $msgs ? (int) end($msgs)['id'] : $after,
        'mode' => $c['chat_mode'],
    ]);
});

$router->post('/cliente/criar-senha', function () {
    $c = require_portal();
    $pass = (string) ($_POST['password'] ?? '');
    if ($c['account_id'] && portal_account_id()) {
        redirect('cliente');
    }
    if (mb_strlen($pass) < 8 || $pass !== (string) ($_POST['password2'] ?? '')) {
        flash('error', 'Use pelo menos 8 caracteres e repita a mesma senha.');
        portal_back('inicio', 'senha');
    }
    add_event((int) $c['id'], 'portal', 'Cliente criou a senha da área do cliente', null, false, 'cliente');
    login_portal((int) $c['id'], password_hash($pass, PASSWORD_DEFAULT));
    flash('ok', 'Senha criada. Para voltar, use o protocolo ' . $c['protocol'] . ' e esta senha.');
    portal_back('inicio');
});

$router->get('/cliente/trocar/{id}', function (string $id) {
    $acc = portal_account_id() ?? forbidden();
    $c = one('SELECT * FROM cases WHERE id = ? AND account_id = ?', [(int) $id, $acc]);
    if (!$c || !client_case_authorized($c)) {
        forbidden();
    }
    $_SESSION['portal_case'] = (int) $c['id'];
    redirect('cliente');
});

/** Documento do kit publicado (texto selecionável, imprimível). */
$router->get('/cliente/documento-kit/{id}', function (string $id) {
    $c = require_portal();
    $v = one("SELECT * FROM case_artifact_versions WHERE id = ? AND case_id = ? AND status = 'publicado'", [(int) $id, $c['id']]) ?? forbidden();
    if (input('baixar') === '1') {
        kit_download($c, $v);
    }
    view('panel/kit_doc', ['c' => $c, 'v' => $v, 'meta_title' => KIT_ITEMS[$v['kind']]['label'], 'client' => true], 'print');
});

function kit_download(array $c, array $v): never
{
    $name = preg_replace('/[^a-z0-9]+/', '-', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', KIT_ITEMS[$v['kind']]['label']) ?: $v['kind'])) . '-' . $c['protocol'] . '-v' . $v['version'] . '.html';
    header('Content-Type: text/html; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Cache-Control: private, no-store');
    echo '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><title>' . e(KIT_ITEMS[$v['kind']]['label']) . '</title>'
        . '<style>body{font:12pt/1.5 Georgia,serif;max-width:17cm;margin:2cm auto}p{margin:0 0 .6em;white-space:pre-wrap}</style></head><body>'
        . kit_html((string) $v['content']) . '<p style="color:#666;font-size:9pt">Versão ' . (int) $v['version'] . ' · ' . e($c['protocol']) . '</p></body></html>';
    exit;
}

/** Conteúdo do kit como HTML seguro: texto escapado, parágrafos por linha em branco. */
function kit_html(string $content): string
{
    $out = '';
    foreach (preg_split("/\n{2,}/", trim($content)) as $p) {
        $p = e($p);
        $p = preg_replace('/\[\[([^\]]*)\]\]/u', '<mark>[[$1]]</mark>', $p);
        $out .= '<p>' . nl2br($p) . '</p>';
    }
    return $out;
}

/* ---------- Sem atendimento autorizado / novo atendimento (seção 13.2) ---------- */

$router->get('/cliente/sem-acesso', function () {
    $acc = portal_account_id();
    if (!$acc) {
        redirect('cliente/entrar');
    }
    if (account_cases($acc)) {
        redirect('cliente');
    }
    $last = one('SELECT id, return_requested_at FROM cases WHERE account_id = ? ORDER BY id DESC LIMIT 1', [$acc]);
    view('portal/sem_acesso', ['meta_title' => 'Área do cliente', 'noindex' => true, 'requested' => $last['return_requested_at'] ?? null]);
});

$router->post('/cliente/novo-atendimento', function () {
    $acc = portal_account_id() ?? redirect('cliente/entrar');
    if (account_cases($acc)) {
        redirect('cliente');
    }
    $last = one('SELECT id FROM cases WHERE account_id = ? ORDER BY id DESC LIMIT 1', [$acc]);
    if ($last) {
        $note = trim(mb_substr((string) input('message'), 0, 1000));
        q('UPDATE cases SET return_requested_at = ?, updated_at = ? WHERE id = ?', [now_str(), now_str(), $last['id']]);
        add_event((int) $last['id'], 'acesso', 'Cliente pediu novo atendimento', $note ?: null, false, 'cliente');
    }
    flash('ok', 'Pedido enviado. Sidney vai verificar e abrir um novo atendimento para você.');
    redirect('cliente/sem-acesso');
});
