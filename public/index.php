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

// Hospedagem sem os requisitos mínimos: explica o que ativar, em vez de uma tela em branco.
$missing = environment_problems();
if ($missing) {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>Configuração pendente</title><body style="font:16px/1.6 system-ui;max-width:640px;margin:60px auto;padding:0 20px">'
        . '<h1>Falta um ajuste na hospedagem</h1><ul><li>' . implode('</li><li>', array_map('e', $missing)) . '</li></ul>'
        . '<p>No hPanel da Hostinger: <strong>Avançado → Configuração do PHP</strong>. Depois de salvar, recarregue esta página.</p></body>';
    exit;
}

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Frame-Options: SAMEORIGIN');
header('Permissions-Policy: camera=(), microphone=(self), geolocation=(self)'); // localização: prova opcional da assinatura eletrônica
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; script-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'");

start_session();

$router = new Router();
require APP_DIR . '/routes/site.php';
require APP_DIR . '/routes/portal.php';
require APP_DIR . '/routes/panel.php';
require APP_DIR . '/routes/inicial.php';
require APP_DIR . '/routes/inicial_painel.php';

// Avisos por e-mail de novidades nas conversas: rodada leve ao fim da
// requisição (no máximo uma por minuto), depois de entregar a resposta.
register_shutdown_function(static function (): void {
    try {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        notify_tick();
        transcripts_tick(); // transcrições do Meet das reuniões encerradas
    } catch (Throwable $e) {
        error_log('[fatuche] avisos: ' . $e->getMessage());
    }
});

try {
    $router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', request_path());
} catch (Throwable $e) {
    error_log('[fatuche] ' . $e->getMessage() . ' em ' . $e->getFile() . ':' . $e->getLine());
    try {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
    } catch (Throwable) {
    }
    view('site/error', [
        'title'   => 'Algo deu errado do nosso lado',
        'message' => 'Não foi culpa sua. Se você estava enviando informações, fale com a equipe pelo WhatsApp para confirmarmos se elas chegaram.',
    ], 'site', 500);
}
