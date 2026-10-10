<div class="login">
  <div class="login-card">
    <div class="login-brand"><?php partial('mark', ['size' => 36]) ?><span>Sidney Fatuche Advocacia<small>Painel da equipe</small></span></div>
    <?php if (!$has_users): ?>
      <p class="hint">Nenhum usuário cadastrado ainda. No servidor, rode <code>php scripts/criar-usuario.php</code> para criar o primeiro acesso.</p>
    <?php endif ?>
    <form method="post" action="<?= e(url('painel/entrar')) ?>">
      <?= csrf_field() ?>
      <label class="fld"><span>E-mail</span><input type="email" name="email" autocomplete="username" required autofocus></label>
      <label class="fld"><span>Senha</span><input type="password" name="password" autocomplete="current-password" required></label>
      <button class="b b-primary b-block" type="submit">Entrar</button>
    </form>
  </div>
</div>
