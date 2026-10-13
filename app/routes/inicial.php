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
        redirect('cliente?area=conversa');
    }
    $acc = portal_account_id();
    if ($acc) {
        // Quem já é cliente continua pelas conversas; de lá abre um novo atendimento.
        redirect(account_cases($acc) ? 'cliente/conversas' : 'cliente/sem-acesso');
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

/** Entrada sem formulário: a primeira mensagem no chat abre o atendimento. */
function intake_chat_post(): never
{
    $existing = portal_case();
    if ($existing && is_initial($existing) && $existing['initial_status'] !== 'completed') {
        redirect('cliente?area=conversa');
    }
    $msg = trim(mb_substr(str_replace("\r\n", "\n", (string) input('mensagem')), 0, 2000));
    if ($msg === '') {
        $msg = trim(mb_substr((string) input('atalho'), 0, 200));
    }
    $errors = [];
    if (mb_strlen($msg) < 2) {
        $errors['mensagem'] = 'Escreva em poucas palavras o que está acontecendo, ou toque em uma das opções.';
    }
    $bot = input('website') !== '' || (time() - (int) ($_SESSION['triage_started'] ?? 0)) < 2;
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
        $_SESSION['portal_case'] = $done;
        redirect('cliente?area=conversa');
    }
    record_attempt('triage:' . client_ip());
    @set_time_limit(180);
    $acc = portal_account_id();
    $aviso = (string) input('aviso');
    $id = start_chat_case($msg, (string) input('origin'), $aviso, $acc);
    if (!$acc) {
        login_portal($id);
    }
    $_SESSION['portal_case'] = $id;
    if ($key !== '') {
        $_SESSION['intake_done_keys'][$key] = $id;
    }
    unset($_SESSION['intake_form_key']);
    redirect('cliente?area=conversa');
}

function intake_start_post(): never
{
    if (!isset($_POST['nome'])) {
        intake_chat_post();
    }
    // Entrada antiga com nome, e-mail e telefone (ainda aceita: registros do painel e testes).
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
        redirect('cliente?area=conversa');
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
        $_SESSION['portal_case'] = $done;
        redirect('cliente?area=conversa');
    }
    record_attempt('triage:' . client_ip());
    $acc = portal_account_id();
    $id = start_client_case($a, $acc, '');
    if (!$acc) {
        // Toda pessoa ganha uma conta (sem senha, por enquanto): é ela que reúne
        // os atendimentos como conversas e recebe os avisos por e-mail.
        login_portal($id);
    }
    $_SESSION['portal_case'] = $id;
    if ($key !== '') {
        $_SESSION['intake_done_keys'][$key] = $id;
    }
    unset($_SESSION['intake_form_key']);
    redirect('cliente?area=conversa');
}

/* ---------- Área do cliente: atendimento inicial ---------- */

function portal_initial_view(array $c, array $extra = []): never
{
    $id = (int) $c['id'];
    agent_resume($c);
    $area = (string) input('area', 'conversa');
    if (!in_array($area, ['inicio', 'conversa', 'documentos', 'reuniao'], true)) {
        $area = 'conversa';
    }
    if ($area === 'conversa') {
        mark_case_seen($id);
    }
    // Reunião pelo Meet ainda sem link (o Google falhou na hora): tenta de novo antes de mostrar.
    if ($area === 'reuniao' && ($m = current_meeting($id)) && $m['channel'] === 'meet' && !$m['url'] && google_connected()) {
        meeting_google_ensure($m);
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
        'has_password' => portal_has_password(),
        'convs' => client_conversations($c),
        'form_key' => bin2hex(random_bytes(10)),
    ], 'portal');
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
    $choice = (string) input('choice');
    if ($choice === 'desfazer') {
        // Desmarcou "vou procurar": o documento volta a faltar.
        $d = one('SELECT * FROM documents WHERE id = ? AND case_id = ?', [(int) $id, $c['id']]);
        if ($d) {
            $later = (array) case_data($c, 'doc_later', []);
            unset($later[$d['kind']]);
            set_case_data((int) $c['id'], 'doc_later', $later);
            if ($d['status'] === 'nao_possui') {
                q("UPDATE documents SET status = 'falta' WHERE id = ?", [$d['id']]);
            }
        }
        $err = null;
    } else {
        $err = bot_doc_answer($c, (int) $id, $choice, uploaded_files('arquivo'), (string) input('k'));
    }
    $back = (string) input('volta') === 'documentos' ? 'documentos' : 'conversa';
    if (!$err && $choice === 'enviar') {
        case_sync_later((int) $c['id']); // contexto do caso e Google Drive, depois da resposta
    }
    $lido = !$err && $choice === 'enviar' ? (string) scalar("SELECT body FROM chat_messages WHERE case_id = ? AND author = 'bot' ORDER BY id DESC LIMIT 1", [$c['id']]) : '';
    flash($err ? 'error' : 'ok', $err ?: (str_starts_with($lido, 'Li o documento') ? 'Recebido. ' . str_replace("\n", ' ', $lido) : 'Recebido.'));
    portal_back($back, $back === 'conversa' ? 'pergunta' : 'docs');
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
            // Pedido para falar com Sidney: nos dois primeiros, a IA pede o que falta; no terceiro, a agenda marca a conversa.
            $ask = human_ask_handle(find_case($id), $text);
            if (!empty($ask['reply'])) {
                $ajax ? json_out(chat_ajax_payload(find_case($id), $after)) : portal_back('conversa', 'fim');
            }
            $event[] = ($event ? 'Junto, a pessoa escreveu: ' : 'Mensagem nova do cliente: ') . mb_substr($text, 0, 2000);
            if ($ask) {
                $event[] = $ask['instruction'];
            }
        }
        if ($event) {
            agent_turn(find_case($id), implode("\n", $event));
        }
        if ($files || $audios) {
            case_sync_later($id);
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
            $ask = human_ask_handle(find_case($id), $text);
            if (!$ask) {
                chat_post($id, 'bot', ia_reply(find_case($id), $text) ?? BOT_OFFSCRIPT);
            } elseif (empty($ask['reply'])) {
                chat_post($id, 'bot', human_ask_reply($id, (int) $ask['n']));
            }
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

// Resumo do caso (pop-up): o cliente corrige o que precisar e envia para Sidney.
$router->post('/cliente/conversa/resumo', function () {
    $c = require_portal();
    $id = (int) $c['id'];
    $errors = case_summary_save($id, (array) ($_POST['f'] ?? []));
    if ($errors) {
        flash('error', 'Confira: ' . implode(' · ', $errors));
        portal_back('conversa', 'resumo');
    }
    if (input('op') !== 'enviar') {
        flash('ok', 'Correções salvas. Confira o resumo e envie para Sidney.');
        portal_back('conversa', 'resumo');
    }
    case_sync_later($id);
    if (agent_finish($id)) {
        chat_post($id, 'client', 'Conferi o resumo: está tudo certo, pode enviar para Sidney.');
    } elseif (!find_case($id)['intake_done_at']) {
        flash('error', 'Ainda falta alguma informação ou documento. Veja a lista acima da conversa.');
        portal_back('conversa', 'fim');
    }
    // Próxima etapa: documentos (anexa o que tiver) e, de lá, o agendamento com Sidney.
    flash('ok', 'Resumo enviado para Sidney. Anexe os documentos que tiver e siga para o agendamento.');
    portal_back('documentos', 'docs');
});

// O cliente escolhe uma das duas opções de horário; confere de novo se continua livre.
$router->post('/cliente/reuniao/marcar', function () {
    $c = require_portal();
    $id = (int) $c['id'];
    $t = dt(str_replace('T', ' ', (string) input('horario')));
    if (current_meeting($id)) {
        portal_back('reuniao', 'h-reu');
    }
    if (!meeting_ready($id)) {
        flash('error', 'Antes de marcar, confira o resumo e os documentos.');
        portal_back('documentos', 'docs');
    }
    if (!$t || !agenda_slot_ok($t)) {
        flash('error', 'Esse horário acabou de ser ocupado. Veja as novas opções abaixo.');
        portal_back('reuniao', 'h-reu');
    }
    $m = agenda_book(find_case($id), 'coleta', $t);
    case_sync_later($id, false);
    flash($m ? 'ok' : 'error', $m ? 'Conversa confirmada para ' . fmt_when(dt($m['starts_at'])) . '.' : 'Não foi possível marcar agora. Sidney vai entrar em contato para combinar.');
    portal_back('reuniao', 'h-reu');
});

// Endereço antigo (opção "perguntas com botões" removida): só volta para a conversa.
$router->post('/cliente/conversa/modo', function () {
    $c = require_portal();
    if (case_data($c, 'agent_off')) {
        set_case_data((int) $c['id'], 'agent_off', null);
    }
    portal_back('conversa', 'fim');
});

/** Resposta do envio sem recarregar: mensagens novas, atalhos e o que falta. */
function chat_ajax_payload(array $c, int $after): array
{
    $msgs = all('SELECT * FROM chat_messages WHERE case_id = ? AND id > ? ORDER BY id', [$c['id'], $after]);
    if ($msgs) {
        mark_case_seen((int) $c['id'], (int) end($msgs)['id']);
    }
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
    if ($msgs && input('visivel') !== '0') {
        mark_case_seen((int) $c['id'], (int) end($msgs)['id']);
    }
    $badges = [];
    foreach (client_conversations($c) as $cv) {
        $badges[$cv['id']] = $cv['current'] && input('visivel') !== '0' ? 0 : $cv['unread'];
    }
    json_out([
        'html' => render('partials/chat_msgs', ['msgs' => $msgs, 'operator' => false, 'c' => $c]),
        'last' => $msgs ? (int) end($msgs)['id'] : $after,
        'mode' => $c['chat_mode'],
        'badges' => $badges,
    ]);
});

$router->post('/cliente/criar-senha', function () {
    $acc = portal_account_id();
    $c = portal_case();
    $back = (string) input('volta') === 'conversas' ? 'cliente/conversas' : 'cliente?area=inicio';
    if (!$acc && !$c) {
        redirect('cliente/entrar');
    }
    if (portal_has_password()) {
        redirect($back);
    }
    $pass = (string) ($_POST['password'] ?? '');
    if (mb_strlen($pass) < 8 || $pass !== (string) ($_POST['password2'] ?? '')) {
        flash('error', 'Use pelo menos 8 caracteres e repita a mesma senha.');
        redirect($back);
    }
    $hash = password_hash($pass, PASSWORD_DEFAULT);
    if ($acc) {
        q('UPDATE client_accounts SET pass_hash = ? WHERE id = ?', [$hash, $acc]);
    } else {
        login_portal((int) $c['id'], $hash);
    }
    if ($c) {
        add_event((int) $c['id'], 'portal', 'Cliente criou a senha da área do cliente', null, false, 'cliente');
    }
    flash('ok', 'Senha criada. Para voltar, use o protocolo de qualquer atendimento' . ($c ? ' (este é ' . $c['protocol'] . ')' : '') . ' e esta senha.');
    redirect($back);
});

/** Contadores de novidades da lista de conversas (consulta periódica). */
$router->get('/cliente/conversas/novidades', function () {
    if (!portal_account_id() && !portal_case()) {
        json_out(['error' => 'acesso'], 403);
    }
    $badges = [];
    foreach (client_conversations(portal_case()) as $cv) {
        $badges[$cv['id']] = $cv['unread'];
    }
    json_out(['badges' => $badges]);
});

$router->get('/cliente/trocar/{id}', function (string $id) {
    $acc = portal_account_id() ?? forbidden();
    $c = one('SELECT * FROM cases WHERE id = ? AND account_id = ?', [(int) $id, $acc]);
    if (!$c || !client_case_authorized($c)) {
        forbidden();
    }
    $_SESSION['portal_case'] = (int) $c['id'];
    redirect('cliente?area=conversa');
});

/* ---------- Conversas: lista, novo atendimento, avisos e acesso por e-mail ---------- */

/** Lista de atendimentos (no celular, a tela das conversas; no computador, a coluna da esquerda). */
$router->get('/cliente/conversas', function () {
    $acc = portal_account_id();
    $c = portal_case();
    if (!$acc && !$c) {
        redirect('cliente/entrar');
    }
    if ($acc && !account_cases($acc)) {
        redirect('cliente/sem-acesso');
    }
    view('portal/conversas', [
        'meta_title' => 'Seus atendimentos · Área do cliente',
        'noindex' => true,
        'convs' => client_conversations($c),
        'list_only' => true,
        'has_password' => portal_has_password(),
        'notify' => portal_notify_on(),
    ], 'portal');
});

$router->post('/cliente/novo', function () {
    $acc = portal_account_id();
    if (!$acc) {
        $c = portal_case();
        if (!$c) {
            redirect('cliente/entrar');
        }
        login_portal((int) $c['id']);
        $acc = portal_account_id();
    }
    $r = open_client_case($acc);
    if (is_string($r)) {
        flash('error', $r);
        redirect('cliente/conversas');
    }
    $_SESSION['portal_case'] = $r;
    redirect('cliente?area=conversa');
});

$router->post('/cliente/avisos', function () {
    $acc = portal_account_id() ?? redirect('cliente/entrar');
    $on = input('avisos') === '1';
    q('UPDATE client_accounts SET notify_email = ? WHERE id = ?', [$on ? 1 : 0, $acc]);
    flash('ok', $on ? 'Pronto: avisamos por e-mail quando houver novidade.' : 'Avisos por e-mail desligados. As novidades continuam marcadas aqui.');
    redirect('cliente/conversas');
});

/** Link recebido por e-mail: entra na conta e abre o atendimento. */
$router->get('/cliente/acesso/{token}', function (string $token) {
    $bucket = 'acesso:' . client_ip();
    if (too_many_attempts($bucket, 12, 15)) {
        flash('error', 'Muitas tentativas seguidas. Aguarde 15 minutos.');
        redirect('cliente/entrar');
    }
    $r = client_access_consume($token);
    if (!$r) {
        record_attempt($bucket);
        flash('error', 'Este link venceu ou não é válido. Peça um novo abaixo, com o seu e-mail.');
        redirect('cliente/entrar');
    }
    [$acc, $caseId] = $r;
    $c = $caseId ? one('SELECT * FROM cases WHERE id = ? AND account_id = ?', [$caseId, $acc]) : null;
    login_account($acc, $c && !case_access_expired($c) ? (int) $c['id'] : null);
    redirect(portal_case() ? 'cliente?area=conversa' : 'cliente/conversas');
});

$router->post('/cliente/link', function () {
    $email = trim((string) input('email'));
    $bucket = 'link:' . client_ip();
    if (too_many_attempts($bucket, 5, 60)) {
        flash('error', 'Muitos pedidos seguidos. Aguarde uma hora ou fale com Sidney pelo WhatsApp.');
        redirect('cliente/entrar');
    }
    record_attempt($bucket);
    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        send_access_links($email);
    }
    // Mesma resposta exista ou não a conta: o formulário não revela quem é cliente.
    flash('ok', 'Se este e-mail estiver em algum atendimento, enviamos agora um link de acesso. Confira também a caixa de spam.');
    redirect('cliente/entrar');
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
    $label = kit_label($v['kind']);
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
    $label = kit_label($v['kind']);
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
