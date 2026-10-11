<?php
/** Proposta de um atendimento: condições à esquerda, simulação e cláusulas do contrato à direita. */
$id = (int) $c['id'];
$p = $d['pagamento'];
$tipo = $p['condicao'] === 'avista' ? 'avista' : $p['modalidade'];
$atividades = array_merge($d['atividades'], array_fill(0, max(1, 3 - count($d['atividades'])), ['descricao' => '', 'valor' => null]));
$custos = array_merge($d['custos'], array_fill(0, max(1, 3 - count($d['custos'])), ['tipo' => '', 'descricao' => '', 'valor' => null]));
$auto = proposal_auto_texts($d);
$prev = proposal_clause_preview($id, $d);
$ckd = fn(bool $b) => $b ? 'checked' : '';
$maisAberto = $d['custos'] || $d['deslocamento']['repassa'] || !$d['despesas']['repassa'] || $d['validade'];
?>
<div class="page-head">
  <div>
    <h1>Proposta · <?= e($c['client_name']) ?></h1>
    <p class="sub">Atendimento <?= e($c['protocol']) ?> · <a href="<?= e(url('painel/atendimento/' . $id)) ?>#kit">ficha e kit</a><?= $pr ? ' · salva em ' . e(fmt_datetime($pr['updated_at'])) : ' · ainda não salva' ?></p>
  </div>
  <a class="b" href="<?= e(url('painel/propostas')) ?>">Todas as propostas</a>
</div>

<?php if ($errors): ?>
  <div class="flash flash-error" role="alert">Nada foi salvo. Confira: <?= e(implode(' ', $errors)) ?></div>
<?php endif ?>

<form class="form prop-form prop-layout" id="prop-form" method="post" action="<?= e(url('painel/propostas/' . $id)) ?>" data-proposta data-previa="<?= e(url('painel/propostas/' . $id . '/previa')) ?>">
  <?= csrf_field() ?>
  <div class="prop-campos stack">
    <section class="box">
      <div class="box-head"><h2>Honorários</h2></div>
      <div class="var-grid">
        <label class="fld"><span>Valor fixo (R$)</span><input name="fixos_valor" inputmode="decimal" placeholder="0,00" value="<?= e(cents_input($d['fixos']['valor'])) ?>"></label>
        <label class="fld"><span>O que remunera <small>(opcional)</small></span><input name="fixos_descricao" maxlength="500" placeholder="ex.: notificação e ação revisional em 1º grau" value="<?= e($d['fixos']['descricao']) ?>"></label>
      </div>
      <details class="prop-mais" <?= $d['atividades'] ? 'open' : '' ?>>
        <summary>Honorários por atividade</summary>
        <div class="prop-rows">
          <?php foreach ($atividades as $i => $a): ?>
            <div class="prop-row">
              <label class="fld"><span>Atividade</span><input name="atividade_descricao[]" maxlength="300" placeholder="ex.: audiência de conciliação" value="<?= e($a['descricao']) ?>"></label>
              <label class="fld prop-valor"><span>Valor (R$)</span><input name="atividade_valor[]" inputmode="decimal" placeholder="0,00" value="<?= e(cents_input($a['valor'])) ?>"></label>
            </div>
          <?php endforeach ?>
        </div>
      </details>
      <label class="chk prop-toggle"><input type="checkbox" name="exito_ativo" value="1" <?= $ckd($d['exito']['ativo']) ?>> Honorários de êxito</label>
      <div class="prop-if-exito prop-exito">
        <label class="fld prop-pct"><span>Percentual</span><input name="exito_percentual" inputmode="decimal" placeholder="20" value="<?= e($d['exito']['percentual']) ?>"></label>
        <label class="fld"><span>Sobre</span><textarea name="exito_base" rows="3" maxlength="1000"><?= e($d['exito']['base']) ?></textarea></label>
      </div>
    </section>

    <section class="box">
      <div class="box-head"><h2>Pagamento</h2></div>
      <div class="prop-tipos" role="radiogroup" aria-label="Condição de pagamento">
        <label><input type="radio" name="pagamento_tipo" value="avista" <?= $ckd($tipo === 'avista') ?>><span><strong>À vista</strong></span></label>
        <label><input type="radio" name="pagamento_tipo" value="proprio" <?= $ckd($tipo === 'proprio') ?>><span><strong>Parcelado</strong><small>juros de 1% a.m. + INPC</small></span></label>
        <label><input type="radio" name="pagamento_tipo" value="cartao" <?= $ckd($tipo === 'cartao') ?>><span><strong>Cartão de crédito</strong><small>taxa da operadora pelo cliente</small></span></label>
      </div>
      <div class="var-grid prop-if-avista">
        <label class="fld"><span>Vencimento</span><input type="date" name="vencimento" value="<?= e((string) $p['vencimento']) ?>"></label>
      </div>
      <div class="var-grid prop-if-parcelado">
        <label class="fld"><span>Parcelas</span><input type="number" name="parcelas" min="1" max="60" value="<?= (int) $p['parcelas'] ?>"></label>
        <label class="fld prop-if-proprio"><span>1º vencimento</span><input type="date" name="primeiro_vencimento" value="<?= e((string) $p['primeiro_vencimento']) ?>"></label>
        <label class="fld"><span>Entrada (R$, opcional)</span><input name="entrada" inputmode="decimal" placeholder="0,00" value="<?= e(cents_input($p['entrada'])) ?>"></label>
        <label class="fld"><span>Vencimento da entrada</span><input type="date" name="entrada_vencimento" value="<?= e((string) $p['entrada_vencimento']) ?>"></label>
      </div>
      <fieldset class="prop-formas"><legend class="lbl">Formas aceitas</legend>
        <?php foreach (PAYMENT_METHODS as $k => $l): ?>
          <label class="chk"><input type="checkbox" name="formas[]" value="<?= e($k) ?>" <?= $ckd(in_array($k, $p['formas'], true)) ?>> <?= e(mb_strtoupper(mb_substr($l, 0, 1)) . mb_substr($l, 1)) ?></label>
        <?php endforeach ?>
      </fieldset>
    </section>

    <details class="box prop-mais" <?= $maisAberto ? 'open' : '' ?>>
      <summary><h2>Mais condições</h2><span class="hint">valores à parte, deslocamento, despesas, validade</span></summary>
      <h3>Valores cobrados à parte <small class="muted">(não são honorários)</small></h3>
      <div class="prop-rows">
        <?php foreach ($custos as $x): ?>
          <div class="prop-row prop-row3">
            <label class="fld"><span>Tipo</span>
              <select name="custo_tipo[]"><option value="">—</option><?php foreach (COST_TYPES as $k => $l): ?><option value="<?= e($k) ?>" <?= selected($k, $x['tipo']) ?>><?= e($l) ?></option><?php endforeach ?></select>
            </label>
            <label class="fld"><span>Descrição</span><input name="custo_descricao[]" maxlength="300" placeholder="ex.: certidão de ônus do veículo" value="<?= e($x['descricao']) ?>"></label>
            <label class="fld prop-valor"><span>Estimativa</span><input name="custo_valor[]" inputmode="decimal" placeholder="a apurar" value="<?= e(cents_input($x['valor'])) ?>"></label>
          </div>
        <?php endforeach ?>
      </div>
      <div class="var-grid">
        <fieldset class="radios"><legend class="lbl">Deslocamento</legend>
          <label class="chk"><input type="radio" name="deslocamento" value="nao" <?= $ckd(!$d['deslocamento']['repassa']) ?>> Incluído nos honorários</label>
          <label class="chk"><input type="radio" name="deslocamento" value="sim" <?= $ckd($d['deslocamento']['repassa']) ?>> Repassar ao cliente</label>
          <div class="prop-if-desloc">
            <label class="chk"><input type="radio" name="deslocamento_criterio" value="comprovacao" <?= $ckd($d['deslocamento']['criterio'] !== 'km') ?>> Mediante comprovantes</label>
            <label class="chk"><input type="radio" name="deslocamento_criterio" value="km" <?= $ckd($d['deslocamento']['criterio'] === 'km') ?>> Por km: <input class="prop-km" name="deslocamento_km" inputmode="decimal" placeholder="R$ 0,00" aria-label="Valor por km" value="<?= e(cents_input($d['deslocamento']['valor_km'])) ?>"></label>
          </div>
        </fieldset>
        <fieldset class="radios"><legend class="lbl">Despesas antecipadas pelo escritório</legend>
          <label class="chk"><input type="radio" name="despesas" value="sim" <?= $ckd($d['despesas']['repassa']) ?>> Reembolsar mediante comprovação</label>
          <label class="chk"><input type="radio" name="despesas" value="nao" <?= $ckd(!$d['despesas']['repassa']) ?>> Não repassar</label>
        </fieldset>
      </div>
      <label class="fld prop-validade"><span>Validade da proposta</span><input type="date" name="validade" value="<?= e((string) $d['validade']) ?>"></label>
      <?php if ($p['observacoes'] !== ''): ?><input type="hidden" name="pagamento_observacoes" value="<?= e($p['observacoes']) ?>"><?php endif ?>
    </details>

    <div class="inline-form"><button class="b b-primary" type="submit">Salvar proposta</button><span class="hint" data-erros aria-live="polite"></span></div>
  </div>

  <aside class="prop-previa stack" aria-label="Prévia">
    <section class="box">
      <div class="box-head"><h2>Simulação</h2></div>
      <div data-sim><?= render('panel/_proposta_simulacao', ['d' => $d]) ?></div>
    </section>
    <section class="box">
      <div class="box-head"><h2>Como fica no contrato</h2></div>
      <p class="hint">O texto em destaque é escrito pela proposta e pode ser ajustado aqui mesmo. O restante vem do modelo do contrato.</p>
      <?php foreach (PROPOSAL_TEXTS as $k => $label):
        $manual = ($d['texto'][$k] ?? null) !== null;
        $shown = $d['texto'][$k] ?? $auto[$k];
        $ref = $manual ? ($d['texto_ref'][$k] ?? $auto[$k]) : '';
        $velho = $manual && proposal_norm_text($ref) !== proposal_norm_text($auto[$k]);
      ?>
        <div class="prop-clausula <?= $manual ? 'is-manual' : '' ?> <?= $velho ? 'is-velho' : '' ?>" data-texto="<?= e($k) ?>">
          <div class="prop-doc" data-antes><?= $prev[$k]['antes'] ?? '' ?></div>
          <label class="prop-editavel"><span class="sr-only"><?= e($label) ?></span>
            <textarea name="texto_<?= e($k) ?>" rows="4" spellcheck="true" data-auto="<?= e($auto[$k]) ?>"><?= e($shown) ?></textarea>
          </label>
          <input type="hidden" name="texto_<?= e($k) ?>_base" value="<?= e($shown) ?>" data-base>
          <input type="hidden" name="texto_<?= e($k) ?>_manual" value="<?= $manual ? '1' : '0' ?>" data-manual>
          <input type="hidden" name="texto_<?= e($k) ?>_ref" value="<?= e($ref) ?>" data-ref>
          <p class="prop-estado"><span class="-auto">Texto automático: acompanha os valores.</span><span class="-manual">Ajustado por você.</span><span class="-velho"> Os valores mudaram depois do ajuste; confira.</span> <button type="button" class="b-link -manual" data-restaurar>Voltar ao texto automático</button></p>
          <div class="prop-doc" data-depois><?= $prev[$k]['depois'] ?? '' ?></div>
        </div>
      <?php endforeach ?>
      <?php if (!$prev): ?><p class="hint">O modelo do contrato não foi encontrado; a prévia mostra só os trechos da proposta.</p><?php endif ?>
    </section>
  </aside>
</form>

<?php
$charges = asaas_charges($id);
$active = array_values(array_filter($charges, fn($r) => $r['status'] !== 'DELETED'));
$blockers = asaas_blockers($c, $pr);
$plan = $pr ? asaas_plan($pr['data']) : [];
$today = now()->format('Y-m-d');
?>
<section class="box" id="asaas">
  <div class="box-head"><h2>Cobrança no Asaas</h2><?php if (asaas_enabled()): ?><span class="hint"><?= e(ASAAS_ENVS[asaas_env()]['label']) ?></span><?php endif ?></div>
  <?php if ($active): $sum = asaas_summary($id); ?>
    <p>Recebido <strong><?= e(brl($sum['pago'])) ?></strong> de <?= e(brl($sum['total'])) ?> · <?= (int) $sum['pagas'] ?> de <?= (int) $sum['n'] ?> cobrança(s) paga(s)<?= $sum['vencidas'] ? ' · <strong class="t-warn">' . (int) $sum['vencidas'] . ' vencida(s)</strong>' : '' ?></p>
    <div class="tbl-wrap">
      <table class="tbl tbl-compact">
        <thead><tr><th>Cobrança</th><th>Vencimento</th><th>Valor</th><th>Tipo</th><th>Situação</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($charges as $r): ?>
            <tr class="<?= $r['status'] === 'DELETED' ? 'muted' : '' ?>">
              <td><?= e($r['label']) ?></td>
              <td><?= e(fmt_date($r['due_date'])) ?></td>
              <td><?= e(brl((int) $r['value_cents'])) ?><?= (int) $r['installments'] > 1 ? ' <small>em ' . (int) $r['installments'] . 'x</small>' : '' ?></td>
              <td><?= e(ASAAS_BILLING[$r['billing_type']] ?? $r['billing_type']) ?></td>
              <td><?= e(ASAAS_STATUS[$r['status']] ?? $r['status']) ?><?= $r['paid_at'] ? ' em ' . e(fmt_date($r['paid_at'])) : '' ?></td>
              <td><?php if ($r['invoice_url'] && $r['status'] !== 'DELETED'): ?><a href="<?= e($r['invoice_url']) ?>" target="_blank" rel="noopener">fatura</a><?php endif ?></td>
            </tr>
          <?php endforeach ?>
        </tbody>
      </table>
    </div>
    <form class="inline-form" method="post" action="<?= e(url('painel/propostas/' . $id . '/asaas')) ?>">
      <?= csrf_field() ?>
      <button class="b" name="op" value="atualizar">Atualizar situação</button>
      <button class="b b-danger" name="op" value="cancelar" data-confirm="Cancelar no Asaas todas as cobranças ainda não pagas deste contrato?">Cancelar as não pagas</button>
    </form>
    <p class="hint">O link da fatura pode ser enviado ao cliente; o Asaas também avisa por e-mail e SMS conforme as notificações da sua conta.</p>
  <?php elseif ($blockers): ?>
    <p>Depois do contrato assinado, as cobranças são criadas no Asaas com os mesmos valores e vencimentos do contrato.</p>
    <ul class="prop-bloqueios"><?php foreach ($blockers as $b): ?><li><?= e($b) ?></li><?php endforeach ?></ul>
    <?php if (!asaas_enabled()): ?><p><a href="<?= e(url('painel/configuracoes')) ?>#asaas">Configurar o Asaas</a></p><?php endif ?>
  <?php else: $billing = asaas_billing_type($pr['data']); ?>
    <p>O contrato está assinado e confere com esta proposta. Serão criadas no Asaas:</p>
    <form method="post" action="<?= e(url('painel/propostas/' . $id . '/asaas')) ?>" class="form">
      <?= csrf_field() ?>
      <div class="tbl-wrap">
        <table class="tbl tbl-compact">
          <thead><tr><th>Cobrança</th><th>Valor do contrato</th><th>Vencimento</th></tr></thead>
          <tbody>
            <?php foreach ($plan as $item): $old = $item['vencimento'] && $item['vencimento'] < $today; ?>
              <tr>
                <td><?= e($item['rotulo']) ?></td>
                <td><strong><?= e(brl($item['valor'])) ?></strong></td>
                <td><input type="date" name="venc_<?= e($item['ref']) ?>" value="<?= e((string) ($old ? '' : $item['vencimento'])) ?>" min="<?= e($today) ?>" required aria-label="Vencimento de <?= e($item['rotulo']) ?>">
                  <?php if ($old): ?><br><small class="t-warn">No contrato: <?= e(fmt_date($item['vencimento'])) ?> (já passou)</small><?php endif ?></td>
              </tr>
            <?php endforeach ?>
          </tbody>
        </table>
      </div>
      <div class="var-grid">
        <?php if (!array_filter($plan, fn($i) => $i['ref'] === 'cartao') || count($plan) > 1): ?>
          <label class="fld"><span>Tipo de cobrança</span>
            <select name="billing_type"><?php foreach (ASAAS_BILLING as $bk => $bl): if ($bk === 'CREDIT_CARD' && $billing !== 'CREDIT_CARD') { continue; } ?><option value="<?= e($bk) ?>" <?= selected($bk, $billing) ?>><?= e($bl) ?></option><?php endforeach ?></select>
          </label>
        <?php endif ?>
        <?php if (array_filter($plan, fn($i) => $i['ref'] === 'cartao')): ?>
          <label class="fld"><span>Acréscimo da taxa da operadora (%)</span><input name="acrescimo_cartao" inputmode="decimal" placeholder="0"></label>
        <?php endif ?>
      </div>
      <p class="hint">Multa de <?= e(str_replace('.', ',', (string) asaas_late_fees()['multa'])) ?>% e juros de <?= e(str_replace('.', ',', (string) asaas_late_fees()['juros'])) ?>% ao mês após o vencimento, como na cláusula de atraso. O cliente é cadastrado no Asaas pelo CPF, com os dados da ficha.</p>
      <button class="b b-primary" name="op" value="enviar" data-confirm="Criar estas cobranças no Asaas (<?= e(ASAAS_ENVS[asaas_env()]['label']) ?>)?">Criar cobranças no Asaas</button>
    </form>
  <?php endif ?>
</section>
<script src="<?= e(asset('js/proposta.js')) ?>" defer></script>
