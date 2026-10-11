<?php
/** Mensagens da conversa: assistente, cliente e Sidney no mesmo histórico. */
$who = ['bot' => 'Assistente virtual', 'client' => $operator ? first_name((string) $c['client_name']) : 'Você', 'operator' => 'Sidney'];
$editable = !$operator && ($c['chat_mode'] ?? '') === 'bot' && is_initial($c) && ($c['initial_status'] ?? '') !== 'completed';
foreach ($msgs as $m): ?>
  <div class="cv-msg cv-<?= e($m['author']) ?>" data-id="<?= (int) $m['id'] ?>">
    <div class="cv-bubble">
      <span class="cv-who"><?= e($who[$m['author']] ?? $m['author']) ?> · <time datetime="<?= e(str_replace(' ', 'T', $m['created_at'])) ?>"><?= e(fmt_datetime($m['created_at'])) ?></time></span>
      <div class="cv-text"><?= nl2br(e($m['body'])) ?></div>
      <?php if ($editable && $m['author'] === 'client' && $m['question'] && !str_starts_with($m['question'], 'doc_') && $m['question'] !== 'final'): ?>
        <form method="post" action="<?= e(url('cliente/conversa/corrigir')) ?>" class="cv-fix"><?= csrf_field() ?><input type="hidden" name="q" value="<?= e($m['question']) ?>"><button type="submit" class="cv-link">Corrigir resposta</button></form>
      <?php endif ?>
    </div>
  </div>
<?php endforeach;
