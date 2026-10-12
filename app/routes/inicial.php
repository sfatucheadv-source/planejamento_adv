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
    $a['nome'] = preg_replace('/\s+/u', ' ', $a['nome']);
    if (mb_strlen($a['nome']) < 5 || !str_contains($a['nome'], ' ')) {
        $errors['nome'] = 'Informe seu nome completo (nome e sobrenome).';
    }
    $digits = only_digits($a['telefone']);
    if (strlen($digits) < 10 || strlen($digits) > 13) {
        $errors['telefone'] = 'Informe um WhatsApp ou telefone com DDD.';
    }
    if (!filter_var($a['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Informe um e-mail válido: é por ele que enviamos os avisos do atendimento.';
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
    chat_post($id, 'bot', bot_greeting());
    chat_post($id, 'client', 'Sou ' . $a['nome'] . '. Meu contato é ' . format_phone($digits) . ' e ' . mb_strtolower($a['email']) . '.');
    set_field($id, 'nome_completo', $a['nome']);
    if (isset(NOTICE_OPTIONS[$a['aviso']])) {
        // Reaproveita a seleção feita na página inicial (B02).
        set_field($id, 'aviso', $a['aviso']);
        update_urgency_signal($id);
    }
    sync_intake_documents($id);
    if (agent_enabled()) {
        // A IA conduz: cumprimenta pelo nome e faz a primeira pergunta.
        set_case_data($id, 'agent_started', now_str());
        agent_turn(find_case($id), 'Início da conversa: a pessoa acabou de se identificar no site (nome completo, e-mail e telefone já registrados). Cumprimente pelo primeiro nome, diga em uma frase como funciona (ela pode escrever, mandar áudio, fotos ou PDFs) e faça a primeira pergunta.');
    }
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
    agent_resume($c);
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
    $id = (int) $c['id'];
    $ajax = input('ajax') === '1';
    $after = (int) input('depois');
    $text = trim((string) input('message'));
    $agent = agent_active($c);
    // Áudio gravado no navegador ou arquivo de áudio anexado; o resto são documentos.
    $audios = $agent ? uploaded_files('audio') : [];
    $files = [];
    foreach (uploaded_files('arquivo') as $f) {
        if ($agent && isset(ALLOWED_AUDIO_MIME[upload_mime($f)])) {
            $audios[] = $f;
        } else {
            $files[] = $f;
        }
    }
    $fail = function (string $msg, string $anchor = 'fim') use ($ajax) {
        if ($ajax) {
            json_out(['ok' => false, 'error' => $msg], 422);
        }
        flash('error', $msg);
        portal_back('conversa', $anchor);
    };
    if ($text === '' && !($agent && ($files || $audios))) {
        $fail('Escreva a mensagem, grave um áudio ou anexe um arquivo.', 'mensagem');
    }
    $key = (string) input('k');
    if ($key !== '' && scalar('SELECT 1 FROM chat_messages WHERE case_id = ? AND client_key IN (?, ?)', [$id, $key, $key . '-msg'])) {
        $ajax ? json_out(chat_ajax_payload(find_case($id), $after)) : portal_back('conversa', 'fim'); // retentativa: já gravado
    }
    if ($agent) {
        @set_time_limit(300);
        $event = [];
        foreach ($audios as $i => $f) {
            [$desc, $err] = agent_receive_audio($c, $f, $key !== '' ? $key . '-a' . $i : '');
            if ($err) {
                $fail($err);
            }
            if ($desc) {
                $event[] = $desc;
            }
        }
        if ($files) {
            [$desc, $err] = agent_receive_files($c, $files, (string) input('doc'), $key);
            if ($err) {
                $fail($err);
            }
            $event[] = $desc;
        }
        if ($text !== '') {
            chat_post($id, 'client', $text, null, ($key !== '' ? $key . '-msg' : null));
            mark_client_activity($id);
            $event[] = ($event ? 'Junto, a pessoa escreveu: ' : 'Mensagem nova do cliente: ') . mb_substr($text, 0, 2000);
        }
        if ($event) {
            agent_turn(find_case($id), implode("\n", $event));
        }
        if ($ajax) {
            json_out(chat_ajax_payload(find_case($id), $after));
        }
        portal_back('conversa', 'fim');
    }
    $new = chat_post($id, 'client', $text, null, $key ?: null);
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

// Com o mínimo reunido, o cliente pode encerrar a coleta sem esperar a IA.
$router->post('/cliente/conversa/concluir', function () {
    $c = require_portal();
    if (agent_active($c) && agent_finish((int) $c['id'])) {
        chat_post((int) $c['id'], 'client', 'Está tudo certo, pode enviar para Sidney.');
        flash('ok', 'Enviado para Sidney.');
    } else {
        flash('error', 'Ainda falta alguma informação ou documento. Veja a lista acima da conversa.');
    }
    portal_back('conversa', 'fim');
});

// Alterna entre a conversa com a IA e as perguntas com botões.
$router->post('/cliente/conversa/modo', function () {
    $c = require_portal();
    $id = (int) $c['id'];
    if (input('modo') === 'botoes') {
        set_case_data($id, 'agent_off', 'cliente');
        add_event($id, 'ia', 'Cliente preferiu responder pelas perguntas com botões');
    } elseif (agent_enabled()) {
        set_case_data($id, 'agent_off', null);
        set_case_data($id, 'agent_started', now_str());
        chat_post($id, 'bot', 'Certo, seguimos conversando por aqui. Pode escrever com suas palavras ou mandar fotos e PDFs.');
    }
    portal_back('conversa', 'fim');
});

/** Resposta do envio sem recarregar: mensagens novas, atalhos e o que falta. */
function chat_ajax_payload(array $c, int $after): array
{
    $msgs = all('SELECT * FROM chat_messages WHERE case_id = ? AND id > ? ORDER BY id', [$c['id'], $after]);
    return [
        'ok' => true,
        'html' => render('partials/chat_msgs', ['msgs' => $msgs, 'operator' => false, 'c' => $c]),
        'last' => $msgs ? (int) end($msgs)['id'] : $after,
        'bar' => agent_active($c) ? render('partials/cv_agent_bar', ['c' => $c]) : null,
        'reload' => !agent_active($c),
    ];
}

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
    if (input('baixar') !== '') {
        kit_download($c, $v);
    }
    kit_page($c, $v, url('cliente/documento-kit/' . $v['id'], ['baixar' => 'docx']), true);
});

/** .docx do documento, no timbrado do escritório. */
function kit_download(array $c, array $v): never
{
    $label = KIT_ITEMS[$v['kind']]['label'];
    $bin = doc_docx(doc_blocks((string) $v['content']), ['titulo' => $label, 'assunto' => 'Atendimento ' . $c['protocol'] . ' · versão ' . $v['version'], 'autor' => office('lawyer') ?: 'Sidney Fatuche']);
    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: attachment; filename="' . doc_filename($label, (string) $c['protocol'], (int) $v['version']) . '.docx"');
    header('Content-Length: ' . strlen($bin));
    header('Cache-Control: private, no-store');
    echo $bin;
    exit;
}

/** Documento do kit diagramado: folhas A4 com o timbrado, prontas para imprimir ou salvar em PDF. */
function kit_page(array $c, array $v, string $docxUrl, bool $client): never
{
    $label = KIT_ITEMS[$v['kind']]['label'];
    header('Cache-Control: private, no-store');
    echo doc_page($label . ' · ' . $c['protocol'] . ' · v' . $v['version'], doc_blocks((string) $v['content']),
        ['Baixar .docx' => $docxUrl, 'Imprimir / PDF' => 'print'], !$client && $v['status'] === 'minuta' ? 'MINUTA' : null);
    exit;
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

/* ---------- Webhook do Asaas (situação das cobranças) ---------- */

$router->post('/webhook/asaas', function () {
    $payload = json_decode((string) file_get_contents('php://input'), true);
    $code = asaas_webhook((string) ($_SERVER['HTTP_ASAAS_ACCESS_TOKEN'] ?? ''), is_array($payload) ? $payload : []);
    json_out(['ok' => $code === 200], $code);
});
