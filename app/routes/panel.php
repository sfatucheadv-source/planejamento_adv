<?php
declare(strict_types=1);

/** @var Router $router */

/* ---------- Acesso ---------- */

$router->get('/painel/entrar', function () {
    if (current_user()) {
        redirect('painel');
    }
    $hasUsers = (int) scalar('SELECT COUNT(*) FROM users') > 0;
    view('panel/entrar', ['meta_title' => 'Painel da equipe', 'has_users' => $hasUsers], 'panel_bare');
});

/* ---------- Instalação: primeiro acesso, sem terminal ---------- */

function install_code_file(): string
{
    return config('storage_dir') . '/codigo-instalacao.txt';
}

$router->get('/painel/instalar', function () {
    if ((int) scalar('SELECT COUNT(*) FROM users') > 0) {
        redirect('painel/entrar');
    }
    $file = install_code_file();
    if (!is_file($file)) {
        @file_put_contents($file, random_code(10) . PHP_EOL);
        @chmod($file, 0600);
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    view('panel/instalar', [
        'meta_title' => 'Instalação',
        'code_ready' => is_file($file),
        'https' => $https,
    ], 'panel_bare');
});

$router->post('/painel/instalar', function () {
    $bucket = 'install:' . client_ip();
    if (too_many_attempts($bucket, 6, 15)) {
        flash('error', 'Muitas tentativas. Aguarde 15 minutos.');
        redirect('painel/instalar');
    }
    $file = install_code_file();
    $expected = is_file($file) ? strtoupper(trim((string) file_get_contents($file))) : '';
    $code = strtoupper(preg_replace('/\s+/', '', (string) input('code')) ?? '');
    if ($expected === '' || strlen($expected) < 10 || !hash_equals($expected, $code)) {
        record_attempt($bucket);
        flash('error', 'Código de instalação incorreto. Copie o conteúdo do arquivo storage/codigo-instalacao.txt.');
        redirect('painel/instalar');
    }
    $name = trim((string) input('name'));
    $email = mb_strtolower(trim((string) input('email')));
    $pass = (string) ($_POST['password'] ?? '');
    if (mb_strlen($name) < 2 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash('error', 'Informe nome e e-mail válidos.');
        redirect('painel/instalar');
    }
    if (mb_strlen($pass) < 10 || $pass !== (string) ($_POST['password2'] ?? '')) {
        flash('error', 'A senha precisa ter pelo menos 10 caracteres e as duas digitações precisam ser iguais.');
        redirect('painel/instalar');
    }
    $pdo = db();
    $pdo->exec('BEGIN IMMEDIATE');
    if ((int) scalar('SELECT COUNT(*) FROM users') > 0) {
        $pdo->exec('ROLLBACK');
        redirect('painel/entrar');
    }
    $id = insert('users', [
        'name' => $name,
        'email' => $email,
        'roles' => implode(',', array_keys(ROLES)),
        'pass_hash' => password_hash($pass, PASSWORD_DEFAULT),
        'active' => 1,
        'created_at' => now_str(),
    ]);
    $pdo->exec('COMMIT');
    @unlink($file);
    login_user(one('SELECT * FROM users WHERE id = ?', [$id]));
    audit('instalacao', null, $email);
    flash('ok', 'Instalação concluída. Este é o painel da equipe; cadastre os colegas em Equipe.');
    redirect('painel');
});

$router->post('/painel/entrar', function () {
    $email = mb_strtolower(trim((string) input('email')));
    $bucket = 'panel:' . client_ip();
    if (too_many_attempts($bucket)) {
        flash('error', 'Muitas tentativas. Aguarde 15 minutos.');
        redirect('painel/entrar');
    }
    $u = one('SELECT * FROM users WHERE email = ? AND active = 1', [$email]);
    if (!$u || !password_verify((string) ($_POST['password'] ?? ''), $u['pass_hash'])) {
        record_attempt($bucket);
        flash('error', 'E-mail ou senha não conferem.');
        redirect('painel/entrar');
    }
    login_user($u);
    audit('login');
    $to = $_SESSION['after_login'] ?? null;
    unset($_SESSION['after_login']);
    if (is_string($to) && str_starts_with($to, base_path() . '/painel')) {
        header('Location: ' . $to, true, 303);
        exit;
    }
    redirect('painel');
});

$router->get('/painel/sair', function () {
    unset($_SESSION['uid']);
    session_regenerate_id(true);
    redirect('painel/entrar');
});

/* ---------- Hoje ---------- */

$router->get('/painel', function () {
    $u = require_user();
    $active = all("SELECT * FROM cases WHERE status = 'ativo' ORDER BY urgent DESC, created_at");
    $alerts = [];
    foreach ($active as $c) {
        foreach (case_alerts($c) as $a) {
            $a['case'] = $c;
            $alerts[] = $a;
        }
    }
    usort($alerts, fn($a, $b) => ($a['level'] === 'high' ? 0 : 1) <=> ($b['level'] === 'high' ? 0 : 1));
    $byStage = [];
    foreach ($active as $c) {
        $byStage[$c['stage']] = ($byStage[$c['stage']] ?? 0) + 1;
    }
    view('panel/hoje', [
        'meta_title' => 'Hoje · Painel',
        'u' => $u,
        'active' => $active,
        'alerts' => $alerts,
        'byStage' => $byStage,
        'myTasks' => all("SELECT t.*, c.client_name, c.protocol FROM tasks t LEFT JOIN cases c ON c.id = t.case_id WHERE t.status = 'aberta' AND (t.owner_id = ? OR t.owner_id IS NULL) ORDER BY t.due_at IS NULL, t.due_at LIMIT 25", [$u['id']]),
        'waiting' => array_filter($active, fn($c) => !$c['first_human_contact_at']),
        'answers' => all("SELECT d.*, c.client_name, c.protocol FROM decisions d JOIN cases c ON c.id = d.case_id WHERE d.answered_at >= ? ORDER BY d.answered_at DESC LIMIT 10", [now()->modify('-7 days')->format('Y-m-d H:i:s')]),
        'clientNotes' => all("SELECT e.*, c.client_name, c.protocol FROM events e JOIN cases c ON c.id = e.case_id WHERE e.actor = 'cliente' AND e.at >= ? ORDER BY e.at DESC LIMIT 10", [now()->modify('-3 days')->format('Y-m-d H:i:s')]),
    ], 'panel');
});

/* ---------- Casos ---------- */

$router->get('/painel/casos', function () {
    require_user();
    $where = ['1=1'];
    $params = [];
    $status = (string) input('status', 'ativo');
    if ($status !== 'todos') {
        $where[] = 'status = ?';
        $params[] = $status;
    }
    if (($st = (string) input('etapa')) && isset(stages()[$st])) {
        $where[] = 'stage = ?';
        $params[] = $st;
    }
    if (input('urgente') === '1') {
        $where[] = 'urgent = 1';
    }
    if (input('meus') === '1') {
        $uid = current_user()['id'];
        $where[] = '(gestor_id = ? OR advogado_id = ? OR substituto_id = ?)';
        array_push($params, $uid, $uid, $uid);
    }
    if (($term = trim((string) input('q'))) !== '') {
        $where[] = '(client_name LIKE ? OR protocol LIKE ? OR client_phone LIKE ? OR bank LIKE ?)';
        $like = '%' . $term . '%';
        array_push($params, $like, $like, '%' . only_digits($term) . '%', $like);
    }
    $cases = all('SELECT * FROM cases WHERE ' . implode(' AND ', $where) . ' ORDER BY urgent DESC, updated_at DESC', $params);
    view('panel/casos', ['meta_title' => 'Casos · Painel', 'cases' => $cases, 'vista' => (string) input('vista', 'quadro')], 'panel');
});

$router->get('/painel/casos/novo', function () {
    require_user();
    view('panel/novo', ['meta_title' => 'Novo atendimento · Painel', 'old' => [], 'errors' => []], 'panel');
});

$router->post('/painel/casos/novo', function () {
    require_user();
    $a = [
        'notice' => (string) input('notice'),
        'notice_date' => (string) input('notice_date'),
        'bank' => (string) input('bank'),
        'contract_status' => (string) input('contract_status'),
        'installment_value' => input_money('installment_value'),
        'overdue' => (string) input('overdue'),
        'overdue_count' => (string) input('overdue_count'),
        'has_contract' => (string) input('has_contract'),
        'city' => (string) input('city'),
        'uf' => strtoupper((string) input('uf')),
        'priority' => (string) input('priority'),
        'priority_text' => '',
        'description' => (string) input('description'),
        'budget_installment' => input_money('budget_installment'),
        'other_lawyer' => (string) input('other_lawyer'),
        'contact_pref' => (string) input('contact_pref'),
        'contact_time' => (string) input('contact_time'),
        'accessibility' => (string) input('accessibility'),
        'name' => (string) input('name'),
        'phone' => (string) input('phone'),
        'email' => (string) input('email'),
        'origin' => 'painel',
        'summary_confirmed' => input('summary_ok') === '1',
    ];
    $errors = [];
    if (!isset(NOTICE_OPTIONS[$a['notice']])) {
        $errors['notice'] = 'Registre a resposta sobre notificação/apreensão (pode ser “não sei”).';
    }
    if (!isset(PRIORITY_OPTIONS[$a['priority']])) {
        $errors['priority'] = 'Registre a prioridade do cliente.';
    }
    if (mb_strlen($a['name']) < 2) {
        $errors['name'] = 'Informe o nome.';
    }
    if (strlen(only_digits($a['phone'])) < 10) {
        $errors['phone'] = 'Informe o telefone com DDD.';
    }
    if ($errors) {
        view('panel/novo', ['meta_title' => 'Novo atendimento · Painel', 'old' => $_POST, 'errors' => $errors], 'panel', 422);
    }
    $a['summary'] = summary_sentence($a);
    $c = create_case_from_triage($a, (string) input('source', 'whatsapp'));
    if (input('first_contact') === '1') {
        touch_case((int) $c['id'], ['first_human_contact_at' => now_str()]);
        close_tasks_of_kind((int) $c['id'], 'primeiro_contato', 'Atendimento iniciado pela própria equipe');
    }
    audit('caso_criado', (int) $c['id'], 'Origem: ' . input('source', 'whatsapp'));
    flash('ok', 'Caso aberto com protocolo ' . $c['protocol'] . '.');
    redirect('painel/caso/' . $c['id']);
});

$router->get('/painel/caso/{id}', function (string $id) {
    require_user();
    $c = find_case((int) $id);
    $cid = (int) $c['id'];
    view('panel/caso', [
        'meta_title' => $c['client_name'] . ' · ' . $c['protocol'],
        'c' => $c,
        'tab' => (string) input('aba', 'resumo'),
        'docs' => all('SELECT * FROM documents WHERE case_id = ? ORDER BY essential DESC, id', [$cid]),
        'files' => all('SELECT * FROM files WHERE case_id = ? ORDER BY uploaded_at DESC', [$cid]),
        'tasks' => all('SELECT * FROM tasks WHERE case_id = ? ORDER BY status, due_at IS NULL, due_at', [$cid]),
        'events' => all('SELECT * FROM events WHERE case_id = ? ORDER BY at DESC, id DESC', [$cid]),
        'decisions' => all('SELECT * FROM decisions WHERE case_id = ? ORDER BY requested_at DESC', [$cid]),
        'offers' => all('SELECT * FROM offers WHERE case_id = ? ORDER BY round, id', [$cid]),
        'schedule' => all('SELECT * FROM schedule WHERE case_id = ? ORDER BY due_date', [$cid]),
        'deadlines' => all('SELECT * FROM deadlines WHERE case_id = ? ORDER BY status, due_at', [$cid]),
        'exceptions' => all('SELECT * FROM exceptions WHERE case_id = ? ORDER BY resolved_at IS NOT NULL, opened_at DESC', [$cid]),
        'messages' => all('SELECT * FROM messages WHERE case_id = ? ORDER BY sent_at DESC', [$cid]),
        'feedback' => all('SELECT * FROM feedback WHERE case_id = ? ORDER BY created_at DESC', [$cid]),
        'audits' => all('SELECT * FROM audit WHERE case_id = ? ORDER BY at DESC LIMIT 200', [$cid]),
        'users' => users_list(),
    ], 'panel');
});

$router->post('/painel/caso/{id}', function (string $id) {
    require_user();
    $c = find_case((int) $id);
    $tab = handle_case_action($c, (string) input('op'));
    redirect('painel/caso/' . $c['id'] . '?aba=' . $tab . (input('anchor') ? '#' . preg_replace('/\W/', '', (string) input('anchor')) : ''));
});

/* ---------- Tarefas ---------- */

$router->get('/painel/tarefas', function () {
    $u = require_user();
    $scope = (string) input('de', 'minhas');
    $sql = "SELECT t.*, c.client_name, c.protocol, c.urgent FROM tasks t LEFT JOIN cases c ON c.id = t.case_id WHERE t.status = 'aberta'";
    $params = [];
    if ($scope === 'minhas') {
        $sql .= ' AND (t.owner_id = ? OR t.owner_id IS NULL)';
        $params[] = $u['id'];
    }
    $sql .= ' ORDER BY t.due_at IS NULL, t.due_at';
    view('panel/tarefas', ['meta_title' => 'Tarefas · Painel', 'tasks' => all($sql, $params), 'scope' => $scope], 'panel');
});

$router->post('/painel/tarefa/{id}', function (string $id) {
    require_user();
    $t = one('SELECT * FROM tasks WHERE id = ?', [(int) $id]) ?? not_found();
    $op = (string) input('op');
    if ($op === 'concluir') {
        $evidence = trim((string) input('evidence'));
        update('tasks', (int) $t['id'], ['status' => 'concluida', 'done_at' => now_str(), 'evidence' => $evidence ?: 'Concluída']);
        if ($t['case_id']) {
            add_event((int) $t['case_id'], 'tarefa', 'Tarefa concluída: ' . $t['title'], $evidence ?: null);
        }
    } elseif ($op === 'reabrir') {
        update('tasks', (int) $t['id'], ['status' => 'aberta', 'done_at' => null]);
    } elseif ($op === 'adiar') {
        $due = dt((string) input('due_at'));
        if ($due) {
            update('tasks', (int) $t['id'], ['due_at' => $due->format('Y-m-d H:i:s')]);
            if ($t['case_id']) {
                add_event((int) $t['case_id'], 'tarefa', 'Nova data para: ' . $t['title'], 'Novo prazo: ' . fmt_datetime($due->format('Y-m-d H:i:s')) . '. Motivo: ' . (input('reason') ?: 'não informado'));
            }
        }
    }
    back('painel/tarefas');
});

/* ---------- Indicadores, modelos, fluxo ---------- */

$router->get('/painel/indicadores', function () {
    require_user();
    view('panel/indicadores', ['meta_title' => 'Indicadores · Painel', 'm' => indicators()], 'panel');
});

$router->get('/painel/modelos', function () {
    require_user();
    view('panel/modelos', ['meta_title' => 'Modelos de mensagem · Painel'], 'panel');
});

$router->get('/painel/fluxo', function () {
    require_user();
    view('panel/fluxo', ['meta_title' => 'Fluxo de trabalho · Painel'], 'panel');
});

/* ---------- Equipe e conta ---------- */

$router->get('/painel/equipe', function () {
    require_user();
    view('panel/equipe', ['meta_title' => 'Equipe · Painel', 'users' => users_list(false)], 'panel');
});

$router->post('/painel/equipe', function () {
    require_user();
    require_role('supervisor');
    $roles = array_values(array_intersect(array_keys(ROLES), (array) ($_POST['roles'] ?? [])));
    $email = mb_strtolower(trim((string) input('email')));
    $uid = input_int('user_id');
    if (!$roles || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen((string) input('name')) < 2) {
        flash('error', 'Informe nome, e-mail válido e ao menos uma função.');
        redirect('painel/equipe');
    }
    $row = ['name' => (string) input('name'), 'email' => $email, 'phone' => (string) input('phone') ?: null, 'roles' => implode(',', $roles), 'active' => input('active', '1') === '1' ? 1 : 0];
    $pass = (string) ($_POST['password'] ?? '');
    if ($uid) {
        if ($pass !== '') {
            if (mb_strlen($pass) < 10) {
                flash('error', 'A senha precisa de pelo menos 10 caracteres.');
                redirect('painel/equipe');
            }
            $row['pass_hash'] = password_hash($pass, PASSWORD_DEFAULT);
        }
        update('users', $uid, $row);
        audit('usuario_editado', null, $email);
    } else {
        if (mb_strlen($pass) < 10) {
            flash('error', 'A senha inicial precisa de pelo menos 10 caracteres.');
            redirect('painel/equipe');
        }
        if (scalar('SELECT 1 FROM users WHERE email = ?', [$email])) {
            flash('error', 'Já existe usuário com este e-mail.');
            redirect('painel/equipe');
        }
        $row['pass_hash'] = password_hash($pass, PASSWORD_DEFAULT);
        $row['created_at'] = now_str();
        insert('users', $row);
        audit('usuario_criado', null, $email);
    }
    flash('ok', 'Equipe atualizada.');
    redirect('painel/equipe');
});

$router->get('/painel/conta', function () {
    require_user();
    view('panel/conta', ['meta_title' => 'Minha conta · Painel'], 'panel');
});

$router->post('/painel/conta', function () {
    $u = require_user();
    if (!password_verify((string) ($_POST['current'] ?? ''), $u['pass_hash'])) {
        flash('error', 'A senha atual não confere.');
        redirect('painel/conta');
    }
    $new = (string) ($_POST['password'] ?? '');
    if (mb_strlen($new) < 10) {
        flash('error', 'Use pelo menos 10 caracteres.');
        redirect('painel/conta');
    }
    update('users', (int) $u['id'], ['pass_hash' => password_hash($new, PASSWORD_DEFAULT)]);
    audit('senha_alterada');
    flash('ok', 'Senha alterada.');
    redirect('painel/conta');
});

/* ---------- Ações do caso ---------- */

function handle_case_action(array $c, string $op): string
{
    $id = (int) $c['id'];
    $u = current_user();
    switch ($op) {
        case 'etapa':
            move_stage($c, (string) input('to'), trim((string) input('reason')));
            return 'resumo';

        case 'check':
            $stageKey = (string) input('stage');
            $item = (string) input('item');
            if (!isset(stages()[$stageKey]) && $stageKey !== 'encerramento') {
                return 'resumo';
            }
            $s = stage($stageKey);
            $known = array_merge(array_keys($s['steps']), array_keys($s['gates']), array_keys(CLOSING_LIST), ['revisao_estrategia', 'scr', 'teste_pratico']);
            if (!in_array($item, $known, true)) {
                return 'resumo';
            }
            if (isset($s['gates'][$item]) && in_array($item, ['suficiencia', 'classificado', 'recurso'], true)) {
                require_role('advogado');
            }
            $done = input('done') === '1';
            set_check($id, $stageKey, $item, $done, trim((string) input('note')) ?: null);
            if ($item === 'suficiencia' && $done) {
                touch_case($id, ['docs_sufficient_at' => now_str()]);
                add_event($id, 'documentos', 'Documentos suficientes para a análise', 'A análise do contrato começa agora. Meta: até 2 dias úteis.', true);
            }
            audit('check', $id, "$stageKey/$item=" . ($done ? '1' : '0'));
            return (string) input('back', 'resumo');

        case 'proxima_acao':
            $due = dt((string) input('next_action_due'));
            $upd = dt((string) input('next_client_update'));
            touch_case($id, [
                'next_action' => mb_substr((string) input('next_action'), 0, 240) ?: null,
                'next_action_due' => $due?->format('Y-m-d H:i:s'),
                'next_action_owner_id' => input_int('next_action_owner_id'),
                'client_next_action' => mb_substr((string) input('client_next_action'), 0, 240) ?: null,
                'next_client_update' => $upd?->format('Y-m-d H:i:s'),
            ]);
            set_case_data($id, 'client_alert', trim((string) input('client_alert')) ?: null);
            audit('proxima_acao', $id, (string) input('next_action'));
            flash('ok', 'Próxima ação atualizada. O cliente vê essas informações no cartão de acompanhamento.');
            return 'resumo';

        case 'equipe':
            touch_case($id, [
                'gestor_id' => input_int('gestor_id'),
                'advogado_id' => input_int('advogado_id'),
                'substituto_id' => input_int('substituto_id'),
            ]);
            add_event($id, 'equipe', 'Responsáveis definidos', 'Gestor: ' . user_name(input_int('gestor_id')) . ' · Advogado: ' . user_name(input_int('advogado_id')) . ' · Substituto: ' . user_name(input_int('substituto_id')));
            return 'resumo';

        case 'objetivo':
            touch_case($id, [
                'objective' => mb_substr((string) input('objective'), 0, 300) ?: null,
                'objective_measure' => mb_substr((string) input('objective_measure'), 0, 300) ?: null,
                'summary_confirmed' => input('summary_confirmed') === '1' ? 1 : 0,
            ]);
            add_event($id, 'objetivo', 'Objetivo registrado: ' . input('objective'), input('objective_measure') ? 'Como será comprovado: ' . input('objective_measure') : null);
            return 'atendimento';

        case 'primeiro_contato':
            if (!$c['first_human_contact_at']) {
                touch_case($id, ['first_human_contact_at' => now_str()]);
                close_tasks_of_kind($id, 'primeiro_contato', 'Contato por ' . (input('channel') ?: 'WhatsApp'));
                add_event($id, 'contato', 'Primeiro contato: ' . first_name(user_name((int) $u['id'])) . ' se apresentou e vai acompanhar o seu caso', 'Canal: ' . (input('channel') ?: 'WhatsApp'), true);
                set_check($id, 'acolhimento', 'apresentacao', true);
            }
            return 'atendimento';

        case 'conflito':
            $result = (string) input('conflict');
            set_case_data($id, 'conflict', ['result' => $result, 'note' => (string) input('note'), 'by' => $u['id'], 'at' => now_str()]);
            set_check($id, 'acolhimento', 'registro', true);
            set_check($id, 'acolhimento', 'conflito_tratado', $result !== '');
            add_event($id, 'conflito', 'Conflito de interesses verificado: ' . ($result === 'sem_conflito' ? 'sem conflito' : 'há conflito ou impedimento'), (string) input('note') ?: null);
            return 'atendimento';

        case 'urgencia':
            require_role('advogado');
            $class = (string) input('urgency_class');
            if (!isset(URGENCY_CLASSES[$class])) {
                flash('error', 'Escolha a classificação.');
                return 'atendimento';
            }
            $urgent = URGENCY_CLASSES[$class]['urgent'];
            touch_case($id, ['urgency_class' => $class, 'urgent' => $urgent ? 1 : 0, 'urgency_assessed_at' => now_str()]);
            close_tasks_of_kind($id, 'avaliacao_urgencia', 'Classificado: ' . URGENCY_CLASSES[$class]['label']);
            set_check($id, 'urgencia', 'classificado', true, trim((string) input('note')) ?: null);
            set_check($id, 'acolhimento', 'urgencias_encaminhadas', true);
            add_event($id, 'urgencia', 'Urgência avaliada pelo advogado: ' . URGENCY_CLASSES[$class]['label'], URGENCY_CLASSES[$class]['action'] . ' Resultado necessário: ' . URGENCY_CLASSES[$class]['result'] . (input('note') ? "\n" . input('note') : ''));
            if ($urgent) {
                add_task($id, 'Plano urgente: ' . URGENCY_CLASSES[$class]['action'], (int) ($c['advogado_id'] ?: $u['id']), add_business_hours(now(), 2), 'equipe', 'plano_urgente');
            }
            return 'atendimento';

        case 'marcar_urgente':
            $flag = input('urgent') === '1';
            touch_case($id, ['urgent' => $flag ? 1 : 0]);
            add_event($id, 'urgencia', $flag ? 'Caso marcado como urgente' : 'Marcação de urgência removida', (string) input('reason') ?: null);
            if ($flag) {
                add_task($id, 'Avaliar urgência imediatamente (meta: 2 horas úteis)', (int) ($c['advogado_id'] ?: $u['id']), add_business_hours(now(), 2), 'equipe', 'avaliacao_urgencia');
            }
            return 'resumo';

        case 'prazo':
            $due = dt((string) input('due_at'));
            if (!$due || trim((string) input('description')) === '') {
                flash('error', 'Informe a descrição e a data do prazo.');
                return 'atendimento';
            }
            insert('deadlines', [
                'case_id' => $id,
                'description' => mb_substr((string) input('description'), 0, 200),
                'trigger_event' => mb_substr((string) input('trigger_event'), 0, 200) ?: null,
                'due_at' => $due->format('Y-m-d H:i:s'),
                'owner_id' => input_int('owner_id'),
                'substitute_id' => input_int('substitute_id'),
                'status' => 'aberto',
            ]);
            set_check($id, 'urgencia', 'calendario', true);
            add_event($id, 'prazo', 'Prazo jurídico registrado: ' . input('description'), 'Vencimento: ' . fmt_datetime($due->format('Y-m-d H:i:s')) . '. Evento de início: ' . (input('trigger_event') ?: '—'));
            audit('prazo', $id, (string) input('description'));
            return (string) input('back', 'atendimento');

        case 'prazo_status':
            $d = one('SELECT * FROM deadlines WHERE id = ? AND case_id = ?', [input_int('deadline_id'), $id]);
            if ($d) {
                $status = input('status') === 'cumprido' ? 'cumprido' : (input('status') === 'perdido' ? 'perdido' : 'aberto');
                update('deadlines', (int) $d['id'], ['status' => $status, 'done_at' => $status !== 'aberto' ? now_str() : null, 'note' => (string) input('note') ?: $d['note']]);
                add_event($id, 'prazo', 'Prazo ' . $status . ': ' . $d['description'], (string) input('note') ?: null);
                audit('prazo_' . $status, $id, $d['description']);
            }
            return (string) input('back', 'atendimento');

        case 'orientacao_escrita':
            $text = trim((string) input('text'));
            if ($text === '') {
                return 'atendimento';
            }
            add_event($id, 'orientacao', 'Orientação do advogado sobre pagamentos e notificações', $text, true);
            insert('messages', ['case_id' => $id, 'template' => 'orientacao', 'channel' => 'area do cliente', 'body' => $text, 'facts_checked' => 1, 'sent_by' => $u['id'], 'sent_at' => now_str()]);
            set_check($id, 'urgencia', 'orientacao_escrita', true);
            set_check($id, 'urgencia', 'orientacao', true);
            return 'atendimento';

        /* --- Documentos (etapa 3) --- */
        case 'doc_add':
            $kind = (string) input('kind');
            $label = trim((string) input('label'));
            if (isset(DOC_CATALOG[$kind])) {
                $label = $label ?: DOC_CATALOG[$kind]['label'];
            } else {
                $kind = 'outro';
            }
            if ($label === '') {
                return 'documentos';
            }
            insert('documents', ['case_id' => $id, 'kind' => $kind, 'label' => $label, 'status' => 'falta', 'essential' => input('essential') === '1' ? 1 : 0, 'requested_at' => now_str()]);
            add_event($id, 'documento', 'Documento solicitado: ' . $label, isset(DOC_CATALOG[$kind]) ? DOC_CATALOG[$kind]['why'] : null, true);
            return 'documentos';

        case 'doc_status':
            $doc = one('SELECT * FROM documents WHERE id = ? AND case_id = ?', [input_int('doc_id'), $id]);
            $status = (string) input('status');
            if ($doc && isset(DOC_STATUS[$status])) {
                $row = ['status' => $status, 'note' => trim((string) input('note')) ?: $doc['note']];
                if ($status === 'conferido') {
                    $row['reviewed_at'] = now_str();
                    $row['reviewed_by'] = $u['id'];
                    q('UPDATE files SET reviewed_at = ? WHERE document_id = ? AND reviewed_at IS NULL', [now_str(), $doc['id']]);
                    close_tasks_of_kind($id, 'conferir_documento', 'Conferido: ' . $doc['label']);
                }
                if ($status === 'recebido' && !$doc['received_at']) {
                    $row['received_at'] = now_str();
                }
                update('documents', (int) $doc['id'], $row);
                $visible = in_array($status, ['conferido', 'pendencia', 'ajuda', 'dispensado'], true);
                $msg = match ($status) {
                    'conferido' => 'Documento conferido: ' . $doc['label'],
                    'pendencia' => 'Precisamos de nova cópia: ' . $doc['label'],
                    'ajuda' => 'Vamos ajudar a obter: ' . $doc['label'],
                    'dispensado' => 'Documento não é mais necessário: ' . $doc['label'],
                    default => 'Documento “' . $doc['label'] . '”: ' . DOC_STATUS[$status]['label'],
                };
                add_event($id, 'documento', $msg, $status === 'pendencia' ? ($row['note'] ?? null) : null, $visible);
            }
            return 'documentos';

        case 'doc_upload':
            $docId = input_int('doc_id');
            $doc = $docId ? one('SELECT * FROM documents WHERE id = ? AND case_id = ?', [$docId, $id]) : null;
            $saved = 0;
            foreach (uploaded_files('files') as $f) {
                $r = store_upload($f, $id, $doc ? (int) $doc['id'] : null, false);
                if (is_string($r)) {
                    flash('error', $f['name'] . ': ' . $r);
                } else {
                    $saved++;
                    q('UPDATE files SET reviewed_at = ? WHERE id = ?', [now_str(), $r]);
                }
            }
            if ($saved && $doc && in_array($doc['status'], ['falta', 'ajuda', 'pendencia'], true)) {
                update('documents', (int) $doc['id'], ['status' => 'recebido', 'received_at' => now_str()]);
            }
            if ($saved) {
                add_event($id, 'documento', 'Equipe anexou ' . plural($saved, 'arquivo', 'arquivos') . ($doc ? ' em ' . $doc['label'] : ''));
            }
            return 'documentos';

        case 'arquivo_conferido':
            q('UPDATE files SET reviewed_at = ? WHERE id = ? AND case_id = ?', [now_str(), input_int('file_id'), $id]);
            return 'documentos';

        case 'pedido_banco':
            $req = [
                'channel' => (string) input('channel'),
                'protocol' => (string) input('protocol'),
                'requested_at' => (string) input('requested_at') ?: now()->format('Y-m-d'),
                'bank_deadline' => (string) input('bank_deadline'),
                'items' => (string) input('items'),
            ];
            $list = case_data($c, 'bank_requests', []);
            $list[] = $req;
            set_case_data($id, 'bank_requests', $list);
            $base = dt($req['bank_deadline']) ?: add_business_days(now(), 5);
            add_task($id, 'Cobrar banco: cópia do contrato/documentos (protocolo ' . ($req['protocol'] ?: 's/n') . ')', $c['gestor_id'] ? (int) $c['gestor_id'] : null, add_business_days($base, 2), 'banco', 'cobrar_banco');
            add_event($id, 'documento', 'Pedimos ao banco a cópia dos documentos', 'Canal: ' . ($req['channel'] ?: 'oficial') . ($req['protocol'] ? ' · protocolo ' . $req['protocol'] : '') . ($req['bank_deadline'] ? ' · prazo informado: ' . fmt_date($req['bank_deadline']) : ''), true);
            return 'documentos';

        case 'lembrete':
            $n = (int) case_data($c, 'reminders', 0) + 1;
            set_case_data($id, 'reminders', $n);
            add_event($id, 'lembrete', 'Lembrete de documentos enviado ao cliente (' . $n . 'º)', (string) input('note') ?: null);
            if ($n >= 2) {
                flash('ok', 'Segundo lembrete registrado. Se continuar sem resposta, registre a exceção “Cliente deixa de responder”.');
            }
            return 'documentos';

        /* --- Auditoria (etapa 4) --- */
        case 'auditoria':
            $fields = ['contract_date', 'principal', 'down_payment', 'installments', 'installment_value', 'monthly_rate', 'annual_rate', 'cet_annual', 'tac', 'evaluation_fee', 'registration_fee', 'insurance', 'other_charges', 'paid_installments', 'bcb_rate', 'bcb_reference', 'bcb_consulted_at', 'bcb_source', 'reference_date', 'scenario_contract', 'scenario_negotiation', 'scenario_revision', 'questioned', 'notes', 'summary_client', 'conclusion'];
            $audit = case_data($c, 'audit', []);
            foreach ($fields as $f) {
                $v = $_POST[$f] ?? null;
                if (is_string($v)) {
                    $audit[$f] = trim($v);
                }
            }
            $audit['updated_at'] = now_str();
            $audit['updated_by'] = $u['id'];
            if (input('validate') === '1') {
                require_role('advogado');
                if (!isset(AUDIT_CONCLUSIONS[$audit['conclusion'] ?? ''])) {
                    flash('error', 'Escolha a conclusão do parecer antes de validar.');
                    set_case_data($id, 'audit', $audit);
                    return 'analise';
                }
                $audit['validated_at'] = now_str();
                $audit['validated_by'] = $u['id'];
                set_check($id, 'auditoria', 'memoria', true);
                if (trim($audit['summary_client'] ?? '') !== '') {
                    set_check($id, 'auditoria', 'sintese', true);
                }
                add_event($id, 'analise', 'Análise do contrato concluída', 'O advogado validou o cálculo e o parecer. Na próxima conversa, ele explica o resultado e os caminhos possíveis.', true);
                audit('auditoria_validada', $id, AUDIT_CONCLUSIONS[$audit['conclusion']]);
            } elseif (input('unvalidate') === '1') {
                require_role('advogado');
                unset($audit['validated_at'], $audit['validated_by']);
                audit('auditoria_reaberta', $id);
            }
            set_case_data($id, 'audit', $audit);
            return 'analise';

        /* --- Decisões (etapas 5, 7, 11) --- */
        case 'decisao_nova':
            $question = trim((string) input('question'));
            $opts = array_values(array_filter(array_map('trim', preg_split('/\R/', (string) input('options')) ?: [])));
            if ($question === '' || count($opts) < 1) {
                flash('error', 'Escreva a pergunta e pelo menos uma opção.');
                return (string) input('back', 'decisao');
            }
            $did = insert('decisions', [
                'case_id' => $id,
                'kind' => (string) input('kind', 'outro'),
                'question' => $question,
                'context' => trim((string) input('context')) ?: null,
                'options' => json_encode($opts, JSON_UNESCAPED_UNICODE),
                'deadline' => (string) input('deadline') ?: null,
                'requested_by' => $u['id'],
                'requested_at' => now_str(),
            ]);
            if (input('answer') !== null && trim((string) input('answer')) !== '') {
                update('decisions', $did, ['answer' => (string) input('answer'), 'reason' => (string) input('reason') ?: null, 'answered_via' => (string) input('via', 'ligação'), 'answered_at' => now_str(), 'recorded_by' => $u['id']]);
                add_event($id, 'decisao', 'Decisão registrada: ' . input('answer'), $question . (input('reason') ? "\nPor quê: " . input('reason') : ''), true);
            } else {
                add_event($id, 'decisao', 'Uma decisão depende de você', $question, true);
                if (!$c['client_next_action']) {
                    touch_case($id, ['client_next_action' => 'Responder: ' . mb_strimwidth($question, 0, 120, '…')]);
                }
            }
            return (string) input('back', 'decisao');

        case 'decisao_registrar':
            $d = one('SELECT * FROM decisions WHERE id = ? AND case_id = ?', [input_int('decision_id'), $id]);
            $answer = trim((string) input('answer'));
            if ($d && $answer !== '') {
                update('decisions', (int) $d['id'], ['answer' => $answer, 'reason' => (string) input('reason') ?: null, 'answered_via' => (string) input('via', 'ligação'), 'answered_at' => now_str(), 'recorded_by' => $u['id']]);
                add_event($id, 'decisao', 'Decisão registrada: ' . $answer, $d['question'], true);
                touch_case($id, ['client_next_action' => null]);
            }
            return (string) input('back', 'decisao');

        case 'recomendacao':
            require_role('advogado');
            set_case_data($id, 'recommendation', [
                'path' => (string) input('path'),
                'reason' => (string) input('reason'),
                'costs' => (string) input('costs'),
                'limits' => (string) input('limits'),
                'payments' => (string) input('payments'),
                'by' => $u['id'],
                'at' => now_str(),
            ]);
            if (trim((string) input('limits')) !== '') {
                set_check($id, 'decisao', 'limites', true);
            }
            return 'decisao';

        case 'contratacao':
            set_case_data($id, 'engagement', [
                'scope' => (string) input('scope'),
                'excluded' => (string) input('excluded'),
                'fees' => (string) input('fees'),
                'example' => (string) input('example'),
                'expenses' => (string) input('expenses'),
                'signed_at' => (string) input('signed_at'),
                'by' => $u['id'],
                'at' => now_str(),
            ]);
            if (input('signed_at')) {
                set_check($id, 'contratacao', 'assinado', true);
                add_event($id, 'contratacao', 'Contrato de honorários e procuração assinados', 'Você recebe cópia de tudo o que assinou.', true);
            }
            return 'decisao';

        /* --- Negociação e acordo (etapas 7 e 8) --- */
        case 'limites':
            set_case_data($id, 'negotiation', [
                'max_down' => input_money('max_down'),
                'max_installment' => input_money('max_installment'),
                'pay_date' => (string) input('pay_date'),
                'priority' => (string) input('priority'),
                'creditor' => (string) input('creditor'),
                'creditor_contact' => (string) input('creditor_contact'),
                'authorized_at' => (string) input('authorized_at'),
                'by' => $u['id'],
            ]);
            set_check($id, 'negociacao', 'teto', true);
            return 'negociacao';

        case 'oferta':
            $round = input_int('round') ?: ((int) scalar('SELECT MAX(round) FROM offers WHERE case_id = ?', [$id]) ?: 0) + 1;
            $limits = case_data($c, 'negotiation', []);
            $offer = [
                'case_id' => $id,
                'round' => $round,
                'origin' => input('origin') === 'escritorio' ? 'escritorio' : 'credor',
                'creditor' => (string) input('creditor') ?: ($limits['creditor'] ?? null),
                'ref_balance' => input_money('ref_balance'),
                'ref_date' => (string) input('ref_date') ?: null,
                'down_payment' => input_money('down_payment'),
                'installments' => input_int('installments'),
                'installment_value' => input_money('installment_value'),
                'total' => input_money('total'),
                'charges' => (string) input('charges') ?: null,
                'effects' => (string) input('effects') ?: null,
                'valid_until' => (string) input('valid_until') ?: null,
                'protocol' => (string) input('protocol') ?: null,
                'status' => input('origin') === 'escritorio' ? 'enviada' : 'recebida',
                'notes' => (string) input('notes') ?: null,
                'created_by' => $u['id'],
                'created_at' => now_str(),
            ];
            $oid = insert('offers', $offer);
            $over = [];
            if ($offer['origin'] === 'escritorio') {
                if (($limits['max_down'] ?? null) !== null && $offer['down_payment'] !== null && $offer['down_payment'] > $limits['max_down']) {
                    $over[] = 'entrada acima do teto autorizado';
                }
                if (($limits['max_installment'] ?? null) !== null && $offer['installment_value'] !== null && $offer['installment_value'] > $limits['max_installment']) {
                    $over[] = 'parcela acima do limite autorizado';
                }
            }
            if ($over) {
                flash('error', 'Atenção: ' . implode(' e ', $over) . '. Não envie sem nova decisão do cliente.');
            }
            add_event($id, 'oferta', ($offer['origin'] === 'credor' ? 'Oferta recebida do credor' : 'Proposta enviada ao credor') . ' (rodada ' . $round . ')', 'Total: ' . money(offer_total(one('SELECT * FROM offers WHERE id = ?', [$oid]))) . ($offer['valid_until'] ? ' · validade ' . fmt_date($offer['valid_until']) : ''));
            if ($offer['origin'] === 'credor') {
                add_task($id, 'Comunicar oferta ao cliente (meta: 1 dia útil)', $c['gestor_id'] ? (int) $c['gestor_id'] : null, add_business_days(now(), 1), 'equipe', 'comunicar_oferta');
            } else {
                $due = $offer['valid_until'] ? dt($offer['valid_until']) : add_business_days(now(), 5);
                add_task($id, 'Cobrar resposta do credor à proposta (rodada ' . $round . ')', $c['gestor_id'] ? (int) $c['gestor_id'] : null, $due, 'banco', 'cobrar_credor');
                set_check($id, 'negociacao', 'proposta', true);
            }
            return 'negociacao';

        case 'oferta_comunicar':
            $o = one('SELECT * FROM offers WHERE id = ? AND case_id = ?', [input_int('offer_id'), $id]);
            if ($o) {
                update('offers', (int) $o['id'], ['communicated_at' => now_str()]);
                close_tasks_of_kind($id, 'comunicar_oferta', 'Oferta comunicada');
                $ctx = 'Entrada: ' . money($o['down_payment'] !== null ? (float) $o['down_payment'] : null)
                    . "\nParcelas: " . ($o['installments'] ?? '—') . ' de ' . money($o['installment_value'] !== null ? (float) $o['installment_value'] : null)
                    . "\nTotal: " . money(offer_total($o))
                    . ($o['effects'] ? "\nEfeitos: " . $o['effects'] : '')
                    . ($o['valid_until'] ? "\nValidade da oferta: " . fmt_date($o['valid_until']) : '')
                    . (input('recommendation') ? "\n\nNossa avaliação: " . input('recommendation') : '');
                insert('decisions', [
                    'case_id' => $id,
                    'kind' => 'oferta',
                    'question' => 'O banco fez uma proposta de acordo. Você autoriza aceitar?',
                    'context' => $ctx,
                    'options' => json_encode(['Autorizo aceitar a proposta', 'Não aceito; tentem uma contraproposta'], JSON_UNESCAPED_UNICODE),
                    'deadline' => $o['valid_until'],
                    'requested_by' => $u['id'],
                    'requested_at' => now_str(),
                ]);
                add_event($id, 'oferta', 'Nova proposta do banco para você avaliar', 'Os detalhes estão em “Decisões que dependem de você”. Só confirmamos com a sua autorização.', true);
                touch_case($id, ['client_next_action' => 'Avaliar a proposta do banco' . ($o['valid_until'] ? ' até ' . fmt_date($o['valid_until']) : '')]);
                set_check($id, 'negociacao', 'silencio', true);
            }
            return 'negociacao';

        case 'oferta_status':
            $o = one('SELECT * FROM offers WHERE id = ? AND case_id = ?', [input_int('offer_id'), $id]);
            $st = (string) input('status');
            if ($o && in_array($st, ['recebida', 'enviada', 'contraproposta', 'aceita', 'recusada', 'expirada'], true)) {
                if ($st === 'aceita' && !scalar("SELECT 1 FROM decisions WHERE case_id = ? AND kind = 'oferta' AND answer LIKE 'Autorizo%'", [$id]) && input('confirm_via') === '') {
                    flash('error', 'Só marque como aceita com a autorização do cliente registrada (na área do cliente ou informando o canal).');
                    return 'negociacao';
                }
                update('offers', (int) $o['id'], ['status' => $st]);
                add_event($id, 'oferta', 'Oferta da rodada ' . $o['round'] . ': ' . $st, input('confirm_via') ? 'Autorização do cliente por: ' . input('confirm_via') : null, $st === 'aceita');
                if ($st === 'aceita') {
                    set_check($id, 'negociacao', 'decisao_real', true);
                    set_check($id, 'negociacao', 'comparar', true);
                }
            }
            return 'negociacao';

        case 'acordo':
            set_case_data($id, 'agreement', [
                'signed_at' => (string) input('signed_at'),
                'instrument' => (string) input('instrument'),
                'balance' => input_money('balance'),
                'discount' => input_money('discount'),
                'releases' => (string) input('releases'),
                'vehicle' => (string) input('vehicle'),
                'boleto_checked' => input('boleto_checked') === '1',
                'homologation' => (string) input('homologation'),
                'by' => $u['id'],
                'at' => now_str(),
            ]);
            if (input('signed_at')) {
                set_check($id, 'acordo', 'formal', (int) scalar('SELECT COUNT(*) FROM schedule WHERE case_id = ?', [$id]) > 0 || input('sem_parcelas') === '1');
                add_event($id, 'acordo', 'Acordo formalizado', 'Agora acompanhamos o cumprimento: boletos, pagamentos e as obrigações do banco.', true);
            }
            return 'negociacao';

        case 'parcela_add':
            $due = (string) input('due_date');
            $n = max(1, min(120, input_int('repeat') ?: 1));
            $start = dt($due);
            if (!$start) {
                flash('error', 'Informe o vencimento.');
                return 'negociacao';
            }
            for ($k = 0; $k < $n; $k++) {
                insert('schedule', [
                    'case_id' => $id,
                    'description' => trim((string) input('description')) . ($n > 1 ? ' (' . ($k + 1) . '/' . $n . ')' : ''),
                    'due_date' => $start->modify("+$k month")->format('Y-m-d'),
                    'amount' => input_money('amount'),
                    'status' => 'pendente',
                ]);
            }
            if (case_data($c, 'agreement')['signed_at'] ?? null) {
                set_check($id, 'acordo', 'formal', true);
            }
            return 'negociacao';

        case 'parcela_status':
            $p = one('SELECT * FROM schedule WHERE id = ? AND case_id = ?', [input_int('schedule_id'), $id]);
            $st = (string) input('status');
            if ($p && in_array($st, ['pendente', 'pago', 'atrasado'], true)) {
                update('schedule', (int) $p['id'], ['status' => $st, 'confirmed_at' => $st === 'pago' ? now_str() : null, 'proof' => (string) input('proof') ?: $p['proof']]);
                add_event($id, 'pagamento', 'Pagamento ' . ($st === 'pago' ? 'confirmado' : $st) . ': ' . $p['description'], null, $st === 'pago');
                if ($st === 'atrasado') {
                    insert('exceptions', ['case_id' => $id, 'kind' => 'fora_orcamento', 'note' => 'Parcela em atraso: ' . $p['description'], 'opened_by' => $u['id'], 'opened_at' => now_str()]);
                }
            }
            return 'negociacao';

        /* --- Judicial (etapas 9 a 12) --- */
        case 'judicial':
            $j = case_data($c, 'judicial', []);
            foreach (['process_number', 'court', 'filed_at', 'claims', 'cause_value', 'tutela', 'tutela_note', 'hearing_at', 'hearing_format', 'decision_result', 'decision_date', 'decision_summary', 'appeal', 'appeal_reason', 'transit_at', 'new_balance', 'remaining_installments', 'refund_value', 'offset_value', 'fees_expenses', 'compliance_status', 'compliance_note'] as $f) {
                if (isset($_POST[$f]) && is_string($_POST[$f])) {
                    $j[$f] = trim($_POST[$f]);
                }
            }
            $prev = case_data($c, 'judicial', []);
            set_case_data($id, 'judicial', $j);
            if (($j['process_number'] ?? '') !== '' && ($j['filed_at'] ?? '') !== '' && empty($prev['filed_at'])) {
                set_check($id, 'preparacao_judicial', 'protocolo', true);
                add_event($id, 'judicial', 'Entramos com o pedido na Justiça', 'Processo ' . $j['process_number'] . ($j['court'] ? ' · ' . $j['court'] : '') . '. Agora aguardamos a análise do juiz. Continue seguindo a orientação de pagamentos enviada.', true);
            }
            if (($j['tutela'] ?? '') !== ($prev['tutela'] ?? '') && ($j['tutela'] ?? '') !== '') {
                $label = ['pedida' => 'Pedido urgente aguardando decisão', 'concedida' => 'Pedido urgente concedido', 'negada' => 'Pedido urgente negado', 'parcial' => 'Pedido urgente concedido em parte', 'nao_pedida' => 'Sem pedido urgente'][$j['tutela']] ?? $j['tutela'];
                add_event($id, 'judicial', $label, $j['tutela_note'] ?? null, true);
                add_task($id, 'Explicar ao cliente o efeito da decisão sobre o pedido urgente (meta: 1 dia útil)', $c['advogado_id'] ? (int) $c['advogado_id'] : null, add_business_days(now(), 1), 'equipe', 'comunicar_decisao');
            }
            if (($j['decision_result'] ?? '') !== ($prev['decision_result'] ?? '') && isset(DECISION_RESULTS[$j['decision_result'] ?? ''])) {
                $r = DECISION_RESULTS[$j['decision_result']];
                add_event($id, 'judicial', 'Decisão: ' . $r['label'], 'Próxima ação: ' . $r['next']);
                add_task($id, 'Explicar a decisão ao cliente e registrar a escolha: ' . $r['client'], $c['advogado_id'] ? (int) $c['advogado_id'] : null, add_business_days(now(), 1), 'equipe', 'comunicar_decisao');
            }
            if (($j['hearing_at'] ?? '') !== '' && ($j['hearing_at'] ?? '') !== ($prev['hearing_at'] ?? '')) {
                add_event($id, 'judicial', 'Audiência marcada para ' . fmt_datetime($j['hearing_at']), 'Formato: ' . ($j['hearing_format'] ?: 'a confirmar') . '. Vamos te preparar com antecedência.', true);
            }
            return 'judicial';

        /* --- Conferência e encerramento (etapas 13 e 14) --- */
        case 'solucao':
            $items = [];
            foreach (SOLUTION_ITEMS as $k => $it) {
                $items[$k] = [
                    'applies' => isset($_POST['applies'][$k]),
                    'evidence' => trim((string) ($_POST['evidence'][$k] ?? '')),
                    'done' => isset($_POST['done'][$k]) && trim((string) ($_POST['evidence'][$k] ?? '')) !== '',
                ];
            }
            set_case_data($id, 'solution', $items);
            set_case_data($id, 'scr', trim((string) input('scr')));
            set_case_data($id, 'practical_test', trim((string) input('practical_test')));
            if (input('scr')) {
                set_check($id, 'conferencia', 'scr', true);
            }
            if (input('practical_test')) {
                set_check($id, 'conferencia', 'teste', true);
            }
            $fresh = find_case($id);
            set_check($id, 'conferencia', 'checklist', solution_ready($fresh));
            return 'conferencia';

        case 'encerrar':
            require_role('supervisor', 'advogado');
            $outcome = (string) input('outcome');
            if (!isset(OUTCOMES[$outcome])) {
                flash('error', 'Escolha a categoria do desfecho.');
                return 'conferencia';
            }
            touch_case($id, ['outcome' => $outcome]);
            set_case_data($id, 'closing', ['summary' => (string) input('summary'), 'client_duties' => (string) input('client_duties'), 'by' => $u['id'], 'at' => now_str()]);
            $c = find_case($id);
            if ($c['stage'] !== 'encerramento') {
                flash('error', 'Leve o caso até a etapa Encerramento antes de fechar.');
                return 'conferencia';
            }
            $blocking = array_filter(gate_problems($c), fn($p) => $p['block']);
            if ($blocking) {
                flash('error', 'Antes de encerrar: ' . implode('; ', array_map(fn($p) => $p['label'], $blocking)) . '.');
                return 'conferencia';
            }
            touch_case($id, ['status' => 'encerrado', 'closed_at' => now_str(), 'next_action' => 'Acompanhamento posterior (30 dias)', 'next_action_due' => now()->modify('+30 days')->format('Y-m-d 10:00:00')]);
            add_event($id, 'encerramento', 'Caso encerrado: ' . OUTCOMES[$outcome]['label'], trim((string) input('summary')) ?: OUTCOMES[$outcome]['text'], true);
            add_task($id, 'Contato de 30 dias: “O combinado continua funcionando? Surgiu cobrança diferente? Você sabe o que fazer daqui em diante?”', $c['gestor_id'] ? (int) $c['gestor_id'] : null, now()->modify('+30 days')->setTime(10, 0), 'equipe', 'pos_30_dias');
            audit('encerramento', $id, $outcome);
            flash('ok', 'Caso encerrado. O acompanhamento de 30 dias ficou agendado.');
            return 'conferencia';

        case 'reabrir':
            require_role('supervisor', 'advogado');
            touch_case($id, ['status' => 'ativo', 'closed_at' => null]);
            move_stage(find_case($id), (string) input('to', 'conferencia'), 'Reabertura: ' . (input('reason') ?: 'falha na entrega'));
            set_case_data($id, 'reopened', (int) case_data($c, 'reopened', 0) + 1);
            audit('reabertura', $id, (string) input('reason'));
            return 'resumo';

        /* --- Tarefas, exceções, mensagens --- */
        case 'tarefa':
            $title = trim((string) input('title'));
            if ($title !== '') {
                add_task($id, $title, input_int('owner_id'), dt((string) input('due_at')), (string) input('depends_on', 'equipe'));
            }
            return 'tarefas';

        case 'excecao':
            $kind = (string) input('kind');
            if (isset(EXCEPTIONS[$kind])) {
                insert('exceptions', ['case_id' => $id, 'kind' => $kind, 'note' => (string) input('note') ?: null, 'opened_by' => $u['id'], 'opened_at' => now_str()]);
                add_event($id, 'excecao', 'Exceção registrada: ' . EXCEPTIONS[$kind]['label'], 'Quem assume: ' . EXCEPTIONS[$kind]['who'] . '. Providência: ' . EXCEPTIONS[$kind]['action']);
                if (!empty(EXCEPTIONS[$kind]['urgent'])) {
                    touch_case($id, ['urgent' => 1]);
                    add_task($id, 'Exceção urgente: ' . EXCEPTIONS[$kind]['action'], $c['advogado_id'] ? (int) $c['advogado_id'] : null, add_business_hours(now(), 2), 'equipe', 'excecao');
                }
            }
            return 'tarefas';

        case 'excecao_resolver':
            $x = one('SELECT * FROM exceptions WHERE id = ? AND case_id = ?', [input_int('exception_id'), $id]);
            if ($x) {
                update('exceptions', (int) $x['id'], ['resolved_at' => now_str(), 'resolution' => (string) input('resolution') ?: 'Resolvida']);
                add_event($id, 'excecao', 'Exceção resolvida: ' . EXCEPTIONS[$x['kind']]['label'], (string) input('resolution') ?: null);
            }
            return 'tarefas';

        case 'mensagem':
            $body = trim((string) input('body'));
            $channel = (string) input('channel', 'whatsapp');
            if ($body === '') {
                return 'mensagens';
            }
            if (input('facts_checked') !== '1') {
                flash('error', 'Confira fatos, datas e orientação jurídica antes de registrar o envio.');
                $_SESSION['draft_' . $id] = $body;
                return 'mensagens';
            }
            if (preg_match('/\[[^\]]+\]/u', $body)) {
                flash('error', 'Ainda há campos entre colchetes para completar.');
                $_SESSION['draft_' . $id] = $body;
                return 'mensagens';
            }
            insert('messages', ['case_id' => $id, 'template' => (string) input('template') ?: null, 'channel' => $channel, 'body' => $body, 'facts_checked' => 1, 'sent_by' => $u['id'], 'sent_at' => now_str()]);
            add_event($id, 'mensagem', 'Mensagem da equipe', $body, true);
            if (input('template') === 'primeiro_contato' && !$c['first_human_contact_at']) {
                touch_case($id, ['first_human_contact_at' => now_str()]);
                close_tasks_of_kind($id, 'primeiro_contato', 'Mensagem de primeiro contato');
            }
            unset($_SESSION['draft_' . $id]);
            flash('ok', 'Mensagem registrada e publicada na área do cliente.');
            return 'mensagens';

        case 'codigo_portal':
            $code = (string) random_int(100000, 999999);
            touch_case($id, ['portal_code_hash' => password_hash($code, PASSWORD_DEFAULT), 'portal_code_expires' => now()->modify('+7 days')->format('Y-m-d H:i:s')]);
            $_SESSION['portal_code_' . $id] = $code;
            audit('codigo_portal', $id);
            return 'mensagens';

        case 'dados':
            touch_case($id, [
                'client_name' => mb_substr((string) input('client_name'), 0, 120) ?: $c['client_name'],
                'client_phone' => mb_substr((string) input('client_phone'), 0, 30) ?: $c['client_phone'],
                'client_email' => mb_substr((string) input('client_email'), 0, 120) ?: null,
                'client_city' => mb_substr((string) input('client_city'), 0, 80) ?: null,
                'client_uf' => mb_substr((string) input('client_uf'), 0, 2) ?: null,
                'bank' => mb_substr((string) input('bank'), 0, 80) ?: null,
                'contact_pref' => (string) input('contact_pref') ?: null,
                'accessibility' => mb_substr((string) input('accessibility'), 0, 300) ?: null,
            ]);
            audit('dados_editados', $id);
            return 'atendimento';

        case 'nota':
            $text = trim((string) input('text'));
            if ($text !== '') {
                add_event($id, 'nota', 'Nota interna', $text);
            }
            return (string) input('back', 'historico');
    }
    flash('error', 'Ação desconhecida.');
    return 'resumo';
}

/* ---------- Indicadores (seção 22) ---------- */

function indicators(): array
{
    $cases = all('SELECT * FROM cases');
    $firstContact = [];
    $toPlan = [];
    foreach ($cases as $c) {
        if ($c['first_human_contact_at']) {
            $firstContact[] = business_hours_between(dt($c['created_at']), dt($c['first_human_contact_at']));
        }
        $audit = case_data($c, 'audit', []);
        if ($c['docs_sufficient_at'] && !empty($audit['validated_at'])) {
            $toPlan[] = business_hours_between(dt($c['docs_sufficient_at']), dt($audit['validated_at'])) / max(1, close_hour() - open_hour());
        }
    }
    $median = function (array $v): ?float {
        if (!$v) {
            return null;
        }
        sort($v);
        $n = count($v);
        return $n % 2 ? $v[intdiv($n, 2)] : ($v[$n / 2 - 1] + $v[$n / 2]) / 2;
    };
    $closed = array_filter($cases, fn($c) => $c['status'] === 'encerrado');
    $byOutcome = [];
    foreach ($closed as $c) {
        $byOutcome[$c['outcome'] ?? '—'] = ($byOutcome[$c['outcome'] ?? '—'] ?? 0) + 1;
    }
    $reopened = count(array_filter($cases, fn($c) => (int) case_data($c, 'reopened', 0) > 0));
    $ease = all('SELECT ease FROM feedback WHERE ease IS NOT NULL');
    $solutionItems = 0;
    $solutionDone = 0;
    foreach ($cases as $c) {
        foreach (case_data($c, 'solution', []) as $it) {
            if (!empty($it['applies'])) {
                $solutionItems++;
                if (!empty($it['done'])) {
                    $solutionDone++;
                }
            }
        }
    }
    $withinCycle = 0;
    $active = array_filter($cases, fn($c) => $c['status'] === 'ativo');
    foreach ($active as $c) {
        if (dt($c['last_client_update'] ?: $c['created_at']) >= now()->modify('-' . (int) config('update_cycle_days', 15) . ' days')) {
            $withinCycle++;
        }
    }
    return [
        'total' => count($cases),
        'active' => count($active),
        'closed' => count($closed),
        'urgent' => count(array_filter($active, fn($c) => (int) $c['urgent'] === 1)),
        'first_contact_median_h' => $median($firstContact),
        'first_contact_within' => $firstContact ? count(array_filter($firstContact, fn($h) => $h <= 1)) / count($firstContact) : null,
        'plan_median_days' => $median($toPlan),
        'updates_ok' => $active ? $withinCycle / count($active) : null,
        'delivery' => $solutionItems ? $solutionDone / $solutionItems : null,
        'solution_items' => $solutionItems,
        'by_outcome' => $byOutcome,
        'objective_rate' => $closed ? (($byOutcome['resolvido'] ?? 0) / count($closed)) : null,
        'reopen_rate' => $closed ? $reopened / count($closed) : null,
        'ease_avg' => $ease ? array_sum(array_column($ease, 'ease')) / count($ease) : null,
        'ease_n' => count($ease),
        'lost_deadlines' => (int) scalar("SELECT COUNT(*) FROM deadlines WHERE status = 'perdido'"),
        'repeat_requests' => (int) scalar("SELECT COUNT(*) FROM documents WHERE status = 'pendencia'"),
        'by_stage' => array_count_values(array_column($active, 'stage')),
        'by_source' => array_count_values(array_column($cases, 'source')),
    ];
}
