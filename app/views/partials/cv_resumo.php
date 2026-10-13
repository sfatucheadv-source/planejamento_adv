<?php
/** Resumo do caso para o cliente conferir, corrigir e enviar a Sidney (abre sozinho ao chegar a 100%). */
$id = (int) $c['id'];
$secoes = case_summary($id);
$docs = all("SELECT * FROM documents WHERE case_id = ? AND status <> 'dispensado' ORDER BY id", [$id]);
?>
<dialog class="cv-resumo" id="resumo" data-resumo="<?= $id ?>" aria-labelledby="resumo-t">
  <form method="post" action="<?= e(url('cliente/conversa/resumo')) ?>" class="cv-resumo-form">
    <?= csrf_field() ?>
    <header class="cv-resumo-head">
      <h2 id="resumo-t">Confira o resumo do seu caso</h2>
      <button class="cv-resumo-x" type="button" data-resumo-fechar aria-label="Fechar">×</button>
    </header>
    <div class="cv-resumo-body">
      <section class="cv-resumo-entendi">
        <h3><?= e(ASSISTANT_NAME) ?> entendeu assim</h3>
        <p><?= nl2br(e(case_story($id))) ?></p>
      </section>
      <p class="cv-resumo-dica">Confira os dados abaixo. Se algo estiver errado ou faltando, toque em <strong>Corrigir dados</strong>.</p>
      <?php foreach ($secoes as $titulo => $linhas): ?>
        <section class="cv-resumo-sec">
          <h3><?= e($titulo) ?></h3>
          <dl>
            <?php foreach ($linhas as [$k, $label, $valor, $row]): $def = field_def($k); $cur = field_input_value($k, $row); ?>
              <div class="cv-resumo-item">
                <dt><?= e($label) ?></dt>
                <dd class="cv-resumo-v"><?= $valor !== '' ? e($valor) : '<span class="muted">não informado</span>' ?></dd>
                <dd class="cv-resumo-in">
                  <?php if ($def['type'] === 'choice'): ?>
                    <select name="f[<?= e($k) ?>]" aria-label="<?= e($label) ?>"><option value="">—</option><?php foreach ($def['options'] as $ok => $ol): ?><option value="<?= e((string) $ok) ?>" <?= selected((string) $ok, $cur) ?>><?= e($ol) ?></option><?php endforeach ?></select>
                  <?php elseif ($def['type'] === 'textarea'): ?>
                    <textarea name="f[<?= e($k) ?>]" rows="2" aria-label="<?= e($label) ?>"><?= e($cur) ?></textarea>
                  <?php else: ?>
                    <input name="f[<?= e($k) ?>]" value="<?= e($cur) ?>" aria-label="<?= e($label) ?>" <?= $def['type'] === 'date' ? 'type="date"' : (in_array($def['type'], ['money', 'rate'], true) ? 'inputmode="decimal"' : (in_array($def['type'], ['int', 'cpf', 'cep', 'phone'], true) ? 'inputmode="numeric"' : '')) ?>>
                  <?php endif ?>
                </dd>
              </div>
            <?php endforeach ?>
          </dl>
        </section>
      <?php endforeach ?>
      <?php if ($docs): ?>
        <section class="cv-resumo-sec">
          <h3>Documentos</h3>
          <ul class="cv-resumo-docs">
            <?php foreach ($docs as $d): [$label, $sub] = client_doc_state($d); ?><li><?= e($d['label']) ?>: <strong><?= e($label) ?></strong><?= $sub ? ' · ' . e($sub) : '' ?></li><?php endforeach ?>
          </ul>
        </section>
      <?php endif ?>
    </div>
    <footer class="cv-resumo-foot">
      <button class="btn btn-gold" type="submit" name="op" value="enviar">Está tudo certo, enviar para Sidney</button>
      <button class="btn btn-ghost cv-resumo-edit" type="button" data-resumo-editar>Corrigir dados</button>
      <button class="btn btn-gold cv-resumo-save" type="submit" name="op" value="salvar">Salvar correções</button>
      <button class="link-btn" type="button" data-resumo-conversar>Quero explicar mais alguma coisa ao <?= e(ASSISTANT_NAME) ?></button>
    </footer>
  </form>
</dialog>
