<?php
$filesByDoc = [];
foreach ($files as $f) {
    $filesByDoc[(int) ($f['document_id'] ?? 0)][] = $f;
}
$in = array_filter($docs, fn($d) => in_array($d['status'], ['recebido', 'conferido'], true));
$out = array_filter($docs, fn($d) => in_array($d['status'], ['falta', 'pendencia', 'ajuda'], true) && $d['essential']);
$checks = case_checks((int) $c['id'], 'documentos');
$bankReq = case_data($c, 'bank_requests', []);
$reminders = (int) case_data($c, 'reminders', 0);
?>
<div class="grid-main-side">
  <div>
    <section class="box">
      <div class="box-head">
        <h2>Lista única de documentos</h2>
        <span class="sub"><?= count($in) ?> recebidos · <?= count($out) ?> essenciais pendentes</span>
      </div>
      <p class="hint">Peça apenas o que estiver ilegível, incompleto ou desatualizado, sempre com o motivo. Responda: “Recebemos X e Y; falta apenas Z”.</p>
      <div class="tbl-wrap">
        <table class="tbl doc-tbl">
          <thead><tr><th>Documento</th><th>Situação</th><th>Arquivos</th><th>Ação</th></tr></thead>
          <tbody>
            <?php foreach ($docs as $d): ?>
              <tr>
                <td><strong><?= e($d['label']) ?></strong><?= $d['essential'] ? ' <span class="tag tag-blue">essencial</span>' : '' ?><?php if ($d['note']): ?><br><small><?= e($d['note']) ?></small><?php endif ?><br><small class="muted">pedido <?= e(fmt_date($d['requested_at'])) ?><?= $d['received_at'] ? ' · recebido ' . e(fmt_date($d['received_at'])) : '' ?><?= $d['reviewed_at'] ? ' · conferido ' . e(fmt_date($d['reviewed_at'])) . ' por ' . e(first_name(user_name((int) $d['reviewed_by']))) : '' ?></small></td>
                <td><?= status_tag($d['status'], DOC_STATUS) ?></td>
                <td>
                  <?php foreach ($filesByDoc[(int) $d['id']] ?? [] as $f): ?>
                    <div class="file-line"><a href="<?= e(url('arquivo/' . $f['id'])) ?>" target="_blank"><?= e($f['original_name']) ?></a> <small><?= $f['uploaded_by_client'] ? 'cliente' : 'equipe' ?> · <?= e(fmt_date($f['uploaded_at'])) ?></small><?php if (!$f['reviewed_at']): ?> <span class="tag tag-amber">não conferido</span><?php endif ?></div>
                  <?php endforeach ?>
                </td>
                <td>
                  <?= case_form($c, 'doc_status', ['doc_id' => $d['id']], 'mini-form') ?>
                    <select name="status" aria-label="Situação"><?php foreach (DOC_STATUS as $k => $st): ?><option value="<?= $k ?>"<?= selected($d['status'], $k) ?>><?= e($st['label']) ?></option><?php endforeach ?></select>
                    <input name="note" placeholder="Motivo (ilegível, falta página…)" aria-label="Motivo">
                    <button class="b b-xs" type="submit">Salvar</button>
                  </form>
                  <?= case_form($c, 'doc_upload', ['doc_id' => $d['id']], 'mini-form', true) ?>
                    <input type="file" name="files[]" multiple accept="application/pdf,image/*" aria-label="Anexar arquivos">
                    <button class="b b-xs" type="submit">Anexar</button>
                  </form>
                </td>
              </tr>
            <?php endforeach ?>
          </tbody>
        </table>
      </div>
      <?php if (!empty($filesByDoc[0])): ?>
        <h3>Arquivos sem documento associado</h3>
        <?php foreach ($filesByDoc[0] as $f): ?><div class="file-line"><a href="<?= e(url('arquivo/' . $f['id'])) ?>" target="_blank"><?= e($f['original_name']) ?></a> <small><?= e(fmt_date($f['uploaded_at'])) ?></small></div><?php endforeach ?>
      <?php endif ?>
    </section>

    <section class="box">
      <h2>Adicionar à lista</h2>
      <?= case_form($c, 'doc_add', [], 'inline-form') ?>
        <label class="fld"><span>Tipo</span><select name="kind"><?php foreach (DOC_CATALOG as $k => $d): ?><option value="<?= $k ?>"><?= e($d['label']) ?></option><?php endforeach ?><option value="outro">Outro (descrever)</option></select></label>
        <label class="fld grow"><span>Descrição <small>(opcional para tipos do catálogo)</small></span><input name="label"></label>
        <label class="chk"><input type="checkbox" name="essential" value="1"> essencial</label>
        <button class="b" type="submit">Pedir</button>
      </form>
      <p class="hint">Não exija todos os acessórios para iniciar a análise: o advogado marca o que é indispensável para cada decisão. Documentos econômicos para justiça gratuita só quando esse pedido for considerado.</p>
    </section>
  </div>

  <aside>
    <section class="box">
      <h2>Condição para avançar</h2>
      <div class="cklist">
        <?= check_item($c, 'documentos', 'dossie_conferido', stage('documentos')['gates']['dossie_conferido']['label'], $checks['dossie_conferido'] ?? null, 'documentos', true) ?>
        <?= check_item($c, 'documentos', 'suficiencia', stage('documentos')['gates']['suficiencia']['label'], $checks['suficiencia'] ?? null, 'documentos', true) ?>
      </div>
      <?php if ($c['docs_sufficient_at']): ?><p class="ok-line">Suficiência registrada em <?= e(fmt_datetime($c['docs_sufficient_at'])) ?>. Meta da análise: <?= e(fmt_datetime(add_business_days(dt($c['docs_sufficient_at']), 2)->format('Y-m-d H:i:s'))) ?>.</p><?php endif ?>
    </section>

    <section class="box">
      <h2>Mensagem pronta</h2>
      <p class="quote-box small"><?= e(fill_template('documentos', $c)) ?></p>
      <a class="b b-xs" href="<?= e(url('painel/caso/' . $c['id'], ['aba' => 'mensagens', 'modelo' => 'documentos'])) ?>">Editar e enviar</a>
    </section>

    <section class="box">
      <h2>Se o cliente não tem o contrato</h2>
      <ol class="small-ol">
        <li>Orientar a busca no aplicativo, internet banking, e-mail da contratação ou concessionária.</li>
        <li>Pedir cópia integral ao credor por canal oficial, com demonstrativo do saldo, histórico, CET e documentos de seguro e tarifas.</li>
        <li>Registrar protocolo, data e prazo do banco. Cobrança automática 2 dias úteis após o prazo.</li>
        <li>Persistindo a falta, o advogado avalia ouvidoria, vias administrativas e pedido de exibição.</li>
        <li>SCR (Registrato) como apoio: o cliente acessa a própria conta e entrega o relatório, sem compartilhar senha.</li>
      </ol>
      <?php foreach ($bankReq as $r): ?><p class="file-line">Pedido em <?= e(fmt_date($r['requested_at'])) ?> · <?= e($r['channel'] ?: 'canal oficial') ?><?= $r['protocol'] ? ' · protocolo ' . e($r['protocol']) : '' ?><?= $r['bank_deadline'] ? ' · prazo ' . e(fmt_date($r['bank_deadline'])) : '' ?></p><?php endforeach ?>
      <?= case_form($c, 'pedido_banco', [], 'stack') ?>
        <div class="row2">
          <label class="fld"><span>Canal</span><input name="channel" placeholder="SAC, app, e-mail…"></label>
          <label class="fld"><span>Protocolo</span><input name="protocol"></label>
          <label class="fld"><span>Data do pedido</span><input type="date" name="requested_at" value="<?= e(now()->format('Y-m-d')) ?>"></label>
          <label class="fld"><span>Prazo do banco</span><input type="date" name="bank_deadline"></label>
        </div>
        <label class="fld"><span>Itens pedidos</span><input name="items" value="Cópia integral do contrato, demonstrativo do saldo, histórico de pagamentos, CET, seguro e tarifas"></label>
        <button class="b" type="submit">Registrar pedido ao banco</button>
      </form>
    </section>

    <section class="box">
      <h2>Lembretes ao cliente</h2>
      <p class="hint">Até dois lembretes respeitosos, em 2 e 5 dias úteis, salvo urgência. Nunca pausar medida com prazo em curso por rotina automática. Enviados até agora: <strong><?= $reminders ?></strong>.</p>
      <?= case_form($c, 'lembrete', [], 'inline-form') ?>
        <input name="note" placeholder="Observação (opcional)" aria-label="Observação">
        <button class="b b-xs" type="submit">Registrar lembrete</button>
      </form>
    </section>
  </aside>
</div>
