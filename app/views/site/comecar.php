<?php
$v = fn(string $k, string $d = '') => e(is_string($old[$k] ?? null) ? $old[$k] : $d);
$is = fn(string $k, string $val) => (($old[$k] ?? '') === $val) ? ' checked' : '';
$err = fn(string $k) => isset($errors[$k]) ? '<p class="field-error" role="alert">' . e($errors[$k]) . '</p>' : '';
$sentences = array_map(fn($p) => $p['sentence'], PRIORITY_OPTIONS);
$open = is_open();
?>
<section class="triage-page">
  <div class="wrap triage-grid">
    <div class="triage-main">
      <p class="kicker">Conte o que está acontecendo</p>
      <h1>Vamos entender o seu caso</h1>
      <p class="lead">São perguntas rápidas, sem termos jurídicos. Se não souber alguma resposta, tudo bem: “não sei” também ajuda. Uma pessoa da equipe lê tudo antes de falar com você.</p>

      <?php if (!empty($errors['form'])): ?><p class="notice notice-urgent"><?= e($errors['form']) ?></p><?php endif ?>
      <?php if ($errors && empty($errors['form'])): ?><p class="notice notice-urgent" role="alert">Faltou alguma informação. Os pontos estão marcados abaixo.</p><?php endif ?>

      <form class="triage" method="post" action="<?= e(url('comecar')) ?>" data-triage data-sentences='<?= e(json_encode($sentences, JSON_UNESCAPED_UNICODE)) ?>' data-has-errors="<?= $errors ? '1' : '0' ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="origin" value="<?= $v('origin') ?>">
        <div class="hp" aria-hidden="true"><label>Site <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>

        <div class="t-progress" aria-hidden="true"><span data-progress-bar></span></div>
        <p class="t-count" data-step-count aria-live="polite"></p>

        <fieldset class="t-step" data-step>
          <legend><span class="t-n">1</span> Você recebeu alguma notificação do banco, de cartório ou da Justiça? O veículo já foi apreendido?</legend>
          <p class="t-help">Essa resposta define a prioridade do atendimento. Se houver prazo correndo, o advogado olha o seu caso primeiro.</p>
          <div class="options">
            <?php foreach (NOTICE_OPTIONS as $k => $label): ?>
              <label class="option<?= in_array($k, ['apreendido', 'justica', 'cartorio'], true) ? ' option-urgent' : '' ?>"><input type="radio" name="notice" value="<?= e($k) ?>"<?= $is('notice', $k) ?> required><span><?= e($label) ?></span></label>
            <?php endforeach ?>
          </div>
          <?= $err('notice') ?>
          <div class="reveal" data-show-if="notice:apreendido,justica,cartorio,banco">
            <label class="field"><span>Quando isso aconteceu? <small>(se lembrar)</small></span><input type="date" name="notice_date" value="<?= $v('notice_date') ?>" max="<?= e(now()->format('Y-m-d')) ?>"></label>
          </div>
          <div class="reveal urgent-hint" data-show-if="notice:apreendido,justica,cartorio">
            <p><strong>Entendido. Seu caso vai direto para o advogado.</strong> Se puder, já mande uma foto de todas as páginas do documento pelo WhatsApp enquanto termina aqui. Não espere ter o contrato.</p>
            <a class="btn btn-small btn-wa" href="<?= e(whatsapp_link('Olá, recebi um documento sobre busca e apreensão (ou meu veículo foi apreendido) e preciso de orientação urgente. Estou preenchendo o formulário do site.')) ?>" target="_blank" rel="noopener">Enviar o documento pelo WhatsApp</a>
          </div>
        </fieldset>

        <fieldset class="t-step" data-step>
          <legend><span class="t-n">2</span> Sobre o financiamento</legend>
          <p class="t-help">Valores aproximados bastam. Ninguém vai calcular a sua dívida com base nisso: é só para entender o cenário.</p>
          <div class="grid-2">
            <label class="field"><span>Banco ou financeira</span><input type="text" name="bank" value="<?= $v('bank') ?>" placeholder="Ex.: Banco Pan, Santander, BV…" autocomplete="off"></label>
            <label class="field"><span>Valor aproximado da parcela</span><input type="text" name="installment_value" value="<?= $v('installment_value') ?>" inputmode="decimal" placeholder="R$ 0,00" data-money></label>
          </div>
          <div class="q">
            <p class="q-label">O contrato ainda está ativo?</p>
            <div class="options options-inline">
              <label class="option"><input type="radio" name="contract_status" value="ativo"<?= $is('contract_status', 'ativo') ?>><span>Sim, ainda estou pagando</span></label>
              <label class="option"><input type="radio" name="contract_status" value="quitado"<?= $is('contract_status', 'quitado') ?>><span>Já quitei</span></label>
              <label class="option"><input type="radio" name="contract_status" value="nao_sei"<?= $is('contract_status', 'nao_sei') ?>><span>Não sei</span></label>
            </div>
          </div>
          <div class="q">
            <p class="q-label">Tem parcelas em atraso?</p>
            <div class="options options-inline">
              <label class="option"><input type="radio" name="overdue" value="sim"<?= $is('overdue', 'sim') ?>><span>Sim</span></label>
              <label class="option"><input type="radio" name="overdue" value="nao"<?= $is('overdue', 'nao') ?>><span>Não</span></label>
              <label class="option"><input type="radio" name="overdue" value="nao_sei"<?= $is('overdue', 'nao_sei') ?>><span>Não sei</span></label>
            </div>
            <div class="reveal" data-show-if="overdue:sim">
              <label class="field field-inline"><span>Quantas, mais ou menos?</span>
                <select name="overdue_count">
                  <option value="">Escolha</option>
                  <?php foreach (['1 parcela', '2 ou 3 parcelas', 'mais de 3 parcelas'] as $o): ?><option<?= selected($old['overdue_count'] ?? '', $o) ?>><?= e($o) ?></option><?php endforeach ?>
                </select>
              </label>
            </div>
          </div>
          <div class="q">
            <p class="q-label">Você tem o contrato do financiamento?</p>
            <div class="options options-inline">
              <label class="option"><input type="radio" name="has_contract" value="sim"<?= $is('has_contract', 'sim') ?>><span>Tenho</span></label>
              <label class="option"><input type="radio" name="has_contract" value="parcial"<?= $is('has_contract', 'parcial') ?>><span>Só algumas páginas</span></label>
              <label class="option"><input type="radio" name="has_contract" value="nao"<?= $is('has_contract', 'nao') ?>><span>Não tenho</span></label>
            </div>
            <p class="t-help reveal" data-show-if="has_contract:nao,parcial">Sem problema. Isso é muito comum, e a gente ajuda a pedir a cópia ao banco.</p>
          </div>
          <div class="grid-2">
            <label class="field"><span>Cidade</span><input type="text" name="city" value="<?= $v('city') ?>" autocomplete="address-level2"></label>
            <label class="field"><span>Estado</span>
              <select name="uf" autocomplete="address-level1"><option value="">UF</option><?php foreach (UFS as $uf): ?><option<?= selected($old['uf'] ?? '', $uf) ?>><?= $uf ?></option><?php endforeach ?></select>
            </label>
          </div>
        </fieldset>

        <fieldset class="t-step" data-step>
          <legend><span class="t-n">3</span> O que você precisa resolver primeiro?</legend>
          <p class="t-help">Quase sempre existe mais de uma coisa. Escolha a que mais tira o seu sono agora.</p>
          <div class="options options-cards">
            <?php foreach (PRIORITY_OPTIONS as $k => $p): ?>
              <label class="option"><input type="radio" name="priority" value="<?= e($k) ?>"<?= $is('priority', $k) ?> required><span><?= e($p['label']) ?></span></label>
            <?php endforeach ?>
          </div>
          <?= $err('priority') ?>
          <div class="reveal" data-show-if="priority:manter_veiculo,reduzir_parcela,quitar,apreensao">
            <label class="field"><span>Qual parcela caberia no seu orçamento sem apertar as despesas essenciais? <small>(uma estimativa)</small></span><input type="text" name="budget_installment" value="<?= $v('budget_installment') ?>" inputmode="decimal" placeholder="R$ 0,00" data-money></label>
          </div>
          <label class="field"><span>Quer contar com suas palavras? <small>(opcional)</small></span><textarea name="description" rows="4" maxlength="3000" placeholder="O que aconteceu, o que você já tentou, o que te preocupa…"><?= $v('description') ?></textarea></label>
        </fieldset>

        <fieldset class="t-step" data-step>
          <legend><span class="t-n">4</span> Para a gente cuidar bem de você</legend>
          <div class="q">
            <p class="q-label">Já existe outro advogado ou processo cuidando disso?</p>
            <p class="t-help">Perguntamos para não fazer nada em duplicidade com quem já está atuando.</p>
            <div class="options options-inline">
              <label class="option"><input type="radio" name="other_lawyer" value="sim"<?= $is('other_lawyer', 'sim') ?>><span>Sim</span></label>
              <label class="option"><input type="radio" name="other_lawyer" value="nao"<?= $is('other_lawyer', 'nao') ?>><span>Não</span></label>
              <label class="option"><input type="radio" name="other_lawyer" value="nao_sei"<?= $is('other_lawyer', 'nao_sei') ?>><span>Não sei</span></label>
            </div>
          </div>
          <div class="q">
            <p class="q-label">Como você prefere conversar?</p>
            <div class="options options-inline">
              <?php foreach (CONTACT_PREFS as $k => $label): ?>
                <label class="option"><input type="radio" name="contact_pref" value="<?= e($k) ?>"<?= $is('contact_pref', $k) ?>><span><?= e($label) ?></span></label>
              <?php endforeach ?>
            </div>
          </div>
          <div class="grid-2">
            <label class="field"><span>Melhor horário</span>
              <select name="contact_time"><option value="">Qualquer horário</option><?php foreach (['Manhã', 'Hora do almoço', 'Tarde', 'Fim do dia'] as $o): ?><option<?= selected($old['contact_time'] ?? '', $o) ?>><?= e($o) ?></option><?php endforeach ?></select>
            </label>
            <label class="field"><span>Precisa de alguma adaptação? <small>(opcional)</small></span><input type="text" name="accessibility" value="<?= $v('accessibility') ?>" placeholder="Ex.: prefiro áudio, letra maior, ajuda para assinar"></label>
          </div>
        </fieldset>

        <fieldset class="t-step" data-step>
          <legend><span class="t-n">5</span> Como falamos com você?</legend>
          <div class="grid-2">
            <label class="field"><span>Seu nome</span><input type="text" name="name" value="<?= $v('name') ?>" autocomplete="name" required><?= $err('name') ?></label>
            <label class="field"><span>WhatsApp ou telefone com DDD</span><input type="tel" name="phone" value="<?= $v('phone') ?>" autocomplete="tel" inputmode="tel" placeholder="(41) 90000-0000" required data-phone><?= $err('phone') ?></label>
          </div>
          <label class="field"><span>E-mail <small>(opcional)</small></span><input type="email" name="email" value="<?= $v('email') ?>" autocomplete="email"><?= $err('email') ?></label>
          <label class="check"><input type="checkbox" name="consent" value="1"<?= ($old['consent'] ?? '') === '1' ? ' checked' : '' ?> required><span>Autorizo o uso destes dados para o atendimento do meu caso, conforme a <a href="<?= e(url('privacidade')) ?>" target="_blank">política de privacidade</a>. Eles não serão usados para divulgação do escritório.</span></label>
          <?= $err('consent') ?>
        </fieldset>

        <fieldset class="t-step" data-step data-summary-step>
          <legend><span class="t-n">6</span> Deixa eu ver se entendi</legend>
          <blockquote class="summary" data-summary>Depois do envio, a equipe confirma com você um resumo do seu caso em uma frase.</blockquote>
          <div class="options options-inline" data-summary-choice hidden>
            <label class="option"><input type="radio" name="summary_ok" value="1"<?= $is('summary_ok', '1') ?>><span>Sim, é isso</span></label>
            <label class="option"><input type="radio" name="summary_ok" value="0"<?= $is('summary_ok', '0') ?>><span>Quase, quero ajustar</span></label>
          </div>
          <div class="reveal" data-show-if="summary_ok:0">
            <label class="field"><span>O que você mudaria?</span><textarea name="summary_fix" rows="3" maxlength="600"><?= $v('summary_fix') ?></textarea></label>
          </div>
          <p class="t-help">Ao enviar, você recebe um número de protocolo. O envio não significa contratação: primeiro a equipe conversa com você e o advogado explica as opções.</p>
        </fieldset>

        <div class="t-nav">
          <button type="button" class="btn btn-ghost" data-prev>Voltar</button>
          <button type="button" class="btn btn-gold" data-next>Continuar</button>
          <button type="submit" class="btn btn-gold" data-submit>Enviar para a equipe</button>
        </div>
      </form>
    </div>

    <aside class="triage-side">
      <div class="side-card">
        <p class="availability <?= $open ? 'is-open' : '' ?>"><span class="dot" aria-hidden="true"></span><?= $open ? 'Equipe em atendimento agora' : 'Fora do horário de atendimento' ?></p>
        <p><?= e(human_reply_promise()) ?></p>
      </div>
      <div class="side-card">
        <h2>Prefere falar direto?</h2>
        <p>Se for mais fácil, mande um áudio contando a situação. A pessoa que te atender organiza as informações.</p>
        <a class="btn btn-wa btn-block" href="<?= e(whatsapp_link('Olá! Vim pelo site e gostaria de falar sobre meu financiamento de veículo.')) ?>" target="_blank" rel="noopener">Chamar no WhatsApp</a>
      </div>
      <div class="side-card side-safe">
        <h2>Seus dados estão seguros</h2>
        <ul>
          <li>Nunca pedimos senha do banco, do Gov.br ou código de autenticação.</li>
          <li>Não pedimos CPF nem documentos nesta etapa.</li>
          <li>Enviar não é contratar: você decide depois de entender as opções.</li>
        </ul>
      </div>
    </aside>
  </div>
</section>
