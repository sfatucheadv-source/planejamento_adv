<?php
$label = KIT_ITEMS[$v['kind']]['label'];
$miss = json_decode((string) $v['missing'], true) ?: [];
?>
<div class="bar">
  <span><?= e($label) ?> · versão <?= (int) $v['version'] ?> · <?= e(KIT_STATES[$v['status']] ?? $v['status']) ?> · <?= e($c['protocol']) ?></span>
  <span><a href="?baixar=1">Baixar</a> · <a href="#" data-print>Imprimir ou salvar em PDF</a></span>
</div>
<?php if (!$client && $miss): ?><p style="font:14px system-ui;color:#a43e2c">Falta: <?= e(implode('; ', $miss)) ?></p><?php endif ?>
<article class="doc"><?= kit_html((string) $v['content']) ?></article>
<p class="foot"><?= e($label) ?> · versão <?= (int) $v['version'] ?><?= $v['published_at'] ? ' · publicada em ' . e(fmt_datetime($v['published_at'])) : '' ?> · atendimento <?= e($c['protocol']) ?></p>
