<div class="page-head">
  <div>
    <h1>Modelos de documentos</h1>
    <p class="sub">Os textos do kit são preenchidos com os dados da ficha. Você edita o texto aqui, sem mexer no código. Cada alteração cria uma nova versão, que precisa da sua aprovação antes de qualquer publicação ao cliente.</p>
  </div>
</div>
<div class="grid-main-side">
  <div>
    <?php foreach ($templates as $t): ?>
      <section class="box" id="m-<?= e($t['key']) ?>">
        <div class="box-head">
          <h2><?= e($t['title']) ?> <small class="muted">· versão <?= (int) $t['version'] ?></small></h2>
          <?php if ($t['approved_at']): ?><span class="tag tag-green">aprovado em <?= e(fmt_date($t['approved_at'])) ?></span><?php else: ?><span class="tag tag-amber">aguardando aprovação</span><?php endif ?>
        </div>
        <?php if ($edit === $t['key']): ?>
          <form method="post" action="<?= e(url('painel/modelos-documentos')) ?>" class="stack">
            <?= csrf_field() ?><input type="hidden" name="key" value="<?= e($t['key']) ?>"><input type="hidden" name="op" value="salvar">
            <label class="fld"><span>Título</span><input name="title" value="<?= e($t['title']) ?>"></label>
            <label class="fld"><span>Texto do modelo</span><textarea name="body" rows="22" class="at-doc-edit"><?= e($t['body']) ?></textarea></label>
            <div class="inline-form"><button class="b b-primary" type="submit">Salvar nova versão</button><a class="b" href="<?= e(url('painel/modelos-documentos')) ?>">Cancelar</a></div>
          </form>
        <?php else: ?>
          <pre class="at-tpl"><?= e($t['body']) ?></pre>
          <div class="inline-form">
            <a class="b" href="<?= e(url('painel/modelos-documentos', ['editar' => $t['key']])) ?>#m-<?= e($t['key']) ?>">Editar</a>
            <?php if (!$t['approved_at']): ?>
              <form method="post" action="<?= e(url('painel/modelos-documentos')) ?>"><?= csrf_field() ?><input type="hidden" name="key" value="<?= e($t['key']) ?>"><input type="hidden" name="op" value="aprovar"><button class="b b-primary" type="submit">Revisei: aprovar esta versão</button></form>
            <?php endif ?>
          </div>
        <?php endif ?>
      </section>
    <?php endforeach ?>
  </div>
  <aside class="box">
    <h2>Como escrever</h2>
    <p class="small">Use <code>{{variável}}</code> para inserir um dado. Para um texto padrão quando o dado não existir, use <code>{{variável|texto padrão}}</code>. Sem padrão, o dado que faltar aparece marcado e bloqueia a publicação.</p>
    <p class="small">Trechos que você ainda precisa escrever podem ficar como <code>[[COMPLETAR: descrição]]</code>; eles também bloqueiam a revisão.</p>
    <p class="small">Os modelos iniciais trazem só a estrutura. As cláusulas, os poderes e o texto da declaração são seus.</p>
    <h3>Variáveis disponíveis</h3>
    <ul class="small at-vars"><?php foreach ($vars as $k => $l): ?><li><code>{{<?= e($k) ?>}}</code> <?= e($l) ?></li><?php endforeach ?></ul>
    <p class="small">O endereço do escritório vem de <a href="<?= e(url('painel/configuracoes')) ?>">Configurações</a>.</p>
  </aside>
</div>
