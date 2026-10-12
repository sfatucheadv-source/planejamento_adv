<?php
declare(strict_types=1);

define('APP_DIR', __DIR__);
define('ROOT_DIR', dirname(__DIR__));

date_default_timezone_set('America/Sao_Paulo');
if (function_exists('mb_internal_encoding')) {
    mb_internal_encoding('UTF-8');
}

$GLOBALS['CONFIG'] = require APP_DIR . '/config.php';
if (is_file(APP_DIR . '/config.local.php')) {
    $GLOBALS['CONFIG'] = array_replace_recursive($GLOBALS['CONFIG'], require APP_DIR . '/config.local.php');
}

require APP_DIR . '/lib/core.php';
require APP_DIR . '/lib/db.php';
require APP_DIR . '/lib/calendar.php';
require APP_DIR . '/lib/auth.php';
require APP_DIR . '/lib/router.php';
require APP_DIR . '/workflow.php';
require APP_DIR . '/messages.php';
require APP_DIR . '/lib/cases.php';
require APP_DIR . '/guide.php';
require APP_DIR . '/inicial/base.php';
require APP_DIR . '/inicial/campos.php';
require APP_DIR . '/inicial/variaveis.php';
require APP_DIR . '/inicial/propostas.php';
require APP_DIR . '/inicial/asaas.php';
require APP_DIR . '/inicial/fluxo.php';
require APP_DIR . '/inicial/bot.php';
require APP_DIR . '/inicial/ia.php';
require APP_DIR . '/inicial/agente.php';
require APP_DIR . '/inicial/conversas.php';
require APP_DIR . '/inicial/kit.php';
require APP_DIR . '/inicial/diagramacao.php';
require APP_DIR . '/inicial/migracao.php';
require APP_DIR . '/lib/content.php';
require APP_DIR . '/lib/ui.php';
