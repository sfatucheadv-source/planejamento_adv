<?php /** Resultado do simulador de vendas do próprio Asaas. */ ?>
<?php if (!$r['ok']): ?>
  <p class="flash flash-error"><?= e((string) $r['error']) ?></p>
<?php elseif (!$r['rows']): ?>
  <p class="hint">O Asaas não devolveu valores para <?= e(brl($valor)) ?> em <?= (int) $n ?>x.</p>
<?php else: ?>
  <p class="neg-sim-sub">Simulador do Asaas (<?= e(ASAAS_ENVS[asaas_env()]['label']) ?>) para <strong><?= e(brl($valor)) ?></strong> em <?= (int) $n ?>x:</p>
  <div class="neg-api">
    <?php foreach ($r['rows'] as $b): ?>
      <dl><dt><?= e($b['tipo']) ?></dt>
        <?php foreach ($b['itens'] as [$label, $v, $pct]): ?><dd><span><?= e($label) ?></span><strong><?= $pct ? e(fmt_pct($v)) : (str_contains($label, 'parcelas') ? (int) $v : e(brl((int) round($v * 100)))) ?></strong></dd><?php endforeach ?>
      </dl>
    <?php endforeach ?>
  </div>
  <p class="hint">Se os valores diferirem da tabela acima, ajuste as taxas em Configurações › Preços e negociação.</p>
<?php endif ?>
