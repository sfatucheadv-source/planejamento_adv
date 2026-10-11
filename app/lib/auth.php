<?php
declare(strict_types=1);

const ROLES = [
    'gestor'     => 'Gestor do caso',
    'advogado'   => 'Advogado responsável',
    'calculos'   => 'Analista de cálculos',
    'negociador' => 'Negociador',
    'financeiro' => 'Financeiro',
    'supervisor' => 'Supervisor',
];

function current_user(): ?array
{
    static $cached = false;
    if ($cached !== false) {
        return $cached;
    }
    $id = $_SESSION['uid'] ?? null;
    $cached = $id ? one('SELECT * FROM users WHERE id = ? AND active = 1', [$id]) : null;
    return $cached;
}

function user_roles(?array $u = null): array
{
    $u ??= current_user();
    if (!$u) {
        return [];
    }
    // Operador único: executa todas as funções da execução contratada, sem matriz de papéis.
    if (function_exists('operator_id') && operator_id() === (int) $u['id']) {
        return array_keys(ROLES);
    }
    return array_filter(explode(',', $u['roles']));
}

function has_role(string ...$roles): bool
{
    return (bool) array_intersect($roles, user_roles());
}

function require_user(): array
{
    $u = current_user();
    if (!$u) {
        $_SESSION['after_login'] = $_SERVER['REQUEST_URI'] ?? url('painel');
        redirect('painel/entrar');
    }
    return $u;
}

function require_role(string ...$roles): void
{
    if (!has_role(...$roles)) {
        flash('error', 'Esta ação depende da função: ' . implode(' ou ', array_map(fn($r) => ROLES[$r] ?? $r, $roles)) . '.');
        back('painel');
    }
}

function too_many_attempts(string $bucket, int $max = 8, int $minutes = 15): bool
{
    $since = now()->modify("-$minutes minutes")->format('Y-m-d H:i:s');
    q('DELETE FROM login_attempts WHERE at < ?', [now()->modify('-1 day')->format('Y-m-d H:i:s')]);
    return (int) scalar('SELECT COUNT(*) FROM login_attempts WHERE bucket = ? AND at >= ?', [$bucket, $since]) >= $max;
}

function record_attempt(string $bucket): void
{
    insert('login_attempts', ['bucket' => $bucket, 'at' => now_str()]);
}

function client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

function login_user(array $u): void
{
    session_regenerate_id(true);
    $_SESSION['uid'] = (int) $u['id'];
}

function users_list(bool $onlyActive = true): array
{
    return all('SELECT * FROM users' . ($onlyActive ? ' WHERE active = 1' : '') . ' ORDER BY name');
}

function users_with_role(string $role): array
{
    return array_values(array_filter(users_list(), fn($u) => in_array($role, user_roles($u), true)));
}

function user_name(?int $id): string
{
    static $names = null;
    if ($names === null) {
        $names = [];
        foreach (all('SELECT id, name FROM users') as $r) {
            $names[(int) $r['id']] = $r['name'];
        }
    }
    return $id && isset($names[$id]) ? $names[$id] : '—';
}

/* ---------- Portal do cliente (seção 13: acesso por atendimento) ---------- */

/**
 * Caso aberto na área do cliente, revalidado a cada requisição: vínculo com a
 * conta (ou com a sessão privada da coleta) e carência de acesso.
 */
function portal_case(): ?array
{
    $id = $_SESSION['portal_case'] ?? $_SESSION['intake_case'] ?? null;
    if (!$id) {
        return null;
    }
    $c = one('SELECT * FROM cases WHERE id = ?', [(int) $id]);
    return $c && client_case_authorized($c) ? $c : null;
}

function portal_account_id(): ?int
{
    $a = $_SESSION['portal_account'] ?? null;
    return $a ? (int) $a : null;
}

/** O caso está contratado (acesso permanece) ou é do fluxo antigo? */
function case_contracted(array $c): bool
{
    return ($c['workflow_version'] ?? 'legacy') === 'legacy' || !empty($c['execution_started_at']) || ($c['contract_state'] ?? '') === 'formalizado';
}

/** Carência vencida: a negação vale pela data, mesmo sem rotina agendada (S05). */
function case_access_expired(array $c): bool
{
    if (case_contracted($c) || empty($c['access_expires_at'])) {
        return false;
    }
    return now() >= dt($c['access_expires_at']);
}

function client_case_authorized(array $c): bool
{
    $acc = portal_account_id();
    $bound = (int) ($_SESSION['intake_case'] ?? 0) === (int) $c['id'];
    $linked = $acc !== null && (int) ($c['account_id'] ?? 0) === $acc;
    if (!$bound && !$linked) {
        return false;
    }
    return !case_access_expired($c);
}

/** Atendimentos que a conta pode abrir agora. */
function account_cases(int $accountId): array
{
    return array_values(array_filter(
        all('SELECT * FROM cases WHERE account_id = ? ORDER BY id DESC', [$accountId]),
        fn($c) => !case_access_expired($c)
    ));
}

function require_portal(): array
{
    $c = portal_case();
    if (!$c) {
        redirect(portal_account_id() ? 'cliente/sem-acesso' : 'cliente/entrar');
    }
    return $c;
}

function login_account(int $accountId, ?int $caseId = null): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
    $_SESSION['portal_account'] = $accountId;
    if ($caseId) {
        $_SESSION['portal_case'] = $caseId;
    } else {
        unset($_SESSION['portal_case']);
    }
    q('UPDATE client_accounts SET last_login_at = ? WHERE id = ?', [now_str(), $accountId]);
}

/** Cria (ou reaproveita) a conta do caso e entra com ela. */
function login_portal(int $caseId, ?string $newPassHash = null): void
{
    $c = one('SELECT * FROM cases WHERE id = ?', [$caseId]);
    $acc = $c['account_id'] ? (int) $c['account_id'] : null;
    if (!$acc) {
        $acc = insert('client_accounts', ['name' => $c['client_name'], 'phone' => $c['client_phone'], 'email' => $c['client_email'], 'pass_hash' => $newPassHash, 'created_at' => now_str()]);
        q('UPDATE cases SET account_id = ? WHERE id = ?', [$acc, $caseId]);
    } elseif ($newPassHash) {
        q('UPDATE client_accounts SET pass_hash = ? WHERE id = ?', [$newPassHash, $acc]);
    }
    if ($newPassHash) {
        q('UPDATE cases SET portal_pass_hash = ? WHERE id = ?', [$newPassHash, $caseId]);
    }
    unset($_SESSION['intake_case']);
    login_account($acc, $caseId);
}

function logout_portal(): void
{
    unset($_SESSION['portal_account'], $_SESSION['portal_case'], $_SESSION['intake_case']);
    session_regenerate_id(true);
}
