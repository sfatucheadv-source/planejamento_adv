<?php
declare(strict_types=1);

function config(string $key, mixed $default = null): mixed
{
    $node = $GLOBALS['CONFIG'];
    foreach (explode('.', $key) as $part) {
        if (!is_array($node) || !array_key_exists($part, $node)) {
            return $default;
        }
        $node = $node[$part];
    }
    return $node;
}

function office(string $key): string
{
    return (string) config('office.' . $key, '');
}

/** Escapa texto para HTML. */
function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Caminho base em que o site está publicado (vazio na raiz do domínio). */
function base_path(): string
{
    static $base = null;
    if ($base === null) {
        $base = rtrim((string) ($_SERVER['APP_BASE'] ?? ''), '/');
    }
    return $base;
}

function url(string $path = '/', array $query = []): string
{
    $u = base_path() . '/' . ltrim($path, '/');
    if ($query) {
        $u .= '?' . http_build_query($query);
    }
    return $u;
}

function absolute_url(string $path = '/'): string
{
    return rtrim(office('base_url'), '/') . '/' . ltrim($path, '/');
}

function asset(string $path): string
{
    $file = ROOT_DIR . '/public/assets/' . ltrim($path, '/');
    $v = is_file($file) ? substr((string) filemtime($file), -6) : '1';
    return url('assets/' . ltrim($path, '/')) . '?v=' . $v;
}

function redirect(string $path, int $code = 303): never
{
    $target = str_starts_with($path, 'http') ? $path : url($path);
    header('Location: ' . $target, true, $code);
    exit;
}

function back(string $fallback = '/'): never
{
    $ref = $_SERVER['HTTP_REFERER'] ?? '';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($ref && $host && parse_url($ref, PHP_URL_HOST) === $host) {
        header('Location: ' . $ref, true, 303);
        exit;
    }
    redirect($fallback);
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function input(string $key, mixed $default = ''): mixed
{
    $v = $_POST[$key] ?? $_GET[$key] ?? $default;
    return is_string($v) ? trim($v) : $v;
}

function input_int(string $key): ?int
{
    $v = input($key, null);
    return ($v === null || $v === '') ? null : (int) $v;
}

/** Converte "1.234,56" ou "1234.56" em float. */
function input_money(string $key): ?float
{
    return parse_money((string) input($key, ''));
}

function parse_money(string $v): ?float
{
    $v = trim(str_replace(['R$', ' ', "\u{a0}", '%'], '', $v));
    if ($v === '') {
        return null;
    }
    if (str_contains($v, ',')) {
        $v = str_replace('.', '', $v);
        $v = str_replace(',', '.', $v);
    }
    return is_numeric($v) ? (float) $v : null;
}

function money(?float $v, bool $blankAsPending = true): string
{
    if ($v === null) {
        return $blankAsPending ? 'a apurar' : '';
    }
    return 'R$ ' . number_format($v, 2, ',', '.');
}

function pct(?float $v, int $dec = 2): string
{
    return $v === null ? '—' : number_format($v, $dec, ',', '.') . '%';
}

function now(): DateTimeImmutable
{
    if (!empty($GLOBALS['FAKE_NOW'])) {
        return new DateTimeImmutable($GLOBALS['FAKE_NOW']);
    }
    return new DateTimeImmutable('now');
}

function now_str(): string
{
    return now()->format('Y-m-d H:i:s');
}

function dt(?string $v): ?DateTimeImmutable
{
    if (!$v) {
        return null;
    }
    try {
        return new DateTimeImmutable($v);
    } catch (Exception) {
        return null;
    }
}

const MESES = ['', 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
const SEMANA = ['domingo', 'segunda-feira', 'terça-feira', 'quarta-feira', 'quinta-feira', 'sexta-feira', 'sábado'];

function fmt_date(?string $v, bool $long = false): string
{
    $d = dt($v);
    if (!$d) {
        return '—';
    }
    if ($long) {
        return (int) $d->format('j') . ' de ' . MESES[(int) $d->format('n')] . ' de ' . $d->format('Y');
    }
    return $d->format('d/m/Y');
}

function fmt_datetime(?string $v): string
{
    $d = dt($v);
    return $d ? $d->format('d/m/Y \à\s H\hi') : '—';
}

function fmt_when(?DateTimeImmutable $d): string
{
    if (!$d) {
        return '—';
    }
    $today = now()->setTime(0, 0);
    $day = $d->setTime(0, 0);
    $diff = (int) $today->diff($day)->format('%r%a');
    $hour = $d->format('H\hi');
    return match ($diff) {
        0 => 'hoje às ' . $hour,
        1 => 'amanhã às ' . $hour,
        default => SEMANA[(int) $d->format('w')] . ', ' . $d->format('d/m') . ' às ' . $hour,
    };
}

/** Tempo relativo curto: "há 3 h", "em 2 dias". */
function relative(?string $v): string
{
    $d = dt($v);
    if (!$d) {
        return '—';
    }
    $sec = $d->getTimestamp() - now()->getTimestamp();
    $abs = abs($sec);
    $txt = match (true) {
        $abs < 90 => 'agora',
        $abs < 3600 => round($abs / 60) . ' min',
        $abs < 86400 => round($abs / 3600) . ' h',
        default => round($abs / 86400) . ' dia' . (round($abs / 86400) > 1 ? 's' : ''),
    };
    if ($txt === 'agora') {
        return $txt;
    }
    return $sec < 0 ? 'há ' . $txt : 'em ' . $txt;
}

function random_code(int $len = 6, string $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'): string
{
    $out = '';
    $max = strlen($alphabet) - 1;
    for ($i = 0; $i < $len; $i++) {
        $out .= $alphabet[random_int(0, $max)];
    }
    return $out;
}

function only_digits(string $v): string
{
    return preg_replace('/\D+/', '', $v) ?? '';
}

function first_name(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [''];
    return $parts[0];
}

function whatsapp_link(string $text = '', ?string $number = null): string
{
    $n = $number ? only_digits($number) : office('whatsapp');
    if ($number && strlen($n) <= 11) {
        $n = '55' . $n;
    }
    return 'https://wa.me/' . $n . ($text !== '' ? '?text=' . rawurlencode($text) : '');
}

/* ---------- Sessão, flash e CSRF ---------- */

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $dir = config('storage_dir') . '/sessions';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    if (is_dir($dir) && is_writable($dir)) {
        session_save_path($dir);
    }
    session_name('fatuche');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => base_path() . '/',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.gc_maxlifetime', '28800');
    session_start();
}

function flash(string $type, string $msg): void
{
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $msg];
}

function take_flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(24));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    $t = $_POST['_csrf'] ?? '';
    if (!is_string($t) || !hash_equals($_SESSION['csrf'] ?? '', $t)) {
        http_response_code(419);
        echo render('site/error', ['title' => 'Sessão expirada', 'message' => 'Sua sessão expirou por segurança. Volte à página anterior, atualize e tente novamente. Nada do que você escreveu foi perdido no nosso sistema, porque ainda não havia sido enviado.'], 'site');
        exit;
    }
}

/* ---------- Views ---------- */

function render(string $template, array $data = [], ?string $layout = null): string
{
    $file = APP_DIR . '/views/' . $template . '.php';
    if (!is_file($file)) {
        throw new RuntimeException('View inexistente: ' . $template);
    }
    $content = (static function (string $__file, array $__data): string {
        extract($__data, EXTR_SKIP);
        ob_start();
        require $__file;
        return (string) ob_get_clean();
    })($file, $data);

    if ($layout === null) {
        return $content;
    }
    $data['content'] = $content;
    return render('layout/' . $layout, $data);
}

function partial(string $template, array $data = []): void
{
    echo render('partials/' . $template, $data);
}

function view(string $template, array $data = [], string $layout = 'site', int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    echo render($template, $data, $layout);
    exit;
}

function json_out(mixed $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function not_found(): never
{
    view('site/error', [
        'title'   => 'Página não encontrada',
        'message' => 'O endereço que você abriu não existe mais ou foi digitado com alguma diferença. Você pode voltar ao início ou falar diretamente com a equipe.',
    ], 'site', 404);
}

function forbidden(): never
{
    view('site/error', [
        'title'   => 'Acesso não permitido',
        'message' => 'Você não tem permissão para abrir esta página.',
    ], 'site', 403);
}

/**
 * Foto real do escritório. Se o arquivo ainda não foi colocado em
 * public/assets/fotos, devolve null para que a página use o layout alternativo.
 */
function photo_url(string $name): ?string
{
    foreach (['jpg', 'jpeg', 'webp', 'png'] as $ext) {
        if (is_file(ROOT_DIR . '/public/assets/fotos/' . $name . '.' . $ext)) {
            return asset('fotos/' . $name . '.' . $ext);
        }
    }
    return null;
}

function selected(mixed $a, mixed $b): string
{
    return (string) $a === (string) $b ? ' selected' : '';
}

function checked(bool $c): string
{
    return $c ? ' checked' : '';
}

function nl2p(string $text): string
{
    $parts = preg_split("/\n\s*\n/", trim($text)) ?: [];
    return implode('', array_map(fn($p) => '<p>' . nl2br(e(trim($p))) . '</p>', $parts));
}

function plural(int $n, string $one, string $many): string
{
    return $n . ' ' . ($n === 1 ? $one : $many);
}
