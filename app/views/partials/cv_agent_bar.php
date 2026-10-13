<?php
/** Conversa com a IA: o que ainda falta, botão para encerrar e respostas sugeridas (atalhos). */
$id = (int) $c['id'];
$prog = agent_progress($id);
$sug = $c['intake_done_at'] ? [] : (array) case_data($c, 'agent_suggestions', []);
?>
<?php if (!$c['intake_done_at']): ?>
  <?php if ($prog['campos'] || $prog['docs']):
    $msgsCliente = (int) scalar("SELECT COUNT(*) FROM chat_messages WHERE case_id = ? AND author = 'client'", [$id]);
    if ($msgsCliente >= AGENT_GUIDE_AFTER): $guia = agent_guide($id); $pct = agent_progress_pct($id); ?>
    <details class="cv-guia" id="pergunta" data-guia>
      <summary>
        <span class="cv-guia-t">O que Sidney precisa</span>
        <span class="cv-guia-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $pct ?>" aria-label="Atendimento <?= $pct ?>% concluído"><i style="width: <?= $pct ?>%"></i></span>
        <span class="cv-guia-n"><strong><?= $pct ?>%</strong> concluído</span>
      </summary>
      <ul class="cv-guia-lista">
        <?php foreach ($guia as $g): ?>
          <li class="<?= $g['done'] ? 'is-ok' : '' ?> <?= $g['doc'] ? 'is-doc' : '' ?>"><span aria-hidden="true"><?= $g['done'] ? '✓' : ($g['doc'] ? '📎' : '•') ?></span><?= e($g['label']) ?><span class="sr"><?= $g['done'] ? ' (pronto)' : ' (falta)' ?></span></li>
        <?php endforeach ?>
      </ul>
      <p class="cv-guia-dica">Não precisa seguir a ordem: conte do seu jeito e mande os documentos aqui pelo clipe.</p>
    </details>
    <?php endif ?>
    <?php if (meeting_ready($id)): ?>
      <p class="cv-libera"><span aria-hidden="true">📅</span> Já dá para agendar com Sidney. <a href="<?= e(url('cliente', ['area' => 'reuniao'])) ?>#h-reu">Escolher horário</a></p>
    <?php endif ?>
  <?php else: ?>
    <div class="cv-pronto" id="pergunta">
      <span class="cv-pronto-t"><span aria-hidden="true">✓</span> <strong>100%</strong> · Tudo o que Sidney precisa já está aqui.</span>
      <button class="btn btn-gold btn-small" type="button" data-resumo-abrir>Revisar e enviar</button>
    </div>
    <?php partial('cv_resumo', ['c' => $c]) ?>
  <?php endif ?>
<?php endif ?>
<div class="cv-chips" aria-label="Respostas sugeridas"><?php foreach ($sug as $s): ?><button class="cv-chip" type="submit" form="fim" name="message" value="<?= e($s) ?>" formnovalidate><?= e($s) ?></button><?php endforeach ?></div>
