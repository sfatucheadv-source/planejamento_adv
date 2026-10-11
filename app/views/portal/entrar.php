<section class="portal">
  <div class="wrap">
    <div class="login-box">
      <p class="kicker">Área do cliente</p>
      <h1>Acompanhe o seu caso</h1>
      <p class="lead">Continue a conversa com o assistente, envie documentos, veja a reunião e a proposta e acompanhe o próximo passo.</p>
      <div class="panel">
        <h2>Entrar</h2>
        <form method="post" action="<?= e(url('cliente/entrar')) ?>" class="stack">
          <?= csrf_field() ?>
          <label class="field"><span>Protocolo</span><input type="text" name="protocol" placeholder="SF-XXXXXX" autocomplete="username" required></label>
          <label class="field"><span>Senha</span><input type="password" name="password" autocomplete="current-password" required></label>
          <button class="btn btn-gold" type="submit">Entrar</button>
        </form>
      </div>
      <details class="panel">
        <summary><strong>Recebi um código de acesso</strong></summary>
        <p class="muted">Use o protocolo e o código que Sidney enviou para criar a sua senha.</p>
        <form method="post" action="<?= e(url('cliente/ativar')) ?>" class="stack">
          <?= csrf_field() ?>
          <label class="field"><span>Protocolo</span><input type="text" name="protocol" placeholder="SF-XXXXXX" required></label>
          <label class="field"><span>Código</span><input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" required></label>
          <label class="field"><span>Nova senha <small>(mínimo 8 caracteres)</small></span><input type="password" name="password" minlength="8" autocomplete="new-password" required></label>
          <button class="btn btn-ghost" type="submit">Criar senha e entrar</button>
        </form>
      </details>
      <p class="muted">A área do cliente é opcional. Tudo também pode ser feito pelo <a href="<?= e(whatsapp_link('Olá! Preciso de ajuda para acessar a área do cliente.')) ?>" target="_blank" rel="noopener">WhatsApp <?= e(office('phone_display')) ?></a>. Nunca pedimos senha do banco ou do Gov.br.</p>
    <p class="muted">Ainda não começou? <a href="<?= e(url('comecar')) ?>">Conte seu caso pelo assistente</a>.</p>
    </div>
  </div>
</section>
