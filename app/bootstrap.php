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
require APP_DIR . '/lib/content.php';
require APP_DIR . '/lib/ui.php';
