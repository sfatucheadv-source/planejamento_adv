<div class="page-head"><div><h1>Mensagens para reduzir o esforço do cliente</h1><p class="sub">Adapte os campos entre colchetes ao caso. O gestor confere fatos, datas e orientação jurídica antes de enviar. Para usar com os dados preenchidos, abra a aba Mensagens dentro do caso.</p></div></div>
<div class="grid-2col">
  <?php foreach (MESSAGE_TEMPLATES as $k => $t): ?>
    <section class="box">
      <h2><?= e($t['label']) ?></h2>
      <p class="quote-box small"><?= nl2br(e(preg_replace('/\{\{(\w+)\}\}/', '[$1]', $t['text']))) ?></p>
      <small class="muted">Etapa: <?= $t['stage'] === '*' ? 'qualquer' : e(stage_label($t['stage'])) ?></small>
    </section>
  <?php endforeach ?>
</div>
