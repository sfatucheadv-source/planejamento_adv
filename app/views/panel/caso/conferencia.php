<?php
$sol = case_data($c, 'solution', []);
$ck13 = case_checks((int) $c['id'], 'conferencia');
$ck14 = case_checks((int) $c['id'], 'encerramento');
$closing = case_data($c, 'closing', []);
$problems = $c['stage'] === 'encerramento' ? gate_problems($c) : [];
?>
<div class="grid-main-side">
  <div>
    <?= case_form($c, 'solucao', [], 'box form') ?>
      <h2>Etapa 13 · Conferência da solução na vida do cliente</h2>
      <p class="hint">Objetivo registrado: <strong><?= e($c['objective'] ?: '—') ?></strong>. Comparar o resultado contratado com o resultado entregue. Aplicar apenas os itens pertinentes. Financiamento que continua ativo normalmente conserva a garantia: não prometa baixa de gravame só porque a parcela foi reduzida.</p>
      <div class="tbl-wrap"><table class="tbl tbl-compact">
        <thead><tr><th>Aplica?</th><th>Solução</th><th>Evidência guardada</th><th>Conferido</th></tr></thead>
        <tbody>
          <?php foreach (SOLUTION_ITEMS as $k => $it): $row = $sol[$k] ?? []; ?>
            <tr class="<?= !empty($row['done']) ? 'row-ok' : '' ?>">
              <td><input type="checkbox" name="applies[<?= $k ?>]" value="1"<?= checked(!empty($row['applies'])) ?> aria-label="Aplica: <?= e($it['label']) ?>"></td>
              <td><strong><?= e($it['label']) ?></strong><br><small>Concluir quando: <?= e($it['done']) ?></small></td>
              <td><input name="evidence[<?= $k ?>]" value="<?= e($row['evidence'] ?? '') ?>" placeholder="<?= e($it['evidence']) ?>"></td>
              <td><input type="checkbox" name="done[<?= $k ?>]" value="1"<?= checked(!empty($row['done'])) ?> aria-label="Conferido"></td>
            </tr>
          <?php endforeach ?>
        </tbody>
      </table></div>
      <label class="fld"><span>Conferência do SCR (separado de cadastros privados; atualização mensal; não prometer apagar histórico verdadeiro)</span><textarea name="scr" rows="2"><?= e(case_data($c, 'scr', '')) ?></textarea></label>
      <label class="fld"><span>Teste prático com o cliente: recebeu o boleto correto? O pagamento foi reconhecido? O dinheiro entrou? Recebeu cobrança diferente?</span><textarea name="practical_test" rows="2"><?= e(case_data($c, 'practical_test', '')) ?></textarea></label>
      <div class="actions"><button class="b b-primary" type="submit">Salvar conferência</button></div>
      <p class="hint">Qualquer divergência reabre a obrigação correspondente na negociação ou no cumprimento. Não transferir ao cliente a tarefa de cobrar sozinho.</p>
    </form>

    <section class="box">
      <h2>Etapa 14 · Prestação de contas e encerramento</h2>
      <h3>Lista obrigatória para autorizar o fechamento</h3>
      <div class="cklist">
        <?php foreach (CLOSING_LIST as $k => $label): ?><?= check_item($c, 'encerramento', $k, $label, $ck14[$k] ?? null, 'conferencia', true) ?><?php endforeach ?>
      </div>
      <h3>Pacote final e acompanhamento</h3>
      <div class="cklist">
        <?php foreach (stage('encerramento')['steps'] as $k => $label): ?><?= check_item($c, 'encerramento', $k, $label, $ck14[$k] ?? null, 'conferencia') ?><?php endforeach ?>
      </div>

      <?php if ($c['status'] === 'ativo'): ?>
        <?php if ($c['stage'] !== 'encerramento'): ?>
          <p class="warn-line">Para encerrar, mova o caso até a etapa 14 (Encerramento) na aba Resumo.</p>
        <?php elseif ($problems): ?>
          <ul class="problems"><?php foreach ($problems as $p): ?><li class="<?= $p['block'] ? 'blk' : '' ?>"><?= e($p['label']) ?></li><?php endforeach ?></ul>
        <?php endif ?>
        <?= case_form($c, 'encerrar', [], 'stack') ?>
          <label class="fld"><span>Desfecho</span><select name="outcome"><option value="">—</option><?php foreach (OUTCOMES as $ok => $o): ?><option value="<?= $ok ?>"<?= selected($c['outcome'], $ok) ?>><?= e($o['label']) ?> — <?= e($o['text']) ?></option><?php endforeach ?></select></label>
          <label class="fld"><span>Resumo de uma página: problema inicial, caminho seguido, solução e efeito financeiro</span><textarea name="summary" rows="4"><?= e($closing['summary'] ?? '') ?></textarea></label>
          <label class="fld"><span>O que continua com o cliente (vencimentos, guarda de documentos, compromissos)</span><textarea name="client_duties" rows="2"><?= e($closing['client_duties'] ?? '') ?></textarea></label>
          <p class="hint">Em derrota definitiva: entregar saldo e riscos conhecidos, opções de negociação, consequências de inadimplemento e encaminhamentos. Não criar recursos sem fundamento nem adiar o encerramento para sustentar expectativa.</p>
          <button class="b b-primary" type="submit" data-confirm="Encerrar o caso? O sistema registra data, categoria do desfecho e responsável pela revisão final.">Encerrar caso (supervisor ou advogado)</button>
        </form>
      <?php else: ?>
        <p class="ok-line">Encerrado em <?= e(fmt_datetime($c['closed_at'])) ?> · <?= e(OUTCOMES[$c['outcome']]['label'] ?? '') ?><?= !empty($closing['by']) ? ' · revisão final: ' . e(user_name((int) $closing['by'])) : '' ?></p>
        <?php if (!empty($closing['summary'])): ?><div class="quote-box"><?= nl2p($closing['summary']) ?></div><?php endif ?>
      <?php endif ?>
    </section>
  </div>

  <aside>
    <section class="box">
      <h2>Condição da etapa 13</h2>
      <div class="cklist">
        <?= check_item($c, 'conferencia', 'checklist', stage('conferencia')['gates']['checklist']['label'], $ck13['checklist'] ?? null, 'conferencia', true) ?>
      </div>
      <p class="hint">O sistema marca este item quando todas as soluções aplicáveis têm evidência e conferência.</p>
    </section>

    <section class="box">
      <h2>Avaliação do cliente</h2>
      <?php if (!$feedback): ?><p class="empty">Ainda não respondida. O pedido aparece na área do cliente na etapa de encerramento.</p><?php endif ?>
      <?php foreach ($feedback as $f): ?>
        <p><strong><?= (int) $f['ease'] ?>/5</strong> · <?= e($f['kind'] === '30dias' ? 'contato de 30 dias' : 'encerramento') ?> · <?= e(fmt_date($f['created_at'])) ?><?= $f['simplify'] ? '<br>“' . e($f['simplify']) . '”' : '' ?></p>
      <?php endforeach ?>
      <p class="hint">A resposta mede esforço e melhoria do atendimento; não condiciona entrega ou uso de documentos.</p>
    </section>
  </aside>
</div>
