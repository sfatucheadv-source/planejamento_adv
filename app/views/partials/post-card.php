<a class="card post-card" href="<?= e(url('blog/' . $p['slug'])) ?>">
  <span class="post-cover cat-<?= e($p['category_slug']) ?>" aria-hidden="true">
    <span class="post-num"><?= e($p['number']) ?></span>
    <span class="post-cat"><?= e($p['category']) ?></span>
  </span>
  <span class="post-body">
    <h3><?= e($p['title']) ?></h3>
    <p><?= e($p['description']) ?></p>
    <span class="card-link">Ler o estudo →</span>
  </span>
</a>
