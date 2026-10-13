<?php
/** Indicadores do topo da proposta (refeitos a cada alteração). */
$std = pricing_standard((int) $c['id']);
$ia = pricing_ia((int) $c['id']);
$t = proposal_totals($d);
$rows = $t['agora'] ? sales_sim($t['agora'], [], $d) : [];
$curKey = sales_sim_current_key($d);
$cur = array_values(array_filter($rows, fn($r) => $r['key'] === $curKey))[0] ?? null;
?>
<div class="neg-kpi"><span>Padrão</span><strong><?= e(brl($std['valor'])) ?></strong><small><?= e($std['nota']) ?> · + <?= e(fmt_pct($std['exito'])) ?> de êxito</small></div>
<div class="neg-kpi neg-kpi-ia"><span>Proposta da IA</span><strong><?= $ia ? e(brl($ia)) : '—' ?></strong><small><?= $ia ? 'última avaliação' : 'peça na aba Honorários' ?></small></div>
<div class="neg-kpi"><span>Honorários</span><strong><?= $t['total'] ? e(brl($t['total'])) : '—' ?></strong><small><?= $t['desconto'] ? 'com ' . e(brl($t['desconto'])) . ' de desconto' : ($d['exito']['ativo'] ? '+ êxito de ' . e($d['exito']['percentual']) . '%' : 'sem desconto') ?></small></div>
<div class="neg-kpi"><span>Ao final do processo</span><strong><?= $t['final'] ? e(brl($t['final'])) : '—' ?></strong><small><?= $t['final'] ? 'INPC + 1% a.m., devido mesmo sem êxito' : 'nada para o final' ?></small></div>
<div class="neg-kpi"><span>Cliente paga agora</span><strong><?= $cur ? e(brl($cur['cliente'])) : ($t['agora'] ? e(brl($t['agora'])) : '—') ?></strong><small><?= $cur ? e($cur['label']) . ($cur['n'] > 1 ? ' · ' . (int) $cur['n'] . 'x de ' . e(brl($cur['parcela'])) : '') : '' ?></small></div>
<div class="neg-kpi"><span>Você recebe</span><strong><?= $cur ? e(brl($cur['liquido'])) : '—' ?></strong><small><?= $cur ? ($cur['taxas'] ? 'após ' . e(brl($cur['taxas'])) . ' de taxas' : 'sem taxa') : '' ?></small></div>
