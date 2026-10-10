<?php
$conflict = case_data($c, 'conflict');
$yn = ['sim' => 'Sim', 'nao' => 'Não', 'nao_sei' => 'Não sabe', 'parcial' => 'Parcial', 'ativo' => 'Ativo', 'quitado' => 'Quitado'];
?>
<div class="grid-main-side">
  <div>
    <section class="box">
      <h2>O que o cliente contou</h2>
      <blockquote class="quote-box">“<?= e($c['summary_sentence']) ?>” <small><?= $c['summary_confirmed'] ? 'Cliente confirmou o resumo.' : 'Resumo ainda não confirmado pelo cliente.' ?></small></blockquote>
      <dl class="facts-grid">
        <div><dt>Notificação / apreensão</dt><dd><?= e(NOTICE_OPTIONS[$c['notice']] ?? '—') ?><?= $c['notice_date'] ? ' · ' . e(fmt_date($c['notice_date'])) : '' ?></dd></div>
        <div><dt>Prioridade</dt><dd><?= e(PRIORITY_OPTIONS[$c['priority']]['label'] ?? '—') ?></dd></div>
        <div><dt>Banco</dt><dd><?= e($c['bank'] ?: '—') ?></dd></div>
        <div><dt>Contrato</dt><dd><?= e($yn[$c['contract_status']] ?? '—') ?></dd></div>
        <div><dt>Parcela aproximada</dt><dd><?= $c['installment_value'] !== null ? e(money((float) $c['installment_value'])) : '—' ?></dd></div>
        <div><dt>Cabe no orçamento</dt><dd><?= $c['budget_installment'] !== null ? e(money((float) $c['budget_installment'])) : '—' ?></dd></div>
        <div><dt>Atraso</dt><dd><?= e($yn[$c['overdue']] ?? '—') ?><?= $c['overdue_count'] ? ' · ' . e($c['overdue_count']) : '' ?></dd></div>
        <div><dt>Tem o contrato</dt><dd><?= e($yn[$c['has_contract']] ?? '—') ?></dd></div>
        <div><dt>Outro advogado/processo</dt><dd><?= e($yn[$c['other_lawyer']] ?? '—') ?></dd></div>
        <div><dt>Prefere</dt><dd><?= e(CONTACT_PREFS[$c['contact_pref']] ?? '—') ?><?= $c['contact_time'] ? ' · ' . e($c['contact_time']) : '' ?></dd></div>
        <div><dt>Acessibilidade</dt><dd><?= e($c['accessibility'] ?: '—') ?></dd></div>
        <div><dt>Veio de</dt><dd><?= e($c['origin_page'] ?: $c['source']) ?></dd></div>
      </dl>
      <?php if ($c['description']): ?><div class="client-words"><strong>Nas palavras do cliente</strong><?= nl2p($c['description']) ?></div><?php endif ?>
    </section>

    <section class="box">
      <h2>Etapa 1 · Primeiro contato e objetivo</h2>
      <?php if ($c['first_human_contact_at']): ?>
        <p class="ok-line">Primeiro contato humano em <?= e(fmt_datetime($c['first_human_contact_at'])) ?> (<?= number_format(business_hours_between(dt($c['created_at']), dt($c['first_human_contact_at'])), 1, ',', '') ?> h úteis após a entrada).</p>
      <?php else: ?>
        <?= case_form($c, 'primeiro_contato', [], 'inline-form') ?>
          <label class="fld"><span>Canal</span><select name="channel"><option>WhatsApp</option><option>Ligação</option><option>E-mail</option><option>Vídeo</option></select></label>
          <button class="b b-primary" type="submit">Registrar primeiro contato humano</button>
        </form>
        <p class="hint">Meta: 1 hora útil. Use a aba Mensagens para enviar o modelo de primeiro contato.</p>
      <?php endif ?>

      <?= case_form($c, 'objetivo', [], 'stack') ?>
        <label class="fld"><span>Objetivo concreto e prioritário</span><input name="objective" value="<?= e($c['objective']) ?>" placeholder="Ex.: manter o veículo com prestação de até R$ 900"></label>
        <label class="fld"><span>Como será comprovado</span><input name="objective_measure" value="<?= e($c['objective_measure']) ?>" placeholder="Ex.: novo demonstrativo e primeiro boleto no valor acordado"></label>
        <label class="chk"><input type="checkbox" name="summary_confirmed" value="1"<?= checked((bool) $c['summary_confirmed']) ?>> Repeti o entendimento em uma frase e o cliente confirmou</label>
        <button class="b" type="submit">Salvar objetivo</button>
      </form>
    </section>

    <section class="box">
      <h2>Conflito de interesses</h2>
      <p class="hint">Verificar antes de receber documentação extensa ou aceitar a representação. Sem conflito resolvido, orientar encaminhamento adequado e registrar o limite da atuação.</p>
      <?php if ($conflict): ?><p class="ok-line"><?= $conflict['result'] === 'sem_conflito' ? 'Sem conflito' : 'Conflito ou impedimento identificado' ?> · <?= e(user_name((int) $conflict['by'])) ?> · <?= e(fmt_datetime($conflict['at'])) ?><?= $conflict['note'] ? ' · ' . e($conflict['note']) : '' ?></p><?php endif ?>
      <?= case_form($c, 'conflito', [], 'inline-form') ?>
        <label class="fld"><span>Resultado</span><select name="conflict"><option value="sem_conflito">Sem conflito</option><option value="conflito">Há conflito / impedimento</option></select></label>
        <label class="fld grow"><span>Observação</span><input name="note" placeholder="Partes verificadas, processos existentes…"></label>
        <button class="b" type="submit">Registrar verificação</button>
      </form>
    </section>

    <section class="box">
      <h2>Etapa 2 · Triagem de urgência <small>(advogado)</small></h2>
      <?php if ($c['urgency_assessed_at']): ?>
        <p class="ok-line">Classificado em <?= e(fmt_datetime($c['urgency_assessed_at'])) ?>: <strong><?= e(URGENCY_CLASSES[$c['urgency_class']]['label'] ?? '—') ?></strong></p>
      <?php elseif ($c['urgent']): ?>
        <p class="warn-line">Urgência sinalizada na entrada e ainda não avaliada pelo advogado. Meta: 2 horas úteis.</p>
      <?php endif ?>
      <div class="tbl-wrap"><table class="tbl tbl-compact">
        <thead><tr><th>Situação</th><th>Providência imediata</th><th>Resultado para seguir</th></tr></thead>
        <tbody><?php foreach (URGENCY_CLASSES as $k => $u): ?><tr class="<?= $c['urgency_class'] === $k ? 'row-sel' : '' ?>"><td><?= e($u['label']) ?></td><td><?= e($u['action']) ?></td><td><?= e($u['result']) ?></td></tr><?php endforeach ?></tbody>
      </table></div>
      <?= case_form($c, 'urgencia', [], 'stack') ?>
        <label class="fld"><span>Classificação (com base em documentos e fatos, não só em dias de atraso)</span>
          <select name="urgency_class"><option value="">—</option><?php foreach (URGENCY_CLASSES as $k => $u): ?><option value="<?= $k ?>"<?= selected($c['urgency_class'], $k) ?>><?= e($u['label']) ?></option><?php endforeach ?></select>
        </label>
        <label class="fld"><span>Vias verificadas, rito, contagem aplicável e medida decidida</span><textarea name="note" rows="2"></textarea></label>
        <button class="b b-primary" type="submit">Registrar avaliação do advogado</button>
      </form>
    </section>

    <section class="box">
      <h2>Orientação escrita ao cliente</h2>
      <p class="hint">Sobre pagamentos e novas notificações. A ação revisional não afasta a mora; contestar tarifas ou seguros não protege automaticamente contra apreensão. Nunca recomendar parar de pagar ou pagar parcela reduzida por conta própria.</p>
      <?= case_form($c, 'orientacao_escrita', [], 'stack') ?>
        <textarea name="text" rows="4" placeholder="Ex.: Continue pagando as parcelas pelo boleto oficial enquanto analisamos. Se chegar qualquer carta, mensagem ou visita de oficial de justiça, fotografe e envie no mesmo dia pelo WhatsApp."></textarea>
        <button class="b" type="submit">Publicar orientação para o cliente</button>
      </form>
    </section>
  </div>

  <aside>
    <section class="box" id="prazos">
      <h2>Prazos jurídicos</h2>
      <p class="hint">Prazo jurídico e data de atualização ao cliente são controles distintos. As metas internas não podem retardar a atuação necessária.</p>
      <?php if ($deadlines): ?>
        <ul class="dl-list">
          <?php foreach ($deadlines as $d): $late = $d['status'] === 'aberto' && dt($d['due_at']) < now(); ?>
            <li class="<?= $d['status'] !== 'aberto' ? 'done' : ($late ? 'late' : '') ?>">
              <strong><?= e($d['description']) ?></strong>
              <small><?= e(fmt_datetime($d['due_at'])) ?> · <?= e(user_name($d['owner_id'] ? (int) $d['owner_id'] : null)) ?> (subst. <?= e(user_name($d['substitute_id'] ? (int) $d['substitute_id'] : null)) ?>)<?= $d['trigger_event'] ? ' · início: ' . e($d['trigger_event']) : '' ?></small>
              <?php if ($d['status'] === 'aberto'): ?>
                <?= case_form($c, 'prazo_status', ['deadline_id' => $d['id'], 'back' => 'atendimento'], 'mini-actions') ?>
                  <button class="b b-xs" name="status" value="cumprido">Cumprido</button>
                  <button class="b b-xs b-danger" name="status" value="perdido" data-confirm="Registrar prazo perdido? Isso gera investigação imediata.">Perdido</button>
                </form>
              <?php else: ?><span class="tag <?= $d['status'] === 'cumprido' ? 'tag-green' : 'tag-red' ?>"><?= e($d['status']) ?></span><?php endif ?>
            </li>
          <?php endforeach ?>
        </ul>
      <?php endif ?>
      <?= case_form($c, 'prazo', ['back' => 'atendimento'], 'stack') ?>
        <label class="fld"><span>Descrição</span><input name="description" placeholder="Ex.: contestação na busca e apreensão" required></label>
        <label class="fld"><span>Evento de início</span><input name="trigger_event" placeholder="Ex.: juntada do mandado em 08/10"></label>
        <label class="fld"><span>Vencimento</span><input type="datetime-local" name="due_at" required></label>
        <div class="row2">
          <label class="fld"><span>Executor</span><?= user_select('owner_id', $c['advogado_id'] ? (int) $c['advogado_id'] : null, $users) ?></label>
          <label class="fld"><span>Substituto</span><?= user_select('substitute_id', $c['substituto_id'] ? (int) $c['substituto_id'] : null, $users) ?></label>
        </div>
        <button class="b" type="submit">Adicionar prazo</button>
      </form>
    </section>

    <section class="box">
      <h2>Dados do cliente</h2>
      <?= case_form($c, 'dados', [], 'stack') ?>
        <label class="fld"><span>Nome</span><input name="client_name" value="<?= e($c['client_name']) ?>"></label>
        <label class="fld"><span>Telefone</span><input name="client_phone" value="<?= e($c['client_phone']) ?>"></label>
        <label class="fld"><span>E-mail</span><input name="client_email" value="<?= e($c['client_email']) ?>"></label>
        <div class="row2">
          <label class="fld"><span>Cidade</span><input name="client_city" value="<?= e($c['client_city']) ?>"></label>
          <label class="fld"><span>UF</span><input name="client_uf" value="<?= e($c['client_uf']) ?>" maxlength="2"></label>
        </div>
        <label class="fld"><span>Banco</span><input name="bank" value="<?= e($c['bank']) ?>"></label>
        <label class="fld"><span>Canal preferido</span><select name="contact_pref"><option value="">—</option><?php foreach (CONTACT_PREFS as $k => $l): ?><option value="<?= $k ?>"<?= selected($c['contact_pref'], $k) ?>><?= e($l) ?></option><?php endforeach ?></select></label>
        <label class="fld"><span>Acessibilidade</span><input name="accessibility" value="<?= e($c['accessibility']) ?>"></label>
        <button class="b" type="submit">Salvar dados</button>
      </form>
    </section>
  </aside>
</div>
