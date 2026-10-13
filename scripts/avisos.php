<?php
declare(strict_types=1);

/**
 * Envia os avisos por e-mail de novidades nas conversas dos clientes.
 * O site já faz isso sozinho com as visitas; para garantir o envio mesmo sem
 * movimento, agende no cron da hospedagem a cada 5 minutos:
 *   php /caminho/do/site/scripts/avisos.php
 */

require dirname(__DIR__) . '/app/bootstrap.php';

$n = notify_tick(true);
echo $n . " aviso(s) enviado(s)\n";
$t = transcripts_tick(true);
echo $t . " transcrição(ões) procurada(s)\n";
