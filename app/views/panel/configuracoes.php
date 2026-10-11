<?php $opId = operator_id(); ?>
<div class="page-head"><div><h1>Configurações</h1><p class="sub">Ajustes da operação. Nada aqui apaga atendimentos.</p></div></div>
<form class="box form narrow-box" method="post" action="<?= e(url('painel/configuracoes')) ?>">
  <?= csrf_field() ?>
  <h2>Operador dos atendimentos</h2>
  <label class="fld"><span>Quem recebe e conduz os novos atendimentos</span>
    <select name="operator_user_id"><option value="0">Nenhum (modo de equipe antigo)</option><?php foreach ($users as $x): ?><option value="<?= (int) $x['id'] ?>" <?= selected((int) $x['id'], $opId) ?>><?= e($x['name']) ?> · <?= e($x['email']) ?></option><?php endforeach ?></select>
  </label>
  <p class="hint">Todo novo atendimento pertence a esta pessoa. Os atendimentos só aparecem para ela.</p>
  <h2>Entrada pelo site</h2>
  <label class="chk"><input type="radio" name="intake_workflow" value="simple_v1" <?= checked(intake_workflow() === 'simple_v1') ?>> Assistente virtual (atendimento inicial com Sidney)</label>
  <label class="chk"><input type="radio" name="intake_workflow" value="legacy" <?= checked(intake_workflow() === 'legacy') ?>> Formulário antigo de seis passos (reversão)</label>
  <p class="hint">A reversão só muda como as novas entradas chegam. Os atendimentos já registrados continuam como estão.</p>
  <h2>Dados do escritório para os documentos</h2>
  <label class="fld"><span>Endereço profissional</span><input name="office_address" value="<?= e((string) setting('office_address', '')) ?>" placeholder="Rua, número, bairro, cidade/UF, CEP"></label>
  <p class="hint">Nome, OAB e CNPJ vêm do arquivo de configuração: <?= e(office('society')) ?> · <?= e(office('society_oab')) ?> · CNPJ <?= e(office('cnpj')) ?> · <?= e(office('lawyer')) ?> (<?= e(office('lawyer_oab')) ?>).</p>
  <button class="b b-primary" type="submit">Salvar</button>
</form>
<div class="box narrow-box">
  <h2>Outras configurações</h2>
  <ul>
    <li><a href="<?= e(url('painel/modelos-documentos')) ?>">Modelos de documentos</a></li>
    <li><a href="<?= e(url('painel/migracao')) ?>">Migração dos atendimentos anteriores</a></li>
    <li><a href="<?= e(url('painel/equipe')) ?>">Usuários com acesso ao painel</a> <small>(somente supervisor)</small></li>
  </ul>
</div>
