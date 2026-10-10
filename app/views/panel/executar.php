<?php
require_once __DIR__ . '/guia/blocos.php';
$s = stage($c['stage']);
$stageKeys = array_keys(stages());
$curIdx = array_search($c['stage'], $stageKeys, true);
$list = array_values($steps);
$st = $current && $current !== '_avancar' ? ($steps[$current] ?? null) : null;
$pos = $st ? array_search($current, array_keys($steps), true) : null;
$nextKey = $pos !== null ? (array_keys($steps)[$pos + 1] ?? '_avancar') : null;
$prevKey = $pos ? array_keys($steps)[$pos - 1] : null;
$roleNames = fn(array $roles) => implode(' ou ', array_map(fn($r) => ROLES[$r] ?? $r, $roles));
$say = null;
if ($st && !empty($st['say'])) {
    $raw = is_callable($st['say']) ? ($st['say'])($c) : $st['say'];
    if ($raw) {
        $say = isset(MESSAGE_TEMPLATES[$raw]) ? ['tpl' => $raw, 'text' => fill_template($raw, $c)] : ['tpl' => '', 'text' => $raw];
        // Rascunho devolvido quando faltou conferir ou completar colchetes.
        if (!empty($_SESSION['draft_' . $c['id']])) {
            $say['text'] = $_SESSION['draft_' . $c['id']];
        }
    }
}
?>
<div class="g-head <?= $c['urgent'] ? 'is-urgent' : '' ?>">
  <a class="g-back" href="<?= e(url('painel/fila')) ?>">← Minha fila</a>
  <div class="g-who">
    <h1><?= e($c['client_name']) ?><?php if ($c['urgent']): ?> <span class="tag tag-red">urgente</span><?php endif ?></h1>
    <p class="sub"><span class="mono"><?= e($c['protocol']) ?></span> · <?= e($c['client_phone']) ?> · <a href="<?= e(whatsapp_link('', $c['client_phone'])) ?>" target="_blank" rel="noopener">WhatsApp</a><?= $c['bank'] ? ' · ' . e($c['bank']) : '' ?></p>
  </div>
  <a class="b b-xs" href="<?= e(url('painel/caso/' . $c['id'])) ?>">Ficha completa</a>
</div>

<div class="g-stagebar" aria-label="Etapa <?= $s['n'] ?> de 14">
  <div class="g-stagebar-txt"><strong>Etapa <?= $s['n'] ?> de 14 · <?= e($s['label']) ?></strong><small>O cliente vê: “<?= e(CLIENT_PHASES[$s['phase']]['label']) ?>”</small></div>
  <ol><?php foreach ($stageKeys as $i => $k): ?><li class="<?= $i < $curIdx ? 'past' : ($i === $curIdx ? 'cur' : '') ?>" title="<?= e(stage($k)['label']) ?>"></li><?php endforeach ?></ol>
</div>

<?php if ($c['status'] !== 'ativo'): ?>
  <section class="g-card">
    <h2>Caso encerrado</h2>
    <p>Encerrado em <?= e(fmt_datetime($c['closed_at'])) ?> · <?= e(OUTCOMES[$c['outcome']]['label'] ?? '') ?>.</p>
    <?php if ($tasks): ?><p>Restam tarefas de acompanhamento, listadas ao lado.</p><?php endif ?>
  </section>
<?php endif ?>

<div class="g-grid">
  <?php if ($c['status'] === 'ativo'): ?>
  <nav class="g-steps" aria-label="Passos desta etapa">
    <p class="g-steps-h">Passos desta etapa</p>
    <ol>
      <?php $i = 0; foreach ($steps as $k => $x): $i++; ?>
        <li class="<?= $x['done'] ? 'done' : '' ?> <?= $k === $current ? 'cur' : '' ?> <?= !$x['done'] && $x['blocked_by'] ? 'locked' : '' ?>">
          <a href="<?= e(url('painel/executar/' . $c['id'], ['passo' => $k])) ?>">
            <span class="g-dot"><?= $x['done'] ? '✓' : $i ?></span>
            <span><?= e($x['title']) ?><small><?= e(guide_role_person($c, $x['roles'][0])) ?><?= !empty($x['optional']) ? ' · se precisar' : '' ?><?= !$x['done'] && $x['waiting'] ? ' · aguardando' : '' ?></small></span>
          </a>
        </li>
      <?php endforeach ?>
      <?php if ($s['next']): ?>
      <li class="<?= $current === '_avancar' ? 'cur' : '' ?> <?= $ready ? '' : 'locked' ?>">
        <a href="<?= e(url('painel/executar/' . $c['id'], ['passo' => '_avancar'])) ?>"><span class="g-dot">→</span><span>Passar o caso adiante<small>próxima etapa</small></span></a>
      </li>
      <?php endif ?>
    </ol>
  </nav>
  <?php endif ?>

  <div class="g-main">
    <?php if ($st): ?>
      <section class="g-card">
        <p class="g-kicker">Passo <?= $pos + 1 ?> de <?= count($steps) ?> · quem faz: <strong><?= e($roleNames($st['roles'])) ?></strong> (<?= e(guide_role_person($c, $st['roles'][0])) ?>)</p>
        <h2><?= e($st['title']) ?></h2>

        <?php if ($st['done']): ?><p class="ok-line">Este passo já foi concluído. Você pode revisar ou corrigir os dados abaixo.</p><?php endif ?>
        <?php if (!$st['done'] && $st['blocked_by']): ?><p class="warn-line">Antes deste passo: <?= e(implode('; ', array_map(fn($k) => $steps[$k]['title'], $st['blocked_by']))) ?>.</p><?php endif ?>
        <?php if (!$st['done'] && $st['waiting']): ?><p class="info-line"><?= e($st['waiting']) ?>. Assim que a resposta chegar, registre aqui.</p><?php endif ?>
        <?php if (!$st['can']): ?><p class="warn-line">Este passo é de <?= e($roleNames($st['roles'])) ?>. Você pode acompanhar, mas não executar.</p><?php endif ?>

        <div class="g-how">
          <h3>O que fazer</h3>
          <ol><?php foreach ((array) $st['how'] as $h): ?><li><?= e($h) ?></li><?php endforeach ?></ol>
        </div>

        <?php if ($say): ?>
          <div class="g-say">
            <h3>O que dizer ao cliente</h3>
            <?php if ($st['can']): ?>
              <?= case_form($c, 'mensagem', ['template' => $say['tpl'], 'channel' => 'whatsapp'], 'stack') ?>
                <textarea name="body" rows="6" data-msg aria-label="Mensagem"><?= e($say['text']) ?></textarea>
                <p class="hint">Complete o que estiver entre colchetes e confira fatos e datas.</p>
                <div class="g-say-actions">
                  <a class="b" href="<?= e(whatsapp_link($say['text'], $c['client_phone'])) ?>" target="_blank" rel="noopener" data-wa-link data-phone="<?= e(only_digits((string) $c['client_phone'])) ?>">Abrir no WhatsApp</a>
                  <label class="chk"><input type="checkbox" name="facts_checked" value="1"> Conferi</label>
                  <button class="b" type="submit">Registrar que enviei</button>
                </div>
              </form>
            <?php else: ?>
              <p class="quote-box small"><?= nl2br(e($say['text'])) ?></p>
            <?php endif ?>
          </div>
        <?php endif ?>

        <?php if (!empty($st['form'])): ?>
          <div class="g-fields <?= $st['can'] ? '' : 'is-readonly' ?>">
            <?php if ($st['can']) { guia_bloco($st['form'], $c, $users); } elseif (in_array($st['form'], ['fatos', 'docs_resumo', 'urgencia_info', 'objetivo_resumo', 'parcelas'], true)) { guia_bloco($st['form'], $c, $users); } ?>
          </div>
        <?php endif ?>

        <?php if ($st['can'] && !empty($st['choices']) && !$st['done']): ?>
          <form method="post" action="<?= e(url('painel/executar/' . $c['id'])) ?>" class="g-choices">
            <?= csrf_field() ?>
            <input type="hidden" name="op" value="escolha">
            <input type="hidden" name="passo" value="<?= e($current) ?>">
            <?php if (array_filter($st['choices'], fn($ch) => !empty($ch['note']))): ?>
              <label class="fld"><span><?= e(current(array_filter(array_map(fn($ch) => $ch['note'] ?? null, $st['choices'])))) ?> <small>(obrigatório nessa opção)</small></span><textarea name="note" rows="2"></textarea></label>
            <?php else: ?>
              <label class="fld"><span>Observação <small>(opcional)</small></span><input name="note"></label>
            <?php endif ?>
            <div class="g-choice-btns">
              <?php foreach ($st['choices'] as $i => $ch): ?>
                <button class="b <?= !empty($ch['danger']) ? 'b-danger' : (($i === 0 && empty($ch['note'])) || !empty($ch['primary']) ? 'b-primary' : '') ?>" type="submit" name="escolha" value="<?= e($ch['key']) ?>"<?= $st['blocked_by'] ? ' disabled' : '' ?>><?= e($ch['label']) ?></button>
              <?php endforeach ?>
            </div>
          </form>
        <?php endif ?>

        <nav class="g-nav">
          <?php if ($prevKey): ?><a href="<?= e(url('painel/executar/' . $c['id'], ['passo' => $prevKey])) ?>">← Passo anterior</a><?php else: ?><span></span><?php endif ?>
          <a href="<?= e(url('painel/fila')) ?>">Voltar à fila</a>
          <?php if ($nextKey): ?><a href="<?= e(url('painel/executar/' . $c['id'], ['passo' => $nextKey])) ?>"><?= $st['done'] ? 'Próximo passo' : 'Pular por agora' ?> →</a><?php endif ?>
        </nav>
      </section>

    <?php elseif ($current === '_avancar'):
        $problems = gate_problems($c);
        $blocking = array_filter($problems, fn($p) => $p['block']);
        $pending = array_filter($steps, fn($x) => !$x['done'] && empty($x['optional']));
        $rec = guide_recommended_next($c);
        $next = $s['next'];
        usort($next, fn($a, $b) => ($b === $rec) <=> ($a === $rec));
        $earlier = array_filter(stages(), fn($x) => $x['n'] < $s['n']);
    ?>
      <section class="g-card">
        <p class="g-kicker">Fim da etapa <?= $s['n'] ?> · <?= e($s['label']) ?></p>
        <h2>Passar o caso adiante</h2>
        <p class="hint">Entrega desta etapa: <?= e($s['deliverable']) ?></p>
        <?php if ($pending || $blocking): ?>
          <ul class="problems">
            <?php foreach ($pending as $k => $x): ?><li class="blk">Falta o passo: <a href="<?= e(url('painel/executar/' . $c['id'], ['passo' => $k])) ?>"><?= e($x['title']) ?></a> (<?= e(guide_role_person($c, $x['roles'][0])) ?>)</li><?php endforeach ?>
            <?php foreach ($blocking as $p): ?><li class="blk"><?= e($p['label']) ?></li><?php endforeach ?>
          </ul>
        <?php else: ?>
          <p class="ok-line">Tudo pronto nesta etapa.</p>
        <?php endif ?>
        <?php foreach (array_filter($problems, fn($p) => !$p['block']) as $p): ?><p class="hint">Recomendado: <?= e($p['label']) ?></p><?php endforeach ?>

        <?php if ($next): ?>
          <form method="post" action="<?= e(url('painel/executar/' . $c['id'])) ?>" class="g-form">
            <?= csrf_field() ?>
            <input type="hidden" name="op" value="avancar">
            <fieldset class="g-radios">
              <legend>Para onde o caso vai agora?</legend>
              <?php foreach ($next as $nk): $ns = stage($nk); ?>
                <label class="g-radio"><input type="radio" name="to" value="<?= e($nk) ?>" required<?= $nk === $rec ? ' checked' : '' ?>><span><strong><?= $ns['n'] ?>. <?= e($ns['title']) ?><?= $nk === $rec ? ' <em class="tag tag-green">sugerido</em>' : '' ?></strong><small>Quando: <?= e($ns['entry']) ?> Responsável: <?= e(guide_role_person($c, $ns['owner'][0])) ?>.</small></span></label>
              <?php endforeach ?>
            </fieldset>
            <label class="fld"><span>Observação para quem recebe o caso <small>(opcional)</small></span><input name="reason"></label>
            <button class="b b-primary" type="submit"<?= $blocking || $pending ? ' disabled' : '' ?>>Avançar o caso</button>
          </form>
        <?php endif ?>

        <?php if ($earlier): ?>
          <details class="more">
            <summary>Voltar a uma etapa anterior (documento faltante, divergência)</summary>
            <form method="post" action="<?= e(url('painel/executar/' . $c['id'])) ?>" class="inline-form">
              <?= csrf_field() ?>
              <input type="hidden" name="op" value="avancar">
              <label class="fld"><span>Etapa</span><select name="to"><?php foreach ($earlier as $ek => $es): ?><option value="<?= e($ek) ?>"><?= $es['n'] ?>. <?= e($es['label']) ?></option><?php endforeach ?></select></label>
              <label class="fld grow"><span>Motivo e próxima ação</span><input name="reason" required></label>
              <button class="b" type="submit">Voltar</button>
            </form>
          </details>
        <?php endif ?>
      </section>
    <?php endif ?>
  </div>

  <aside class="g-info">
    <section class="box">
      <h2>Resumo do caso</h2>
      <dl class="mini-dl">
        <div><dt>Objetivo</dt><dd><?= e($c['objective'] ?: 'ainda não registrado') ?></dd></div>
        <div><dt>O cliente contou</dt><dd><?= e($c['summary_sentence']) ?></dd></div>
        <div><dt>Equipe</dt><dd><?= e(first_name(user_name($c['gestor_id'] ? (int) $c['gestor_id'] : null))) ?> (gestor) · <?= e(first_name(user_name($c['advogado_id'] ? (int) $c['advogado_id'] : null))) ?> (advogado)</dd></div>
        <div><dt>Próximo retorno ao cliente</dt><dd><?= $c['next_client_update'] ? e(fmt_datetime($c['next_client_update'])) : '—' ?></dd></div>
      </dl>
    </section>
    <?php if ($alerts = case_alerts($c)): ?>
      <section class="box">
        <h2>Atenção</h2>
        <ul class="alerts alerts-inline"><?php foreach ($alerts as $a): ?><li class="alert alert-<?= e($a['level']) ?>"><span><?= e($a['text']) ?></span></li><?php endforeach ?></ul>
      </section>
    <?php endif ?>
    <?php if ($deadlines): ?>
      <section class="box">
        <h2>Prazos abertos</h2>
        <ul class="dl-list"><?php foreach ($deadlines as $d): ?><li class="<?= dt($d['due_at']) < now() ? 'late' : '' ?>"><strong><?= e($d['description']) ?></strong><small><?= e(fmt_datetime($d['due_at'])) ?></small></li><?php endforeach ?></ul>
      </section>
    <?php endif ?>
    <?php if ($tasks): ?>
      <section class="box">
        <h2>Tarefas deste caso</h2>
        <ul class="g-tasks">
          <?php foreach ($tasks as $t): ?>
            <li>
              <strong><?= e($t['title']) ?></strong>
              <small><?= e(user_name($t['owner_id'] ? (int) $t['owner_id'] : null)) ?><?= $t['due_at'] ? ' · ' . e(fmt_when(dt($t['due_at']))) : '' ?></small>
              <form method="post" action="<?= e(url('painel/tarefa/' . $t['id'])) ?>" class="mini-form">
                <?= csrf_field() ?><input type="hidden" name="op" value="concluir">
                <input name="evidence" placeholder="Como foi resolvida" aria-label="Como foi resolvida">
                <button class="b b-xs" type="submit">Concluir</button>
              </form>
            </li>
          <?php endforeach ?>
        </ul>
      </section>
    <?php endif ?>
  </aside>
</div>
