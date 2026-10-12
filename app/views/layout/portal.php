<?php
/**
 * Área do cliente como aplicativo de conversas: a tela não rola; rolam só a
 * lista de atendimentos e a conversa aberta. No celular, lista e conversa
 * se alternam (como no WhatsApp).
 */
$title = $meta_title ?? 'Área do cliente';
$flashes = take_flashes();
$convs = $convs ?? [];
$listOnly = !empty($list_only);
$unreadTotal = array_sum(array_column($convs, 'unread'));
?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, interactive-widget=resizes-content">
<title><?= $unreadTotal ? '(' . (int) $unreadTotal . ') ' : '' ?><?= e($title) ?></title>
<meta name="robots" content="noindex">
<meta name="theme-color" content="#0F3B43">
<link rel="icon" href="<?= e(asset('img/favicon.svg')) ?>" type="image/svg+xml">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700;1,9..40,400&family=Fraunces:ital,opsz,wght@0,9..144,500;0,9..144,600;0,9..144,700;1,9..144,500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(asset('css/site.css')) ?>">
</head>
<body class="app-body">
<?php if ($flashes): ?>
<div class="app-flashes" role="status" data-flashes>
  <?php foreach ($flashes as $f): ?><p class="flash flash-<?= e($f['type']) ?>"><?= e($f['msg']) ?><button type="button" class="app-flash-x" aria-label="Fechar" data-flash-close>×</button></p><?php endforeach ?>
</div>
<?php endif ?>
<div class="app <?= $listOnly ? 'app--list' : 'app--open' ?>" data-app data-title="<?= e($title) ?>">
  <aside class="app-side" aria-label="Seus atendimentos">
    <header class="app-side-head">
      <a class="app-brand" href="<?= e(url('/')) ?>" aria-label="Sidney Fatuche Advocacia, página inicial">
        <?php partial('mark', ['size' => 30, 'light' => true]) ?>
        <span><strong>Sidney Fatuche</strong><small>Área do cliente</small></span>
      </a>
      <?php if (portal_account_id()): ?><a class="app-out" href="<?= e(url('cliente/sair')) ?>">Sair</a><?php endif ?>
    </header>
    <div class="app-side-title">
      <h1>Seus atendimentos</h1>
      <form method="post" action="<?= e(url('cliente/novo')) ?>">
        <?= csrf_field() ?>
        <button class="app-new" type="submit" title="Abrir uma nova conversa para outro contrato ou assunto">
          <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" d="M12 5v14M5 12h14"/></svg>
          Novo atendimento
        </button>
      </form>
    </div>
    <nav class="conv-list" data-conv-list data-poll="<?= e(url('cliente/conversas/novidades')) ?>">
      <?php foreach ($convs as $cv): ?>
        <a class="conv-item<?= $cv['current'] && !$listOnly ? ' is-on' : '' ?><?= $cv['unread'] ? ' has-new' : '' ?>" href="<?= e(url('cliente/trocar/' . $cv['id'])) ?>" data-conv="<?= (int) $cv['id'] ?>"<?= $cv['current'] && !$listOnly ? ' aria-current="page"' : '' ?>>
          <span class="conv-avatar" aria-hidden="true"><svg viewBox="0 0 24 24" width="22" height="22"><path fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" d="M5 17h14M6 17l1.5-5.2A2 2 0 0 1 9.4 10h5.2a2 2 0 0 1 1.9 1.8L18 17M7 17v2M17 17v2M8.5 14h.01M15.5 14h.01"/></svg></span>
          <span class="conv-main">
            <span class="conv-top"><strong><?= e($cv['title']) ?></strong><time datetime="<?= e(str_replace(' ', 'T', $cv['at'])) ?>"><?= e(conv_when($cv['at'])) ?></time></span>
            <span class="conv-bottom"><span class="conv-prev"><?= e($cv['preview'] ?: $cv['status']) ?></span><span class="conv-badge" data-badge<?= $cv['unread'] ? '' : ' hidden' ?> aria-label="<?= (int) $cv['unread'] ?> novidade(s)"><?= (int) $cv['unread'] ?></span></span>
            <small class="conv-meta"><?= e($cv['protocol']) ?> · <?= e($cv['status']) ?></small>
          </span>
        </a>
      <?php endforeach ?>
    </nav>
    <?php if (portal_account_id()): ?>
      <details class="app-side-foot">
        <summary>Avisos e acesso</summary>
        <form method="post" action="<?= e(url('cliente/avisos')) ?>" class="app-opt">
          <?= csrf_field() ?>
          <?php if (portal_notify_on()): ?>
            <span>Avisos por e-mail <strong>ligados</strong></span><button class="link-btn" type="submit" name="avisos" value="0">Desligar</button>
          <?php else: ?>
            <span>Avisos por e-mail <strong>desligados</strong></span><button class="link-btn" type="submit" name="avisos" value="1">Ligar</button>
          <?php endif ?>
        </form>
        <?php if (empty($has_password)): ?>
          <form method="post" action="<?= e(url('cliente/criar-senha')) ?>" class="app-pass" id="senha">
            <?= csrf_field() ?><input type="hidden" name="volta" value="conversas">
            <p>Crie uma senha para entrar de qualquer aparelho com o protocolo. Sem senha, você entra pelo link que enviamos por e-mail.</p>
            <input type="password" name="password" minlength="8" autocomplete="new-password" placeholder="Senha (mínimo 8)" aria-label="Senha" required>
            <input type="password" name="password2" minlength="8" autocomplete="new-password" placeholder="Repita a senha" aria-label="Repita a senha" required>
            <button class="btn btn-ghost btn-small" type="submit">Criar senha</button>
          </form>
        <?php endif ?>
        <p class="app-safe">Sidney só usa o WhatsApp <?= e(office('phone_display')) ?> e o e-mail <?= e(office('email')) ?>. Nunca pedimos senha do banco ou do Gov.br.</p>
      </details>
    <?php endif ?>
  </aside>

  <main class="app-main" id="conteudo">
    <?= $content ?>
  </main>
</div>
<script src="<?= e(asset('js/site.js')) ?>" defer></script>
<script src="<?= e(asset('js/chat.js')) ?>" defer></script>
</body>
</html>
