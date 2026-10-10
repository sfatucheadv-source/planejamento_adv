<?php
$tpl = (string) input('modelo', '');
$draft = $_SESSION['draft_' . $c['id']] ?? null;
$body = $draft ?? ($tpl && isset(MESSAGE_TEMPLATES[$tpl]) ? fill_template($tpl, $c) : '');
$code = $_SESSION['portal_code_' . $c['id']] ?? null;
unset($_SESSION['portal_code_' . $c['id']]);
?>
<div class="grid-main-side">
  <div>
    <section class="box">
      <h2>Escrever ao cliente</h2>
      <p class="hint">Escolha um modelo. Os campos conhecidos são preenchidos com os dados do caso; os que ficarem entre colchetes precisam ser completados. Confira fatos, datas e orientação jurídica antes de enviar. Uma pergunta ou um pequeno conjunto de ações por mensagem.</p>
      <nav class="chips-p">
        <?php foreach (MESSAGE_TEMPLATES as $k => $t): ?>
          <a class="<?= $tpl === $k ? 'on' : '' ?>" href="<?= e(url('painel/caso/' . $c['id'], ['aba' => 'mensagens', 'modelo' => $k])) ?>"><?= e($t['label']) ?></a>
        <?php endforeach ?>
      </nav>
      <?= case_form($c, 'mensagem', ['template' => $tpl], 'stack') ?>
        <label class="fld"><span>Mensagem</span><textarea name="body" rows="9" data-msg><?= e($body) ?></textarea></label>
        <div class="row2">
          <label class="fld"><span>Canal</span><select name="channel"><option value="whatsapp">WhatsApp</option><option value="ligacao">Ligação (resumo)</option><option value="email">E-mail</option><option value="area do cliente">Somente área do cliente</option></select></label>
          <label class="chk"><input type="checkbox" name="facts_checked" value="1"> Conferi fatos, datas e orientação jurídica</label>
        </div>
        <div class="actions">
          <a class="b" href="<?= e(whatsapp_link($body, $c['client_phone'])) ?>" target="_blank" rel="noopener" data-wa-link data-phone="<?= e(only_digits((string) $c['client_phone'])) ?>">Abrir no WhatsApp do cliente</a>
          <button class="b b-primary" type="submit">Registrar envio e publicar na área do cliente</button>
        </div>
      </form>
    </section>

    <section class="box">
      <h2>Mensagens enviadas</h2>
      <?php if (!$messages): ?><p class="empty">Nenhuma mensagem registrada.</p><?php endif ?>
      <?php foreach ($messages as $m): ?>
        <div class="msg"><small><?= e(fmt_datetime($m['sent_at'])) ?> · <?= e($m['channel']) ?> · <?= e(user_name($m['sent_by'] ? (int) $m['sent_by'] : null)) ?><?= $m['template'] ? ' · modelo ' . e(MESSAGE_TEMPLATES[$m['template']]['label'] ?? $m['template']) : '' ?></small><p><?= nl2br(e($m['body'])) ?></p></div>
      <?php endforeach ?>
    </section>
  </div>
  <aside>
    <section class="box">
      <h2>Acesso à área do cliente</h2>
      <?php if ($c['portal_pass_hash']): ?>
        <p class="ok-line">O cliente já tem senha.</p>
      <?php endif ?>
      <?php if ($code): ?>
        <p class="code-box">Protocolo <strong><?= e($c['protocol']) ?></strong><br>Código <strong><?= e($code) ?></strong><br><small>válido por 7 dias · mostrado só agora</small></p>
        <a class="b b-xs" target="_blank" rel="noopener" href="<?= e(whatsapp_link('Olá, ' . first_name($c['client_name']) . '! Para acompanhar seu caso pelo site, acesse ' . absolute_url('cliente/entrar') . ', escolha "Recebi um código da equipe" e use o protocolo ' . $c['protocol'] . ' e o código ' . $code . '. A área do cliente é opcional: seguimos também por aqui.', $c['client_phone'])) ?>">Enviar pelo WhatsApp</a>
      <?php else: ?>
        <p class="hint">Gere um código de uso único para o cliente criar a própria senha. Nunca peça senhas ao cliente.</p>
        <?= case_form($c, 'codigo_portal') ?><button class="b" type="submit"><?= $c['portal_pass_hash'] ? 'Gerar código para redefinir a senha' : 'Gerar código de acesso' ?></button></form>
      <?php endif ?>
    </section>
    <section class="box">
      <h2>Prévia do cartão</h2>
      <p class="quote-box small"><?= nl2br(e(fill_template('cartao', $c))) ?></p>
    </section>
  </aside>
</div>
