<?php
$high = array_filter($alerts, fn($a) => $a['level'] === 'high');
$hour = (int) now()->format('G');
$greet = $hour < 12 ? 'Bom dia' : ($hour < 18 ? 'Boa tarde' : 'Boa noite');
?>
<div class="page-head">
  <div>
    <h1><?= e($greet) ?>, <?= e(first_name($u['name'])) ?></h1>
    <p class="sub"><?= e(SEMANA[(int) now()->format('w')]) ?>, <?= e(fmt_date(now_str(), true)) ?> · <?= count($active) ?> casos ativos · <?= count($high) ?> alertas prioritários</p>
  </div>
  <a class="b b-primary" href="<?= e(url('painel/casos/novo')) ?>">+ Novo atendimento</a>
</div>

<div class="kpis">
  <a class="kpi <?= $waiting ? 'kpi-alert' : '' ?>" href="<?= e(url('painel/casos', ['etapa' => 'acolhimento'])) ?>"><strong><?= count($waiting) ?></strong><span>aguardando primeiro contato</span></a>
  <a class="kpi <?= array_filter($active, fn($c) => $c['urgent']) ? 'kpi-alert' : '' ?>" href="<?= e(url('painel/casos', ['urgente' => 1])) ?>"><strong><?= count(array_filter($active, fn($c) => $c['urgent'])) ?></strong><span>urgentes</span></a>
  <a class="kpi" href="<?= e(url('painel/tarefas')) ?>"><strong><?= count($myTasks) ?></strong><span>tarefas abertas comigo</span></a>
  <a class="kpi" href="<?= e(url('painel/casos', ['etapa' => 'negociacao'])) ?>"><strong><?= (int) ($byStage['negociacao'] ?? 0) ?></strong><span>em negociação</span></a>
  <a class="kpi" href="<?= e(url('painel/casos', ['etapa' => 'processo'])) ?>"><strong><?= (int) (($byStage['preparacao_judicial'] ?? 0) + ($byStage['processo'] ?? 0) + ($byStage['decisao_recursos'] ?? 0)) ?></strong><span>na via judicial</span></a>
</div>

<div class="grid-2col">
  <section class="box">
    <h2>Alertas das metas e exceções</h2>
    <?php if (!$alerts): ?>
      <p class="empty">Nenhum alerta. Todos os casos ativos têm próxima ação, dentro das metas.</p>
    <?php else: ?>
      <ul class="alerts">
        <?php foreach (array_slice($alerts, 0, 30) as $a): ?>
          <li class="alert alert-<?= e($a['level']) ?>">
            <a href="<?= e(url('painel/caso/' . $a['case_id'])) ?>"><strong><?= e($a['case']['client_name']) ?></strong> <span class="mono"><?= e($a['case']['protocol']) ?></span></a>
            <span><?= e($a['text']) ?></span>
            <small>Se a meta não for cumprida: <?= e($a['miss']) ?></small>
          </li>
        <?php endforeach ?>
      </ul>
    <?php endif ?>
  </section>

  <section class="box">
    <h2>Minhas tarefas</h2>
    <?php if (!$myTasks): ?>
      <p class="empty">Nada pendente com você.</p>
    <?php else: ?>
      <ul class="task-list">
        <?php foreach ($myTasks as $t): $late = $t['due_at'] && dt($t['due_at']) < now(); ?>
          <li class="<?= $late ? 'late' : '' ?>">
            <form method="post" action="<?= e(url('painel/tarefa/' . $t['id'])) ?>"><?= csrf_field() ?><input type="hidden" name="op" value="concluir"><button class="tick" title="Concluir" aria-label="Concluir tarefa"></button></form>
            <div>
              <span><?= e($t['title']) ?></span>
              <small><?php if ($t['case_id']): ?><a href="<?= e(url('painel/caso/' . $t['case_id'])) ?>"><?= e($t['client_name']) ?></a> · <?php endif ?><?= $t['due_at'] ? ($late ? 'venceu ' : 'vence ') . e(relative($t['due_at'])) : 'sem data' ?> · depende de <?= e(TASK_DEPENDS[$t['depends_on']] ?? $t['depends_on']) ?></small>
            </div>
          </li>
        <?php endforeach ?>
      </ul>
    <?php endif ?>
  </section>
</div>

<div class="grid-2col">
  <section class="box">
    <h2>Respostas recentes de clientes</h2>
    <?php if (!$answers && !$clientNotes): ?>
      <p class="empty">Nenhuma resposta ou recado nos últimos dias.</p>
    <?php else: ?>
      <ul class="feed">
        <?php foreach ($answers as $d): ?>
          <li><a href="<?= e(url('painel/caso/' . $d['case_id'], ['aba' => 'decisao'])) ?>"><strong><?= e($d['client_name']) ?></strong></a> respondeu “<?= e($d['answer']) ?>” <small><?= e(relative($d['answered_at'])) ?> · <?= e(mb_strimwidth($d['question'], 0, 80, '…')) ?></small></li>
        <?php endforeach ?>
        <?php foreach ($clientNotes as $n): ?>
          <li><a href="<?= e(url('painel/caso/' . $n['case_id'], ['aba' => 'historico'])) ?>"><strong><?= e($n['client_name']) ?></strong></a> · <?= e($n['title']) ?> <small><?= e(relative($n['at'])) ?></small><?php if ($n['body'] && $n['type'] === 'mensagem_cliente'): ?><blockquote><?= e(mb_strimwidth($n['body'], 0, 220, '…')) ?></blockquote><?php endif ?></li>
        <?php endforeach ?>
      </ul>
    <?php endif ?>
  </section>

  <section class="box">
    <h2>Casos por etapa</h2>
    <ul class="stage-bars">
      <?php $max = max(1, ...array_values($byStage ?: [1])); foreach (stages() as $k => $s): $n = (int) ($byStage[$k] ?? 0); ?>
        <li><a href="<?= e(url('painel/casos', ['etapa' => $k])) ?>"><span class="sb-label"><?= $s['n'] ?>. <?= e($s['label']) ?></span><span class="sb-bar"><span style="width: <?= round($n / $max * 100) ?>%"></span></span><span class="sb-n"><?= $n ?></span></a></li>
      <?php endforeach ?>
    </ul>
  </section>
</div>
