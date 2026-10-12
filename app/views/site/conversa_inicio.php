<?php
$v = fn(string $k) => e(is_string($old[$k] ?? null) ? $old[$k] : '');
$err = fn(string $k) => isset($errors[$k]) ? '<p class="field-error" role="alert" id="err-' . $k . '">' . e($errors[$k]) . '</p>' : '';
$open = is_open();
?>
<section class="triage-page">
  <div class="wrap triage-grid">
    <div class="triage-main">
      <p class="kicker">Atendimento inicial</p>
      <h1>Atendimento online</h1>
      <p class="lead">Sem formulário: comece contando o que está acontecendo. O assistente ouve, registra e organiza tudo para Sidney, que analisa o caso e conversa com você sobre as opções.</p>

      <div class="cv-log cv-intro" aria-label="Conversa">
        <div class="cv-msg cv-bot"><div class="cv-bubble"><span class="cv-who">Assistente virtual</span><div class="cv-text"><?= e(bot_greeting()) ?></div></div></div>
        <div class="cv-msg cv-bot"><div class="cv-bubble"><span class="cv-who">Assistente virtual</span><div class="cv-text">O que está acontecendo? Escreva do seu jeito ou toque em uma opção. Eu peço seu nome e contato durante a conversa.</div></div></div>
      </div>

      <?php if (!empty($errors['form'])): ?><p class="notice notice-urgent"><?= e($errors['form']) ?></p><?php endif ?>
      <form class="panel cv-start cv-start-chat" method="post" action="<?= e(url('comecar')) ?>" data-cv-start>
        <?= csrf_field() ?>
        <input type="hidden" name="origin" value="<?= $v('origin') ?>">
        <input type="hidden" name="aviso" value="<?= $v('aviso') ?>">
        <input type="hidden" name="form_key" value="<?= e($form_key) ?>">
        <div class="hp" aria-hidden="true"><label>Site <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
        <div class="cv-atalhos" role="group" aria-label="Respostas rápidas">
          <?php foreach (['O banco mandou notificação', 'Meu carro foi apreendido', 'Estou com parcelas atrasadas', 'A parcela está pesada demais', 'Quero revisar os juros'] as $at): ?>
            <button type="submit" name="atalho" value="<?= e($at) ?>" class="cv-atalho" formnovalidate><?= e($at) ?></button>
          <?php endforeach ?>
        </div>
        <label class="field cv-start-msg"><span class="sr">Sua mensagem</span>
          <textarea name="mensagem" rows="3" maxlength="2000" placeholder="Conte o que está acontecendo com o seu financiamento…" autofocus <?= isset($errors['mensagem']) ? 'aria-invalid="true" aria-describedby="err-mensagem"' : '' ?>><?= $v('mensagem') ?></textarea>
        </label>
        <?= $err('mensagem') ?>
        <button type="submit" class="btn btn-gold btn-block">Enviar e começar</button>
        <p class="t-help">Ao enviar, você autoriza o uso das informações da conversa para o seu atendimento, conforme a <a href="<?= e(url('privacidade')) ?>" target="_blank">política de privacidade</a>.<?php if (llm_enabled()): ?> As mensagens e documentos podem ser lidos por um serviço de inteligência artificial, só para organizar as informações; Sidney confere tudo.<?php endif ?> Começar não significa contratar.</p>
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
