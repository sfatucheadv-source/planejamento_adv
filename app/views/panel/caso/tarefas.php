<div class="grid-main-side">
  <div>
    <section class="box">
      <h2>Tarefas do caso</h2>
      <p class="hint">Cada tarefa tem responsável, prazo e de quem depende. Pendência deve indicar de quem se depende e quando a equipe vai cobrar.</p>
      <?php if (!$tasks): ?><p class="empty">Nenhuma tarefa.</p><?php endif ?>
      <ul class="task-list">
        <?php foreach ($tasks as $t): $late = $t['status'] === 'aberta' && $t['due_at'] && dt($t['due_at']) < now(); ?>
          <li class="<?= $t['status'] !== 'aberta' ? 'done' : ($late ? 'late' : '') ?>">
            <form method="post" action="<?= e(url('painel/tarefa/' . $t['id'])) ?>"><?= csrf_field() ?><input type="hidden" name="op" value="<?= $t['status'] === 'aberta' ? 'concluir' : 'reabrir' ?>"><button class="tick <?= $t['status'] !== 'aberta' ? 'on' : '' ?>" title="<?= $t['status'] === 'aberta' ? 'Concluir' : 'Reabrir' ?>" aria-label="<?= $t['status'] === 'aberta' ? 'Concluir' : 'Reabrir' ?>"></button></form>
            <div>
              <span><?= e($t['title']) ?></span>
              <small><?= e(user_name($t['owner_id'] ? (int) $t['owner_id'] : null)) ?> · <?= $t['due_at'] ? e(fmt_datetime($t['due_at'])) : 'sem data' ?> · depende de <?= e(TASK_DEPENDS[$t['depends_on']] ?? $t['depends_on']) ?><?= $t['done_at'] ? ' · concluída ' . e(fmt_datetime($t['done_at'])) . ($t['evidence'] ? ' (' . e($t['evidence']) . ')' : '') : '' ?></small>
              <?php if ($t['status'] === 'aberta'): ?>
                <details class="more"><summary>Concluir com evidência ou mudar a data</summary>
                  <form method="post" action="<?= e(url('painel/tarefa/' . $t['id'])) ?>" class="inline-form"><?= csrf_field() ?><input type="hidden" name="op" value="concluir"><input name="evidence" placeholder="Evidência da conclusão" aria-label="Evidência"><button class="b b-xs">Concluir</button></form>
                  <form method="post" action="<?= e(url('painel/tarefa/' . $t['id'])) ?>" class="inline-form"><?= csrf_field() ?><input type="hidden" name="op" value="adiar"><input type="datetime-local" name="due_at" aria-label="Nova data"><input name="reason" placeholder="Motivo (avisar o cliente antes do vencimento)" aria-label="Motivo"><button class="b b-xs">Nova data</button></form>
                </details>
              <?php endif ?>
            </div>
          </li>
        <?php endforeach ?>
      </ul>
      <?= case_form($c, 'tarefa', [], 'inline-form') ?>
        <label class="fld grow"><span>Nova tarefa</span><input name="title" required></label>
        <label class="fld"><span>Responsável</span><?= user_select('owner_id', (int) current_user()['id'], $users) ?></label>
        <label class="fld"><span>Prazo</span><input type="datetime-local" name="due_at"></label>
        <label class="fld"><span>Depende de</span><select name="depends_on"><?php foreach (TASK_DEPENDS as $k => $l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach ?></select></label>
        <button class="b" type="submit">Adicionar</button>
      </form>
    </section>
  </div>
  <aside>
    <section class="box">
      <h2>Exceções</h2>
      <p class="hint">Toda exceção precisa ter próximo passo. Cliente sem resposta não autoriza arquivamento automático com prazo jurídico ativo.</p>
      <?php foreach ($exceptions as $x): $def = EXCEPTIONS[$x['kind']] ?? ['label' => $x['kind'], 'who' => '', 'action' => '']; ?>
        <div class="exc <?= $x['resolved_at'] ? 'resolved' : '' ?>">
          <strong><?= e($def['label']) ?></strong>
          <small>Assume: <?= e($def['who']) ?> · aberta <?= e(fmt_datetime($x['opened_at'])) ?></small>
          <p><?= e($def['action']) ?><?= $x['note'] ? '<br><em>' . e($x['note']) . '</em>' : '' ?></p>
          <?php if (!$x['resolved_at']): ?>
            <?= case_form($c, 'excecao_resolver', ['exception_id' => $x['id']], 'inline-form') ?><input name="resolution" placeholder="Como foi resolvida" aria-label="Resolução"><button class="b b-xs">Resolver</button></form>
          <?php else: ?><small>Resolvida <?= e(fmt_datetime($x['resolved_at'])) ?>: <?= e($x['resolution']) ?></small><?php endif ?>
        </div>
      <?php endforeach ?>
      <?= case_form($c, 'excecao', [], 'stack') ?>
        <label class="fld"><span>Registrar exceção</span><select name="kind"><?php foreach (EXCEPTIONS as $k => $x): ?><option value="<?= $k ?>"><?= e($x['label']) ?> → <?= e($x['who']) ?></option><?php endforeach ?></select></label>
        <label class="fld"><span>Detalhe</span><input name="note"></label>
        <button class="b" type="submit">Registrar</button>
      </form>
    </section>
  </aside>
</div>
