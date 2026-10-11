<section class="page-hero">
  <div class="wrap narrow">
    <p class="kicker">Dúvidas frequentes</p>
    <h1>Respostas diretas, sem juridiquês</h1>
    <p class="lead">Se a sua dúvida não estiver aqui, pergunte. Sidney responde.</p>
  </div>
</section>
<section class="section">
  <div class="wrap narrow">
    <div class="faq faq-full">
      <?php foreach (faq() as [$q, $a]): ?>
        <details><summary><?= e($q) ?></summary><p><?= e($a) ?></p></details>
      <?php endforeach ?>
    </div>
  </div>
</section>
<script type="application/ld+json"><?= json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'FAQPage',
    'mainEntity' => array_map(fn($f) => ['@type' => 'Question', 'name' => $f[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f[1]]], faq()),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<?php partial('cta', ['origin' => 'duvidas']) ?>
