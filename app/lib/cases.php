<?php
declare(strict_types=1);

/* ---------- Dados auxiliares do caso (JSON) ---------- */

function case_data(array $c, ?string $key = null, mixed $default = null): mixed
{
    $data = json_decode($c['data'] ?: '{}', true) ?: [];
    if ($key === null) {
        return $data;
    }
    return $data[$key] ?? $default;
}

function set_case_data(int $caseId, string $key, mixed $value): void
{
    $c = one('SELECT data FROM cases WHERE id = ?', [$caseId]);
    $data = json_decode($c['data'] ?? '{}', true) ?: [];
    $data[$key] = $value;
    q('UPDATE cases SET data = ?, updated_at = ? WHERE id = ?', [json_encode($data, JSON_UNESCAPED_UNICODE), now_str(), $caseId]);
}

function find_case(int $id): array
{
    $c = one('SELECT * FROM cases WHERE id = ?', [$id]);
    if (!$c) {
        not_found();
    }
    return $c;
}

function touch_case(int $caseId, array $fields = []): void
{
    $fields['updated_at'] = now_str();
    update('cases', $caseId, $fields);
}

/* ---------- Linha do tempo e tarefas ---------- */

function add_event(int $caseId, string $type, string $title, ?string $body = null, bool $visible = false, ?string $actor = null): void
{
    $u = current_user();
    insert('events', [
        'case_id' => $caseId,
        'at' => now_str(),
        'actor' => $actor ?? ($u ? 'equipe' : 'sistema'),
        'user_id' => $u['id'] ?? null,
        'type' => $type,
        'title' => $title,
        'body' => $body,
        'visible_to_client' => $visible ? 1 : 0,
    ]);
    if ($visible && ($actor ?? 'equipe') !== 'cliente') {
        q('UPDATE cases SET last_client_update = ? WHERE id = ?', [now_str(), $caseId]);
    }
}

function add_task(?int $caseId, string $title, ?int $ownerId, ?DateTimeImmutable $due, string $dependsOn = 'equipe', ?string $kind = null): int
{
    return insert('tasks', [
        'case_id' => $caseId,
        'title' => $title,
        'owner_id' => $ownerId,
        'due_at' => $due?->format('Y-m-d H:i:s'),
        'depends_on' => $dependsOn,
        'kind' => $kind,
        'status' => 'aberta',
        'created_by' => current_user()['id'] ?? null,
        'created_at' => now_str(),
    ]);
}

function close_tasks_of_kind(int $caseId, string $kind, string $evidence): void
{
    q("UPDATE tasks SET status = 'concluida', done_at = ?, evidence = ? WHERE case_id = ? AND kind = ? AND status = 'aberta'", [now_str(), $evidence, $caseId, $kind]);
}

/* ---------- Triagem do site → abertura do caso ---------- */

function triage_urgency(array $a): array
{
    $notice = $a['notice'] ?? '';
    return match ($notice) {
        'apreendido', 'justica' => [true, 'apreensao_prazo'],
        'cartorio', 'banco'     => [true, 'notificacao'],
        'nao_sei'               => [true, null],
        default => ($a['contract_status'] ?? '') === 'quitado' || ($a['overdue'] ?? '') === 'nao'
            ? [false, 'em_dia']
            : [false, ($a['overdue'] ?? '') === 'sim' ? 'atraso' : null],
    };
}

function summary_sentence(array $a): string
{
    $prio = PRIORITY_OPTIONS[$a['priority'] ?? 'outro']['sentence'] ?? 'resolver sua situação';
    $parts = ['Sua prioridade é ' . $prio];
    if (!empty($a['budget_installment']) && in_array($a['priority'] ?? '', ['manter_veiculo', 'reduzir_parcela', 'quitar', 'apreensao'], true)) {
        $parts[] = 'com uma parcela de até ' . money((float) $a['budget_installment']) . ' sem apertar suas despesas essenciais';
    } elseif (($a['priority'] ?? '') === 'manter_veiculo') {
        $parts[] = 'e encontrar uma prestação que consiga pagar';
    }
    $s = implode(' ', $parts);
    if (in_array($a['notice'] ?? '', ['apreendido', 'justica', 'cartorio'], true)) {
        $s .= '. Como há ' . match ($a['notice']) {
            'apreendido' => 'uma apreensão',
            'justica' => 'um documento da Justiça',
            default => 'uma notificação de cartório',
        } . ', o advogado vai olhar o seu caso primeiro';
    }
    return $s . '. Correto?';
}

function initial_documents(array $a): array
{
    $docs = ['contrato', 'historico', 'titular', 'veiculo'];
    if (in_array($a['notice'] ?? '', ['apreendido', 'justica'], true)) {
        array_unshift($docs, 'processo');
    }
    if (in_array($a['notice'] ?? '', ['apreendido', 'justica', 'cartorio', 'banco', 'nao_sei'], true)) {
        $docs[] = 'notificacoes';
    }
    if (($a['priority'] ?? '') === 'venda_saldo') {
        $docs[] = 'venda';
        $docs[] = 'despesas';
    }
    return array_values(array_unique($docs));
}

function new_protocol(): string
{
    do {
        $p = 'SF-' . random_code(6);
    } while (scalar('SELECT 1 FROM cases WHERE protocol = ?', [$p]));
    return $p;
}

/**
 * Cria o registro único do caso a partir da triagem do site.
 * Retorna o caso recém-criado.
 */
function create_case_from_triage(array $a, string $source = 'site'): array
{
    [$urgent, $class] = triage_urgency($a);
    $now = now();
    $ts = $now->format('Y-m-d H:i:s');

    $gestor = users_with_role('gestor')[0]['id'] ?? null;
    $advogado = users_with_role('advogado')[0]['id'] ?? null;

    db()->beginTransaction();
    $id = insert('cases', [
        'protocol' => new_protocol(),
        'created_at' => $ts,
        'updated_at' => $ts,
        'source' => $source,
        'origin_page' => mb_substr((string) ($a['origin'] ?? ''), 0, 120),
        'client_name' => $a['name'],
        'client_phone' => $a['phone'],
        'client_email' => $a['email'] ?: null,
        'client_city' => $a['city'] ?: null,
        'client_uf' => $a['uf'] ?: null,
        'contact_pref' => $a['contact_pref'] ?: null,
        'contact_time' => $a['contact_time'] ?: null,
        'accessibility' => $a['accessibility'] ?: null,
        'notice' => $a['notice'],
        'notice_date' => $a['notice_date'] ?: null,
        'bank' => $a['bank'] ?: null,
        'contract_status' => $a['contract_status'] ?: null,
        'installment_value' => $a['installment_value'],
        'overdue' => $a['overdue'] ?: null,
        'overdue_count' => $a['overdue_count'] ?: null,
        'has_contract' => $a['has_contract'] ?: null,
        'other_lawyer' => $a['other_lawyer'] ?: null,
        'priority' => $a['priority'],
        'priority_text' => $a['priority_text'] ?: null,
        'budget_installment' => $a['budget_installment'],
        'summary_sentence' => $a['summary'],
        'summary_confirmed' => $a['summary_confirmed'] ? 1 : 0,
        'description' => $a['description'] ?: null,
        'consent_at' => $ts,
        'stage' => 'acolhimento',
        'stage_since' => $ts,
        'urgent' => $urgent ? 1 : 0,
        'urgency_class' => $class,
        'gestor_id' => $gestor,
        'advogado_id' => $advogado,
        'next_action' => 'Primeiro contato humano com o cliente',
        'next_action_due' => add_business_hours($now, 1)->format('Y-m-d H:i:s'),
        'next_action_owner_id' => $gestor,
        'next_client_update' => add_business_hours($now, 1)->format('Y-m-d H:i:s'),
        'data' => '{}',
    ]);

    foreach (initial_documents($a) as $kind) {
        $status = 'falta';
        if ($kind === 'contrato' && ($a['has_contract'] ?? '') === 'nao') {
            $status = 'ajuda';
        }
        insert('documents', [
            'case_id' => $id,
            'kind' => $kind,
            'label' => DOC_CATALOG[$kind]['label'],
            'status' => $status,
            'essential' => in_array($kind, ['contrato', 'historico', 'processo', 'notificacoes', 'venda', 'despesas'], true) ? 1 : 0,
            'requested_at' => $ts,
        ]);
    }

    add_event($id, 'abertura', 'Solicitação recebida pelo site', 'Registro único aberto a partir da triagem do site. ' . ($a['summary_confirmed'] ? 'O cliente confirmou o resumo.' : 'O cliente pediu ajuste no resumo.'), true, 'cliente');
    add_task($id, 'Primeiro contato humano (meta: 1 hora útil)', $gestor, add_business_hours($now, 1), 'equipe', 'primeiro_contato');
    if ($urgent) {
        add_task($id, 'Avaliação inicial de urgência pelo advogado (meta: 2 horas úteis)', $advogado, add_business_hours($now, 2), 'equipe', 'avaliacao_urgencia');
        add_event($id, 'urgencia', 'Caso marcado como urgente na triagem', $class ? URGENCY_CLASSES[$class]['label'] : 'O cliente não soube dizer se há notificação ou apreensão. Revisão conservadora pelo advogado.');
    }
    db()->commit();

    return find_case($id);
}

/* ---------- Checklists e condições de avanço ---------- */

function case_checks(int $caseId, string $stage): array
{
    $out = [];
    foreach (all('SELECT * FROM case_checks WHERE case_id = ? AND stage = ?', [$caseId, $stage]) as $r) {
        $out[$r['item']] = $r;
    }
    return $out;
}

function set_check(int $caseId, string $stage, string $item, bool $done, ?string $note = null): void
{
    $u = current_user();
    q(
        'INSERT INTO case_checks (case_id, stage, item, done, note, done_by, done_at) VALUES (?,?,?,?,?,?,?)
         ON CONFLICT(case_id, stage, item) DO UPDATE SET done = excluded.done, note = COALESCE(excluded.note, note), done_by = excluded.done_by, done_at = excluded.done_at',
        [$caseId, $stage, $item, $done ? 1 : 0, $note, $u['id'] ?? null, $done ? now_str() : null]
    );
}

/**
 * Lista o que falta para sair da etapa atual.
 * @return array<int,array{label:string,block:bool}>
 */
function gate_problems(array $c, ?string $stageKey = null): array
{
    $stageKey ??= $c['stage'];
    $s = stage($stageKey);
    $checks = case_checks((int) $c['id'], $stageKey);
    $problems = [];
    foreach ($s['gates'] as $key => $g) {
        if (empty($checks[$key]['done'])) {
            $problems[] = ['label' => $g['label'], 'block' => $g['block']];
        }
    }
    foreach ($s['needs'] as $need) {
        $p = match ($need) {
            'first_contact' => $c['first_human_contact_at'] ? null : 'Registrar o primeiro contato humano com o cliente',
            'team' => ($c['gestor_id'] && $c['advogado_id']) ? null : 'Atribuir gestor e advogado ao caso',
            'objective' => trim((string) $c['objective']) !== '' ? null : 'Registrar o objetivo prioritário e como ele será comprovado',
            'urgency_class' => $c['urgency_class'] && $c['urgency_assessed_at'] ? null : 'Advogado precisa classificar a urgência',
            'audit_validated' => !empty(case_data($c, 'audit')['validated_at']) ? null : 'Cálculo e parecer precisam ser validados pelo advogado',
            'path_decision' => scalar("SELECT 1 FROM decisions WHERE case_id = ? AND kind = 'caminho' AND answer IS NOT NULL", [$c['id']]) ? null : 'Decisão do cliente sobre o caminho precisa estar documentada',
            'solution_evidence' => solution_ready($c) ? null : 'Cada solução aplicável precisa de evidência conferida',
            'closing_list' => closing_ready($c) ? null : 'Lista obrigatória de encerramento incompleta',
            'outcome' => $c['outcome'] ? null : 'Escolher a categoria do desfecho',
            default => null,
        };
        if ($p) {
            $problems[] = ['label' => $p, 'block' => true];
        }
    }
    return $problems;
}

function solution_ready(array $c): bool
{
    $items = case_data($c, 'solution', []);
    $applicable = array_filter($items, fn($i) => !empty($i['applies']));
    if (!$applicable) {
        return false;
    }
    foreach ($applicable as $i) {
        if (empty($i['done'])) {
            return false;
        }
    }
    return true;
}

function closing_ready(array $c): bool
{
    $checks = case_checks((int) $c['id'], 'encerramento');
    foreach (array_keys(CLOSING_LIST) as $k) {
        if (empty($checks[$k]['done'])) {
            return false;
        }
    }
    return true;
}

function move_stage(array $c, string $to, string $reason = ''): bool
{
    $from = $c['stage'];
    if (!isset(stages()[$to])) {
        return false;
    }
    $allowed = stage($from)['next'];
    $isForward = in_array($to, $allowed, true);
    $blocking = array_filter(gate_problems($c), fn($p) => $p['block']);
    // Voltar a uma etapa anterior (ex.: documento faltante) é permitido com motivo.
    $isBack = stage($to)['n'] < stage($from)['n'];
    if (!$isForward && !$isBack) {
        flash('error', 'Esse caminho não parte da etapa atual. Caminhos possíveis: ' . implode(', ', array_map('stage_label', $allowed)) . '.');
        return false;
    }
    if ($isForward && $blocking && !$isBack) {
        flash('error', 'Antes de avançar: ' . implode('; ', array_map(fn($p) => $p['label'], $blocking)) . '.');
        return false;
    }
    if ($isBack && trim($reason) === '') {
        flash('error', 'Informe o motivo do retorno e a próxima ação.');
        return false;
    }
    touch_case((int) $c['id'], ['stage' => $to, 'stage_since' => now_str()]);
    $phaseFrom = stage($from)['phase'];
    $phaseTo = stage($to)['phase'];
    add_event((int) $c['id'], 'etapa', 'Etapa: ' . stage_label($from) . ' → ' . stage_label($to), $reason ?: null);
    if ($phaseFrom !== $phaseTo) {
        // O cliente vê apenas a mudança de situação, com uma frase simples.
        $verb = $isBack ? 'Seu caso voltou para' : 'Seu caso avançou para';
        add_event((int) $c['id'], 'fase', $verb . ': ' . CLIENT_PHASES[$phaseTo]['label'], CLIENT_PHASES[$phaseTo]['text'], true);
    }
    audit('etapa', (int) $c['id'], "$from → $to" . ($reason ? " · $reason" : ''));
    return true;
}

/* ---------- Metas internas e alertas (seção 4) ---------- */

/**
 * @return array<int,array{level:string,text:string,miss:string,case_id:int}>
 */
function case_alerts(array $c): array
{
    if ($c['status'] !== 'ativo') {
        return [];
    }
    $now = now();
    $out = [];
    $created = dt($c['created_at']);
    $id = (int) $c['id'];

    if (!$c['first_human_contact_at'] && business_hours_between($created, $now) > 1) {
        $out[] = ['level' => 'high', 'text' => 'Primeiro contato humano passou de 1 hora útil', 'miss' => SERVICE_GOALS[0]['miss'], 'case_id' => $id];
    }
    if ($c['urgent'] && !$c['urgency_assessed_at'] && business_hours_between($created, $now) > 2) {
        $out[] = ['level' => 'high', 'text' => 'Urgência sem avaliação do advogado há mais de 2 horas úteis', 'miss' => SERVICE_GOALS[1]['miss'], 'case_id' => $id];
    }
    foreach (all('SELECT uploaded_at FROM files WHERE case_id = ? AND uploaded_by_client = 1 AND reviewed_at IS NULL', [$id]) as $f) {
        if (business_hours_between(dt($f['uploaded_at']), $now) > (close_hour() - open_hour())) {
            $out[] = ['level' => 'mid', 'text' => 'Documento enviado pelo cliente sem conferência há mais de 1 dia útil', 'miss' => SERVICE_GOALS[2]['miss'], 'case_id' => $id];
            break;
        }
    }
    if ($c['docs_sufficient_at'] && empty(case_data($c, 'audit')['validated_at'])
        && in_array($c['stage'], ['documentos', 'auditoria'], true)
        && $now > add_business_days(dt($c['docs_sufficient_at']), 2)) {
        $out[] = ['level' => 'mid', 'text' => 'Análise passou de 2 dias úteis após documentos suficientes', 'miss' => SERVICE_GOALS[3]['miss'], 'case_id' => $id];
    }
    foreach (all("SELECT created_at, valid_until FROM offers WHERE case_id = ? AND communicated_at IS NULL AND status = 'recebida'", [$id]) as $o) {
        if ($now > add_business_days(dt($o['created_at']), 1)) {
            $out[] = ['level' => 'high', 'text' => 'Oferta do credor ainda não comunicada ao cliente (meta: 1 dia útil)', 'miss' => SERVICE_GOALS[4]['miss'], 'case_id' => $id];
        }
        if ($o['valid_until'] && dt($o['valid_until']) < $now->modify('+2 days')) {
            $out[] = ['level' => 'high', 'text' => 'Oferta vence em breve (' . fmt_date($o['valid_until']) . ')', 'miss' => EXCEPTIONS['oferta_expira']['action'], 'case_id' => $id];
        }
    }
    $last = dt($c['last_client_update'] ?: $c['created_at']);
    $cycle = (int) config('update_cycle_days', 15);
    if ($last < $now->modify("-$cycle days")) {
        $out[] = ['level' => 'mid', 'text' => "Cliente sem atualização há mais de $cycle dias", 'miss' => SERVICE_GOALS[5]['miss'], 'case_id' => $id];
    }
    if (!trim((string) $c['next_action']) || !$c['next_action_due']) {
        $out[] = ['level' => 'mid', 'text' => 'Caso sem próxima ação com data e responsável', 'miss' => 'Um cartão “aguardando” sem tarefa e data é incompleto.', 'case_id' => $id];
    } elseif (dt($c['next_action_due']) < $now) {
        $out[] = ['level' => 'mid', 'text' => 'Próxima ação vencida: ' . $c['next_action'], 'miss' => 'Avisar o cliente antes do vencimento e registrar nova data e responsável.', 'case_id' => $id];
    }
    foreach (all("SELECT * FROM deadlines WHERE case_id = ? AND status = 'aberto' AND due_at <= ?", [$id, $now->modify('+2 days')->format('Y-m-d H:i:s')]) as $d) {
        $out[] = ['level' => 'high', 'text' => 'Prazo jurídico: ' . $d['description'] . ' (' . fmt_datetime($d['due_at']) . ')', 'miss' => 'Prazos legais curtos exigem prioridade e substituição.', 'case_id' => $id];
    }
    foreach (all("SELECT * FROM schedule WHERE case_id = ? AND status = 'pendente' AND due_date <= ?", [$id, $now->modify('+3 days')->format('Y-m-d')]) as $p) {
        $out[] = ['level' => 'mid', 'text' => 'Pagamento do acordo vence ' . fmt_date($p['due_date']) . ': ' . $p['description'], 'miss' => 'Avisar o cliente três dias antes e confirmar o comprovante.', 'case_id' => $id];
    }
    $rounds = (int) scalar('SELECT COUNT(DISTINCT round) FROM offers WHERE case_id = ?', [$id]);
    if ($c['stage'] === 'negociacao' && ($rounds >= 2 || $now > add_business_days(dt($c['stage_since']), 10))
        && !scalar("SELECT 1 FROM offers WHERE case_id = ? AND status = 'aceita'", [$id])
        && empty(case_checks($id, 'negociacao')['revisao_estrategia']['done'])) {
        $out[] = ['level' => 'mid', 'text' => 'Duas rodadas ou 10 dias úteis sem avanço na negociação', 'miss' => 'O advogado revisa a estratégia.', 'case_id' => $id];
    }
    foreach (all('SELECT kind FROM exceptions WHERE case_id = ? AND resolved_at IS NULL', [$id]) as $x) {
        $out[] = ['level' => !empty(EXCEPTIONS[$x['kind']]['urgent']) ? 'high' : 'mid', 'text' => 'Exceção aberta: ' . EXCEPTIONS[$x['kind']]['label'], 'miss' => EXCEPTIONS[$x['kind']]['action'], 'case_id' => $id];
    }
    return $out;
}

/* ---------- Visão do cliente ---------- */

function client_phase_index(array $c): int
{
    $keys = array_keys(CLIENT_PHASES);
    return (int) array_search(stage($c['stage'])['phase'], $keys, true);
}

function client_documents(int $caseId): array
{
    return all("SELECT * FROM documents WHERE case_id = ? AND status <> 'dispensado' ORDER BY essential DESC, id", [$caseId]);
}

/* ---------- Cálculos financeiros (etapa 4) ---------- */

function annual_from_monthly(float $monthlyPct): float
{
    return ((1 + $monthlyPct / 100) ** 12 - 1) * 100;
}

function monthly_from_annual(float $annualPct): float
{
    return ((1 + $annualPct / 100) ** (1 / 12) - 1) * 100;
}

/** Prestação pela Tabela Price. */
function price_installment(float $principal, float $monthlyPct, int $n): float
{
    $i = $monthlyPct / 100;
    if ($n <= 0) {
        return 0.0;
    }
    if ($i == 0.0) {
        return $principal / $n;
    }
    return $principal * $i / (1 - (1 + $i) ** -$n);
}

/** Taxa mensal implícita (Newton) que reproduz a prestação informada. */
function implied_monthly_rate(float $principal, float $installment, int $n): ?float
{
    if ($principal <= 0 || $installment <= 0 || $n <= 0 || $installment * $n < $principal) {
        return null;
    }
    $i = 0.02;
    for ($k = 0; $k < 100; $k++) {
        $f = price_installment($principal, $i * 100, $n) - $installment;
        $d = (price_installment($principal, ($i + 1e-7) * 100, $n) - price_installment($principal, $i * 100, $n)) / 1e-7;
        if (abs($d) < 1e-12) {
            break;
        }
        $next = $i - $f / $d;
        if (abs($next - $i) < 1e-10) {
            $i = $next;
            break;
        }
        $i = max(1e-9, $next);
    }
    return $i * 100;
}

/** Saldo devedor após k prestações pagas (Price). */
function price_balance(float $principal, float $monthlyPct, int $n, int $paid): float
{
    $i = $monthlyPct / 100;
    $pmt = price_installment($principal, $monthlyPct, $n);
    if ($i == 0.0) {
        return max(0, $principal - $pmt * $paid);
    }
    return max(0, $principal * (1 + $i) ** $paid - $pmt * (((1 + $i) ** $paid - 1) / $i));
}

function offer_total(array $o): ?float
{
    if ($o['total'] !== null && $o['total'] !== '') {
        return (float) $o['total'];
    }
    if ($o['installments'] !== null && $o['installment_value'] !== null) {
        return (float) ($o['down_payment'] ?? 0) + (int) $o['installments'] * (float) $o['installment_value'];
    }
    return $o['down_payment'] !== null ? (float) $o['down_payment'] : null;
}

/* ---------- Arquivos ---------- */

const ALLOWED_MIME = [
    'application/pdf' => 'pdf',
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
    'image/heic' => 'heic',
    'image/heif' => 'heif',
];

/** Áudios aceitos só na conversa do atendimento (gravados no navegador ou enviados como arquivo). */
const ALLOWED_AUDIO_MIME = [
    'audio/wav' => 'wav', 'audio/x-wav' => 'wav', 'audio/wave' => 'wav', 'audio/vnd.wave' => 'wav',
    'audio/mpeg' => 'mp3', 'audio/mp3' => 'mp3', 'audio/ogg' => 'ogg', 'audio/webm' => 'webm', 'video/webm' => 'webm',
    'audio/mp4' => 'm4a', 'audio/x-m4a' => 'm4a', 'audio/aac' => 'aac', 'audio/x-hx-aac-adts' => 'aac', 'audio/flac' => 'flac', 'audio/x-flac' => 'flac',
];

/** Tipo real do arquivo enviado (não o informado pelo navegador). */
function upload_mime(array $file): string
{
    return is_file((string) ($file['tmp_name'] ?? '')) ? ((new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) ?: '') : '';
}

/**
 * Grava um arquivo enviado. Retorna o id ou uma mensagem de erro.
 */
function store_upload(array $file, int $caseId, ?int $documentId, bool $byClient, bool $allowAudio = false): int|string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return match ($file['error'] ?? 0) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'O arquivo é maior que o limite de ' . config('upload_max_mb') . ' MB.',
            UPLOAD_ERR_NO_FILE => 'Escolha um arquivo para enviar.',
            default => 'O envio não foi concluído. Tente de novo; se continuar, mande pelo WhatsApp.',
        };
    }
    if ($file['size'] > config('upload_max_mb') * 1024 * 1024) {
        return 'O arquivo é maior que o limite de ' . config('upload_max_mb') . ' MB.';
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) ?: '';
    $types = ALLOWED_MIME + ($allowAudio ? ALLOWED_AUDIO_MIME : []);
    if (!isset($types[$mime])) {
        return $allowAudio ? 'Aceitamos PDF, fotos (JPG, PNG, WEBP, HEIC) ou áudio.' : 'Aceitamos PDF ou fotos (JPG, PNG, WEBP, HEIC).';
    }
    $dir = config('storage_dir') . '/uploads/' . $caseId;
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }
    $stored = bin2hex(random_bytes(16)) . '.' . $types[$mime];
    $ok = is_uploaded_file($file['tmp_name'])
        ? move_uploaded_file($file['tmp_name'], $dir . '/' . $stored)
        : rename($file['tmp_name'], $dir . '/' . $stored);
    if (!$ok) {
        return 'Não conseguimos gravar o arquivo. Tente de novo em instantes.';
    }
    $name = preg_replace('/[^\pL\pN ._()-]+/u', '_', basename((string) $file['name'])) ?: 'arquivo';
    return insert('files', [
        'case_id' => $caseId,
        'document_id' => $documentId,
        'original_name' => mb_substr($name, 0, 150),
        'stored_name' => $stored,
        'mime' => $mime,
        'size' => (int) $file['size'],
        'uploaded_by_client' => $byClient ? 1 : 0,
        'uploaded_by' => $byClient ? null : (current_user()['id'] ?? null),
        'uploaded_at' => now_str(),
    ]);
}

/** Normaliza o array $_FILES de um campo múltiplo. */
function uploaded_files(string $field): array
{
    $f = $_FILES[$field] ?? null;
    if (!$f) {
        return [];
    }
    if (!is_array($f['name'])) {
        return [$f];
    }
    $out = [];
    foreach ($f['name'] as $i => $name) {
        if (($f['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $out[] = ['name' => $name, 'type' => $f['type'][$i], 'tmp_name' => $f['tmp_name'][$i], 'error' => $f['error'][$i], 'size' => $f['size'][$i]];
    }
    return $out;
}

/**
 * Cálculos de apoio da auditoria (etapa 4). Tudo aqui é estimativa:
 * a memória de cálculo validada pelo advogado é que vale.
 */
function audit_calc(array $a): array
{
    $f = fn(string $k) => isset($a[$k]) && $a[$k] !== '' ? parse_money((string) $a[$k]) : null;
    $principal = $f('principal');
    $n = isset($a['installments']) && $a['installments'] !== '' ? (int) $a['installments'] : null;
    $pmt = $f('installment_value');
    $im = $f('monthly_rate');
    $bcb = $f('bcb_rate');
    $paid = isset($a['paid_installments']) && $a['paid_installments'] !== '' ? (int) $a['paid_installments'] : null;
    $out = [];
    $out['annual_from_monthly'] = $im !== null ? annual_from_monthly($im) : null;
    $out['implied_rate'] = ($principal && $pmt && $n) ? implied_monthly_rate($principal, $pmt, $n) : null;
    $out['pmt_at_contract'] = ($principal && $im !== null && $n) ? price_installment($principal, $im, $n) : null;
    $out['reproduces'] = ($out['pmt_at_contract'] !== null && $pmt) ? abs($out['pmt_at_contract'] - $pmt) <= max(1.0, $pmt * 0.005) : null;
    $rate = $im ?? $out['implied_rate'];
    $out['ratio'] = ($rate !== null && $bcb) ? $rate / $bcb : null;
    $out['bcb_annual'] = $bcb !== null ? annual_from_monthly($bcb) : null;
    $out['pmt_at_bcb'] = ($principal && $bcb !== null && $n) ? price_installment($principal, $bcb, $n) : null;
    $out['diff_per_installment'] = ($out['pmt_at_bcb'] !== null && $pmt) ? $pmt - $out['pmt_at_bcb'] : null;
    $out['diff_paid'] = ($out['diff_per_installment'] !== null && $paid !== null) ? $out['diff_per_installment'] * $paid : null;
    $out['balance_contract'] = ($principal && $rate !== null && $n && $paid !== null) ? price_balance($principal, $rate, $n, min($paid, $n)) : null;
    $out['balance_bcb'] = ($principal && $bcb !== null && $n && $paid !== null) ? price_balance($principal, $bcb, $n, min($paid, $n)) : null;
    $charges = 0.0;
    foreach (['tac', 'evaluation_fee', 'registration_fee', 'insurance', 'other_charges'] as $k) {
        $charges += $f($k) ?? 0.0;
    }
    $out['charges'] = $charges ?: null;
    $out['charges_share'] = ($charges && $principal) ? $charges / $principal * 100 : null;
    $out['total_contract'] = ($pmt && $n) ? $pmt * $n + ($f('down_payment') ?? 0) : null;
    return $out;
}
