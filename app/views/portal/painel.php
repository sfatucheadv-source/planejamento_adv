<?php
$phaseIdx = client_phase_index($c);
$phaseKey = array_keys(CLIENT_PHASES)[$phaseIdx];
$closed = $c['status'] !== 'ativo';
$lastDone = null;
foreach ($events as $ev) {
    if ($ev['actor'] !== 'cliente') { $lastDone = $ev; break; }
}
$clientAlert = case_data($c, 'client_alert');
$filesByDoc = [];
foreach ($files as $f) { $filesByDoc[(int) $f['document_id']][] = $f; }
?>
<section class="portal">
  <div class="wrap">
    <header class="portal-head">
      <div>
        <p class="kicker">Área do cliente · protocolo <?= e($c['protocol']) ?></p>
        <h1>Olá, <?= e(first_name($c['client_name'])) ?></h1>
      </div>
      <a class="btn btn-ghost btn-small" href="<?= e(url('cliente/sair')) ?>">Sair</a>
    </header>

    <ol class="phase-track" aria-label="Situação do caso">
      <?php $i = 0; foreach (CLIENT_PHASES as $k => $ph): ?>
        <li class="<?= $i < $phaseIdx || $closed ? 'done' : ($i === $phaseIdx ? 'current' : '') ?>"<?= $i === $phaseIdx ? ' aria-current="step"' : '' ?>><?= e($ph['label']) ?></li>
      <?php $i++; endforeach ?>
    </ol>

    <?php if ($clientAlert): ?>
      <p class="notice notice-urgent"><strong>Atenção:</strong> <?= e($clientAlert) ?></p>
    <?php endif ?>

    <div class="portal-grid">
      <div>
        <section class="panel case-card" aria-labelledby="cartao">
          <h2 id="cartao">Seu caso em uma olhada</h2>
          <dl>
            <div><dt>Seu objetivo</dt><dd><?= e($c['objective'] ?: preg_replace('/\s*Correto\?$/u', '', (string) $c['summary_sentence'])) ?></dd></div>
            <div><dt>Situação atual</dt><dd><?= $closed ? 'Caso encerrado' . ($c['outcome'] ? ' · ' . e(OUTCOMES[$c['outcome']]['label']) : '') : e(CLIENT_PHASES[$phaseKey]['label']) ?><br><small class="muted"><?= e(CLIENT_PHASES[$phaseKey]['text']) ?></small></dd></div>
            <div><dt>O que concluímos</dt><dd><?= $lastDone ? e($lastDone['title']) . ' <small class="muted">(' . e(fmt_date($lastDone['at'])) . ')</small>' : 'Recebemos o seu caso.' ?></dd></div>
            <?php if (!$closed): ?>
              <div><dt>Próximo passo do escritório</dt><dd><?= e($c['next_action'] ?: 'Organizar os próximos passos') ?><?= $c['next_action_owner_id'] ? ' <small class="muted">· ' . e(user_name((int) $c['next_action_owner_id'])) . '</small>' : '' ?></dd></div>
              <div><dt>Sua próxima ação</dt><dd><?= e($c['client_next_action'] ?: 'Nenhuma no momento. Pode deixar com a gente.') ?></dd></div>
              <div><dt>Próximo retorno</dt><dd><?= $c['next_client_update'] ? 'Até ' . e(fmt_when(dt($c['next_client_update']))) : 'Informamos em breve' ?></dd></div>
            <?php endif ?>
          </dl>
        </section>

        <?php if ($decisions): ?>
        <section class="panel" id="decisoes">
          <h2>Decisões que dependem de você</h2>
          <p class="muted">Nada é aceito em seu nome sem a sua confirmação. Silêncio nunca vale como “sim”.</p>
          <?php foreach ($decisions as $d): $opts = json_decode($d['options'], true) ?: []; ?>
            <form class="decision" method="post" action="<?= e(url('cliente/decisao/' . $d['id'])) ?>">
              <?= csrf_field() ?>
              <h3><?= e($d['question']) ?></h3>
              <?php if ($d['context']): ?><div class="muted"><?= nl2p($d['context']) ?></div><?php endif ?>
              <?php if ($d['deadline']): ?><p><strong>Responder até <?= e(fmt_date($d['deadline'])) ?>.</strong></p><?php endif ?>
              <div class="options">
                <?php foreach ($opts as $o): ?><label class="option"><input type="radio" name="answer" value="<?= e($o) ?>" required><span><?= e($o) ?></span></label><?php endforeach ?>
                <label class="option"><input type="radio" name="answer" value="Quero conversar antes de decidir"><span>Quero conversar antes de decidir</span></label>
              </div>
              <label class="field"><span>Por quê? <small>(opcional, ajuda Sidney)</small></span><textarea name="reason" rows="2"></textarea></label>
              <button class="btn btn-gold" type="submit">Enviar minha resposta</button>
            </form>
          <?php endforeach ?>
        </section>
        <?php endif ?>

        <section class="panel" id="documentos">
          <h2>Documentos</h2>
          <p class="muted">Aceitamos PDF ou fotos legíveis (até <?= (int) config('upload_max_mb') ?> MB por arquivo). Se não tiver algum, é só avisar: a gente ajuda a conseguir.</p>
          <ul class="doc-rows">
            <?php foreach ($docs as $d): $st = DOC_STATUS[$d['status']] ?? DOC_STATUS['falta']; $cat = DOC_CATALOG[$d['kind']] ?? null; ?>
              <li class="doc-row">
                <div class="doc-row-head"><strong><?= e($d['label']) ?></strong><span class="badge badge-<?= e($st['tone']) ?>"><?= e($st['client']) ?></span></div>
                <?php if ($cat): ?><p><?= e($cat['why']) ?> <?= in_array($d['status'], ['falta', 'pendencia', 'ajuda'], true) ? e($cat['how']) : '' ?></p><?php endif ?>
                <?php if ($d['status'] === 'pendencia' && $d['note']): ?><p><strong>O que precisamos:</strong> <?= e($d['note']) ?></p><?php endif ?>
                <?php if (!empty($filesByDoc[(int) $d['id']])): ?>
                  <ul class="files-mini"><?php foreach ($filesByDoc[(int) $d['id']] as $f): ?><li><a href="<?= e(url('arquivo/' . $f['id'])) ?>" target="_blank"><?= e($f['original_name']) ?></a> <small class="muted"><?= e(fmt_date($f['uploaded_at'])) ?></small></li><?php endforeach ?></ul>
                <?php endif ?>
                <?php if (!$closed && in_array($d['status'], ['falta', 'pendencia', 'ajuda', 'recebido'], true)): ?>
                  <form method="post" action="<?= e(url('cliente/documento/' . $d['id'])) ?>" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <input type="file" name="files[]" accept="application/pdf,image/*" multiple required aria-label="Arquivos de <?= e($d['label']) ?>">
                    <button class="btn btn-small btn-gold" type="submit"><?= $d['status'] === 'recebido' ? 'Enviar mais páginas' : 'Enviar' ?></button>
                  </form>
                  <?php if ($d['status'] !== 'recebido'): ?>
                  <form method="post" action="<?= e(url('cliente/documento/' . $d['id'])) ?>">
                    <?= csrf_field() ?><input type="hidden" name="action" value="nao_possui">
                    <button class="linkish" type="submit">Não tenho este documento</button>
                  </form>
                  <?php endif ?>
                <?php endif ?>
              </li>
            <?php endforeach ?>
          </ul>
        </section>

        <?php if ($schedule): ?>
        <section class="panel">
          <h2>Calendário do acordo</h2>
          <p class="muted">Avisamos três dias antes de cada vencimento. Antes de pagar, confira se o boleto veio do canal oficial do banco.</p>
          <div class="table-wrap"><table class="table">
            <thead><tr><th>Vencimento</th><th>O quê</th><th>Valor</th><th>Situação</th></tr></thead>
            <tbody><?php foreach ($schedule as $s): ?>
              <tr><td><?= e(fmt_date($s['due_date'])) ?></td><td><?= e($s['description']) ?></td><td><?= e(money($s['amount'] !== null ? (float) $s['amount'] : null)) ?></td><td><span class="badge <?= $s['status'] === 'pago' ? 'badge-ok' : ($s['status'] === 'atrasado' ? 'badge-danger' : 'badge-warn') ?>"><?= e(ucfirst($s['status'])) ?></span></td></tr>
            <?php endforeach ?></tbody>
          </table></div>
        </section>
        <?php endif ?>

        <?php if ($closed || $c['stage'] === 'encerramento'): ?>
        <section class="panel" id="avaliacao">
          <?php if ($feedback): ?>
            <h2>Obrigado pela avaliação</h2>
            <p>Você deu nota <?= (int) $feedback['ease'] ?> de 5 para a facilidade do atendimento.</p>
          <?php else: ?>
            <h2>Como foi resolver com a gente?</h2>
            <form method="post" action="<?= e(url('cliente/avaliacao')) ?>" class="stack">
              <?= csrf_field() ?>
              <p class="q-label">De 1 a 5, quanto foi fácil resolver as etapas conosco?</p>
              <div class="rating"><?php for ($n = 1; $n <= 5; $n++): ?><label><input type="radio" name="ease" value="<?= $n ?>" required><span><?= $n ?></span></label><?php endfor ?></div>
              <label class="field"><span>O que poderíamos simplificar?</span><textarea name="simplify" rows="3"></textarea></label>
              <button class="btn btn-gold" type="submit">Enviar</button>
            </form>
          <?php endif ?>
        </section>
        <?php endif ?>
      </div>

      <aside>
        <section class="panel">
          <h2>Quem cuida do seu caso</h2>
          <p><strong><?= e(user_name($c['gestor_id'] ? (int) $c['gestor_id'] : null)) ?></strong><br><span class="muted">acompanha o seu caso</span></p>
          <p><strong><?= e(user_name($c['advogado_id'] ? (int) $c['advogado_id'] : null)) ?></strong><br><span class="muted">advogado responsável</span></p>
          <a class="btn btn-wa btn-block" href="<?= e(whatsapp_link('Olá! Sou ' . first_name($c['client_name']) . ', protocolo ' . $c['protocol'] . '.')) ?>" target="_blank" rel="noopener">Falar no WhatsApp</a>
        </section>

        <?php if (!$closed): ?>
        <section class="panel">
          <h2>Mandar um recado</h2>
          <form method="post" action="<?= e(url('cliente/mensagem')) ?>" class="stack">
            <?= csrf_field() ?>
            <label class="field"><span class="sr">Recado</span><textarea name="message" rows="3" maxlength="2000" placeholder="Recebi uma nova carta, mudei de telefone, tenho uma dúvida…" required></textarea></label>
            <button class="btn btn-ghost" type="submit">Enviar recado</button>
          </form>
          <p class="muted"><small>Se receber notificação, mandado ou intimação, avise pelo WhatsApp também.</small></p>
        </section>
        <?php endif ?>

        <section class="panel">
          <h2>Atualizações</h2>
          <ol class="timeline">
            <?php foreach ($events as $ev): ?>
              <li><time datetime="<?= e($ev['at']) ?>"><?= e(fmt_datetime($ev['at'])) ?></time><strong><?= e($ev['title']) ?></strong><?php if ($ev['body']): ?><p><?= e($ev['body']) ?></p><?php endif ?></li>
            <?php endforeach ?>
          </ol>
        </section>

        <?php if ($answered): ?>
        <section class="panel">
          <h2>Suas decisões</h2>
          <ul class="files-mini"><?php foreach ($answered as $d): ?><li><span><strong><?= e($d['answer']) ?></strong> · <?= e($d['question']) ?> <small class="muted">(<?= e(fmt_date($d['answered_at'])) ?>)</small></span></li><?php endforeach ?></ul>
        </section>
        <?php endif ?>
      </aside>
    </div>
  </div>
</section>
