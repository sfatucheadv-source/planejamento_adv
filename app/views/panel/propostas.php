<?php
/** Propostas registradas e escolha do atendimento para uma nova. */
?>
<div class="page-head">
  <div>
    <h1>Propostas</h1>
    <p class="sub">As condições negociadas com cada cliente. A tela mostra a simulação do pagamento e as cláusulas como sairão no contrato; depois da assinatura, as cobranças vão para o Asaas com os mesmos valores.</p>
  </div>
</div>

<section class="box">
  <div class="box-head"><h2>Nova proposta</h2></div>
  <?php if ($cases): ?>
    <form class="inline-form" method="post" action="<?= e(url('painel/propostas')) ?>">
      <?= csrf_field() ?>
      <label class="fld"><span>Atendimento</span>
        <select name="case_id" required>
          <option value="">Escolha o atendimento</option>
          <?php foreach ($cases as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e($c['protocol']) ?> · <?= e($c['client_name']) ?></option><?php endforeach ?>
        </select>
      </label>
      <button class="b b-primary" type="submit">Cadastrar proposta</button>
    </form>
  <?php else: ?>
    <p class="hint">Todos os atendimentos em andamento já têm proposta. Para mudar uma, abra-a na lista abaixo.</p>
  <?php endif ?>
</section>

<section class="box">
  <div class="box-head"><h2>Propostas registradas</h2><span class="hint"><?= count($rows) ?></span></div>
  <?php if (!$rows): ?>
    <p class="hint">Nenhuma proposta registrada ainda.</p>
  <?php else: ?>
    <div class="tbl-wrap">
      <table class="tbl tbl-compact prop-lista">
        <thead><tr><th>Atendimento</th><th>Honorários</th><th>Êxito</th><th>Pagamento</th><th>Asaas</th><th>Atualizada</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): $d = array_replace_recursive(proposal_defaults(), json_decode((string) $r['data'], true) ?: []); $t = proposal_totals($d); $p = $d['pagamento']; ?>
          <tr>
            <td><a href="<?= e(url('painel/propostas/' . $r['case_id'])) ?>"><strong><?= e($r['client_name']) ?></strong></a><br><small class="muted"><?= e($r['protocol']) ?> · <?= e(INITIAL_STATUS[$r['initial_status']]['label'] ?? '') ?></small></td>
            <td><?= $t['total'] ? e(brl($t['total'])) : '—' ?></td>
            <td><?= $d['exito']['ativo'] ? e(percent_extenso($d['exito']['percentual'])) : '—' ?></td>
            <td><?= $p['condicao'] === 'avista' ? 'À vista' : e(($t['entrada'] ? 'Entrada + ' : '') . ($p['modalidade'] === 'cartao' ? 'até ' . $p['cartao_parcelas'] . 'x no cartão' : $p['parcelas'] . 'x próprio')) ?></td>
            <td><?php $as = asaas_summary((int) $r['case_id']); ?><?= $as ? e(brl($as['pago'])) . ' de ' . e(brl($as['total'])) . ($as['vencidas'] ? ' <small class="t-warn">' . (int) $as['vencidas'] . ' vencida(s)</small>' : '') : '—' ?></td>
            <td><small><?= e(fmt_datetime($r['updated_at'])) ?></small></td>
          </tr>
        <?php endforeach ?>
        </tbody>
      </table>
    </div>
  <?php endif ?>
</section>
