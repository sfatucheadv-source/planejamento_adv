<?php
$related = posts_in($p['related']);
$url = absolute_url('blog/' . $p['slug']);
$urgentCat = in_array($p['category_slug'], ['apreendido', 'notificacao', 'defesa'], true);
$share = trim($p['share_text']) . "\n\n" . $url;
?>
<article class="post">
  <header class="post-hero cat-<?= e($p['category_slug']) ?>">
    <div class="wrap narrow">
      <nav class="crumbs" aria-label="Você está em"><a href="<?= e(url('blog')) ?>">Blog</a> <span aria-hidden="true">/</span> <a href="<?= e(url('blog/categoria/' . $p['category_slug'])) ?>"><?= e($p['category']) ?></a></nav>
      <h1><?= e($p['title']) ?></h1>
      <p class="lead"><?= e($p['description']) ?></p>
      <div class="byline">
        <?php if ($ph = photo_url('sidney-retrato')): ?><img src="<?= e($ph) ?>" alt="" width="44" height="44"><?php else: ?><span class="byline-mark"><?php partial('mark', ['size' => 26, 'light' => true]) ?></span><?php endif ?>
        <span><strong><?= e(office('lawyer')) ?></strong> · <?= e(office('lawyer_oab')) ?><br><small>Fontes verificadas em <?= e(fmt_date($p['reviewed'], true)) ?></small></span>
      </div>
    </div>
  </header>

  <div class="wrap narrow post-body">
    <?php if ($urgentCat): ?>
      <p class="notice notice-urgent">Se há prazo em curso, informe isso no atendimento. A leitura não substitui a conferência imediata dos documentos. <a href="<?= e(whatsapp_link('Olá, li o artigo "' . $p['title'] . '" e tenho um prazo em curso. Preciso de orientação.')) ?>" target="_blank" rel="noopener">Falar agora no WhatsApp</a></p>
    <?php endif ?>

    <div class="prose">
      <?= $p['html'] /* conteúdo editorial convertido no build, com escape aplicado */ ?>
    </div>

    <?php foreach ($p['callouts'] as $c): ?>
      <aside class="callout"><strong><?= e($c['label']) ?></strong><p><?= $c['html'] ?></p></aside>
    <?php endforeach ?>

    <section class="sources">
      <h2>Fontes oficiais do entendimento descrito</h2>
      <ul>
        <?php foreach ($p['sources'] as $s): ?><li><a href="<?= e($s['url']) ?>" target="_blank" rel="noopener"><?= e($s['label']) ?></a></li><?php endforeach ?>
      </ul>
      <p class="muted">Conteúdo informativo. A aplicação dos fundamentos depende dos documentos e das circunstâncias do caso. Decisões citadas pertencem aos processos públicos indicados e não descrevem resultados do escritório.</p>
    </section>

    <div class="share">
      <span>Pode ajudar alguém?</span>
      <a class="btn btn-small btn-wa" href="<?= e('https://wa.me/?text=' . rawurlencode($share)) ?>" target="_blank" rel="noopener">Enviar pelo WhatsApp</a>
      <button class="btn btn-small btn-ghost" type="button" data-copy="<?= e($url) ?>">Copiar link</button>
    </div>

    <aside class="author-box">
      <?php if ($ph = photo_url('sidney-retrato')): ?><img src="<?= e($ph) ?>" alt="Sidney Fatuche" width="88" height="88"><?php else: ?><span class="author-mark"><?php partial('mark', ['size' => 44, 'light' => true]) ?></span><?php endif ?>
      <div>
        <p><strong>Sidney Fatuche</strong> é o advogado responsável pela Sidney Fatuche Advocacia (<?= e(office('lawyer_oab')) ?>). Nesta série, apresenta questões jurídicas sobre financiamento de veículos, busca e apreensão e cobranças após a venda, com referência a decisões públicas e aos documentos relevantes para cada situação.</p>
        <p><a href="<?= e(url('comecar', ['origem' => 'blog/' . $p['slug']])) ?>">Para uma análise individual, conte a etapa do seu caso →</a></p>
      </div>
    </aside>
  </div>
</article>

<?php if ($related): ?>
<section class="section section-paper">
  <div class="wrap">
    <header class="section-head"><p class="kicker">Continue lendo</p><h2>Estudos relacionados</h2></header>
    <div class="cards posts"><?php foreach ($related as $r) { partial('post-card', ['p' => $r]); } ?></div>
  </div>
</section>
<?php endif ?>

<script type="application/ld+json"><?= json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'BlogPosting',
    'headline' => $p['title'],
    'description' => $p['description'],
    'dateModified' => $p['reviewed'],
    'author' => ['@type' => 'Person', 'name' => office('lawyer')],
    'publisher' => ['@type' => 'Organization', 'name' => office('brand')],
    'mainEntityOfPage' => $url,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>

<?php partial('cta', ['origin' => 'blog/' . $p['slug']]) ?>
