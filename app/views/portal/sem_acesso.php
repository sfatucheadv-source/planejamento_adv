<section class="portal">
  <div class="wrap">
    <div class="login-box">
      <p class="kicker">Área do cliente</p>
      <h1>Nenhum atendimento disponível agora</h1>
      <p class="lead">O atendimento anterior foi concluído e o prazo de acesso a ele terminou. Seus documentos continuam guardados com o escritório.</p>
      <div class="panel">
        <?php if ($requested): ?>
          <p><strong>Pedido enviado em <?= e(fmt_datetime($requested)) ?>.</strong> Sidney vai verificar e abrir um novo atendimento para você.</p>
        <?php else: ?>
          <h2>Pedir um novo atendimento</h2>
          <form method="post" action="<?= e(url('cliente/novo-atendimento')) ?>" class="stack">
            <?= csrf_field() ?>
            <label class="field"><span>Conte em poucas palavras o que você precisa agora <small>(opcional)</small></span><textarea name="message" rows="3"></textarea></label>
            <button class="btn btn-gold" type="submit">Pedir a Sidney</button>
          </form>
        <?php endif ?>
      </div>
      <p class="muted">Precisa de um documento seu? Peça pelo <a href="<?= e(whatsapp_link('Olá, Sidney! Preciso de um documento do meu atendimento.')) ?>" target="_blank" rel="noopener">WhatsApp <?= e(office('phone_display')) ?></a>. <a href="<?= e(url('cliente/sair')) ?>">Sair</a></p>
    </div>
  </div>
</section>
