<?php
$s = stage($c['stage']);
$alerts = case_alerts($c);
$tabs = [
    'resumo' => 'Resumo',
    'atendimento' => 'Acolhimento e urgência',
    'documentos' => 'Documentos',
    'analise' => 'Análise',
    'decisao' => 'Decisão e contratação',
    'negociacao' => 'Negociação e acordo',
    'judicial' => 'Judicial',
    'conferencia' => 'Conferência e encerramento',
    'tarefas' => 'Tarefas e exceções',
    'mensagens' => 'Mensagens',
    'historico' => 'Histórico',
];
if (!isset($tabs[$tab])) {
    $tab = 'resumo';
}
$counts = [
    'documentos' => count(array_filter($docs, fn($d) => in_array($d['status'], ['falta', 'pendencia', 'ajuda', 'recebido'], true))),
    'tarefas' => count(array_filter($tasks, fn($t) => $t['status'] === 'aberta')) + count(array_filter($exceptions, fn($x) => !$x['resolved_at'])),
    'decisao' => count(array_filter($decisions, fn($d) => !$d['answer'])),
];
$stageKeys = array_keys(stages());
$curIdx = array_search($c['stage'], $stageKeys, true);
?>
<div class="case-head <?= $c['urgent'] ? 'is-urgent' : '' ?>">
  <div class="ch-main">
    <p class="crumb"><a href="<?= e(url('painel/casos')) ?>">Casos</a> / <span class="mono"><?= e($c['protocol']) ?></span></p>
    <h1><?= e($c['client_name']) ?>
      <?php if ($c['urgent']): ?><span class="tag tag-red">urgente</span><?php endif ?>
      <?php if ($c['status'] !== 'ativo'): ?><span class="tag tag-gray">encerrado · <?= e(OUTCOMES[$c['outcome']]['label'] ?? '') ?></span><?php endif ?>
    </h1>
    <p class="sub">
      <?= e($c['client_phone']) ?> <a href="<?= e(whatsapp_link('', $c['client_phone'])) ?>" target="_blank" rel="noopener">WhatsApp</a>
      <?= $c['client_email'] ? ' · ' . e($c['client_email']) : '' ?>
      <?= $c['client_city'] ? ' · ' . e($c['client_city']) . '/' . e($c['client_uf']) : '' ?>
      <?= $c['bank'] ? ' · ' . e($c['bank']) : '' ?>
      · origem <?= e($c['source']) ?> · aberto <?= e(fmt_datetime($c['created_at'])) ?>
    </p>
    <p class="objective"><strong>Objetivo:</strong> <?= e($c['objective'] ?: '— registrar na aba Acolhimento —') ?><?php if ($c['objective_measure']): ?> <small>· comprovação: <?= e($c['objective_measure']) ?></small><?php endif ?></p>
  </div>
  <div class="ch-side">
    <p><span class="lbl">Etapa</span><strong><?= $s['n'] ?>. <?= e($s['label']) ?></strong><small>há <?= e(str_replace('há ', '', relative($c['stage_since']))) ?> · cliente vê “<?= e(CLIENT_PHASES[$s['phase']]['label']) ?>”</small></p>
    <p><span class="lbl">Gestor · Advogado · Substituto</span><?= e(first_name(user_name($c['gestor_id'] ? (int) $c['gestor_id'] : null))) ?> · <?= e(first_name(user_name($c['advogado_id'] ? (int) $c['advogado_id'] : null))) ?> · <?= e(first_name(user_name($c['substituto_id'] ? (int) $c['substituto_id'] : null))) ?></p>
  </div>
</div>

<ol class="stage-line" aria-label="Etapas internas">
  <?php foreach (stages() as $k => $st): $i = array_search($k, $stageKeys, true); ?>
    <li class="<?= $k === $c['stage'] ? 'cur' : ($i < $curIdx ? 'past' : '') ?>" title="<?= e($st['title']) ?>"><span><?= $st['n'] ?></span><em><?= e($st['label']) ?></em></li>
  <?php endforeach ?>
</ol>

<?php if ($alerts): ?>
  <ul class="alerts alerts-inline">
    <?php foreach ($alerts as $a): ?><li class="alert alert-<?= e($a['level']) ?>"><span><?= e($a['text']) ?></span><small><?= e($a['miss']) ?></small></li><?php endforeach ?>
  </ul>
<?php endif ?>

<nav class="tabs" aria-label="Seções do caso">
  <?php foreach ($tabs as $k => $label): ?>
    <a class="<?= $tab === $k ? 'on' : '' ?>" href="<?= e(url('painel/caso/' . $c['id'], ['aba' => $k])) ?>"><?= e($label) ?><?php if (!empty($counts[$k])): ?><b><?= $counts[$k] ?></b><?php endif ?></a>
  <?php endforeach ?>
</nav>

<div class="tab-body">
  <?php require __DIR__ . '/caso/' . $tab . '.php'; ?>
</div>
