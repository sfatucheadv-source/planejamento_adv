<?php
/**
 * Simulador de vendas: sugestões de recebimento no topo e, abaixo, as linhas
 * do modo escolhido (PIX à vista, parcelado ou recorrente; boleto; cartão).
 * PIX direto na chave não tem taxa; no cartão a taxa é do cliente (parcela mínima).
 */
$rows = sales_sim((int) $opt['valor'], $opt, $d);
$curKey = sales_sim_current_key($d);
if (!$rows): ?>
  <p class="hint">Escolha a precificação (padrão ou IA) ou informe um valor para simular.</p>
<?php return; endif;
$by = array_column($rows, null, 'key');
$f = asaas_fees();
$min = pricing()['cartao_min_parcela'];
$cartoes = array_values(array_filter($rows, fn($r) => $r['grupo'] === 'cartao'));
$maxCartao = end($cartoes) ?: null;
$sug = array_filter([
    $by['pix'] ?? null,
    $by['pixparc_6'] ?? null,
    $maxCartao && $maxCartao['n'] > 1 ? $maxCartao : null,
]);
$sel = array_values(array_filter($rows, fn($r) => match ($opt['meio']) {
    'cartao' => $r['grupo'] === 'cartao',
    'boleto' => $r['grupo'] === 'boleto',
    default => $r['grupo'] === 'pix' && $r['sub'] === $opt['pix'],
}));
$usar = fn(array $r) => ' data-usar-tipo="' . ($r['n'] === 1 && $r['grupo'] !== 'cartao' ? 'avista' : ($r['grupo'] === 'cartao' ? 'cartao' : 'proprio')) . '" data-usar-parcelas="' . (int) $r['n'] . '"'
    . ' data-usar-meio="' . e($r['sub'] === 'recorrente' ? 'pix_recorrente' : ($r['grupo'] === 'boleto' ? 'boleto' : 'pix')) . '" data-usar-forma="' . e($r['grupo'] === 'boleto' ? 'boleto' : ($r['grupo'] === 'cartao' ? 'credito' : 'pix')) . '"';
?>
<p class="neg-sim-sub">Simulando <strong><?= e(brl((int) $opt['valor'])) ?></strong> (<?= e($opt['base_rotulo']) ?>)</p>
<div class="sim-sug" aria-label="Sugestões de recebimento">
  <?php foreach ($sug as $r): ?>
    <div class="sim-sug-card <?= $r['key'] === $curKey ? 'is-cur' : '' ?>">
      <span><?= e($r['grupo'] === 'cartao' ? 'Cartão (taxa do cliente)' : ($r['n'] > 1 ? 'Parcelado no escritório' : 'PIX à vista')) ?></span>
      <strong><?= $r['n'] > 1 ? (int) $r['n'] . 'x de ' . e(brl($r['parcela'])) : e(brl($r['cliente'])) ?></strong>
      <small>você recebe <?= e(brl($r['liquido'])) ?> · <?= e($r['recebe']) ?></small>
      <button type="button" class="b b-sm"<?= $usar($r) ?>>Usar</button>
    </div>
  <?php endforeach ?>
</div>

<div class="sim-modos" role="group" aria-label="Modo do simulador">
  <div class="sim-modo"><span class="lbl">Valor</span>
    <?php foreach (['padrao' => 'Padrão (' . fmt_pct(pricing()['pct_veiculo']) . ' do veículo)', 'ia' => 'Proposta da IA', 'proposta' => 'Valor da proposta'] as $k => $l): ?>
      <label class="sim-chip"><input type="radio" name="sim_base" value="<?= $k ?>" <?= $opt['base'] === $k ? 'checked' : '' ?>> <?= e($l) ?></label>
    <?php endforeach ?>
  </div>
  <div class="sim-modo"><span class="lbl">Meio</span>
    <?php foreach (['pix' => 'PIX', 'boleto' => 'Boleto', 'cartao' => 'Cartão'] as $k => $l): ?>
      <label class="sim-chip"><input type="radio" name="sim_meio" value="<?= $k ?>" <?= $opt['meio'] === $k ? 'checked' : '' ?>> <?= e($l) ?></label>
    <?php endforeach ?>
  </div>
  <?php if ($opt['meio'] === 'pix'): ?>
    <div class="sim-modo"><span class="lbl">PIX</span>
      <?php foreach (['avista' => 'À vista', 'parcelado' => 'Parcelado no escritório', 'recorrente' => 'Recorrente no Asaas'] as $k => $l): ?>
        <label class="sim-chip"><input type="radio" name="sim_pix" value="<?= $k ?>" <?= $opt['pix'] === $k ? 'checked' : '' ?>> <?= e($l) ?></label>
      <?php endforeach ?>
    </div>
  <?php else: ?><input type="hidden" name="sim_pix" value="<?= e($opt['pix']) ?>"><?php endif ?>
</div>

<div class="tbl-wrap">
  <table class="tbl tbl-compact neg-sim">
    <thead><tr><th>Forma</th><th>Cliente paga</th><th>Parcela</th><th>Você recebe</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($sel as $r): ?>
        <tr class="<?= $r['key'] === $curKey ? 'is-cur' : '' ?>">
          <td><?= e($r['label']) ?><?= $r['key'] === $curKey ? ' <span class="neg-tag">na proposta</span>' : '' ?><br><small class="muted"><?= e($r['recebe']) ?></small></td>
          <td><strong><?= e(brl($r['cliente'])) ?></strong></td>
          <td><?= $r['n'] > 1 ? (int) $r['n'] . 'x ' . e(brl($r['parcela'])) : '—' ?></td>
          <td><strong><?= e(brl($r['liquido'])) ?></strong><?= $r['taxas'] ? '<br><small class="muted">taxas ' . e(brl($r['taxas'])) . '</small>' : '<br><small class="muted">sem taxa</small>' ?><?= $r['antecipado'] !== null && $r['n'] > 1 ? '<br><small class="muted">antecipando: ' . e(brl($r['antecipado'])) . '</small>' : '' ?></td>
          <td><button type="button" class="b b-sm"<?= $usar($r) ?> title="Usar esta condição na proposta">Usar</button></td>
        </tr>
      <?php endforeach ?>
    </tbody>
  </table>
</div>
<p class="hint">
  <?php if ($opt['meio'] === 'cartao'): ?>Taxa do Asaas repassada ao cliente (<?= e(fmt_pct($f['cartao_1'])) ?> à vista a <?= e(fmt_pct($f['cartao_7_12'])) ?> em 12x, mais <?= e(brl((int) round($f['cartao_fixo'] * 100))) ?>); parcela mínima de <?= e(brl($min)) ?>.
  <?php elseif ($opt['meio'] === 'boleto'): ?>Boleto: <?= e(brl((int) round($f['boleto_fixo'] * 100))) ?> por boleto pago. Parcelado: parcelas fixas com juros de 1% a.m. (Calculadora do Cidadão).
  <?php else: ?>PIX direto na chave do escritório: sem taxa do Asaas. Parcelado: parcelas fixas com juros de 1% a.m. (Calculadora do Cidadão). Recorrente: cobrança automática do Asaas, <?= e(brl((int) round($f['pix_fixo'] * 100))) ?> por pagamento, se disponível na sua conta.<?php endif ?>
  <a href="<?= e(url('painel/configuracoes/precos')) ?>">Ajustar taxas</a>
</p>
