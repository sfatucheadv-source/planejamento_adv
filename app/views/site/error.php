<section class="page-hero">
  <div class="wrap narrow">
    <h1><?= e($title) ?></h1>
    <p class="lead"><?= e($message) ?></p>
    <div class="actions">
      <a class="btn btn-gold" href="<?= e(url('/')) ?>">Voltar ao início</a>
      <a class="btn btn-ghost" href="<?= e(whatsapp_link('Olá! Tive um problema no site e preciso de ajuda.')) ?>" target="_blank" rel="noopener">Falar no WhatsApp</a>
    </div>
  </div>
</section>
