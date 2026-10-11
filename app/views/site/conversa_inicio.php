<?php
$v = fn(string $k) => e(is_string($old[$k] ?? null) ? $old[$k] : '');
$err = fn(string $k) => isset($errors[$k]) ? '<p class="field-error" role="alert" id="err-' . $k . '">' . e($errors[$k]) . '</p>' : '';
$open = is_open();
?>
<section class="triage-page">
  <div class="wrap triage-grid">
    <div class="triage-main">
      <p class="kicker">Atendimento inicial</p>
      <h1>Conte seu caso pelo assistente</h1>
      <p class="lead">Você pode enviar o que tiver e continuar depois.<?= llm_enabled() ? ' Com os documentos em mãos, o assistente lê os dados por você e pergunta só o que faltar.' : '' ?> O assistente organiza suas informações e documentos; Sidney analisa o caso e conversa com você sobre as opções.</p>

      <div class="cv-log cv-intro" aria-label="Conversa">
        <div class="cv-msg cv-bot"><div class="cv-bubble"><span class="cv-who">Assistente virtual</span><div class="cv-text"><?= e(BOT_GREETING) ?></div></div></div>
        <div class="cv-msg cv-bot"><div class="cv-bubble"><span class="cv-who">Assistente virtual</span><div class="cv-text">Para começar, como posso te chamar e qual é o seu contato? Assim seu atendimento fica guardado e você continua de onde parou.</div></div></div>
      </div>

      <?php if (!empty($errors['form'])): ?><p class="notice notice-urgent"><?= e($errors['form']) ?></p><?php endif ?>
      <form class="panel cv-start" method="post" action="<?= e(url('comecar')) ?>" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="origin" value="<?= $v('origin') ?>">
        <input type="hidden" name="aviso" value="<?= $v('aviso') ?>">
        <input type="hidden" name="form_key" value="<?= e($form_key) ?>">
        <div class="hp" aria-hidden="true"><label>Site <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
        <label class="field"><span>Como podemos te chamar?</span><input type="text" name="nome" value="<?= $v('nome') ?>" autocomplete="name" required <?= isset($errors['nome']) ? 'aria-invalid="true" aria-describedby="err-nome" autofocus' : '' ?>></label>
        <?= $err('nome') ?>
        <label class="field"><span>WhatsApp ou telefone, com DDD</span><input type="tel" name="telefone" value="<?= $v('telefone') ?>" autocomplete="tel" inputmode="tel" required <?= isset($errors['telefone']) ? 'aria-invalid="true" aria-describedby="err-telefone"' : '' ?>></label>
        <?= $err('telefone') ?>
        <label class="field"><span>E-mail <small>(opcional)</small></span><input type="email" name="email" value="<?= $v('email') ?>" autocomplete="email" <?= isset($errors['email']) ? 'aria-invalid="true" aria-describedby="err-email"' : '' ?>></label>
        <?= $err('email') ?>
        <label class="field"><span>Precisa de alguma adaptação para conversar? <small>(opcional)</small></span><input type="text" name="adaptacao" value="<?= $v('adaptacao') ?>" placeholder="Ex.: prefiro áudio, tenho baixa visão"></label>
        <label class="check"><input type="checkbox" name="consent" value="1" required <?= isset($errors['consent']) ? 'aria-invalid="true"' : '' ?>> <span>Autorizo o uso destes dados para o meu atendimento, conforme a <a href="<?= e(url('privacidade')) ?>" target="_blank">política de privacidade</a>.<?php if (llm_enabled()): ?> Os documentos e mensagens podem ser lidos por um serviço de inteligência artificial, só para organizar as informações; Sidney confere tudo.<?php endif ?></span></label>
        <?= $err('consent') ?>
        <button type="submit" class="btn btn-gold btn-block">Vamos começar</button>
        <p class="t-help">Começar não significa contratar. Primeiro organizamos as informações; depois Sidney analisa e conversa com você.</p>
      </form>
    </div>
    <aside class="triage-side">
      <div class="side-card">
        <p class="availability <?= $open ? 'is-open' : '' ?>"><span class="dot" aria-hidden="true"></span><?= $open ? 'Sidney em expediente agora' : 'Fora do horário de atendimento' ?></p>
        <p class="muted">O assistente funciona a qualquer hora, mas não é plantão do advogado. Atendimento humano: dias úteis, das <?= (int) config('hours.start', 9) ?>h às <?= (int) config('hours.end', 18) ?>h.</p>
        <p><strong>Se o veículo foi apreendido ou chegou documento da Justiça,</strong> diga isso logo no início: Sidney recebe o aviso na hora.</p>
      </div>
      <div class="side-card side-safe">
        <h2>Segurança</h2>
        <ul>
          <li>Nunca pedimos senha do banco, do Gov.br ou código de autenticação.</li>
          <li>Seus documentos ficam em área protegida.</li>
        </ul>
        <p class="muted">Já começou antes? <a href="<?= e(url('cliente/entrar')) ?>">Entre na área do cliente</a>.</p>
      </div>
    </aside>
  </div>
</section>
