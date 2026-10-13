<?php
/**
 * Honorários da reunião (só o operador vê): parte da precificação padrão
 * (ou da proposta já gravada), aceita desconto, parte para o final do
 * processo e a forma de pagamento. Com o aceite verbal do cliente, as
 * condições vão para a proposta e o contrato ganha uma nova minuta.
 * Requer $id, $c e $form (do atendimento).
 */
$draft = $_SESSION['hon_reu'][$id] ?? [];
[$hd, $herr] = meeting_fees($id, $draft);
$pr = proposal_get($id);
$std = pricing_standard($id);
$iaVal = pricing_ia($id);
$pc = pricing();
$ht = proposal_totals($hd);
$hp = $hd['pagamento'];
$htipo = $hp['condicao'] === 'avista' ? 'avista' : ($hp['modalidade'] === 'cartao' ? 'cartao' : 'proprio');
$hmodo = $hd['precificacao']['modo'];
$tudoFinal = pct_value($hd['precificacao']['final_pct']) >= 100;
$sched = proposal_schedule($hd);
$rows = $ht['agora'] ? sales_sim($ht['agora'], [], $hd) : [];
$curKey = sales_sim_current_key($hd);
$cur = array_values(array_filter($rows, fn($r) => $r['key'] === $curKey))[0] ?? null;
$autoTx = proposal_auto_texts($hd);
$prevTx = proposal_clause_preview($id, $hd);
$ck = fn(bool $b) => $b ? 'checked' : '';
?>
<details class="at-inline hon-reu" id="honorarios" <?= $draft || $herr ? 'open' : '' ?>>
  <summary>Honorários <span class="hint">só você vê · <?= $pr ? 'proposta salva: ' . e(brl(proposal_totals($pr['data'])['total'])) : 'padrão: ' . e(brl($std['valor'])) ?></span></summary>
  <?= $form('honorarios_reuniao', 'honorarios', false, 'stack hon-form') ?>
    <fieldset class="prop-tipos hon-modos"><legend class="lbl">Valor</legend>
      <label><input type="radio" name="precificacao_modo" value="padrao" <?= $ck($hmodo === 'padrao') ?>><span><strong>Padrão · <?= e(brl($std['valor'])) ?></strong><small><?= e($std['nota']) ?> + <?= e(fmt_pct($std['exito'])) ?> de êxito</small></span></label>
      <label><input type="radio" name="precificacao_modo" value="ia" <?= $ck($hmodo === 'ia') ?> <?= $iaVal ? '' : 'disabled' ?>><span><strong>Proposta da IA<?= $iaVal ? ' · ' . e(brl($iaVal)) : '' ?></strong><small><?= $iaVal ? 'última avaliação' : 'sem avaliação ainda' ?></small></span></label>
      <label><input type="radio" name="precificacao_modo" value="manual" <?= $ck($hmodo === 'manual') ?>><span><strong>Outro valor</strong><small>digite abaixo</small></span></label>
    </fieldset>
    <div class="row3">
      <label class="fld"><span>Valor fixo (R$)</span><input name="fixos_valor" inputmode="decimal" value="<?= e(cents_input($hd['fixos']['valor'])) ?>" placeholder="0,00"></label>
      <label class="fld"><span>Êxito sobre a economia (%)</span><input name="exito_percentual" inputmode="decimal" value="<?= e($hd['exito']['ativo'] ? $hd['exito']['percentual'] : '0') ?>"></label>
      <label class="fld"><span>Desconto (%)</span><input name="desconto" inputmode="decimal" value="<?= e($hd['precificacao']['desconto']) ?>" placeholder="0"></label>
    </div>
    <div class="hon-facil">
      <label class="fld"><span>Parte para o final do processo (%)</span><input name="final_pct" inputmode="decimal" value="<?= $tudoFinal ? '' : e($hd['precificacao']['final_pct']) ?>" placeholder="ex.: 30"></label>
      <label class="chk"><input type="checkbox" name="tudo_final" value="1" <?= $ck($tudoFinal) ?>> Tudo ao final do processo</label>
      <p class="hint">A parte ao final é corrigida mensalmente pelo INPC, com juros de 1% ao mês, e é devida mesmo sem êxito: vira uma condição especial no contrato.</p>
    </div>
    <fieldset class="prop-tipos hon-pag" <?= $tudoFinal ? 'disabled' : '' ?>><legend class="lbl">Pagamento</legend>
      <label><input type="radio" name="pagamento_tipo" value="avista" <?= $ck($htipo === 'avista') ?>><span><strong>PIX à vista</strong><small>direto na chave, sem taxa</small></span></label>
      <label><input type="radio" name="pagamento_tipo" value="proprio" <?= $ck($htipo === 'proprio') ?>><span><strong>Parcelado no escritório</strong><small>parcelas fixas, 1% ao mês</small></span></label>
      <label><input type="radio" name="pagamento_tipo" value="cartao" <?= $ck($htipo === 'cartao') ?>><span><strong>Cartão de crédito</strong><small>taxa do cliente · parcela mín. <?= e(brl($pc['cartao_min_parcela'])) ?></small></span></label>
    </fieldset>
    <div class="row3">
      <label class="fld"><span>Parcelas</span><input name="parcelas" type="number" min="1" max="60" value="<?= (int) ($htipo === 'cartao' ? $hp['cartao_parcelas'] : $hp['parcelas']) ?>"></label>
      <label class="fld"><span>Parcelas por (escritório)</span><select name="meio"><?php foreach (['pix' => 'PIX', 'boleto' => 'Boleto', 'pix_recorrente' => 'PIX recorrente (Asaas)'] as $mk => $ml): ?><option value="<?= $mk ?>" <?= selected($mk, (string) $hp['meio']) ?>><?= e($ml) ?></option><?php endforeach ?></select></label>
      <label class="fld"><span>1º vencimento</span><input type="date" name="primeiro_vencimento" value="<?= e((string) $hp['primeiro_vencimento']) ?>"></label>
    </div>
    <label class="fld hon-entrada"><span>Entrada (R$, opcional)</span><input name="entrada" inputmode="decimal" value="<?= e(cents_input($hp['entrada'])) ?>" placeholder="0,00"></label>

    <div class="hon-res" aria-live="polite">
      <div><span>Honorários</span><strong><?= e(brl($ht['total'])) ?></strong><small><?= $ht['desconto'] ? 'desconto de ' . e(brl($ht['desconto'])) : 'sem desconto' ?><?= $hd['exito']['ativo'] ? ' · + ' . e($hd['exito']['percentual']) . '% de êxito' : '' ?></small></div>
      <div><span>Ao final</span><strong><?= $ht['final'] ? e(brl($ht['final'])) : '—' ?></strong><small><?= $ht['final'] ? 'INPC + 1% a.m.' : 'nada para o final' ?></small></div>
      <div><span>Cliente paga agora</span><strong><?= $cur ? e(brl($cur['cliente'])) : ($ht['agora'] ? e(brl($ht['agora'])) : '—') ?></strong><small><?= $cur && $cur['n'] > 1 ? (int) $cur['n'] . 'x de ' . e(brl($cur['parcela'])) : ($cur ? e($cur['label']) : '') ?></small></div>
      <div><span>Você recebe</span><strong><?= $cur ? e(brl($cur['liquido'])) : '—' ?></strong><small><?= $cur ? ($cur['taxas'] ? 'após ' . e(brl($cur['taxas'])) . ' de taxas' : 'sem taxa') : '' ?></small></div>
    </div>
    <?php if ($herr): ?><p class="err-line"><?= e(implode(' ', $herr)) ?></p><?php endif ?>
    <?php if ($sched && $htipo === 'proprio'): ?>
      <p class="small hon-parc"><?= count($sched) ?> parcelas fixas de <strong><?= e(brl($sched[0]['valor'])) ?></strong><?= $sched[0]['vencimento'] ? ', a partir de ' . e(fmt_date($sched[0]['vencimento'])) : '' ?> (total <?= e(brl(array_sum(array_column($sched, 'valor')))) ?>).</p>
    <?php endif ?>

    <details class="hon-clausulas">
      <summary>Cláusulas do contrato sobre os honorários</summary>
      <p class="hint">Como as condições acima entram no contrato. Os trechos em destaque mudam com os valores; você pode ajustar o texto antes de registrar.</p>
      <?php foreach (PROPOSAL_TEXTS as $k => $label): $tx = $hd['texto'][$k] ?? $autoTx[$k]; ?>
        <div class="hon-cl">
          <h4><?= e($label) ?><?= $hd['texto'][$k] !== null ? ' <span class="neg-tag">ajustada</span>' : '' ?></h4>
          <?php if (!empty($prevTx[$k]['antes'])): ?><div class="hon-ctx"><?= $prevTx[$k]['antes'] ?></div><?php endif ?>
          <div class="hon-mark"><?= proposal_editor_html($tx) ?></div>
          <label class="fld"><span>Ajustar o texto</span><textarea name="texto_<?= e($k) ?>" rows="5"><?= e($tx) ?></textarea></label>
          <input type="hidden" name="texto_<?= e($k) ?>_base" value="<?= e($tx) ?>">
          <?php if ($hd['texto'][$k] !== null): ?><input type="hidden" name="texto_<?= e($k) ?>_manual" value="1"><input type="hidden" name="texto_<?= e($k) ?>_ref" value="<?= e((string) $hd['texto_ref'][$k]) ?>"><?php endif ?>
        </div>
      <?php endforeach ?>
    </details>

    <div class="hon-acoes">
      <button class="b" type="submit" name="acao" value="calcular">Recalcular</button>
      <button class="b b-primary" type="submit" name="acao" value="registrar">Cliente aceitou: registrar no contrato</button>
      <?php if ($draft): ?><button class="b b-ghost" type="submit" name="acao" value="limpar" formnovalidate>Descartar ajustes</button><?php endif ?>
      <a class="b b-ghost" href="<?= e(url('painel/propostas/' . $id)) ?>">Proposta completa</a>
    </div>
    <p class="hint">Recalcular não grava nada. Ao registrar, as condições vão para a proposta e o contrato ganha uma nova minuta para revisar e publicar no Kit.</p>
  </form>
</details>
