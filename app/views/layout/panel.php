<?php
$u = current_user();
$path = request_path();
$flashes = take_flashes();
$openTasks = $u ? (int) scalar("SELECT COUNT(*) FROM tasks WHERE status = 'aberta' AND (owner_id = ? OR owner_id IS NULL) AND due_at <= ?", [$u['id'], now()->format('Y-m-d 23:59:59')]) : 0;
$operator = $u && is_operator($u);
$queueCount = $u ? count(guide_queue($u)[0]) : 0;
$attCount = $operator ? (int) scalar("SELECT COUNT(*) FROM cases WHERE workflow_version = 'simple_v1' AND execution_started_at IS NULL AND operator_id = ? AND initial_status <> 'completed' AND ((activity_at IS NOT NULL AND (activity_seen_at IS NULL OR activity_at > activity_seen_at)) OR (urgency_signal IS NOT NULL AND urgency_reviewed_at IS NULL))", [$u['id']]) : 0;
$icons = [
    'list' => 'M9 6h11M9 12h11M9 18h11M4 6h.01M4 12h.01M4 18h.01',
    'pulse' => 'M3 12h4l3 8 4-16 3 8h4',
    'rows' => 'M4 6h16M4 12h16M4 18h10',
    'check' => 'M9 11l3 3L22 4M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11',
    'plus' => 'M12 5v14M5 12h14',
    'bars' => 'M4 20V10M10 20V4M16 20v-7M22 20H2',
    'chat' => 'M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z',
    'flow' => 'M6 3v12M18 9a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM6 21a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM18 9a9 9 0 0 1-9 9',
    'team' => 'M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75',
    'doc' => 'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zM14 2v6h6M8 13h8M8 17h6',
    'coin' => 'M12 1v22M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6',
    'braces' => 'M8 3H7a2 2 0 0 0-2 2v5a2 2 0 0 1-2 2 2 2 0 0 1 2 2v5a2 2 0 0 0 2 2h1M16 3h1a2 2 0 0 1 2 2v5a2 2 0 0 0 2 2 2 2 0 0 0-2 2v5a2 2 0 0 1-2 2h-1',
    'gear' => 'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z',
];
// Operador único: atendimento inicial em primeiro lugar; execução dos contratados em seguida.
$nav = $operator ? [
    'painel/atendimentos' => ['Atendimentos', $icons['list']],
    'painel/atendimentos/novo' => ['Registrar atendimento', $icons['plus']],
    'painel/fila' => ['Execução', $icons['pulse']],
    'painel/casos' => ['Casos contratados', $icons['rows']],
    'painel/tarefas' => ['Tarefas', $icons['check']],
    'painel/modelos-documentos' => ['Modelos de documentos', $icons['doc']],
    'painel/propostas' => ['Propostas', $icons['coin']],
    'painel/variaveis' => ['Variáveis dos documentos', $icons['braces']],
    'painel/modelos' => ['Mensagens', $icons['chat']],
    'painel/indicadores' => ['Indicadores', $icons['bars']],
    'painel/configuracoes' => ['Configurações', $icons['gear']],
] : [
    'painel/fila' => ['Minha fila', $icons['list']],
    'painel' => ['Visão geral', $icons['pulse']],
    'painel/casos' => ['Casos', $icons['rows']],
    'painel/tarefas' => ['Tarefas', $icons['check']],
    'painel/casos/novo' => ['Novo atendimento', $icons['plus']],
    'painel/indicadores' => ['Indicadores', $icons['bars']],
    'painel/modelos' => ['Mensagens', $icons['chat']],
    'painel/fluxo' => ['Fluxo de trabalho', $icons['flow']],
    'painel/equipe' => ['Equipe', $icons['team']],
    'painel/configuracoes' => ['Configurações', $icons['gear']],
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
    <a class="side-brand" href="<?= e(url($operator ? 'painel/atendimentos' : 'painel/fila')) ?>"><?php partial('mark', ['size' => 28, 'light' => true]) ?><span>Fatuche<small><?= $operator ? 'Painel de Sidney' : 'Painel da equipe' ?></small></span></a>
    <nav class="side-nav">
      <?php foreach ($nav as $href => [$label, $icon]):
        $active = $href === 'painel' ? $path === '/painel' : ($href === 'painel/fila' && str_starts_with($path, '/painel/executar'))
            || ($href === 'painel/atendimentos' && str_starts_with($path, '/painel/atendimento/'))
            || (str_starts_with($path, '/' . $href) && !($href === 'painel/casos' && str_starts_with($path, '/painel/casos/novo')) && !($href === 'painel/atendimentos' && str_starts_with($path, '/painel/atendimentos/novo')) && !($href === 'painel/modelos' && str_starts_with($path, '/painel/modelos-')));
      ?>
        <a class="<?= $active ? 'is-active' : '' ?>" href="<?= e(url($href)) ?>"><svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="<?= $icon ?>" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg><?= e($label) ?><?php if ($href === 'painel/fila' && $queueCount): ?><span class="pill"><?= $queueCount ?></span><?php endif ?><?php if ($href === 'painel/atendimentos' && $attCount): ?><span class="pill"><?= $attCount ?></span><?php endif ?><?php if ($href === 'painel/tarefas' && $openTasks): ?><span class="pill"><?= $openTasks ?></span><?php endif ?></a>
      <?php endforeach ?>
    </nav>
    <div class="side-foot">
      <p class="avail <?= is_open() ? 'on' : '' ?>"><span></span><?= is_open() ? 'Em expediente' : 'Fora do expediente · retorno ' . e(fmt_when(next_opening())) ?></p>
      <?php if ($u): ?>
        <p class="me"><strong><?= e($u['name']) ?></strong><small><?= $operator ? 'Operador dos atendimentos' : e(implode(' · ', array_map(fn($r) => ROLES[$r] ?? $r, user_roles($u)))) ?></small></p>
        <p class="me-links"><a href="<?= e(url('painel/conta')) ?>">Minha conta</a> · <a href="<?= e(url('painel/sair')) ?>">Sair</a> · <a href="<?= e(url('/')) ?>" target="_blank">Ver site</a></p>
      <?php endif ?>
    </div>
  </aside>
  <div class="main">
    <header class="topbar">
      <button class="side-toggle" type="button" data-side-toggle aria-label="Abrir menu">☰</button>
      <form class="top-search" method="get" action="<?= e(url($operator ? 'painel/atendimentos' : 'painel/casos')) ?>"><input type="search" name="q" placeholder="Buscar nome, protocolo, telefone<?= $operator ? ' ou contrato' : ' ou banco' ?>" value="<?= e((string) input('q')) ?>" aria-label="Buscar"><?php if (!$operator): ?><input type="hidden" name="status" value="todos"><?php endif ?></form>
    </header>
    <?php if ($u && !single_operator() && !str_starts_with($path, '/painel/configuracoes')): ?><div class="flashes"><p class="flash">Novo atendimento inicial (assistente virtual e ficha única) disponível: escolha o operador em <a href="<?= e(url('painel/configuracoes')) ?>">Configurações</a> para ativá-lo. Até lá, o site continua com o formulário anterior.</p></div><?php endif ?>
    <?php if ($flashes): ?><div class="flashes"><?php foreach ($flashes as $f): ?><p class="flash flash-<?= e($f['type']) ?>"><?= e($f['msg']) ?></p><?php endforeach ?></div><?php endif ?>
    <main class="content"><?= $content ?></main>
  </div>
</div>
<script src="<?= e(asset('js/panel.js')) ?>" defer></script>
<script src="<?= e(asset('js/chat.js')) ?>" defer></script>
</body>
</html>
