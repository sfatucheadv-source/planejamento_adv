<?php
$a = case_data($c, 'audit', []);
$k = audit_calc($a);
$v = fn(string $key) => e($a[$key] ?? '');
$validated = !empty($a['validated_at']);
$s4 = stage('auditoria');
?>
<div class="grid-main-side">
  <div>
    <?= case_form($c, 'auditoria', [], 'box form', false) ?>
      <div class="box-head">
        <h2>Etapa 4 · Auditoria financeira e jurídica</h2>
        <?php if ($validated): ?><span class="tag tag-green">validada por <?= e(first_name(user_name((int) $a['validated_by']))) ?> em <?= e(fmt_date($a['validated_at'])) ?></span><?php else: ?><span class="tag tag-amber">aguardando validação do advogado</span><?php endif ?>
      </div>

      <h3>1. Operação original e alterações</h3>
      <div class="row4" data-calc>
        <label class="fld"><span>Data do contrato</span><input type="date" name="contract_date" value="<?= $v('contract_date') ?>"></label>
        <label class="fld"><span>Valor financiado (principal)</span><input name="principal" value="<?= $v('principal') ?>" data-k="principal" inputmode="decimal"></label>
        <label class="fld"><span>Entrada</span><input name="down_payment" value="<?= $v('down_payment') ?>" data-k="down" inputmode="decimal"></label>
        <label class="fld"><span>Nº de parcelas</span><input name="installments" value="<?= $v('installments') ?>" data-k="n" inputmode="numeric"></label>
        <label class="fld"><span>Valor da parcela</span><input name="installment_value" value="<?= $v('installment_value') ?>" data-k="pmt" inputmode="decimal"></label>
        <label class="fld"><span>Taxa mensal contratada (%)</span><input name="monthly_rate" value="<?= $v('monthly_rate') ?>" data-k="im" inputmode="decimal"></label>
        <label class="fld"><span>Taxa anual contratada (%)</span><input name="annual_rate" value="<?= $v('annual_rate') ?>" inputmode="decimal"></label>
        <label class="fld"><span>CET anual (%)</span><input name="cet_annual" value="<?= $v('cet_annual') ?>" inputmode="decimal"></label>
        <label class="fld"><span>Parcelas já pagas</span><input name="paid_installments" value="<?= $v('paid_installments') ?>" data-k="paid" inputmode="numeric"></label>
        <label class="fld"><span>Data de referência dos valores</span><input type="date" name="reference_date" value="<?= $v('reference_date') ?: e(now()->format('Y-m-d')) ?>"></label>
      </div>

      <h3>2. Cobranças acessórias (conferir uma a uma)</h3>
      <div class="row4" data-calc>
        <label class="fld"><span>TAC / cadastro</span><input name="tac" value="<?= $v('tac') ?>" data-k="c1" inputmode="decimal"></label>
        <label class="fld"><span>Tarifa de avaliação</span><input name="evaluation_fee" value="<?= $v('evaluation_fee') ?>" data-k="c2" inputmode="decimal"></label>
        <label class="fld"><span>Registro do contrato</span><input name="registration_fee" value="<?= $v('registration_fee') ?>" data-k="c3" inputmode="decimal"></label>
        <label class="fld"><span>Seguro</span><input name="insurance" value="<?= $v('insurance') ?>" data-k="c4" inputmode="decimal"></label>
        <label class="fld"><span>Outros serviços</span><input name="other_charges" value="<?= $v('other_charges') ?>" data-k="c5" inputmode="decimal"></label>
      </div>
      <label class="fld"><span>Cobranças questionadas e fundamento (serviço prestado? onerosidade? escolha do seguro?)</span><textarea name="questioned" rows="3"><?= $v('questioned') ?></textarea></label>

      <h3>3. Série oficial do Banco Central</h3>
      <p class="hint">Aquisição de veículos por pessoa física, recursos livres: <a href="https://www3.bcb.gov.br/sgspub/consultarvalores/telaCvsSelecionarSeries.paint" target="_blank" rel="noopener">série 25471 no SGS do Banco Central</a>. Guarde fonte, competência, unidade e data da consulta.</p>
      <div class="row4" data-calc>
        <label class="fld"><span>Taxa média mensal (%)</span><input name="bcb_rate" value="<?= $v('bcb_rate') ?>" data-k="bcb" inputmode="decimal"></label>
        <label class="fld"><span>Competência</span><input name="bcb_reference" value="<?= $v('bcb_reference') ?>" placeholder="mm/aaaa da contratação"></label>
        <label class="fld"><span>Consultado em</span><input type="date" name="bcb_consulted_at" value="<?= $v('bcb_consulted_at') ?>"></label>
        <label class="fld"><span>Fonte</span><input name="bcb_source" value="<?= $v('bcb_source') ?: 'BCB SGS 25471' ?>"></label>
      </div>

      <h3>4. Três cenários na mesma data de referência</h3>
      <label class="fld"><span>Cenário contratado (saldo, parcelas restantes, atrasos e custo)</span><textarea name="scenario_contract" rows="2"><?= $v('scenario_contract') ?></textarea></label>
      <label class="fld"><span>Cenário de negociação (condições sustentáveis e total a pagar)</span><textarea name="scenario_negotiation" rows="2"><?= $v('scenario_negotiation') ?></textarea></label>
      <label class="fld"><span>Cenário revisional (alterações fundamentadas; saldo, parcelas e pagos indevidamente, como estimativa)</span><textarea name="scenario_revision" rows="2"><?= $v('scenario_revision') ?></textarea></label>

      <h3>5. Memória e conclusão</h3>
      <label class="fld"><span>Memória de cálculo: entradas, fontes, fórmulas, hipóteses (evitar dupla contagem entre restituição e abatimento)</span><textarea name="notes" rows="4"><?= $v('notes') ?></textarea></label>
      <label class="fld"><span>Síntese de uma página para o cliente, em linguagem simples</span><textarea name="summary_client" rows="4"><?= $v('summary_client') ?></textarea></label>
      <label class="fld"><span>Conclusão do parecer</span><select name="conclusion"><option value="">—</option><?php foreach (AUDIT_CONCLUSIONS as $ck => $cl): ?><option value="<?= $ck ?>"<?= selected($a['conclusion'] ?? '', $ck) ?>><?= e($cl) ?></option><?php endforeach ?></select></label>
      <div class="actions">
        <button class="b" type="submit">Salvar rascunho</button>
        <?php if (!$validated): ?>
          <button class="b b-primary" type="submit" name="validate" value="1" data-confirm="Validar cálculo e parecer? Só o advogado responsável deve validar.">Validar como advogado</button>
        <?php else: ?>
          <button class="b" type="submit" name="unvalidate" value="1">Reabrir validação</button>
        <?php endif ?>
      </div>
    </form>
  </div>

  <aside>
    <section class="box calc" data-calc-out>
      <h2>Calculadora de apoio</h2>
      <p class="hint">Estimativas para investigar, nunca conclusão automática. Atualiza enquanto você digita.</p>
      <dl class="calc-dl">
        <div><dt>Taxa anual equivalente à mensal contratada<br><small>(1 + i)<sup>12</sup> − 1</small></dt><dd data-o="annual"><?= e(pct($k['annual_from_monthly'])) ?></dd></div>
        <div><dt>Taxa mensal que reproduz a parcela (Price)</dt><dd data-o="implied"><?= e(pct($k['implied_rate'], 3)) ?></dd></div>
        <div><dt>Parcela pela taxa contratada</dt><dd data-o="pmtc"><?= e($k['pmt_at_contract'] !== null ? money($k['pmt_at_contract']) : '—') ?></dd></div>
        <div><dt>O cálculo reproduz a operação?</dt><dd data-o="repro"><?= $k['reproduces'] === null ? '—' : ($k['reproduces'] ? 'Sim' : 'Não: conferir dados antes de propor alterações') ?></dd></div>
        <div><dt>Taxa média BCB anualizada</dt><dd data-o="bcba"><?= e(pct($k['bcb_annual'])) ?></dd></div>
        <div class="hl"><dt>Razão taxa contratada ÷ média</dt><dd data-o="ratio"><?= $k['ratio'] !== null ? number_format($k['ratio'], 2, ',', '') . '×' : '—' ?></dd></div>
        <div><dt>Parcela hipotética pela taxa média</dt><dd data-o="pmtb"><?= e($k['pmt_at_bcb'] !== null ? money($k['pmt_at_bcb']) : '—') ?></dd></div>
        <div><dt>Diferença por parcela</dt><dd data-o="diff"><?= e($k['diff_per_installment'] !== null ? money($k['diff_per_installment']) : '—') ?></dd></div>
        <div><dt>Diferença nas parcelas já pagas</dt><dd data-o="diffpaid"><?= e($k['diff_paid'] !== null ? money($k['diff_paid']) : '—') ?></dd></div>
        <div><dt>Saldo estimado (taxa contratada)</dt><dd data-o="balc"><?= e($k['balance_contract'] !== null ? money($k['balance_contract']) : '—') ?></dd></div>
        <div><dt>Saldo estimado (taxa média)</dt><dd data-o="balb"><?= e($k['balance_bcb'] !== null ? money($k['balance_bcb']) : '—') ?></dd></div>
        <div><dt>Cobranças acessórias</dt><dd data-o="charges"><?= e($k['charges'] !== null ? money($k['charges']) . ' (' . pct($k['charges_share'], 1) . ' do principal)' : '—') ?></dd></div>
        <div><dt>Total contratado (entrada + parcelas)</dt><dd data-o="total"><?= e($k['total_contract'] !== null ? money($k['total_contract']) : '—') ?></dd></div>
      </dl>
      <p class="hint"><strong>Exemplo didático:</strong> 3% ao mês ÷ média de 2% = 1,5. O número indica o que investigar; não conclui sozinho pela ilegalidade, pela redução da prestação ou pelo direito à restituição. 1,5 vez a média não é critério automático. CET não se compara com a série de juros remuneratórios.</p>
      <p class="hint">Restituição simples como cenário; em dobro apenas com fundamento específico (conferir o alcance atual do Tema 929). Tabela Price e juros compostos não são, por si, prova de ilegalidade.</p>
    </section>

    <section class="box">
      <h2>Sequência de análise</h2>
      <ol class="small-ol"><?php foreach ($s4['steps'] as $t): ?><li><?= e($t) ?></li><?php endforeach ?></ol>
    </section>
  </aside>
</div>
