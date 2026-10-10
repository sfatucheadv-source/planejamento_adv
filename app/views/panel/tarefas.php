<div class="page-head">
  <div><h1>Tarefas</h1><p class="sub">Tarefas abertas, das mais urgentes para as sem data.</p></div>
  <div class="seg">
    <a class="<?= $scope === 'minhas' ? 'on' : '' ?>" href="<?= e(url('painel/tarefas', ['de' => 'minhas'])) ?>">Minhas</a>
    <a class="<?= $scope === 'todas' ? 'on' : '' ?>" href="<?= e(url('painel/tarefas', ['de' => 'todas'])) ?>">Toda a equipe</a>
  </div>
</div>
<section class="box">
  <?php if (!$tasks): ?><p class="empty">Nenhuma tarefa aberta.</p><?php endif ?>
  <ul class="task-list">
    <?php foreach ($tasks as $t): $late = $t['due_at'] && dt($t['due_at']) < now(); ?>
      <li class="<?= $late ? 'late' : '' ?>">
        <form method="post" action="<?= e(url('painel/tarefa/' . $t['id'])) ?>"><?= csrf_field() ?><input type="hidden" name="op" value="concluir"><button class="tick" aria-label="Concluir"></button></form>
        <div>
          <span><?= e($t['title']) ?> <?php if (!empty($t['urgent'])): ?><span class="tag tag-red">urgente</span><?php endif ?></span>
          <small><?php if ($t['case_id']): ?><a href="<?= e(url('painel/caso/' . $t['case_id'], ['aba' => 'tarefas'])) ?>"><?= e($t['client_name']) ?> · <?= e($t['protocol']) ?></a> · <?php endif ?><?= e(user_name($t['owner_id'] ? (int) $t['owner_id'] : null)) ?> · <?= $t['due_at'] ? ($late ? 'venceu ' : 'vence ') . e(relative($t['due_at'])) . ' (' . e(fmt_datetime($t['due_at'])) . ')' : 'sem data' ?> · depende de <?= e(TASK_DEPENDS[$t['depends_on']] ?? $t['depends_on']) ?></small>
        </div>
      </li>
    <?php endforeach ?>
  </ul>
</section>
