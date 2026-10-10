<?php
$lim = case_data($c, 'negotiation', []);
$ag = case_data($c, 'agreement', []);
$ck7 = case_checks((int) $c['id'], 'negociacao');
$ck8 = case_checks((int) $c['id'], 'acordo');
$offerDecisions = array_filter($decisions, fn($d) => $d['kind'] === 'oferta');
$rounds = $offers ? max(array_column($offers, 'round')) : 0;
$statusTags = ['recebida' => 'tag-blue', 'enviada' => 'tag-gray', 'contraproposta' => 'tag-amber', 'aceita' => 'tag-green', 'recusada' => 'tag-red', 'expirada' => 'tag-gray'];
?>
<div class="grid-main-side">
  <div>
    <section class="box">
      <div class="box-head"><h2>Etapa 7 · Ofertas e propostas</h2><span class="sub"><?= $rounds ?> rodada(s)</span></div>
      <?php if ($offers): ?>
      <div class="tbl-wrap"><table class="tbl tbl-compact offers">
        <thead><tr><th>Rodada</th><th>Saldo ref.</th><th>Entrada</th><th>Parcelas</th><th>Total</th><th>Validade</th><th>Situação</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($offers as $o): $tot = offer_total($o); ?>
            <tr>
              <td><?= (int) $o['round'] ?> · <?= $o['origin'] === 'credor' ? 'credor' : 'nossa' ?><br><small><?= e($o['creditor'] ?? '') ?></small></td>
              <td><?= e(money($o['ref_balance'] !== null ? (float) $o['ref_balance'] : null)) ?><?= $o['ref_date'] ? '<br><small>em ' . e(fmt_date($o['ref_date'])) . '</small>' : '' ?></td>
              <td><?= e(money($o['down_payment'] !== null ? (float) $o['down_payment'] : null)) ?></td>
              <td><?= $o['installments'] ? (int) $o['installments'] . '× ' . e(money((float) $o['installment_value'])) : '—' ?></td>
              <td><strong><?= e(money($tot)) ?></strong><?php if ($o['ref_balance'] && $tot): ?><br><small><?= $tot <= $o['ref_balance'] ? 'desconto de ' . e(pct((1 - $tot / $o['ref_balance']) * 100, 1)) : 'acima do saldo em ' . e(pct(($tot / $o['ref_balance'] - 1) * 100, 1)) ?></small><?php endif ?></td>
              <td><?= $o['valid_until'] ? e(fmt_date($o['valid_until'])) : '—' ?></td>
              <td><span class="tag <?= $statusTags[$o['status']] ?? 'tag-gray' ?>"><?= e($o['status']) ?></span><?= $o['communicated_at'] ? '<br><small>comunicada ' . e(fmt_date($o['communicated_at'])) . '</small>' : '' ?></td>
              <td class="nowrap">
                <?php if ($o['origin'] === 'credor' && !$o['communicated_at']): ?>
                  <?= case_form($c, 'oferta_comunicar', ['offer_id' => $o['id']], 'mini-form') ?>
                    <input name="recommendation" placeholder="Nossa avaliação" aria-label="Nossa avaliação">
                    <button class="b b-xs b-primary" type="submit">Enviar ao cliente</button>
                  </form>
                <?php endif ?>
                <?= case_form($c, 'oferta_status', ['offer_id' => $o['id']], 'mini-form') ?>
                  <select name="status" aria-label="Situação"><?php foreach (array_keys($statusTags) as $st): ?><option<?= selected($o['status'], $st) ?>><?= $st ?></option><?php endforeach ?></select>
                  <input name="confirm_via" placeholder="Autorização por (se aceita fora do portal)" aria-label="Autorização">
                  <button class="b b-xs" type="submit">Atualizar</button>
                </form>
              </td>
            </tr>
            <?php if ($o['charges'] || $o['effects'] || $o['notes']): ?><tr class="sub-row"><td colspan="8"><small><?= $o['charges'] ? 'Encargos/garantias: ' . e($o['charges']) . ' · ' : '' ?><?= $o['effects'] ? 'Efeitos: ' . e($o['effects']) . ' · ' : '' ?><?= e($o['notes'] ?? '') ?></small></td></tr><?php endif ?>
          <?php endforeach ?>
        </tbody>
      </table></div>
      <?php else: ?><p class="empty">Nenhuma oferta registrada.</p><?php endif ?>

      <?php foreach ($offerDecisions as $d): ?>
        <p class="<?= $d['answer'] ? 'ok-line' : 'warn-line' ?>">Cliente <?= $d['answer'] ? 'respondeu: “' . e($d['answer']) . '”' . ($d['reason'] ? ' · ' . e($d['reason']) : '') : 'ainda não respondeu à proposta enviada em ' . e(fmt_date($d['requested_at'])) ?></p>
      <?php endforeach ?>

      <details class="more">
        <summary>Registrar oferta do credor ou proposta nossa</summary>
        <?= case_form($c, 'oferta', [], 'stack') ?>
          <div class="row4">
            <label class="fld"><span>Origem</span><select name="origin"><option value="credor">Oferta do credor</option><option value="escritorio">Proposta nossa</option></select></label>
            <label class="fld"><span>Rodada</span><input name="round" value="<?= $rounds ?: 1 ?>" inputmode="numeric"></label>
            <label class="fld"><span>Credor / interlocutor</span><input name="creditor" value="<?= e($lim['creditor'] ?? $c['bank']) ?>"></label>
            <label class="fld"><span>Protocolo</span><input name="protocol"></label>
            <label class="fld"><span>Saldo de referência</span><input name="ref_balance" inputmode="decimal"></label>
            <label class="fld"><span>Data do saldo</span><input type="date" name="ref_date"></label>
            <label class="fld"><span>Entrada</span><input name="down_payment" inputmode="decimal"></label>
            <label class="fld"><span>Nº parcelas</span><input name="installments" inputmode="numeric"></label>
            <label class="fld"><span>Valor da parcela</span><input name="installment_value" inputmode="decimal"></label>
            <label class="fld"><span>Total (se informado)</span><input name="total" inputmode="decimal"></label>
            <label class="fld"><span>Validade</span><input type="date" name="valid_until"></label>
          </div>
          <label class="fld"><span>Encargos, garantias, seguros embutidos, renúncias, novação</span><input name="charges"></label>
          <label class="fld"><span>Efeitos sobre saldo, veículo, registros e processos</span><input name="effects"></label>
          <label class="fld"><span>Observações</span><input name="notes"></label>
          <button class="b b-primary" type="submit">Registrar</button>
        </form>
      </details>
      <p class="hint">“Prestação menor” isoladamente não demonstra vantagem. Não usar percentuais de desconto como promessa: descontos dependem da oferta efetiva do credor. Após duas rodadas ou 10 dias úteis sem avanço, o advogado revisa a estratégia.</p>
      <div class="cklist"><?= check_item($c, 'negociacao', 'revisao_estrategia', 'Advogado revisou a estratégia após duas rodadas ou 10 dias úteis sem avanço', $ck7['revisao_estrategia'] ?? null, 'negociacao') ?></div>
    </section>

    <section class="box">
      <h2>Etapa 8 · Formalização e cumprimento do acordo</h2>
      <?= case_form($c, 'acordo', [], 'stack') ?>
        <div class="row4">
          <label class="fld"><span>Assinado em</span><input type="date" name="signed_at" value="<?= e($ag['signed_at'] ?? '') ?>"></label>
          <label class="fld"><span>Saldo reconhecido</span><input name="balance" value="<?= e(num($ag['balance'] ?? null)) ?>" inputmode="decimal"></label>
          <label class="fld"><span>Desconto</span><input name="discount" value="<?= e(num($ag['discount'] ?? null)) ?>" inputmode="decimal"></label>
          <label class="fld"><span>Homologação</span><select name="homologation"><?php foreach (['' => '—', 'nao_ha_processo' => 'Não há processo', 'pedir' => 'Pedir homologação', 'homologado' => 'Homologado'] as $hk => $hl): ?><option value="<?= $hk ?>"<?= selected($ag['homologation'] ?? '', $hk) ?>><?= $hl ?></option><?php endforeach ?></select></label>
        </div>
        <label class="fld"><span>Instrumento (identificação, partes, contrato, veículo)</span><input name="instrument" value="<?= e($ag['instrument'] ?? '') ?>"></label>
        <label class="fld"><span>Quitação, boletos, restrições, gravame, processos</span><textarea name="releases" rows="2"><?= e($ag['releases'] ?? '') ?></textarea></label>
        <label class="fld"><span>Destino do veículo e saldo residual, se houver entrega</span><input name="vehicle" value="<?= e($ag['vehicle'] ?? '') ?>"></label>
        <label class="chk"><input type="checkbox" name="boleto_checked" value="1"<?= checked(!empty($ag['boleto_checked'])) ?>> Beneficiário e autenticidade do boleto conferidos em canal oficial</label>
        <label class="chk"><input type="checkbox" name="sem_parcelas" value="1"> Acordo sem parcelas futuras (pagamento único já registrado)</label>
        <button class="b" type="submit">Salvar acordo</button>
      </form>
      <div class="cklist">
        <?php foreach (stage('acordo')['steps'] as $sk => $sl): ?><?= check_item($c, 'acordo', $sk, $sl, $ck8[$sk] ?? null, 'negociacao') ?><?php endforeach ?>
        <?= check_item($c, 'acordo', 'formal', stage('acordo')['gates']['formal']['label'], $ck8['formal'] ?? null, 'negociacao', true) ?>
      </div>
    </section>
  </div>

  <aside>
    <section class="box">
      <h2>Autorização do cliente</h2>
      <p class="hint">Não elevar a proposta acima do limite autorizado sem nova decisão do cliente.</p>
      <?= case_form($c, 'limites', [], 'stack') ?>
        <div class="row2">
          <label class="fld"><span>Teto de entrada</span><input name="max_down" value="<?= e(num($lim['max_down'] ?? null)) ?>" inputmode="decimal"></label>
          <label class="fld"><span>Prestação máxima</span><input name="max_installment" value="<?= e(num($lim['max_installment'] ?? ($c['budget_installment'] !== null ? (float) $c['budget_installment'] : null))) ?>" inputmode="decimal"></label>
          <label class="fld"><span>Data possível de pagamento</span><input name="pay_date" value="<?= e($lim['pay_date'] ?? '') ?>" placeholder="ex.: dia 10"></label>
          <label class="fld"><span>Autorizado em</span><input type="date" name="authorized_at" value="<?= e($lim['authorized_at'] ?? '') ?>"></label>
        </div>
        <label class="fld"><span>Objetivo prioritário</span><input name="priority" value="<?= e($lim['priority'] ?? $c['objective']) ?>"></label>
        <label class="fld"><span>Credor atual / cessionário / empresa de cobrança</span><input name="creditor" value="<?= e($lim['creditor'] ?? $c['bank']) ?>"></label>
        <label class="fld"><span>Interlocutor e canal oficial</span><input name="creditor_contact" value="<?= e($lim['creditor_contact'] ?? '') ?>"></label>
        <button class="b" type="submit">Salvar autorização</button>
      </form>
    </section>

    <section class="box">
      <h2>Calendário do acordo</h2>
      <p class="hint">Aviso ao cliente 3 dias antes de cada vencimento. Registrar “acordo em cumprimento”, sem marcar quitação antecipadamente.</p>
      <?php if ($schedule): ?>
        <ul class="dl-list">
          <?php foreach ($schedule as $p): ?>
            <li class="<?= $p['status'] === 'pago' ? 'done' : ($p['status'] === 'atrasado' ? 'late' : '') ?>">
              <strong><?= e(fmt_date($p['due_date'])) ?> · <?= e(money($p['amount'] !== null ? (float) $p['amount'] : null)) ?></strong>
              <small><?= e($p['description']) ?><?= $p['proof'] ? ' · comprovante: ' . e($p['proof']) : '' ?></small>
              <?= case_form($c, 'parcela_status', ['schedule_id' => $p['id']], 'mini-actions') ?>
                <input name="proof" placeholder="comprovante" aria-label="Comprovante">
                <button class="b b-xs" name="status" value="pago">Pago</button>
                <button class="b b-xs b-danger" name="status" value="atrasado">Atrasado</button>
              </form>
            </li>
          <?php endforeach ?>
        </ul>
      <?php endif ?>
      <?= case_form($c, 'parcela_add', [], 'stack') ?>
        <label class="fld"><span>Descrição</span><input name="description" value="Parcela do acordo" required></label>
        <div class="row2">
          <label class="fld"><span>Primeiro vencimento</span><input type="date" name="due_date" required></label>
          <label class="fld"><span>Valor</span><input name="amount" inputmode="decimal"></label>
          <label class="fld"><span>Repetir mensalmente</span><input name="repeat" value="1" inputmode="numeric"></label>
        </div>
        <button class="b" type="submit">Adicionar ao calendário</button>
      </form>
    </section>
  </aside>
</div>
