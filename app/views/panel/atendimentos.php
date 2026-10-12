<?php
$hour = (int) now()->format('G');
$greet = $hour < 12 ? 'Bom dia' : ($hour < 18 ? 'Boa tarde' : 'Boa noite');
$q = trim((string) input('q'));
$statusTag = [
    'collecting' => 'tag-blue', 'analysis' => 'tag-amber', 'meeting_scheduled' => 'tag-green', 'awaiting_decision' => 'tag-amber', 'completed' => 'tag-gray',
];
?>
<div class="page-head">
  <div>
    <h1><?= e($greet) ?>, <?= e(first_name($u['name'])) ?>.</h1>
    <p class="sub"><?= $rows ? plural(count($rows), 'atendimento', 'atendimentos') . ' nesta lista.' : 'Nenhum atendimento nesta lista.' ?> Clique em um para abrir a ficha.</p>
  </div>
  <a class="b b-primary" href="<?= e(url('painel/atendimentos/novo')) ?>">+ Registrar atendimento</a>
</div>

<form class="filters" method="get" action="<?= e(url('painel/atendimentos')) ?>" role="search">
  <div class="seg" role="group" aria-label="Filtro">
    <?php foreach (ATTENDANCE_FILTERS as $k => $label): ?>
      <a class="<?= $filter === $k ? 'on' : '' ?>" href="<?= e(url('painel/atendimentos', array_filter(['f' => $k === 'todos' ? null : $k, 'q' => $q ?: null]))) ?>"><?= e($label) ?></a>
    <?php endforeach ?>
  </div>
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="Nome, protocolo, telefone ou contrato" aria-label="Pesquisar">
  <?php if ($filter !== 'todos'): ?><input type="hidden" name="f" value="<?= e($filter) ?>"><?php endif ?>
  <button class="b" type="submit">Pesquisar</button>
</form>

<?php if (!$rows): ?>
  <p class="q-empty"><?= $filter === 'arquivados' ? 'Nenhum atendimento arquivado.' : ($filter === 'concluidos' ? 'Nenhum atendimento inicial concluído ainda.' : 'Tudo em dia. Quando alguém começar pelo assistente do site, o atendimento aparece aqui.') ?></p>
<?php endif ?>

<div class="at-list">
  <?php foreach ($rows as $r): $c = $r['c']; $late = $r['due'] && dt($r['due']) < now(); ?>
    <a class="at-row <?= $r['urgent'] ? 'is-urgent' : '' ?> <?= $r['new'] ? 'is-new' : '' ?>" href="<?= e(url('painel/atendimento/' . $c['id'])) ?>">
      <span class="at-main">
        <span class="at-name"><?= e($c['client_name']) ?>
          <?php if ($r['urgent']): ?><em class="tag tag-red"><?= e(URGENCY_SIGNALS[$c['urgency_signal']] ?? 'Prazo próximo') ?></em><?php endif ?>
          <?php if ($r['new']): ?><em class="tag tag-blue">novo</em><?php endif ?>
        </span>
        <?php $irmaos = count(client_sibling_cases($c)); ?>
        <span class="at-meta"><span class="mono"><?= e($c['protocol']) ?></span> · <?= e(client_case_title($c)) ?> · <?= e($c['bank'] ?: 'banco não informado') ?> · início <?= e(fmt_date(substr((string) $c['created_at'], 0, 10))) ?><?= $r['contract'] ? ' · contrato ' . e($r['contract']) : '' ?><?= $irmaos > 1 ? ' · <em class="tag tag-gray">' . $irmaos . ' atendimentos do cliente</em>' : '' ?><?= $c['archived_at'] ? ' · <em class="tag tag-gray">arquivado</em>' : '' ?></span>
        <span class="at-next"><strong>Próxima ação:</strong> <?= e($c['next_action'] ?: 'definir') ?><?php if ($r['pending']): ?> · <span class="muted">pendência: <?= e($r['pending']) ?></span><?php endif ?></span>
      </span>
      <span class="at-side">
        <span class="tag <?= $statusTag[$c['initial_status']] ?? '' ?>"><?= e(initial_status_label($c)) ?></span>
        <span class="at-when <?= $late ? 'late' : '' ?>"><?= $r['due'] ? ($late ? 'retorno vencido · ' : 'retorno ') . e(fmt_when(dt($r['due']))) : 'sem retorno' ?></span>
        <small>atualizado <?= e(relative($c['updated_at'])) ?></small>
      </span>
    </a>
  <?php endforeach ?>
</div>
