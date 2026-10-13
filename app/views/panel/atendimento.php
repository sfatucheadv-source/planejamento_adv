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
$proposalVersions = array_filter($versions, fn($v) => in_array($v['status'], ['revisado', 'publicado'], true));
$canMeet = in_array($st, ['collecting', 'analysis', 'meeting_scheduled', 'awaiting_decision'], true); // a análise pode ser feita até o horário
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

<?php $irmaos = client_sibling_cases($c); if (count($irmaos) > 1): ?>
  <nav class="at-irmaos" aria-label="Atendimentos deste cliente">
    <span class="lbl"><?= count($irmaos) ?> atendimentos deste cliente</span>
    <?php foreach ($irmaos as $ir): $on = (int) $ir['id'] === $id; ?>
      <a class="at-irmao <?= $on ? 'is-on' : '' ?>" href="<?= e(url('painel/atendimento/' . $ir['id'])) ?>"<?= $on ? ' aria-current="page"' : '' ?>>
        <strong><?= e(client_case_title($ir)) ?></strong>
        <small><?= e(client_case_meta($ir)) ?> · <?= e($ir['protocol']) ?> · <?= e(initial_status_label($ir)) ?><?= $ir['archived_at'] ? ' · arquivado' : '' ?></small>
      </a>
    <?php endforeach ?>
  </nav>
<?php endif ?>

<?php if ($c['archived_at']): ?><p class="warn-line">Atendimento arquivado em <?= e(fmt_datetime($c['archived_at'])) ?>: não aparece nas listas do escritório.</p><?php endif ?>

<?php
$crit = critical_status($id);
$critMiss = critical_missing($id);
$pct = agent_progress_pct($id);
$docsAtivos = array_filter($docs, fn($d) => $d['status'] !== 'dispensado');
$docsOk = count(array_filter($docsAtivos, fn($d) => in_array($d['status'], ['recebido', 'conferido'], true)));
$ctx = (array) case_data($c, 'contexto_ia', []);
$prop = proposal_get($id);
$abaN = ['resumo' => 'Resumo', 'dados' => 'Dados', 'documentos' => 'Documentos', 'analise' => 'Análise e proposta', 'reuniao' => 'Reunião', 'kit' => 'Kit e decisão', 'mais' => 'Arquivo e gestão'];
$abaBadge = ['resumo' => count($critMiss), 'documentos' => count($docsAtivos) - $docsOk];
?>
<nav class="fx-abas" data-ficha-abas aria-label="Áreas do atendimento">
  <?php foreach ($abaN as $k => $l): ?><a href="#<?= $k ?>" data-aba="<?= $k ?>"><?= e($l) ?><?php if (!empty($abaBadge[$k])): ?> <span class="fx-badge"><?= (int) $abaBadge[$k] ?></span><?php endif ?></a><?php endforeach ?>
</nav>

<div class="grid-main-side at-grid">
<div>

<!-- Resumo: painel do caso -->
<section class="fx-aba" id="resumo" data-aba-painel="resumo" aria-label="Resumo do caso">
  <div class="fx-kpis">
    <div class="fx-kpi"><span>Coleta</span><strong><?= $pct ?>%</strong><i class="fx-bar"><b style="width: <?= $pct ?>%"></b></i><small><?= $c['intake_done_at'] ? 'resumo confirmado pelo cliente' : ($pct >= MEETING_UNLOCK_PCT ? 'agendamento liberado' : 'em andamento') ?></small></div>
    <div class="fx-kpi"><span>Documentos</span><strong><?= $docsOk ?>/<?= count($docsAtivos) ?></strong><small><?= count($docsAtivos) - $docsOk ? (count($docsAtivos) - $docsOk) . ' faltando' : 'todos recebidos' ?></small></div>
    <div class="fx-kpi"><span>Reunião</span><strong><?= $meeting ? e(fmt_when(dt($meeting['starts_at']))) : '—' ?></strong><small><?= $meeting ? e(MEETING_CHANNELS[$meeting['channel']]['short']) : 'não marcada' ?></small></div>
    <div class="fx-kpi"><span>Proposta</span><strong><?= $prop ? e(brl(proposal_totals($prop['data'])['total'])) : '—' ?></strong><small><a href="<?= e(url('painel/propostas/' . $id)) ?>"><?= $prop ? 'ver proposta' : 'montar proposta' ?></a></small></div>
    <?php if ($cap = case_data($c, 'capacidade')): ?><div class="fx-kpi fx-cap-<?= e($cap['nivel']) ?>" title="<?= e((string) ($cap['obs'] ?? '')) ?>"><span>Capacidade</span><strong><?= e(CAPACITY_LEVELS[$cap['nivel']] ?? $cap['nivel']) ?></strong><small>anotação interna</small></div><?php endif ?>
  </div>

  <div class="fx-card">
    <div class="fx-card-head"><h2>Contexto do caso</h2>
      <?php if (llm_enabled()): ?><?= $form('contexto_ia', 'resumo', false, 'inline-form') ?><button class="b b-xs" type="submit"><?= $ctx ? 'Atualizar com IA' : 'Montar com IA' ?></button></form><?php endif ?></div>
    <p class="fx-ctx"><?= nl2br(e(!empty($ctx['contexto']) ? $ctx['contexto'] : case_story($id))) ?></p>
    <?php if (!empty($ctx['necessidades']) || !empty($ctx['relevante'])): ?>
      <div class="fx-2col">
        <?php if (!empty($ctx['necessidades'])): ?><div><h3>Principais necessidades</h3><ul><?php foreach ($ctx['necessidades'] as $n): ?><li><?= e($n) ?></li><?php endforeach ?></ul></div><?php endif ?>
        <?php if (!empty($ctx['relevante'])): ?><div><h3>Pontos relevantes</h3><ul><?php foreach ($ctx['relevante'] as $n): ?><li><?= e($n) ?></li><?php endforeach ?></ul></div><?php endif ?>
      </div>
    <?php endif ?>
  </div>

  <div class="fx-card" id="criticas">
    <div class="fx-card-head"><h2>Dados para a qualificação, a procuração e o contrato</h2><span class="hint"><?= $critMiss ? count($critMiss) . ' pendente(s)' : 'tudo informado' ?></span></div>
    <div class="fx-crit">
      <?php foreach ($crit as $g => $items): $faltam = array_filter($items, fn($it) => !$it['ok']); ?>
        <div class="fx-crit-g <?= $faltam ? 'is-falta' : 'is-ok' ?>">
          <h3><?= $faltam ? '•' : '✓' ?> <?= e($g) ?></h3>
          <ul><?php foreach ($items as $it): ?><li class="<?= $it['ok'] ? 'ok' : 'falta' ?>"><span><?= e($it['label']) ?></span><b><?= $it['ok'] ? e(field_display($it['key'], $it['row'])) . ' ' . $src($it['key']) : 'falta' ?></b></li><?php endforeach ?></ul>
        </div>
      <?php endforeach ?>
    </div>
    <?php if ($critMiss): $pergunta = critical_question($c, $critMiss); ?>
      <div class="fx-crit-acoes">
        <details class="fx-sanar">
          <summary class="b b-xs b-primary">Preencher agora</summary>
          <?= $form('dados', 'resumo') ?>
            <div class="at-fields"><?php foreach ($critMiss as $k): ?><div class="at-field"><?= $input($k, false) ?></div><?php endforeach ?></div>
            <div class="inline-form">
              <label class="fld fld-s"><span>Origem</span><select name="source"><option value="cliente">Cliente (telefone/WhatsApp)</option><option value="contrato">Conferido no documento</option><option value="sidney">Sidney</option></select></label>
              <button class="b b-primary" type="submit">Salvar</button>
            </div>
          </form>
        </details>
        <?php if ($c['client_phone']): ?><a class="b b-xs fx-wa" href="<?= e(whatsapp_link($pergunta, (string) $c['client_phone'])) ?>" target="_blank" rel="noopener">Perguntar pelo WhatsApp</a><?php endif ?>
        <?= $form('pedir_dados', 'resumo', false, 'inline-form') ?><?php foreach ($critMiss as $k): ?><input type="hidden" name="keys[]" value="<?= e($k) ?>"><?php endforeach ?><button class="b b-xs" type="submit">Perguntar na conversa do site</button></form>
      </div>
      <details class="fx-msg"><summary class="small">Ver a mensagem que vai para o cliente</summary><pre><?= e($pergunta) ?></pre></details>
    <?php endif ?>
  </div>

  <div class="fx-card">
    <div class="fx-card-head"><h2>Pendências</h2><a class="hint" href="#documentos">ver documentos</a></div>
    <?php if (!$open): ?><p class="empty">Nenhuma pendência aberta.</p><?php else: ?>
      <ul class="fx-pend"><?php foreach ($open as $p): ?><li><span class="tag <?= $p['essential'] ? 'tag-red' : 'tag-gray' ?>"><?= $p['essential'] ? 'exigido' : 'opcional' ?></span> <?= e($p['description']) ?></li><?php endforeach ?></ul>
    <?php endif ?>
  </div>
</section>

<!-- 1 · Dados e contexto -->
<section class="box at-block fx-aba" data-aba-painel="dados" id="dados" aria-labelledby="h-dados">
  <div class="box-head"><h2 id="h-dados">Dados e contexto</h2><span class="hint">Coleta <?= $c['intake_done_at'] ? 'concluída pelo cliente em ' . e(fmt_date($c['intake_done_at'])) : 'em andamento' ?></span></div>

  <?php if ($cap = case_data($c, 'capacidade')): ?>
    <p class="at-cap at-cap-<?= e($cap['nivel']) ?>" title="Anotação interna do assistente; o cliente não vê"><span>Capacidade de contratação</span> <strong><?= e(CAPACITY_LEVELS[$cap['nivel']] ?? $cap['nivel']) ?></strong><?= $cap['obs'] ? ' <small>' . e($cap['obs']) . '</small>' : '' ?></p>
  <?php endif ?>
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

  <?php foreach (array_merge(['abertura', 'urgencia', 'situacao', 'qualificacao', 'endereco', 'relacao', 'financiamento', 'veiculo', 'pagamentos', 'contexto', 'gratuidade', 'reuniao'], !empty($groupFields['personalizadas']) ? ['personalizadas'] : []) as $g):
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
<section class="box at-block fx-aba" data-aba-painel="documentos" id="documentos" aria-labelledby="h-docs">
  <div class="box-head"><h2 id="h-docs">Documentos</h2>
    <span class="hint"><?= $c['sufficiency_at'] ? 'Suficiência aprovada em ' . e(fmt_date($c['sufficiency_at'])) : 'Suficiência ainda não aprovada' ?></span></div>
  <ul class="fd-lista">
    <?php foreach ($docsAtivos as $d): $arqs = $filesByDoc[(int) $d['id']] ?? []; $ok = in_array($d['status'], ['recebido', 'conferido'], true); $tone = DOC_STATUS[$d['status']]['tone'] ?? ''; ?>
      <li class="fd-item is-<?= $ok ? 'ok' : ($d['status'] === 'pendencia' ? 'reenviar' : 'falta') ?>" id="doc-<?= (int) $d['id'] ?>">
        <span class="fd-ico" aria-hidden="true"><?= $ok ? '✓' : '•' ?></span>
        <div class="fd-nome">
          <strong><?= e($d['label']) ?></strong>
          <small>
            <span class="tag tag-<?= ['ok' => 'green', 'warn' => 'amber', 'info' => 'blue', 'muted' => 'gray'][$tone] ?? 'gray' ?>"><?= e(DOC_STATUS[$d['status']]['label'] ?? $d['status']) ?></span>
            <?= $d['reject_reason'] ? e($d['reject_reason']) . ' · ' : '' ?>
            <?php foreach ($arqs as $f): ?><a href="<?= e(url('arquivo/' . $f['id'])) ?>" target="_blank" rel="noopener"><?= e(mb_strimwidth($f['original_name'], 0, 40, '…')) ?></a> <span class="muted"><?= $f['uploaded_by_client'] ? 'cliente' : 'Sidney' ?> · <?= e(fmt_date($f['uploaded_at'])) ?></span> <?php endforeach ?>
          </small>
        </div>
        <div class="fd-acoes">
          <?= $form('doc_exigir', 'documentos', false, 'inline-form') ?><input type="hidden" name="doc_id" value="<?= (int) $d['id'] ?>"><input type="hidden" name="essential" value="<?= $d['essential'] ? '0' : '1' ?>"><button class="fd-exige <?= $d['essential'] ? 'is-on' : '' ?>" type="submit" title="<?= $d['essential'] ? 'Exigido: clique para tornar opcional' : 'Opcional: clique para exigir' ?>"><?= $d['essential'] ? 'Exigido' : 'Opcional' ?></button></form>
          <?php if ($d['status'] === 'recebido'): ?>
            <?= $form('doc_conferir', '', false, 'inline-form') ?><input type="hidden" name="doc_id" value="<?= (int) $d['id'] ?>"><input type="hidden" name="status" value="conferido"><input type="hidden" name="essential" value="<?= (int) $d['essential'] ?>"><button class="b b-xs b-primary" type="submit">Conferido</button></form>
          <?php endif ?>
          <?php if (llm_enabled() && $arqs): ?>
            <?= $form('ia_extrair', '', false, 'inline-form') ?><input type="hidden" name="doc_id" value="<?= (int) $d['id'] ?>"><button class="b b-xs" type="submit" title="Ler de novo e preencher a ficha">Ler com IA</button></form>
          <?php endif ?>
          <details class="fd-mais">
            <summary class="b b-xs" aria-label="Mais ações">⋯</summary>
            <div class="fd-menu">
              <?= $form('doc_upload', '', true) ?><input type="hidden" name="doc_id" value="<?= (int) $d['id'] ?>"><label class="fld"><span>Anexar arquivo obtido</span><input type="file" name="arquivo[]" accept="application/pdf,image/jpeg,image/png" required></label><input name="origin" placeholder="Origem (ex.: enviado pelo banco)"><button class="b b-xs" type="submit">Anexar</button></form>
              <?php if ($arqs): ?>
                <?= $form('doc_conferir', '') ?><input type="hidden" name="doc_id" value="<?= (int) $d['id'] ?>"><input type="hidden" name="status" value="pendencia"><input type="hidden" name="essential" value="<?= (int) $d['essential'] ?>"><label class="fld"><span>Pedir de novo ao cliente</span><input name="reason" placeholder="Por quê? (ex.: foto cortada)" required></label><button class="b b-xs" type="submit">Pedir reenvio</button></form>
              <?php endif ?>
              <?= $form('doc_conferir', 'documentos', false, 'inline-form') ?><input type="hidden" name="doc_id" value="<?= (int) $d['id'] ?>"><input type="hidden" name="status" value="dispensado"><input type="hidden" name="essential" value="0"><button class="b b-xs b-danger" type="submit">Remover do checklist</button></form>
            </div>
          </details>
        </div>
      </li>
    <?php endforeach ?>
  </ul>
  <?php $removidos = array_filter($docs, fn($d) => $d['status'] === 'dispensado'); if ($removidos): ?>
    <details class="at-inline"><summary>Removidos do checklist (<?= count($removidos) ?>)</summary>
      <?php foreach ($removidos as $d): ?>
        <?= $form('doc_conferir', 'documentos', false, 'inline-form') ?><input type="hidden" name="doc_id" value="<?= (int) $d['id'] ?>"><input type="hidden" name="status" value="falta"><input type="hidden" name="essential" value="0"><span><?= e($d['label']) ?></span> <button class="b b-xs" type="submit">Voltar a pedir</button></form>
      <?php endforeach ?>
    </details>
  <?php endif ?>
  <?= $form('doc_add', 'documentos', false, 'inline-form fd-add') ?>
    <select name="kind" aria-label="Documento a incluir"><option value="">Incluir documento…</option><?php foreach (DOC_CATALOG as $k => $dc): if (array_filter($docs, fn($x) => $x['kind'] === $k && $x['status'] !== 'dispensado')) { continue; } ?><option value="<?= e($k) ?>"><?= e($dc['label']) ?></option><?php endforeach ?><option value="">Outro (descreva ao lado)</option></select>
    <input name="label" placeholder="Descrição, se for outro">
    <label class="chk"><input type="checkbox" name="essential" value="1"> exigido</label>
    <button class="b b-xs" type="submit">Incluir</button>
  </form>

  <?php $openItems = array_filter($open, fn($p) => $p['src'] === 'item'); ?>
  <?php if ($openItems): ?><h3>Outras pendências</h3><?php endif ?>
  <ul class="at-pend">
    <?php foreach ($openItems as $p): ?>
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
<section class="box at-block fx-aba" data-aba-painel="analise" id="analise" aria-labelledby="h-an">
  <div class="box-head"><h2 id="h-an">Análise e proposta</h2>
    <span class="hint"><?= $c['preparation_at'] ? 'Validada em ' . e(fmt_datetime($c['preparation_at'])) : 'Ainda não validada' ?></span></div>
  <?php if ($c['review_flag']): ?><p class="warn-line"><strong>Análise/kit precisa de revisão.</strong> Recalcule, corrija ou mantenha com justificativa e valide de novo. A proposta apresentada e os documentos assinados continuam nas versões originais.</p><?php endif ?>

  <div class="fx-fin">
    <?php $finK = ['banco', 'capital', 'prazo', 'parcela', 'taxa_mensal', 'cet', 'parcelas_pagas', 'saldo_cobrado']; ?>
    <dl class="fx-fin-dl"><?php foreach ($finK as $k): ?><div><dt><?= e(field_def($k)['label']) ?></dt><dd><?= $val($k) !== '' ? e($val($k)) : '<span class="muted">—</span>' ?></dd></div><?php endforeach ?></dl>
    <p class="hint">Vêm da conversa e da leitura do contrato. <a href="#dados">Corrigir em Dados</a>.</p>
    <?php if ($calc): ?>
      <details class="fx-calc"><summary>Calculadora de apoio</summary>
        <ul class="small">
          <?php if ($calc['pmt'] !== null): ?><li>Parcela pela taxa contratada: <?= e(money($calc['pmt'])) ?><?= $PMT ? ' · informada ' . e(money($PMT)) : '' ?></li><?php endif ?>
          <?php if ($calc['implied'] !== null): ?><li>Taxa implícita na parcela informada: <?= e(pct($calc['implied'], 3)) ?> a.m.</li><?php endif ?>
          <?php if ($calc['annual'] !== null): ?><li>Taxa anual efetiva equivalente: <?= e(pct($calc['annual'])) ?> a.a.</li><?php endif ?>
          <?php if ($calc['pmt_bcb'] !== null): ?><li>Parcela pela taxa média de referência: <?= e(money($calc['pmt_bcb'])) ?></li><?php endif ?>
        </ul>
        <p class="hint">Estimativa técnica: PMT = P × i / (1 − (1+i)^−n). Carência, atraso ou parcelas irregulares exigem cálculo próprio. CET não se compara com taxa de juros.</p>
      </details>
    <?php endif ?>
  </div>

  <?php
    $parK = array_merge(['data_base', 'bacen_codigo', 'bacen_periodo', 'bacen_taxa', 'bacen_fonte'], PARECER_FIELDS);
    $find = array_filter((array) case_data($c, 'parecer_localizar', []), fn($k) => !isset($fields[$k]), ARRAY_FILTER_USE_KEY);
    $parOk = count(array_filter($parK, fn($k) => isset($fields[$k])));
    $parAt = case_data($c, 'parecer_at');
  ?>
  <div class="fx-card fx-parecer" id="parecer-ia">
    <div class="fx-card-head"><h2>Parecer de cálculos</h2><span class="hint"><?= $parOk ?>/<?= count($parK) ?> preenchidos<?= $parAt ? ' · IA em ' . e(fmt_datetime((string) $parAt)) : '' ?></span></div>
    <p class="hint">Tudo vem da IA: taxa média do site do Banco Central (série <?= BCB_SERIE ?>, mês da contratação), contrato e documentos anexos e o que o cliente informou. Confira; para corrigir, edite o campo ou peça à IA abaixo.</p>
    <dl class="fx-par">
      <?php foreach ($parK as $k): $def = field_def($k); if (!$def) { continue; } $has = isset($fields[$k]); ?>
        <div class="fx-par-item <?= $has ? '' : 'is-falta' ?>" id="par-<?= e($k) ?>">
          <dt><?= e($def['label']) ?> <?= $has ? $src($k) : '' ?></dt>
          <dd>
            <?php if ($has): ?><span class="fx-par-v"><?= nl2br(e($val($k))) ?></span>
            <?php else: ?><span class="fx-par-falta">A localizar<?= isset($find[$k]) ? ': ' . e($find[$k]) : '' ?></span><?php endif ?>
            <details class="fx-par-ed"><summary>editar</summary>
              <?= $form('dados', 'par-' . $k) ?><?= $input($k, false) ?><input type="hidden" name="source" value="sidney"><button class="b b-xs" type="submit">Salvar</button></form>
            </details>
          </dd>
        </div>
      <?php endforeach ?>
    </dl>
    <div class="fx-par-acoes">
      <?= $form('parecer_ia', 'parecer-ia', false, 'inline-form') ?><button class="b b-xs b-primary" type="submit"><?= $parAt ? 'Buscar de novo o que falta' : 'Preencher com IA' ?></button></form>
      <?php if ($find): ?><details class="fx-msg"><summary class="small">Texto pronto para pesquisar o que falta em outra IA</summary><pre><?= e(parecer_find_prompt($c, $find)) ?></pre></details><?php endif ?>
    </div>
    <?php if (llm_enabled()): ?>
      <?= $form('parecer_comando', 'parecer-ia', false, 'stack fx-cmd') ?>
        <label class="fld"><span>Pedir uma correção à IA</span><textarea name="comando" rows="2" maxlength="1500" placeholder="Ex.: a taxa mensal correta é 2,15% (cláusula 4 do contrato); refaça os resultados e a conclusão." required></textarea></label>
        <button class="b b-xs" type="submit">Aplicar com IA</button>
      </form>
    <?php endif ?>
  </div>

  <?= $form('aprovar_preparacao', 'analise', false, 'inline-form fx-salvar') ?>
    <?php if (in_array($st, ['analysis', 'meeting_scheduled', 'awaiting_decision'], true)): ?>
      <button class="b b-primary" type="submit">Conferi: validar a análise</button>
      <span class="hint">Exige a conclusão e os resultados do parecer e a proposta registrada em <a href="<?= e(url('painel/propostas/' . $id)) ?>">Propostas</a>.</span>
    <?php else: ?>
      <span class="hint">A validação da análise fica disponível depois da suficiência dos documentos.</span>
    <?php endif ?>
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
<section class="box at-block fx-aba" data-aba-painel="reuniao" id="reuniao" aria-labelledby="h-reu">
  <div class="box-head"><h2 id="h-reu">Reunião</h2>
    <span class="hint">Preferência do cliente: <?= e($val('reuniao_canal') ?: 'não informada') ?><?= $val('reuniao_disponibilidade') ? ' · ' . e($val('reuniao_disponibilidade')) : '' ?></span></div>

  <?php if ($qm = qualif_missing($id)): ?>
    <div class="fx-lembrete"><strong>Lembrete para a conversa:</strong> perguntar <?= e(implode(', ', qualif_labels($qm))) ?>. <span class="hint">O assistente perguntou uma vez e não insistiu. Registre as respostas no <a href="#criticas">Resumo</a>.</span></div>
  <?php endif ?>
  <?php if ($meeting): $tf = $meeting['transcript_file_id'] ? one('SELECT * FROM files WHERE id = ?', [$meeting['transcript_file_id']]) : null;
      $mStart = dt($meeting['starts_at']); $mEnd = $mStart->modify('+' . (int) $meeting['duration_min'] . ' minutes'); ?>
    <div class="reu-card">
      <div class="reu-card-head">
        <div>
          <span class="reu-quando"><?= e(ucfirst(SEMANA[(int) $mStart->format('w')])) ?>, <?= e($mStart->format('d/m/Y')) ?> · <?= e($mStart->format('H\hi')) ?>–<?= e($mEnd->format('H\hi')) ?></span>
          <span class="hint"><?= e(MEETING_CHANNELS[$meeting['channel']]['label']) ?> · <?= (int) $meeting['duration_min'] ?> min · <?= e(['coleta' => 'escolhido pelo cliente', 'assistente' => 'marcado pelo assistente', 'painel' => 'confirmado por você'][$meeting['origin'] ?? ''] ?? 'confirmado') ?> em <?= e(fmt_datetime($meeting['confirmed_at'])) ?><?= $mStart < now() ? ' · já começou' : ' · ' . e(fmt_when($mStart)) ?></span>
        </div>
        <?php if ($meeting['url']): ?><a class="b b-primary" href="<?= e($meeting['url']) ?>" target="_blank" rel="noopener">Entrar no Meet</a><?php endif ?>
      </div>
      <ul class="reu-status">
        <?php if ($meeting['channel'] === 'meet'): ?>
          <li class="<?= $meeting['url'] ? 'ok' : 'warn' ?>"><strong>Google Meet:</strong>
            <?php if ($meeting['url']): ?><a href="<?= e($meeting['url']) ?>" target="_blank" rel="noopener"><?= e($meeting['url']) ?></a><?php else: ?><?= e($meeting['google_error'] ?: 'link ainda não criado') ?><?php endif ?></li>
          <li class="<?= $meeting['google_event_id'] ? 'ok' : 'warn' ?>"><strong>Sua agenda:</strong> <?= $meeting['google_event_id'] ? 'horário bloqueado no Google Agenda, com convite para o cliente' : 'compromisso não criado no Google Agenda' ?>
            <?php if (!$meeting['google_event_id'] || !$meeting['url']): ?>
              <?= $form('reuniao_google', 'reuniao', false, 'inline') ?><input type="hidden" name="meeting_id" value="<?= (int) $meeting['id'] ?>"><button class="b b-sm" type="submit">Criar no Google agora</button></form>
            <?php endif ?></li>
        <?php elseif ($meeting['phone']): ?>
          <li class="ok"><strong>Ligação:</strong> <?= e(format_phone((string) $meeting['phone'])) ?></li>
        <?php endif ?>
        <li class="<?= $meeting['client_mail_at'] ? 'ok' : 'warn' ?>"><strong>E-mail ao cliente:</strong> <?= $meeting['client_mail_at'] ? 'confirmação enviada em ' . e(fmt_datetime($meeting['client_mail_at'])) : (filter_var((string) $c['client_email'], FILTER_VALIDATE_EMAIL) ? 'ainda não enviado' : 'cliente sem e-mail') ?>
          <?php if (filter_var((string) $c['client_email'], FILTER_VALIDATE_EMAIL)): ?>
            <?= $form('reuniao_email', 'reuniao', false, 'inline') ?><input type="hidden" name="meeting_id" value="<?= (int) $meeting['id'] ?>"><button class="b b-sm" type="submit"><?= $meeting['client_mail_at'] ? 'Reenviar' : 'Enviar agora' ?></button></form>
          <?php endif ?></li>
        <?php if ($meeting['channel'] === 'meet'): ?>
          <li class="<?= $tf ? 'ok' : 'info' ?>"><strong>Transcrição:</strong>
            <?php if ($tf): ?><a href="<?= e(url('arquivo/' . $tf['id'])) ?>" target="_blank" rel="noopener">salva em <?= e(fmt_datetime($meeting['transcript_at'])) ?></a><?= $tf['drive_id'] ? ' · também no Drive' : '' ?>
            <?php else: ?><?= e($meeting['transcript_note'] ?: 'será procurada no Google depois da reunião') ?><?= $meeting['transcript_checked_at'] ? ' · última busca ' . e(fmt_datetime($meeting['transcript_checked_at'])) : '' ?>
              <?php if ($mStart < now() && $meeting['url']): ?><?= $form('reuniao_transcricao', 'reuniao', false, 'inline') ?><input type="hidden" name="meeting_id" value="<?= (int) $meeting['id'] ?>"><button class="b b-sm" type="submit">Buscar agora</button></form><?php endif ?>
            <?php endif ?></li>
        <?php endif ?>
        <li class="info"><strong>Cliente:</strong> <?= e($c['client_name']) ?> · <?= e(format_phone((string) $c['client_phone'])) ?><?= $c['client_email'] ? ' · ' . e($c['client_email']) : '' ?></li>
        <?php if (!$c['preparation_at']): ?><li class="info"><strong>Análise:</strong> ainda pendente; você pode concluir até o horário ou durante a conversa.</li><?php endif ?>
      </ul>
      <?php if ($meeting['note']): ?><p class="small muted"><?= e($meeting['note']) ?></p><?php endif ?>
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
            <label class="chk"><input type="radio" name="channel" value="<?= $k ?>" <?= checked(($meeting['channel'] ?? 'meet') === $k) ?> required> <?= e($m['label']) ?><?= $k === 'meet' ? ' <small class="muted">(padrão do escritório)</small>' : '' ?></label>
          <?php endforeach ?>
        </fieldset>
        <div class="row3">
          <label class="fld"><span>Data e hora (Brasília)</span><input type="datetime-local" name="starts_at" value="<?= e(dt_local($meeting['starts_at'] ?? null)) ?>" required></label>
          <label class="fld"><span>Duração (min)</span><input type="number" name="duration" min="10" max="240" step="5" value="<?= (int) ($meeting['duration_min'] ?? 30) ?>"></label>
          <label class="fld"><span>Telefone (se ligação)</span><input name="phone" inputmode="tel" value="<?= e(format_phone((string) ($meeting['phone'] ?? $c['client_phone']))) ?>"></label>
        </div>
        <label class="fld"><span>Link do Google Meet (opcional)</span><input name="url" type="url" placeholder="em branco: o sistema cria a sala no Google Meet" value=""></label>
        <label class="fld"><span>Orientação ao cliente (opcional)</span><input name="note" placeholder="Ex.: tenha o contrato em mãos"></label>
        <p class="hint">O sistema confere conflito com outras reuniões suas, cria o compromisso com o Meet no seu Google Agenda (convite para o cliente) e manda a confirmação por e-mail.</p>
        <button class="b b-primary" type="submit"><?= $meeting ? 'Reagendar' : 'Confirmar reunião' ?></button>
      </form>
    </details>
  <?php endif ?>

  <?php if ($meeting && in_array($st, ['collecting', 'analysis', 'meeting_scheduled'], true)):
      $dr = (array) (case_data($c, 'reuniao_ia_' . $meeting['id']) ?: []);
      $dv = fn(string $k, string $fallback = '') => (string) ($dr[$k] ?? $fallback);
      $temTx = (bool) $meeting['transcript_file_id']; ?>
    <details class="at-inline" id="registrar" <?= $dr || dt($meeting['starts_at']) < now() ? 'open' : '' ?>>
      <summary>Registrar reunião</summary>
      <div class="reg-ia">
        <?php if ($dr): ?>
          <p class="small"><strong>Preenchido pela IA</strong> a partir da transcrição em <?= e(fmt_datetime((string) $dr['at'])) ?>. Confira e ajuste antes de gravar.</p>
        <?php elseif ($temTx): ?>
          <p class="small">A transcrição está salva: a IA pode completar o registro para você conferir.</p>
        <?php else: ?>
          <p class="small muted">Quando a transcrição do Meet for salva, a IA completa este registro sozinha para você conferir.</p>
        <?php endif ?>
        <?php if ($temTx && llm_enabled()): ?>
          <?= $form('reuniao_ia', 'registrar', false, 'inline') ?><button class="b b-sm" type="submit"><?= $dr ? 'Refazer com a IA' : 'Preencher com a IA pela transcrição' ?></button></form>
        <?php endif ?>
      </div>
      <?= $form('registrar_reuniao', 'reuniao') ?>
        <fieldset class="radios"><legend class="lbl">A reunião aconteceu?</legend>
          <label class="chk"><input type="radio" name="held" value="sim" checked data-toggle-show="held-yes"> Sim</label>
          <label class="chk"><input type="radio" name="held" value="nao" data-toggle-show="held-no"> Não aconteceu</label>
        </fieldset>
        <div data-show="held-yes">
          <div class="row2">
            <label class="fld"><span>Reação do cliente</span><select name="reaction"><?php foreach (MEETING_REACTIONS as $rk => $rl): ?><option value="<?= $rk ?>"><?= e(ucfirst($rl)) ?></option><?php endforeach ?></select></label>
            <label class="fld"><span>Canal efetivo</span><select name="effective_channel"><?php foreach (MEETING_CHANNELS as $k => $mc): ?><option value="<?= $k ?>" <?= selected($k, $meeting['channel']) ?>><?= e($mc['short']) ?></option><?php endforeach ?></select></label>
          </div>
          <label class="fld"><span>Resumo para o cliente <small class="muted">(vai para a conversa dele)</small></span><textarea name="summary" rows="5"><?= e($dv('summary')) ?></textarea></label>
          <label class="chk"><input type="checkbox" name="confirmed_info" value="1" <?= checked(!empty($dr['confirmed_info'])) ?>> Conferi com o cliente as informações da ficha</label>
          <div class="row2">
            <label class="fld"><span>Informações corrigidas</span><textarea name="corrections" rows="3"><?= e($dv('corrections')) ?></textarea></label>
            <label class="fld"><span>Orientação dada</span><textarea name="guidance" rows="3"><?= e($dv('guidance')) ?></textarea></label>
          </div>
          <div class="row2">
            <label class="fld"><span>Riscos explicados</span><textarea name="risks" rows="3"><?= e($dv('risks', $val('proposta_riscos'))) ?></textarea></label>
            <label class="fld"><span>Pendências restantes</span><textarea name="pending_left" rows="3"><?= e($dv('pending_left')) ?></textarea></label>
          </div>
          <div class="row2">
            <?php if ($proposalVersions): ?><label class="fld"><span>Documento apresentado</span><select name="proposal_version_id"><option value="">—</option><?php foreach ($proposalVersions as $v): ?><option value="<?= (int) $v['id'] ?>"><?= e(kit_label($v['kind'])) ?> v<?= (int) $v['version'] ?> (<?= e(KIT_STATES[$v['status']]) ?>)</option><?php endforeach ?></select></label><?php else: ?><p class="hint"><?= proposal_get($id) ? 'Proposta registrada em Propostas.' : 'Sem proposta registrada em Propostas.' ?></p><?php endif ?>
            <label class="chk at-mid"><input type="checkbox" name="material_change" value="1"> Surgiu mudança material (análise/kit precisam de revisão)</label>
          </div>
        </div>
        <div data-show="held-no" hidden>
          <label class="fld"><span>Motivo, se conhecido</span><input name="no_show_reason"></label>
        </div>
        <div class="row2">
          <label class="fld"><span>Próxima ação</span><input name="next_action" value="<?= e($dv('next_action')) ?>" placeholder="Ex.: aguardar decisão do cliente"></label>
          <label class="fld"><span>Retorno <small class="muted">(cria o compromisso na sua agenda)</small></span><input type="datetime-local" name="return_at" value=""></label>
        </div>
        <button class="b b-primary" type="submit">Gravar registro</button>
        <p class="hint">Nenhum campo é obrigatório. A reação do cliente e o retorno são seus: com data e hora de retorno, o compromisso vai para o seu Google Agenda. Gravar o registro não formaliza contratação.</p>
      </form>
    </details>
  <?php endif ?>

  <?= render('panel/_reuniao_honorarios', ['id' => $id, 'c' => $c, 'form' => $form]) ?>

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
<section class="box at-block fx-aba" data-aba-painel="kit" id="kit" aria-labelledby="h-kit">
  <div class="box-head"><h2 id="h-kit">Kit e decisão</h2>
    <span class="tag <?= $kit['ready'] ? 'tag-green' : 'tag-amber' ?>"><?= $kit['ready'] ? 'Kit pronto' : 'Kit em preparação' ?></span></div>

  <?php $noDrive = (array) case_data($c, 'drive_kit', []); $naItems = []; $rects = [];
    foreach (rect_open($id) as $rq) { $rects[$rq['kind']][] = $rq; } ?>
  <div class="fk-grid">
    <?php foreach (KIT_ITEMS as $k => $meta): $it = $kit['items'][$k]; $v = $it['version']; $t = $meta['template'] ? doc_template($k) : null; $miss = $v ? (json_decode($v['missing'], true) ?: []) : [];
      if ($it['state'] === 'nao_aplicavel') { $naItems[$k] = [$meta, $v]; continue; } ?>
      <article class="fk-card is-<?= e($it['state']) ?><?= $it['stale'] ? ' is-stale' : '' ?>" id="kit-<?= e($k) ?>">
        <header>
          <strong><?= e($meta['label']) ?></strong>
          <span class="fk-st"><?= e(KIT_STATES[$it['state']]) ?><?= $v ? ' · v' . (int) $v['version'] : '' ?></span>
        </header>
        <div class="fk-flags">
          <?php if ($t && !$t['approved_at']): ?><span class="tag tag-gray" title="Publicação bloqueada até a aprovação do modelo">modelo não aprovado</span><?php endif ?>
          <?php if ($v && $v['signed_file_id']): ?><span class="tag tag-green">assinado</span><?php endif ?>
          <?php $vsig = $v ? sig_for_version((int) $v['id']) : null; if ($vsig): ?><a class="tag tag-green" href="<?= e(url('arquivo/' . $vsig['file_id'])) ?>" target="_blank" rel="noopener" title="Via assinada com a folha de evidências">assinatura eletrônica · <?= e(fmt_datetime($vsig['signed_at'])) ?></a><?php endif ?>
          <?php if ($v && !empty($noDrive[$v['id']])): ?><a class="tag tag-blue" href="https://drive.google.com/file/d/<?= e(rawurlencode((string) $noDrive[$v['id']])) ?>/view" target="_blank" rel="noopener">.docx no Drive</a><?php endif ?>
        </div>
        <?php if ($it['stale']): ?><p class="fk-aviso">Os dados mudaram depois desta versão: gere uma nova.</p><?php endif ?>
        <?php if ($vsig): $vev = json_decode((string) $vsig['evidence'], true) ?: []; ?>
          <p class="fk-assin">Assinado por <strong><?= e($vsig['signer_name']) ?></strong> · código <?= e($vsig['code']) ?> · IP <?= e($vsig['ip']) ?> · local: <?= e(sig_geo_text($vev['localizacao'] ?? [])) ?></p>
        <?php endif ?>
        <?php foreach ($rects[$k] ?? [] as $rq): $an = json_decode((string) $rq['ai_note'], true) ?: null; ?>
          <div class="fk-ret is-<?= e($rq['status']) ?>">
            <p><strong>Retificação pedida pelo cliente</strong> <span class="hint">v<?= (int) $rq['version'] ?> · <?= e(fmt_datetime($rq['created_at'])) ?><?= $rq['status'] === 'resolvida' ? ' · resolvida' : '' ?></span></p>
            <blockquote><?= nl2br(e($rq['body'])) ?></blockquote>
            <?php if ($an): ?>
              <p class="fk-ret-ia"><span class="tag <?= ['procedente' => 'tag-green', 'parcial' => 'tag-amber', 'improcedente' => 'tag-gray'][$an['avaliacao']] ?? 'tag-blue' ?>">IA: <?= e(RECT_AVALIACAO[$an['avaliacao']] ?? 'Precisa conferir') ?></span> <?= e($an['observacao']) ?></p>
              <?php if ($an['trecho'] || $an['sugestao']): ?><p class="small"><?= $an['trecho'] ? 'Trecho: “' . e($an['trecho']) . '”' : '' ?><?= $an['sugestao'] ? '<br>Sugestão: “' . e($an['sugestao']) . '”' : '' ?></p><?php endif ?>
            <?php elseif ($rq['status'] === 'aberta'): ?>
              <p class="hint">A IA ainda não avaliou este pedido.</p>
            <?php endif ?>
            <?php if ($rq['status'] === 'aberta'): ?>
              <?= $form('kit_retificacao', 'kit-' . $k, false, 'inline-form') ?><input type="hidden" name="rid" value="<?= (int) $rq['id'] ?>">
                <input name="resolucao" placeholder="O que foi feito (opcional)" maxlength="500"><button class="b b-xs" type="submit" name="acao" value="resolver">Marcar como resolvida</button>
                <?php if (!$an && llm_enabled()): ?><button class="b b-xs" type="submit" name="acao" value="ia">Avaliar com a IA</button><?php endif ?>
              </form>
            <?php elseif ($rq['resolution']): ?><p class="small muted">Resolução: <?= e($rq['resolution']) ?></p><?php endif ?>
          </div>
        <?php endforeach ?>
        <?php if ($miss): ?><p class="fk-falta">Falta: <?= e(implode('; ', $miss)) ?></p><?php endif ?>
        <div class="fk-acoes">
          <?php if ($it['state'] !== 'publicado' || $it['stale']): ?>
            <?= $form('kit_gerar', '', false, 'inline-form') ?><input type="hidden" name="kind" value="<?= e($k) ?>"><button class="b b-xs<?= !$v ? ' b-primary' : '' ?>" type="submit"><?= $v ? 'Nova versão' : 'Gerar minuta' ?></button></form>
          <?php endif ?>
          <?php if ($v && $v['status'] === 'minuta'): ?>
            <?= $form('kit_revisar', '', false, 'inline-form') ?><input type="hidden" name="kind" value="<?= e($k) ?>"><input type="hidden" name="vid" value="<?= (int) $v['id'] ?>"><button class="b b-xs b-primary" type="submit" <?= $miss ? 'disabled' : '' ?> title="Versão final: o .docx vai para o Drive">Versão final</button></form>
          <?php endif ?>
          <?php if ($v && $v['status'] === 'revisado'): ?>
            <?= $form('kit_publicar', '', false, 'inline-form') ?><input type="hidden" name="kind" value="<?= e($k) ?>"><input type="hidden" name="vid" value="<?= (int) $v['id'] ?>"><button class="b b-xs b-primary" type="submit">Publicar ao cliente</button></form>
          <?php endif ?>
          <?php if ($v): ?>
            <a class="b b-xs" href="<?= e(url('painel/atendimento/' . $id . '/kit/' . $v['id'])) ?>" target="_blank" rel="noopener">Ver</a>
            <a class="b b-xs" href="<?= e(url('painel/atendimento/' . $id . '/kit/' . $v['id'], ['baixar' => 'docx'])) ?>">.docx</a>
          <?php endif ?>
          <details class="fd-mais">
            <summary class="b b-xs" aria-label="Mais ações">⋯</summary>
            <div class="fd-menu">
              <?php if ($v && in_array($v['status'], ['minuta', 'revisado'], true)): ?>
                <?= $form('kit_editar') ?><input type="hidden" name="kind" value="<?= e($k) ?>"><input type="hidden" name="vid" value="<?= (int) $v['id'] ?>">
                  <label class="fld"><span>Texto desta versão</span><textarea name="content" rows="12" class="at-doc-edit"><?= e((string) $v['content']) ?></textarea></label>
                  <button class="b b-xs" type="submit">Salvar texto</button>
                </form>
              <?php endif ?>
              <?php if ($it['state'] !== 'publicado'): ?>
                <?= $form('kit_na', '', false, 'inline-form') ?><input type="hidden" name="kind" value="<?= e($k) ?>"><input name="reason" placeholder="Por que não se aplica" required><button class="b b-xs b-danger" type="submit">Remover do kit</button></form>
              <?php endif ?>
            </div>
          </details>
        </div>
      </article>
    <?php endforeach ?>
  </div>
  <?php if ($naItems): ?>
    <div class="fk-fora">
      <span class="hint">Fora do kit:</span>
      <?php foreach ($naItems as $k => [$meta, $v]): ?>
        <?= $form('kit_gerar', '', false, 'inline-form') ?><input type="hidden" name="kind" value="<?= e($k) ?>"><button class="b b-xs" type="submit" title="<?= e((string) ($v['content'] ?? '')) ?>">+ <?= e($meta['label']) ?></button></form>
      <?php endforeach ?>
    </div>
  <?php endif ?>
  <p class="hint">“Versão final” trava o texto revisado<?= drive_enabled() ? ' e grava o .docx com o número da versão na pasta do atendimento no Google Drive' : '' ?>. Para mudar um modelo para todos os casos, use <a href="<?= e(url('painel/modelos-documentos')) ?>">Modelos de documentos</a>.</p>
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
  <?php if ($c['contract_state'] === 'formalizado'): $as = asaas_summary($id); ?>
    <p class="hint">Cobrança no Asaas: <?= $as ? 'recebido ' . e(brl($as['pago'])) . ' de ' . e(brl($as['total'])) . ($as['vencidas'] ? ' · ' . (int) $as['vencidas'] . ' vencida(s)' : '') . ' · ' : 'ainda não criada · ' ?><a href="<?= e(url('painel/propostas/' . $id)) ?>#asaas"><?= $as ? 'ver cobranças' : 'criar as cobranças do contrato' ?></a></p>
  <?php endif ?>
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

<div class="fx-aba" data-aba-painel="mais" id="mais">
<section class="box at-arquivo" id="arquivo-caso">
  <h2>Resumo do caso e Google Drive</h2>
  <p class="hint">O arquivo .md reúne todas as variáveis conhecidas, o contexto, as necessidades, os pontos relevantes, os documentos e a conversa.</p>
  <p class="at-arquivo-acoes">
    <a class="b b-xs" href="<?= e(url('painel/atendimento/' . $id . '/caso.md')) ?>">Baixar <?= e($c['protocol']) ?>.md</a>
    <?php if (drive_enabled()): $pasta = case_data($c, 'drive_pasta'); ?>
      <?php if ($pasta): ?><a class="b b-xs" href="https://drive.google.com/drive/folders/<?= e(rawurlencode((string) $pasta)) ?>" target="_blank" rel="noopener">Abrir a pasta no Drive</a><?php endif ?>
      <form method="post" action="<?= e(url('painel/atendimento/' . $id . '/drive')) ?>" class="inline-form"><?= csrf_field() ?><button class="b b-xs" type="submit">Enviar ao Drive agora</button></form>
      <span class="hint"><?= case_data($c, 'drive_at') ? 'Última gravação: ' . e(fmt_datetime((string) case_data($c, 'drive_at'))) : 'Ainda não gravado' ?> · <?= e(drive_path()) ?>/<?= e($c['protocol']) ?></span>
    <?php else: ?>
      <span class="hint">Drive não conectado: conecte em <a href="<?= e(url('painel/configuracoes/agenda')) ?>">Configurações › Google</a>.</span>
    <?php endif ?>
  </p>
</section>

<?php if (can_manage_cases()): $gest = fn(string $op) => '<form method="post" action="' . e(url('painel/atendimento/' . $id . '/gestao')) . '" class="stack">' . csrf_field() . '<input type="hidden" name="op" value="' . $op . '">'; ?>
<section class="box at-gestao" id="gestao">
  <div class="box-head"><h2>Gestão do atendimento</h2><span class="hint">gestor e administrador · fechar, arquivar ou excluir</span></div>
  <div class="at-gestao-grid">
    <?php if ($c['initial_status'] !== 'completed'): ?>
      <?= $gest('fechar') ?>
        <h3>Fechar</h3>
        <p class="hint">Encerra o atendimento em qualquer etapa. O cliente vê que foi encerrado e a conversa passa para você.</p>
        <label class="fld"><span>Motivo</span><textarea name="motivo" rows="2" required placeholder="ex.: cliente desistiu; resolveu direto com o banco"></textarea></label>
        <button class="b" type="submit" data-confirm="Fechar este atendimento?">Fechar atendimento</button>
      </form>
    <?php endif ?>
    <?= $gest($c['archived_at'] ? 'desarquivar' : 'arquivar') ?>
      <h3><?= $c['archived_at'] ? 'Desarquivar' : 'Arquivar' ?></h3>
      <p class="hint"><?= $c['archived_at'] ? 'Volta a aparecer nas listas do escritório.' : 'Tira das listas do escritório sem apagar nada. Fica no filtro “Arquivados”. O cliente continua vendo a conversa.' ?></p>
      <button class="b" type="submit"><?= $c['archived_at'] ? 'Desarquivar' : 'Arquivar atendimento' ?></button>
    </form>
    <?= $gest('excluir') ?>
      <h3>Excluir</h3>
      <p class="hint">Apaga de vez o atendimento, a conversa, a ficha, os documentos e a proposta. Não dá para desfazer.</p>
      <label class="fld"><span>Digite o protocolo <strong class="mono"><?= e($c['protocol']) ?></strong> para confirmar</span><input name="confirmar" autocomplete="off" required></label>
      <button class="b b-danger" type="submit" data-confirm="Excluir de vez o atendimento <?= e($c['protocol']) ?>? Não dá para desfazer.">Excluir atendimento</button>
    </form>
  </div>
</section>
<?php endif ?>
<section class="box" id="historico">
  <div class="box-head"><h2>Histórico interno</h2></div>
  <ul class="tl">
    <?php foreach ($events as $ev): ?>
      <li><small><?= e(fmt_datetime($ev['at'])) ?><?= $ev['visible_to_client'] ? ' · visível ao cliente' : '' ?></small><br><strong><?= e($ev['title']) ?></strong><?= $ev['body'] ? '<br><span class="muted">' . nl2br(e($ev['body'])) . '</span>' : '' ?></li>
    <?php endforeach ?>
  </ul>
</section>
</div>

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

