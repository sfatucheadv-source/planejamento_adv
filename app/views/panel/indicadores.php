<?php
$p = fn(?float $v) => $v === null ? '—' : number_format($v * 100, 0, ',', '.') . '%';
$h = fn(?float $v, string $u) => $v === null ? '—' : number_format($v, 1, ',', '.') . ' ' . $u;
?>
<div class="page-head">
  <div><h1>Indicadores</h1><p class="sub">Medem se a operação reduz o esforço do cliente e entrega efeitos reais. Contratos fechados, casos concluídos e objetivos alcançados são números separados.</p></div>
</div>
<div class="kpis">
  <div class="kpi"><strong><?= $m['total'] ?></strong><span>casos registrados</span></div>
  <div class="kpi"><strong><?= $m['active'] ?></strong><span>ativos</span></div>
  <div class="kpi"><strong><?= $m['closed'] ?></strong><span>encerrados</span></div>
  <div class="kpi <?= $m['lost_deadlines'] ? 'kpi-alert' : '' ?>"><strong><?= $m['lost_deadlines'] ?></strong><span>prazos jurídicos perdidos</span></div>
</div>
<section class="box">
  <div class="tbl-wrap"><table class="tbl">
    <thead><tr><th>Indicador</th><th>Valor atual</th><th>Forma de medir</th><th>Uso na gestão</th></tr></thead>
    <tbody>
      <tr><td>Tempo até resposta humana</td><td><strong><?= $h($m['first_contact_median_h'], 'h úteis') ?></strong> mediana · <?= $p($m['first_contact_within']) ?> dentro de 1 h útil</td><td>Intervalo entre contato e primeiro atendimento</td><td>Corrigir fila e cobertura</td></tr>
      <tr><td>Tempo até plano recomendado</td><td><strong><?= $h($m['plan_median_days'], 'dias úteis') ?></strong> mediana</td><td>Documentos suficientes até parecer validado</td><td>Localizar demora em cálculos e revisão</td></tr>
      <tr><td>Repetição de pedidos</td><td><strong><?= $m['repeat_requests'] ?></strong> documentos com pedido de reenvio</td><td>Solicitações repetidas por caso</td><td>Simplificar coleta e transferência</td></tr>
      <tr><td>Esforço do cliente</td><td><strong><?= $m['ease_avg'] === null ? '—' : number_format($m['ease_avg'], 1, ',', '') . '/5' ?></strong> (<?= $m['ease_n'] ?> respostas)</td><td>Nota de 1 a 5 sobre facilidade</td><td>Corrigir barreiras de canal, assinatura e documentos</td></tr>
      <tr><td>Atualizações no prazo</td><td><strong><?= $p($m['updates_ok']) ?></strong> dos casos ativos com notícia nos últimos <?= (int) config('update_cycle_days') ?> dias</td><td>Retornos realizados ÷ previstos</td><td>Evitar silêncio durante espera</td></tr>
      <tr><td>Cumprimento efetivo</td><td><strong><?= $p($m['delivery']) ?></strong> de <?= $m['solution_items'] ?> entregas exigíveis</td><td>Entregas comprovadas ÷ exigíveis</td><td>Identificar acordo ou decisão sem efeito material</td></tr>
      <tr><td>Objetivo alcançado</td><td><strong><?= $p($m['objective_rate']) ?></strong> dos encerrados</td><td>Casos resolvidos ÷ encerrados, por categoria</td><td>Avaliar qualidade da solução</td></tr>
      <tr><td>Reabertura por falha de entrega</td><td><strong><?= $p($m['reopen_rate']) ?></strong></td><td>Reabertos ÷ encerrados</td><td>Corrigir conferência e cumprimento</td></tr>
      <tr><td>Prazos jurídicos perdidos</td><td><strong><?= $m['lost_deadlines'] ?></strong></td><td>Ocorrências e causa</td><td>Investigar imediatamente cada ocorrência</td></tr>
    </tbody>
  </table></div>
</section>
<div class="grid-2col">
  <section class="box">
    <h2>Encerrados por desfecho</h2>
    <?php if (!$m['by_outcome']): ?><p class="empty">Nenhum caso encerrado.</p><?php endif ?>
    <ul class="stage-bars"><?php $max = max(1, ...array_values($m['by_outcome'] ?: [1])); foreach ($m['by_outcome'] as $k => $n): ?><li><span class="sb-label"><?= e(OUTCOMES[$k]['label'] ?? $k) ?></span><span class="sb-bar"><span style="width: <?= round($n / $max * 100) ?>%"></span></span><span class="sb-n"><?= $n ?></span></li><?php endforeach ?></ul>
  </section>
  <section class="box">
    <h2>Origem dos casos</h2>
    <ul class="stage-bars"><?php $max = max(1, ...array_values($m['by_source'] ?: [1])); foreach ($m['by_source'] as $k => $n): ?><li><span class="sb-label"><?= e($k) ?></span><span class="sb-bar"><span style="width: <?= round($n / $max * 100) ?>%"></span></span><span class="sb-n"><?= $n ?></span></li><?php endforeach ?></ul>
  </section>
</div>
<section class="box">
  <h2>Implantação e rotina de supervisão</h2>
  <p class="hint">Medir tempos e notas nas primeiras quatro semanas antes de fixar metas de produtividade definitivas. Diariamente: urgências, prazos e entregas vencidas. Semanalmente: casos parados, negociações sem resposta e obrigações de banco pendentes. Mensalmente: esforço do cliente, resultados efetivos e reaberturas.</p>
</section>
