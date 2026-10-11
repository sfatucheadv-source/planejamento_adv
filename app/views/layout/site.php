<?php
$title = $meta_title ?? 'Sidney Fatuche Advocacia';
$desc = $meta_desc ?? '';
$path = request_path();
$open = is_open();
$nextOpen = next_opening();
$waText = 'Olá! Vim pelo site e gostaria de falar sobre meu financiamento de veículo.';
$nav = [
    'atendimento' => 'Como funciona',
    'blog' => 'Blog',
    'sobre' => 'Quem atende',
    'duvidas' => 'Dúvidas',
];
$flashes = take_flashes();
?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?></title>
<?php if ($desc): ?><meta name="description" content="<?= e($desc) ?>"><?php endif ?>
<?php if (!empty($noindex)): ?><meta name="robots" content="noindex"><?php endif ?>
<link rel="canonical" href="<?= e(absolute_url($canonical ?? ltrim($path, '/'))) ?>">
<meta property="og:title" content="<?= e($title) ?>">
<meta property="og:description" content="<?= e($desc) ?>">
<meta property="og:type" content="<?= e($og_type ?? 'website') ?>">
<meta property="og:locale" content="pt_BR">
<meta property="og:site_name" content="Sidney Fatuche Advocacia">
<meta property="og:image" content="<?= e(absolute_url('assets/img/og.jpg')) ?>">
<meta name="theme-color" content="#0F3B43">
<link rel="icon" href="<?= e(asset('img/favicon.svg')) ?>" type="image/svg+xml">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700;1,9..40,400&family=Fraunces:ital,opsz,wght@0,9..144,500;0,9..144,600;0,9..144,700;1,9..144,500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(asset('css/site.css')) ?>">
<script type="application/ld+json"><?= json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'LegalService',
    'name' => office('brand'),
    'url' => office('base_url'),
    'telephone' => '+' . office('whatsapp'),
    'email' => office('email'),
    'areaServed' => 'BR',
    'address' => ['@type' => 'PostalAddress', 'addressLocality' => 'São José dos Pinhais', 'addressRegion' => 'PR', 'addressCountry' => 'BR'],
    'founder' => ['@type' => 'Person', 'name' => office('lawyer'), 'jobTitle' => 'Advogado', 'identifier' => office('lawyer_oab')],
    'openingHours' => 'Mo-Fr ' . sprintf('%02d:00-%02d:00', open_hour(), close_hour()),
    'sameAs' => ['https://www.instagram.com/' . office('instagram') . '/'],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
</head>
<body class="<?= e($body_class ?? '') ?>">
<a class="skip" href="#conteudo">Ir para o conteúdo</a>

<header class="site-header" data-header>
  <div class="wrap header-row">
    <a class="brand" href="<?= e(url('/')) ?>" aria-label="Sidney Fatuche Advocacia, página inicial">
      <?php partial('mark', ['size' => 34]) ?>
      <span class="brand-text"><strong>Sidney Fatuche</strong><small>Advocacia · OAB/PR 131.033</small></span>
    </a>
    <button class="menu-toggle" type="button" aria-expanded="false" aria-controls="menu" data-menu-toggle>
      <span></span><span></span><span></span><span class="sr">Abrir menu</span>
    </button>
    <nav id="menu" class="main-nav" aria-label="Principal">
      <div class="nav-group">
        <button type="button" class="nav-link has-sub" aria-expanded="false" data-sub-toggle>Seu caso</button>
        <div class="subnav">
          <?php foreach (situations() as $slug => $s): ?>
            <a href="<?= e(url($slug)) ?>"><?= e($s['menu']) ?></a>
          <?php endforeach ?>
          <a href="<?= e(url('comecar')) ?>">Outra situação</a>
        </div>
      </div>
      <?php foreach ($nav as $href => $label): ?>
        <a class="nav-link<?= str_starts_with($path, '/' . $href) ? ' is-active' : '' ?>" href="<?= e(url($href)) ?>"><?= e($label) ?></a>
      <?php endforeach ?>
      <a class="nav-link nav-client" href="<?= e(url('cliente')) ?>">Área do cliente</a>
      <a class="btn btn-gold nav-cta" href="<?= e(url('comecar')) ?>">Contar meu caso</a>
    </nav>
  </div>
</header>

<?php if ($flashes): ?>
<div class="wrap flashes" role="status">
  <?php foreach ($flashes as $f): ?><p class="flash flash-<?= e($f['type']) ?>"><?= e($f['msg']) ?></p><?php endforeach ?>
</div>
<?php endif ?>

<main id="conteudo">
<?= $content ?>
</main>

<footer class="site-footer">
  <div class="wrap footer-grid">
    <div class="footer-brand">
      <a class="brand brand-light" href="<?= e(url('/')) ?>">
        <?php partial('mark', ['size' => 34, 'light' => true]) ?>
        <span class="brand-text"><strong>Sidney Fatuche</strong><small>Advocacia</small></span>
      </a>
      <p>Financiamento de veículos: revisão, negociação, busca e apreensão e prestação de contas da venda. Atendimento digital para todo o Brasil, com base em <?= e(office('city')) ?>.</p>
      <p class="availability <?= $open ? 'is-open' : '' ?>">
        <span class="dot" aria-hidden="true"></span>
        <?= $open ? 'Sidney em atendimento agora' : 'Fora do horário. Retornamos ' . e(fmt_when($nextOpen)) ?>
      </p>
    </div>
    <div>
      <h2 class="footer-title">Fale com o escritório</h2>
      <ul class="footer-list">
        <li><a href="<?= e(whatsapp_link($waText)) ?>" rel="noopener" target="_blank">WhatsApp <?= e(office('phone_display')) ?></a></li>
        <li><a href="mailto:<?= e(office('email')) ?>"><?= e(office('email')) ?></a></li>
        <li><a href="https://www.instagram.com/<?= e(office('instagram')) ?>/" rel="noopener" target="_blank">Instagram @<?= e(office('instagram')) ?></a></li>
        <li>Dias úteis, das <?= open_hour() ?>h às <?= close_hour() ?>h (Brasília)</li>
      </ul>
    </div>
    <div>
      <h2 class="footer-title">Navegue</h2>
      <ul class="footer-list">
        <?php foreach (situations() as $slug => $s): ?><li><a href="<?= e(url($slug)) ?>"><?= e($s['menu']) ?></a></li><?php endforeach ?>
        <li><a href="<?= e(url('blog')) ?>">Blog</a></li>
        <li><a href="<?= e(url('cliente')) ?>">Área do cliente</a></li>
      </ul>
    </div>
    <div>
      <h2 class="footer-title">Transparência</h2>
      <ul class="footer-list">
        <li><a href="<?= e(url('seguranca')) ?>">Segurança e canais oficiais</a></li>
        <li><a href="<?= e(url('privacidade')) ?>">Privacidade</a></li>
        <li><a href="<?= e(url('termos')) ?>">Termos de uso</a></li>
        <li><a href="<?= e(office('oab_lookup_url')) ?>" rel="noopener" target="_blank">Confira a inscrição na OAB</a></li>
      </ul>
    </div>
  </div>
  <div class="wrap footer-legal">
    <p><?= e(mb_strtoupper(office('society'))) ?> · <?= e(office('society_oab')) ?> · CNPJ <?= e(office('cnpj')) ?> · <?= e(office('lawyer')) ?> · <?= e(office('lawyer_oab')) ?></p>
    <p>Conteúdo informativo. Cada caso exige análise individual dos documentos. Não prometemos resultado: explicamos caminhos, custos e riscos para você decidir.</p>
  </div>
</footer>

<a class="wa-float" href="<?= e(whatsapp_link($waText)) ?>" target="_blank" rel="noopener" aria-label="Conversar pelo WhatsApp">
  <svg viewBox="0 0 32 32" width="26" height="26" aria-hidden="true"><path fill="currentColor" d="M16 3C9 3 3.3 8.6 3.3 15.6c0 2.4.7 4.7 1.9 6.7L3 29l6.9-2.1c1.9 1 4 1.6 6.1 1.6 7 0 12.7-5.6 12.7-12.6S23 3 16 3Zm0 23.1c-1.9 0-3.8-.5-5.4-1.5l-.4-.2-4.1 1.2 1.2-4-.3-.4c-1.1-1.7-1.6-3.6-1.6-5.6C5.4 9.8 10.1 5.2 16 5.2s10.6 4.6 10.6 10.4S21.9 26.1 16 26.1Zm5.8-7.8c-.3-.2-1.9-.9-2.2-1s-.5-.2-.7.2-.8 1-1 1.2-.4.2-.7.1a8.6 8.6 0 0 1-4.3-3.7c-.3-.6.3-.5.9-1.7.1-.2 0-.4 0-.5l-1-2.4c-.3-.6-.5-.5-.7-.5h-.6a1.2 1.2 0 0 0-.9.4 3.6 3.6 0 0 0-1.1 2.7c0 1.6 1.2 3.1 1.3 3.3s2.3 3.5 5.6 4.9c2.1.9 2.9 1 4 .8.6-.1 1.9-.8 2.2-1.5s.3-1.4.2-1.5-.3-.2-.6-.3Z"/></svg>
  <span>WhatsApp</span>
</a>

<script src="<?= e(asset('js/site.js')) ?>" defer></script>
<script src="<?= e(asset('js/chat.js')) ?>" defer></script>
</body>
</html>
