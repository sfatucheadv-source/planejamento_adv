<?php
declare(strict_types=1);

// Servidor embutido do PHP (desenvolvimento): entrega arquivos estáticos diretamente.
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if (is_file($file) && !str_ends_with($file, '.php')) {
        return false;
    }
}

require dirname(__DIR__) . '/app/bootstrap.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Frame-Options: SAMEORIGIN');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; script-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'");

start_session();

$router = new Router();
require APP_DIR . '/routes/site.php';
require APP_DIR . '/routes/portal.php';
require APP_DIR . '/routes/panel.php';

try {
    $router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', request_path());
} catch (Throwable $e) {
    error_log('[fatuche] ' . $e->getMessage() . ' em ' . $e->getFile() . ':' . $e->getLine());
    if (db()->inTransaction()) {
        db()->rollBack();
    }
    view('site/error', [
        'title'   => 'Algo deu errado do nosso lado',
        'message' => 'Não foi culpa sua. Se você estava enviando informações, fale com a equipe pelo WhatsApp para confirmarmos se elas chegaram.',
    ], 'site', 500);
}
