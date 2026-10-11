<?php
/** Simulação do pagamento: totais e quadro de parcelas (também devolvida pela prévia ao vivo). */
$t = proposal_totals($d);
$p = $d['pagamento'];
$sched = proposal_schedule($d);
$cartao = $p['condicao'] === 'parcelado' && $p['modalidade'] === 'cartao';
$totalPago = $t['entrada'] + array_sum(array_column($sched, 'valor'));
?>
<dl class="prop-totais">
  <div><dt>Honorários</dt><dd><strong><?= $t['total'] ? e(brl($t['total'])) : '—' ?></strong><?= $d['exito']['ativo'] && $d['exito']['percentual'] !== '' ? ' + ' . e(str_replace('.', ',', (string) parse_money(str_replace('%', '', $d['exito']['percentual'])))) . '% de êxito' : '' ?></dd></div>
  <?php if ($p['condicao'] === 'avista'): ?>
    <div><dt>À vista</dt><dd><?= $t['total'] ? e(brl($t['total'])) : '—' ?><?= $p['vencimento'] ? ' em ' . e(fmt_date($p['vencimento'])) : '' ?></dd></div>
  <?php else: ?>
    <?php if ($t['entrada']): ?><div><dt>Entrada</dt><dd><?= e(brl($t['entrada'])) ?><?= $p['entrada_vencimento'] ? ' em ' . e(fmt_date($p['entrada_vencimento'])) : '' ?></dd></div><?php endif ?>
    <div><dt><?= $cartao ? 'No cartão' : 'Parcelado' ?></dt><dd><?= $sched ? count($sched) . 'x' . ($cartao ? ' de ' . e(brl($sched[0]['valor'])) . ' + taxa da operadora' : '') : '—' ?></dd></div>
    <?php if (!$cartao && $sched): ?><div><dt>Total com juros</dt><dd><strong><?= e(brl($totalPago)) ?></strong> <small class="muted">(juros de <?= e(brl($totalPago - $t['total'])) ?>)</small></dd></div><?php endif ?>
  <?php endif ?>
</dl>
<?php if ($sched && !$cartao): ?>
  <div class="tbl-wrap">
    <table class="tbl tbl-compact prop-quadro">
      <thead><tr><th>Parcela</th><th>Vencimento</th><th>Principal</th><th>Juros</th><th>Valor</th></tr></thead>
      <tbody>
        <?php foreach ($sched as $s): ?><tr><td><?= (int) $s['n'] ?>ª</td><td><?= e($s['vencimento'] ? fmt_date($s['vencimento']) : '—') ?></td><td><?= e(brl($s['principal'])) ?></td><td><?= e(brl($s['juros'])) ?> <small class="muted"><?= (int) $s['n'] ?>%</small></td><td><strong><?= e(brl($s['valor'])) ?></strong></td></tr><?php endforeach ?>
      </tbody>
    </table>
  </div>
  <p class="hint">Juros simples de 1% por mês, da contratação até cada vencimento. O INPC é aplicado na data de cada pagamento.</p>
<?php elseif ($cartao && $sched): ?>
  <p class="hint">A taxa de parcelamento da operadora é somada às parcelas e paga pelo cliente.</p>
<?php endif ?>
