<?php
/** Conversa com a IA: o que ainda falta, botão para encerrar e respostas sugeridas (atalhos). */
$id = (int) $c['id'];
$prog = agent_progress($id);
$sug = $c['intake_done_at'] ? [] : (array) case_data($c, 'agent_suggestions', []);
?>
<?php if (!$c['intake_done_at']): ?>
  <?php if ($prog['campos'] || $prog['docs']): ?>
    <p class="cv-progress small muted" id="pergunta">Para Sidney analisar, ainda falta<?= $prog['campos'] ? ': ' . e(implode(', ', array_map('lcfirst_pt', array_slice($prog['campos'], 0, 3)))) . (count($prog['campos']) > 3 ? ' e mais ' . plural(count($prog['campos']) - 3, 'informação', 'informações') : '') : '' ?><?= $prog['docs'] ? ($prog['campos'] ? '; e ' : ': ') . plural(count($prog['docs']), 'documento', 'documentos') . ' (' . e(implode(', ', array_map('lcfirst_pt', $prog['docs']))) . ')' : '' ?>.</p>
  <?php else: ?>
    <form method="post" action="<?= e(url('cliente/conversa/concluir')) ?>" class="cv-progress cv-btns" id="pergunta"><?= csrf_field() ?><span class="small">Tudo o que Sidney precisa já está aqui.</span><button class="btn btn-gold btn-small" type="submit">Está tudo certo, pode enviar para Sidney</button></form>
  <?php endif ?>
<?php endif ?>
<div class="cv-chips" aria-label="Respostas sugeridas">
  <?php foreach ($sug as $s): ?><button class="cv-chip" type="submit" form="fim" name="message" value="<?= e($s) ?>" formnovalidate><?= e($s) ?></button><?php endforeach ?>
  <button class="cv-chip cv-chip-ghost" type="submit" form="fim" name="message" value="Quero falar com Sidney." formnovalidate>Quero falar com Sidney</button>
</div>
