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
  <h2 id="ia">Inteligência artificial (OpenRouter)</h2>
  <p class="hint">Com a IA ligada, o assistente pede os documentos logo no começo, lê os dados (contrato, identidade, comprovante de residência, extrato) e só pergunta ao cliente o que não estiver neles. Também responde às mensagens livres, sem dar orientação jurídica. Os dados lidos ficam marcados “IA · conferir” na ficha até você confirmar.</p>
  <label class="chk"><input type="checkbox" name="llm_enabled" value="1" <?= checked(setting('llm_enabled') === '1') ?>> Usar IA no assistente</label>
  <label class="fld"><span>Modelo (identificador do OpenRouter)</span>
    <input name="llm_model" list="llm-models" value="<?= e(llm_model()) ?>" autocomplete="off" spellcheck="false">
    <datalist id="llm-models">
      <?php foreach (LLM_SUGGESTED_MODELS + $models as $id => $label): ?><option value="<?= e($id) ?>"><?= e($label) ?></option><?php endforeach ?>
    </datalist>
  </label>
  <p class="hint">Use um modelo que leia PDF e foto. Sugestões: <?= e(implode(' · ', array_keys(LLM_SUGGESTED_MODELS))) ?>. <a href="<?= e(url('painel/configuracoes', ['modelos' => 1])) ?>#ia">Carregar a lista completa do OpenRouter</a><?= $models ? ' (' . count($models) . ' modelos com leitura de imagem ou arquivo)' : '' ?>.</p>
  <label class="fld"><span>Chave de API do OpenRouter <?= llm_key() ? '<small>(configurada, termina em ' . e(llm_key_hint()) . ')</small>' : '<small>(não configurada)</small>' ?></span>
    <input type="password" name="llm_api_key" autocomplete="new-password" placeholder="<?= llm_key() ? 'Deixe em branco para manter a chave atual' : 'sk-or-v1-…' ?>" spellcheck="false">
  </label>
  <?php if (llm_key()): ?><label class="chk"><input type="checkbox" name="llm_remove_key" value="1"> Remover a chave</label><?php endif ?>
  <label class="chk"><input type="checkbox" name="llm_test" value="1"> Testar a conexão ao salvar</label>
  <p class="hint">A chave fica guardada só no servidor e nunca aparece de novo nesta tela. Crie e limite o gasto da chave em openrouter.ai › Keys. Os documentos enviados pelo cliente são mandados ao provedor do modelo escolhido para leitura; o aviso de privacidade do site informa isso.</p>
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
