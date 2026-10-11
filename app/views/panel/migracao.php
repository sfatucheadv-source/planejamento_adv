<?php
$labels = ['coleta' => 'Coleta', 'analise' => 'Análise', 'decisao' => 'Conferir conversa', 'assinatura' => 'Assinatura pendente', 'execucao' => 'Execução/legado', 'encerrado' => 'Encerrado'];
$counts = [];
foreach ($rows as $r) { $counts[$r['class'][0]] = ($counts[$r['class'][0]] ?? 0) + 1; }
?>
<div class="page-head"><div><h1>Migração dos atendimentos anteriores</h1>
<p class="sub">Relatório dos registros do fluxo de 14 etapas. Nada é migrado automaticamente: cada atendimento pré-contratual é conferido e migrado por você. Contratados e encerrados continuam na execução, com prazos e histórico.</p></div></div>
<div class="kpis"><?php foreach ($labels as $k => $l): ?><div class="kpi"><span class="lbl"><?= e($l) ?></span><strong><?= (int) ($counts[$k] ?? 0) ?></strong></div><?php endforeach ?></div>
<div class="tbl-wrap box">
<table class="tbl tbl-compact">
  <thead><tr><th>Cliente</th><th>Etapa atual</th><th>Classe</th><th>Proposta</th><th>Ação</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): $c = $r['c']; [$cls, $text, $suggest] = $r['class']; ?>
    <tr>
      <td><a href="<?= e(url('painel/caso/' . $c['id'])) ?>"><?= e($c['client_name']) ?></a><br><span class="mono"><?= e($c['protocol']) ?></span></td>
      <td><?= e(stage_label($c['stage'])) ?><?= $c['status'] !== 'ativo' ? ' · ' . e($c['status']) : '' ?></td>
      <td><span class="tag"><?= e($labels[$cls]) ?></span></td>
      <td class="small"><?= e($text) ?></td>
      <td>
        <?php if ($suggest): ?>
          <form method="post" action="<?= e(url('painel/migracao')) ?>" class="mini-form"><?= csrf_field() ?><input type="hidden" name="case_id" value="<?= (int) $c['id'] ?>">
            <select name="target" aria-label="Situação de destino"><?php foreach (INITIAL_STATUS as $k => $s): if ($k === 'completed') { continue; } ?><option value="<?= $k ?>" <?= selected($k, $suggest) ?>><?= e($s['label']) ?></option><?php endforeach ?></select>
            <button class="b b-xs" type="submit">Conferi: migrar</button>
          </form>
        <?php else: ?><span class="muted small">Permanece</span><?php endif ?>
      </td>
    </tr>
  <?php endforeach ?>
  <?php if (!$rows): ?><tr><td colspan="5" class="empty">Não há atendimentos no fluxo anterior.</td></tr><?php endif ?>
  </tbody>
</table>
</div>
<p class="hint">Uso por terminal: <code>php scripts/relatorio-migracao.php</code> lista as mesmas classes, exceções e campos não mapeados.</p>
