<?php
$a = case_data($c, 'audit', []);
$rec = case_data($c, 'recommendation', []);
$eng = case_data($c, 'engagement', []);
$ck5 = case_checks((int) $c['id'], 'decisao');
$ck6 = case_checks((int) $c['id'], 'contratacao');
$pathOptions = implode("\n", array_map(fn($p) => $p['label'], PATHS));
$kinds = ['caminho' => 'Caminho (etapa 5)', 'oferta' => 'Oferta do credor', 'acordo' => 'Texto final do acordo', 'acao' => 'Ajuizar ação', 'recurso' => 'Recurso', 'encerramento' => 'Encerramento', 'outro' => 'Outra decisão'];
?>
<div class="grid-main-side">
  <div>
    <section class="box">
      <h2>Etapa 5 · Opções e escolha informada</h2>
      <?php if (!empty($a['validated_at'])): ?>
        <p class="ok-line">Parecer validado: <strong><?= e(AUDIT_CONCLUSIONS[$a['conclusion']] ?? '—') ?></strong></p>
        <?php if (!empty($a['summary_client'])): ?><div class="quote-box"><?= nl2p($a['summary_client']) ?></div><?php endif ?>
      <?php else: ?>
        <p class="warn-line">O parecer ainda não foi validado (aba Análise). Apresente as opções depois da validação.</p>
      <?php endif ?>
      <div class="tbl-wrap"><table class="tbl tbl-compact">
        <thead><tr><th>Caminho</th><th>Quando considerar</th><th>O que explicar ao cliente</th></tr></thead>
        <tbody><?php foreach (PATHS as $pk => $p): ?><tr class="<?= ($rec['path'] ?? '') === $pk ? 'row-sel' : '' ?>"><td><strong><?= e($p['label']) ?></strong></td><td><?= e($p['when']) ?></td><td><?= e($p['explain']) ?></td></tr><?php endforeach ?></tbody>
      </table></div>
      <p class="hint">Começar pelo objetivo do cliente. Valores na mesma data, distinguindo “estimativa”, “oferta do banco” e “resultado já confirmado”. Explicar que alongar a dívida reduz a prestação e aumenta o custo total.</p>

      <?= case_form($c, 'recomendacao', [], 'stack') ?>
        <h3>Recomendação do advogado</h3>
        <div class="row2">
          <label class="fld"><span>Caminho recomendado</span><select name="path"><option value="">—</option><?php foreach (PATHS as $pk => $p): ?><option value="<?= $pk ?>"<?= selected($rec['path'] ?? '', $pk) ?>><?= e($p['label']) ?></option><?php endforeach ?></select></label>
          <label class="fld"><span>Razão</span><input name="reason" value="<?= e($rec['reason'] ?? '') ?>"></label>
        </div>
        <label class="fld"><span>Custos: serviço, despesas previsíveis, risco de sucumbência, possíveis recursos</span><textarea name="costs" rows="2"><?= e($rec['costs'] ?? '') ?></textarea></label>
        <label class="fld"><span>Limites da negociação e decisões pendentes</span><textarea name="limits" rows="2"><?= e($rec['limits'] ?? '') ?></textarea></label>
        <label class="fld"><span>O que ficou combinado sobre os pagamentos enquanto buscamos a solução</span><textarea name="payments" rows="2"><?= e($rec['payments'] ?? '') ?></textarea></label>
        <button class="b" type="submit">Salvar recomendação</button>
      </form>
      <div class="cklist">
        <?= check_item($c, 'decisao', 'objetivo_mensuravel', stage('decisao')['gates']['objetivo_mensuravel']['label'], $ck5['objetivo_mensuravel'] ?? null, 'decisao', true) ?>
        <?= check_item($c, 'decisao', 'limites', stage('decisao')['gates']['limites']['label'], $ck5['limites'] ?? null, 'decisao') ?>
      </div>
    </section>

    <section class="box" id="decisoes">
      <h2>Decisões do cliente</h2>
      <p class="hint">Confirmar por escrito decisões sobre acordo, ação, recurso e alterações relevantes de estratégia. Nenhum silêncio vale como aceite.</p>
      <?php if (!$decisions): ?><p class="empty">Nenhuma decisão registrada.</p><?php endif ?>
      <?php foreach ($decisions as $d): $opts = json_decode($d['options'], true) ?: []; ?>
        <div class="decision-row <?= $d['answer'] ? 'answered' : 'pending' ?>">
          <div class="dr-head"><span class="tag <?= $d['answer'] ? 'tag-green' : 'tag-amber' ?>"><?= $d['answer'] ? 'respondida' : 'aguardando cliente' ?></span> <small><?= e($kinds[$d['kind']] ?? $d['kind']) ?> · pedida <?= e(fmt_datetime($d['requested_at'])) ?><?= $d['deadline'] ? ' · até ' . e(fmt_date($d['deadline'])) : '' ?></small></div>
          <strong><?= e($d['question']) ?></strong>
          <?php if ($d['context']): ?><div class="small muted"><?= nl2p($d['context']) ?></div><?php endif ?>
          <?php if ($d['answer']): ?>
            <p class="answer">“<?= e($d['answer']) ?>”<?= $d['reason'] ? ' · ' . e($d['reason']) : '' ?> <small>via <?= e($d['answered_via']) ?>, <?= e(fmt_datetime($d['answered_at'])) ?><?= $d['recorded_by'] ? ' · registrado por ' . e(first_name(user_name((int) $d['recorded_by']))) : '' ?></small></p>
          <?php else: ?>
            <?= case_form($c, 'decisao_registrar', ['decision_id' => $d['id'], 'back' => 'decisao'], 'inline-form') ?>
              <label class="fld"><span>Resposta dada pelo cliente</span><select name="answer"><?php foreach ($opts as $o): ?><option><?= e($o) ?></option><?php endforeach ?></select></label>
              <label class="fld"><span>Canal</span><select name="via"><option>ligação</option><option>vídeo</option><option>WhatsApp</option><option>e-mail</option></select></label>
              <label class="fld grow"><span>Por quê (nas palavras do cliente)</span><input name="reason"></label>
              <button class="b b-xs" type="submit">Registrar</button>
            </form>
          <?php endif ?>
        </div>
      <?php endforeach ?>

      <details class="more" <?= $decisions ? '' : 'open' ?>>
        <summary>Pedir ou registrar uma decisão</summary>
        <?= case_form($c, 'decisao_nova', ['back' => 'decisao'], 'stack') ?>
          <div class="row2">
            <label class="fld"><span>Tipo</span><select name="kind" data-preset-kind><?php foreach ($kinds as $kk => $kl): ?><option value="<?= $kk ?>"><?= e($kl) ?></option><?php endforeach ?></select></label>
            <label class="fld"><span>Responder até</span><input type="date" name="deadline"></label>
          </div>
          <label class="fld"><span>Pergunta ao cliente</span><input name="question" value="Qual caminho você prefere seguir?" required></label>
          <label class="fld"><span>Opções (uma por linha)</span><textarea name="options" rows="4"><?= e($pathOptions) ?></textarea></label>
          <label class="fld"><span>Contexto (custos, riscos, recomendação, efeitos)</span><textarea name="context" rows="3"><?= e(isset($rec['path']) && $rec['path'] ? 'Recomendação do advogado: ' . PATHS[$rec['path']]['label'] . ($rec['reason'] ? ', porque ' . $rec['reason'] : '') . '.' : '') ?></textarea></label>
          <fieldset class="sub-fs">
            <legend>Já decidido em ligação ou vídeo? Registre a resposta agora</legend>
            <div class="row2">
              <label class="fld"><span>Resposta do cliente <small>(deixe vazio para enviar à área do cliente)</small></span><input name="answer"></label>
              <label class="fld"><span>Canal</span><select name="via"><option>ligação</option><option>vídeo</option><option>WhatsApp</option></select></label>
            </div>
            <label class="fld"><span>Por quê</span><input name="reason"></label>
          </fieldset>
          <button class="b b-primary" type="submit">Salvar decisão</button>
        </form>
      </details>
    </section>
  </div>

  <aside>
    <section class="box">
      <h2>Etapa 6 · Contratação e plano de trabalho</h2>
      <div class="cklist">
        <?php foreach (stage('contratacao')['steps'] as $sk => $sl): ?><?= check_item($c, 'contratacao', $sk, $sl, $ck6[$sk] ?? null, 'decisao') ?><?php endforeach ?>
      </div>
      <h3>Condição para avançar</h3>
      <div class="cklist">
        <?php foreach (stage('contratacao')['gates'] as $gk => $g): ?><?= check_item($c, 'contratacao', $gk, $g['label'], $ck6[$gk] ?? null, 'decisao', $g['block']) ?><?php endforeach ?>
      </div>
      <?= case_form($c, 'contratacao', [], 'stack') ?>
        <label class="fld"><span>Escopo: o que o serviço cobre</span><textarea name="scope" rows="2" placeholder="diagnóstico, negociação, ação, defesa em busca e apreensão, recursos, cálculos, cumprimento"><?= e($eng['scope'] ?? '') ?></textarea></label>
        <label class="fld"><span>Não incluído (exige contratação distinta)</span><textarea name="excluded" rows="2"><?= e($eng['excluded'] ?? '') ?></textarea></label>
        <label class="fld"><span>Honorários: inicial, êxito, base e momento de cobrança</span><textarea name="fees" rows="2"><?= e($eng['fees'] ?? '') ?></textarea></label>
        <label class="fld"><span>Exemplo numérico da base de êxito</span><textarea name="example" rows="2"><?= e($eng['example'] ?? '') ?></textarea></label>
        <label class="fld"><span>Despesas externas, acordo, desistência e prestação de contas</span><textarea name="expenses" rows="2"><?= e($eng['expenses'] ?? '') ?></textarea></label>
        <label class="fld"><span>Assinatura do contrato e da procuração</span><input type="date" name="signed_at" value="<?= e($eng['signed_at'] ?? '') ?>"></label>
        <button class="b" type="submit">Salvar contratação</button>
      </form>
      <p class="hint">Validar a tabela da seccional; não importar percentuais como regra universal. Distinguir honorários contratuais e sucumbenciais.</p>
    </section>
  </aside>
</div>
