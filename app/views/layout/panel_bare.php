<?php $flashes = take_flashes(); ?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($meta_title ?? 'Painel') ?> · Fatuche</title>
<link rel="icon" href="<?= e(asset('img/favicon.svg')) ?>" type="image/svg+xml">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,400;9..40,600&family=Fraunces:opsz,wght@9..144,600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(asset('css/panel.css')) ?>">
</head>
<body class="bare">
<?php if ($flashes): ?><div class="flashes"><?php foreach ($flashes as $f): ?><p class="flash flash-<?= e($f['type']) ?>"><?= e($f['msg']) ?></p><?php endforeach ?></div><?php endif ?>
<?= $content ?>
</body>
</html>
