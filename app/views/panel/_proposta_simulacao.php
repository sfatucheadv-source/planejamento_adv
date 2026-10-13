<?php
/** Simulação do pagamento: totais e quadro de parcelas (também devolvida pela prévia ao vivo). */
$t = proposal_totals($d);
$p = $d['pagamento'];
$sched = proposal_schedule($d);
$cartao = $p['condicao'] === 'parcelado' && $p['modalidade'] === 'cartao';
$totalPago = $t['entrada'] + array_sum(array_column($sched, 'valor'));
?>
<dl class="prop-totais">
  <div><dt>Honorários</dt><dd><strong><?= $t['total'] ? e(brl($t['total'])) : '—' ?></strong><?= $t['desconto'] ? ' <small class="muted">(' . e(brl($t['bruto'])) . ' − ' . e(brl($t['desconto'])) . ' de desconto)</small>' : '' ?><?= $d['exito']['ativo'] && $d['exito']['percentual'] !== '' ? ' + ' . e(str_replace('.', ',', (string) parse_money(str_replace('%', '', $d['exito']['percentual'])))) . '% de êxito' : '' ?></dd></div>
  <?php if ($t['final']): ?><div><dt>Ao final do processo</dt><dd><strong><?= e(brl($t['final'])) ?></strong> <small class="muted">INPC + 1% a.m., devido mesmo sem êxito</small></dd></div><?php endif ?>
  <?php if ($t['agora'] <= 0): ?>
  <?php elseif ($p['condicao'] === 'avista'): ?>
    <div><dt>À vista</dt><dd><?= e(brl($t['agora'])) ?><?= $p['vencimento'] ? ' em ' . e(fmt_date($p['vencimento'])) : '' ?></dd></div>
  <?php else: ?>
    <?php if ($t['entrada']): ?><div><dt>Entrada</dt><dd><?= e(brl($t['entrada'])) ?><?= $p['entrada_vencimento'] ? ' em ' . e(fmt_date($p['entrada_vencimento'])) : '' ?></dd></div><?php endif ?>
    <div><dt><?= $cartao ? 'No cartão' : 'Parcelado' ?></dt><dd><?= $sched ? count($sched) . 'x de ' . e(brl($sched[0]['valor'])) . ($cartao ? ' + taxa da operadora' : ' fixas') : '—' ?></dd></div>
    <?php if (!$cartao && $sched): ?><div><dt>Total com juros</dt><dd><strong><?= e(brl($totalPago)) ?></strong> <small class="muted">(juros de <?= e(brl($totalPago - $t['agora'])) ?>)</small></dd></div><?php endif ?>
  <?php endif ?>
</dl>
<?php if ($sched && !$cartao): ?>
  <div class="tbl-wrap">
    <table class="tbl tbl-compact prop-quadro">
      <thead><tr><th>Parcela</th><th>Vencimento</th><th>Amortização</th><th>Juros</th><th>Valor</th></tr></thead>
      <tbody>
        <?php foreach ($sched as $s): ?><tr><td><?= (int) $s['n'] ?>ª</td><td><?= e($s['vencimento'] ? fmt_date($s['vencimento']) : '—') ?></td><td><?= e(brl($s['principal'])) ?></td><td><?= e(brl($s['juros'])) ?></td><td><strong><?= e(brl($s['valor'])) ?></strong></td></tr><?php endforeach ?>
      </tbody>
    </table>
  </div>
  <p class="hint">Parcelas fixas e iguais, juros de 1% ao mês pelo sistema de prestações fixas da Calculadora do Cidadão do Banco Central.</p>
<?php elseif ($cartao && $sched): ?>
  <p class="hint">A taxa de parcelamento da operadora é somada às parcelas e paga pelo cliente. Parcela mínima de <?= e(brl(pricing()['cartao_min_parcela'])) ?>.</p>
<?php endif ?>
