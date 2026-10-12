<?php
$urgent = !empty($s['urgent']);
$related = posts_in($s['posts']);
$startQuery = ['prioridade' => $s['priority'], 'origem' => $slug] + ($s['notice'] ? ['aviso' => $s['notice']] : []);
?>
<section class="page-hero<?= $urgent ? ' page-hero-urgent' : '' ?>">
  <div class="wrap page-hero-grid">
    <div>
      <p class="kicker"><?= e($s['kicker']) ?></p>
      <h1><?= e($s['title']) ?></h1>
      <p class="lead"><?= e($s['lead']) ?></p>
      <div class="actions">
        <a class="btn btn-gold btn-lg" href="<?= e(url('comecar', $startQuery)) ?>">Iniciar o atendimento online</a>
        <?php if ($urgent): ?>
          <a class="btn btn-light btn-lg" href="<?= e(url('comecar', ['aviso' => 'apreendido'] + $startQuery)) ?>">É urgente: atendimento online agora</a>
        <?php endif ?>
      </div>
    </div>
    <div class="pains">
      <p class="pains-title">Talvez você esteja vivendo isto:</p>
      <ul><?php foreach ($s['pains'] as $p): ?><li><?= e($p) ?></li><?php endforeach ?></ul>
    </div>
  </div>
</section>

<?php if ($urgent): ?>
<div class="wrap">
  <p class="notice notice-urgent"><strong>Se há prazo correndo, não espere terminar de ler.</strong> <?= e($s['honest']) ?></p>
</div>
<?php endif ?>

<section class="section">
  <div class="wrap">
    <header class="section-head">
      <p class="kicker">O que a análise confere</p>
      <h2>O que olhamos no seu caso</h2>
    </header>
    <div class="cards checks">
      <?php foreach ($s['checks'] as $i => [$t, $d]): ?>
        <div class="card check-card"><span class="check-n"><?= $i + 1 ?></span><h3><?= e($t) ?></h3><p><?= e($d) ?></p></div>
      <?php endforeach ?>
    </div>
  </div>
</section>

<section class="section section-paper">
  <div class="wrap split split-top">
    <div>
      <p class="kicker">Documentos</p>
      <h2>O que ajuda a começar</h2>
      <p>Não precisa ter tudo. Comece com o que tiver: a gente diz exatamente o que falta e ajuda a conseguir o resto pelos canais oficiais do banco.</p>
      <p class="muted">Você nunca precisa nos passar senha do banco, do Gov.br ou código de autenticação.</p>
    </div>
    <ul class="doc-list">
      <?php foreach ($s['docs'] as $k): $d = DOC_CATALOG[$k]; ?>
        <li>
          <strong><?= e($d['label']) ?></strong>
          <span><?= e($d['why']) ?></span>
          <small><?= e($d['how']) ?></small>
        </li>
      <?php endforeach ?>
    </ul>
  </div>
</section>

<?php if (!$urgent): ?>
<section class="section">
  <div class="wrap">
    <p class="notice"><strong>Com honestidade:</strong> <?= e($s['honest']) ?></p>
  </div>
</section>
<?php endif ?>

<section class="section">
  <div class="wrap">
    <header class="section-head row-head">
      <div><p class="kicker">Para ler com calma</p><h2>Estudos relacionados</h2></div>
      <a class="btn btn-ghost" href="<?= e(url('blog')) ?>">Ver o blog</a>
    </header>
    <div class="cards posts">
      <?php foreach ($related as $p) { partial('post-card', ['p' => $p]); } ?>
    </div>
  </div>
</section>

<?php partial('cta', ['origin' => $slug]) ?>
