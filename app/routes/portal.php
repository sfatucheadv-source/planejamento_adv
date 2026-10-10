<?php
declare(strict_types=1);

/** @var Router $router */

$router->get('/cliente', function () {
    $c = portal_case();
    if (!$c) {
        redirect('cliente/entrar');
    }
    $id = (int) $c['id'];
    view('portal/painel', [
        'meta_title' => 'Seu caso · Área do cliente',
        'noindex' => true,
        'c' => $c,
        'docs' => client_documents($id),
        'files' => all('SELECT * FROM files WHERE case_id = ? ORDER BY uploaded_at DESC', [$id]),
        'decisions' => all('SELECT * FROM decisions WHERE case_id = ? AND answer IS NULL ORDER BY requested_at', [$id]),
        'answered' => all('SELECT * FROM decisions WHERE case_id = ? AND answer IS NOT NULL ORDER BY answered_at DESC LIMIT 5', [$id]),
        'events' => all('SELECT * FROM events WHERE case_id = ? AND visible_to_client = 1 ORDER BY at DESC, id DESC LIMIT 30', [$id]),
        'schedule' => all("SELECT * FROM schedule WHERE case_id = ? ORDER BY due_date", [$id]),
        'feedback' => one("SELECT * FROM feedback WHERE case_id = ? AND kind = 'encerramento'", [$id]),
    ]);
});

$router->get('/cliente/entrar', function () {
    if (portal_case()) {
        redirect('cliente');
    }
    view('portal/entrar', ['meta_title' => 'Área do cliente · Sidney Fatuche Advocacia', 'noindex' => true]);
});

$router->post('/cliente/entrar', function () {
    $protocol = strtoupper(trim((string) input('protocol')));
    $bucket = 'portal:' . client_ip();
    if (too_many_attempts($bucket)) {
        flash('error', 'Muitas tentativas seguidas. Aguarde 15 minutos ou peça ajuda pelo WhatsApp.');
        redirect('cliente/entrar');
    }
    $c = one('SELECT * FROM cases WHERE protocol = ?', [$protocol]);
    if (!$c || !$c['portal_pass_hash'] || !password_verify((string) ($_POST['password'] ?? ''), $c['portal_pass_hash'])) {
        record_attempt($bucket);
        flash('error', 'Protocolo ou senha não conferem. Se ainda não criou senha, use o código enviado pela equipe.');
        redirect('cliente/entrar');
    }
    login_portal((int) $c['id']);
    redirect('cliente');
});

$router->post('/cliente/ativar', function () {
    $protocol = strtoupper(trim((string) input('protocol')));
    $code = trim((string) input('code'));
    $pass = (string) ($_POST['password'] ?? '');
    $bucket = 'portal:' . client_ip();
    if (too_many_attempts($bucket)) {
        flash('error', 'Muitas tentativas seguidas. Aguarde 15 minutos ou peça ajuda pelo WhatsApp.');
        redirect('cliente/entrar');
    }
    $c = one('SELECT * FROM cases WHERE protocol = ?', [$protocol]);
    $valid = $c && $c['portal_code_hash'] && dt($c['portal_code_expires']) > now() && password_verify($code, $c['portal_code_hash']);
    if (!$valid) {
        record_attempt($bucket);
        flash('error', 'Código inválido ou vencido. Peça um novo código à equipe.');
        redirect('cliente/entrar');
    }
    if (mb_strlen($pass) < 8) {
        flash('error', 'Use pelo menos 8 caracteres na senha.');
        redirect('cliente/entrar');
    }
    q('UPDATE cases SET portal_pass_hash = ?, portal_code_hash = NULL, portal_code_expires = NULL WHERE id = ?', [password_hash($pass, PASSWORD_DEFAULT), $c['id']]);
    add_event((int) $c['id'], 'portal', 'Cliente ativou o acesso à área do cliente', null, false, 'cliente');
    login_portal((int) $c['id']);
    flash('ok', 'Acesso criado. Bem-vindo à sua área.');
    redirect('cliente');
});

$router->get('/cliente/sair', function () {
    unset($_SESSION['portal_case']);
    session_regenerate_id(true);
    redirect('cliente/entrar');
});

$router->post('/cliente/documento/{id}', function (string $id) {
    $c = require_portal();
    $doc = one('SELECT * FROM documents WHERE id = ? AND case_id = ?', [(int) $id, $c['id']]) ?? forbidden();
    $action = (string) input('action');
    if ($action === 'nao_possui') {
        update('documents', (int) $doc['id'], ['status' => 'nao_possui', 'note' => mb_substr((string) input('note'), 0, 300) ?: null]);
        add_event((int) $c['id'], 'documento', 'Você informou que não possui: ' . $doc['label'], 'A equipe vai avaliar como obter ou se é possível seguir sem ele.', true, 'cliente');
        add_task((int) $c['id'], 'Cliente não possui “' . $doc['label'] . '”: definir como obter', $c['gestor_id'] ? (int) $c['gestor_id'] : null, add_business_days(now(), 1), 'equipe', 'documento');
        flash('ok', 'Anotado. A equipe vai te dizer como conseguir esse documento ou se dá para seguir sem ele.');
        redirect('cliente#documentos');
    }
    $files = uploaded_files('files');
    if (!$files) {
        flash('error', 'Escolha pelo menos um arquivo.');
        redirect('cliente#documentos');
    }
    $saved = 0;
    foreach (array_slice($files, 0, 10) as $f) {
        $r = store_upload($f, (int) $c['id'], (int) $doc['id'], true);
        if (is_string($r)) {
            flash('error', $f['name'] . ': ' . $r);
        } else {
            $saved++;
        }
    }
    if ($saved) {
        update('documents', (int) $doc['id'], ['status' => 'recebido', 'received_at' => now_str()]);
        add_event((int) $c['id'], 'documento', 'Recebemos ' . plural($saved, 'arquivo', 'arquivos') . ': ' . $doc['label'], 'A conferência é feita em até 1 dia útil. Se faltar alguma página, avisamos.', true, 'cliente');
        add_task((int) $c['id'], 'Conferir envio do cliente: ' . $doc['label'] . ' (meta: 1 dia útil)', $c['gestor_id'] ? (int) $c['gestor_id'] : null, add_business_days(now(), 1), 'equipe', 'conferir_documento');
        flash('ok', 'Recebido! A equipe confere em até 1 dia útil e te avisa se faltar algo.');
    }
    redirect('cliente#documentos');
});

$router->post('/cliente/decisao/{id}', function (string $id) {
    $c = require_portal();
    $d = one('SELECT * FROM decisions WHERE id = ? AND case_id = ? AND answer IS NULL', [(int) $id, $c['id']]) ?? forbidden();
    $options = json_decode($d['options'], true) ?: [];
    $answer = (string) input('answer');
    if (!in_array($answer, $options, true) && $answer !== 'Quero conversar antes de decidir') {
        flash('error', 'Escolha uma das opções.');
        redirect('cliente#decisoes');
    }
    update('decisions', (int) $d['id'], [
        'answer' => $answer,
        'reason' => mb_substr((string) input('reason'), 0, 1500) ?: null,
        'answered_via' => 'area do cliente',
        'answered_at' => now_str(),
    ]);
    add_event((int) $c['id'], 'decisao', 'Sua resposta foi registrada: ' . $answer, $d['question'], true, 'cliente');
    add_task((int) $c['id'], 'Cliente respondeu decisão: “' . mb_strimwidth($d['question'], 0, 60, '…') . '”', $c['advogado_id'] ? (int) $c['advogado_id'] : null, add_business_days(now(), 1), 'equipe', 'decisao_cliente');
    flash('ok', $answer === 'Quero conversar antes de decidir' ? 'Combinado. A equipe vai te procurar para conversar antes.' : 'Decisão registrada. A equipe confirma com você o próximo passo.');
    redirect('cliente#decisoes');
});

$router->post('/cliente/mensagem', function () {
    $c = require_portal();
    $text = trim(mb_substr((string) input('message'), 0, 2000));
    if ($text === '') {
        redirect('cliente');
    }
    add_event((int) $c['id'], 'mensagem_cliente', 'Recado enviado pela área do cliente', $text, true, 'cliente');
    add_task((int) $c['id'], 'Responder recado do cliente na área do cliente', $c['gestor_id'] ? (int) $c['gestor_id'] : null, add_business_hours(now(), 4), 'equipe', 'recado');
    flash('ok', 'Recado enviado. Respondemos pelo seu canal preferido.');
    redirect('cliente');
});

$router->post('/cliente/avaliacao', function () {
    $c = require_portal();
    $ease = (int) input('ease');
    if ($ease < 1 || $ease > 5) {
        flash('error', 'Escolha uma nota de 1 a 5.');
        redirect('cliente#avaliacao');
    }
    insert('feedback', [
        'case_id' => $c['id'],
        'kind' => (string) input('kind') === '30dias' ? '30dias' : 'encerramento',
        'ease' => $ease,
        'simplify' => mb_substr((string) input('simplify'), 0, 1500) ?: null,
        'answers' => json_encode(array_filter([
            'funcionando' => (string) input('funcionando'),
            'cobranca_diferente' => (string) input('cobranca_diferente'),
            'sabe_proximos' => (string) input('sabe_proximos'),
        ]), JSON_UNESCAPED_UNICODE),
        'created_at' => now_str(),
    ]);
    add_event((int) $c['id'], 'avaliacao', 'Avaliação enviada pelo cliente', 'Nota de facilidade: ' . $ease, false, 'cliente');
    flash('ok', 'Obrigado! Sua resposta nos ajuda a simplificar o atendimento.');
    redirect('cliente');
});
