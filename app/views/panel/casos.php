<?php
$byStage = [];
foreach ($cases as $c) {
    $byStage[$c['stage']][] = $c;
}
$qs = $_GET;
?>
<div class="page-head">
  <div>
    <h1>Casos</h1>
    <p class="sub"><?= plural(count($cases), 'caso encontrado', 'casos encontrados') ?>. Cada cartão mostra a próxima ação e de quem ela depende.</p>
  </div>
  <div class="seg">
    <a class="<?= $vista === 'quadro' ? 'on' : '' ?>" href="<?= e(url('painel/casos', ['vista' => 'quadro'] + $qs)) ?>">Quadro</a>
    <a class="<?= $vista === 'lista' ? 'on' : '' ?>" href="<?= e(url('painel/casos', ['vista' => 'lista'] + $qs)) ?>">Lista</a>
  </div>
</div>

<form class="filters" method="get" action="<?= e(url('painel/casos')) ?>">
  <input type="hidden" name="vista" value="<?= e($vista) ?>">
  <input type="search" name="q" value="<?= e((string) input('q')) ?>" placeholder="Nome, protocolo, telefone, banco">
  <select name="etapa"><option value="">Todas as etapas</option><?php foreach (stages() as $k => $s): ?><option value="<?= e($k) ?>"<?= selected(input('etapa'), $k) ?>><?= $s['n'] ?>. <?= e($s['label']) ?></option><?php endforeach ?></select>
  <select name="status"><?php foreach (['ativo' => 'Ativos', 'encerrado' => 'Encerrados', 'todos' => 'Todos'] as $k => $l): ?><option value="<?= $k ?>"<?= selected(input('status', 'ativo'), $k) ?>><?= $l ?></option><?php endforeach ?></select>
  <label class="chk"><input type="checkbox" name="urgente" value="1"<?= checked(input('urgente') === '1') ?>> Só urgentes</label>
  <label class="chk"><input type="checkbox" name="meus" value="1"<?= checked(input('meus') === '1') ?>> Meus casos</label>
  <button class="b">Filtrar</button>
</form>

<?php if ($vista === 'lista'): ?>
  <div class="tbl-wrap">
    <table class="tbl">
      <thead><tr><th>Cliente</th><th>Etapa</th><th>Próxima ação</th><th>Gestor</th><th>Retorno ao cliente</th><th>Atualizado</th></tr></thead>
      <tbody>
        <?php foreach ($cases as $c): $alerts = case_alerts($c); ?>
          <tr class="<?= $c['urgent'] ? 'row-urgent' : '' ?>">
            <td><a href="<?= e(url('painel/caso/' . $c['id'])) ?>"><strong><?= e($c['client_name']) ?></strong></a><br><small class="mono"><?= e($c['protocol']) ?></small> <?php if ($c['urgent']): ?><span class="tag tag-red">urgente</span><?php endif ?><?php if ($alerts): ?><span class="tag tag-amber"><?= count($alerts) ?> alerta<?= count($alerts) > 1 ? 's' : '' ?></span><?php endif ?></td>
            <td><?= e(stage_label($c['stage'])) ?><?= $c['status'] !== 'ativo' ? '<br><small>' . e(OUTCOMES[$c['outcome']]['label'] ?? 'Encerrado') . '</small>' : '' ?></td>
            <td><?= e($c['next_action'] ?: '—') ?><br><small><?= $c['next_action_due'] ? e(relative($c['next_action_due'])) : 'sem data' ?></small></td>
            <td><?= e(user_name($c['gestor_id'] ? (int) $c['gestor_id'] : null)) ?></td>
            <td><?= $c['next_client_update'] ? e(fmt_date($c['next_client_update'])) : '—' ?></td>
            <td><small><?= e(relative($c['updated_at'])) ?></small></td>
          </tr>
        <?php endforeach ?>
      </tbody>
    </table>
  </div>
<?php else: ?>
  <div class="board">
    <?php foreach (CLIENT_PHASES as $pk => $ph): ?>
      <div class="board-phase">
        <h2 class="phase-title"><?= e($ph['label']) ?></h2>
        <div class="board-cols">
          <?php foreach (stages() as $sk => $s): if ($s['phase'] !== $pk) continue; $list = $byStage[$sk] ?? []; ?>
            <section class="col">
              <header><span><?= $s['n'] ?>. <?= e($s['label']) ?></span><b><?= count($list) ?></b></header>
              <?php foreach ($list as $c): $alerts = case_alerts($c); ?>
                <a class="kcard <?= $c['urgent'] ? 'kcard-urgent' : '' ?>" href="<?= e(url('painel/caso/' . $c['id'])) ?>">
                  <strong><?= e($c['client_name']) ?></strong>
                  <small class="mono"><?= e($c['protocol']) ?><?= $c['bank'] ? ' · ' . e($c['bank']) : '' ?></small>
                  <span class="kc-next"><?= e($c['next_action'] ?: 'Sem próxima ação') ?></span>
                  <span class="kc-meta"><?= $c['next_action_due'] ? e(relative($c['next_action_due'])) : 'sem data' ?> · <?= e(first_name(user_name($c['gestor_id'] ? (int) $c['gestor_id'] : null))) ?></span>
                  <?php if ($c['urgent'] || $alerts): ?><span class="kc-tags"><?php if ($c['urgent']): ?><span class="tag tag-red">urgente</span><?php endif ?><?php if ($alerts): ?><span class="tag tag-amber"><?= count($alerts) ?> alerta<?= count($alerts) > 1 ? 's' : '' ?></span><?php endif ?></span><?php endif ?>
                </a>
              <?php endforeach ?>
            </section>
          <?php endforeach ?>
        </div>
      </div>
    <?php endforeach ?>
  </div>
<?php endif ?>
