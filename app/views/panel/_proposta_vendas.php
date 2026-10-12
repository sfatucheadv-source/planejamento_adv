<?php
/** Simulador de vendas: quanto o cliente paga e quanto você recebe em cada forma (taxas do Asaas). */
$rows = sales_sim((int) $opt['valor'], $opt, $d);
$curKey = sales_sim_current_key($d);
if (!$rows): ?>
  <p class="hint">Informe os honorários (ou um valor para simular) para ver as formas de pagamento.</p>
<?php return; endif;
$best = max(array_column($rows, 'liquido'));
$f = asaas_fees();
$show = fn($r) => $r['grupo'] !== 'cartao' || in_array($r['n'], [1, 2, 3, 4, 6, 10, 12, 18, 21], true) || $r['key'] === $curKey;
?>
<p class="neg-sim-sub">Simulando <strong><?= e(brl((int) $opt['valor'])) ?></strong><?= $opt['valor_manual'] ? ' (valor digitado)' : ' (honorários da proposta)' ?> · taxas <?= asaas_fees_custom() ? 'da sua conta' : 'de referência do Asaas' ?><?= $opt['repasse'] ? ' · taxa do cartão paga pelo cliente' : '' ?></p>
<div class="tbl-wrap">
  <table class="tbl tbl-compact neg-sim">
    <thead><tr><th>Forma</th><th>Cliente paga</th><th>Parcela</th><th>Você recebe</th><th>Antecipando</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($rows as $r): if (!$show($r)) { continue; } $lost = $best - $r['liquido']; ?>
        <tr class="<?= $r['key'] === $curKey ? 'is-cur' : '' ?> neg-<?= e($r['grupo']) ?>">
          <td><?= e($r['label']) ?><?= $r['key'] === $curKey ? ' <span class="neg-tag">na proposta</span>' : '' ?><br><small class="muted">recebe <?= e($r['recebe']) ?></small></td>
          <td><strong><?= e(brl($r['cliente'])) ?></strong></td>
          <td><?= $r['n'] > 1 ? (int) $r['n'] . 'x ' . e(brl($r['parcela'])) . (!empty($r['parcela_ultima']) && $r['parcela_ultima'] !== $r['parcela'] ? '<br><small class="muted">até ' . e(brl($r['parcela_ultima'])) . '</small>' : '') : '—' ?></td>
          <td><strong><?= e(brl($r['liquido'])) ?></strong><br><small class="<?= $lost > 0 ? 't-warn' : 'muted' ?>"><?= $lost > 0 ? '−' . e(brl($lost)) . ' vs. melhor' : 'melhor líquido' ?></small></td>
          <td><?= $r['antecipado'] !== null ? e(brl($r['antecipado'])) . '<br><small class="muted">tudo em ~2 dias</small>' : '<small class="muted">' . ($r['grupo'] === 'proprio' ? 'risco de atraso é seu' : '—') . '</small>' ?></td>
          <td><button type="button" class="b b-sm" data-usar-tipo="<?= e($r['grupo'] === 'avista' ? 'avista' : ($r['grupo'] === 'cartao' ? 'cartao' : 'proprio')) ?>" data-usar-parcelas="<?= (int) $r['n'] ?>" data-usar-forma="<?= e($r['grupo'] === 'avista' ? $r['key'] : '') ?>" title="Usar esta condição na proposta">Usar</button></td>
        </tr>
      <?php endforeach ?>
    </tbody>
  </table>
</div>
<p class="hint">Cartão: <?= e(fmt_pct($f['cartao_1'])) ?> à vista, <?= e(fmt_pct($f['cartao_2_6'])) ?> de 2 a 6x, <?= e(fmt_pct($f['cartao_7_12'])) ?> de 7 a 12x<?= $f['cartao_max'] > 12 ? ', ' . e(fmt_pct($f['cartao_13_21'])) . ' acima de 12x' : '' ?>, mais <?= e(brl((int) round($f['cartao_fixo'] * 100))) ?> por cobrança; antecipação a <?= e(fmt_pct($f['antecipacao'])) ?> ao mês. PIX e boleto: <?= e(brl((int) round($f['pix_fixo'] * 100))) ?> e <?= e(brl((int) round($f['boleto_fixo'] * 100))) ?> por pagamento. Parcelado próprio: juros de 1% a.m. a favor do escritório, sem antecipação. <a href="<?= e(url('painel/configuracoes/precos')) ?>">Ajustar taxas</a></p>
