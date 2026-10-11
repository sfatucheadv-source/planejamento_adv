<?php
declare(strict_types=1);

/**
 * Atendimento inicial (workflow simple_v1) — Plano de reforma v1.0, 10/10/2026.
 *
 * Um operador (Sidney), coleta por chatbot, cinco situações e nove atividades
 * dentro da mesma ficha. Os casos antigos continuam no workflow "legacy" e a
 * execução dos contratados reaproveita as etapas 7 a 14 existentes.
 * As seções citadas nos comentários seguem a numeração do plano.
 */

/* ---------- Configurações gravadas no banco (sem editar PHP) ---------- */

function setting(string $key, mixed $default = null): mixed
{
    static $cache = null;
    if ($key === '__reset') {
        $cache = null;
        return null;
    }
    if ($cache === null) {
        $cache = [];
        foreach (all('SELECT key, value FROM settings') as $r) {
            $cache[$r['key']] = $r['value'];
        }
    }
    return array_key_exists($key, $cache) && $cache[$key] !== null && $cache[$key] !== '' ? $cache[$key] : $default;
}

function set_setting(string $key, ?string $value): void
{
    q('INSERT INTO settings (key, value, updated_at) VALUES (?,?,?) ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at', [$key, $value, now_str()]);
    setting('__reset');
}

/** Seção 14.1 — o operador é identificado pelo ID configurado, nunca pelo "primeiro usuário com função". */
function operator_id(): ?int
{
    $id = (int) setting('operator_user_id', 0);
    return $id > 0 ? $id : null;
}

function single_operator(): bool
{
    return operator_id() !== null;
}

function is_operator(?array $u = null): bool
{
    $u ??= current_user();
    return $u && operator_id() === (int) $u['id'];
}

/** Seção 15.3 — chave de reversão: novas entradas pelo fluxo simples ou pelo formulário antigo. */
function intake_workflow(): string
{
    // Sem operador configurado (por exemplo, logo após atualizar uma instalação
    // antiga), as entradas continuam no formulário anterior: nenhum atendimento
    // novo fica sem dono.
    if (!single_operator()) {
        return 'legacy';
    }
    return setting('intake_workflow', 'simple_v1') === 'legacy' ? 'legacy' : 'simple_v1';
}

/** O caso está no atendimento inicial (e não na execução ou no legado)? */
function is_initial(array $c): bool
{
    return ($c['workflow_version'] ?? 'legacy') === 'simple_v1' && empty($c['execution_started_at']);
}

/** Predicado SQL das telas antigas: legado e contratados em execução. */
const SQL_EXECUTION = "(workflow_version = 'legacy' OR execution_started_at IS NOT NULL)";

/** Sidney pode operar este atendimento? Decide no servidor, pelo vínculo do caso. */
function can_operate(array $c, ?array $u = null): bool
{
    $u ??= current_user();
    if (!$u) {
        return false;
    }
    if (is_initial($c)) {
        return (int) ($c['operator_id'] ?? 0) === (int) $u['id'];
    }
    return true;
}

/* ---------- Seção 3.1 — cinco situações ---------- */

const INITIAL_STATUS = [
    'collecting'        => ['label' => 'Em coleta',          'client' => 'Vamos reunir suas informações e documentos.'],
    'analysis'          => ['label' => 'Em análise',         'client' => 'Sidney está analisando seu financiamento e preparando a conversa.'],
    'meeting_scheduled' => ['label' => 'Reunião agendada',   'client' => 'Sua reunião está confirmada. Veja o formato e o horário.'],
    'awaiting_decision' => ['label' => 'Aguardando decisão', 'client' => 'Veja a proposta e o próximo passo combinado.'],
    'completed'         => ['label' => 'Concluído',          'client' => 'Atendimento inicial concluído. Veja a decisão e a continuidade.'],
];

function initial_status_label(array $c, bool $client = false): string
{
    $s = $c['initial_status'] ?? 'collecting';
    if ($s === 'awaiting_decision' && $c['decision'] === 'aceitou' && $c['contract_state'] !== 'formalizado') {
        return $client ? 'Aguardando assinatura. Os documentos estão disponíveis abaixo.' : 'Aguardando assinatura';
    }
    if ($s === 'meeting_scheduled' && !current_meeting((int) $c['id'])) {
        return $client ? 'Vamos combinar um novo horário para a conversa.' : 'Reunião a reagendar';
    }
    return $client ? INITIAL_STATUS[$s]['client'] : INITIAL_STATUS[$s]['label'];
}

/* ---------- Seção 3.3 — decisão e contratação são campos separados ---------- */

const DECISIONS = [
    'pendente'        => 'Pendente',
    'aceitou'         => 'Aceitou a proposta',
    'recusou'         => 'Recusou',
    'desistiu'        => 'Desistiu',
    'nao_recomendado' => 'Atuação não recomendada',
];

const CONTRACT_STATES = [
    'nao_preparado'       => 'Não preparado',
    'minuta'              => 'Minuta',
    'enviado'             => 'Enviado ao cliente',
    'assinatura_pendente' => 'Assinatura pendente',
    'formalizado'         => 'Formalizado',
];

/* ---------- Seção 9 — canais da reunião ---------- */

const MEETING_CHANNELS = [
    'meet'          => ['label' => 'Vídeo pelo Google Meet', 'short' => 'Google Meet',       'action' => 'Entrar na reunião'],
    'phone'         => ['label' => 'Ligação telefônica',     'short' => 'Telefone',          'action' => 'Confirmar meu telefone'],
    'whatsapp_text' => ['label' => 'WhatsApp por texto',     'short' => 'WhatsApp (texto)',  'action' => 'Conversar no WhatsApp'],
    'site_chat'     => ['label' => 'Chat deste site',        'short' => 'Chat do site',      'action' => 'Abrir conversa com Sidney'],
];

/* ---------- Seção 10 — itens do kit ---------- */

const KIT_ITEMS = [
    'proposta'         => ['label' => 'Proposta de atendimento',           'template' => true],
    'honorarios'       => ['label' => 'Contrato de honorários',            'template' => true],
    'procuracao'       => ['label' => 'Procuração',                        'template' => true],
    'hipossuficiencia' => ['label' => 'Declaração de hipossuficiência',    'template' => true],
    'parecer'          => ['label' => 'Parecer de cálculos',               'template' => true],
    'ficha_inicial'    => ['label' => 'Ficha para adequação da inicial',   'template' => false],
];

const KIT_STATES = [
    'nao_preparado' => 'Não preparado',
    'minuta'        => 'Minuta',
    'revisado'      => 'Revisado',
    'publicado'     => 'Publicado',
    'nao_aplicavel' => 'Não aplicável',
];

const PENDING_TYPES = ['campo' => 'Campo', 'documento' => 'Documento', 'esclarecimento' => 'Esclarecimento'];
const PENDING_STATUS = ['aberta' => 'Aberta', 'resolvida' => 'Resolvida', 'nao_aplicavel' => 'Não se aplica'];

/** Carência de acesso do encerrado não contratado (seção 13.1). */
const CLOSURE_GRACE_HOURS = 72;

/* ---------- Utilidades ---------- */

function bump_case(int $caseId, array $fields = []): void
{
    $fields['updated_at'] = now_str();
    $set = implode(',', array_map(fn($c) => "$c = :$c", array_keys($fields)));
    $fields['__id'] = $caseId;
    q("UPDATE cases SET $set, row_version = row_version + 1 WHERE id = :__id", $fields);
}

/** Seção 14.2 — controle otimista: o formulário traz a versão que viu. */
function version_conflict(array $c): bool
{
    $v = input('row_version');
    return $v !== '' && (int) $v !== (int) $c['row_version'];
}

function brasilia(?string $v): string
{
    return $v ? fmt_datetime($v) . ' (horário de Brasília)' : '—';
}
