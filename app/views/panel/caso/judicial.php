<?php
$j = case_data($c, 'judicial', []);
$v = fn(string $k) => e($j[$k] ?? '');
$ck9 = case_checks((int) $c['id'], 'preparacao_judicial');
$ck10 = case_checks((int) $c['id'], 'processo');
$ck11 = case_checks((int) $c['id'], 'decisao_recursos');
$ck12 = case_checks((int) $c['id'], 'apuracao');
?>
<div class="grid-main-side">
  <div>
    <?= case_form($c, 'judicial', [], 'box form') ?>
      <h2>Etapa 9 · Preparação e protocolo</h2>
      <div class="row4">
        <label class="fld"><span>Número do processo</span><input name="process_number" value="<?= $v('process_number') ?>"></label>
        <label class="fld"><span>Juízo</span><input name="court" value="<?= $v('court') ?>"></label>
        <label class="fld"><span>Protocolado em</span><input type="date" name="filed_at" value="<?= $v('filed_at') ?>"></label>
        <label class="fld"><span>Valor da causa</span><input name="cause_value" value="<?= $v('cause_value') ?>" placeholder="conforme pedidos, não o total financiado"></label>
      </div>
      <label class="fld"><span>Pedidos (cada um vinculado à prova correspondente)</span><textarea name="claims" rows="3"><?= $v('claims') ?></textarea></label>
      <div class="row2">
        <label class="fld"><span>Medida urgente (tutela)</span><select name="tutela"><?php foreach (['' => '—', 'nao_pedida' => 'Não pedida', 'pedida' => 'Pedida, sem decisão', 'concedida' => 'Concedida', 'parcial' => 'Concedida em parte', 'negada' => 'Negada'] as $tk => $tl): ?><option value="<?= $tk ?>"<?= selected($j['tutela'] ?? '', $tk) ?>><?= $tl ?></option><?php endforeach ?></select></label>
        <label class="fld"><span>Condições, prazos, destinatários e alcance da ordem</span><input name="tutela_note" value="<?= $v('tutela_note') ?>"></label>
      </div>

      <h2>Etapa 10 · Provas e atos</h2>
      <div class="row2">
        <label class="fld"><span>Audiência</span><input type="datetime-local" name="hearing_at" value="<?= e(dt_local($j['hearing_at'] ?? null)) ?>"></label>
        <label class="fld"><span>Formato e instruções</span><input name="hearing_format" value="<?= $v('hearing_format') ?>" placeholder="presencial / remota, link, documentos"></label>
      </div>

      <h2>Etapa 11 · Decisão e recursos</h2>
      <div class="row4">
        <label class="fld"><span>Resultado</span><select name="decision_result"><option value="">—</option><?php foreach (DECISION_RESULTS as $dk => $dr): ?><option value="<?= $dk ?>"<?= selected($j['decision_result'] ?? '', $dk) ?>><?= e($dr['label']) ?></option><?php endforeach ?></select></label>
        <label class="fld"><span>Data da decisão</span><input type="date" name="decision_date" value="<?= $v('decision_date') ?>"></label>
        <label class="fld"><span>Recurso</span><select name="appeal"><?php foreach (['' => '—', 'sim' => 'Recorrer', 'nao' => 'Não recorrer', 'banco' => 'Banco recorreu'] as $ak => $al): ?><option value="<?= $ak ?>"<?= selected($j['appeal'] ?? '', $ak) ?>><?= $al ?></option><?php endforeach ?></select></label>
        <label class="fld"><span>Trânsito em julgado</span><input type="date" name="transit_at" value="<?= $v('transit_at') ?>"></label>
      </div>
      <label class="fld"><span>Pedidos acolhidos, rejeitados e não definidos; efeitos sobre saldo, pagamentos, restituição, posse, custas e honorários</span><textarea name="decision_summary" rows="3"><?= $v('decision_summary') ?></textarea></label>
      <label class="fld"><span>Fundamento, custo, risco e utilidade do recurso (ou da ausência)</span><textarea name="appeal_reason" rows="2"><?= $v('appeal_reason') ?></textarea></label>

      <h2>Etapa 12 · Apuração e cumprimento</h2>
      <div class="row4">
        <label class="fld"><span>Novo saldo</span><input name="new_balance" value="<?= $v('new_balance') ?>"></label>
        <label class="fld"><span>Parcelas restantes</span><input name="remaining_installments" value="<?= $v('remaining_installments') ?>"></label>
        <label class="fld"><span>Valores a devolver</span><input name="refund_value" value="<?= $v('refund_value') ?>"></label>
        <label class="fld"><span>Montante compensado</span><input name="offset_value" value="<?= $v('offset_value') ?>"></label>
      </div>
      <div class="row2">
        <label class="fld"><span>Despesas e honorários</span><input name="fees_expenses" value="<?= $v('fees_expenses') ?>"></label>
        <label class="fld"><span>Cumprimento</span><select name="compliance_status"><?php foreach (['' => '—', 'voluntario' => 'Cumprimento voluntário pedido', 'formal' => 'Cumprimento formal em curso', 'parcial' => 'Cumprido em parte', 'pendente' => 'Cumprimento pendente (obstáculo)', 'cumprido' => 'Cumprido'] as $sk => $sl): ?><option value="<?= $sk ?>"<?= selected($j['compliance_status'] ?? '', $sk) ?>><?= $sl ?></option><?php endforeach ?></select></label>
      </div>
      <label class="fld"><span>Medida realizada, obstáculo, próxima tentativa</span><input name="compliance_note" value="<?= $v('compliance_note') ?>"></label>
      <p class="hint">Uma quantia em depósito não é dinheiro disponível. Não marcar “restituição entregue” só porque o banco juntou comprovante aos autos.</p>
      <div class="actions"><button class="b b-primary" type="submit">Salvar dados judiciais</button></div>
    </form>
  </div>

  <aside>
    <?php if (!empty($j['decision_result']) && isset(DECISION_RESULTS[$j['decision_result']])): $r = DECISION_RESULTS[$j['decision_result']]; ?>
      <section class="box">
        <h2>Decisão <?= e(mb_strtolower($r['label'])) ?></h2>
        <p><strong>Próxima ação:</strong> <?= e($r['next']) ?></p>
        <p><strong>O cliente precisa decidir:</strong> <?= e($r['client']) ?></p>
        <a class="b b-xs" href="<?= e(url('painel/caso/' . $c['id'], ['aba' => 'decisao'])) ?>#decisoes">Registrar decisão do cliente</a>
      </section>
    <?php endif ?>

    <section class="box">
      <h2>Condições por etapa</h2>
      <h3>9 · Preparação</h3>
      <div class="cklist">
        <?php foreach (stage('preparacao_judicial')['gates'] as $gk => $g): ?><?= check_item($c, 'preparacao_judicial', $gk, $g['label'], $ck9[$gk] ?? null, 'judicial', $g['block']) ?><?php endforeach ?>
      </div>
      <h3>10 · Processo</h3>
      <div class="cklist">
        <?php foreach (stage('processo')['steps'] as $sk => $sl): ?><?= check_item($c, 'processo', $sk, $sl, $ck10[$sk] ?? null, 'judicial') ?><?php endforeach ?>
      </div>
      <h3>11 · Decisão</h3>
      <div class="cklist">
        <?php foreach (stage('decisao_recursos')['gates'] as $gk => $g): ?><?= check_item($c, 'decisao_recursos', $gk, $g['label'], $ck11[$gk] ?? null, 'judicial', $g['block']) ?><?php endforeach ?>
      </div>
      <h3>12 · Apuração</h3>
      <div class="cklist">
        <?php foreach (stage('apuracao')['gates'] as $gk => $g): ?><?= check_item($c, 'apuracao', $gk, $g['label'], $ck12[$gk] ?? null, 'judicial', $g['block']) ?><?php endforeach ?>
      </div>
    </section>

    <section class="box">
      <h2>Prazos do processo</h2>
      <?php foreach ($deadlines as $d): ?><p class="file-line <?= $d['status'] === 'aberto' && dt($d['due_at']) < now() ? 'late' : '' ?>"><strong><?= e($d['description']) ?></strong> · <?= e(fmt_datetime($d['due_at'])) ?> · <?= e($d['status']) ?></p><?php endforeach ?>
      <a class="b b-xs" href="<?= e(url('painel/caso/' . $c['id'], ['aba' => 'atendimento'])) ?>#prazos">Gerenciar prazos</a>
      <p class="hint">Manter comunicação quinzenal mesmo sem movimentação: “Ainda aguardamos [evento]. Conferimos o andamento em [data]…”.</p>
    </section>
  </aside>
</div>
