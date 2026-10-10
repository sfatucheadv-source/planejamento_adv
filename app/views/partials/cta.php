<?php $open = is_open(); ?>
<section class="section cta-final">
  <div class="wrap cta-box">
    <div>
      <h2>Conte o que está acontecendo</h2>
      <p>Leva uns três minutos. Você não precisa ter o contrato em mãos nem saber termos jurídicos. Uma pessoa da equipe lê tudo e responde.</p>
      <p class="cta-status"><?= e(human_reply_promise()) ?></p>
    </div>
    <div class="cta-actions">
      <a class="btn btn-gold btn-lg" href="<?= e(url('comecar', ['origem' => $origin ?? request_path()])) ?>">Contar meu caso</a>
      <a class="btn btn-light btn-lg" href="<?= e(whatsapp_link('Olá! Vim pelo site e gostaria de falar sobre meu financiamento de veículo.')) ?>" target="_blank" rel="noopener">Chamar no WhatsApp</a>
    </div>
  </div>
</section>
