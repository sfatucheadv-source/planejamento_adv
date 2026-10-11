<div class="page-head">
  <div>
    <h1>Registrar atendimento</h1>
    <p class="sub">Para quem chegou por telefone, WhatsApp ou indicação. Depois você completa a ficha ou gera um código para o cliente continuar pelo assistente.</p>
  </div>
</div>
<form class="box form narrow-box" method="post" action="<?= e(url('painel/atendimentos/novo')) ?>">
  <?= csrf_field() ?>
  <label class="fld"><span>Nome</span><input name="nome" value="<?= e((string) ($old['nome'] ?? '')) ?>" required autocomplete="off"></label>
  <?php if (isset($errors['nome'])): ?><p class="err"><?= e($errors['nome']) ?></p><?php endif ?>
  <div class="row2">
    <label class="fld"><span>Telefone ou WhatsApp</span><input name="telefone" inputmode="tel" value="<?= e((string) ($old['telefone'] ?? '')) ?>" required></label>
    <label class="fld"><span>E-mail (opcional)</span><input type="email" name="email" value="<?= e((string) ($old['email'] ?? '')) ?>"></label>
  </div>
  <?php foreach (['telefone', 'email'] as $k): if (isset($errors[$k])): ?><p class="err"><?= e($errors[$k]) ?></p><?php endif; endforeach ?>
  <div class="row2">
    <label class="fld"><span>Como chegou</span><select name="source"><option value="whatsapp">WhatsApp</option><option value="telefone">Telefone</option><option value="indicacao">Indicação</option><option value="presencial">Presencial</option></select></label>
    <label class="fld"><span>Aviso, apreensão ou processo</span><select name="aviso"><option value="">Ainda não perguntei</option><?php foreach (NOTICE_OPTIONS as $k => $l): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach ?></select></label>
  </div>
  <label class="fld"><span>O que a pessoa contou</span><textarea name="relato" rows="4"><?= e((string) ($old['relato'] ?? '')) ?></textarea></label>
  <button class="b b-primary" type="submit">Abrir atendimento</button>
</form>
