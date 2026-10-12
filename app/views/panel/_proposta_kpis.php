<?php
/** Indicadores do topo do painel de negociação (refeitos a cada alteração da proposta). */
$pp = guide_prices($g);
$t = proposal_totals($d);
$rows = $t['total'] ? sales_sim($t['total'], ['repasse' => $d['pagamento']['modalidade'] === 'cartao' && $d['pagamento']['condicao'] === 'parcelado' ? !empty($opt['repasse']) : false, 'parcelas_proprio' => (int) $d['pagamento']['parcelas']], $d) : [];
$curKey = sales_sim_current_key($d);
$cur = array_values(array_filter($rows, fn($r) => $r['key'] === $curKey))[0] ?? null;
$pos = $pp['referencia'] && $t['total'] ? ($t['total'] / $pp['referencia'] - 1) * 100 : null;
?>
<div class="neg-kpi"><span>Valor da OAB</span><strong><?= $pp['base'] ? e(brl($pp['base'])) : '—' ?></strong><small><?= $pp['base'] ? ($pp['manual'] ? 'informado para este caso' : count($pp['itens']) . ' serviço(s) da tabela') : 'escolha os serviços no guia' ?></small></div>
<div class="neg-kpi"><span>Referência</span><strong><?= $pp['referencia'] ? e(brl($pp['referencia'])) : '—' ?></strong><small><?= $g['indicacao'] === true ? 'com indicação (+' . e(fmt_pct($pp['pct'])) . ')' : ($g['indicacao'] === false ? 'sem indicação' : 'indicação não informada') ?></small></div>
<div class="neg-kpi"><span>Proposta atual</span><strong><?= $t['total'] ? e(brl($t['total'])) : '—' ?></strong><small><?= $pos !== null ? ($pos >= 0 ? '+' : '') . e(fmt_pct(round($pos, 1))) . ' em relação à referência' : ($d['exito']['ativo'] ? 'só êxito' : 'sem valor') ?></small></div>
<div class="neg-kpi"><span>Cliente paga</span><strong><?= $cur ? e(brl($cur['cliente'])) : '—' ?></strong><small><?= $cur ? e($cur['label']) . ($cur['n'] > 1 ? ' · ' . (int) $cur['n'] . 'x de ' . e(brl($cur['parcela'])) : '') : 'defina o valor' ?></small></div>
<div class="neg-kpi"><span>Você recebe</span><strong><?= $cur ? e(brl($cur['liquido'])) : '—' ?></strong><small><?= $cur ? 'líquido, após ' . e(brl($cur['taxas'])) . ' de taxas' : '' ?></small></div>
<div class="neg-kpi neg-kpi-ia"><span>Sugestão da IA</span><strong><?= $ev ? e(brl((int) $ev['result']['preco_sugerido'])) : '—' ?></strong><small><?= $ev ? e(fmt_datetime($ev['created_at'])) : 'peça uma avaliação no guia' ?></small></div>
