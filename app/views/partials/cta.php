<?php $open = is_open(); ?>
<section class="section cta-final">
  <div class="wrap cta-box">
    <div>
      <h2>Conte o que está acontecendo</h2>
      <p>O atendimento começa pelo chat: escreva do seu jeito, mande áudio, fotos ou PDFs. Você não precisa ter o contrato em mãos nem saber termos jurídicos. Sidney confere tudo e responde.</p>
      <p class="cta-status"><?= e(human_reply_promise()) ?></p>
    </div>
    <div class="cta-actions">
      <a class="btn btn-gold btn-lg" href="<?= e(url('comecar', ['origem' => $origin ?? request_path()])) ?>">Iniciar o atendimento online</a>
    </div>
  </div>
</section>
