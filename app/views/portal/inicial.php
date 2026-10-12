<?php
/**
 * Área do cliente no atendimento inicial: Início, Conversa, Documentos e
 * Reunião e proposta (seção 12.1). Termos técnicos ficam de fora.
 */
$id = (int) $c['id'];
$st = $c['initial_status'];
$old = $_SESSION['bot_old'] ?? null;
unset($_SESSION['bot_old']);
$oldVal = fn(string $k) => $old && ($old['q'] ?? '') === ($q['key'] ?? '') ? (string) ($k === 'value' ? $old['value'] : ($old['post'][$k] ?? '')) : '';
$completed = $st === 'completed';
$openDocs = array_filter($docs, fn($d) => in_array($d['status'], DOC_OPEN, true));
$areas = ['conversa' => 'Conversa', 'documentos' => 'Documentos', 'reuniao' => 'Reunião e proposta', 'inicio' => 'Situação'];
$filesByDoc = [];
foreach ($files as $f) {
    $filesByDoc[(int) $f['document_id']][] = $f;
}
// Botão principal conforme o contexto.
if ($completed) {
    $main = ['reuniao', 'Ver a decisão e a continuidade'];
} elseif ($st === 'awaiting_decision') {
    $main = ['reuniao', $c['decision'] === 'aceitou' && $c['contract_state'] !== 'formalizado' ? 'Ver os documentos para assinar' : 'Ver a proposta e responder'];
} elseif ($meeting) {
    $main = ['reuniao', 'Ver a reunião'];
} elseif ($q) {
    $main = ['conversa', $q['block'] === 'B02' && !$c['intake_done_at'] && !array_filter($chat, fn($m) => $m['author'] === 'client' && $m['question']) ? 'Começar a conversa' : 'Continuar de onde parei'];
} elseif ($openDocs) {
    $main = ['documentos', 'Enviar o que falta'];
} else {
    $main = ['conversa', 'Abrir a conversa'];
}
$meetingAction = function (array $m) use ($c) {
    return match ($m['channel']) {
        'meet' => '<a class="btn btn-gold" href="' . e($m['url']) . '" target="_blank" rel="noopener">Entrar na reunião</a>',
        'whatsapp_text' => '<a class="btn btn-wa" href="' . e(whatsapp_link('Olá, Sidney! Estou pronto(a) para a nossa conversa. Protocolo ' . $c['protocol'] . '.')) . '" target="_blank" rel="noopener">Conversar no WhatsApp</a>',
        'site_chat' => '<a class="btn btn-gold" href="' . e(url('cliente', ['area' => 'conversa'])) . '#fim">Abrir conversa com Sidney</a>',
        default => '',
    };
};
$choiceButtons = function (array $q) {
    $h = '';
    foreach (['unknown', 'later', 'skip'] as $mode) {
        if (!empty($q[$mode])) {
            $h .= '<button type="submit" name="mode" value="' . $mode . '" class="btn btn-ghost btn-small" formnovalidate>' . e($q[$mode]) . '</button>';
        }
    }
    return $h;
};
?>
<section class="app-pane">
    <header class="app-bar">
      <a class="app-back" href="<?= e(url('cliente/conversas')) ?>" aria-label="Voltar para os seus atendimentos"><svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" d="M15 18l-6-6 6-6"/></svg></a>
      <div class="app-bar-title">
        <strong><?= e(client_case_title($c)) ?></strong>
        <small><?= e(client_case_meta($c)) ?> · <?= e($c['protocol']) ?> · <?= e($c['chat_mode'] === 'human' ? 'Sidney está na conversa' : initial_status_label($c, true)) ?></small>
      </div>
    </header>

    <nav class="cv-tabs app-tabs" aria-label="Áreas do atendimento">
      <?php foreach ($areas as $k => $l): ?>
        <a href="<?= e(url('cliente', ['area' => $k])) ?>" class="<?= $area === $k ? 'on' : '' ?>"<?= $area === $k ? ' aria-current="page"' : '' ?>><?= e($l) ?><?php if ($k === 'documentos' && $openDocs && !$completed): ?> <span class="cv-badge"><?= count($openDocs) ?></span><?php endif ?></a>
      <?php endforeach ?>
    </nav>

<?php if ($area !== 'conversa'): ?>
    <div class="app-scroll"><div class="app-scroll-in">
<?php endif ?>
<?php if ($area === 'inicio'): ?>
    <div class="portal-grid">
      <div>
        <section class="panel case-card" aria-labelledby="h-sit">
          <h2 id="h-sit">Seu atendimento</h2>
          <p class="cv-status"><?= e(initial_status_label($c, true)) ?></p>
          <dl>
            <?php if (!$completed): ?>
              <div><dt>Próximo passo</dt><dd><?= e($c['next_action'] ?: 'Sidney vai conferir suas informações') ?></dd></div>
              <div><dt>Próximo retorno</dt><dd><?= $c['next_client_update'] ? 'Até ' . e(fmt_when(dt($c['next_client_update']))) . ' (horário de Brasília)' : 'Sidney informa a data em breve. Se a previsão mudar, avisamos.' ?></dd></div>
              <?php if ($c['client_next_action']): ?><div><dt>O que depende de você</dt><dd><?= e($c['client_next_action']) ?></dd></div><?php endif ?>
            <?php else: ?>
              <div><dt>Resultado</dt><dd><?= e(DECISIONS[$c['decision']] ?? '') ?><?= $c['closure_reason'] ? '. ' . e($c['closure_reason']) : '' ?></dd></div>
              <?php if ($c['access_expires_at']): ?><div><dt>Acesso a esta área</dt><dd>Disponível até <?= e(fmt_datetime($c['access_expires_at'])) ?>. Depois, para um novo assunto, peça a Sidney um novo atendimento.</dd></div><?php endif ?>
            <?php endif ?>
          </dl>
          <a class="btn btn-gold" href="<?= e(url('cliente', ['area' => $main[0]])) ?><?= $main[0] === 'conversa' ? '#pergunta' : '' ?>"><?= e($main[1]) ?></a>
        </section>

        <ol class="phase-track cv-track" aria-label="Situações do atendimento inicial">
          <?php $keys = array_keys(INITIAL_STATUS); $idx = array_search($st, $keys, true); foreach (INITIAL_STATUS as $k => $s): $i = array_search($k, $keys, true); ?>
            <li class="<?= $i < $idx || $completed ? 'done' : ($i === $idx ? 'current' : '') ?>"<?= $i === $idx ? ' aria-current="step"' : '' ?>><?= e($s['label']) ?></li>
          <?php endforeach ?>
        </ol>
        <p class="muted small">“Concluído” encerra o atendimento inicial (análise, conversa e proposta). Não significa que a dívida foi resolvida: a atuação contratada, se houver, continua depois.</p>
      </div>
      <aside>
        <?php if (!$has_password): ?>
          <section class="panel" id="senha">
            <h2>Guarde seu acesso</h2>
            <p class="muted">Crie uma senha para voltar de qualquer aparelho. Seu protocolo é <strong><?= e($c['protocol']) ?></strong>.</p>
            <form method="post" action="<?= e(url('cliente/criar-senha')) ?>" class="stack">
              <?= csrf_field() ?>
              <label class="field"><span>Senha <small>(mínimo 8 caracteres)</small></span><input type="password" name="password" minlength="8" autocomplete="new-password" required></label>
              <label class="field"><span>Repita a senha</span><input type="password" name="password2" minlength="8" autocomplete="new-password" required></label>
              <button class="btn btn-ghost" type="submit">Criar senha</button>
            </form>
          </section>
        <?php endif ?>
        <section class="panel"><h2>Segurança</h2><p class="muted">Sidney só usa o WhatsApp <?= e(office('phone_display')) ?> e o e-mail <?= e(office('email')) ?>. Nunca pedimos senha do banco ou do Gov.br.</p></section>
      </aside>
    </div>

<?php elseif ($area === 'conversa'): ?>
    <section class="app-chat" aria-labelledby="h-cv">
      <h2 id="h-cv" class="sr">Conversa</h2>
      <div class="cv-log" data-chat-log data-poll="<?= e(url('cliente/conversa/novas')) ?>" data-last="<?= $chat ? (int) end($chat)['id'] : 0 ?>" aria-live="polite">
        <p class="cv-day-note">O assistente <?= e(ASSISTANT_NAME) ?> e Sidney aparecem identificados. <?= $c['chat_mode'] === 'human' ? 'Agora é Sidney quem está conversando com você.' : 'O assistente organiza; Sidney confere tudo.' ?></p>
        <?php partial('chat_msgs', ['msgs' => $chat, 'operator' => false, 'c' => $c]) ?>
      </div>
      <div class="app-compose<?= agent_active($c) ? ' app-compose--ai' : '' ?>">

      <?php if (agent_active($c)): $docOpts = array_filter($docs, fn($d) => $d['status'] !== 'dispensado'); ?>
        <div data-agent-bar><?php partial('cv_agent_bar', ['c' => $c]) ?></div>
        <form class="cv-composer" method="post" action="<?= e(url('cliente/conversa/mensagem')) ?>" enctype="multipart/form-data" id="fim" data-chat-form data-reading="Enviando e lendo o arquivo…">
          <?= csrf_field() ?><input type="hidden" name="k" value="<?= e(bin2hex(random_bytes(8))) ?>" data-key>
          <div class="cv-preview" data-preview-box></div>
          <?php if ($docOpts): ?>
            <label class="cv-doc-choice" data-doc-choice><span>O arquivo é</span>
              <select name="doc"><option value="">o assistente identifica</option><?php foreach ($docOpts as $d): ?><option value="<?= (int) $d['id'] ?>" <?= selected($d['kind'], (string) case_data($c, 'agent_doc')) ?>><?= e($d['label']) ?></option><?php endforeach ?></select>
            </label>
          <?php endif ?>
          <div class="cv-rec" data-rec-bar hidden><span class="cv-rec-dot" aria-hidden="true"></span> Gravando <span data-rec-time>0:00</span><button type="button" class="btn btn-gold btn-small" data-rec-stop>Enviar áudio</button><button type="button" class="link-btn" data-rec-cancel>Cancelar</button></div>
          <div class="cv-composer-row">
            <label class="cv-icon-btn" title="Anexar foto, PDF ou áudio"><span class="sr">Anexar foto, PDF ou áudio</span>
              <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M21.4 11.1l-9.2 9.2a6 6 0 0 1-8.5-8.5l9.2-9.2a4 4 0 0 1 5.7 5.7l-9.2 9.2a2 2 0 0 1-2.8-2.8l8.5-8.5"/></svg>
              <input type="file" name="arquivo[]" accept="application/pdf,image/jpeg,image/png,image/webp,image/heic,audio/*" multiple data-preview>
            </label>
            <label class="cv-input"><span class="sr">Sua mensagem</span><textarea name="message" rows="1" placeholder="Escreva sua mensagem…" data-chat-input></textarea></label>
            <button type="button" class="cv-icon-btn" data-rec hidden title="Gravar áudio" aria-label="Gravar áudio"><svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3zM19 10v2a7 7 0 0 1-14 0v-2M12 19v3"/></svg></button>
            <button type="submit" class="cv-send" aria-label="Enviar"><svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path fill="currentColor" d="M3 20.5 21 12 3 3.5l.01 6.6L15 12 3.01 13.9z"/></svg></button>
          </div>
          <p class="cv-hint muted small">Escreva do seu jeito, toque numa sugestão, grave um áudio ou anexe fotos e PDFs. Sidney confere tudo.</p>
        </form>
        <form method="post" action="<?= e(url('cliente/conversa/modo')) ?>" class="cv-modo"><?= csrf_field() ?><button class="link-btn" type="submit" name="modo" value="botoes">Prefiro responder com perguntas e botões</button></form>
      <?php else: ?>
      <?php if ($q): ?>
          <div class="cv-q" id="pergunta">
            <div class="cv-msg cv-bot"><div class="cv-bubble"><span class="cv-who"><?= e(ASSISTANT_NAME) ?><?= !empty($q['editing']) ? ' · corrigindo' : '' ?></span><div class="cv-text"><?= nl2br(e((string) $q['ask'])) ?></div></div></div>
            <?php if ($q['kind'] === 'doc'): $d = $q['doc']; ?>
              <form class="cv-answer" method="post" action="<?= e(url('cliente/conversa/documento/' . $d['id'])) ?>" enctype="multipart/form-data" data-save<?= llm_enabled() ? ' data-reading="Enviando e lendo o documento. Pode levar até um minuto; não feche a página."' : '' ?>>
                <?= csrf_field() ?><input type="hidden" name="k" value="<?= e($form_key) ?>">
                <label class="field"><span>Arquivo (PDF ou foto, até <?= (int) config('upload_max_mb') ?> MB)</span><input type="file" name="arquivo[]" accept="application/pdf,image/jpeg,image/png" multiple data-preview></label>
                <div class="cv-preview" data-preview-box></div>
                <div class="cv-btns">
                  <button class="btn btn-gold" type="submit" name="choice" value="enviar">Tenho e vou enviar</button>
                  <button class="btn btn-ghost btn-small" type="submit" name="choice" value="depois" formnovalidate>Vou enviar depois</button>
                  <button class="btn btn-ghost btn-small" type="submit" name="choice" value="nao_tenho" formnovalidate>Não tenho</button>
                </div>
              </form>
            <?php elseif ($q['kind'] === 'final'): ?>
              <div class="cv-summary">
                <h3>Seus dados</h3>
                <dl class="facts">
                  <?php foreach (['nome', 'telefone', 'aviso', 'objetivo_prioridade', 'situacao_contrato', 'banco', 'parcela', 'reuniao_canal'] as $k): if (!isset($fields[$k])) { continue; } ?>
                    <div><dt><?= e(field_def($k)['label']) ?></dt><dd><?= e(field_display($k, $fields[$k])) ?></dd></div>
                  <?php endforeach ?>
                </dl>
                <h3>Documentos</h3>
                <ul class="cv-docs-mini">
                  <?php foreach ($docs as $d): [$label, $sub] = client_doc_state($d); ?><li><?= e($d['label']) ?>: <strong><?= e($label) ?></strong><?= $sub ? ' · ' . e($sub) : '' ?></li><?php endforeach ?>
                </ul>
                <p>Próximo passo: Sidney confere os documentos e prepara a conversa com você.</p>
              </div>
              <form class="cv-answer" method="post" action="<?= e(url('cliente/conversa/responder')) ?>" data-save>
                <?= csrf_field() ?><input type="hidden" name="q" value="final"><input type="hidden" name="k" value="<?= e($form_key) ?>">
                <div class="cv-btns"><button class="btn btn-gold" type="submit">Está correto, pode enviar para Sidney</button></div>
                <p class="muted small">Para corrigir alguma resposta, use “Corrigir resposta” na conversa acima.</p>
              </form>
            <?php else: ?>
              <form class="cv-answer" method="post" action="<?= e(url('cliente/conversa/responder')) ?>" data-save>
                <?= csrf_field() ?><input type="hidden" name="q" value="<?= e($q['key']) ?>"><input type="hidden" name="k" value="<?= e($form_key) ?>">
                <?php if ($q['kind'] === 'choice'): $opts = field_def($q['field'])['options']; ?>
                  <div class="cv-options" role="radiogroup" aria-label="Respostas">
                    <?php foreach ($opts as $k => $l): ?><button class="cv-opt" type="submit" name="value" value="<?= e($k) ?>"><?= e($l) ?></button><?php endforeach ?>
                  </div>
                  <div class="cv-btns"><?= $choiceButtons($q) ?></div>
                <?php elseif ($q['kind'] === 'confirm'): ?>
                  <div class="cv-btns"><button class="btn btn-gold" type="submit" name="value" value="sim">Sim, está correto</button></div>
                  <label class="field"><span>Ou escreva o que precisa corrigir</span><textarea name="correction" rows="2"><?= e($oldVal('correction')) ?></textarea></label>
                  <div class="cv-btns"><button class="btn btn-ghost btn-small" type="submit" name="value" value="corrigir">Enviar correção</button></div>
                <?php elseif ($q['kind'] === 'group'): ?>
                  <div class="cv-grid">
                    <?php foreach ($q['fields'] as $f): $def = field_def($f); ?>
                      <label class="field <?= in_array($f, ['logradouro'], true) ? 'cv-wide' : '' ?>"><span><?= e($def['label']) ?><?= in_array($f, $q['optional'] ?? [], true) ? ' <small>(opcional)</small>' : '' ?></span>
                        <?php if ($def['type'] === 'choice'): ?>
                          <select name="<?= e($f) ?>"><option value="">—</option><?php foreach ($def['options'] as $k => $l): ?><option value="<?= e($k) ?>" <?= selected($k, $oldVal($f) ?: field_input_value($f, $fields[$f] ?? null)) ?>><?= e($l) ?></option><?php endforeach ?></select>
                        <?php else: ?>
                          <input type="text" name="<?= e($f) ?>" value="<?= e($oldVal($f) ?: field_input_value($f, $fields[$f] ?? null)) ?>" <?= $f === 'cep' ? 'inputmode="numeric" autocomplete="postal-code"' : ($f === 'logradouro' ? 'autocomplete="address-line1"' : ($f === 'cidade' ? 'autocomplete="address-level2"' : '')) ?>>
                        <?php endif ?>
                      </label>
                    <?php endforeach ?>
                  </div>
                  <div class="cv-btns"><button class="btn btn-gold" type="submit">Enviar</button><?= $choiceButtons($q) ?></div>
                <?php else:
                  $def = field_def($q['field']);
                  $cur = $oldVal('value') ?: (!empty($q['editing']) ? field_input_value($q['field'], $fields[$q['field']] ?? null) : (string) ($q['suggest'] ?? ''));
                  $attrs = match ($def['type']) {
                      'money', 'rate' => 'type="text" inputmode="decimal"',
                      'int', 'cpf', 'cep' => 'type="text" inputmode="numeric"',
                      'date' => 'type="date"',
                      default => 'type="text"',
                  };
                ?>
                  <label class="field"><span class="sr"><?= e($def['label']) ?></span>
                    <?php if ($def['type'] === 'textarea'): ?>
                      <textarea name="value" rows="3"><?= e($cur) ?></textarea>
                    <?php else: ?>
                      <input <?= $attrs ?> name="value" value="<?= e($cur) ?>" placeholder="<?= e(match ($def['type']) { 'money' => 'Ex.: 1.450,00', 'rate' => 'Ex.: 1,89', 'cpf' => 'Somente números', default => '' }) ?>" autocomplete="off">
                    <?php endif ?>
                  </label>
                  <div class="cv-btns"><button class="btn btn-gold" type="submit">Enviar</button><?= $choiceButtons($q) ?></div>
                <?php endif ?>
              </form>
            <?php endif ?>
            <p class="cv-saving" data-saving hidden aria-live="polite">Salvando…</p>
          </div>
        <?php elseif (!$completed && $c['chat_mode'] === 'bot'): ?>
          <p class="notice" id="pergunta"><?= e(BOT_DONE) ?></p>
        <?php endif ?>
  
        <form class="cv-free" method="post" action="<?= e(url('cliente/conversa/mensagem')) ?>" id="fim" data-save>
          <?= csrf_field() ?><input type="hidden" name="k" value="<?= e(bin2hex(random_bytes(8))) ?>">
          <label class="field"><span><?= $c['chat_mode'] === 'human' ? 'Escreva para Sidney' : 'Tem uma dúvida ou algo fora das perguntas? Escreva aqui' ?></span><textarea name="message" rows="2" required></textarea></label>
          <div class="cv-btns">
            <button class="btn <?= $c['chat_mode'] === 'human' ? 'btn-gold' : 'btn-ghost' ?>" type="submit">Enviar mensagem</button>
          </div>
        </form>
        <?php if (agent_enabled() && $c['chat_mode'] === 'bot' && !$completed && case_data($c, 'agent_off')): ?>
          <form method="post" action="<?= e(url('cliente/conversa/modo')) ?>" class="cv-modo"><?= csrf_field() ?><button class="link-btn" type="submit" name="modo" value="ia">Voltar a conversar livremente com o assistente</button></form>
        <?php endif ?>
      <?php endif ?>
      </div>
    </section>

<?php elseif ($area === 'documentos'): ?>
    <section class="panel" aria-labelledby="h-docs">
      <h2 id="h-docs">Seus documentos</h2>
      <p class="muted">“Recebido” quer dizer que chegou; Sidney ainda confere se está completo e legível. Se não tiver algum item, avise: o atendimento continua.</p>
      <div class="doc-rows">
        <?php foreach ($docs as $d): [$label, $sub, $tone] = client_doc_state($d); $cat = DOC_CATALOG[$d['kind']] ?? null; $isOpen = in_array($d['status'], DOC_OPEN, true); ?>
          <div class="doc-row" id="doc-<?= (int) $d['id'] ?>">
            <div class="doc-row-head"><strong><?= e($d['label']) ?></strong> <span class="badge badge-<?= e($tone) ?>"><?= e($label) ?></span><?= $sub ? ' <small class="muted">' . e($sub) . '</small>' : '' ?></div>
            <?php if ($cat && $isOpen): ?><p class="muted small"><?= e($cat['why']) ?> <?= e($cat['how']) ?></p><?php endif ?>
            <?php foreach ($filesByDoc[(int) $d['id']] ?? [] as $f): ?><a class="files-mini" href="<?= e(url('arquivo/' . $f['id'])) ?>" target="_blank" rel="noopener"><?= e($f['original_name']) ?></a> <small class="muted"><?= e(fmt_date($f['uploaded_at'])) ?></small><br><?php endforeach ?>
            <?php if ($isOpen && !$completed): ?>
              <form method="post" action="<?= e(url('cliente/conversa/documento/' . $d['id'])) ?>" enctype="multipart/form-data" class="cv-doc-form" data-save<?= llm_enabled() ? ' data-reading="Enviando e lendo o documento. Pode levar até um minuto."' : '' ?>>
                <?= csrf_field() ?><input type="hidden" name="k" value="<?= e(bin2hex(random_bytes(8))) ?>"><input type="hidden" name="volta" value="documentos">
                <input type="file" name="arquivo[]" accept="application/pdf,image/jpeg,image/png" multiple aria-label="Arquivo de <?= e($d['label']) ?>" data-preview>
                <div class="cv-preview" data-preview-box></div>
                <div class="cv-btns">
                  <button class="btn btn-gold btn-small" type="submit" name="choice" value="enviar">Enviar</button>
                  <?php if ($d['status'] !== 'nao_possui'): ?><button class="btn btn-ghost btn-small" type="submit" name="choice" value="nao_tenho" formnovalidate>Não tenho</button><?php endif ?>
                </div>
              </form>
            <?php endif ?>
          </div>
        <?php endforeach ?>
      </div>
      <?php $openItems = array_filter($pendings, fn($p) => $p['src'] === 'item'); if ($openItems): ?>
        <h3>Outras pendências</h3>
        <ul><?php foreach ($openItems as $p): ?><li><?= e($p['description']) ?></li><?php endforeach ?></ul>
        <p class="muted small">Responda pela <a href="<?= e(url('cliente', ['area' => 'conversa'])) ?>#fim">conversa</a>.</p>
      <?php endif ?>
      <p class="muted small">Formatos aceitos: PDF, JPG ou PNG, até <?= (int) config('upload_max_mb') ?> MB por arquivo. Se seu celular salvar em outro formato, tire um print da foto ou mande pelo WhatsApp que Sidney registra para você.</p>
    </section>

<?php else: ?>
    <div class="portal-grid">
      <div>
        <section class="panel" aria-labelledby="h-reu">
          <h2 id="h-reu">Reunião com Sidney</h2>
          <?php if ($meeting): ?>
            <p class="cv-status">Confirmada para <strong><?= e(fmt_datetime($meeting['starts_at'])) ?></strong> (horário de Brasília), por <strong><?= e(MEETING_CHANNELS[$meeting['channel']]['short']) ?></strong>.</p>
            <?php if ($meeting['channel'] === 'phone'): ?><p>Sidney vai ligar para <strong><?= e(format_phone((string) $meeting['phone'])) ?></strong>. Se o número estiver errado, avise na conversa.</p>
              <form method="post" action="<?= e(url('cliente/conversa/mensagem')) ?>"><?= csrf_field() ?><input type="hidden" name="k" value="tel-<?= (int) $meeting['id'] ?>"><input type="hidden" name="message" value="Confirmo meu telefone <?= e(format_phone((string) $meeting['phone'])) ?> para a reunião."><button class="btn btn-gold" type="submit">Confirmar meu telefone</button></form>
            <?php endif ?>
            <?= $meetingAction($meeting) ?>
            <?php if ($meeting['note']): ?><p class="muted"><?= e($meeting['note']) ?></p><?php endif ?>
          <?php elseif ($held): ?>
            <p>Conversa realizada em <?= e(fmt_datetime($held['held_at'])) ?>.</p>
          <?php else: ?>
            <p class="muted">A reunião ainda não foi confirmada. <?= isset($fields['reuniao_canal']) ? 'Sua preferência: ' . e(field_display('reuniao_canal', $fields['reuniao_canal'])) . '. ' : '' ?>Sidney confirma o horário depois de analisar seu caso.</p>
          <?php endif ?>
          <?php if ($held && $held['summary']): ?>
            <h3>Resumo da conversa</h3>
            <p><?= nl2br(e($held['summary'])) ?></p>
            <p class="muted small">Se alguma informação estiver diferente, corrija pela <a href="<?= e(url('cliente', ['area' => 'conversa'])) ?>#fim">conversa</a>.</p>
          <?php endif ?>
        </section>
      </div>
      <aside>
        <section class="panel" aria-labelledby="h-prop">
          <h2 id="h-prop">Proposta e documentos</h2>
          <?php if (!$kitPub): ?><p class="muted">Quando Sidney disponibilizar a proposta ou os documentos, eles aparecem aqui.</p><?php endif ?>
          <ul class="cv-kit">
            <?php foreach ($kitPub as $kind => $v): ?>
              <li><a href="<?= e(url('cliente/documento-kit/' . $v['id'])) ?>" target="_blank" rel="noopener"><?= e(KIT_ITEMS[$kind]['label']) ?></a> <small class="muted">versão <?= (int) $v['version'] ?> · <?= e(fmt_date($v['published_at'])) ?></small></li>
            <?php endforeach ?>
          </ul>
          <?php if ($st === 'awaiting_decision'): ?>
            <p><?= $c['decision'] === 'aceitou' && $c['contract_state'] !== 'formalizado' ? 'Você aceitou a proposta. Falta a assinatura dos documentos; Sidney orienta como assinar.' : 'Pense com calma. Se tiver dúvida, escreva na conversa. Nada é aceito em seu nome sem a sua confirmação.' ?></p>
          <?php endif ?>
          <?php if ($completed): ?><p><strong><?= e(DECISIONS[$c['decision']]) ?>.</strong> <?= e((string) $c['closure_reason']) ?></p><?php endif ?>
        </section>
      </aside>
    </div>
<?php endif ?>
<?php if ($area !== 'conversa'): ?>
    </div></div>
<?php endif ?>
</section>
