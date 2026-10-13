<div class="page-head"><div><h1>Equipe</h1><p class="sub">Cada caso precisa de gestor, advogado e substituto identificados. Uma pessoa pode acumular funções.</p></div></div>
<div class="grid-main-side">
  <section class="box">
    <div class="tbl-wrap"><table class="tbl">
      <thead><tr><th>Nome</th><th>E-mail</th><th>Funções</th><th>Casos ativos</th><th>Para assinar</th><th>Situação</th></tr></thead>
      <tbody>
        <?php foreach ($users as $u): $n = (int) scalar("SELECT COUNT(*) FROM cases WHERE status = 'ativo' AND (gestor_id = ? OR advogado_id = ?)", [$u['id'], $u['id']]); ?>
          <tr><td><strong><?= e($u['name']) ?></strong></td><td><?= e($u['email']) ?></td><td><?= e(implode(', ', array_map(fn($r) => ROLES[$r] ?? $r, user_roles($u)))) ?></td><td><?= $n ?></td><td><?php $falta = array_merge(team_profile_missing($u), team_signature($u) ? [] : ['rubrica']); ?><?= $falta ? '<span class="tag tag-amber" title="' . e(implode(', ', $falta)) . '">dados incompletos</span>' : '<span class="tag tag-green">pronto</span>' ?><?php $sm = team_sign_method($u); ?> <span class="tag <?= $sm === 'senha' ? 'tag-gray' : 'tag-blue' ?>"><?= e(TEAM_SIGN_METHODS[$sm]) ?></span></td><td><?= $u['active'] ? '<span class="tag tag-green">ativo</span>' : '<span class="tag tag-gray">inativo</span>' ?></td></tr>
        <?php endforeach ?>
      </tbody>
    </table></div>
  </section>
  <aside class="box">
    <h2>Adicionar ou editar</h2>
    <?php if (!has_role('supervisor')): ?><p class="hint">Somente quem tem a função de supervisor altera a equipe.</p><?php endif ?>
    <form method="post" action="<?= e(url('painel/equipe')) ?>" class="stack">
      <?= csrf_field() ?>
      <label class="fld"><span>Editar existente</span><select name="user_id"><option value="">— novo usuário —</option><?php foreach ($users as $u): ?><option value="<?= (int) $u['id'] ?>"><?= e($u['name']) ?></option><?php endforeach ?></select></label>
      <label class="fld"><span>Nome</span><input name="name" required></label>
      <label class="fld"><span>E-mail</span><input type="email" name="email" required></label>
      <label class="fld"><span>Telefone</span><input name="phone"></label>
      <fieldset class="sub-fs"><legend>Funções</legend><?php foreach (ROLES as $k => $l): ?><label class="chk"><input type="checkbox" name="roles[]" value="<?= $k ?>"> <?= e($l) ?></label><?php endforeach ?></fieldset>
      <label class="fld"><span>Senha <small>(mín. 10; deixe vazio ao editar para manter)</small></span><input type="password" name="password" autocomplete="new-password"></label>
      <label class="fld"><span>Situação</span><select name="active"><option value="1">Ativo</option><option value="0">Inativo</option></select></label>
      <button class="b b-primary" type="submit">Salvar</button>
    </form>
  </aside>
</div>
