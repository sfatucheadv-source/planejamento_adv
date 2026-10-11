<?php
/**
 * Ficha única do atendimento inicial: cabeçalho fixo e cinco blocos
 * (Dados e contexto · Documentos · Análise e proposta · Reunião · Kit e decisão),
 * com a conversa ao lado. Qualquer bloco pode ser aberto em qualquer situação.
 */
$id = (int) $c['id'];
$st = $c['initial_status'];
$statusKeys = array_keys(INITIAL_STATUS);
$stIdx = array_search($st, $statusKeys, true);
$form = function (string $op, string $anchor = '', bool $multipart = false, string $class = 'stack') use ($id, $c): string {
    return '<form method="post" action="' . e(url('painel/atendimento/' . $id)) . '" class="' . $class . '"' . ($multipart ? ' enctype="multipart/form-data"' : '') . '>'
        . csrf_field() . '<input type="hidden" name="op" value="' . e($op) . '"><input type="hidden" name="row_version" value="' . (int) $c['row_version'] . '">'
        . ($anchor ? '<input type="hidden" name="anchor" value="' . e($anchor) . '">' : '');
};
$val = fn(string $k) => isset($fields[$k]) ? field_display($k, $fields[$k]) : '';
$src = function (string $k) use ($fields): string {
    $r = $fields[$k] ?? null;
    if (!$r) {
        return '<span class="src src-none">sem resposta</span>';
    }
    $cls = ['cliente' => 'src-cli', 'contrato' => 'src-ctr', 'sidney' => 'src-sid', 'migracao' => 'src-mig', 'anterior' => 'src-mig', 'ia' => 'src-ia'][$r['source']] ?? '';
    return '<span class="src ' . $cls . '" title="' . e(FIELD_SOURCES[$r['source']] ?? $r['source']) . '">' . e(['cliente' => 'cliente', 'contrato' => 'contrato', 'sidney' => 'Sidney', 'migracao' => 'cadastro antigo', 'anterior' => 'confirmar', 'ia' => 'IA · conferir'][$r['source']] ?? $r['source']) . '</span>';
};
$input = function (string $k, bool $withState = true) use ($fields): string {
    $def = field_def($k);
    $row = $fields[$k] ?? null;
    $v = field_input_value($k, $row);
    $name = 'f[' . $k . ']';
    $idAttr = 'fld-' . $k;
    $h = match ($def['type']) {
        'textarea' => '<textarea id="' . $idAttr . '" name="' . $name . '" rows="' . (($def['max'] ?? 300) > 1500 ? 4 : 2) . '">' . e($v) . '</textarea>',
        'choice' => '<select id="' . $idAttr . '" name="' . $name . '"><option value="">—</option>' . implode('', array_map(fn($o, $l) => '<option value="' . e($o) . '"' . selected($o, $v) . '>' . e($l) . '</option>', array_keys($def['options']), $def['options'])) . '</select>',
        'date' => '<input id="' . $idAttr . '" type="date" name="' . $name . '" value="' . e($v) . '">',
        'money', 'rate' => '<input id="' . $idAttr . '" type="text" inputmode="decimal" name="' . $name . '" value="' . e($v) . '"' . ($def['type'] === 'rate' ? ' placeholder="' . e($def['unit']) . '"' : ' placeholder="R$"') . '>',
        'int', 'cpf', 'cep', 'phone', 'renavam' => '<input id="' . $idAttr . '" type="text" inputmode="numeric" name="' . $name . '" value="' . e($v) . '">',
        'email' => '<input id="' . $idAttr . '" type="email" name="' . $name . '" value="' . e($v) . '">',
        default => '<input id="' . $idAttr . '" type="text" name="' . $name . '" value="' . e($v) . '">',
    };
    $state = $row['state'] ?? 'informado';
    $sel = $withState ? '<select class="st-sel" name="st[' . $k . ']" aria-label="Situação de ' . e($def['label']) . '">'
        . implode('', array_map(fn($o, $l) => '<option value="' . $o . '"' . selected($o, $state) . '>' . $l . '</option>', ['informado', 'desconhecido', 'depois', 'nao_se_aplica'], ['Informado', 'Não sabe', 'Depois', 'Não se aplica'])) . '</select>' : '';
    return '<label class="fld" for="' . $idAttr . '"><span>' . e($def['label']) . ' ' . (isset($def['unit']) ? '<small>(' . e($def['unit']) . ')</small> ' : '') . '</span>' . $h . '</label>' . ($sel ? '<div class="st-row">' . $sel . '</div>' : '');
};
$groupFields = [];
foreach (field_defs() as $k => $d) {
    $groupFields[$d['g']][] = $k;
}
$filesByDoc = [];
foreach ($files as $f) {
    $filesByDoc[(int) $f['document_id']][] = $f;
}
$internal = array_filter($files, fn($f) => (int) $f['internal'] === 1 || $f['kind'] === 'assinado');
$proposalVersions = array_filter($versions, fn($v) => $v['kind'] === 'proposta' && in_array($v['status'], ['revisado', 'publicado'], true));
$canMeet = $c['preparation_at'] && !$c['review_flag'] && in_array($st, ['analysis', 'meeting_scheduled', 'awaiting_decision'], true);
$calc = null;
$P = fv($id, 'capital') !== null ? ((int) fv($id, 'capital')) / 100 : null;
$N = fv($id, 'prazo') !== null ? (int) fv($id, 'prazo') : null;
$I = fv($id, 'taxa_mensal') !== null ? (float) fv($id, 'taxa_mensal') : null;
$PMT = fv($id, 'parcela') !== null ? ((int) fv($id, 'parcela')) / 100 : null;
$B = fv($id, 'bacen_taxa') !== null ? (float) fv($id, 'bacen_taxa') : null;
if ($P && $N) {
    $calc = [
        'pmt' => $I !== null ? price_installment($P, $I, $N) : null,
        'implied' => $PMT ? implied_monthly_rate($P, $PMT, $N) : null,
        'annual' => $I !== null ? annual_from_monthly($I) : null,
        'pmt_bcb' => $B !== null ? price_installment($P, $B, $N) : null,
    ];
}
?>
<div class="at-head" id="topo">
  <a class="g-back" href="<?= e(url('painel/atendimentos')) ?>">← Atendimentos</a>
  <div class="at-who">
    <h1><?= e($c['client_name']) ?>
      <?php if ($c['urgency_signal']): ?><span class="tag <?= $c['urgency_reviewed_at'] ? 'tag-amber' : 'tag-red' ?>"><?= e(URGENCY_SIGNALS[$c['urgency_signal']]) ?><?= $c['urgency_reviewed_at'] ? ' · conferida' : '' ?></span><?php endif ?>
    </h1>
    <p class="sub"><span class="mono"><?= e($c['protocol']) ?></span><?php if ($c['client_phone']): ?> · <?= e(format_phone((string) $c['client_phone'])) ?> · <a href="<?= e(whatsapp_link('', $c['client_phone'])) ?>" target="_blank" rel="noopener">abrir WhatsApp</a><?php endif ?><?= $c['bank'] ? ' · ' . e($c['bank']) : '' ?></p>
  </div>
  <div class="at-next-box">
    <span class="lbl">Próxima ação</span>
    <strong><?= e($c['next_action'] ?: 'Definir próxima ação') ?></strong>
    <small><?= $c['next_action_due'] ? 'até ' . e(fmt_when(dt($c['next_action_due']))) : 'sem data' ?><?= $c['next_client_update'] ? ' · retorno ao cliente ' . e(fmt_when(dt($c['next_client_update']))) : '' ?></small>
    <details class="at-inline">
      <summary>Alterar</summary>
      <?= $form('proxima', 'topo') ?>
        <label class="fld"><span>Próxima ação</span><input name="action" value="<?= e((string) $c['next_action']) ?>" required></label>
        <div class="row2">
          <label class="fld"><span>Até</span><input type="datetime-local" name="due" value="<?= e(dt_local($c['next_action_due'])) ?>" required></label>
          <label class="fld"><span>Retorno ao cliente</span><input type="datetime-local" name="client_return" value="<?= e(dt_local($c['next_client_update'])) ?>"></label>
        </div>
        <button class="b b-primary b-xs" type="submit">Salvar</button>
      </form>
    </details>
  </div>
</div>

<ol class="at-track" aria-label="Situação do atendimento">
  <?php foreach (INITIAL_STATUS as $k => $s): $i = array_search($k, $statusKeys, true); ?>
    <li class="<?= $i < $stIdx ? 'past' : ($i === $stIdx ? 'cur' : '') ?>"<?= $i === $stIdx ? ' aria-current="step"' : '' ?>><?= e($i === $stIdx ? initial_status_label($c) : $s['label']) ?></li>
  <?php endforeach ?>
</ol>

<?php if ($alerts): ?>
  <ul class="alerts alerts-inline">
    <?php foreach ($alerts as [$lvl, $txt]): ?><li class="alert <?= $lvl === 'high' ? 'alert-high' : '' ?>"><?= e($txt) ?></li><?php endforeach ?>
  </ul>
<?php endif ?>
<?php if ($dup): ?>
  <div class="warn-line">Possível duplicidade com <a href="<?= e(url('painel/atendimento/' . $dup['id'])) ?>"><?= e($dup['protocol']) ?> · <?= e($dup['client_name']) ?></a>. <?= $form('duplicidade_ok', 'topo', false, 'inline-form') ?><input name="note" placeholder="Decisão (ex.: outro assunto, mantidos separados)"><button class="b b-xs" type="submit">Conferido</button></form></div>
<?php endif ?>
<?php if ($prev): ?><p class="hint">Vinculado ao atendimento anterior <a href="<?= e(url('painel/atendimento/' . $prev['id'])) ?>"><?= e($prev['protocol']) ?></a>. Dados trazidos de lá estão marcados “confirmar”.</p><?php endif ?>
<?php if ($c['migrated_from_stage']): ?><p class="hint">Migrado do fluxo anterior (etapa “<?= e(stage_label($c['migrated_from_stage'])) ?>”). O histórico completo continua na <a href="<?= e(url('painel/caso/' . $id)) ?>?legado=1">ficha antiga</a>.</p><?php endif ?>

<nav class="at-blocks" aria-label="Blocos da ficha">
  <a href="#dados">1 · Dados e contexto</a><a href="#documentos">2 · Documentos</a><a href="#analise">3 · Análise e proposta</a><a href="#reuniao">4 · Reunião</a><a href="#kit">5 · Kit e decisão</a><a href="#conversa">Conversa</a>
</nav>

<div class="grid-main-side at-grid">
<div>

<!-- 1 · Dados e contexto -->
<section class="box at-block" id="dados" aria-labelledby="h-dados">
  <div class="box-head"><h2 id="h-dados">1 · Dados e contexto</h2><span class="hint">Coleta <?= $c['intake_done_at'] ? 'concluída pelo cliente em ' . e(fmt_date($c['intake_done_at'])) : 'em andamento' ?></span></div>

  <?php if ($c['urgency_signal']): ?>
    <div class="at-urg">
      <p><strong><?= e(URGENCY_SIGNALS[$c['urgency_signal']]) ?>:</strong> <?= e((string) $c['urgency_reason']) ?><?= $c['urgency_date'] ? ' · relatado para ' . e(fmt_date($c['urgency_date'])) : '' ?>. <span class="muted">Relato do cliente; prazo só existe depois de confirmado.</span></p>
      <?php if ($c['urgency_reviewed_at']): ?>
        <p class="ok-line">Conferida em <?= e(fmt_datetime($c['urgency_reviewed_at'])) ?>: <?= e((string) $c['urgency_opinion']) ?></p>
      <?php endif ?>
      <details <?= $c['urgency_reviewed_at'] ? '' : 'open' ?>>
        <summary>Registrar conferência da urgência</summary>
        <?= $form('urgencia_revisar', 'dados') ?>
          <label class="fld"><span>Parecer breve</span><textarea name="opinion" rows="2" required><?= e((string) $c['urgency_opinion']) ?></textarea></label>
          <label class="chk"><input type="checkbox" name="confirmed" value="1" <?= checked((bool) $c['urgent']) ?>> Urgência confirmada</label>
          <div class="row2">
            <label class="fld"><span>Prazo jurídico confirmado (opcional)</span><input name="deadline" placeholder="Ex.: contestação na busca e apreensão"></label>
            <label class="fld"><span>Vence em</span><input type="datetime-local" name="due_at"></label>
          </div>
          <button class="b b-primary b-xs" type="submit">Salvar conferência</button>
        </form>
      </details>
      <?php foreach ($deadlines as $d): ?>
        <div class="file-line"><span><?= $d['status'] === 'aberto' ? '⏱' : '✓' ?> <?= e($d['description']) ?> · <?= e(fmt_datetime($d['due_at'])) ?></span>
          <?php if ($d['status'] === 'aberto'): ?><?= $form('prazo_cumprido', 'dados', false, 'inline-form') ?><input type="hidden" name="deadline_id" value="<?= (int) $d['id'] ?>"><button class="b b-xs" type="submit">Cumprido</button></form><?php endif ?>
        </div>
      <?php endforeach ?>
    </div>
  <?php endif ?>

  <?php foreach (['abertura', 'urgencia', 'situacao', 'qualificacao', 'endereco', 'relacao', 'financiamento', 'veiculo', 'pagamentos', 'contexto', 'gratuidade', 'reuniao'] as $g):
    $keys = $groupFields[$g];
    $answered = count(array_filter($keys, fn($k) => isset($fields[$k])));
  ?>
    <details class="at-group" id="g-<?= e($g) ?>" <?= in_array($g, ['abertura', 'financiamento'], true) ? 'open' : '' ?>>
      <summary><strong><?= e(FIELD_GROUPS[$g]) ?></strong> <span class="muted"><?= $answered ?>/<?= count($keys) ?></span></summary>
      <dl class="at-dl">
        <?php foreach ($keys as $k): if (!isset($fields[$k])) { continue; } ?>
          <div><dt><?= e(field_def($k)['label']) ?></dt><dd><?= e($val($k)) ?> <?= $src($k) ?></dd></div>
        <?php endforeach ?>
      </dl>
      <?php $iaKeys = array_filter($keys, fn($k) => ($fields[$k]['source'] ?? '') === 'ia'); if ($iaKeys): ?>
        <?= $form('ia_conferir', 'g-' . $g, false, 'inline-form') ?><input type="hidden" name="group" value="<?= e($g) ?>"><button class="b b-xs" type="submit">Conferi no documento: confirmar <?= count($iaKeys) ?> dado(s) da IA</button></form>
      <?php endif ?>
      <details class="at-edit">
        <summary>Corrigir ou completar</summary>
        <?= $form('dados', 'g-' . $g) ?>
          <div class="at-fields">
            <?php foreach ($keys as $k): ?><div class="at-field"><?= $input($k) ?></div><?php endforeach ?>
          </div>
          <div class="inline-form">
            <label class="fld fld-s"><span>Origem desta informação</span>
              <select name="source"><option value="contrato">Conferido no contrato</option><option value="sidney" selected>Corrigido por Sidney</option><option value="cliente">Informado pelo cliente (por telefone/WhatsApp)</option></select>
            </label>
            <button class="b b-primary" type="submit">Salvar</button>
          </div>
          <p class="hint">Campo deixado em branco não apaga o que já existe. Mudança em dado usado na análise aprovada acende o aviso de revisão.</p>
        </form>
      </details>
    </details>
  <?php endforeach ?>
</section>

<!-- 2 · Documentos -->
<section class="box at-block" id="documentos" aria-labelledby="h-docs">
  <div class="box-head"><h2 id="h-docs">2 · Documentos e pendências</h2>
    <span class="hint"><?= $c['sufficiency_at'] ? 'Suficiência aprovada em ' . e(fmt_date($c['sufficiency_at'])) : 'Suficiência ainda não aprovada' ?></span></div>
  <div class="tbl-wrap">
    <table class="tbl tbl-compact at-docs">
      <thead><tr><th>Documento</th><th>Situação</th><th>Arquivos</th><th>Conferência</th></tr></thead>
      <tbody>
      <?php foreach ($docs as $d): $tone = DOC_STATUS[$d['status']]['tone'] ?? ''; ?>
        <tr id="doc-<?= (int) $d['id'] ?>">
          <td><strong><?= e($d['label']) ?></strong><br><span class="tag <?= $d['essential'] ? 'tag-red' : 'tag-gray' ?>"><?= $d['essential'] ? 'essencial' : 'pontual' ?></span><?= $d['origin'] && !in_array($d['origin'], ['checklist'], true) ? ' <small>' . e($d['origin']) . '</small>' : '' ?></td>
          <td><span class="tag tag-<?= ['ok' => 'green', 'warn' => 'amber', 'info' => 'blue', 'muted' => 'gray'][$tone] ?? 'gray' ?>"><?= e(DOC_STATUS[$d['status']]['label'] ?? $d['status']) ?></span><?= $d['reject_reason'] ? '<br><small>' . e($d['reject_reason']) . '</small>' : '' ?></td>
          <td><?php foreach ($filesByDoc[(int) $d['id']] ?? [] as $f): ?><a class="file-line" href="<?= e(url('arquivo/' . $f['id'])) ?>" target="_blank" rel="noopener"><?= e($f['original_name']) ?></a><small><?= $f['uploaded_by_client'] ? 'cliente' : 'Sidney' ?> · <?= e(fmt_date($f['uploaded_at'])) ?></small><br><?php endforeach ?>
            <?php if (llm_enabled() && !empty($filesByDoc[(int) $d['id']])): ?>
              <?= $form('ia_extrair', '', false, 'inline-form') ?><input type="hidden" name="doc_id" value="<?= (int) $d['id'] ?>"><button class="b b-xs" type="submit">Ler com IA</button></form>
            <?php endif ?>
            <details><summary class="small">Registrar arquivo obtido</summary>
              <?= $form('doc_upload', '', true) ?><input type="hidden" name="doc_id" value="<?= (int) $d['id'] ?>"><input type="file" name="arquivo[]" accept="application/pdf,image/jpeg,image/png" required><input name="origin" placeholder="Origem (ex.: enviado pelo banco)"><button class="b b-xs" type="submit">Registrar</button></form>
            </details>
          </td>
          <td>
            <?= $form('doc_conferir', '', false, 'mini-form') ?>
              <input type="hidden" name="doc_id" value="<?= (int) $d['id'] ?>">
              <select name="status" aria-label="Situação de <?= e($d['label']) ?>">
                <?php foreach (['conferido' => 'Conferido', 'pendencia' => 'Rejeitar · reenviar', 'falta' => 'Falta', 'ajuda' => 'Vamos ajudar a obter', 'dispensado' => 'Não se aplica'] as $k => $l): ?>
                  <option value="<?= $k ?>" <?= selected($k, $d['status'] === 'recebido' ? 'conferido' : $d['status']) ?>><?= $l ?></option>
                <?php endforeach ?>
              </select>
              <input name="reason" placeholder="Motivo, se rejeitar" value="<?= e((string) $d['reject_reason']) ?>">
              <label class="chk"><input type="checkbox" name="essential" value="1" <?= checked((bool) $d['essential']) ?>> essencial</label>
              <button class="b b-xs" type="submit">Salvar</button>
            </form>
          </td>
        </tr>
      <?php endforeach ?>
      </tbody>
    </table>
  </div>
  <details class="at-inline">
    <summary>Incluir documento no checklist</summary>
    <?= $form('doc_add', 'documentos', false, 'inline-form') ?>
      <select name="kind"><option value="">Outro (descreva)</option><?php foreach (DOC_CATALOG as $k => $d): ?><option value="<?= e($k) ?>"><?= e($d['label']) ?></option><?php endforeach ?></select>
      <input name="label" placeholder="Descrição, se “outro”">
      <label class="chk"><input type="checkbox" name="essential" value="1"> essencial</label>
      <button class="b b-xs" type="submit">Incluir</button>
    </form>
  </details>

  <h3>Pendências</h3>
  <?php $openItems = array_filter($pendings, fn($p) => $p['status'] === 'aberta'); ?>
  <?php if (!$open): ?><p class="empty">Nenhuma pendência aberta.</p><?php endif ?>
  <ul class="at-pend">
    <?php foreach ($open as $p): ?>
      <li><span class="tag <?= $p['essential'] ? 'tag-red' : 'tag-gray' ?>"><?= $p['essential'] ? 'essencial' : 'pontual' ?></span> <?= e(PENDING_TYPES[$p['type']] ?? $p['type']) ?>: <?= e($p['description']) ?>
        <?php if ($p['src'] === 'item'): ?>
          <?= $form('pendencia_status', 'documentos', false, 'inline-form') ?><input type="hidden" name="item_id" value="<?= $p['id'] ?>">
            <select name="status"><option value="resolvida">Resolvida</option><option value="nao_aplicavel">Não se aplica</option></select>
            <select name="criticality"><option value="">manter criticidade</option><option value="essencial">essencial</option><option value="pontual">pontual</option></select>
            <button class="b b-xs" type="submit">Salvar</button></form>
        <?php endif ?>
      </li>
    <?php endforeach ?>
  </ul>
  <details class="at-inline">
    <summary>Registrar pendência de campo ou esclarecimento</summary>
    <?= $form('pendencia_add', 'documentos', false, 'inline-form') ?>
      <select name="type"><?php foreach (PENDING_TYPES as $k => $l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach ?></select>
      <input name="description" placeholder="O que falta" required>
      <select name="criticality"><option value="pontual">pontual</option><option value="essencial">essencial</option></select>
      <button class="b b-xs" type="submit">Registrar</button>
    </form>
  </details>
  <details class="at-inline">
    <summary>Pedir complemento ao cliente</summary>
    <?= $form('pedir_complemento', 'documentos') ?>
      <?php foreach ($open as $p): ?>
        <label class="chk"><input type="checkbox" name="<?= $p['src'] === 'doc' ? 'docs[]' : 'items[]' ?>" value="<?= $p['id'] ?>" checked> <?= e($p['description']) ?></label>
      <?php endforeach ?>
      <label class="fld"><span>Outro item (opcional)</span><input name="extra" placeholder="Ex.: confirmar a data da notificação"></label>
      <div class="row2">
        <label class="fld"><span>Criticidade do outro item</span><select name="criticality"><option value="pontual">pontual</option><option value="essencial">essencial</option></select></label>
        <label class="fld"><span>Próximo retorno</span><input type="date" name="return_at" value="<?= e(substr(in_business_days(2), 0, 10)) ?>" required></label>
      </div>
      <p class="hint">A mensagem vai para a conversa do cliente: “Para continuar, falta apenas: [lista]. Você pode enviar aqui. Se não tiver algum item, me avise.”</p>
      <button class="b" type="submit">Enviar pedido</button>
    </form>
  </details>

  <?php if ($st === 'collecting'): $ess = essential_open($id); ?>
    <div class="at-gate">
      <h3>Suficiência</h3>
      <?php if ($ess): ?><p class="warn-line">Pendência essencial: <?= e(implode('; ', $ess)) ?>.</p><?php endif ?>
      <?= $form('liberar_analise', 'documentos') ?>
        <label class="fld"><span>Observação da conferência</span><input name="note" placeholder="Ex.: contrato legível, histórico até 08/2026"></label>
        <?php if ($ess): ?>
          <label class="fld"><span>Atuação urgente delimitada (só se for o caso)</span><textarea name="urgent_scope" rows="2" placeholder="O que será feito agora com os dados disponíveis e o que fica pendente"></textarea></label>
        <?php endif ?>
        <button class="b b-primary" type="submit">Aprovar suficiência e liberar análise</button>
      </form>
    </div>
  <?php elseif ($c['urgent_scope']): ?>
    <p class="hint">Atuação urgente delimitada: <?= e($c['urgent_scope']) ?></p>
  <?php endif ?>
</section>

<!-- 3 · Análise e proposta -->
<section class="box at-block" id="analise" aria-labelledby="h-an">
  <div class="box-head"><h2 id="h-an">3 · Análise e proposta</h2>
    <span class="hint"><?= $c['preparation_at'] ? 'Validada em ' . e(fmt_datetime($c['preparation_at'])) : 'Ainda não validada' ?></span></div>
  <?php if ($c['review_flag']): ?><p class="warn-line"><strong>Análise/kit precisa de revisão.</strong> Recalcule, corrija ou mantenha com justificativa e valide de novo. A proposta apresentada e os documentos assinados continuam nas versões originais.</p><?php endif ?>

  <div class="at-fin">
    <h3>Dados do financiamento usados</h3>
    <dl class="at-dl">
      <?php foreach (['banco', 'contrato_numero', 'contrato_data', 'capital', 'prazo', 'parcela', 'taxa_mensal', 'taxa_anual', 'cet', 'parcelas_pagas', 'saldo_cobrado', 'saldo_data'] as $k): ?>
        <div><dt><?= e(field_def($k)['label']) ?></dt><dd><?= $val($k) !== '' ? e($val($k)) . ' ' . $src($k) : '<span class="muted">—</span>' ?></dd></div>
      <?php endforeach ?>
    </dl>
    <p class="hint"><a href="#g-financiamento">Corrigir no bloco 1</a> indicando “conferido no contrato”.</p>
    <?php if ($calc): ?>
      <div class="g-calc">
        <strong>Calculadora de apoio (prestação fixa mensal regular)</strong>
        <ul class="small">
          <?php if ($calc['pmt'] !== null): ?><li>Parcela pela taxa contratada: <?= e(money($calc['pmt'])) ?><?= $PMT ? ' · informada ' . e(money($PMT)) : '' ?></li><?php endif ?>
          <?php if ($calc['implied'] !== null): ?><li>Taxa implícita na parcela informada: <?= e(pct($calc['implied'], 3)) ?> a.m.</li><?php endif ?>
          <?php if ($calc['annual'] !== null): ?><li>Taxa anual efetiva equivalente: <?= e(pct($calc['annual'])) ?> a.a. ((1+i)^12 − 1)</li><?php endif ?>
          <?php if ($calc['pmt_bcb'] !== null): ?><li>Parcela pela taxa média de referência: <?= e(money($calc['pmt_bcb'])) ?></li><?php endif ?>
        </ul>
        <p class="hint">Estimativa técnica: PMT = P × i / (1 − (1+i)^−n). Carência, atraso, renegociação ou parcelas irregulares exigem modelo próprio ou cálculo externo. CET não se compara com taxa de juros.</p>
      </div>
    <?php endif ?>
  </div>

  <?= $form('analise', 'analise') ?>
    <h3>Referência e parecer</h3>
    <div class="row3">
      <?php foreach (['data_base', 'bacen_codigo', 'bacen_periodo'] as $k): ?><div><?= $input($k, false) ?></div><?php endforeach ?>
    </div>
    <div class="row2"><div><?= $input('bacen_taxa', false) ?></div><div><?= $input('bacen_fonte', false) ?></div></div>
    <p class="hint">Série 25471 do BCB: taxa média mensal de crédito livre para pessoa física na aquisição de veículos (% a.m.). Use só quando a modalidade corresponder e guarde competência e data da consulta.</p>
    <?php foreach (['metodo', 'resultados', 'memoria', 'limitacoes'] as $k): ?><?= $input($k, false) ?><?php endforeach ?>
    <div class="row2"><div><?= $input('conclusao', false) ?></div><div><?= $input('revisao_data', false) ?></div></div>
    <?= $input('conclusao_texto', false) ?>
    <h3>Conferências de Sidney</h3>
    <div class="row2"><div><?= $input('conflito', false) ?></div><div><?= $input('legitimidade', false) ?></div></div>
    <?= $input('impedimento_motivo', false) ?>
    <h3>Proposta</h3>
    <?php foreach (['proposta_escopo', 'proposta_honorarios', 'proposta_base_exito', 'proposta_pagamento', 'proposta_despesas', 'proposta_riscos', 'proposta_exclusoes'] as $k): ?><?= $input($k, false) ?><?php endforeach ?>
    <div class="row2"><div><?= $input('proposta_validade', false) ?></div><div><?= $input('proposta_prazo_repasse', false) ?></div></div>
    <?php if (!isset($fields['proposta_prazo_repasse']) && setting('default_prazo_repasse')): ?><p class="hint">Em branco, o contrato usa o padrão do escritório: “<?= e(setting('default_prazo_repasse')) ?>”.</p><?php endif ?>
    <?= $input('procuracao_poderes', false) ?>
    <h3>Contrato de honorários e assinatura</h3>
    <?php $standing = financing_standing($id); ?>
    <div class="row2">
      <div><?= $input('contrato_situacao', false) ?><?php if (!isset($fields['contrato_situacao'])): ?><p class="hint"><?= $standing ? 'Em branco, vale o que o cliente informou: ' . e(mb_strtolower(FINANCING_STANDING[$standing])) . '.' : 'O que o cliente informou não basta para definir a situação.' ?></p><?php endif ?></div>
      <div><?= $input('foro', false) ?><?php if (!isset($fields['foro'])): ?><p class="hint"><?= setting('default_foro') ? 'Em branco, vale o padrão do escritório: ' . e(setting('default_foro')) . '.' : 'Sem padrão do escritório: defina em Variáveis.' ?> Independe da cidade do escritório.</p><?php endif ?></div>
    </div>
    <?= $input('processos_notificacoes', false) ?>
    <?php $relato = array_filter([isset($fields['aviso']) ? $val('aviso') : null, $val('aviso_data') ?: null, $val('aviso_descricao') ?: null, isset($fields['processo_existente']) ? 'processo sobre o financiamento: ' . mb_strtolower($val('processo_existente')) : null]); ?>
    <?php if ($relato): ?><p class="hint">Relato do cliente (confira antes de escrever no contrato): <?= e(implode(' · ', $relato)) ?></p><?php endif ?>
    <div class="row2"><div><?= $input('testemunha1_nome', false) ?></div><div><?= $input('testemunha1_cpf', false) ?></div></div>
    <div class="row2"><div><?= $input('testemunha2_nome', false) ?></div><div><?= $input('testemunha2_cpf', false) ?></div></div>
    <p class="hint">Testemunhas são opcionais, conforme a forma de assinatura. Os dados do veículo ficam no bloco 1, grupo Veículo.</p>
    <?php if ($customKeys = $groupFields['personalizadas'] ?? []): ?>
      <h3>Variáveis personalizadas</h3>
      <?php foreach ($customKeys as $k): $cv = custom_vars()[field_def($k)['var']] ?? null; ?>
        <?= $input($k, false) ?>
        <p class="hint"><code>{{<?= e(field_def($k)['var']) ?>}}</code><?= $cv && $cv['help'] ? ' · ' . e($cv['help']) : '' ?><?= $cv && $cv['value'] !== null && !isset($fields[$k]) ? ' · em branco, vale o padrão: “' . e(mb_strimwidth($cv['value'], 0, 120, '…')) . '”' : '' ?></p>
      <?php endforeach ?>
    <?php endif ?>
    <h3>Para a reunião e a inicial</h3>
    <?= $input('perguntas_reuniao', false) ?>
    <?= $input('encargos_discutidos', false) ?>
    <?= $input('obs_juridicas', false) ?>
    <div class="inline-form">
      <button class="b" type="submit">Salvar (preliminar)</button>
      <?php if (in_array($st, ['analysis', 'meeting_scheduled', 'awaiting_decision'], true)): ?>
        <button class="b b-primary" type="submit" name="op" value="aprovar_preparacao">Salvar e validar análise</button>
      <?php endif ?>
    </div>
    <p class="hint">Validar exige conclusão, resultados (ou análise limitada identificada), conferência de conflito e de titularidade, escopo, honorários, riscos e perguntas para a reunião. Impedimento fundamentado bloqueia a proposta definitiva.</p>
  </form>

  <h3>Arquivos internos (memória, planilhas)</h3>
  <?php foreach (array_filter($internal, fn($f) => $f['kind'] !== 'assinado') as $f): ?>
    <a class="file-line" href="<?= e(url('arquivo/' . $f['id'])) ?>" target="_blank" rel="noopener"><?= e($f['original_name']) ?></a> <small><?= e($f['kind'] ?: 'interno') ?> · <?= e(fmt_date($f['uploaded_at'])) ?> · não visível ao cliente</small><br>
  <?php endforeach ?>
  <?= $form('arquivo_interno', 'analise', true, 'inline-form') ?><input type="hidden" name="kind" value="memoria"><input type="file" name="arquivo[]" accept="application/pdf,image/jpeg,image/png" required><button class="b b-xs" type="submit">Anexar memória de cálculo</button></form>

  <?php if (in_array($st, ['meeting_scheduled', 'awaiting_decision'], true)): ?>
    <details class="at-inline"><summary>Reanalisar (mudança material)</summary>
      <?= $form('reanalisar', 'analise') ?>
        <label class="fld"><span>O que mudou e quais itens são afetados</span><textarea name="note" rows="2" required></textarea></label>
        <button class="b b-danger" type="submit">Voltar para análise</button>
      </form>
    </details>
  <?php endif ?>
</section>

<!-- 4 · Reunião -->
<section class="box at-block" id="reuniao" aria-labelledby="h-reu">
  <div class="box-head"><h2 id="h-reu">4 · Reunião</h2>
    <span class="hint">Preferência do cliente: <?= e($val('reuniao_canal') ?: 'não informada') ?><?= $val('reuniao_disponibilidade') ? ' · ' . e($val('reuniao_disponibilidade')) : '' ?></span></div>

  <?php if ($meeting): ?>
    <div class="at-meet">
      <strong><?= e(MEETING_CHANNELS[$meeting['channel']]['label']) ?></strong> · <?= e(brasilia($meeting['starts_at'])) ?> · <?= (int) $meeting['duration_min'] ?> min
      <?php if ($meeting['url']): ?><br><a href="<?= e($meeting['url']) ?>" target="_blank" rel="noopener"><?= e($meeting['url']) ?></a><?php endif ?>
      <?php if ($meeting['phone']): ?><br>Ligar para <?= e(format_phone($meeting['phone'])) ?><?php endif ?>
      <?php if ($meeting['note']): ?><br><small><?= e($meeting['note']) ?></small><?php endif ?>
    </div>
  <?php endif ?>

  <?php if ($val('perguntas_reuniao')): ?>
    <h3>Perguntas preparadas</h3>
    <ul class="small"><?php foreach (array_filter(array_map('trim', explode("\n", $val('perguntas_reuniao')))) as $qq): ?><li><?= e($qq) ?></li><?php endforeach ?></ul>
  <?php endif ?>

  <?php if ($canMeet): ?>
    <details class="at-inline" <?= !$meeting && $st === 'analysis' ? 'open' : '' ?>>
      <summary><?= $meeting ? 'Reagendar ou trocar o canal' : 'Confirmar reunião' ?></summary>
      <?= $form('confirmar_reuniao', 'reuniao') ?>
        <fieldset class="radios at-channels"><legend class="lbl">Canal</legend>
          <?php foreach (MEETING_CHANNELS as $k => $m): ?>
            <label class="chk"><input type="radio" name="channel" value="<?= $k ?>" <?= checked(($meeting['channel'] ?? fv($id, 'reuniao_canal') ?? 'meet') === $k) ?> required> <?= e($m['label']) ?></label>
          <?php endforeach ?>
        </fieldset>
        <div class="row3">
          <label class="fld"><span>Data e hora (Brasília)</span><input type="datetime-local" name="starts_at" value="<?= e(dt_local($meeting['starts_at'] ?? null)) ?>" required></label>
          <label class="fld"><span>Duração (min)</span><input type="number" name="duration" min="10" max="240" step="5" value="<?= (int) ($meeting['duration_min'] ?? 30) ?>"></label>
          <label class="fld"><span>Telefone (se ligação)</span><input name="phone" inputmode="tel" value="<?= e(format_phone((string) ($meeting['phone'] ?? $c['client_phone']))) ?>"></label>
        </div>
        <label class="fld"><span>Link do Google Meet (se vídeo; criado por você)</span><input name="url" type="url" placeholder="https://meet.google.com/abc-defg-hij" value="<?= e((string) ($meeting['url'] ?? '')) ?>"></label>
        <label class="fld"><span>Orientação ao cliente (opcional)</span><input name="note" placeholder="Ex.: tenha o contrato em mãos"></label>
        <p class="hint">O sistema confere conflito com outras reuniões suas. Nada é criado no Google automaticamente.</p>
        <button class="b b-primary" type="submit"><?= $meeting ? 'Reagendar' : 'Confirmar reunião' ?></button>
      </form>
    </details>
  <?php elseif (in_array($st, ['collecting', 'analysis'], true)): ?>
    <p class="hint">A reunião é confirmada depois de validar a análise e a proposta (bloco 3).</p>
  <?php endif ?>

  <?php if ($st === 'meeting_scheduled' && $meeting): ?>
    <details class="at-inline" open>
      <summary>Registrar reunião</summary>
      <?= $form('registrar_reuniao', 'reuniao') ?>
        <fieldset class="radios"><legend class="lbl">A reunião aconteceu?</legend>
          <label class="chk"><input type="radio" name="held" value="sim" checked data-toggle-show="held-yes"> Sim</label>
          <label class="chk"><input type="radio" name="held" value="nao" data-toggle-show="held-no"> Não aconteceu</label>
        </fieldset>
        <div data-show="held-yes">
          <label class="fld"><span>Canal efetivo</span><select name="effective_channel"><?php foreach (MEETING_CHANNELS as $k => $m): ?><option value="<?= $k ?>" <?= selected($k, $meeting['channel']) ?>><?= e($m['short']) ?></option><?php endforeach ?></select></label>
          <label class="fld"><span>Resumo escrito</span><textarea name="summary" rows="4"></textarea></label>
          <label class="chk"><input type="checkbox" name="confirmed_info" value="1"> Conferi com o cliente as informações da ficha</label>
          <div class="row2">
            <label class="fld"><span>Informações corrigidas</span><textarea name="corrections" rows="2"></textarea></label>
            <label class="fld"><span>Orientação dada</span><textarea name="guidance" rows="2"></textarea></label>
          </div>
          <div class="row2">
            <label class="fld"><span>Riscos explicados</span><textarea name="risks" rows="2"><?= e($val('proposta_riscos')) ?></textarea></label>
            <label class="fld"><span>Pendências pontuais restantes</span><textarea name="pending_left" rows="2"></textarea></label>
          </div>
          <div class="row2">
            <label class="fld"><span>Proposta apresentada</span><select name="proposal_version_id"><option value="">—</option><?php foreach ($proposalVersions as $v): ?><option value="<?= (int) $v['id'] ?>">Versão <?= (int) $v['version'] ?> (<?= e(KIT_STATES[$v['status']]) ?>)</option><?php endforeach ?></select></label>
            <label class="fld"><span>…ou motivo do adiamento da proposta</span><input name="proposal_postponed"></label>
          </div>
          <div class="row2">
            <label class="fld"><span>Reação do cliente</span><select name="reaction"><option value="pensar">Vai pensar</option><option value="aceitou">Aceitou (aceite preliminar)</option><option value="recusou">Recusou</option><option value="complemento">Precisa de complemento</option></select></label>
            <label class="chk at-mid"><input type="checkbox" name="material_change" value="1"> Surgiu mudança material (análise/kit precisam de revisão)</label>
          </div>
        </div>
        <div data-show="held-no" hidden>
          <label class="fld"><span>Motivo, se conhecido</span><input name="no_show_reason"></label>
        </div>
        <div class="row2">
          <label class="fld"><span>Próxima ação</span><input name="next_action" placeholder="Ex.: aguardar decisão do cliente"></label>
          <label class="fld"><span>Data de retorno</span><input type="date" name="return_at" value="<?= e(substr(in_business_days(2), 0, 10)) ?>"></label>
        </div>
        <button class="b b-primary" type="submit">Registrar reunião</button>
        <p class="hint">“Registrar reunião” não formaliza contratação. Ausência não vira recusa.</p>
      </form>
    </details>
  <?php endif ?>

  <?php if ($meetings): ?>
    <h3>Histórico de reuniões</h3>
    <ul class="small at-hist">
      <?php foreach ($meetings as $m): ?>
        <li><span class="tag <?= $m['status'] === 'realizada' ? 'tag-green' : ($m['status'] === 'confirmada' ? 'tag-blue' : 'tag-gray') ?>"><?= e(['confirmada' => 'confirmada', 'realizada' => 'realizada', 'nao_realizada' => 'não realizada', 'substituida' => 'substituída', 'cancelada' => 'cancelada'][$m['status']] ?? $m['status']) ?></span>
          <?= e(MEETING_CHANNELS[$m['channel']]['short']) ?> · <?= e(fmt_datetime($m['starts_at'])) ?>
          <?php if ($m['summary']): ?><br><span class="muted"><?= nl2br(e($m['summary'])) ?></span><?php endif ?>
          <?php if ($m['no_show_reason']): ?><br><span class="muted"><?= e($m['no_show_reason']) ?></span><?php endif ?>
        </li>
      <?php endforeach ?>
    </ul>
  <?php endif ?>
</section>

<!-- 5 · Kit e decisão -->
<section class="box at-block" id="kit" aria-labelledby="h-kit">
  <div class="box-head"><h2 id="h-kit">5 · Kit e decisão</h2>
    <span class="tag <?= $kit['ready'] ? 'tag-green' : 'tag-amber' ?>"><?= $kit['ready'] ? 'Kit pronto' : 'Kit em preparação' ?></span></div>

  <div class="at-kit">
    <?php foreach (KIT_ITEMS as $k => $meta): $it = $kit['items'][$k]; $v = $it['version']; $t = $meta['template'] ? doc_template($k) : null; $miss = $v ? (json_decode($v['missing'], true) ?: []) : []; ?>
      <div class="at-kit-item" id="kit-<?= e($k) ?>">
        <div class="at-kit-head">
          <strong><?= e($meta['label']) ?></strong>
          <span class="tag <?= ['revisado' => 'tag-green', 'publicado' => 'tag-blue', 'minuta' => 'tag-amber', 'nao_aplicavel' => 'tag-gray'][$it['state']] ?? '' ?>"><?= e(KIT_STATES[$it['state']]) ?><?= $v && $v['status'] !== 'nao_aplicavel' ? ' · v' . (int) $v['version'] : '' ?></span>
          <?php if ($t && !$t['approved_at']): ?><span class="tag tag-gray" title="Publicação bloqueada até a aprovação do modelo">modelo não aprovado</span><?php endif ?>
          <?php if ($v && $v['signed_file_id']): ?><span class="tag tag-green">assinado</span><?php endif ?>
        </div>
        <?php if ($it['stale']): ?><p class="warn-line">Há alterações ainda não revisadas: os dados mudaram depois desta versão. Gere uma nova versão.</p><?php endif ?>
        <?php if ($miss): ?><p class="err">Falta: <?= e(implode('; ', $miss)) ?></p><?php endif ?>
        <?php if ($v && $v['status'] === 'nao_aplicavel'): ?><p class="hint">Não se aplica: <?= e((string) $v['content']) ?></p><?php endif ?>
        <div class="inline-form">
          <?php if ($it['state'] !== 'publicado' || $it['stale']): ?>
            <?= $form('kit_gerar', '', false, 'inline-form') ?><input type="hidden" name="kind" value="<?= e($k) ?>"><button class="b b-xs" type="submit"><?= $v && $v['status'] !== 'nao_aplicavel' ? 'Gerar nova versão' : 'Gerar minuta' ?></button></form>
          <?php endif ?>
          <?php if ($v && $v['status'] !== 'nao_aplicavel'): ?>
            <a class="b b-xs" href="<?= e(url('painel/atendimento/' . $id . '/kit/' . $v['id'])) ?>" target="_blank" rel="noopener">Ver / imprimir</a>
            <a class="b b-xs" href="<?= e(url('painel/atendimento/' . $id . '/kit/' . $v['id'], ['baixar' => 1])) ?>">Baixar</a>
          <?php endif ?>
          <?php if ($v && $v['status'] === 'minuta'): ?>
            <?= $form('kit_revisar', '', false, 'inline-form') ?><input type="hidden" name="kind" value="<?= e($k) ?>"><input type="hidden" name="vid" value="<?= (int) $v['id'] ?>"><button class="b b-xs b-primary" type="submit" <?= $miss ? 'disabled' : '' ?>>Marcar revisado</button></form>
          <?php endif ?>
          <?php if ($v && $v['status'] === 'revisado' && $k !== 'ficha_inicial'): ?>
            <?= $form('kit_publicar', '', false, 'inline-form') ?><input type="hidden" name="kind" value="<?= e($k) ?>"><input type="hidden" name="vid" value="<?= (int) $v['id'] ?>"><button class="b b-xs b-primary" type="submit">Publicar ao cliente</button></form>
          <?php endif ?>
        </div>
        <?php if ($v && in_array($v['status'], ['minuta', 'revisado'], true)): ?>
          <details><summary class="small">Editar o texto desta versão</summary>
            <?= $form('kit_editar') ?><input type="hidden" name="kind" value="<?= e($k) ?>"><input type="hidden" name="vid" value="<?= (int) $v['id'] ?>">
              <textarea name="content" rows="14" class="at-doc-edit"><?= e((string) $v['content']) ?></textarea>
              <button class="b b-xs" type="submit">Salvar minuta</button>
            </form>
          </details>
        <?php endif ?>
        <?php if ($it['state'] !== 'publicado'): ?>
          <details><summary class="small">Não se aplica</summary>
            <?= $form('kit_na', '', false, 'inline-form') ?><input type="hidden" name="kind" value="<?= e($k) ?>"><input name="reason" placeholder="Motivo" required><button class="b b-xs" type="submit">Salvar</button></form>
          </details>
        <?php endif ?>
      </div>
    <?php endforeach ?>
  </div>
  <?php if (!$kit['ready'] && $st !== 'completed'): ?><p class="hint">Para o kit ficar pronto: <?= e(implode('; ', $kit['missing'])) ?>.</p><?php endif ?>

  <h3>Decisão do cliente</h3>
  <p><strong><?= e(DECISIONS[$c['decision']]) ?></strong><?= $c['decision_at'] ? ' · ' . e(fmt_datetime($c['decision_at'])) : '' ?><?= $c['decision_note'] ? ' · ' . e($c['decision_note']) : '' ?></p>
  <?php if ($st !== 'completed'): ?>
    <details class="at-inline" <?= $st === 'awaiting_decision' ? 'open' : '' ?>>
      <summary>Registrar decisão</summary>
      <?= $form('registrar_decisao', 'kit') ?>
        <div class="row2">
          <label class="fld"><span>Decisão</span><select name="decision"><?php foreach (DECISIONS as $k => $l): ?><option value="<?= $k ?>" <?= selected($k, $c['decision']) ?>><?= e($l) ?></option><?php endforeach ?></select></label>
          <label class="fld"><span>Observação</span><input name="note"></label>
        </div>
        <div class="row2">
          <label class="fld"><span>Próxima ação</span><input name="next_action" placeholder="Obrigatória se ainda pendente"></label>
          <label class="fld"><span>Retorno combinado</span><input type="date" name="return_at"></label>
        </div>
        <button class="b" type="submit">Salvar decisão</button>
        <p class="hint">Complemento é pendência, não decisão. Silêncio não é recusa.</p>
      </form>
    </details>
  <?php endif ?>

  <h3>Contratação</h3>
  <p><strong><?= e(CONTRACT_STATES[$c['contract_state']]) ?></strong><?= $c['contract_signed_at'] ? ' · assinado em ' . e(fmt_date($c['contract_signed_at'])) : '' ?><?= $c['contract_verified_at'] ? ' · conferido' : '' ?></p>
  <?php if ($c['contract_state'] !== 'formalizado'): ?>
    <details class="at-inline">
      <summary>Atualizar contratação</summary>
      <?= $form('contrato', 'kit', true) ?>
        <label class="fld fld-s"><span>Situação</span><select name="contract_state"><?php foreach (CONTRACT_STATES as $k => $l): ?><option value="<?= $k ?>" <?= selected($k, $c['contract_state']) ?>><?= e($l) ?></option><?php endforeach ?></select></label>
        <?php $signedFiles = array_filter($files, fn($f) => $f['kind'] === 'assinado'); ?>
        <div class="row2">
          <label class="fld"><span>Instrumento assinado</span><select name="signed_file_id"><option value="0">Anexar novo arquivo abaixo</option><?php foreach ($signedFiles as $f): ?><option value="<?= (int) $f['id'] ?>"><?= e($f['original_name']) ?> · <?= e(fmt_date($f['uploaded_at'])) ?></option><?php endforeach ?></select></label>
          <label class="fld"><span>Arquivo</span><input type="file" name="arquivo[]" accept="application/pdf,image/jpeg,image/png"></label>
        </div>
        <div class="row2">
          <label class="fld"><span>Data da assinatura</span><input type="date" name="signed_at"></label>
          <label class="chk at-mid"><input type="checkbox" name="verified" value="1"> Conferi assinatura e eficácia (versão exata)</label>
        </div>
        <label class="fld"><span>Observação</span><input name="note"></label>
        <button class="b" type="submit">Salvar</button>
        <p class="hint">Aceite sem assinatura não recebe o rótulo “contratado”. Não há integração de assinatura eletrônica: anexe o instrumento assinado.</p>
      </form>
    </details>
  <?php endif ?>

  <?php if ($st === 'awaiting_decision'): ?>
    <div class="at-gate">
      <h3>Finalizar atendimento inicial</h3>
      <?= $form('finalizar', 'kit') ?>
        <?php if ($c['decision'] === 'aceitou'): ?>
          <label class="fld fld-s"><span>A execução contratada começa por</span><select name="execution_stage"><option value="negociacao" <?= selected('negociacao', fv($id, 'conclusao') === 'revisao' ? '' : 'negociacao') ?>>Negociação</option><option value="preparacao_judicial" <?= selected('preparacao_judicial', fv($id, 'conclusao') === 'revisao' ? 'preparacao_judicial' : '') ?>>Preparação judicial</option></select></label>
        <?php endif ?>
        <label class="fld"><span><?= $c['decision'] === 'aceitou' ? 'Observação' : 'Orientação dada e motivo do encerramento' ?></span><textarea name="note" rows="2"></textarea></label>
        <button class="b b-primary" type="submit">Finalizar atendimento inicial</button>
        <p class="hint">“Concluído” encerra o atendimento inicial; não significa dívida resolvida. Com contratação formalizada, o caso segue para a execução.</p>
      </form>
    </div>
  <?php endif ?>

  <?php if (in_array($st, ['collecting', 'analysis'], true)): ?>
    <details class="at-inline">
      <summary>Encerrar antes da reunião</summary>
      <?= $form('encerrar_antes', 'kit') ?>
        <label class="fld fld-s"><span>Motivo</span><select name="decision"><option value="desistiu">Desistência</option><option value="recusou">Recusa</option><option value="nao_recomendado">Atuação não recomendada</option></select></label>
        <label class="fld"><span>Orientação dada ao cliente</span><textarea name="note" rows="2" required></textarea></label>
        <button class="b b-danger" type="submit">Encerrar atendimento inicial</button>
      </form>
    </details>
  <?php endif ?>

  <?php if ($st === 'completed'): ?>
    <div class="at-gate">
      <h3>Concluído</h3>
      <p><?= e((string) $c['closure_reason']) ?></p>
      <?php if ($c['execution_started_at']): ?>
        <p>Em execução: <a href="<?= e(url('painel/caso/' . $id)) ?>"><?= e(stage_label($c['stage'])) ?></a>.</p>
      <?php elseif (!$c['closure_communicated_at']): ?>
        <?= $form('comunicar_encerramento', 'kit') ?>
          <div class="row2">
            <label class="fld"><span>Comunicado por</span><input name="channel" placeholder="WhatsApp, e-mail, ligação, chat do site" required></label>
            <label class="fld"><span>Quando</span><input type="datetime-local" name="at" value="<?= e(dt_local(now_str())) ?>"></label>
          </div>
          <button class="b" type="submit">Registrar comunicação do encerramento</button>
          <p class="hint">O acesso deste atendimento na área do cliente fica disponível por <?= CLOSURE_GRACE_HOURS ?> horas a partir da comunicação. Nada é apagado.</p>
        </form>
      <?php else: ?>
        <p class="hint">Encerramento comunicado em <?= e(fmt_datetime($c['closure_communicated_at'])) ?> (<?= e((string) $c['closure_channel']) ?>). Acesso do cliente até <?= e(fmt_datetime($c['access_expires_at'])) ?><?= case_access_expired($c) ? ' (expirado)' : '' ?>.</p>
      <?php endif ?>
      <?php foreach ($next_cases as $n): ?><p>Novo atendimento vinculado: <a href="<?= e(url('painel/atendimento/' . $n['id'])) ?>"><?= e($n['protocol']) ?></a></p><?php endforeach ?>
      <?php if (!$c['execution_started_at']): ?>
        <details class="at-inline" <?= $c['return_requested_at'] ? 'open' : '' ?>>
          <summary>Abrir novo atendimento vinculado<?= $c['return_requested_at'] ? ' (cliente pediu em ' . e(fmt_date($c['return_requested_at'])) . ')' : '' ?></summary>
          <?= $form('novo_vinculado', 'kit') ?>
            <?php if (!$c['account_id']): ?><label class="chk"><input type="checkbox" name="confirm" value="1"> Verifiquei a identidade de quem pediu</label><?php endif ?>
            <p class="hint">O histórico anterior não é revelado ao cliente. Os dados reaproveitados precisam de confirmação de atualidade.</p>
            <button class="b" type="submit">Abrir novo atendimento</button>
          </form>
        </details>
      <?php endif ?>
    </div>
  <?php endif ?>

  <h3>Acesso do cliente</h3>
  <p class="small"><?= $c['account_id'] ? 'O cliente tem senha na área do cliente.' : 'O cliente ainda não criou senha.' ?>
    <?php if ($code): ?> Código gerado: <strong class="mono"><?= e($code) ?></strong> (protocolo <?= e($c['protocol']) ?>, válido por 7 dias).<?php endif ?></p>
  <?= $form('codigo_portal', 'kit', false, 'inline-form') ?><button class="b b-xs" type="submit">Gerar código de acesso</button></form>
</section>

<details class="box" id="historico">
  <summary><strong>Histórico interno</strong></summary>
  <ul class="tl">
    <?php foreach ($events as $ev): ?>
      <li><small><?= e(fmt_datetime($ev['at'])) ?><?= $ev['visible_to_client'] ? ' · visível ao cliente' : '' ?></small><br><strong><?= e($ev['title']) ?></strong><?= $ev['body'] ? '<br><span class="muted">' . nl2br(e($ev['body'])) . '</span>' : '' ?></li>
    <?php endforeach ?>
  </ul>
</details>
</div>

<!-- Conversa -->
<aside class="box at-chat" id="conversa" aria-labelledby="h-cv">
  <div class="box-head"><h2 id="h-cv">Conversa</h2>
    <span class="tag <?= $c['chat_mode'] === 'bot' ? 'tag-blue' : 'tag-green' ?>"><?= $c['chat_mode'] === 'bot' ? 'assistente coletando' : 'Sidney conversando' ?></span></div>
  <div class="cv-log" data-chat-log data-poll="<?= e(url('painel/atendimento/' . $id . '/conversa/novas')) ?>" data-last="<?= $chat ? (int) end($chat)['id'] : 0 ?>" aria-live="polite">
    <?php partial('chat_msgs', ['msgs' => $chat, 'operator' => true, 'c' => $c]) ?>
  </div>
  <?php if ($q): ?><p class="hint">Pergunta atual do assistente: “<?= e(mb_strimwidth((string) ($q['ask'] ?? ''), 0, 140, '…')) ?>”</p><?php endif ?>
  <?= $form('chat_enviar', 'conversa', false, 'stack cv-send') ?>
    <input type="hidden" name="k" value="<?= e(bin2hex(random_bytes(8))) ?>">
    <label class="fld"><span>Mensagem para o cliente</span><textarea name="message" rows="3" required></textarea></label>
    <button class="b b-primary" type="submit">Enviar</button>
  </form>
  <?= $form('chat_modo', 'conversa', false, 'inline-form') ?>
    <?php if ($c['chat_mode'] === 'bot'): ?>
      <input type="hidden" name="mode" value="human"><button class="b b-xs" type="submit">Assumir conversa</button><span class="hint">O assistente para de perguntar.</span>
    <?php else: ?>
      <input type="hidden" name="mode" value="bot"><button class="b b-xs" type="submit">Retomar coleta automática</button><span class="hint">O assistente pergunta só o que falta.</span>
    <?php endif ?>
  </form>
  <p class="hint">Mensagens externas (WhatsApp, e-mail) continuam sendo enviadas por você; abrir o WhatsApp não confirma entrega.</p>
</aside>
</div>
