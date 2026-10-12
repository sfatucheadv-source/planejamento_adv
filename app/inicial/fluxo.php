<?php
declare(strict_types=1);

/**
 * Seções 3, 5.3 e 7 — abertura, checklist condicional, pendências, urgência
 * transversal, transições com travas no servidor, próxima ação e alertas.
 */

/* ---------- Abertura (seção 14.3: uma criação, operador configurado) ---------- */

function create_initial_case(array $a, string $source = 'site', ?int $accountId = null, ?int $previousId = null): array
{
    $now = now_str();
    $op = operator_id();
    $dup = null;
    $digits = only_digits((string) $a['telefone']);
    if ($digits !== '') {
        // Outro atendimento da mesma conta é um assunto novo da pessoa, não duplicidade.
        $dup = scalar("SELECT id FROM cases WHERE workflow_version = 'simple_v1' AND client_phone = ? AND initial_status <> 'completed' AND execution_started_at IS NULL AND (? IS NULL OR account_id IS NULL OR account_id <> ?) ORDER BY id DESC LIMIT 1", [$digits, $accountId, $accountId]);
    }
    db()->beginTransaction();
    $id = insert('cases', [
        'protocol' => new_protocol(),
        'created_at' => $now,
        'updated_at' => $now,
        'source' => $source,
        'origin_page' => mb_substr((string) ($a['origem'] ?? ''), 0, 120),
        'client_name' => mb_substr((string) $a['nome'], 0, 120),
        'client_phone' => $digits ?: null,
        'consent_at' => $now,
        'stage' => 'acolhimento',
        'stage_since' => $now,
        'workflow_version' => 'simple_v1',
        'initial_status' => 'collecting',
        'operator_id' => $op,
        'gestor_id' => $op,
        'advogado_id' => $op,
        'account_id' => $accountId,
        'previous_case_id' => $previousId,
        'duplicate_of' => $dup ? (int) $dup : null,
        'next_action' => 'Conferir a coleta do assistente',
        'next_action_due' => add_business_days(now(), 1)->format('Y-m-d H:i:s'),
        'next_action_owner_id' => $op,
        'activity_at' => $now,
        'data' => '{}',
    ]);
    set_field($id, 'nome', (string) $a['nome']);
    set_field($id, 'telefone', (string) $a['telefone']);
    if (!empty($a['email'])) {
        set_field($id, 'email', (string) $a['email']);
    }
    if (!empty($a['adaptacao'])) {
        set_field($id, 'adaptacao', (string) $a['adaptacao']);
    }
    add_event($id, 'abertura', 'Atendimento aberto', $source === 'site' ? 'Contato recebido pelo assistente virtual do site.' : 'Atendimento registrado por Sidney.', true, $source === 'site' ? 'cliente' : 'equipe');
    if ($dup) {
        add_event($id, 'duplicidade', 'Possível duplicidade', 'Há outro atendimento ativo com o mesmo telefone (protocolo ' . scalar('SELECT protocol FROM cases WHERE id = ?', [$dup]) . '). Sidney decide o vínculo.');
    }
    if ($previousId) {
        add_event($id, 'vinculo', 'Novo atendimento vinculado ao anterior', 'Atendimento anterior: ' . scalar('SELECT protocol FROM cases WHERE id = ?', [$previousId]) . '.');
    }
    db()->commit();
    return find_case($id);
}

/* ---------- Checklist condicional (seção 7.1) ---------- */

/** @return array<string,bool> tipo => essencial (sugestão; Sidney decide a criticidade) */
function intake_required_docs(int $caseId): array
{
    $aviso = fv($caseId, 'aviso');
    $docs = ['contrato' => true, 'historico' => true, 'identidade' => false, 'residencia' => false];
    if (in_array($aviso, ['apreendido', 'justica'], true) || fv($caseId, 'processo_existente') === 'sim') {
        $docs['processo'] = true;
    }
    if (in_array($aviso, ['cartorio', 'banco'], true)) {
        $docs['notificacoes'] = true;
    }
    if (fv($caseId, 'objetivo_prioridade') === 'venda_saldo') {
        $docs['venda'] = true;
        $docs['despesas'] = true;
    }
    if (fv($caseId, 'gratuidade') === 'sim') {
        $docs['renda'] = false;
    }
    return $docs;
}

const CONDITIONAL_DOCS = ['processo', 'notificacoes', 'venda', 'despesas', 'renda'];

function sync_intake_documents(int $caseId): void
{
    $required = intake_required_docs($caseId);
    $have = [];
    foreach (all('SELECT * FROM documents WHERE case_id = ?', [$caseId]) as $d) {
        $have[$d['kind']] = $d;
    }
    foreach ($required as $kind => $essential) {
        if (!isset($have[$kind])) {
            insert('documents', [
                'case_id' => $caseId, 'kind' => $kind, 'label' => DOC_CATALOG[$kind]['label'], 'status' => 'falta',
                'essential' => $essential ? 1 : 0, 'requested_at' => now_str(), 'origin' => 'checklist',
            ]);
        } elseif ($have[$kind]['status'] === 'dispensado' && $have[$kind]['origin'] === 'checklist') {
            q("UPDATE documents SET status = 'falta' WHERE id = ?", [$have[$kind]['id']]);
        }
    }
    foreach ($have as $kind => $d) {
        if (in_array($kind, CONDITIONAL_DOCS, true) && !isset($required[$kind]) && $d['origin'] === 'checklist'
            && in_array($d['status'], ['falta', 'ajuda'], true) && !scalar('SELECT 1 FROM files WHERE document_id = ?', [$d['id']])) {
            q("UPDATE documents SET status = 'dispensado' WHERE id = ?", [$d['id']]);
        }
    }
}

/** Seção 7.1 — o que o cliente vê. */
function client_doc_state(array $d): array
{
    return match ($d['status']) {
        'recebido' => ['Recebido', 'Em conferência', 'info'],
        'conferido' => ['Recebido', 'Conferido', 'ok'],
        'pendencia' => ['Falta', 'Reenviar' . ($d['reject_reason'] ? ': ' . $d['reject_reason'] : ''), 'warn'],
        'dispensado' => ['Não se aplica', '', 'muted'],
        'nao_possui' => ['Falta', 'Você informou que não tem. Sidney vai orientar.', 'warn'],
        'ajuda' => ['Falta', 'Vamos ajudar a obter', 'warn'],
        default => ['Falta', '', 'warn'],
    };
}

const DOC_OPEN = ['falta', 'ajuda', 'pendencia', 'nao_possui'];

/* ---------- Pendências (seção 7.2) ---------- */

/** Pendências abertas: documentos que faltam + itens registrados por Sidney. */
function open_pendings(int $caseId, bool $forClient = false): array
{
    $out = [];
    foreach (all("SELECT * FROM documents WHERE case_id = ? AND status IN ('falta','ajuda','pendencia','nao_possui') ORDER BY essential DESC, id", [$caseId]) as $d) {
        $out[] = ['src' => 'doc', 'id' => (int) $d['id'], 'type' => 'documento', 'description' => $d['label'] . ($d['status'] === 'pendencia' && $d['reject_reason'] ? ' (reenviar: ' . $d['reject_reason'] . ')' : ''), 'essential' => (bool) $d['essential'], 'status' => $d['status']];
    }
    foreach (all("SELECT * FROM case_pending_items WHERE case_id = ? AND status = 'aberta' ORDER BY criticality = 'essencial' DESC, id", [$caseId]) as $p) {
        $out[] = ['src' => 'item', 'id' => (int) $p['id'], 'type' => $p['type'], 'description' => $p['description'], 'essential' => $p['criticality'] === 'essencial', 'status' => 'aberta'];
    }
    return $out;
}

/** Itens essenciais ainda sem tratamento (inclui documento entregue e não conferido). */
function essential_open(int $caseId): array
{
    $out = [];
    foreach (all("SELECT label, status FROM documents WHERE case_id = ? AND essential = 1 AND status IN ('falta','ajuda','pendencia','nao_possui','recebido')", [$caseId]) as $d) {
        $out[] = $d['label'] . ($d['status'] === 'recebido' ? ' (recebido, falta conferir)' : '');
    }
    foreach (all("SELECT description FROM case_pending_items WHERE case_id = ? AND status = 'aberta' AND criticality = 'essencial'", [$caseId]) as $p) {
        $out[] = $p['description'];
    }
    return $out;
}

function add_pending(int $caseId, string $type, string $description, string $criticality = 'pontual', ?string $ref = null): int
{
    return insert('case_pending_items', [
        'case_id' => $caseId, 'type' => isset(PENDING_TYPES[$type]) ? $type : 'esclarecimento', 'ref' => $ref,
        'description' => mb_substr($description, 0, 300), 'criticality' => $criticality === 'essencial' ? 'essencial' : 'pontual',
        'status' => 'aberta', 'created_at' => now_str(), 'created_by' => current_user()['id'] ?? null,
    ]);
}

/* ---------- Urgência sem nova etapa (seção 5.3) ---------- */

function update_urgency_signal(int $caseId): void
{
    $aviso = fv($caseId, 'aviso');
    $signal = match ($aviso) {
        'apreendido', 'justica' => 'possivel_urgencia',
        'cartorio', 'banco', 'nao_sei' => 'verificar_aviso',
        default => null,
    };
    $reason = $aviso ? (NOTICE_OPTIONS[$aviso] ?? null) : null;
    $c = find_case($caseId);
    if ($signal === $c['urgency_signal'] && $reason === $c['urgency_reason']) {
        return;
    }
    bump_case($caseId, [
        'urgency_signal' => $signal,
        'urgency_reason' => $signal ? $reason : null,
        'urgency_date' => fv($caseId, 'aviso_data'),
        'urgent' => $signal === 'possivel_urgencia' ? 1 : 0,
        'activity_at' => now_str(),
    ]);
    if ($signal) {
        add_event($caseId, 'urgencia', $signal === 'possivel_urgencia' ? 'Possível urgência relatada' : 'Aviso a verificar', $reason . '. Relato do cliente; prazo ainda não confirmado por Sidney.');
    }
}

const URGENCY_SIGNALS = ['possivel_urgencia' => 'Possível urgência', 'verificar_aviso' => 'Verificar aviso'];

/* ---------- Próxima ação e retorno ---------- */

function set_next(int $caseId, string $action, ?string $due, ?string $clientReturn = null): void
{
    $f = ['next_action' => mb_substr($action, 0, 200), 'next_action_due' => $due, 'next_action_owner_id' => operator_id()];
    if ($clientReturn !== null) {
        $f['next_client_update'] = $clientReturn;
    }
    bump_case($caseId, $f);
}

function in_business_days(int $n): string
{
    return add_business_days(now(), $n)->format('Y-m-d 18:00:00');
}

/* ---------- Conversa (bot, cliente e Sidney no mesmo histórico) ---------- */

function chat_post(int $caseId, string $author, string $body, ?string $question = null, ?string $key = null): ?int
{
    try {
        return insert('chat_messages', [
            'case_id' => $caseId, 'author' => $author, 'user_id' => $author === 'operator' ? (current_user()['id'] ?? null) : null,
            'body' => mb_substr($body, 0, 4000), 'question' => $question, 'client_key' => $key, 'created_at' => now_str(),
        ]);
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'UNIQUE')) {
            return null; // retentativa do mesmo envio: não duplica
        }
        throw $e;
    }
}

function mark_client_activity(int $caseId): void
{
    q('UPDATE cases SET activity_at = ?, updated_at = ? WHERE id = ?', [now_str(), now_str(), $caseId]);
}

/* ---------- Reunião (seção 9) ---------- */

function current_meeting(int $caseId): ?array
{
    return one("SELECT * FROM case_meetings WHERE case_id = ? AND status = 'confirmada' ORDER BY starts_at DESC LIMIT 1", [$caseId]);
}

function held_meeting(int $caseId): ?array
{
    return one("SELECT * FROM case_meetings WHERE case_id = ? AND status = 'realizada' ORDER BY held_at DESC LIMIT 1", [$caseId]);
}

function valid_meet_url(string $url): bool
{
    return (bool) preg_match('#^https://meet\.google\.com/[a-z]{3}-[a-z]{4}-[a-z]{3}(\?[^\s]*)?$#', $url);
}

/* ---------- Transições (seção 3.2) ---------- */

/**
 * Executa um comando do atendimento inicial. Retorna erro legível ou null.
 * Transição, evento e alterações pertinentes vão na mesma transação.
 */
function initial_command(array $c, string $cmd, array $in): ?string
{
    $id = (int) $c['id'];
    $s = $c['initial_status'];
    $active = $s !== 'completed';
    $err = match ($cmd) {
        'liberar_analise' => $s !== 'collecting' ? 'A análise já foi liberada.' : null,
        'aprovar_preparacao' => !in_array($s, ['analysis', 'meeting_scheduled', 'awaiting_decision'], true) ? 'Libere a análise antes de validar a preparação.' : null,
        'confirmar_reuniao' => !in_array($s, ['analysis', 'meeting_scheduled', 'awaiting_decision'], true) ? 'A reunião só é confirmada depois da análise.' : null,
        'registrar_reuniao' => $s !== 'meeting_scheduled' ? 'Não há reunião agendada.' : null,
        'reanalisar' => !in_array($s, ['meeting_scheduled', 'awaiting_decision'], true) ? 'Reanálise só depois da preparação.' : null,
        'finalizar' => $s !== 'awaiting_decision' ? 'Finalize depois da reunião e da decisão.' : null,
        'encerrar_antes' => !in_array($s, ['collecting', 'analysis'], true) ? 'Depois da reunião, registre a decisão e finalize.' : null,
        'comunicar_encerramento' => $s !== 'completed' ? 'Conclua o atendimento antes de comunicar o encerramento.' : null,
        default => $active ? null : 'Atendimento inicial concluído.',
    };
    if ($err) {
        return $err;
    }
    db()->beginTransaction();
    try {
        $err = initial_command_run(find_case($id), $cmd, $in);
        if ($err) {
            db()->rollBack();
            return $err;
        }
        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        throw $e;
    }
    audit('inicial_' . $cmd, $id);
    return null;
}

function initial_command_run(array $c, string $cmd, array $in): ?string
{
    $id = (int) $c['id'];
    $note = trim((string) ($in['note'] ?? ''));
    switch ($cmd) {
        case 'liberar_analise':
            $scope = trim((string) ($in['urgent_scope'] ?? ''));
            $open = essential_open($id);
            if ($open && $scope === '') {
                return 'Ainda há pendência essencial: ' . implode('; ', $open) . '. Peça complemento, reclassifique como pontual ou delimite a atuação urgente.';
            }
            if (scalar("SELECT 1 FROM documents WHERE case_id = ? AND status = 'recebido'", [$id]) && $scope === '') {
                return 'Confira os documentos recebidos antes de aprovar a suficiência.';
            }
            bump_case($id, ['initial_status' => 'analysis', 'sufficiency_at' => now_str(), 'sufficiency_note' => $note ?: null, 'urgent_scope' => $scope ?: null]);
            add_event($id, 'situacao', 'Em análise', 'Sidney aprovou a suficiência' . ($scope ? ' com atuação urgente delimitada: ' . $scope : '') . '.', true);
            set_next($id, 'Preparar cálculos, parecer e proposta', in_business_days(2), in_business_days(2));
            chat_post($id, 'bot', 'Sidney conferiu seus documentos e começou a análise do financiamento. Quando a análise estiver pronta, vamos combinar a conversa.');
            return null;

        case 'aprovar_preparacao':
            $f = fn($k) => fv($id, $k);
            $miss = [];
            if (!$f('conclusao')) {
                $miss[] = 'conclusão';
            }
            if ($f('conclusao') !== 'limitada' && !$f('resultados') && !$f('memoria') && !scalar("SELECT 1 FROM files WHERE case_id = ? AND kind = 'memoria'", [$id])) {
                $miss[] = 'resultados ou memória de cálculo (ou marque “análise limitada”)';
            }
            if ($f('conclusao') === 'limitada' && !$f('limitacoes')) {
                $miss[] = 'limitações da análise';
            }
            if (!$f('conflito')) {
                $miss[] = 'conferência de conflito de interesses';
            }
            if (!$f('legitimidade')) {
                $miss[] = 'conferência de titularidade e mandato';
            }
            $definitive = $f('conclusao') !== 'nao_recomendada';
            if ($definitive && (!$f('proposta_escopo') || !$f('proposta_honorarios'))) {
                $miss[] = 'escopo e honorários da proposta';
            }
            if (!$f('proposta_riscos')) {
                $miss[] = 'riscos';
            }
            if (!$f('perguntas_reuniao')) {
                $miss[] = 'perguntas para a reunião';
            }
            if ($miss) {
                return 'Para validar a preparação falta: ' . implode('; ', $miss) . '.';
            }
            if ($f('conflito') === 'impedimento' || $f('legitimidade') === 'impedimento') {
                return 'Há impedimento registrado: a proposta definitiva fica bloqueada. Encerre com orientação ou trate o impedimento.';
            }
            if (essential_open($id) && !$c['urgent_scope']) {
                return 'Há pendência essencial aberta: ' . implode('; ', essential_open($id)) . '. A proposta definitiva depende dela.';
            }
            bump_case($id, ['preparation_at' => now_str(), 'review_flag' => null]);
            add_event($id, 'analise', 'Análise e proposta validadas', 'Conclusão: ' . field_display('conclusao', case_fields($id)['conclusao']) . '.');
            if ($c['initial_status'] === 'analysis') {
                set_next($id, 'Confirmar a reunião com o cliente', in_business_days(1));
            }
            return null;

        case 'confirmar_reuniao':
            if (!$c['preparation_at'] || $c['review_flag']) {
                return 'Valide a análise e a proposta (sem revisão pendente) antes de confirmar a reunião.';
            }
            $channel = (string) ($in['channel'] ?? '');
            if (!isset(MEETING_CHANNELS[$channel])) {
                return 'Escolha o canal da reunião.';
            }
            $start = dt(str_replace('T', ' ', (string) ($in['starts_at'] ?? '')));
            if (!$start) {
                return 'Informe data e hora da reunião.';
            }
            if ($start < now()->modify('-5 minutes')) {
                return 'A data da reunião já passou.';
            }
            $dur = max(10, min(240, (int) ($in['duration'] ?? 30)));
            $url = trim((string) ($in['url'] ?? ''));
            $phone = only_digits((string) ($in['phone'] ?? ''));
            if ($channel === 'meet' && !valid_meet_url($url)) {
                return 'Informe o link do Google Meet completo (https://meet.google.com/abc-defg-hij).';
            }
            if ($channel === 'phone' && (strlen($phone) < 10 || strlen($phone) > 13)) {
                return 'Confirme o número de telefone com DDD.';
            }
            if ($channel === 'whatsapp_text' && !office('whatsapp')) {
                return 'Configure o WhatsApp institucional antes de usar este canal.';
            }
            $end = $start->modify("+$dur minutes");
            foreach (all("SELECT m.*, c.client_name FROM case_meetings m JOIN cases c ON c.id = m.case_id WHERE m.status = 'confirmada' AND m.case_id <> ? AND c.operator_id = ?", [$id, $c['operator_id']]) as $o) {
                $os = dt($o['starts_at']);
                $oe = $os->modify('+' . (int) $o['duration_min'] . ' minutes');
                if ($start < $oe && $end > $os) {
                    return 'Conflito com a reunião de ' . $o['client_name'] . ' às ' . fmt_datetime($o['starts_at']) . '.';
                }
            }
            $prev = current_meeting($id);
            $mid = insert('case_meetings', [
                'case_id' => $id, 'status' => 'confirmada', 'channel' => $channel, 'starts_at' => $start->format('Y-m-d H:i:s'),
                'duration_min' => $dur, 'url' => $channel === 'meet' ? $url : null, 'phone' => $channel === 'phone' ? $phone : null,
                'note' => mb_substr(trim((string) ($in['note'] ?? '')), 0, 500) ?: null, 'confirmed_at' => now_str(),
                'confirmed_by' => current_user()['id'] ?? null, 'created_at' => now_str(),
            ]);
            if ($prev) {
                q("UPDATE case_meetings SET status = 'substituida', replaced_by = ? WHERE id = ?", [$mid, $prev['id']]);
                add_event($id, 'reuniao', 'Reunião reagendada', MEETING_CHANNELS[$prev['channel']]['short'] . ' em ' . fmt_datetime($prev['starts_at']) . ' → ' . MEETING_CHANNELS[$channel]['short'] . ' em ' . fmt_datetime($start->format('Y-m-d H:i:s')) . '.', true);
            }
            $fields = ['initial_status' => 'meeting_scheduled'];
            if ($channel === 'site_chat') {
                $fields['chat_mode'] = 'human';
            }
            bump_case($id, $fields);
            $how = match ($channel) {
                'meet' => 'Link: ' . $url,
                'phone' => 'Sidney vai ligar para ' . format_phone($phone) . '.',
                'whatsapp_text' => 'A conversa será pelo WhatsApp ' . office('phone_display') . '.',
                default => 'A conversa será aqui mesmo, neste chat.',
            };
            $msg = 'Sua conversa com Sidney está confirmada para ' . fmt_datetime($start->format('Y-m-d H:i:s')) . ' (horário de Brasília), por ' . MEETING_CHANNELS[$channel]['short'] . '. ' . $how;
            chat_post($id, 'operator', $msg);
            add_event($id, 'reuniao', 'Reunião confirmada', $msg, true);
            set_next($id, 'Realizar a reunião', $start->format('Y-m-d H:i:s'), $start->format('Y-m-d H:i:s'));
            return null;

        case 'registrar_reuniao':
            $m = current_meeting($id);
            if (!$m) {
                return 'Não há reunião confirmada para registrar.';
            }
            if (($in['held'] ?? '') === 'nao') {
                $next = parse_br_date((string) ($in['return_at'] ?? ''));
                q("UPDATE case_meetings SET status = 'nao_realizada', no_show_reason = ? WHERE id = ?", [mb_substr(trim((string) ($in['no_show_reason'] ?? '')), 0, 300) ?: null, $m['id']]);
                bump_case($id, []);
                add_event($id, 'reuniao', 'Reunião não realizada', ($in['no_show_reason'] ?? '') ?: 'Motivo não informado.', true);
                set_next($id, 'Reagendar a reunião', $next ? $next . ' 18:00:00' : in_business_days(1), $next ? $next . ' 18:00:00' : null);
                chat_post($id, 'operator', 'A conversa de ' . fmt_datetime($m['starts_at']) . ' não aconteceu. Vamos combinar um novo horário. Se preferir outro formato, é só avisar aqui.');
                return null;
            }
            $summary = trim((string) ($in['summary'] ?? ''));
            if (mb_strlen($summary) < 10) {
                return 'Escreva o resumo da reunião.';
            }
            $proposal = (int) ($in['proposal_version_id'] ?? 0);
            $postpone = trim((string) ($in['proposal_postponed'] ?? ''));
            if ($proposal && !scalar("SELECT 1 FROM case_artifact_versions WHERE id = ? AND case_id = ? AND kind = 'proposta'", [$proposal, $id])) {
                $proposal = 0;
            }
            if (!$proposal && $postpone === '') {
                return 'Indique a versão da proposta apresentada ou o motivo do adiamento.';
            }
            if (($in['confirmed_info'] ?? '') !== '1') {
                return 'Confirme que as informações do cliente foram conferidas na reunião.';
            }
            $return = parse_br_date((string) ($in['return_at'] ?? ''));
            $action = trim((string) ($in['next_action'] ?? ''));
            if (!$return || $action === '') {
                return 'Informe a próxima ação e a data de retorno.';
            }
            $reaction = (string) ($in['reaction'] ?? 'pensar');
            q('UPDATE case_meetings SET status = ?, held_at = ?, effective_channel = ?, summary = ?, corrections = ?, guidance = ?, risks = ?, pending_left = ?, proposal_version_id = ?, reaction = ? WHERE id = ?', [
                'realizada', now_str(), isset(MEETING_CHANNELS[$in['effective_channel'] ?? '']) ? $in['effective_channel'] : $m['channel'],
                mb_substr($summary, 0, 4000), trim((string) ($in['corrections'] ?? '')) ?: null, trim((string) ($in['guidance'] ?? '')) ?: null,
                trim((string) ($in['risks'] ?? '')) ?: null, trim((string) ($in['pending_left'] ?? '')) ?: ($postpone ? 'Proposta adiada: ' . $postpone : null),
                $proposal ?: null, $reaction, $m['id'],
            ]);
            $f = ['initial_status' => 'awaiting_decision'];
            if ($m['channel'] === 'site_chat') {
                $f['chat_mode'] = 'human';
            }
            if ($reaction === 'aceitou') {
                $f += ['decision' => 'aceitou', 'decision_at' => now_str(), 'decision_note' => 'Aceite preliminar na reunião.'];
            }
            if (($in['material_change'] ?? '') === '1') {
                $f['review_flag'] = 'analise';
            }
            bump_case($id, $f);
            add_event($id, 'reuniao', 'Reunião realizada', $summary, false);
            set_next($id, $action, $return . ' 18:00:00', $return . ' 18:00:00');
            chat_post($id, 'operator', "Este é o resumo do que conversamos e o próximo passo:\n\n" . $summary . "\n\nPróximo passo: " . $action . ' (até ' . fmt_date($return) . ").\n\nSe alguma informação estiver diferente, você pode corrigir aqui.");
            return null;

        case 'reanalisar':
            if (mb_strlen($note) < 5) {
                return 'Explique em poucas palavras o que mudou e o que precisa ser revisto.';
            }
            if ($m = current_meeting($id)) {
                q("UPDATE case_meetings SET status = 'cancelada', no_show_reason = ? WHERE id = ?", ['Reanálise: ' . $note, $m['id']]);
            }
            bump_case($id, ['initial_status' => 'analysis', 'preparation_at' => null, 'review_flag' => 'analise']);
            add_event($id, 'situacao', 'Voltou para análise', $note, true);
            set_next($id, 'Revisar a análise: ' . $note, in_business_days(2), in_business_days(2));
            return null;

        case 'registrar_decisao':
            $d = (string) ($in['decision'] ?? '');
            if (!isset(DECISIONS[$d])) {
                return 'Escolha a decisão.';
            }
            $return = parse_br_date((string) ($in['return_at'] ?? ''));
            $action = trim((string) ($in['next_action'] ?? ''));
            if ($d === 'pendente' && (!$return || $action === '')) {
                return 'Enquanto o cliente decide, registre o retorno combinado e a próxima ação. Silêncio não é recusa.';
            }
            bump_case($id, ['decision' => $d, 'decision_at' => $d === 'pendente' ? null : now_str(), 'decision_note' => $note ?: null]);
            add_event($id, 'decisao', 'Decisão: ' . DECISIONS[$d], $note ?: null, true);
            if ($action !== '') {
                set_next($id, $action, $return ? $return . ' 18:00:00' : in_business_days(1), $return ? $return . ' 18:00:00' : null);
            }
            return null;

        case 'contrato':
            $st = (string) ($in['contract_state'] ?? '');
            if (!isset(CONTRACT_STATES[$st])) {
                return 'Escolha a situação do contrato.';
            }
            $f = ['contract_state' => $st];
            if ($st === 'formalizado') {
                if ($c['decision'] !== 'aceitou') {
                    return 'Registre o aceite do cliente antes de formalizar a contratação.';
                }
                $file = (int) ($in['signed_file_id'] ?? 0);
                if (!$file || !scalar("SELECT 1 FROM files WHERE id = ? AND case_id = ? AND kind = 'assinado'", [$file, $id])) {
                    return 'Anexe o instrumento assinado antes de marcar como formalizado.';
                }
                if (($in['verified'] ?? '') !== '1') {
                    return 'Confirme que conferiu a assinatura e a eficácia do instrumento.';
                }
                $signed = parse_br_date((string) ($in['signed_at'] ?? '')) ?? now()->format('Y-m-d');
                $f += ['contract_file_id' => $file, 'contract_signed_at' => $signed, 'contract_verified_at' => now_str()];
                // A assinatura refere-se à versão exata do documento (seção 10.2).
                foreach (['honorarios', 'procuracao'] as $k) {
                    q("UPDATE case_artifact_versions SET signed_file_id = ? WHERE id = (SELECT id FROM case_artifact_versions WHERE case_id = ? AND kind = ? AND status IN ('revisado','publicado') ORDER BY version DESC LIMIT 1)", [$file, $id, $k]);
                }
            }
            bump_case($id, $f);
            add_event($id, 'contratacao', 'Contratação: ' . CONTRACT_STATES[$st], $note ?: null, $st === 'formalizado' || $st === 'assinatura_pendente');
            return null;

        case 'finalizar':
            if ($c['decision'] === 'pendente') {
                return 'Registre a decisão do cliente. Enquanto ele decide, o atendimento continua aguardando.';
            }
            if ($c['decision'] === 'aceitou') {
                $k = kit_status($c);
                if (!$k['ready']) {
                    return 'Kit ainda não está pronto: ' . implode('; ', $k['missing']) . '.';
                }
                if ($c['contract_state'] !== 'formalizado') {
                    return 'Aceite sem assinatura não é contratação. Registre o instrumento assinado e conferido.';
                }
                $start = (string) ($in['execution_stage'] ?? '');
                if (!in_array($start, ['negociacao', 'preparacao_judicial'], true)) {
                    return 'Escolha por onde começa a execução contratada.';
                }
                bump_case($id, [
                    'initial_status' => 'completed', 'initial_completed_at' => now_str(), 'execution_started_at' => now_str(),
                    'stage' => $start, 'stage_since' => now_str(), 'gestor_id' => $c['operator_id'], 'advogado_id' => $c['operator_id'],
                    'closure_reason' => $note ?: 'Contratação formalizada.',
                ]);
                set_check($id, 'contratacao', 'assinado', true, 'Atendimento inicial');
                set_check($id, 'contratacao', 'escopo_compreendido', true, 'Atendimento inicial');
                add_event($id, 'situacao', 'Atendimento inicial concluído', 'Contratação formalizada. Seu caso segue para ' . stage_label($start) . '. Concluir o atendimento inicial não significa que a dívida foi resolvida: agora começa a atuação contratada.', true);
                set_next($id, 'Iniciar ' . stage_label($start), in_business_days(2), in_business_days(5));
                return null;
            }
            if (mb_strlen($note) < 5) {
                return 'Registre a orientação dada e o motivo do encerramento.';
            }
            bump_case($id, ['initial_status' => 'completed', 'initial_completed_at' => now_str(), 'closure_reason' => $note, 'next_action' => 'Comunicar o encerramento ao cliente', 'next_action_due' => in_business_days(1)]);
            add_event($id, 'situacao', 'Atendimento inicial concluído', DECISIONS[$c['decision']] . '. ' . $note, true);
            return null;

        case 'encerrar_antes':
            $d = (string) ($in['decision'] ?? '');
            if (!in_array($d, ['recusou', 'desistiu', 'nao_recomendado'], true)) {
                return 'Escolha o motivo: recusa, desistência ou atuação não recomendada.';
            }
            if (mb_strlen($note) < 5) {
                return 'Registre a orientação dada ao cliente.';
            }
            if ($m = current_meeting($id)) {
                q("UPDATE case_meetings SET status = 'cancelada' WHERE id = ?", [$m['id']]);
            }
            bump_case($id, ['initial_status' => 'completed', 'initial_completed_at' => now_str(), 'decision' => $d, 'decision_at' => now_str(), 'closure_reason' => $note, 'next_action' => 'Comunicar o encerramento ao cliente', 'next_action_due' => in_business_days(1)]);
            add_event($id, 'situacao', 'Atendimento inicial concluído', DECISIONS[$d] . '. ' . $note, true);
            return null;

        case 'comunicar_encerramento':
            if ($c['initial_status'] !== 'completed' || $c['contract_state'] === 'formalizado') {
                return 'Só se comunica o encerramento de atendimento concluído sem contratação.';
            }
            $channel = trim((string) ($in['channel'] ?? ''));
            $at = dt(str_replace('T', ' ', (string) ($in['at'] ?? ''))) ?? now();
            if ($channel === '') {
                return 'Informe por qual meio o encerramento foi comunicado.';
            }
            if ($at > now()->modify('+5 minutes')) {
                return 'A comunicação não pode ter data futura.';
            }
            $exp = $at->modify('+' . CLOSURE_GRACE_HOURS . ' hours');
            bump_case($id, ['closure_communicated_at' => $at->format('Y-m-d H:i:s'), 'closure_channel' => mb_substr($channel, 0, 80), 'access_expires_at' => $exp->format('Y-m-d H:i:s'), 'next_action' => null, 'next_action_due' => null]);
            add_event($id, 'acesso', 'Encerramento comunicado', 'Por ' . $channel . '. O acesso a este atendimento na área do cliente fica disponível até ' . fmt_datetime($exp->format('Y-m-d H:i:s')) . '.', true);
            return null;
    }
    return 'Comando desconhecido.';
}

/* ---------- Alertas (seção 11.3) ---------- */

function initial_alerts(array $c): array
{
    $id = (int) $c['id'];
    $out = [];
    if ($c['urgency_signal'] && !$c['urgency_reviewed_at']) {
        $out[] = ['high', URGENCY_SIGNALS[$c['urgency_signal']] . ($c['urgency_reason'] ? ': ' . $c['urgency_reason'] : '')];
    }
    foreach (all("SELECT * FROM deadlines WHERE case_id = ? AND status = 'aberto' AND due_at <= ?", [$id, now()->modify('+2 days')->format('Y-m-d H:i:s')]) as $d) {
        $out[] = ['high', 'Prazo confirmado: ' . $d['description'] . ' (' . fmt_datetime($d['due_at']) . ')'];
    }
    if ($c['activity_at'] && (!$c['activity_seen_at'] || $c['activity_at'] > $c['activity_seen_at'])) {
        $out[] = ['mid', 'Mensagem, resposta ou documento novo'];
    }
    if ($c['initial_status'] !== 'completed') {
        if ($e = essential_open($id)) {
            $out[] = ['mid', 'Pendência essencial: ' . $e[0] . (count($e) > 1 ? ' e mais ' . (count($e) - 1) : '')];
        }
        $m = current_meeting($id);
        if ($m && dt($m['starts_at']) <= now()->modify('+1 day')) {
            $out[] = ['mid', 'Reunião ' . fmt_when(dt($m['starts_at']))];
        }
        if ($c['initial_status'] === 'meeting_scheduled' && !$m) {
            $out[] = ['mid', 'Reunião a reagendar'];
        }
        $due = $c['next_client_update'] ?: $c['next_action_due'];
        if ($due && dt($due) < now()) {
            $out[] = ['mid', 'Retorno vencido: ' . ($c['next_action'] ?: 'definir próxima ação')];
        }
    }
    if ($c['review_flag']) {
        $out[] = ['mid', 'Análise/kit precisa de revisão'];
    }
    if (kit_has_stale($id)) {
        $out[] = ['mid', 'Documento do kit com alterações não revisadas'];
    }
    if ($c['duplicate_of']) {
        $out[] = ['low', 'Possível duplicidade com outro atendimento'];
    }
    if ($c['return_requested_at']) {
        $out[] = ['mid', 'Cliente pediu novo atendimento'];
    }
    if ($c['initial_status'] === 'completed' && $c['contract_state'] !== 'formalizado' && !$c['closure_communicated_at']) {
        $out[] = ['mid', 'Comunicar o encerramento ao cliente'];
    }
    return $out;
}
