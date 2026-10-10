<?php
$hour = (int) now()->format('G');
$greet = $hour < 12 ? 'Bom dia' : ($hour < 18 ? 'Boa tarde' : 'Boa noite');
$item = function (array $it, bool $wait) {
    $c = $it['case'];
    $s = stage($c['stage']);
    $due = $it['due'] ?? $c['next_action_due'];
    $late = $due && dt($due) < now();
    $title = $it['step']['title'] ?? $it['reason'] ?? 'Ver o caso';
    ?>
    <a class="q-item <?= $c['urgent'] ? 'is-urgent' : '' ?> <?= $wait ? 'is-wait' : '' ?>" href="<?= e(url('painel/executar/' . $c['id'], isset($it['step']['key']) && !$wait ? ['passo' => $it['step']['key']] : [])) ?>">
      <span class="q-stage" title="Etapa <?= $s['n'] ?> de 14"><?= $s['n'] ?></span>
      <span class="q-body">
        <span class="q-name"><?= e($c['client_name']) ?><?php if ($c['urgent']): ?> <em class="tag tag-red">urgente</em><?php endif ?><?php if ($c['status'] !== 'ativo'): ?> <em class="tag tag-gray">encerrado</em><?php endif ?></span>
        <span class="q-what"><?= $wait ? e($it['reason']) : e($title) ?></span>
        <span class="q-meta"><?= e($s['label']) ?> · <?= e($c['bank'] ?: 'banco não informado') ?> · <span class="mono"><?= e($c['protocol']) ?></span><?php if (!$wait && $it['tasks']): ?> · <?= plural(count($it['tasks']), 'tarefa', 'tarefas') ?><?php endif ?></span>
      </span>
      <span class="q-when <?= $late && !$wait ? 'late' : '' ?>">
        <?php if ($wait): ?>
          <small>retorno ao cliente</small><?= $c['next_client_update'] ? e(fmt_date($c['next_client_update'])) : '—' ?>
        <?php elseif ($due): ?>
          <small><?= $late ? 'atrasado' : 'até' ?></small><?= e(fmt_when(dt($due))) ?>
        <?php endif ?>
      </span>
      <span class="q-go" aria-hidden="true"><?= $wait ? 'Ver' : 'Fazer' ?> →</span>
    </a>
    <?php
};
?>
<div class="page-head">
  <div>
    <h1><?= e($greet) ?>, <?= e(first_name($u['name'])) ?>.</h1>
    <p class="sub">
      <?php if ($todo): ?>Você tem <strong><?= plural(count($todo), 'caso', 'casos') ?></strong> esperando por você. Clique em um para abrir o passo a passo.
      <?php else: ?>Nada pendente com você agora.<?php endif ?>
    </p>
  </div>
  <a class="b b-primary" href="<?= e(url('painel/casos/novo')) ?>">+ Novo atendimento</a>
</div>

<section class="q-list" aria-labelledby="q-todo">
  <h2 id="q-todo" class="q-h">A fazer agora <span><?= count($todo) ?></span></h2>
  <?php if (!$todo): ?>
    <p class="q-empty">Tudo em dia. Quando um caso chegar na sua vez, ele aparece aqui.</p>
  <?php endif ?>
  <?php foreach ($todo as $it) { $item($it, false); } ?>
</section>

<?php if ($waiting): ?>
<section class="q-list" aria-labelledby="q-wait">
  <h2 id="q-wait" class="q-h">Aguardando cliente, banco ou Justiça <span><?= count($waiting) ?></span></h2>
  <p class="hint">Estes casos voltam para “A fazer” sozinhos quando a resposta for registrada. Mesmo sem novidade, o cliente recebe notícia a cada <?= (int) config('update_cycle_days') ?> dias.</p>
  <?php foreach ($waiting as $it) { $item($it, true); } ?>
</section>
<?php endif ?>

<p class="hint q-foot">Funções: <?= e(implode(' · ', array_map(fn($r) => ROLES[$r] ?? $r, user_roles($u)))) ?>. A fila mostra só os passos dessas funções nos casos em que você é o responsável. A visão de todos os casos está em <a href="<?= e(url('painel/casos')) ?>">Casos</a>.</p>
