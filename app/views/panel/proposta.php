<?php
/** Cadastro da proposta de um atendimento. */
$id = (int) $c['id'];
$p = $d['pagamento'];
$atividades = array_merge($d['atividades'], array_fill(0, max(2, 4 - count($d['atividades'])), ['descricao' => '', 'valor' => null]));
$custos = array_merge($d['custos'], array_fill(0, max(2, 4 - count($d['custos'])), ['tipo' => '', 'descricao' => '', 'valor' => null]));
$t = proposal_totals($d);
$sched = proposal_schedule($d);
$ckd = fn(bool $b) => $b ? 'checked' : '';
?>
<div class="page-head">
  <div>
    <h1>Proposta · <?= e($c['client_name']) ?></h1>
    <p class="sub">Atendimento <?= e($c['protocol']) ?> · <a href="<?= e(url('painel/atendimento/' . $id)) ?>#kit">abrir a ficha e o kit</a><?= $pr ? ' · atualizada em ' . e(fmt_datetime($pr['updated_at'])) : ' · ainda não salva' ?></p>
  </div>
  <a class="b" href="<?= e(url('painel/propostas')) ?>">Todas as propostas</a>
</div>

<?php if ($errors): ?>
  <div class="flash flash-error" role="alert">Nada foi salvo. Confira: <?= e(implode(' ', $errors)) ?></div>
<?php endif ?>

<form class="form stack prop-form" method="post" action="<?= e(url('painel/propostas/' . $id)) ?>">
  <?= csrf_field() ?>

  <section class="box">
    <div class="box-head"><h2>Honorários</h2><span class="hint">Valores em reais</span></div>

    <h3><label class="chk"><input type="checkbox" name="fixos_ativo" value="1" <?= $ckd($d['fixos']['ativo']) ?>> Honorários fixos</label></h3>
    <p class="hint">Valor fechado, sem variável: não depende do resultado.</p>
    <div class="var-grid prop-if-fixos">
      <label class="fld"><span>Valor</span><input name="fixos_valor" inputmode="decimal" placeholder="R$ 0,00" value="<?= e(cents_input($d['fixos']['valor'])) ?>"></label>
      <label class="fld"><span>O que remunera</span><input name="fixos_descricao" maxlength="500" placeholder="ex.: análise, notificação e ação revisional em primeiro grau" value="<?= e($d['fixos']['descricao']) ?>"></label>
    </div>

    <h3>Honorários contratuais por atividade</h3>
    <p class="hint">Um valor em reais para cada atividade. Linhas em branco são ignoradas.</p>
    <div class="prop-rows">
      <?php foreach ($atividades as $i => $a): ?>
        <div class="prop-row">
          <label class="fld"><span>Atividade <?= $i + 1 ?></span><input name="atividade_descricao[]" maxlength="300" placeholder="ex.: audiência de conciliação" value="<?= e($a['descricao']) ?>"></label>
          <label class="fld prop-valor"><span>Valor</span><input name="atividade_valor[]" inputmode="decimal" placeholder="R$ 0,00" value="<?= e(cents_input($a['valor'])) ?>"></label>
        </div>
      <?php endforeach ?>
    </div>

    <h3><label class="chk"><input type="checkbox" name="exito_ativo" value="1" <?= $ckd($d['exito']['ativo']) ?>> Honorários de êxito</label></h3>
    <div class="var-grid prop-if-exito">
      <label class="fld"><span>Percentual (%)</span><input name="exito_percentual" inputmode="decimal" placeholder="ex.: 20" value="<?= e($d['exito']['percentual']) ?>"></label>
      <label class="fld var-wide"><span>Base de cálculo</span><textarea name="exito_base" rows="2" maxlength="1000" placeholder="ex.: o benefício econômico obtido: restituições, compensações e redução definitiva do saldo devedor"><?= e($d['exito']['base']) ?></textarea></label>
    </div>
  </section>

  <section class="box">
    <div class="box-head"><h2>Valores cobrados à parte</h2><span class="hint">Não são honorários</span></div>
    <p class="hint">Taxas de serviço, emolumentos de cartório, custas judiciais, impostos e outros custos. Deixe o valor em branco quando ainda não for conhecido (“a apurar”).</p>
    <div class="prop-rows">
      <?php foreach ($custos as $i => $x): ?>
        <div class="prop-row prop-row3">
          <label class="fld"><span>Tipo</span>
            <select name="custo_tipo[]"><option value="">—</option><?php foreach (COST_TYPES as $k => $l): ?><option value="<?= e($k) ?>" <?= selected($k, $x['tipo']) ?>><?= e($l) ?></option><?php endforeach ?></select>
          </label>
          <label class="fld"><span>Descrição</span><input name="custo_descricao[]" maxlength="300" placeholder="ex.: certidão de ônus do veículo" value="<?= e($x['descricao']) ?>"></label>
          <label class="fld prop-valor"><span>Estimativa</span><input name="custo_valor[]" inputmode="decimal" placeholder="a apurar" value="<?= e(cents_input($x['valor'])) ?>"></label>
        </div>
      <?php endforeach ?>
    </div>

    <h3>Repasse ao cliente</h3>
    <div class="var-grid">
      <fieldset class="radios"><legend class="lbl">Custos de deslocamento</legend>
        <label class="chk"><input type="radio" name="deslocamento" value="nao" <?= $ckd(!$d['deslocamento']['repassa']) ?>> Não repassar (incluídos nos honorários)</label>
        <label class="chk"><input type="radio" name="deslocamento" value="sim" <?= $ckd($d['deslocamento']['repassa']) ?>> Repassar ao cliente</label>
        <div class="prop-if-desloc">
          <label class="chk"><input type="radio" name="deslocamento_criterio" value="comprovacao" <?= $ckd($d['deslocamento']['criterio'] !== 'km') ?>> Mediante comprovantes</label>
          <label class="chk"><input type="radio" name="deslocamento_criterio" value="km" <?= $ckd($d['deslocamento']['criterio'] === 'km') ?>> Por quilômetro rodado</label>
          <label class="fld"><span>Valor por km</span><input name="deslocamento_km" inputmode="decimal" placeholder="R$ 0,00" value="<?= e(cents_input($d['deslocamento']['valor_km'])) ?>"></label>
        </div>
      </fieldset>
      <fieldset class="radios"><legend class="lbl">Despesas antecipadas pelo escritório</legend>
        <label class="chk"><input type="radio" name="despesas" value="sim" <?= $ckd($d['despesas']['repassa']) ?>> Reembolsar mediante comprovação</label>
        <label class="chk"><input type="radio" name="despesas" value="nao" <?= $ckd(!$d['despesas']['repassa']) ?>> Não repassar</label>
      </fieldset>
    </div>
  </section>

  <section class="box">
    <div class="box-head"><h2>Pagamento</h2></div>
    <fieldset class="radios prop-formas"><legend class="lbl">Formas aceitas</legend>
      <?php foreach (PAYMENT_METHODS as $k => $l): ?>
        <label class="chk"><input type="checkbox" name="formas[]" value="<?= e($k) ?>" <?= $ckd(in_array($k, $p['formas'], true)) ?>> <?= e(mb_strtoupper(mb_substr($l, 0, 1)) . mb_substr($l, 1)) ?></label>
      <?php endforeach ?>
    </fieldset>

    <fieldset class="radios"><legend class="lbl">Condição</legend>
      <label class="chk"><input type="radio" name="condicao" value="avista" <?= $ckd($p['condicao'] === 'avista') ?>> À vista</label>
      <label class="chk"><input type="radio" name="condicao" value="parcelado" <?= $ckd($p['condicao'] === 'parcelado') ?>> Parcelado</label>
    </fieldset>
    <div class="var-grid prop-if-avista">
      <label class="fld"><span>Vencimento</span><input type="date" name="vencimento" value="<?= e((string) $p['vencimento']) ?>"></label>
    </div>
    <div class="prop-if-parcelado stack">
      <div class="var-grid">
        <label class="fld"><span>Entrada (opcional)</span><input name="entrada" inputmode="decimal" placeholder="R$ 0,00" value="<?= e(cents_input($p['entrada'])) ?>"></label>
        <label class="fld"><span>Vencimento da entrada</span><input type="date" name="entrada_vencimento" value="<?= e((string) $p['entrada_vencimento']) ?>"></label>
      </div>
      <fieldset class="radios"><legend class="lbl">Parcelamento</legend>
        <label class="chk"><input type="radio" name="modalidade" value="proprio" <?= $ckd($p['modalidade'] === 'proprio') ?>> Próprio do escritório: juros simples de 1% ao mês e atualização monetária pelo INPC</label>
        <label class="chk"><input type="radio" name="modalidade" value="cartao" <?= $ckd($p['modalidade'] === 'cartao') ?>> No cartão de crédito: a taxa de parcelamento da operadora fica com o cliente</label>
      </fieldset>
      <div class="var-grid prop-if-proprio">
        <label class="fld"><span>Número de parcelas</span><input type="number" name="parcelas" min="1" max="60" value="<?= (int) $p['parcelas'] ?>"></label>
        <label class="fld"><span>Vencimento da 1ª parcela</span><input type="date" name="primeiro_vencimento" value="<?= e((string) $p['primeiro_vencimento']) ?>"></label>
      </div>
      <div class="var-grid prop-if-cartao">
        <label class="fld"><span>Em até quantas parcelas no cartão</span><input type="number" name="cartao_parcelas" min="1" max="24" value="<?= (int) $p['cartao_parcelas'] ?>"></label>
      </div>
    </div>
    <div class="var-grid">
      <label class="fld var-wide"><span>Observações de pagamento (opcional, entra no contrato)</span><textarea name="pagamento_observacoes" rows="2" maxlength="1000"><?= e($p['observacoes']) ?></textarea></label>
      <label class="fld"><span>Validade da proposta</span><input type="date" name="validade" value="<?= e((string) $d['validade']) ?>"></label>
    </div>
  </section>

  <div class="inline-form"><button class="b b-primary" type="submit">Salvar proposta</button></div>
</form>

<?php if ($pr && !$errors): $fx = proposal_effects($d); ?>
<section class="box" id="resumo">
  <div class="box-head"><h2>Resumo salvo</h2></div>
  <div class="var-grid">
    <div>
      <dl class="at-dl">
        <?php if ($t['fixos']): ?><div><dt>Honorários fixos</dt><dd><?= e(brl($t['fixos'])) ?></dd></div><?php endif ?>
        <?php if ($t['atividades']): ?><div><dt>Por atividade</dt><dd><?= e(brl($t['atividades'])) ?></dd></div><?php endif ?>
        <div><dt>Total dos honorários</dt><dd><strong><?= e(brl($t['total'])) ?></strong><?= $d['exito']['ativo'] ? ' + êxito de ' . e(percent_extenso($d['exito']['percentual'])) : '' ?></dd></div>
        <?php if ($p['condicao'] === 'parcelado'): ?>
          <?php if ($t['entrada']): ?><div><dt>Entrada</dt><dd><?= e(brl($t['entrada'])) ?></dd></div><?php endif ?>
          <div><dt>Saldo parcelado</dt><dd><?= e(brl($t['saldo'])) ?></dd></div>
        <?php endif ?>
      </dl>
      <?php if ($sched && $p['modalidade'] === 'proprio'): ?>
        <div class="tbl-wrap">
          <table class="tbl tbl-compact">
            <thead><tr><th>Parcela</th><th>Vencimento</th><th>Principal</th><th>Juros (1% a.m.)</th><th>Valor</th></tr></thead>
            <tbody>
              <?php foreach ($sched as $s): ?><tr><td><?= (int) $s['n'] ?>ª</td><td><?= e($s['vencimento'] ? fmt_date($s['vencimento']) : '—') ?></td><td><?= e(brl($s['principal'])) ?></td><td><?= e(brl($s['juros'])) ?></td><td><strong><?= e(brl($s['valor'])) ?></strong></td></tr><?php endforeach ?>
            </tbody>
          </table>
        </div>
        <p class="hint">Juros simples de 1% por mês contado da contratação até cada vencimento. A atualização pelo INPC é aplicada na data de cada pagamento.</p>
      <?php elseif ($sched): ?>
        <p class="hint">Em até <?= count($sched) ?> parcelas no cartão, acrescidas da taxa de parcelamento da operadora, paga pelo cliente.</p>
      <?php endif ?>
    </div>
    <div>
      <h3>Efeito no contrato</h3>
      <ul class="prop-efeitos">
        <?php foreach ($fx as [$label, $on]): ?><li class="<?= $on ? 'on' : 'off' ?>"><?= $on ? 'Incluído' : 'Excluído' ?> · <?= e($label) ?></li><?php endforeach ?>
      </ul>
      <p class="hint">Para o contrato refletir estas condições, gere uma nova versão no <a href="<?= e(url('painel/atendimento/' . $id)) ?>#kit">kit do atendimento</a>. A numeração das cláusulas e dos parágrafos se ajusta sozinha.</p>
    </div>
  </div>
</section>
<?php endif ?>
