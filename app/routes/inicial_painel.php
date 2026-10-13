<?php
declare(strict_types=1);

/**
 * Painel do operador único (seção 11): lista de atendimentos e ficha com cinco
 * blocos. Salvar mantém Sidney na mesma ficha e posição; as travas valem para
 * confirmar atos, não para navegar.
 */

/** @var Router $router */

function require_case_operator(int $id): array
{
    $u = require_user();
    $c = find_case($id);
    if (!can_operate($c, $u)) {
        forbidden();
    }
    return $c;
}

/* ---------- Lista de atendimentos (seção 11.1) ---------- */

const ATTENDANCE_FILTERS = ['todos' => 'Todos', 'urgentes' => 'Urgentes', 'retorno' => 'Retorno hoje/vencido', 'aguardando' => 'Aguardando cliente', 'concluidos' => 'Concluídos', 'arquivados' => 'Arquivados'];

$router->get('/painel/atendimentos', function () {
    $u = require_user();
    $filter = (string) input('f', 'todos');
    $filter = isset(ATTENDANCE_FILTERS[$filter]) ? $filter : 'todos';
    $where = ["workflow_version = 'simple_v1'", 'execution_started_at IS NULL', 'operator_id = ?'];
    $params = [(int) $u['id']];
    if ($filter === 'arquivados') {
        $where[] = 'archived_at IS NOT NULL';
    } else {
        $where[] = 'archived_at IS NULL';
        $where[] = $filter === 'concluidos' ? "initial_status = 'completed'" : "initial_status <> 'completed'";
    }
    if (($term = trim((string) input('q'))) !== '') {
        $where[] = '(client_name LIKE ? OR protocol LIKE ? OR client_phone LIKE ? OR bank LIKE ? OR id IN (SELECT case_id FROM case_fields WHERE field = \'contrato_numero\' AND value LIKE ?))';
        $like = '%' . $term . '%';
        array_push($params, $like, $like, '%' . (only_digits($term) ?: '§') . '%', $like, $like);
    }
    $rows = [];
    $today = now()->format('Y-m-d 23:59:59');
    foreach (all('SELECT * FROM cases WHERE ' . implode(' AND ', $where), $params) as $c) {
        $alerts = initial_alerts($c);
        $due = $c['next_client_update'] ?: $c['next_action_due'];
        $urgent = $c['urgency_signal'] && !$c['urgency_reviewed_at'] || array_filter($alerts, fn($a) => str_starts_with($a[1], 'Prazo confirmado'));
        $waitingClient = in_array($c['initial_status'], ['collecting', 'awaiting_decision'], true) && !$c['activity_at'] || ($c['initial_status'] === 'collecting' && !$c['intake_done_at']) || ($c['initial_status'] === 'awaiting_decision' && $c['decision'] === 'pendente');
        if ($filter === 'urgentes' && !$urgent) {
            continue;
        }
        if ($filter === 'retorno' && !($due && $due <= $today)) {
            continue;
        }
        if ($filter === 'aguardando' && !$waitingClient) {
            continue;
        }
        $pend = open_pendings((int) $c['id']);
        $rows[] = [
            'c' => $c, 'alerts' => $alerts, 'urgent' => (bool) $urgent, 'due' => $due,
            'pending' => $pend[0]['description'] ?? null, 'new' => $c['activity_at'] && (!$c['activity_seen_at'] || $c['activity_at'] > $c['activity_seen_at']),
            'contract' => fv((int) $c['id'], 'contrato_numero'),
        ];
    }
    // Ordenação: urgência/prazo confirmado, retorno vencido/hoje, demais retornos, atualização.
    usort($rows, function ($a, $b) use ($today) {
        $rank = fn($r) => [$r['urgent'] ? 0 : 1, $r['new'] ? 0 : 1, $r['due'] && $r['due'] <= $today ? 0 : 1, $r['due'] ?: '9999', $r['c']['updated_at']];
        return $rank($a) <=> $rank($b);
    });
    view('panel/atendimentos', ['meta_title' => 'Atendimentos · Painel', 'rows' => $rows, 'filter' => $filter, 'u' => $u], 'panel');
});

$router->get('/painel/atendimentos/novo', function () {
    require_user();
    view('panel/atendimento_novo', ['meta_title' => 'Registrar atendimento · Painel', 'errors' => [], 'old' => []], 'panel');
});

/** Alternativa assistida: Sidney registra o que recebeu por telefone ou WhatsApp. */
$router->post('/painel/atendimentos/novo', function () {
    $u = require_user();
    if (!is_operator($u)) {
        flash('error', 'Somente o operador configurado registra atendimentos novos.');
        redirect('painel/atendimentos');
    }
    $a = ['nome' => trim((string) input('nome')), 'telefone' => (string) input('telefone'), 'email' => trim((string) input('email')), 'adaptacao' => '', 'origem' => 'painel'];
    $errors = [];
    if (mb_strlen($a['nome']) < 2) {
        $errors['nome'] = 'Informe o nome.';
    }
    if (strlen(only_digits($a['telefone'])) < 10) {
        $errors['telefone'] = 'Informe o telefone com DDD.';
    }
    if ($a['email'] !== '' && !filter_var($a['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Confira o e-mail.';
    }
    if ($errors) {
        view('panel/atendimento_novo', ['meta_title' => 'Registrar atendimento · Painel', 'errors' => $errors, 'old' => $_POST], 'panel', 422);
    }
    $c = create_initial_case($a, (string) input('source', 'whatsapp'));
    $id = (int) $c['id'];
    if (isset(NOTICE_OPTIONS[(string) input('aviso')])) {
        set_field($id, 'aviso', (string) input('aviso'), 'informado', 'sidney');
        update_urgency_signal($id);
    }
    if (($t = trim((string) input('relato'))) !== '') {
        set_field($id, 'objetivo_texto', $t, 'informado', 'sidney');
    }
    sync_intake_documents($id);
    bump_case($id, ['activity_seen_at' => now_str()]);
    audit('atendimento_registrado', $id);
    flash('ok', 'Atendimento ' . $c['protocol'] . ' registrado.');
    redirect('painel/atendimento/' . $id);
});

/* ---------- Ficha (seção 11.2) ---------- */

$router->get('/painel/atendimento/{id}', function (string $id) {
    $c = require_case_operator((int) $id);
    if (!is_initial($c)) {
        redirect('painel/caso/' . $c['id']);
    }
    $cid = (int) $c['id'];
    q('UPDATE cases SET activity_seen_at = ? WHERE id = ?', [now_str(), $cid]);
    $c = find_case($cid);
    view('panel/atendimento', [
        'meta_title' => $c['client_name'] . ' · ' . $c['protocol'],
        'c' => $c,
        'fields' => case_fields($cid, true),
        'docs' => all('SELECT * FROM documents WHERE case_id = ? ORDER BY essential DESC, id', [$cid]),
        'files' => all('SELECT * FROM files WHERE case_id = ? ORDER BY uploaded_at DESC', [$cid]),
        'pendings' => all('SELECT * FROM case_pending_items WHERE case_id = ? ORDER BY status = \'aberta\' DESC, id DESC', [$cid]),
        'open' => open_pendings($cid),
        'alerts' => initial_alerts($c),
        'meetings' => all('SELECT * FROM case_meetings WHERE case_id = ? ORDER BY id DESC', [$cid]),
        'meeting' => current_meeting($cid),
        'kit' => kit_status($c),
        'versions' => all('SELECT * FROM case_artifact_versions WHERE case_id = ? ORDER BY kind, version DESC', [$cid]),
        'chat' => all('SELECT * FROM chat_messages WHERE case_id = ? ORDER BY id', [$cid]),
        'events' => all('SELECT * FROM events WHERE case_id = ? ORDER BY at DESC, id DESC LIMIT 60', [$cid]),
        'deadlines' => all("SELECT * FROM deadlines WHERE case_id = ? ORDER BY status, due_at", [$cid]),
        'q' => bot_current($c),
        'prev' => $c['previous_case_id'] ? one('SELECT id, protocol, initial_status FROM cases WHERE id = ?', [$c['previous_case_id']]) : null,
        'dup' => $c['duplicate_of'] ? one('SELECT id, protocol, client_name FROM cases WHERE id = ?', [$c['duplicate_of']]) : null,
        'next_cases' => all('SELECT id, protocol FROM cases WHERE previous_case_id = ?', [$cid]),
        'code' => $_SESSION['portal_code_' . $cid] ?? null,
    ], 'panel');
});

$router->get('/painel/atendimento/{id}/conversa/novas', function (string $id) {
    $c = require_case_operator((int) $id);
    $after = (int) input('depois');
    $msgs = all('SELECT * FROM chat_messages WHERE case_id = ? AND id > ? ORDER BY id', [$c['id'], $after]);
    if ($msgs) {
        q('UPDATE cases SET activity_seen_at = ? WHERE id = ?', [now_str(), $c['id']]);
    }
    json_out(['html' => render('partials/chat_msgs', ['msgs' => $msgs, 'operator' => true, 'c' => $c]), 'last' => $msgs ? (int) end($msgs)['id'] : $after]);
});

$router->get('/painel/atendimento/{id}/kit/{vid}', function (string $id, string $vid) {
    $c = require_case_operator((int) $id);
    $v = kit_version($c, (int) $vid) ?? not_found();
    if ($v['status'] === 'nao_aplicavel') {
        not_found();
    }
    if (input('baixar') !== '') {
        kit_download($c, $v);
    }
    kit_page($c, $v, url('painel/atendimento/' . $c['id'] . '/kit/' . $v['id'], ['baixar' => 'docx']), false);
});

$router->post('/painel/atendimento/{id}', function (string $id) {
    $c = require_case_operator((int) $id);
    $anchor = initial_panel_action($c, (string) input('op'));
    redirect('painel/atendimento/' . $c['id'] . '#' . $anchor);
});

/* Gestor e administrador: fechar, arquivar, desarquivar e excluir o atendimento. */
$router->get('/painel/atendimento/{id}/caso.md', function (string $id) {
    require_user();
    $c = require_case_operator((int) $id);
    case_context_refresh((int) $c['id']);
    header('Content-Type: text/markdown; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $c['protocol'] . '.md"');
    echo case_markdown((int) $c['id']);
    exit;
});

$router->post('/painel/atendimento/{id}/drive', function (string $id) {
    require_user();
    $c = require_case_operator((int) $id);
    @set_time_limit(300);
    case_context_refresh((int) $c['id']);
    [$n, $err] = drive_sync_case((int) $c['id']);
    flash($err ? 'error' : 'ok', $err ? 'Google Drive: ' . $err : 'Gravado no Drive: ' . plural($n, 'arquivo novo', 'arquivos novos') . ' e o resumo ' . $c['protocol'] . '.md.');
    redirect('painel/atendimento/' . $c['id'] . '#arquivo-caso');
});

$router->post('/painel/atendimento/{id}/gestao', function (string $id) {
    $u = require_user();
    $c = require_case_operator((int) $id);
    if (!can_manage_cases()) {
        flash('error', 'Fechar, arquivar e excluir atendimentos é do gestor ou do administrador.');
        redirect('painel/atendimento/' . $c['id']);
    }
    switch ((string) input('op')) {
        case 'fechar':
            $err = case_close($c, (string) input('motivo'), (int) $u['id']);
            flash($err ? 'error' : 'ok', $err ?? 'Atendimento fechado. O cliente vê que foi encerrado.');
            break;
        case 'arquivar':
            case_archive($c, true, (int) $u['id']);
            flash('ok', 'Atendimento arquivado: saiu das listas. Ele continua acessível pelo filtro “Arquivados”.');
            redirect('painel/atendimentos');
        case 'desarquivar':
            case_archive($c, false, (int) $u['id']);
            flash('ok', 'Atendimento desarquivado.');
            break;
        case 'excluir':
            $err = case_delete($c, (string) input('confirmar'), (int) $u['id']);
            if (!$err) {
                flash('ok', 'Atendimento ' . $c['protocol'] . ' excluído.');
                redirect('painel/atendimentos');
            }
            flash('error', $err);
            break;
        default:
            flash('error', 'Ação desconhecida.');
    }
    redirect('painel/atendimento/' . $c['id'] . '#gestao');
});

/** Executa uma ação da ficha e devolve a âncora do bloco. */
function initial_panel_action(array $c, string $op): string
{
    $id = (int) $c['id'];
    $msg = fn(?string $err, string $ok) => flash($err ? 'error' : 'ok', $err ?: $ok);
    switch ($op) {
        case 'dados':
            if (version_conflict($c)) {
                flash('error', 'Os dados mudaram enquanto você editava (cliente ou assistente). Nada foi sobrescrito: confira os valores atuais e salve de novo.');
                return 'dados';
            }
            $source = in_array(input('source'), ['contrato', 'sidney', 'cliente'], true) ? (string) input('source') : 'sidney';
            $errs = [];
            $changed = 0;
            foreach ((array) ($_POST['f'] ?? []) as $key => $raw) {
                $def = field_def((string) $key);
                if (!$def) {
                    continue;
                }
                $state = (string) ($_POST['st'][$key] ?? 'informado');
                $raw = trim((string) $raw);
                $cur = case_fields($id)[$key] ?? null;
                if ($state === 'informado' && $raw === '') {
                    continue; // campo vazio na tela: não apaga silenciosamente
                }
                if ($state !== 'informado' && $cur && $cur['state'] === $state) {
                    continue;
                }
                if ($state === 'informado' && $cur && field_input_value((string) $key, $cur) === $raw) {
                    continue;
                }
                $src = !empty($def['sidney']) ? 'sidney' : $source;
                if ($e = set_field($id, (string) $key, $raw, $state, $src)) {
                    $errs[] = $def['label'] . ': ' . $e;
                } else {
                    $changed++;
                }
            }
            foreach (['aviso', 'processo_existente', 'objetivo_prioridade', 'gratuidade', 'aviso_data'] as $k) {
                if (isset($_POST['f'][$k])) {
                    bot_after_answer($id, $k);
                }
            }
            bump_case($id, []);
            flash($errs ? 'error' : 'ok', $errs ? implode(' ', $errs) : ($changed ? 'Dados salvos.' : 'Nada mudou.'));
            return (string) input('anchor', 'dados');

        case 'analise':
        case 'aprovar_preparacao':
            if (version_conflict($c)) {
                flash('error', 'A ficha mudou enquanto você editava. Nada foi sobrescrito: confira e salve de novo.');
                return 'analise';
            }
            $errs = [];
            foreach ((array) ($_POST['f'] ?? []) as $key => $raw) {
                $def = field_def((string) $key);
                if (!$def || empty($def['sidney'])) {
                    continue;
                }
                $raw = trim((string) $raw);
                $cur = case_fields($id)[$key] ?? null;
                if ($raw === '') {
                    if ($cur && $cur['state'] === 'informado') {
                        q('DELETE FROM case_fields WHERE case_id = ? AND field = ?', [$id, $key]);
                        case_fields($id, true);
                    }
                    continue;
                }
                if ($cur && field_input_value((string) $key, $cur) === $raw) {
                    continue;
                }
                if ($e = set_field($id, (string) $key, $raw, 'informado', 'sidney')) {
                    $errs[] = $def['label'] . ': ' . $e;
                }
            }
            if ($errs) {
                flash('error', implode(' ', $errs));
                return 'analise';
            }
            if ($op === 'aprovar_preparacao') {
                $msg(initial_command(find_case($id), 'aprovar_preparacao', $_POST), 'Análise e proposta validadas. A reunião pode ser confirmada.');
            } else {
                bump_case($id, []);
                flash('ok', 'Análise salva (preliminar).');
            }
            return 'analise';

        case 'urgencia_revisar':
            $op = trim((string) input('opinion'));
            if ($op === '') {
                flash('error', 'Registre um parecer breve sobre a urgência.');
                return 'dados';
            }
            bump_case($id, ['urgency_reviewed_at' => now_str(), 'urgency_opinion' => mb_substr($op, 0, 1000), 'urgent' => input('confirmed') === '1' ? 1 : 0]);
            if (($due = (string) input('due_at')) !== '' && ($d = dt(str_replace('T', ' ', $due)))) {
                insert('deadlines', ['case_id' => $id, 'description' => mb_substr(trim((string) input('deadline')) ?: 'Prazo confirmado por Sidney', 0, 200), 'trigger_event' => $c['urgency_reason'], 'due_at' => $d->format('Y-m-d H:i:s'), 'owner_id' => $c['operator_id'], 'status' => 'aberto']);
            }
            add_event($id, 'urgencia', 'Urgência conferida por Sidney', $op);
            flash('ok', 'Urgência conferida.');
            return 'dados';

        case 'prazo_cumprido':
            q("UPDATE deadlines SET status = 'cumprido', done_at = ? WHERE id = ? AND case_id = ?", [now_str(), (int) input('deadline_id'), $id]);
            return 'dados';

        case 'proxima':
            $due = dt(str_replace('T', ' ', (string) input('due')));
            $ret = dt(str_replace('T', ' ', (string) input('client_return')));
            if (trim((string) input('action')) === '' || !$due) {
                flash('error', 'Informe a próxima ação e a data.');
                return 'topo';
            }
            set_next($id, trim((string) input('action')), $due->format('Y-m-d H:i:s'), $ret ? $ret->format('Y-m-d H:i:s') : null);
            flash('ok', 'Próxima ação atualizada.');
            return 'topo';

        case 'pendencia_add':
            $desc = trim((string) input('description'));
            if ($desc === '') {
                flash('error', 'Descreva a pendência.');
                return 'documentos';
            }
            add_pending($id, (string) input('type'), $desc, (string) input('criticality'));
            flash('ok', 'Pendência registrada.');
            return 'documentos';

        case 'pendencia_status':
            $st = (string) input('status');
            if (isset(PENDING_STATUS[$st])) {
                q('UPDATE case_pending_items SET status = ?, criticality = COALESCE(?, criticality), resolved_at = ?, note = COALESCE(?, note) WHERE id = ? AND case_id = ?', [
                    $st, in_array(input('criticality'), ['essencial', 'pontual'], true) ? input('criticality') : null, $st === 'aberta' ? null : now_str(),
                    trim((string) input('note')) ?: null, (int) input('item_id'), $id,
                ]);
                bump_case($id, []);
            }
            return (string) input('anchor', 'documentos');

        case 'doc_conferir':
            $doc = one('SELECT * FROM documents WHERE id = ? AND case_id = ?', [(int) input('doc_id'), $id]);
            $st = (string) input('status');
            if (!$doc || !in_array($st, ['conferido', 'pendencia', 'dispensado', 'falta', 'ajuda'], true)) {
                return 'documentos';
            }
            $reason = trim((string) input('reason'));
            if ($st === 'pendencia' && $reason === '') {
                flash('error', 'Diga em palavras simples por que precisa reenviar.');
                return 'documentos';
            }
            q('UPDATE documents SET status = ?, reject_reason = ?, essential = ?, reviewed_at = ?, reviewed_by = ? WHERE id = ?', [
                $st, $st === 'pendencia' ? mb_substr($reason, 0, 200) : null, input('essential') === '1' ? 1 : 0,
                in_array($st, ['conferido', 'pendencia'], true) ? now_str() : $doc['reviewed_at'], current_user()['id'], $doc['id'],
            ]);
            q('UPDATE files SET reviewed_at = ? WHERE document_id = ? AND reviewed_at IS NULL', [now_str(), $doc['id']]);
            if ($st === 'pendencia') {
                $later = case_data($c, 'doc_later', []);
                unset($later[$doc['kind']]);
                set_case_data($id, 'doc_later', $later);
                add_event($id, 'documento', 'Precisamos de nova cópia: ' . $doc['label'], $reason, true);
            } elseif ($st === 'conferido') {
                add_event($id, 'documento', 'Conferido: ' . $doc['label'], null, true);
            }
            bump_case($id, []);
            flash('ok', 'Documento atualizado.');
            return 'doc-' . $doc['id'];

        case 'doc_add':
            $kind = (string) input('kind');
            $label = trim((string) input('label'));
            if (isset(DOC_CATALOG[$kind])) {
                $label = DOC_CATALOG[$kind]['label'];
            } elseif ($label === '') {
                flash('error', 'Escolha o tipo ou descreva o documento.');
                return 'documentos';
            } else {
                $kind = 'outro_' . substr(sha1($label . microtime()), 0, 6);
            }
            if (scalar('SELECT 1 FROM documents WHERE case_id = ? AND kind = ?', [$id, $kind])) {
                flash('error', 'Este documento já está no checklist.');
                return 'documentos';
            }
            insert('documents', ['case_id' => $id, 'kind' => $kind, 'label' => mb_substr($label, 0, 150), 'status' => 'falta', 'essential' => input('essential') === '1' ? 1 : 0, 'requested_at' => now_str(), 'origin' => 'sidney']);
            flash('ok', 'Documento incluído no checklist.');
            return 'documentos';

        case 'doc_upload':
            $doc = one('SELECT * FROM documents WHERE id = ? AND case_id = ?', [(int) input('doc_id'), $id]);
            $files = uploaded_files('arquivo');
            if (!$doc || !$files) {
                flash('error', 'Escolha o documento e o arquivo.');
                return 'documentos';
            }
            foreach ($files as $f) {
                $r = store_case_file($f, $id, (int) $doc['id'], false);
                if (is_string($r)) {
                    flash('error', $r);
                    return 'documentos';
                }
            }
            $origin = trim((string) input('origin')) ?: 'obtido por Sidney';
            q("UPDATE documents SET status = 'recebido', received_at = ?, origin = ?, reject_reason = NULL WHERE id = ?", [now_str(), mb_substr($origin, 0, 80), $doc['id']]);
            add_event($id, 'documento', 'Arquivo registrado por Sidney: ' . $doc['label'], 'Origem: ' . $origin);
            if ($c['preparation_at'] && in_array($doc['kind'], ['contrato', 'historico'], true)) {
                bump_case($id, ['review_flag' => 'analise']);
            } else {
                bump_case($id, []);
            }
            flash('ok', 'Arquivo registrado. Confira para concluir.');
            return 'doc-' . $doc['id'];

        case 'arquivo_interno':
            $kind = in_array(input('kind'), ['memoria', 'assinado', 'nota'], true) ? (string) input('kind') : 'nota';
            $files = uploaded_files('arquivo');
            if (!$files) {
                flash('error', 'Escolha o arquivo.');
                return $kind === 'assinado' ? 'kit' : 'analise';
            }
            foreach ($files as $f) {
                $r = store_case_file($f, $id, null, false, null, $kind, $kind !== 'assinado');
                if (is_string($r)) {
                    flash('error', $r);
                    return 'analise';
                }
            }
            add_event($id, 'arquivo', $kind === 'memoria' ? 'Memória de cálculo anexada' : ($kind === 'assinado' ? 'Instrumento assinado anexado' : 'Arquivo interno anexado'));
            flash('ok', 'Arquivo anexado.');
            return $kind === 'assinado' ? 'kit' : 'analise';

        case 'pedir_complemento':
            $items = [];
            foreach ((array) ($_POST['docs'] ?? []) as $did) {
                if ($d = one('SELECT label FROM documents WHERE id = ? AND case_id = ?', [(int) $did, $id])) {
                    $items[] = $d['label'];
                }
            }
            foreach ((array) ($_POST['items'] ?? []) as $pid) {
                if ($p = one("SELECT description FROM case_pending_items WHERE id = ? AND case_id = ? AND status = 'aberta'", [(int) $pid, $id])) {
                    $items[] = $p['description'];
                }
            }
            if (($extra = trim((string) input('extra'))) !== '') {
                add_pending($id, 'esclarecimento', $extra, (string) input('criticality', 'pontual'));
                $items[] = $extra;
            }
            $ret = parse_br_date((string) input('return_at'));
            if (!$items || !$ret) {
                flash('error', 'Escolha o que falta e a data do próximo retorno.');
                return 'documentos';
            }
            $text = 'Para continuar, falta apenas: ' . implode('; ', $items) . '. Você pode enviar aqui. Se não tiver algum item, me avise.';
            chat_post($id, 'operator', $text);
            add_event($id, 'complemento', 'Pedido de complemento', $text, true);
            set_next($id, 'Conferir o complemento pedido', $ret . ' 18:00:00', $ret . ' 18:00:00');
            bump_case($id, ['client_next_action' => 'Enviar: ' . mb_substr(implode('; ', $items), 0, 180)]);
            flash('ok', 'Pedido de complemento enviado na conversa do cliente.');
            return 'documentos';

        case 'liberar_analise':
        case 'confirmar_reuniao':
        case 'registrar_reuniao':
        case 'reanalisar':
        case 'registrar_decisao':
        case 'finalizar':
        case 'encerrar_antes':
        case 'comunicar_encerramento':
            $ok = [
                'liberar_analise' => 'Suficiência aprovada. Atendimento em análise.',
                'confirmar_reuniao' => 'Reunião confirmada e comunicada ao cliente.',
                'registrar_reuniao' => 'Reunião registrada.',
                'reanalisar' => 'Atendimento voltou para análise.',
                'registrar_decisao' => 'Decisão registrada.',
                'finalizar' => 'Atendimento inicial concluído.',
                'encerrar_antes' => 'Atendimento inicial encerrado.',
                'comunicar_encerramento' => 'Comunicação registrada; a carência de acesso começou.',
            ][$op];
            $msg(initial_command($c, $op, $_POST), $ok);
            return match ($op) {
                'liberar_analise' => 'documentos',
                'confirmar_reuniao', 'registrar_reuniao' => 'reuniao',
                'reanalisar' => 'analise',
                default => 'kit',
            };

        case 'contrato':
            $file = (int) input('signed_file_id');
            if ($file === 0 && ($files = uploaded_files('arquivo'))) {
                $r = store_case_file($files[0], $id, null, false, null, 'assinado', false);
                if (is_string($r)) {
                    flash('error', $r);
                    return 'kit';
                }
                $file = $r;
            }
            $in = $_POST;
            $in['signed_file_id'] = $file;
            $msg(initial_command($c, 'contrato', $in), 'Situação do contrato atualizada.');
            return 'kit';

        case 'kit_gerar':
            $msg(kit_generate($c, (string) input('kind')), 'Minuta gerada com os dados atuais.');
            return 'kit-' . input('kind');
        case 'kit_editar':
            $msg(kit_edit($c, (int) input('vid'), (string) ($_POST['content'] ?? '')), 'Minuta salva. Revise de novo antes de publicar.');
            return 'kit-' . input('kind');
        case 'kit_revisar':
        case 'kit_publicar':
            // Versão final (revisada ou publicada): o .docx vai para a pasta do atendimento no Drive.
            $err = $op === 'kit_revisar' ? kit_review($c, (int) input('vid')) : kit_publish($c, (int) input('vid'));
            if (!$err && ($v = kit_version($c, (int) input('vid')))) {
                after_response(fn() => drive_save_kit(find_case($id), $v));
            }
            $msg($err, ($op === 'kit_revisar' ? 'Versão final revisada.' : 'Documento publicado para o cliente.') . (drive_enabled() ? ' O .docx vai para a pasta do atendimento no Drive.' : ''));
            return 'kit-' . input('kind');

        case 'doc_exigir':
            $doc = one('SELECT * FROM documents WHERE id = ? AND case_id = ?', [(int) input('doc_id'), $id]);
            if ($doc) {
                $on = input('essential') === '1';
                q('UPDATE documents SET essential = ? WHERE id = ?', [$on ? 1 : 0, $doc['id']]);
                add_event($id, 'documento', ($on ? 'Passou a ser exigido: ' : 'Deixou de ser exigido: ') . $doc['label']);
                bump_case($id, []);
                flash('ok', $doc['label'] . ($on ? ': exigido.' : ': opcional.'));
            }
            return 'documentos';

        case 'pedir_dados':
            $keys = array_values(array_intersect(critical_missing($id), (array) ($_POST['keys'] ?? [])));
            if (!$keys) {
                flash('error', 'Marque o que quer perguntar.');
                return 'resumo';
            }
            chat_post($id, 'operator', critical_question($c, $keys));
            set_next($id, 'Aguardar dados do cliente: ' . implode(', ', array_map(fn($k) => mb_strtolower(field_def($k)['label']), array_slice($keys, 0, 4))), in_business_days(1), in_business_days(2));
            add_event($id, 'pendencia', 'Pedido de dados ao cliente pela conversa do site', implode(', ', array_map(fn($k) => field_def($k)['label'], $keys)) . '.');
            flash('ok', 'Pergunta enviada na conversa do site. O cliente recebe o aviso por e-mail.');
            return 'resumo';

        case 'contexto_ia':
            $r = case_context_refresh($id);
            flash($r ? 'ok' : 'error', $r ? 'Contexto do caso atualizado.' : 'Não foi possível atualizar com a IA agora.');
            return 'resumo';

        case 'parecer_ia':
            @set_time_limit(240);
            [$done, $find, $aviso] = parecer_fill($id);
            flash($done || $find ? 'ok' : 'error', ($done ? 'Preenchidos: ' . implode(', ', array_map(fn($k) => field_def($k)['label'], $done)) . '. ' : '')
                . ($find ? count($find) . ' a localizar (veja a explicação de cada um). ' : '') . ($aviso ?: '') ?: 'Nada a preencher.');
            return 'parecer-ia';

        case 'parecer_comando':
            @set_time_limit(240);
            [$done, $err] = parecer_command($id, (string) input('comando'));
            flash($err && !$done ? 'error' : 'ok', ($done ? 'Alterados pela IA: ' . implode(', ', array_map(fn($k) => field_def($k)['label'], $done)) . '. Confira. ' : '') . ($err ?? ''));
            return 'parecer-ia';

        case 'ia_sugerir':
            [$done, $err] = analysis_ai_suggest($id);
            flash($err ? 'error' : 'ok', $err ?: 'A IA sugeriu ' . plural(count($done), 'campo', 'campos') . ' (marcados “IA · conferir”). Revise antes de validar.');
            return 'analise';
        case 'kit_na':
            $msg(kit_not_applicable($c, (string) input('kind'), (string) input('reason')), 'Marcado como não aplicável.');
            return 'kit-' . input('kind');

        case 'chat_enviar':
            $text = trim((string) input('message'));
            if ($text !== '') {
                chat_post($id, 'operator', $text, null, (string) input('k') ?: null);
                bump_case($id, ['activity_seen_at' => now_str()]);
            }
            if (input('ajax') === '1') {
                json_out(['ok' => true]);
            }
            return 'conversa';

        case 'chat_modo':
            $mode = input('mode') === 'bot' ? 'bot' : 'human';
            bump_case($id, ['chat_mode' => $mode, 'intake_edit' => null]);
            add_event($id, 'conversa', $mode === 'human' ? 'Sidney assumiu a conversa' : 'Coleta automática retomada');
            chat_post($id, $mode === 'human' ? 'operator' : 'bot', $mode === 'human'
                ? 'Olá, aqui é o Sidney. A partir de agora sou eu que converso com você por aqui.'
                : 'Vou retomar a organização das informações que ainda faltam.');
            return 'conversa';

        case 'duplicidade_ok':
            bump_case($id, ['duplicate_of' => null]);
            add_event($id, 'duplicidade', 'Sidney conferiu a possível duplicidade', trim((string) input('note')) ?: null);
            return 'topo';

        case 'novo_vinculado':
            if (!$c['account_id'] && input('confirm') !== '1') {
                flash('error', 'Confirme a identidade do cliente antes de abrir o novo atendimento.');
                return 'kit';
            }
            if ($c['account_id'] && ($cur = one("SELECT protocol FROM cases WHERE account_id = ? AND workflow_version = 'simple_v1' AND execution_started_at IS NULL AND initial_status <> 'completed'", [$c['account_id']]))) {
                flash('error', 'O cliente já tem um atendimento pré-contratual atual (' . $cur['protocol'] . ').');
                return 'kit';
            }
            $n = create_initial_case(['nome' => $c['client_name'], 'telefone' => (string) $c['client_phone'], 'email' => (string) $c['client_email'], 'adaptacao' => (string) $c['accessibility'], 'origem' => 'retorno'], 'retorno', $c['account_id'] ? (int) $c['account_id'] : null, $id);
            foreach (case_fields($id) as $k => $r) {
                $def = field_def($k);
                if ($def && empty($def['sidney']) && !field_answered((int) $n['id'], $k) && $r['state'] === 'informado' && in_array($def['g'], ['qualificacao', 'endereco', 'relacao', 'financiamento'], true)) {
                    q('INSERT INTO case_fields (case_id, field, value, state, source, updated_at) VALUES (?,?,?,?,?,?)', [$n['id'], $k, $r['value'], 'informado', 'anterior', now_str()]);
                }
            }
            sync_intake_documents((int) $n['id']);
            bump_case($id, ['return_requested_at' => null]);
            add_event($id, 'vinculo', 'Novo atendimento aberto: ' . $n['protocol']);
            flash('ok', 'Novo atendimento ' . $n['protocol'] . ' aberto e vinculado. Os dados trazidos precisam de confirmação de atualidade.');
            redirect('painel/atendimento/' . $n['id']);

        case 'ia_extrair':
            $doc = one('SELECT * FROM documents WHERE id = ? AND case_id = ?', [(int) input('doc_id'), $id]);
            if (!$doc || !llm_enabled()) {
                flash('error', 'IA não configurada (Configurações) ou documento inválido.');
                return 'documentos';
            }
            $r = ia_extract_document($id, (int) $doc['id']);
            flash($r ? 'ok' : 'error', $r ? 'IA leu o documento: ' . count($r['filled']) . ' campo(s) preenchido(s)' . ($r['divergent'] ? ', ' . count($r['divergent']) . ' divergência(s) no histórico' : '') . '.' : 'A IA não conseguiu ler este documento. Veja o histórico.');
            return 'doc-' . $doc['id'];

        case 'ia_conferir':
            $g = (string) input('group');
            $keys = array_keys(array_filter(field_defs(), fn($d) => $d['g'] === $g));
            if ($keys) {
                $in = implode(',', array_fill(0, count($keys), '?'));
                $n = q("UPDATE case_fields SET source = 'contrato', updated_by = ?, updated_at = ? WHERE case_id = ? AND source = 'ia' AND field IN ($in)", array_merge([current_user()['id'], now_str(), $id], $keys))->rowCount();
                case_fields($id, true);
                add_event($id, 'campo', 'Dados extraídos pela IA conferidos por Sidney', FIELD_GROUPS[$g] . ': ' . $n . ' campo(s).');
                flash('ok', 'Dados confirmados como conferidos no documento.');
            }
            return 'g-' . $g;

        case 'codigo_portal':
            $code = random_code(8, '0123456789');
            bump_case($id, ['portal_code_hash' => password_hash($code, PASSWORD_DEFAULT), 'portal_code_expires' => now()->modify('+7 days')->format('Y-m-d H:i:s')]);
            $_SESSION['portal_code_' . $id] = $code;
            add_event($id, 'portal', 'Código de acesso gerado (válido por 7 dias)');
            return 'topo';
    }
    flash('error', 'Ação desconhecida.');
    return 'topo';
}

/* ---------- Modelos de documentos (sem editar PHP) ---------- */

$router->get('/painel/modelos-documentos', function () {
    require_user();
    seed_templates();
    $templates = all('SELECT * FROM doc_templates ORDER BY key');
    $key = (string) (input('modelo') ?: input('editar'));
    $cur = array_values(array_filter($templates, fn($t) => $t['key'] === $key))[0] ?? ($templates[0] ?? null);
    $caseId = (int) input('caso');
    $case = $caseId ? find_case_or_null($caseId) : null;
    $labels = template_var_labels();
    $simVars = [];
    if ($cur) {
        $vals = $case ? kit_vars((int) $case['id']) : [];
        foreach (template_used_vars($cur['body']) as $k) {
            if (isset($labels[$k])) {
                $simVars[$k] = ['label' => $labels[$k], 'value' => $vals[$k] ?? null];
            }
        }
    }
    view('panel/modelos_documentos', [
        'meta_title' => 'Modelos de documentos · Painel',
        'templates' => $templates,
        't' => $cur,
        'vars' => $labels,
        'case' => $case,
        'simVars' => $simVars,
        'cases' => all("SELECT id, protocol, client_name, created_at, account_id, bank FROM cases WHERE workflow_version = 'simple_v1' AND archived_at IS NULL ORDER BY id DESC LIMIT 60"),
    ], 'panel');
});

/**
 * Simulação ao vivo: o texto em edição (ainda não salvo) com os dados de um
 * atendimento e os ajustes digitados na tela. Nada é gravado.
 */
$router->post('/painel/modelos-documentos/{key}/simular', function (string $key) {
    require_user();
    $t = doc_template($key) ?? not_found();
    $body = str_replace("\r\n", "\n", (string) ($_POST['body'] ?? $t['body']));
    $caseId = (int) input('caso');
    $case = $caseId ? find_case_or_null($caseId) : null;
    if ($case && !can_operate($case, current_user())) {
        forbidden();
    }
    $vars = $case ? kit_vars((int) $case['id']) : [];
    $vars['kit.versao'] = 'simulação';
    $labels = template_var_labels();
    foreach ((array) ($_POST['v'] ?? []) as $k => $v) {
        $v = trim(mb_substr((string) $v, 0, 2000));
        if (isset($labels[(string) $k]) && $v !== '') {
            $vars[(string) $k] = $v;
        }
    }
    [$content] = render_template($body, $vars);
    $zoom = max(0.4, min(1.2, (float) input('zoom', '0.75')));
    header('Cache-Control: private, no-store');
    $html = doc_page($t['title'] . ' · simulação', doc_blocks($content), [], $case ? 'SIMULAÇÃO' : 'MODELO');
    echo str_replace('</head>', '<style>body{zoom:' . $zoom . '}.barra{display:none!important}</style></head>', $html);
    exit;
});

/** Prévia do modelo no timbrado, com as variáveis à mostra (destacadas). */
$router->get('/painel/modelos-documentos/{key}/previa', function (string $key) {
    require_user();
    $t = doc_template($key) ?? not_found();
    $body = preg_replace_callback('/\{\{\s*flex:([^}\/]*)\/([^}]*)\}\}/u', fn($m) => flex_word(trim($m[1]), trim($m[2]), null), $t['body']);
    header('Cache-Control: private, no-store');
    echo doc_page($t['title'] . ' · modelo v' . $t['version'], doc_blocks($body), ['Imprimir / PDF' => 'print'], 'MODELO');
    exit;
});

$router->post('/painel/modelos-documentos', function () {
    $u = require_user();
    if (!is_operator($u) && !has_role('supervisor')) {
        forbidden();
    }
    $key = (string) input('key');
    $t = doc_template($key) ?? not_found();
    if (input('op') === 'aprovar') {
        q('UPDATE doc_templates SET approved_at = ?, approved_by = ? WHERE key = ?', [now_str(), $u['id'], $key]);
        audit('modelo_aprovado', null, $key . ' v' . $t['version']);
        flash('ok', 'Modelo aprovado. Documentos gerados com esta versão podem ser publicados.');
    } else {
        $body = str_replace("\r\n", "\n", (string) ($_POST['body'] ?? ''));
        if (trim($body) === '') {
            flash('error', 'O modelo não pode ficar vazio.');
            redirect('painel/modelos-documentos?modelo=' . $key);
        }
        [, $missing] = render_template($body, array_fill_keys(array_keys(template_var_labels()), 'x'));
        $bad = array_filter($missing, fn($m) => str_starts_with($m, 'variável não aprovada'));
        if ($bad) {
            flash('error', 'Variáveis não reconhecidas: ' . implode(', ', $bad) . '. O texto não foi salvo.');
            $_SESSION['modelo_rascunho'][$key] = $body;
            redirect('painel/modelos-documentos?modelo=' . $key . (input('caso') ? '&caso=' . (int) input('caso') : ''));
        }
        if ($body !== $t['body']) {
            q('UPDATE doc_templates SET body = ?, title = ?, version = version + 1, approved_at = NULL, approved_by = NULL, updated_at = ? WHERE key = ?', [$body, mb_substr(trim((string) input('title')) ?: $t['title'], 0, 120), now_str(), $key]);
            audit('modelo_editado', null, $key);
            flash('ok', 'Nova versão do modelo salva. Revise e aprove para liberar a publicação.');
        } else {
            flash('ok', 'Nada mudou no texto.');
        }
    }
    redirect('painel/modelos-documentos?modelo=' . $key . (input('caso') ? '&caso=' . (int) input('caso') : ''));
});

/* ---------- Propostas (condições negociadas por atendimento) ---------- */

/** Volta para a aba em que a proposta foi salva. */
function proposal_tab_hash(): string
{
    $aba = (string) input('aba');
    return in_array($aba, ['produto', 'simulacao', 'contrato'], true) ? '#' . $aba : '';
}

$router->get('/painel/propostas', function () {
    $u = require_user();
    $rows = all("SELECT p.*, c.protocol, c.client_name, c.initial_status FROM proposals p JOIN cases c ON c.id = p.case_id
                 WHERE c.workflow_version = 'simple_v1' AND c.operator_id = ? AND c.archived_at IS NULL ORDER BY p.updated_at DESC", [$u['id']]);
    $cases = all("SELECT id, protocol, client_name FROM cases WHERE workflow_version = 'simple_v1' AND execution_started_at IS NULL AND operator_id = ?
                  AND initial_status <> 'completed' AND archived_at IS NULL AND id NOT IN (SELECT case_id FROM proposals) ORDER BY id DESC", [$u['id']]);
    view('panel/propostas', ['meta_title' => 'Propostas · Painel', 'rows' => $rows, 'cases' => $cases], 'panel');
});

$router->post('/painel/propostas', function () {
    $c = require_case_operator((int) input('case_id'));
    redirect('painel/propostas/' . $c['id']);
});

$router->get('/painel/propostas/{id}', function (string $id) {
    $c = require_case_operator((int) $id);
    $pr = proposal_get((int) $c['id']);
    view('panel/proposta', ['meta_title' => 'Proposta · ' . $c['protocol'], 'c' => $c, 'pr' => $pr, 'd' => $pr['data'] ?? proposal_defaults(), 'errors' => []], 'panel');
});

$router->post('/painel/propostas/{id}', function (string $id) {
    $u = require_user();
    $c = require_case_operator((int) $id);
    [$d, $errors] = proposal_from_input($_POST);
    if ($errors) {
        view('panel/proposta', ['meta_title' => 'Proposta · ' . $c['protocol'], 'c' => $c, 'pr' => proposal_get((int) $c['id']), 'd' => $d, 'errors' => $errors, 'g' => proposal_guide_from_input($_POST)], 'panel');
        return;
    }
    proposal_save((int) $c['id'], $d, (int) $u['id']);
    proposal_guide_save((int) $c['id'], proposal_guide_from_input($_POST), (int) $u['id']);
    flash('ok', 'Proposta salva. Para o contrato usar estas condições, gere uma nova versão no kit do atendimento.');
    redirect('painel/propostas/' . $c['id'] . proposal_tab_hash());
});

// Simulação e prévia das cláusulas, refeitas a cada alteração do formulário (nada é salvo).
$router->post('/painel/propostas/{id}/previa', function (string $id) {
    $c = require_case_operator((int) $id);
    [$d, $errors] = proposal_from_input($_POST);
    json_out([
        'simulacao' => render('panel/_proposta_simulacao', ['d' => $d]),
        'vendas' => render('panel/_proposta_vendas', ['d' => $d, 'opt' => sales_sim_options($_POST, $d)]),
        'kpis' => render('panel/_proposta_kpis', ['c' => $c, 'd' => $d, 'g' => array_key_exists('base_manual', $_POST) ? proposal_guide_from_input($_POST) : proposal_guide((int) $c['id']), 'ev' => negotiation_history((int) $c['id'], 1)[0] ?? null, 'opt' => sales_sim_options($_POST, $d)]),
        'auto' => $auto = proposal_auto_texts($d),
        'auto_html' => array_map('proposal_editor_html', $auto),
        'clausulas' => proposal_clause_preview((int) $c['id'], $d),
        'erros' => $errors,
    ]);
});

/*
 * Avaliação de preço pela IA a partir do produto, do perfil do cliente e da
 * proposta na tela (salva junto, se estiver completa).
 */
$router->post('/painel/propostas/{id}/avaliar', function (string $id) {
    $u = require_user();
    $c = require_case_operator((int) $id);
    @set_time_limit(180);
    $g = proposal_guide_from_input($_POST);
    proposal_guide_save((int) $c['id'], $g, (int) $u['id']);
    $d = null;
    if (isset($_POST['pagamento_tipo'])) {
        [$d, $errors] = proposal_from_input($_POST);
        if (!$errors) {
            proposal_save((int) $c['id'], $d, (int) $u['id']);
        }
    }
    $d ??= proposal_get((int) $c['id'])['data'] ?? proposal_defaults();
    [$ok, $r] = negotiation_evaluate($c, $d, $g, (int) $u['id']);
    flash($ok ? 'ok' : 'error', $ok ? 'Avaliação pronta: veja a sugestão no painel.' : $r);
    redirect('painel/propostas/' . $c['id'] . '#avaliacao');
});

/* Conferência dos valores no simulador de vendas do próprio Asaas. */
$router->post('/painel/propostas/{id}/asaas-simular', function (string $id) {
    $c = require_case_operator((int) $id);
    $valor = money_cents((string) input('valor')) ?? 0;
    $n = max(1, min(21, (int) input('parcelas')));
    $r = $valor > 0 ? asaas_simulate_api($valor, $n) : ['ok' => false, 'error' => 'Informe um valor para simular.', 'rows' => []];
    json_out(['html' => render('panel/_proposta_asaas_api', ['r' => $r, 'valor' => $valor, 'n' => $n])]);
});

$router->post('/painel/propostas/{id}/asaas', function (string $id) {
    require_user();
    $c = require_case_operator((int) $id);
    $op = (string) input('op');
    $err = match ($op) {
        'enviar' => asaas_send($c, $_POST),
        'atualizar' => asaas_refresh($c),
        'cancelar' => asaas_cancel($c),
        default => 'Ação desconhecida.',
    };
    flash($err ? 'error' : 'ok', $err ?? match ($op) {
        'enviar' => 'Cobranças criadas no Asaas com os valores do contrato.',
        'atualizar' => 'Situação das cobranças atualizada.',
        default => 'Cobranças pendentes canceladas no Asaas.',
    });
    redirect('painel/propostas/' . $c['id'] . '#asaas');
});

/* ---------- Variáveis dos documentos ---------- */

$router->get('/painel/variaveis', function () {
    require_user();
    seed_templates();
    $used = [];
    foreach (array_keys(template_var_labels()) as $k) {
        $used[$k] = templates_using_var($k);
    }
    view('panel/variaveis', [
        'meta_title' => 'Variáveis dos documentos · Painel',
        'catalog' => template_var_catalog(),
        'used' => $used,
        'custom' => custom_vars(),
        'edit' => (int) input('editar'),
    ], 'panel');
});

$router->post('/painel/variaveis', function () {
    $u = require_user();
    if (!is_operator($u) && !has_role('supervisor')) {
        forbidden();
    }
    $op = (string) input('op');
    if ($op === 'escritorio') {
        $errs = [];
        $pixType = (string) input('escritorio_pix_tipo');
        foreach (OFFICE_VARS as $k => $d) {
            [$v, $err] = normalize_office_var($k, (string) ($_POST[str_replace('.', '_', $k)] ?? ''), $pixType);
            if ($err) {
                $errs[] = $d['label'] . ': ' . $err;
                continue;
            }
            set_setting($d['setting'], $v);
        }
        foreach (CASE_VAR_DEFAULTS as $k => $d) {
            set_setting($d['setting'], trim(str_replace("\r\n", "\n", mb_substr((string) ($_POST[$d['setting']] ?? ''), 0, $d['max'] ?? 300))) ?: null);
        }
        audit('variaveis_escritorio', null, null);
        flash($errs ? 'error' : 'ok', $errs ? 'Os demais dados foram salvos. ' . implode(' ', $errs) : 'Dados do escritório salvos. Documentos já gerados mostram o aviso de dados alterados.');
        redirect('painel/variaveis#escritorio');
    }
    if ($op === 'criar' || $op === 'alterar') {
        $err = save_custom_var($op === 'alterar' ? (int) input('id') : null, $_POST);
        flash($err ? 'error' : 'ok', $err ?? ($op === 'criar' ? 'Variável criada. Use-a nos modelos de documentos.' : 'Variável alterada.'));
        redirect('painel/variaveis' . ($err && $op === 'alterar' ? '?editar=' . (int) input('id') : '') . '#personalizadas');
    }
    if ($op === 'excluir') {
        $err = delete_custom_var((int) input('id'));
        flash($err ? 'error' : 'ok', $err ?? 'Variável excluída.');
        redirect('painel/variaveis#personalizadas');
    }
    redirect('painel/variaveis');
});

/* ---------- Assistente com IA (tom, competências, proibições, variáveis mínimas) ---------- */

$router->get('/painel/assistente', function () {
    require_user();
    $since = now()->modify('-30 days')->format('Y-m-d H:i:s');
    $stats = [
        'conversas' => (int) scalar("SELECT COUNT(DISTINCT case_id) FROM llm_log WHERE kind = 'agente' AND created_at >= ?", [$since]),
        'turnos' => (int) scalar("SELECT COUNT(*) FROM llm_log WHERE kind = 'agente' AND created_at >= ?", [$since]),
        'falhas' => (int) scalar("SELECT COUNT(*) FROM llm_log WHERE kind = 'agente' AND ok = 0 AND created_at >= ?", [$since]),
        'duvidas' => all("SELECT e.case_id, e.body, e.at, c.client_name, c.protocol FROM events e JOIN cases c ON c.id = e.case_id WHERE e.type = 'duvida' AND e.at >= ? ORDER BY e.id DESC LIMIT 10", [$since]),
        'bloqueios' => (int) scalar("SELECT COUNT(*) FROM events WHERE type = 'ia' AND title LIKE 'Resposta da IA bloqueada%' AND at >= ?", [$since]),
    ];
    $test = $_SESSION['agent_test'] ?? null;
    unset($_SESSION['agent_test']);
    view('panel/assistente', ['meta_title' => 'Assistente com IA · Painel', 'stats' => $stats, 'test' => $test], 'panel');
});

$router->post('/painel/assistente', function () {
    $u = require_user();
    if (!has_role('supervisor') && !is_operator($u)) {
        forbidden();
    }
    $op = (string) input('op');
    if ($op === 'testar') {
        $msg = trim((string) input('mensagem'));
        $_SESSION['agent_test'] = $msg === '' ? null : ['mensagem' => $msg] + (llm_enabled() ? agent_test($msg) : ['ok' => false, 'error' => 'Configure a IA (fornecedor e chave) em Configurações.']);
        redirect('painel/assistente#teste');
    }
    if ($op === 'padrao') {
        foreach (array_merge(array_keys(AGENT_DEFAULTS), ['agent_required_fields', 'agent_required_docs']) as $k) {
            set_setting($k, null);
        }
        audit('assistente_padrao');
        flash('ok', 'Textos e variáveis do assistente voltaram ao padrão. O liga/desliga não mudou.');
        redirect('painel/assistente');
    }
    set_setting('agent_enabled', input('agent_enabled') === '1' ? '1' : '0');
    foreach (['agent_name' => 120, 'agent_greeting' => 1000, 'agent_tone' => 3000, 'agent_skills' => 4000, 'agent_forbidden' => 4000, 'agent_tech_reply' => 800, 'agent_done' => 800] as $k => $max) {
        $v = trim(str_replace("\r\n", "\n", mb_substr((string) ($_POST[$k] ?? ''), 0, $max)));
        set_setting($k, $v === '' || $v === AGENT_DEFAULTS[$k] ? null : $v);
    }
    $per = (int) input('agent_max_per_hour');
    set_setting('agent_max_per_hour', $per >= 5 && $per <= 200 ? (string) $per : null);
    $fields = array_values(array_intersect(array_keys(agent_allowed_fields()), (array) ($_POST['campos'] ?? [])));
    set_setting('agent_required_fields', json_encode($fields));
    $docs = array_values(array_intersect(array_keys(agent_doc_options()), (array) ($_POST['documentos'] ?? [])));
    set_setting('agent_required_docs', json_encode($docs));
    audit('assistente_config', null, 'ligado=' . setting('agent_enabled') . ' campos=' . count($fields) . ' docs=' . count($docs));
    flash(agent_enabled() || input('agent_enabled') !== '1' ? 'ok' : 'error', input('agent_enabled') === '1' && !llm_enabled()
        ? 'Configurações salvas, mas a conversa com IA só funciona com a IA ligada e uma chave em Configurações › Inteligência artificial. Até lá, vale o roteiro com botões.'
        : 'Configurações do assistente salvas. Valem para as próximas mensagens.');
    redirect('painel/assistente');
});

/* ---------- Configurações: painel de controles e uma página por área ---------- */

const CONFIG_SECTIONS = [
    'atendimento' => 'Atendimento e entrada',
    'ia' => 'Inteligência artificial',
    'email' => 'E-mails de aviso',
    'asaas' => 'Asaas (cobranças)',
    'agenda' => 'Google (Agenda e Drive)',
    'precos' => 'Preços e negociação',
];

function config_view_data(array $u): array
{
    $m = (string) input('modelos');
    return [
        'users' => users_list(), 'u' => $u, 'usage' => llm_usage_summary(),
        'models' => isset(LLM_PROVIDERS[$m]) ? [$m => llm_models_list($m)] : ($m === '1' ? [llm_provider() => llm_models_list()] : []),
    ];
}

function require_config_editor(): array
{
    $u = require_user();
    if (!has_role('supervisor') && !is_operator($u)) {
        forbidden();
    }
    return $u;
}

$router->get('/painel/configuracoes', function () {
    $u = require_user();
    if (input('modelos') !== '') {
        redirect('painel/configuracoes/ia?modelos=' . rawurlencode((string) input('modelos')));
    }
    seed_templates();
    view('panel/configuracoes', ['meta_title' => 'Configurações · Painel'] + config_view_data($u), 'panel');
});

$router->get('/painel/configuracoes/{secao}', function (string $secao) {
    $u = require_user();
    if (!isset(CONFIG_SECTIONS[$secao])) {
        redirect('painel/configuracoes');
    }
    view('panel/config_secao', ['meta_title' => CONFIG_SECTIONS[$secao] . ' · Configurações', 'secao' => $secao] + config_view_data($u), 'panel');
});

/** Liga e desliga direto pelo painel de controles. */
function config_toggle(string $what, bool $on): string
{
    switch ($what) {
        case 'ia':
            if ($on && !llm_key()) {
                return 'error:Cadastre a chave do fornecedor de IA antes de ligar.';
            }
            set_setting('llm_enabled', $on ? '1' : '0');
            return $on ? 'IA ligada.' : 'IA desligada: o site usa o roteiro com perguntas e botões.';
        case 'agente':
            set_setting('agent_enabled', $on ? '1' : '0');
            return $on ? (llm_enabled() ? 'A IA conduz a conversa do site.' : 'error:Conversa com IA ligada, mas só funciona com a IA ligada e com chave.') : 'Conversa conduzida pela IA desligada: vale o roteiro com botões.';
        case 'avisos':
            if ($on) {
                set_setting('mail_driver', (string) (setting('mail_driver_prev') ?: (setting('smtp_host') ? 'smtp' : 'mail')));
                return 'Avisos por e-mail ligados.';
            }
            if (mail_driver() !== 'off') {
                set_setting('mail_driver_prev', mail_driver());
            }
            set_setting('mail_driver', 'off');
            return 'Avisos por e-mail desligados. As novidades continuam marcadas na área do cliente.';
        case 'entrada':
            if ($on && !operator_id()) {
                return 'error:Escolha o operador em “Atendimento e entrada” antes de ligar a entrada pelo assistente.';
            }
            set_setting('intake_workflow', $on ? 'simple_v1' : 'legacy');
            return $on ? 'Entrada pelo atendimento online ligada.' : 'Novas entradas voltam ao formulário antigo de seis passos.';
    }
    return 'error:Controle desconhecido.';
}

function config_save_atendimento(): bool
{
    $op = (int) input('operator_user_id');
    if ($op && !one('SELECT id FROM users WHERE id = ? AND active = 1', [$op])) {
        flash('error', 'Usuário inválido.');
        return false;
    }
    set_setting('operator_user_id', $op ? (string) $op : null);
    set_setting('intake_workflow', input('intake_workflow') === 'legacy' ? 'legacy' : 'simple_v1');
    if ($op) {
        q("UPDATE cases SET operator_id = ? WHERE workflow_version = 'simple_v1' AND operator_id IS NULL", [$op]);
    }
    audit('configuracoes', null, 'operador=' . $op . ' entrada=' . setting('intake_workflow'));
    return true;
}

function config_save_ia(): void
{
    // IA: fornecedor, modelo e chave de cada um. A chave só é trocada quando um
    // valor novo é digitado; nunca volta para a tela.
    set_setting('llm_enabled', input('llm_enabled') === '1' ? '1' : '0');
    if (isset(LLM_PROVIDERS[(string) input('llm_provider')])) {
        set_setting('llm_provider', (string) input('llm_provider'));
    }
    foreach (['openrouter' => ['llm_api_key', 'llm_model', '#^[a-z0-9._-]+/[A-Za-z0-9._:-]+$#'], 'gemini' => ['gemini_api_key', 'gemini_model', '#^[a-z0-9][a-z0-9._-]*$#']] as $pk => [$keyName, $modelName, $modelRe]) {
        $model = trim((string) input($modelName));
        if ($pk === 'gemini') {
            $model = preg_replace('#^models/#', '', $model);
        }
        if ($model !== '' && preg_match($modelRe, $model)) {
            set_setting(LLM_PROVIDERS[$pk]['model_setting'], $model);
        } elseif ($model !== '') {
            flash('error', 'Modelo inválido para ' . LLM_PROVIDERS[$pk]['label'] . ': use o identificador, por exemplo ' . LLM_PROVIDERS[$pk]['default_model'] . '.');
        }
        $key = trim((string) ($_POST[$keyName] ?? ''));
        if (input('remove_key_' . $pk) === '1' || ($pk === 'openrouter' && input('llm_remove_key') === '1')) {
            set_setting(LLM_PROVIDERS[$pk]['key_setting'], null);
            audit('ia_chave_removida', null, $pk);
        } elseif ($key !== '') {
            if (!llm_key_format_ok($pk, $key)) {
                flash('error', $pk === 'openrouter' ? 'A chave do OpenRouter começa com “sk-or-”. Confira e cole de novo.' : 'A chave do Gemini parece incompleta. Copie de novo pelo botão “Copiar chave”.');
            } else {
                set_setting(LLM_PROVIDERS[$pk]['key_setting'], $key);
                audit('ia_chave_alterada', null, $pk . ' final ' . substr($key, -4));
            }
        }
    }
    if (input('llm_test') === '1') {
        setting('__reset');
        [$okTest, $msgTest] = llm_test();
        flash($okTest ? 'ok' : 'error', 'Teste da IA: ' . $msgTest);
    }
}

function config_save_asaas(): void
{
    // Asaas: ambiente, chave (só trocada quando digitada), multa e juros de mora.
    $akey = trim((string) ($_POST['asaas_api_key'] ?? ''));
    if (input('asaas_remove_key') === '1') {
        set_setting('asaas_api_key', null);
        audit('asaas_chave_removida');
    } elseif ($akey !== '') {
        if (!asaas_key_format_ok($akey)) {
            flash('error', 'A chave do Asaas começa com “$aact_”. Copie de novo em Integrações › Chaves de API.');
        } else {
            set_setting('asaas_api_key', $akey);
            audit('asaas_chave_alterada', null, 'final ' . substr($akey, -4));
        }
    }
    $aenv = (string) input('asaas_env');
    if (isset(ASAAS_ENVS[$aenv])) {
        set_setting('asaas_env', $aenv);
    }
    if ($akey !== '' && asaas_key_format_ok($akey) && ($kenv = asaas_key_env($akey)) && $kenv !== $aenv && isset(ASAAS_ENVS[$aenv])) {
        set_setting('asaas_env', $kenv);
        flash('error', 'A chave informada é do ambiente “' . ASAAS_ENVS[$kenv]['label'] . '”; o ambiente foi ajustado para ela.');
    }
    foreach (['asaas_multa' => 20, 'asaas_juros' => 10] as $k => $max) {
        $v = parse_money(str_replace('%', '', (string) input($k)));
        if ($v !== null && $v >= 0 && $v <= $max) {
            set_setting($k, rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.'));
        }
    }
    if (input('asaas_test') === '1') {
        setting('__reset');
        [$okA, $msgA] = asaas_test();
        flash($okA ? 'ok' : 'error', 'Teste do Asaas: ' . $msgA);
    }
}

/** Agenda: conta do Google (app OAuth do escritório), expediente e regras do horário marcado pelo assistente. */
function config_save_agenda(): void
{
    $id = trim((string) input('google_client_id'));
    if ($id !== '' && !preg_match('/^[\w.-]+\.apps\.googleusercontent\.com$/', $id)) {
        flash('error', 'O ID do cliente do Google termina em “.apps.googleusercontent.com”. Copie de novo em Google Cloud › APIs e serviços › Credenciais.');
    } elseif ($id !== google_client_id()) {
        set_setting('google_client_id', $id ?: null);
        foreach (['google_refresh_token', 'google_access_token', 'google_access_expires', 'google_account_email', 'google_scopes', 'drive_root_id'] as $k) {
            set_setting($k, null); // outro app: a autorização antiga não vale
        }
        audit('google_cliente_alterado');
    }
    $secret = trim((string) ($_POST['google_client_secret'] ?? ''));
    if ($secret !== '') {
        set_setting('google_client_secret', $secret);
        audit('google_segredo_alterado', null, 'final ' . substr($secret, -4));
    }
    $pasta = trim((string) input('google_drive_pasta'), " /\\");
    if ($pasta !== drive_path()) {
        set_setting('google_drive_pasta', $pasta !== '' && $pasta !== DRIVE_PATH_DEFAULT ? mb_substr($pasta, 0, 200) : null);
        set_setting('drive_root_id', null);
    }
    set_setting('drive_off', input('drive_on') === '1' ? null : '1');
    $cal = trim((string) input('google_calendar_id'));
    set_setting('google_calendar_id', $cal !== '' && $cal !== 'primary' ? mb_substr($cal, 0, 200) : null);
    if (input('google_desconectar') === '1') {
        $tok = (string) setting('google_refresh_token', '');
        if ($tok !== '') {
            google_http('POST', GOOGLE_REVOKE_URL, ['token' => $tok], true);
        }
        foreach (['google_refresh_token', 'google_access_token', 'google_access_expires', 'google_account_email', 'google_scopes', 'drive_root_id'] as $k) {
            set_setting($k, null);
        }
        audit('google_desconectado');
    }
    $hm = fn(string $v) => preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $v, $x) ? (int) $x[1] * 60 + (int) $x[2] : null;
    $ini = $hm((string) input('agenda_inicio'));
    $fim = $hm((string) input('agenda_fim'));
    if ($ini !== null && $fim !== null && $fim - $ini >= 30) {
        set_setting('agenda_inicio', agenda_hhmm($ini));
        set_setting('agenda_fim', agenda_hhmm($fim));
    } else {
        flash('error', 'Confira o expediente: o fim precisa ser pelo menos 30 minutos depois do início.');
    }
    foreach (['agenda_duracao' => [10, 120], 'agenda_antecedencia' => [0, 1440], 'agenda_intervalo' => [0, 480]] as $k => [$min, $max]) {
        $v = input($k);
        if (is_numeric($v) && (int) $v >= $min && (int) $v <= $max) {
            set_setting($k, (string) (int) $v);
        }
    }
}

function config_save_email(): void
{
    // E-mails de aviso: a senha SMTP só é trocada quando digitada e nunca volta para a tela.
    if (isset(MAIL_DRIVERS[(string) input('mail_driver')])) {
        set_setting('mail_driver', (string) input('mail_driver'));
    }
    foreach (['mail_from' => FILTER_VALIDATE_EMAIL, 'smtp_user' => null] as $k => $filter) {
        $v = trim((string) input($k));
        if ($v === '' || !$filter || filter_var($v, $filter)) {
            set_setting($k, $v === '' ? null : mb_substr($v, 0, 160));
        }
    }
    set_setting('mail_from_name', trim(mb_substr(str_replace(["\r", "\n"], '', (string) input('mail_from_name')), 0, 80)) ?: null);
    $host = trim((string) input('smtp_host'));
    set_setting('smtp_host', $host !== '' && preg_match('/^[a-z0-9.-]+$/i', $host) ? $host : null);
    $port = (int) input('smtp_port');
    set_setting('smtp_port', in_array($port, [25, 465, 587, 2525], true) ? (string) $port : null);
    $spass = (string) ($_POST['smtp_pass'] ?? '');
    if ($spass !== '') {
        set_setting('smtp_pass', $spass);
        audit('smtp_senha_alterada');
    }
    $delay = (int) input('notify_delay_min');
    set_setting('notify_delay_min', $delay >= 1 && $delay <= 240 ? (string) $delay : null);
    if (($to = trim((string) input('mail_test_to'))) !== '') {
        setting('__reset');
        $lines = ['Este é um teste dos avisos aos clientes.', 'Se chegou, os clientes vão receber os avisos de novidade nas conversas.'];
        [$okM, $errM] = send_mail($to, 'Teste de e-mail · ' . office('brand'), implode("\n\n", $lines), mail_html('Teste de e-mail', $lines), null, 'teste');
        flash($okM ? 'ok' : 'error', $okM ? 'E-mail de teste enviado para ' . $to . '. Confira a caixa de entrada e o spam.' : 'O e-mail de teste falhou: ' . $errM);
    }
}

function config_save_precos(): void
{
    $rows = [];
    foreach ((array) ($_POST['oab_label'] ?? []) as $i => $label) {
        $label = trim(mb_substr((string) $label, 0, 160));
        if ($label === '') {
            continue;
        }
        $key = preg_replace('/[^a-z0-9_]/', '', (string) ($_POST['oab_key'][$i] ?? '')) ?: 'item_' . substr(md5($label), 0, 8);
        $rows[] = ['key' => $key, 'label' => $label, 'valor' => money_cents((string) ($_POST['oab_valor'][$i] ?? '')),
            'nota' => trim(mb_substr((string) ($_POST['oab_nota'][$i] ?? ''), 0, 160))];
    }
    if ($rows) {
        set_setting('oab_tabela', json_encode($rows, JSON_UNESCAPED_UNICODE));
    }
    $pct = parse_money(str_replace('%', '', (string) input('indicacao_pct')));
    if ($pct !== null && $pct >= 0 && $pct <= 200) {
        set_setting('indicacao_pct', rtrim(rtrim(number_format($pct, 2, '.', ''), '0'), '.'));
    }
    if (input('fees_padrao') === '1') {
        set_setting('asaas_fees', null);
        return;
    }
    $fees = [];
    foreach (ASAAS_FEE_DEFAULTS as $k => $def) {
        $v = parse_money(str_replace('%', '', (string) input('fee_' . $k)));
        if ($v !== null && $v >= 0 && $v <= ($k === 'cartao_max' ? 21 : 100)) {
            $fees[$k] = $k === 'cartao_max' ? (int) $v : round($v, 2);
        }
    }
    set_setting('asaas_fees', array_diff_assoc($fees, ASAAS_FEE_DEFAULTS) ? json_encode($fees) : null);
}

$router->post('/painel/configuracoes', function () {
    require_config_editor();
    $secao = (string) input('secao');
    if (input('op') === 'alternar') {
        $msg = config_toggle((string) input('controle'), input('ligar') === '1');
        $err = str_starts_with($msg, 'error:');
        flash($err ? 'error' : 'ok', $err ? substr($msg, 6) : $msg);
        audit('configuracoes_controle', null, (string) input('controle') . '=' . (input('ligar') === '1' ? 'on' : 'off'));
        redirect('painel/configuracoes');
    }
    if ($secao !== '' && !isset(CONFIG_SECTIONS[$secao])) {
        redirect('painel/configuracoes');
    }
    // Cada página salva só a sua área; o envio sem área (formulário antigo) salva todas.
    $ok = true;
    if ($secao === '' || $secao === 'atendimento') {
        $ok = config_save_atendimento();
    }
    if ($ok && ($secao === '' || $secao === 'ia')) {
        config_save_ia();
    }
    if ($ok && ($secao === '' || $secao === 'asaas')) {
        config_save_asaas();
    }
    if ($ok && ($secao === '' || $secao === 'email')) {
        config_save_email();
    }
    if ($ok && $secao === 'precos') {
        config_save_precos();
    }
    if ($ok && $secao === 'agenda') {
        config_save_agenda();
    }
    if ($ok) {
        flash('ok', $secao ? CONFIG_SECTIONS[$secao] . ': configurações salvas.' : 'Configurações salvas.');
    }
    redirect($secao ? 'painel/configuracoes/' . $secao : 'painel/configuracoes');
});

/* ---------- Google Agenda: autorização (OAuth) ---------- */

$router->get('/painel/google/conectar', function () {
    require_config_editor();
    if (google_client_id() === '' || (string) setting('google_client_secret', '') === '') {
        flash('error', 'Cadastre o ID do cliente e a chave secreta do Google antes de conectar.');
        redirect('painel/configuracoes/agenda');
    }
    $_SESSION['google_state'] = bin2hex(random_bytes(16));
    redirect(google_auth_url($_SESSION['google_state']));
});

$router->get('/painel/google/retorno', function () {
    require_config_editor();
    $state = (string) input('state');
    $expected = (string) ($_SESSION['google_state'] ?? '');
    unset($_SESSION['google_state']);
    if ($expected === '' || !hash_equals($expected, $state)) {
        flash('error', 'A autorização do Google expirou ou não veio deste painel. Clique em “Conectar” de novo.');
    } elseif (input('error') !== '') {
        flash('error', 'O Google não autorizou o acesso (' . mb_substr((string) input('error'), 0, 60) . ').');
    } elseif ($err = google_exchange_code((string) input('code'))) {
        flash('error', 'Não foi possível conectar o Google Agenda. ' . $err);
    } else {
        audit('google_conectado', null, (string) setting('google_account_email', ''));
        flash('ok', 'Google Agenda conectado' . (setting('google_account_email') ? ' (' . setting('google_account_email') . ')' : '') . '. As conversas marcadas pelo assistente já vão para a sua agenda, com link do Meet.');
    }
    redirect('painel/configuracoes/agenda');
});

/* ---------- Migração dos casos existentes (seção 15.2) ---------- */

$router->get('/painel/migracao', function () {
    require_user();
    $rows = [];
    foreach (all("SELECT * FROM cases WHERE workflow_version = 'legacy' ORDER BY status, id") as $c) {
        $rows[] = ['c' => $c, 'class' => migration_class($c)];
    }
    view('panel/migracao', ['meta_title' => 'Migração · Painel', 'rows' => $rows], 'panel');
});

$router->post('/painel/migracao', function () {
    $u = require_user();
    if (!is_operator($u)) {
        flash('error', 'Configure o operador antes de migrar atendimentos.');
        redirect('painel/migracao');
    }
    $c = find_case((int) input('case_id'));
    [$class, , $suggest] = migration_class($c);
    $target = (string) input('target');
    if ($c['workflow_version'] !== 'legacy' || !$suggest || !isset(INITIAL_STATUS[$target]) || $target === 'completed') {
        flash('error', 'Este caso não é candidato à migração para o atendimento inicial.');
        redirect('painel/migracao');
    }
    migrate_case_to_initial($c, $target);
    flash('ok', 'Atendimento ' . $c['protocol'] . ' migrado. Confira a ficha.');
    redirect('painel/atendimento/' . $c['id']);
});

