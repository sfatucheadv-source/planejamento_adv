<?php
$u = current_user();
$path = request_path();
$flashes = take_flashes();
$openTasks = $u ? (int) scalar("SELECT COUNT(*) FROM tasks WHERE status = 'aberta' AND (owner_id = ? OR owner_id IS NULL) AND due_at <= ?", [$u['id'], now()->format('Y-m-d 23:59:59')]) : 0;
$nav = [
    'painel' => ['Hoje', 'M3 12h4l3 8 4-16 3 8h4'],
    'painel/casos' => ['Casos', 'M4 6h16M4 12h16M4 18h10'],
    'painel/tarefas' => ['Tarefas', 'M9 11l3 3L22 4M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11'],
    'painel/casos/novo' => ['Novo atendimento', 'M12 5v14M5 12h14'],
    'painel/indicadores' => ['Indicadores', 'M4 20V10M10 20V4M16 20v-7M22 20H2'],
    'painel/modelos' => ['Mensagens', 'M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z'],
    'painel/fluxo' => ['Fluxo de trabalho', 'M6 3v12M18 9a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM6 21a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM18 9a9 9 0 0 1-9 9'],
    'painel/equipe' => ['Equipe', 'M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75'],
];
?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($meta_title ?? 'Painel') ?> · Fatuche</title>
<link rel="icon" href="<?= e(asset('img/favicon.svg')) ?>" type="image/svg+xml">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600;9..40,700&family=Fraunces:opsz,wght@9..144,600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(asset('css/panel.css')) ?>">
</head>
<body>
<div class="app">
  <aside class="side" data-side>
    <a class="side-brand" href="<?= e(url('painel')) ?>"><?php partial('mark', ['size' => 28, 'light' => true]) ?><span>Fatuche<small>Painel da equipe</small></span></a>
    <nav class="side-nav">
      <?php foreach ($nav as $href => [$label, $icon]):
        $active = $href === 'painel' ? $path === '/painel' : str_starts_with($path, '/' . $href) && !($href === 'painel/casos' && str_starts_with($path, '/painel/casos/novo'));
      ?>
        <a class="<?= $active ? 'is-active' : '' ?>" href="<?= e(url($href)) ?>"><svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="<?= $icon ?>" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg><?= e($label) ?><?php if ($href === 'painel/tarefas' && $openTasks): ?><span class="pill"><?= $openTasks ?></span><?php endif ?></a>
      <?php endforeach ?>
    </nav>
    <div class="side-foot">
      <p class="avail <?= is_open() ? 'on' : '' ?>"><span></span><?= is_open() ? 'Em expediente' : 'Fora do expediente · retorno ' . e(fmt_when(next_opening())) ?></p>
      <?php if ($u): ?>
        <p class="me"><strong><?= e($u['name']) ?></strong><small><?= e(implode(' · ', array_map(fn($r) => ROLES[$r] ?? $r, user_roles($u)))) ?></small></p>
        <p class="me-links"><a href="<?= e(url('painel/conta')) ?>">Minha conta</a> · <a href="<?= e(url('painel/sair')) ?>">Sair</a> · <a href="<?= e(url('/')) ?>" target="_blank">Ver site</a></p>
      <?php endif ?>
    </div>
  </aside>
  <div class="main">
    <header class="topbar">
      <button class="side-toggle" type="button" data-side-toggle aria-label="Abrir menu">☰</button>
      <form class="top-search" method="get" action="<?= e(url('painel/casos')) ?>"><input type="search" name="q" placeholder="Buscar cliente, protocolo, telefone ou banco" value="<?= e((string) input('q')) ?>"><input type="hidden" name="status" value="todos"></form>
    </header>
    <?php if ($flashes): ?><div class="flashes"><?php foreach ($flashes as $f): ?><p class="flash flash-<?= e($f['type']) ?>"><?= e($f['msg']) ?></p><?php endforeach ?></div><?php endif ?>
    <main class="content"><?= $content ?></main>
  </div>
</div>
<script src="<?= e(asset('js/panel.js')) ?>" defer></script>
</body>
</html>
