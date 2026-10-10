<div class="page-head"><div><h1>Minha conta</h1></div></div>
<section class="box narrow-box">
  <form method="post" action="<?= e(url('painel/conta')) ?>" class="stack">
    <?= csrf_field() ?>
    <label class="fld"><span>Senha atual</span><input type="password" name="current" autocomplete="current-password" required></label>
    <label class="fld"><span>Nova senha (mínimo 10 caracteres)</span><input type="password" name="password" autocomplete="new-password" minlength="10" required></label>
    <button class="b b-primary" type="submit">Alterar senha</button>
  </form>
</section>
