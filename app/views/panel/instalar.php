<div class="login">
  <div class="login-card login-wide">
    <div class="login-brand"><?php partial('mark', ['size' => 36]) ?><span>Sidney Fatuche Advocacia<small>Instalação do painel da equipe</small></span></div>
    <p class="ok-line">O site está funcionando nesta hospedagem. Falta só criar o primeiro acesso ao painel.</p>
    <?php if (!$https): ?>
      <p class="warn-line">O endereço não está em HTTPS. Ative o SSL do domínio no hPanel (Segurança → SSL) e abra esta página com <strong>https://</strong> antes de criar a senha.</p>
    <?php endif ?>
    <ol class="small-ol">
      <li>No hPanel, abra <strong>Arquivos → Gerenciador de Arquivos</strong>.</li>
      <li>Entre na pasta onde o site foi instalado (normalmente <code>public_html</code>) e depois em <code>storage</code>.</li>
      <li>Abra o arquivo <code>codigo-instalacao.txt</code> e copie o código de 10 letras e números.</li>
    </ol>
    <?php if (!$code_ready): ?>
      <p class="warn-line">Não foi possível criar o arquivo de código. Confira se a pasta <code>storage</code> permite gravação.</p>
    <?php endif ?>
    <form method="post" action="<?= e(url('painel/instalar')) ?>">
      <?= csrf_field() ?>
      <label class="fld"><span>Código de instalação</span><input name="code" autocomplete="off" required autofocus></label>
      <label class="fld"><span>Seu nome</span><input name="name" value="Sidney Fatuche" required></label>
      <label class="fld"><span>E-mail de acesso</span><input type="email" name="email" value="<?= e(office('email')) ?>" autocomplete="username" required></label>
      <label class="fld"><span>Senha (mínimo 10 caracteres)</span><input type="password" name="password" minlength="10" autocomplete="new-password" required></label>
      <label class="fld"><span>Repita a senha</span><input type="password" name="password2" minlength="10" autocomplete="new-password" required></label>
      <p class="hint">Este primeiro acesso recebe todas as funções (gestor, advogado, cálculos, negociação, financeiro e supervisão). Depois você cadastra a equipe em <strong>Equipe</strong>. O arquivo de código é apagado ao concluir e esta página deixa de funcionar.</p>
      <button class="b b-primary b-block" type="submit">Concluir instalação</button>
    </form>
  </div>
</div>
