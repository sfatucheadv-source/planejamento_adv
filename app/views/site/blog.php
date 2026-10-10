<?php
$cats = post_categories();
$list = $active_cat ? array_values(array_filter(posts(), fn($p) => $p['category_slug'] === $active_cat)) : posts();
?>
<section class="page-hero">
  <div class="wrap narrow">
    <p class="kicker">Blog</p>
    <h1><?= $active_cat ? e($cats[$active_cat]) : 'Busca e apreensão na prática: fatos, documentos e o que dizem os tribunais' ?></h1>
    <p class="lead">Estudos curtos para você reconhecer a sua situação, saber o que guardar e entender por que cada caso precisa de análise. Cada texto traz as fontes oficiais consultadas.</p>
  </div>
</section>

<section class="section section-tight">
  <div class="wrap">
    <div class="blog-tools">
      <nav class="chips" aria-label="Categorias">
        <a class="chip<?= $active_cat ? '' : ' is-active' ?>" href="<?= e(url('blog')) ?>">Todos</a>
        <?php foreach ($cats as $slug => $label): ?>
          <a class="chip<?= $active_cat === $slug ? ' is-active' : '' ?>" href="<?= e(url('blog/categoria/' . $slug)) ?>"><?= e($label) ?></a>
        <?php endforeach ?>
      </nav>
      <label class="search"><span class="sr">Buscar no blog</span><input type="search" placeholder="Buscar: juros, notificação, FIPE…" data-filter=".post-card"></label>
    </div>
    <div class="cards posts posts-grid">
      <?php foreach ($list as $p) { partial('post-card', ['p' => $p]); } ?>
    </div>
    <p class="empty" data-filter-empty hidden>Nenhum estudo encontrado com esse termo. Tente outra palavra ou <a href="<?= e(url('comecar')) ?>">conte o seu caso</a>.</p>
  </div>
</section>

<?php partial('cta', ['origin' => 'blog']) ?>
