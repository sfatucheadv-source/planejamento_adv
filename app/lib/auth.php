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
    return $u ? array_filter(explode(',', $u['roles'])) : [];
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

/* ---------- Portal do cliente ---------- */

function portal_case(): ?array
{
    $id = $_SESSION['portal_case'] ?? null;
    return $id ? one('SELECT * FROM cases WHERE id = ?', [$id]) : null;
}

function require_portal(): array
{
    $c = portal_case();
    if (!$c) {
        redirect('cliente/entrar');
    }
    return $c;
}

function login_portal(int $caseId): void
{
    session_regenerate_id(true);
    $_SESSION['portal_case'] = $caseId;
}
