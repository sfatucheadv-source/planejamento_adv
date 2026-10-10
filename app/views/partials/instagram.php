<?php
$pics = [];
for ($i = 1; $i <= 6; $i++) {
    if ($u = photo_url('instagram-' . $i)) {
        $pics[] = $u;
    }
}
$profile = 'https://www.instagram.com/' . office('instagram') . '/';
?>
<section class="section insta">
  <div class="wrap">
    <header class="section-head row-head">
      <div>
        <p class="kicker">No Instagram</p>
        <h2>@<?= e(office('instagram')) ?></h2>
        <p>No Instagram publicamos explicações curtas sobre financiamento, apreensão e venda do veículo. Os estudos completos, com as fontes, ficam aqui no blog.</p>
      </div>
      <a class="btn btn-ghost" href="<?= e($profile) ?>" target="_blank" rel="noopener">Seguir no Instagram</a>
    </header>
    <?php if ($pics): ?>
      <div class="insta-grid">
        <?php foreach ($pics as $i => $u): ?>
          <a href="<?= e($profile) ?>" target="_blank" rel="noopener"><img src="<?= e($u) ?>" alt="Publicação do Instagram @<?= e(office('instagram')) ?>" loading="lazy" width="400" height="400"></a>
        <?php endforeach ?>
      </div>
    <?php else: ?>
      <div class="insta-grid insta-text">
        <?php foreach (array_slice(posts(), 0, 4) as $p): ?>
          <a class="insta-tile cat-<?= e($p['category_slug']) ?>" href="<?= e(url('blog/' . $p['slug'])) ?>">
            <span class="insta-tile-top"><?php partial('mark', ['size' => 22, 'light' => true]) ?> <?= e($p['category']) ?></span>
            <strong><?= e($p['slides'][0] ?? $p['title']) ?></strong>
            <span class="insta-tile-foot">Ler o estudo completo →</span>
          </a>
        <?php endforeach ?>
      </div>
    <?php endif ?>
  </div>
</section>
