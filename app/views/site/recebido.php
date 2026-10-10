<?php if (!$c): ?>
<section class="page-hero">
  <div class="wrap narrow">
    <h1>Mensagem recebida</h1>
    <p class="lead">Obrigado. Se você não anotou o protocolo, não se preocupe: a equipe vai falar com você pelo contato informado.</p>
    <a class="btn btn-gold" href="<?= e(url('/')) ?>">Voltar ao início</a>
  </div>
</section>
<?php return; endif ?>
<?php
$docs = client_documents((int) $c['id']);
$wa = 'Olá! Acabei de enviar meu caso pelo site. Protocolo ' . $c['protocol'] . '.';
?>
<section class="page-hero received">
  <div class="wrap narrow">
    <p class="kicker">Recebido</p>
    <h1>Obrigado, <?= e(first_name($c['client_name'])) ?>. Já estamos com o seu caso.</h1>
    <p class="protocol">Seu protocolo: <strong><?= e($c['protocol']) ?></strong></p>
    <p class="lead"><?= e(human_reply_promise(dt($c['created_at']))) ?> O contato será por <?= e(CONTACT_PREFS[$c['contact_pref']] ?? 'WhatsApp') ?>, no número <?= e($c['client_phone']) ?>.</p>
    <?php if ($c['urgent']): ?>
      <div class="notice notice-urgent">
        <p><strong>Seu caso foi marcado como prioridade e encaminhado ao advogado.</strong> Para ganhar tempo, mande agora pelo WhatsApp uma foto de todas as páginas do documento que você recebeu (mandado, citação, carta ou notificação), mesmo que esteja incompleto.</p>
        <a class="btn btn-wa" href="<?= e(whatsapp_link($wa . ' Meu caso é urgente e vou enviar o documento.')) ?>" target="_blank" rel="noopener">Enviar documento pelo WhatsApp</a>
      </div>
    <?php endif ?>
    <blockquote class="summary">“<?= e(preg_replace('/\s*Correto\?$/u', '', (string) $c['summary_sentence'])) ?>”</blockquote>
  </div>
</section>

<section class="section">
  <div class="wrap split split-top">
    <div>
      <h2>O que acontece agora</h2>
      <ol class="next-steps">
        <li><strong>Uma pessoa da equipe fala com você</strong> para se apresentar, confirmar esse resumo e verificar se há algum prazo urgente.</li>
        <li><strong>Você recebe uma lista única de documentos</strong>, dizendo o que já temos, o que falta e o que a gente ajuda a conseguir.</li>
        <li><strong>O advogado analisa e explica as opções</strong>, com custos e riscos, antes de qualquer contratação.</li>
      </ol>
      <p>Para não ser surpreendido por golpes: a equipe só usa o WhatsApp <?= e(office('phone_display')) ?> e o e-mail <?= e(office('email')) ?>. Nunca pedimos senha ou código.</p>
      <a class="btn btn-wa" href="<?= e(whatsapp_link($wa)) ?>" target="_blank" rel="noopener">Salvar nosso WhatsApp e avisar que enviou</a>
    </div>
    <div>
      <div class="side-card">
        <h2>Quer acompanhar pelo site?</h2>
        <p>Crie uma senha para ver a etapa do seu caso, enviar documentos e acompanhar os retornos. É opcional: tudo também pode ser feito pelo WhatsApp.</p>
        <form method="post" action="<?= e(url('comecar/acesso')) ?>" class="stack">
          <?= csrf_field() ?>
          <label class="field"><span>Protocolo</span><input type="text" value="<?= e($c['protocol']) ?>" readonly></label>
          <label class="field"><span>Crie uma senha <small>(mínimo 8 caracteres)</small></span><input type="password" name="password" minlength="8" autocomplete="new-password" required></label>
          <button class="btn btn-gold" type="submit">Criar meu acesso</button>
        </form>
      </div>
    </div>
  </div>
</section>

<section class="section section-paper">
  <div class="wrap">
    <header class="section-head">
      <h2>Se quiser adiantar, comece a separar</h2>
      <p>Não precisa enviar agora nem ter tudo. É só para você já saber o que vai ajudar.</p>
    </header>
    <ul class="doc-list doc-list-grid">
      <?php foreach ($docs as $d): $cat = DOC_CATALOG[$d['kind']] ?? null; ?>
        <li><strong><?= e($d['label']) ?></strong><?php if ($cat): ?><span><?= e($cat['why']) ?></span><small><?= e($cat['how']) ?></small><?php endif ?></li>
      <?php endforeach ?>
    </ul>
  </div>
</section>
