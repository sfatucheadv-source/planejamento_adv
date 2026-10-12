<?php
/** Assistente com IA: liga/desliga, tom, competências, proibições, mensagens, variáveis e documentos mínimos. */
$allowed = agent_allowed_fields();
$req = agent_required_fields();
$reqDocs = agent_required_docs();
$byGroup = [];
foreach ($allowed as $k => $d) {
    $byGroup[$d['g']][$k] = $d;
}
$txt = fn(string $k) => agent_setting($k);
$conditional = ['aviso_data' => 'só se houver aviso', 'atraso' => 'se o contrato não estiver quitado', 'atraso_qtd' => 'só se houver atraso', 'titular_nome' => 'só se o contrato não estiver no nome da pessoa',
    'representacao' => 'só se representar o titular', 'aviso_descricao' => 'só se não souber o tipo de aviso', 'parcelas_pagas' => 'se o contrato estiver ativo', 'renda_atividade' => 'só se pedir justiça gratuita'];
?>
<div class="page-head">
  <div>
    <h1>Assistente com IA</h1>
    <p class="sub">Como o assistente do site conversa com o cliente no acolhimento e na qualificação: o tom, o que ele pode e não pode fazer e o que precisa reunir antes de passar o atendimento para você.</p>
  </div>
</div>

<?php if (!llm_enabled()): ?>
  <div class="flash flash-error">A IA está desligada ou sem chave. Configure o fornecedor e a chave em <a href="<?= e(url('painel/configuracoes')) ?>#ia">Configurações › Inteligência artificial</a>. Sem isso, o site usa o roteiro com perguntas e botões.</div>
<?php endif ?>

<div class="ag-stats">
  <div><strong><?= (int) $stats['conversas'] ?></strong><span>atendimentos conduzidos pela IA (30 dias)</span></div>
  <div><strong><?= (int) $stats['turnos'] ?></strong><span>respostas<?= $stats['falhas'] ? ' · ' . (int) $stats['falhas'] . ' com falha' : '' ?></span></div>
  <div><strong><?= count($stats['duvidas']) ?></strong><span>dúvidas técnicas encaminhadas a você</span></div>
  <div><strong><?= (int) $stats['bloqueios'] ?></strong><span>respostas barradas pelo filtro de sigilo</span></div>
</div>

<form class="form stack" method="post" action="<?= e(url('painel/assistente')) ?>">
  <?= csrf_field() ?>
  <section class="box">
    <div class="box-head"><h2>Funcionamento</h2></div>
    <label class="chk"><input type="checkbox" name="agent_enabled" value="1" <?= checked(setting('agent_enabled', '1') === '1') ?>> A IA conduz a conversa do site (acolhimento, qualificação, documentos)</label>
    <p class="hint">Ligado: o cliente conversa livremente, manda fotos e PDFs no próprio chat, e a IA lê os documentos e preenche a ficha. Desligado, ou se a IA falhar: o roteiro com perguntas e botões continua de onde parou. O cliente também pode escolher os botões a qualquer momento.</p>
    <div class="var-grid">
      <label class="fld"><span>Nome com que se apresenta</span><input name="agent_name" maxlength="120" value="<?= e($txt('agent_name')) ?>"></label>
      <label class="fld"><span>Respostas por hora em cada atendimento (limite de custo)</span><input type="number" name="agent_max_per_hour" min="5" max="200" value="<?= (int) $txt('agent_max_per_hour') ?>"></label>
    </div>
  </section>

  <section class="box">
    <div class="box-head"><h2>Tom e mensagens</h2></div>
    <label class="fld"><span>Tom de voz</span><textarea name="agent_tone" rows="6" maxlength="3000"><?= e($txt('agent_tone')) ?></textarea></label>
    <label class="fld"><span>Saudação (primeira mensagem do site)</span><textarea name="agent_greeting" rows="3" maxlength="1000"><?= e($txt('agent_greeting')) ?></textarea></label>
    <label class="fld"><span>Encaminhamento de dúvidas técnicas para você</span><textarea name="agent_tech_reply" rows="3" maxlength="800"><?= e($txt('agent_tech_reply')) ?></textarea></label>
    <p class="hint">A dúvida fica registrada no histórico do atendimento e vira a próxima ação para você responder.</p>
    <label class="fld"><span>Mensagem de encerramento da coleta</span><textarea name="agent_done" rows="3" maxlength="800"><?= e($txt('agent_done')) ?></textarea></label>
  </section>

  <section class="box">
    <div class="box-head"><h2>Competências e proibições</h2><span class="hint">uma por linha</span></div>
    <div class="var-grid">
      <label class="fld"><span>O que o assistente faz</span><textarea name="agent_skills" rows="10" maxlength="4000"><?= e($txt('agent_skills')) ?></textarea></label>
      <label class="fld"><span>O que o assistente nunca faz</span><textarea name="agent_forbidden" rows="10" maxlength="4000"><?= e($txt('agent_forbidden')) ?></textarea></label>
    </div>
    <h3>Regras fixas <small class="muted">(valem sempre e não podem ser desligadas)</small></h3>
    <ul class="ag-fixas"><?php foreach (AGENT_FIXED_RULES as $r): ?><li><?= e($r) ?></li><?php endforeach ?></ul>
    <p class="hint">Além das regras, o sistema só entrega à IA os dados do atendimento aberto e confere cada resposta antes de mostrar: CPF, telefone, e-mail, protocolo ou nome de outro cliente bloqueiam a mensagem.</p>
  </section>

  <section class="box">
    <div class="box-head"><h2>Variáveis mínimas</h2><span class="hint"><?= count($req) ?> marcadas</span></div>
    <p class="hint">O que o assistente precisa obter antes de encerrar a coleta. “Não sei” e “informo depois” contam como resposta. O que estiver nos documentos é lido e não é perguntado. As demais informações são registradas quando o cliente contar.</p>
    <div class="ag-campos">
      <?php foreach ($byGroup as $g => $list): ?>
        <fieldset><legend class="lbl"><?= e(FIELD_GROUPS[$g] ?? $g) ?></legend>
          <?php foreach ($list as $k => $d): ?>
            <label class="chk"><input type="checkbox" name="campos[]" value="<?= e($k) ?>" <?= checked(in_array($k, $req, true)) ?>> <?= e($d['label']) ?><?= isset($conditional[$k]) ? ' <small class="muted">(' . e($conditional[$k]) . ')</small>' : '' ?></label>
          <?php endforeach ?>
        </fieldset>
      <?php endforeach ?>
    </div>
    <h3>Documentos mínimos</h3>
    <div class="ag-docs">
      <?php foreach (DOC_CATALOG as $k => $d): ?>
        <label class="chk"><input type="checkbox" name="documentos[]" value="<?= e($k) ?>" <?= checked(in_array($k, $reqDocs, true)) ?>> <?= e($d['label']) ?></label>
      <?php endforeach ?>
    </div>
    <p class="hint">“Não tenho” e “envio depois” também encerram o pedido. Os demais documentos da lista do atendimento são pedidos como “se tiver”.</p>
  </section>

  <div class="inline-form">
    <button class="b b-primary" type="submit">Salvar</button>
    <button class="b" type="submit" name="op" value="padrao" formnovalidate data-confirm="Voltar textos, variáveis e documentos ao padrão?">Restaurar o padrão</button>
  </div>
</form>

<section class="box" id="teste">
  <div class="box-head"><h2>Testar o tom</h2><span class="hint">com um cliente fictício; nada é gravado</span></div>
  <form class="form inline-form" method="post" action="<?= e(url('painel/assistente')) ?>">
    <?= csrf_field() ?><input type="hidden" name="op" value="testar">
    <label class="fld ag-teste"><span>Mensagem do cliente</span><input name="mensagem" maxlength="2000" placeholder="ex.: meu carro vai ser tomado amanhã, os juros são abusivos?" value="<?= e($test['mensagem'] ?? '') ?>"></label>
    <button class="b" type="submit">Ver a resposta</button>
  </form>
  <?php if ($test): ?>
    <?php if ($test['ok']): $j = $test['json']; ?>
      <div class="ag-resposta"><span class="muted small">Resposta do assistente</span><p><?= nl2br(e((string) ($j['resposta'] ?? ''))) ?></p></div>
      <p class="small muted">
        <?= !empty($j['duvida_tecnica']) ? 'Encaminharia como dúvida técnica. ' : '' ?><?= !empty($j['urgente']) ? 'Marcaria como urgente. ' : '' ?>
        <?php $campos = array_intersect_key((array) ($j['campos'] ?? []), $allowed); ?><?= $campos ? 'Registraria: ' . e(implode('; ', array_map(fn($k, $v) => $allowed[$k]['label'] . ' = ' . (is_scalar($v) ? $v : '…'), array_keys($campos), $campos))) . '.' : '' ?>
      </p>
    <?php else: ?>
      <p class="flash flash-error"><?= e((string) $test['error']) ?></p>
    <?php endif ?>
  <?php endif ?>
  <details class="ag-prompt"><summary>Ver as instruções completas enviadas à IA</summary><pre><?= e(agent_system_prompt()) ?></pre></details>
</section>

<?php if ($stats['duvidas']): ?>
<section class="box">
  <div class="box-head"><h2>Dúvidas técnicas recentes</h2></div>
  <ul class="ag-duvidas">
    <?php foreach ($stats['duvidas'] as $d): ?>
      <li><a href="<?= e(url('painel/atendimento/' . $d['case_id'])) ?>"><?= e($d['client_name']) ?></a> <small class="muted"><?= e($d['protocol']) ?> · <?= e(fmt_datetime($d['at'])) ?></small><br><?= e(mb_strimwidth((string) $d['body'], 0, 240, '…')) ?></li>
    <?php endforeach ?>
  </ul>
</section>
<?php endif ?>
