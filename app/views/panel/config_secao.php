<?php
/** Uma área das configurações, com navegação entre as áreas e volta ao painel de controles. */
$opId = operator_id();
$intro = [
    'atendimento' => 'Quem recebe os novos atendimentos e por onde eles entram no site.',
    'ia' => 'Fornecedor, modelo e chave da inteligência artificial usada no atendimento e na leitura dos documentos.',
    'email' => 'Como saem os e-mails que avisam o cliente de novidade na conversa.',
    'asaas' => 'Conta do Asaas para criar as cobranças do contrato assinado.',
    'precos' => 'Tabela da OAB (referência dos honorários), acréscimo para quem vem por indicação e taxas do Asaas usadas no simulador de vendas da proposta.',
][$secao];
?>
<nav class="cfg-crumbs" aria-label="Caminho"><a href="<?= e(url('painel/configuracoes')) ?>">‹ Painel de controles</a></nav>
<div class="page-head"><div><h1 id="<?= e($secao) ?>"><?= e(CONFIG_SECTIONS[$secao]) ?></h1><p class="sub"><?= e($intro) ?></p></div></div>
<nav class="cfg-tabs" aria-label="Áreas das configurações">
  <?php foreach (CONFIG_SECTIONS as $k => $l): ?><a href="<?= e(url('painel/configuracoes/' . $k)) ?>" class="<?= $k === $secao ? 'on' : '' ?>"<?= $k === $secao ? ' aria-current="page"' : '' ?>><?= e($l) ?></a><?php endforeach ?>
</nav>
<form class="box form narrow-box cfg-form" method="post" action="<?= e(url('painel/configuracoes')) ?>">
  <?= csrf_field() ?><input type="hidden" name="secao" value="<?= e($secao) ?>">
<?php if ($secao === 'atendimento'): ?>
  <h3>Operador dos atendimentos</h3>
  <label class="fld"><span>Quem recebe e conduz os novos atendimentos</span>
    <select name="operator_user_id"><option value="0">Nenhum (modo de equipe antigo)</option><?php foreach ($users as $x): ?><option value="<?= (int) $x['id'] ?>" <?= selected((int) $x['id'], $opId) ?>><?= e($x['name']) ?> · <?= e($x['email']) ?></option><?php endforeach ?></select>
  </label>
  <p class="hint">Todo novo atendimento pertence a esta pessoa. Os atendimentos só aparecem para ela.</p>
  <h3>Entrada pelo site</h3>
  <label class="chk"><input type="radio" name="intake_workflow" value="simple_v1" <?= checked(intake_workflow() === 'simple_v1') ?>> Assistente virtual (atendimento inicial com Sidney)</label>
  <label class="chk"><input type="radio" name="intake_workflow" value="legacy" <?= checked(intake_workflow() === 'legacy') ?>> Formulário antigo de seis passos (reversão)</label>
  <p class="hint">A reversão só muda como as novas entradas chegam. Os atendimentos já registrados continuam como estão.</p>
  <h3>Dados do escritório para os documentos</h3>
  <p class="hint">Endereço, telefone, e-mail, prazo de resposta, orientação para urgências, chave PIX, foro padrão e as variáveis personalizadas ficam em <a href="<?= e(url('painel/variaveis')) ?>">Variáveis dos documentos</a>.</p>
<?php elseif ($secao === 'ia'): ?>
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
      <p class="hint">Sugestões: <?= e(implode(' · ', array_keys($pv['models']))) ?>. <a href="<?= e(url('painel/configuracoes/ia', ['modelos' => $pk])) ?>">Carregar a lista completa</a><?= !empty($models[$pk]) ? ' (' . count($models[$pk]) . ' modelos)' : '' ?>.</p>
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
<?php elseif ($secao === 'asaas'): ?>
  <p class="hint">Depois do contrato assinado, a tela da proposta cria no Asaas as cobranças com os mesmos valores e vencimentos do contrato: entrada, parcelas, pagamento à vista ou parcelamento no cartão.</p>
  <fieldset class="radios at-channels"><legend class="lbl">Ambiente</legend>
    <?php foreach (ASAAS_ENVS as $ek => $ev): ?>
      <label class="chk"><input type="radio" name="asaas_env" value="<?= e($ek) ?>" <?= checked(asaas_env() === $ek) ?>> <?= e($ev['label']) ?></label>
    <?php endforeach ?>
  </fieldset>
  <label class="fld"><span>Chave de API <?= asaas_key() ? '<small>(configurada, termina em ' . e(asaas_key_hint()) . ')</small>' : '<small>(não configurada)</small>' ?></span>
    <input type="password" name="asaas_api_key" autocomplete="new-password" placeholder="<?= asaas_key() ? 'Deixe em branco para manter a chave atual' : '$aact_…' ?>" spellcheck="false">
  </label>
  <?php if (asaas_key()): ?><label class="chk"><input type="checkbox" name="asaas_remove_key" value="1"> Remover esta chave</label><?php endif ?>
  <p class="hint">No Asaas: Integrações › Chaves de API › Gerar chave. Teste primeiro no Sandbox (conta separada em sandbox.asaas.com); a chave de produção começa com “$aact_prod_”.</p>
  <div class="var-grid">
    <label class="fld"><span>Multa por atraso (%)</span><input name="asaas_multa" inputmode="decimal" value="<?= e(str_replace('.', ',', (string) asaas_late_fees()['multa'])) ?>"></label>
    <label class="fld"><span>Juros de mora (% ao mês)</span><input name="asaas_juros" inputmode="decimal" value="<?= e(str_replace('.', ',', (string) asaas_late_fees()['juros'])) ?>"></label>
  </div>
  <p class="hint">Os mesmos da cláusula “do atraso no pagamento” do contrato (2% de multa e 1% ao mês). Mude aqui só se mudar o contrato.</p>
  <?php if (asaas_key()): ?>
    <p class="small"><strong>Webhook</strong> (Integrações › Webhooks › Adicionar, eventos de cobrança): URL <code><?= e(asaas_webhook_url()) ?></code> · token de autenticação <code><?= e(asaas_webhook_token()) ?></code>. Com ele, pagamentos e vencimentos aparecem sozinhos no atendimento; sem ele, use “Atualizar situação” na proposta.</p>
  <?php endif ?>
  <label class="chk"><input type="checkbox" name="asaas_test" value="1"> Testar a conexão com o Asaas ao salvar</label>
<?php elseif ($secao === 'precos'): $oab = oab_table(); $oab = array_merge($oab, array_fill(0, 3, ['key' => '', 'label' => '', 'valor' => null, 'nota' => ''])); $fees = asaas_fees(); ?>
  <h3>Tabela de honorários da OAB (referência)</h3>
  <p class="hint">Os valores mínimos da tabela da OAB/PR para os serviços que você oferece. Na proposta, você marca os serviços do caso e o painel soma o valor de referência. Consulte a tabela vigente em <a href="https://honorarios.oabpr.org.br/" target="_blank" rel="noopener">honorarios.oabpr.org.br</a>. Para incluir um serviço, use uma linha em branco; para tirar, apague o nome.</p>
  <div class="cfg-oab">
    <div class="cfg-oab-head"><span>Serviço</span><span>Valor mínimo (R$)</span><span>Item da tabela / observação</span></div>
    <?php foreach ($oab as $i => $it): ?>
      <div class="cfg-oab-row">
        <input type="hidden" name="oab_key[]" value="<?= e((string) $it['key']) ?>">
        <input name="oab_label[]" maxlength="160" value="<?= e((string) $it['label']) ?>" placeholder="Novo serviço" aria-label="Serviço">
        <input name="oab_valor[]" inputmode="decimal" value="<?= e(cents_input($it['valor'] ?? null)) ?>" placeholder="0,00" aria-label="Valor mínimo">
        <input name="oab_nota[]" maxlength="160" value="<?= e((string) ($it['nota'] ?? '')) ?>" placeholder="ex.: item 5.2" aria-label="Observação">
      </div>
    <?php endforeach ?>
  </div>
  <h3>Indicação</h3>
  <label class="fld cfg-short"><span>Acréscimo para quem vem por indicação (%)</span><input name="indicacao_pct" inputmode="decimal" value="<?= e(str_replace('.', ',', (string) indicacao_pct())) ?>"></label>
  <p class="hint">Quem chega indicado já conhece os seus resultados: a referência de preço é o valor da OAB com este acréscimo.</p>
  <h3>Taxas do Asaas <small class="muted"><?= asaas_fees_custom() ? '(personalizadas)' : '(padrão de referência)' ?></small></h3>
  <p class="hint">Usadas no simulador de vendas da proposta. Confira as da sua conta no Asaas, em Minha conta › Taxas, e ajuste aqui. Na proposta, o botão “Conferir no Asaas” consulta o simulador oficial da sua conta.</p>
  <div class="var-grid">
    <?php foreach (ASAAS_FEE_LABELS as $k => $l): ?>
      <label class="fld"><span><?= e($l) ?></span><input name="fee_<?= e($k) ?>" inputmode="decimal" value="<?= e($k === 'cartao_max' ? (string) (int) $fees[$k] : str_replace('.', ',', number_format($fees[$k], 2, '.', ''))) ?>"></label>
    <?php endforeach ?>
  </div>
  <label class="chk"><input type="checkbox" name="fees_padrao" value="1"> Voltar às taxas de referência</label>
<?php else: ?>
  <p class="hint">Quando uma conversa tem novidade que o cliente ainda não viu (mensagem sua, do assistente ou documento disponibilizado), ele recebe um e-mail com um link de acesso válido por <?= ACCESS_LINK_DAYS ?> dias. O e-mail não leva o conteúdo da conversa.</p>
  <fieldset class="radios at-channels"><legend class="lbl">Envio</legend>
    <?php foreach (MAIL_DRIVERS as $mk => $ml): ?>
      <label class="chk"><input type="radio" name="mail_driver" value="<?= e($mk) ?>" <?= checked(mail_driver() === $mk) ?>> <?= e($ml) ?></label>
    <?php endforeach ?>
  </fieldset>
  <div class="var-grid">
    <label class="fld"><span>Remetente (e-mail)</span><input type="email" name="mail_from" value="<?= e((string) setting('mail_from')) ?>" placeholder="<?= e(mail_from()[0]) ?>"></label>
    <label class="fld"><span>Nome do remetente</span><input name="mail_from_name" maxlength="80" value="<?= e((string) setting('mail_from_name')) ?>" placeholder="<?= e(office('brand')) ?>"></label>
    <label class="fld"><span>Servidor SMTP</span><input name="smtp_host" value="<?= e((string) setting('smtp_host')) ?>" placeholder="smtp.hostinger.com" spellcheck="false"></label>
    <label class="fld"><span>Porta</span><input name="smtp_port" inputmode="numeric" value="<?= e((string) (setting('smtp_port') ?: '465')) ?>"></label>
    <label class="fld"><span>Usuário SMTP (o e-mail completo)</span><input name="smtp_user" value="<?= e((string) setting('smtp_user')) ?>" autocomplete="off" spellcheck="false"></label>
    <label class="fld"><span>Senha SMTP <?= setting('smtp_pass') ? '<small>(configurada)</small>' : '<small>(não configurada)</small>' ?></span><input type="password" name="smtp_pass" autocomplete="new-password" placeholder="<?= setting('smtp_pass') ? 'Deixe em branco para manter' : '' ?>"></label>
    <label class="fld"><span>Esperar antes de avisar (minutos)</span><input name="notify_delay_min" inputmode="numeric" value="<?= notify_delay_min() ?>"></label>
  </div>
  <p class="hint">Recomendado: uma caixa de e-mail do domínio do site (no hPanel, Emails › Contas de e-mail), com servidor smtp.hostinger.com, porta 465. Pela função da hospedagem, os e-mails podem cair no spam. A espera evita avisar quem está com a conversa aberta. Para enviar mesmo sem visitas no site, agende no cron da hospedagem, a cada 5 minutos: <code>php <?= e(ROOT_DIR) ?>/scripts/avisos.php</code></p>
  <label class="fld"><span>Enviar um e-mail de teste para</span><input type="email" name="mail_test_to" placeholder="<?= e((string) ($u['email'] ?? office('email'))) ?>"></label>
  <?php $mails = all('SELECT * FROM mail_log ORDER BY id DESC LIMIT 5'); if ($mails): ?>
    <p class="small"><strong>Últimos envios:</strong></p>
    <ul class="small"><?php foreach ($mails as $m): ?><li><?= e(fmt_datetime($m['created_at'])) ?> · <?= e($m['to_addr']) ?> · <?= $m['ok'] ? 'enviado' : 'falhou: ' . e(mb_strimwidth((string) $m['error'], 0, 120, '…')) ?></li><?php endforeach ?></ul>
  <?php endif ?>
<?php endif ?>
  <div class="cfg-actions"><button class="b b-primary" type="submit">Salvar</button><a class="b" href="<?= e(url('painel/configuracoes')) ?>">Voltar ao painel</a></div>
</form>
