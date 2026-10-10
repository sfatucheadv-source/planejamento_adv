<div class="grid-main-side">
  <section class="box">
    <h2>Linha do tempo</h2>
    <p class="hint">Itens marcados como “cliente vê” aparecem na área do cliente.</p>
    <ol class="tl">
      <?php foreach ($events as $ev): ?>
        <li class="<?= $ev['visible_to_client'] ? 'pub' : '' ?> ev-<?= e($ev['type']) ?>">
          <time><?= e(fmt_datetime($ev['at'])) ?></time>
          <strong><?= e($ev['title']) ?></strong>
          <small><?= $ev['actor'] === 'cliente' ? 'cliente' : e($ev['user_id'] ? user_name((int) $ev['user_id']) : 'sistema') ?><?= $ev['visible_to_client'] ? ' · cliente vê' : '' ?></small>
          <?php if ($ev['body']): ?><p><?= nl2br(e($ev['body'])) ?></p><?php endif ?>
        </li>
      <?php endforeach ?>
    </ol>
  </section>
  <aside>
    <section class="box">
      <h2>Nota interna</h2>
      <?= case_form($c, 'nota', ['back' => 'historico'], 'stack') ?><textarea name="text" rows="3" required></textarea><button class="b">Registrar</button></form>
    </section>
    <section class="box">
      <h2>Auditoria</h2>
      <p class="hint">Quem fez o quê, quando. Inclui abertura de arquivos.</p>
      <ul class="audit">
        <?php foreach ($audits as $a): ?><li><small><?= e(fmt_datetime($a['at'])) ?> · <?= e(user_name($a['user_id'] ? (int) $a['user_id'] : null)) ?></small> <?= e($a['action']) ?><?= $a['detail'] ? ': ' . e($a['detail']) : '' ?></li><?php endforeach ?>
      </ul>
    </section>
  </aside>
</div>
