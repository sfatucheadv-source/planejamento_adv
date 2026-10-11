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
  <h2 id="ia">Inteligência artificial</h2>
  <p class="hint">Com a IA ligada, o assistente pede os documentos logo no começo, lê os dados (contrato, identidade, comprovante de residência, extrato) e só pergunta ao cliente o que não estiver neles. Também responde às mensagens livres, sem dar orientação jurídica. Os dados lidos ficam marcados “IA · conferir” na ficha até você confirmar.</p>
  <label class="chk"><input type="checkbox" name="llm_enabled" value="1" <?= checked(setting('llm_enabled') === '1') ?>> Usar IA no assistente</label>
  <fieldset class="radios at-channels"><legend class="lbl">Fornecedor</legend>
    <?php foreach (LLM_PROVIDERS as $pk => $pv): ?>
      <label class="chk"><input type="radio" name="llm_provider" value="<?= e($pk) ?>" <?= checked(llm_provider() === $pk) ?>> <?= e($pv['label']) ?><?= llm_key($pk) ? ' <small>(chave ' . e(llm_key_hint($pk)) . ')</small>' : '' ?></label>
    <?php endforeach ?>
  </fieldset>
  <?php foreach (LLM_PROVIDERS as $pk => $pv):
    $keyName = $pk === 'openrouter' ? 'llm_api_key' : 'gemini_api_key';
    $modelName = $pk === 'openrouter' ? 'llm_model' : 'gemini_model';
    $list = $pv['models'] + ($models[$pk] ?? []);
  ?>
    <details class="at-inline" <?= llm_provider() === $pk ? 'open' : '' ?>>
      <summary><?= e($pv['label']) ?><?= llm_provider() === $pk ? ' · em uso' : '' ?></summary>
      <label class="fld"><span>Modelo</span>
        <input name="<?= $modelName ?>" list="models-<?= e($pk) ?>" value="<?= e(llm_model($pk)) ?>" autocomplete="off" spellcheck="false">
        <datalist id="models-<?= e($pk) ?>"><?php foreach ($list as $id => $label): ?><option value="<?= e($id) ?>"><?= e($label) ?></option><?php endforeach ?></datalist>
      </label>
      <p class="hint">Sugestões: <?= e(implode(' · ', array_keys($pv['models']))) ?>. <a href="<?= e(url('painel/configuracoes', ['modelos' => $pk])) ?>#ia">Carregar a lista completa</a><?= !empty($models[$pk]) ? ' (' . count($models[$pk]) . ' modelos)' : '' ?>.</p>
      <label class="fld"><span>Chave de API <?= llm_key($pk) ? '<small>(configurada, termina em ' . e(llm_key_hint($pk)) . ')</small>' : '<small>(não configurada)</small>' ?></span>
        <input type="password" name="<?= $keyName ?>" autocomplete="new-password" placeholder="<?= llm_key($pk) ? 'Deixe em branco para manter a chave atual' : ($pk === 'openrouter' ? 'sk-or-v1-…' : 'Chave do Google AI Studio') ?>" spellcheck="false">
      </label>
      <?php if (llm_key($pk)): ?><label class="chk"><input type="checkbox" name="remove_key_<?= e($pk) ?>" value="1"> Remover esta chave</label><?php endif ?>
      <p class="hint"><?= $pk === 'openrouter' ? 'Crie a chave e limite o gasto em openrouter.ai › Keys. Um só cadastro dá acesso a modelos de vários fornecedores.' : 'Crie a chave em aistudio.google.com › Get API key (projeto do Google Cloud) e acompanhe o uso e o faturamento no mesmo projeto. No plano gratuito, o Google pode usar o conteúdo para melhorar os produtos; para documentos de clientes, use um projeto com faturamento ativo.' ?></p>
    </details>
  <?php endforeach ?>
  <label class="chk"><input type="checkbox" name="llm_test" value="1"> Testar a conexão do fornecedor escolhido ao salvar</label>
  <p class="hint">As chaves ficam guardadas só no servidor e nunca aparecem de novo nesta tela. Os documentos enviados pelo cliente são mandados ao fornecedor escolhido para leitura; o aviso de privacidade do site informa isso.</p>
  <?php if ($usage['calls']): ?>
    <p class="small">Últimos 30 dias: <?= (int) $usage['calls'] ?> chamada(s), <?= (int) $usage['errors'] ?> com erro<?= $usage['cost'] > 0 ? ', custo informado de US$ ' . e(number_format($usage['cost'], 4, ',', '.')) : '' ?>.<?php if ($usage['last_error']): ?> Último erro (<?= e(fmt_datetime($usage['last_error']['created_at'])) ?>): <?= e(mb_strimwidth((string) $usage['last_error']['error'], 0, 160, '…')) ?><?php endif ?></p>
  <?php endif ?>
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
