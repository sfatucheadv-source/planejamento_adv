<?php
/**
 * Campos de cada passo do modo guiado. Os formulários usam as mesmas ações do
 * caso (handle_case_action); depois de salvar, o operador volta para o passo
 * (case_form acrescenta o retorno automaticamente).
 */
function guia_bloco(string $name, array $c, array $users): void
{
    $id = (int) $c['id'];
    switch ($name):

    case 'primeiro_contato': ?>
      <?= case_form($c, 'primeiro_contato', [], 'g-form') ?>
        <label class="fld"><span>Por onde você falou com o cliente?</span><select name="channel"><option>WhatsApp</option><option>Ligação</option><option>E-mail</option><option>Vídeo</option></select></label>
        <button class="b b-primary" type="submit">Já falei com o cliente</button>
      </form>
    <?php break;

    case 'fatos':
        $yn = ['sim' => 'Sim', 'nao' => 'Não', 'nao_sei' => 'Não sabe', 'parcial' => 'Parcial', 'ativo' => 'Ativo', 'quitado' => 'Quitado']; ?>
      <dl class="facts-grid">
        <div><dt>Notificação / apreensão</dt><dd><?= e(NOTICE_OPTIONS[$c['notice']] ?? '—') ?><?= $c['notice_date'] ? ' · ' . e(fmt_date($c['notice_date'])) : '' ?></dd></div>
        <div><dt>Banco</dt><dd><?= e($c['bank'] ?: '—') ?></dd></div>
        <div><dt>Contrato</dt><dd><?= e($yn[$c['contract_status']] ?? '—') ?></dd></div>
        <div><dt>Parcela aproximada</dt><dd><?= $c['installment_value'] !== null ? e(money((float) $c['installment_value'])) : '—' ?></dd></div>
        <div><dt>Atraso</dt><dd><?= e($yn[$c['overdue']] ?? '—') ?><?= $c['overdue_count'] ? ' · ' . e($c['overdue_count']) : '' ?></dd></div>
        <div><dt>Tem o contrato</dt><dd><?= e($yn[$c['has_contract']] ?? '—') ?></dd></div>
        <div><dt>Outro advogado/processo</dt><dd><?= e($yn[$c['other_lawyer']] ?? '—') ?></dd></div>
        <div><dt>Prioridade</dt><dd><?= e(PRIORITY_OPTIONS[$c['priority']]['label'] ?? '—') ?></dd></div>
        <div><dt>Cabe no orçamento</dt><dd><?= $c['budget_installment'] !== null ? e(money((float) $c['budget_installment'])) : '—' ?></dd></div>
        <div><dt>Cidade</dt><dd><?= e($c['client_city'] ? $c['client_city'] . '/' . $c['client_uf'] : '—') ?></dd></div>
      </dl>
      <?php if ($c['description']): ?><div class="client-words"><strong>Nas palavras do cliente</strong><?= nl2p($c['description']) ?></div><?php endif ?>
      <details class="more">
        <summary>Corrigir dados</summary>
        <?= case_form($c, 'dados', [], 'stack') ?>
          <div class="row2">
            <label class="fld"><span>Nome</span><input name="client_name" value="<?= e($c['client_name']) ?>"></label>
            <label class="fld"><span>Telefone</span><input name="client_phone" value="<?= e($c['client_phone']) ?>"></label>
            <label class="fld"><span>E-mail</span><input name="client_email" value="<?= e($c['client_email']) ?>"></label>
            <label class="fld"><span>Banco</span><input name="bank" value="<?= e($c['bank']) ?>"></label>
            <label class="fld"><span>Cidade</span><input name="client_city" value="<?= e($c['client_city']) ?>"></label>
            <label class="fld"><span>UF</span><input name="client_uf" value="<?= e($c['client_uf']) ?>" maxlength="2"></label>
          </div>
          <label class="fld"><span>Canal preferido</span><select name="contact_pref"><option value="">—</option><?php foreach (CONTACT_PREFS as $k => $l): ?><option value="<?= $k ?>"<?= selected($c['contact_pref'], $k) ?>><?= e($l) ?></option><?php endforeach ?></select></label>
          <label class="fld"><span>Acessibilidade</span><input name="accessibility" value="<?= e($c['accessibility']) ?>"></label>
          <button class="b" type="submit">Salvar dados</button>
        </form>
      </details>
    <?php break;

    case 'objetivo': ?>
      <blockquote class="quote-box">“<?= e($c['summary_sentence']) ?>”</blockquote>
      <?= case_form($c, 'objetivo', [], 'g-form') ?>
        <label class="fld"><span>Objetivo concreto do cliente</span><input name="objective" value="<?= e($c['objective']) ?>" placeholder="Ex.: manter o veículo com prestação de até R$ 900" required></label>
        <label class="fld"><span>Como vamos comprovar o resultado no final</span><input name="objective_measure" value="<?= e($c['objective_measure']) ?>" placeholder="Ex.: novo boleto no valor combinado"></label>
        <label class="chk"><input type="checkbox" name="summary_confirmed" value="1" required<?= checked((bool) $c['summary_confirmed']) ?>> Repeti o entendimento em uma frase e o cliente confirmou</label>
        <button class="b b-primary" type="submit">Salvar e continuar</button>
      </form>
    <?php break;

    case 'equipe': ?>
      <?= case_form($c, 'equipe', [], 'g-form') ?>
        <div class="row2">
          <label class="fld"><span>Gestor do caso (fala com o cliente)</span><?= user_select('gestor_id', $c['gestor_id'] ? (int) $c['gestor_id'] : null, $users) ?></label>
          <label class="fld"><span>Advogado responsável</span><?= user_select('advogado_id', $c['advogado_id'] ? (int) $c['advogado_id'] : null, $users) ?></label>
          <label class="fld"><span>Substituto nas ausências</span><?= user_select('substituto_id', $c['substituto_id'] ? (int) $c['substituto_id'] : null, $users) ?></label>
        </div>
        <button class="b b-primary" type="submit">Salvar responsáveis</button>
      </form>
    <?php break;

    case 'conflito': ?>
      <?= case_form($c, 'conflito', [], 'g-form') ?>
        <label class="fld"><span>Resultado da verificação</span><select name="conflict"><option value="sem_conflito">Sem conflito</option><option value="conflito">Há conflito ou impedimento</option></select></label>
        <label class="fld"><span>O que foi verificado</span><input name="note" placeholder="Partes, processos existentes, outro advogado…"></label>
        <button class="b b-primary" type="submit">Registrar verificação</button>
      </form>
    <?php break;

    case 'urgencia_info': ?>
      <p class="<?= $c['urgent'] ? 'warn-line' : 'hint' ?>">Na triagem o cliente respondeu: <strong><?= e(NOTICE_OPTIONS[$c['notice']] ?? 'não informado') ?></strong><?= $c['notice_date'] ? ' em ' . e(fmt_date($c['notice_date'])) : '' ?>.<?= $c['urgent'] ? ' O caso já está marcado como urgente.' : '' ?></p>
    <?php break;

    case 'urgencia': ?>
      <p class="hint">Na triagem: <strong><?= e(NOTICE_OPTIONS[$c['notice']] ?? 'não informado') ?></strong><?= $c['notice_date'] ? ' · ' . e(fmt_date($c['notice_date'])) : '' ?>.</p>
      <?= case_form($c, 'urgencia', [], 'g-form') ?>
        <fieldset class="g-radios">
          <legend>Qual é a situação?</legend>
          <?php foreach (URGENCY_CLASSES as $k => $u): ?>
            <label class="g-radio"><input type="radio" name="urgency_class" value="<?= $k ?>" required<?= $c['urgency_class'] === $k ? ' checked' : '' ?>><span><strong><?= e($u['label']) ?></strong><small>Fazer agora: <?= e($u['action']) ?></small></span></label>
          <?php endforeach ?>
        </fieldset>
        <label class="fld"><span>Vias verificadas, rito, contagem aplicável e medida decidida</span><textarea name="note" rows="3"></textarea></label>
        <button class="b b-primary" type="submit">Registrar a classificação</button>
      </form>
    <?php break;

    case 'prazos':
        $deadlines = all('SELECT * FROM deadlines WHERE case_id = ? ORDER BY status, due_at', [$id]); ?>
      <?php if ($deadlines): ?>
        <ul class="dl-list">
          <?php foreach ($deadlines as $d): ?>
            <li class="<?= $d['status'] !== 'aberto' ? 'done' : (dt($d['due_at']) < now() ? 'late' : '') ?>"><strong><?= e($d['description']) ?></strong><small><?= e(fmt_datetime($d['due_at'])) ?> · <?= e(user_name($d['owner_id'] ? (int) $d['owner_id'] : null)) ?> · <?= e($d['status']) ?></small></li>
          <?php endforeach ?>
        </ul>
      <?php endif ?>
      <details class="more" <?= $deadlines ? '' : 'open' ?>>
        <summary>Adicionar prazo</summary>
        <?= case_form($c, 'prazo', [], 'stack') ?>
          <label class="fld"><span>Descrição</span><input name="description" placeholder="Ex.: contestação na busca e apreensão" required></label>
          <div class="row2">
            <label class="fld"><span>Evento de início</span><input name="trigger_event" placeholder="Ex.: juntada do mandado em 08/10"></label>
            <label class="fld"><span>Vencimento</span><input type="datetime-local" name="due_at" required></label>
            <label class="fld"><span>Executor</span><?= user_select('owner_id', $c['advogado_id'] ? (int) $c['advogado_id'] : null, $users) ?></label>
            <label class="fld"><span>Substituto</span><?= user_select('substitute_id', $c['substituto_id'] ? (int) $c['substituto_id'] : null, $users) ?></label>
          </div>
          <button class="b" type="submit">Adicionar prazo</button>
        </form>
      </details>
    <?php break;

    case 'orientacao': ?>
      <?= case_form($c, 'orientacao_escrita', [], 'g-form') ?>
        <label class="fld"><span>Orientação ao cliente</span><textarea name="text" rows="6" required>Continue pagando as parcelas pelo boleto oficial enquanto cuidamos do seu caso; não pare de pagar nem pague valor menor por conta própria. Se chegar qualquer carta, mensagem do banco ou visita de oficial de justiça, fotografe e nos envie no mesmo dia pelo WhatsApp.</textarea></label>
        <button class="b b-primary" type="submit">Publicar orientação para o cliente</button>
      </form>
    <?php break;

    case 'docs_resumo':
        $docs = all('SELECT * FROM documents WHERE case_id = ? ORDER BY essential DESC, id', [$id]); ?>
      <ul class="g-docs">
        <?php foreach ($docs as $d): ?>
          <li><?= status_tag($d['status'], DOC_STATUS) ?> <span><?= e($d['label']) ?><?= $d['essential'] ? ' <em>essencial</em>' : '' ?><?= $d['note'] ? '<small>' . e($d['note']) . '</small>' : '' ?></span></li>
        <?php endforeach ?>
      </ul>
      <details class="more">
        <summary>Pedir outro documento</summary>
        <?= case_form($c, 'doc_add', [], 'inline-form') ?>
          <label class="fld"><span>Tipo</span><select name="kind"><?php foreach (DOC_CATALOG as $k => $d): ?><option value="<?= $k ?>"><?= e($d['label']) ?></option><?php endforeach ?><option value="outro">Outro (descrever)</option></select></label>
          <label class="fld grow"><span>Descrição</span><input name="label"></label>
          <label class="chk"><input type="checkbox" name="essential" value="1"> essencial</label>
          <button class="b" type="submit">Pedir</button>
        </form>
      </details>
    <?php break;

    case 'pedido_banco':
        foreach (case_data($c, 'bank_requests', []) as $r): ?>
          <p class="ok-line">Pedido em <?= e(fmt_date($r['requested_at'])) ?> · <?= e($r['channel'] ?: 'canal oficial') ?><?= $r['protocol'] ? ' · protocolo ' . e($r['protocol']) : '' ?><?= $r['bank_deadline'] ? ' · prazo ' . e(fmt_date($r['bank_deadline'])) : '' ?></p>
        <?php endforeach ?>
      <?= case_form($c, 'pedido_banco', [], 'g-form') ?>
        <div class="row2">
          <label class="fld"><span>Canal</span><input name="channel" placeholder="SAC, aplicativo, e-mail…"></label>
          <label class="fld"><span>Protocolo</span><input name="protocol"></label>
          <label class="fld"><span>Data do pedido</span><input type="date" name="requested_at" value="<?= e(now()->format('Y-m-d')) ?>"></label>
          <label class="fld"><span>Prazo informado pelo banco</span><input type="date" name="bank_deadline"></label>
        </div>
        <label class="fld"><span>O que foi pedido</span><input name="items" value="Cópia integral do contrato, demonstrativo do saldo, histórico de pagamentos, CET, seguro e tarifas"></label>
        <button class="b b-primary" type="submit">Registrar pedido ao banco</button>
      </form>
    <?php break;

    case 'docs_conferir':
        $docs = all('SELECT * FROM documents WHERE case_id = ? ORDER BY essential DESC, id', [$id]);
        $files = all('SELECT * FROM files WHERE case_id = ? ORDER BY uploaded_at DESC', [$id]);
        $byDoc = [];
        foreach ($files as $f) {
            $byDoc[(int) ($f['document_id'] ?? 0)][] = $f;
        }
        $reminders = (int) case_data($c, 'reminders', 0); ?>
      <div class="g-doclist">
        <?php foreach ($docs as $d): ?>
          <div class="g-doc">
            <div class="g-doc-head"><strong><?= e($d['label']) ?></strong><?= $d['essential'] ? ' <em class="tag tag-blue">essencial</em>' : '' ?> <?= status_tag($d['status'], DOC_STATUS) ?></div>
            <?php foreach ($byDoc[(int) $d['id']] ?? [] as $f): ?>
              <div class="file-line"><a href="<?= e(url('arquivo/' . $f['id'])) ?>" target="_blank"><?= e($f['original_name']) ?></a> <small><?= $f['uploaded_by_client'] ? 'enviado pelo cliente' : 'anexado pela equipe' ?> · <?= e(fmt_date($f['uploaded_at'])) ?></small></div>
            <?php endforeach ?>
            <div class="g-doc-actions">
              <?= case_form($c, 'doc_status', ['doc_id' => $d['id']], 'mini-form') ?>
                <select name="status" aria-label="Situação"><?php foreach (DOC_STATUS as $k => $st): ?><option value="<?= $k ?>"<?= selected($d['status'], $k) ?>><?= e($st['label']) ?></option><?php endforeach ?></select>
                <input name="note" placeholder="Motivo (ilegível, falta página…)" aria-label="Motivo">
                <button class="b b-xs" type="submit">Salvar</button>
              </form>
              <?= case_form($c, 'doc_upload', ['doc_id' => $d['id']], 'mini-form', true) ?>
                <input type="file" name="files[]" multiple accept="application/pdf,image/*" aria-label="Anexar arquivos">
                <button class="b b-xs" type="submit">Anexar</button>
              </form>
            </div>
          </div>
        <?php endforeach ?>
      </div>
      <div class="g-sub">
        <p class="hint">Lembretes enviados ao cliente: <strong><?= $reminders ?></strong> (até dois, em 2 e 5 dias úteis).</p>
        <?= case_form($c, 'lembrete', [], 'inline-form') ?><button class="b b-xs" type="submit">Registrar lembrete enviado</button></form>
      </div>
    <?php break;

    case 'auditoria':
        require APP_DIR . '/views/panel/caso/analise.php';
        break;

    case 'validar':
        $a = case_data($c, 'audit', []);
        $k = audit_calc($a); ?>
      <dl class="calc-dl g-calc">
        <div><dt>O cálculo reproduz a operação?</dt><dd><?= $k['reproduces'] === null ? '—' : ($k['reproduces'] ? 'Sim' : 'Não') ?></dd></div>
        <div><dt>Taxa contratada (mês)</dt><dd><?= e(($a['monthly_rate'] ?? '') !== '' ? $a['monthly_rate'] . '%' : '—') ?></dd></div>
        <div><dt>Média BCB (mês)</dt><dd><?= e(($a['bcb_rate'] ?? '') !== '' ? $a['bcb_rate'] . '% · ' . ($a['bcb_reference'] ?? '') : '—') ?></dd></div>
        <div class="hl"><dt>Razão contratada ÷ média</dt><dd><?= $k['ratio'] !== null ? number_format($k['ratio'], 2, ',', '') . '×' : '—' ?></dd></div>
        <div><dt>Diferença nas parcelas pagas</dt><dd><?= e($k['diff_paid'] !== null ? money($k['diff_paid']) : '—') ?></dd></div>
        <div><dt>Cobranças acessórias</dt><dd><?= e($k['charges'] !== null ? money($k['charges']) : '—') ?></dd></div>
      </dl>
      <?php if (!empty($a['notes'])): ?><details class="more"><summary>Memória de cálculo</summary><div class="quote-box small"><?= nl2p($a['notes']) ?></div></details><?php endif ?>
      <?php foreach (['scenario_contract' => 'Cenário contratado', 'scenario_negotiation' => 'Cenário de negociação', 'scenario_revision' => 'Cenário revisional'] as $sk => $sl): if (!empty($a[$sk])): ?>
        <p><strong><?= $sl ?>:</strong> <?= e($a[$sk]) ?></p>
      <?php endif; endforeach ?>
      <p><a href="<?= e(url('painel/executar/' . $id, ['passo' => 'calculo'])) ?>">Ver ou corrigir o cálculo completo</a></p>
      <?= case_form($c, 'auditoria', [], 'g-form') ?>
        <label class="fld"><span>Conclusão do parecer</span><select name="conclusion" required><option value="">—</option><?php foreach (AUDIT_CONCLUSIONS as $ck => $cl): ?><option value="<?= $ck ?>"<?= selected($a['conclusion'] ?? '', $ck) ?>><?= e($cl) ?></option><?php endforeach ?></select></label>
        <label class="fld"><span>Síntese para o cliente, em linguagem simples</span><textarea name="summary_client" rows="5"><?= e($a['summary_client'] ?? '') ?></textarea></label>
        <button class="b b-primary" type="submit" name="validate" value="1">Validar cálculo e parecer</button>
      </form>
    <?php break;

    case 'recomendacao':
        $a = case_data($c, 'audit', []);
        $rec = case_data($c, 'recommendation', []); ?>
      <?php if (!empty($a['conclusion'])): ?><p class="ok-line">Parecer: <strong><?= e(AUDIT_CONCLUSIONS[$a['conclusion']] ?? '') ?></strong></p><?php endif ?>
      <?= case_form($c, 'recomendacao', [], 'g-form') ?>
        <fieldset class="g-radios">
          <legend>Caminho recomendado</legend>
          <?php foreach (PATHS as $pk => $p): ?>
            <label class="g-radio"><input type="radio" name="path" value="<?= $pk ?>" required<?= ($rec['path'] ?? '') === $pk ? ' checked' : '' ?>><span><strong><?= e($p['label']) ?></strong><small>Quando: <?= e($p['when']) ?> Explicar: <?= e($p['explain']) ?></small></span></label>
          <?php endforeach ?>
        </fieldset>
        <label class="fld"><span>Razão da recomendação, em uma frase</span><input name="reason" value="<?= e($rec['reason'] ?? '') ?>" required></label>
        <label class="fld"><span>Custos: serviço, despesas, risco de sucumbência, recursos</span><textarea name="costs" rows="2"><?= e($rec['costs'] ?? '') ?></textarea></label>
        <label class="fld"><span>Limites da negociação e decisões pendentes</span><textarea name="limits" rows="2"><?= e($rec['limits'] ?? '') ?></textarea></label>
        <label class="fld"><span>O que fazer com os pagamentos enquanto buscamos a solução</span><textarea name="payments" rows="2"><?= e($rec['payments'] ?? '') ?></textarea></label>
        <button class="b b-primary" type="submit">Salvar recomendação</button>
      </form>
    <?php break;

    case 'escolha_caminho':
        $rec = case_data($c, 'recommendation', []);
        $a = case_data($c, 'audit', []);
        $pending = one("SELECT * FROM decisions WHERE case_id = ? AND kind = 'caminho' AND answer IS NULL ORDER BY requested_at DESC LIMIT 1", [$id]); ?>
      <?php if (!empty($rec['path'])): ?><p class="ok-line">Recomendação: <strong><?= e(PATHS[$rec['path']]['label'] ?? '') ?></strong><?= !empty($rec['reason']) ? ', porque ' . e($rec['reason']) : '' ?>.</p><?php endif ?>
      <?php if (!empty($a['summary_client'])): ?><details class="more"><summary>Síntese da análise para ler com o cliente</summary><div class="quote-box small"><?= nl2p($a['summary_client']) ?></div></details><?php endif ?>
      <?php if ($pending): $opts = json_decode($pending['options'], true) ?: []; ?>
        <p class="warn-line">Pergunta enviada à área do cliente em <?= e(fmt_datetime($pending['requested_at'])) ?>. Se ele respondeu por telefone ou WhatsApp, registre aqui.</p>
        <?= case_form($c, 'decisao_registrar', ['decision_id' => $pending['id']], 'g-form') ?>
          <div class="row2">
            <label class="fld"><span>Escolha do cliente</span><select name="answer"><?php foreach ($opts as $o): ?><option><?= e($o) ?></option><?php endforeach ?></select></label>
            <label class="fld"><span>Canal</span><select name="via"><option>ligação</option><option>vídeo</option><option>WhatsApp</option><option>e-mail</option></select></label>
          </div>
          <label class="fld"><span>Por quê, nas palavras do cliente</span><input name="reason"></label>
          <button class="b b-primary" type="submit">Registrar a escolha</button>
        </form>
      <?php else: ?>
        <?= case_form($c, 'decisao_nova', ['kind' => 'caminho', 'question' => 'Qual caminho você prefere seguir?', 'options' => implode("\n", array_map(fn($p) => $p['label'], PATHS))], 'g-form') ?>
          <input type="hidden" name="context" value="<?= e(!empty($rec['path']) ? 'Recomendação do advogado: ' . PATHS[$rec['path']]['label'] . (!empty($rec['reason']) ? ', porque ' . $rec['reason'] : '') . '.' : '') ?>">
          <fieldset class="g-radios">
            <legend>O que o cliente escolheu?</legend>
            <?php foreach (PATHS as $pk => $p): ?>
              <label class="g-radio"><input type="radio" name="answer" value="<?= e($p['label']) ?>"<?= ($rec['path'] ?? '') === $pk ? ' checked' : '' ?>><span><strong><?= e($p['label']) ?></strong></span></label>
            <?php endforeach ?>
            <label class="g-radio"><input type="radio" name="answer" value=""><span><strong>Ainda vai pensar</strong><small>A pergunta vai para a área do cliente e o caso fica aguardando a resposta.</small></span></label>
          </fieldset>
          <div class="row2">
            <label class="fld"><span>Canal da conversa</span><select name="via"><option>ligação</option><option>vídeo</option><option>WhatsApp</option></select></label>
            <label class="fld"><span>Responder até <small>(se for pensar)</small></span><input type="date" name="deadline"></label>
          </div>
          <label class="fld"><span>Por quê, nas palavras do cliente</span><input name="reason"></label>
          <button class="b b-primary" type="submit">Registrar</button>
        </form>
      <?php endif ?>
    <?php break;

    case 'objetivo_resumo':
        $rec = case_data($c, 'recommendation', []); ?>
      <dl class="mini-dl">
        <div><dt>Objetivo</dt><dd><?= e($c['objective'] ?: '—') ?></dd></div>
        <div><dt>Como comprovar</dt><dd><?= e($c['objective_measure'] ?: '—') ?></dd></div>
        <div><dt>Limites da negociação</dt><dd><?= e($rec['limits'] ?? '—') ?: '—' ?></dd></div>
        <div><dt>Pagamentos até a solução</dt><dd><?= e($rec['payments'] ?? '—') ?: '—' ?></dd></div>
      </dl>
      <details class="more">
        <summary>Ajustar o objetivo</summary>
        <?= case_form($c, 'objetivo', ['summary_confirmed' => $c['summary_confirmed'] ? '1' : '0'], 'stack') ?>
          <label class="fld"><span>Objetivo</span><input name="objective" value="<?= e($c['objective']) ?>"></label>
          <label class="fld"><span>Como comprovar</span><input name="objective_measure" value="<?= e($c['objective_measure']) ?>"></label>
          <button class="b" type="submit">Salvar</button>
        </form>
      </details>
    <?php break;

    case 'contratacao':
        $eng = case_data($c, 'engagement', []); ?>
      <?= case_form($c, 'contratacao', [], 'g-form') ?>
        <label class="fld"><span>O que o serviço cobre</span><textarea name="scope" rows="2" placeholder="diagnóstico, negociação, ação, defesa em busca e apreensão, recursos, cálculos, cumprimento"><?= e($eng['scope'] ?? '') ?></textarea></label>
        <label class="fld"><span>O que não está incluído</span><textarea name="excluded" rows="2"><?= e($eng['excluded'] ?? '') ?></textarea></label>
        <label class="fld"><span>Honorários: inicial, êxito, base e momento de cobrança</span><textarea name="fees" rows="2"><?= e($eng['fees'] ?? '') ?></textarea></label>
        <label class="fld"><span>Exemplo numérico da base de êxito</span><textarea name="example" rows="2"><?= e($eng['example'] ?? '') ?></textarea></label>
        <label class="fld"><span>Despesas externas, acordo, desistência e prestação de contas</span><textarea name="expenses" rows="2"><?= e($eng['expenses'] ?? '') ?></textarea></label>
        <label class="fld"><span>Data em que o cliente assinou contrato e procuração <small>(deixe vazio até assinar)</small></span><input type="date" name="signed_at" value="<?= e($eng['signed_at'] ?? '') ?>"></label>
        <button class="b b-primary" type="submit">Salvar</button>
      </form>
    <?php break;

    case 'limites':
        $lim = case_data($c, 'negotiation', []); ?>
      <?= case_form($c, 'limites', [], 'g-form') ?>
        <div class="row2">
          <label class="fld"><span>Teto de entrada</span><input name="max_down" value="<?= e(num($lim['max_down'] ?? null)) ?>" inputmode="decimal"></label>
          <label class="fld"><span>Parcela máxima</span><input name="max_installment" value="<?= e(num($lim['max_installment'] ?? ($c['budget_installment'] !== null ? (float) $c['budget_installment'] : null))) ?>" inputmode="decimal"></label>
          <label class="fld"><span>Data possível de pagamento</span><input name="pay_date" value="<?= e($lim['pay_date'] ?? '') ?>" placeholder="ex.: dia 10"></label>
          <label class="fld"><span>Autorizado em</span><input type="date" name="authorized_at" value="<?= e($lim['authorized_at'] ?? now()->format('Y-m-d')) ?>"></label>
        </div>
        <label class="fld"><span>Objetivo prioritário</span><input name="priority" value="<?= e($lim['priority'] ?? $c['objective']) ?>"></label>
        <div class="row2">
          <label class="fld"><span>Credor atual</span><input name="creditor" value="<?= e($lim['creditor'] ?? $c['bank']) ?>"></label>
          <label class="fld"><span>Interlocutor e canal oficial</span><input name="creditor_contact" value="<?= e($lim['creditor_contact'] ?? '') ?>"></label>
        </div>
        <button class="b b-primary" type="submit">Salvar autorização</button>
      </form>
    <?php break;

    case 'ofertas':
        $offers = all('SELECT * FROM offers WHERE case_id = ? ORDER BY round, id', [$id]);
        $lim = case_data($c, 'negotiation', []);
        $rounds = $offers ? max(array_column($offers, 'round')) : 0;
        $answers = all("SELECT * FROM decisions WHERE case_id = ? AND kind = 'oferta' ORDER BY requested_at", [$id]); ?>
      <?php if ($lim): ?><p class="hint">Autorizado: entrada até <strong><?= e(money($lim['max_down'] ?? null)) ?></strong> · parcela até <strong><?= e(money($lim['max_installment'] ?? null)) ?></strong><?= !empty($lim['pay_date']) ? ' · pagamento ' . e($lim['pay_date']) : '' ?>.</p><?php endif ?>
      <?php foreach ($offers as $o): $tot = offer_total($o); ?>
        <div class="g-offer">
          <div class="g-doc-head"><strong>Rodada <?= (int) $o['round'] ?> · <?= $o['origin'] === 'credor' ? 'oferta do credor' : 'nossa proposta' ?></strong> <span class="tag tag-gray"><?= e($o['status']) ?></span></div>
          <p class="small">Entrada <?= e(money($o['down_payment'] !== null ? (float) $o['down_payment'] : null)) ?> · <?= $o['installments'] ? (int) $o['installments'] . '× ' . e(money((float) $o['installment_value'])) : 'sem parcelas' ?> · total <strong><?= e(money($tot)) ?></strong><?= $o['valid_until'] ? ' · válida até ' . e(fmt_date($o['valid_until'])) : '' ?></p>
          <?php if ($o['origin'] === 'credor' && !$o['communicated_at']): ?>
            <?= case_form($c, 'oferta_comunicar', ['offer_id' => $o['id']], 'mini-form') ?>
              <input name="recommendation" placeholder="Nossa avaliação da proposta" aria-label="Nossa avaliação">
              <button class="b b-xs b-primary" type="submit">Enviar ao cliente para decidir</button>
            </form>
          <?php endif ?>
          <?php if (!in_array($o['status'], ['aceita', 'recusada', 'expirada'], true)): ?>
            <?= case_form($c, 'oferta_status', ['offer_id' => $o['id']], 'mini-form') ?>
              <select name="status" aria-label="Nova situação"><?php foreach (['contraproposta' => 'Contraproposta', 'aceita' => 'Aceita', 'recusada' => 'Recusada', 'expirada' => 'Expirou'] as $sk => $sl): ?><option value="<?= $sk ?>"><?= $sl ?></option><?php endforeach ?></select>
              <input name="confirm_via" placeholder="Autorização do cliente por (se fora do portal)" aria-label="Autorização">
              <button class="b b-xs" type="submit">Atualizar</button>
            </form>
          <?php endif ?>
        </div>
      <?php endforeach ?>
      <?php foreach ($answers as $d): ?>
        <p class="<?= $d['answer'] ? 'ok-line' : 'warn-line' ?>">Cliente <?= $d['answer'] ? 'respondeu: “' . e($d['answer']) . '”' : 'ainda não respondeu à proposta enviada em ' . e(fmt_date($d['requested_at'])) ?></p>
      <?php endforeach ?>
      <details class="more" <?= $offers ? '' : 'open' ?>>
        <summary>Registrar oferta do credor ou proposta nossa</summary>
        <?= case_form($c, 'oferta', ['round' => $rounds ?: 1], 'stack') ?>
          <div class="row4">
            <label class="fld"><span>Origem</span><select name="origin"><option value="credor">Oferta do credor</option><option value="escritorio">Proposta nossa</option></select></label>
            <label class="fld"><span>Saldo de referência</span><input name="ref_balance" inputmode="decimal"></label>
            <label class="fld"><span>Data do saldo</span><input type="date" name="ref_date"></label>
            <label class="fld"><span>Entrada</span><input name="down_payment" inputmode="decimal"></label>
            <label class="fld"><span>Nº parcelas</span><input name="installments" inputmode="numeric"></label>
            <label class="fld"><span>Valor da parcela</span><input name="installment_value" inputmode="decimal"></label>
            <label class="fld"><span>Total (se informado)</span><input name="total" inputmode="decimal"></label>
            <label class="fld"><span>Validade</span><input type="date" name="valid_until"></label>
          </div>
          <label class="fld"><span>Encargos, garantias, seguros embutidos</span><input name="charges"></label>
          <label class="fld"><span>Efeitos sobre saldo, veículo, registros e processos</span><input name="effects"></label>
          <input type="hidden" name="creditor" value="<?= e($lim['creditor'] ?? $c['bank']) ?>">
          <button class="b b-primary" type="submit">Registrar</button>
        </form>
      </details>
      <?php if ($rounds >= 2): ?>
        <div class="cklist"><?= check_item($c, 'negociacao', 'revisao_estrategia', 'Advogado revisou a estratégia (duas rodadas ou 10 dias úteis sem avanço)', case_checks($id, 'negociacao')['revisao_estrategia'] ?? null, 'negociacao') ?></div>
      <?php endif ?>
    <?php break;

    case 'acordo':
        $ag = case_data($c, 'agreement', []); ?>
      <?= case_form($c, 'acordo', [], 'g-form') ?>
        <label class="fld"><span>Instrumento (partes, contrato, veículo)</span><input name="instrument" value="<?= e($ag['instrument'] ?? '') ?>"></label>
        <div class="row4">
          <label class="fld"><span>Saldo reconhecido</span><input name="balance" value="<?= e(num($ag['balance'] ?? null)) ?>" inputmode="decimal"></label>
          <label class="fld"><span>Desconto</span><input name="discount" value="<?= e(num($ag['discount'] ?? null)) ?>" inputmode="decimal"></label>
          <label class="fld"><span>Homologação</span><select name="homologation"><?php foreach (['' => '—', 'nao_ha_processo' => 'Não há processo', 'pedir' => 'Pedir homologação', 'homologado' => 'Homologado'] as $hk => $hl): ?><option value="<?= $hk ?>"<?= selected($ag['homologation'] ?? '', $hk) ?>><?= $hl ?></option><?php endforeach ?></select></label>
          <label class="fld"><span>Assinado em <small>(vazio até assinar)</small></span><input type="date" name="signed_at" value="<?= e($ag['signed_at'] ?? '') ?>"></label>
        </div>
        <label class="fld"><span>Quitação, boletos, restrições, gravame, processos</span><textarea name="releases" rows="2"><?= e($ag['releases'] ?? '') ?></textarea></label>
        <label class="fld"><span>Destino do veículo e saldo residual, se houver entrega</span><input name="vehicle" value="<?= e($ag['vehicle'] ?? '') ?>"></label>
        <label class="chk"><input type="checkbox" name="boleto_checked" value="1"<?= checked(!empty($ag['boleto_checked'])) ?>> Beneficiário e autenticidade do boleto conferidos em canal oficial</label>
        <label class="chk"><input type="checkbox" name="sem_parcelas" value="1"> Pagamento único, sem parcelas futuras</label>
        <button class="b b-primary" type="submit">Salvar acordo</button>
      </form>
    <?php break;

    case 'calendario':
    case 'parcelas':
        $schedule = all('SELECT * FROM schedule WHERE case_id = ? ORDER BY due_date', [$id]); ?>
      <?php if ($schedule): ?>
        <ul class="dl-list">
          <?php foreach ($schedule as $p): ?>
            <li class="<?= $p['status'] === 'pago' ? 'done' : ($p['status'] === 'atrasado' || ($p['status'] === 'pendente' && $p['due_date'] < now()->format('Y-m-d')) ? 'late' : '') ?>">
              <strong><?= e(fmt_date($p['due_date'])) ?> · <?= e(money($p['amount'] !== null ? (float) $p['amount'] : null)) ?></strong>
              <small><?= e($p['description']) ?> · <?= e($p['status']) ?><?= $p['proof'] ? ' · ' . e($p['proof']) : '' ?></small>
              <?php if ($name === 'parcelas' && $p['status'] !== 'pago'): ?>
                <?= case_form($c, 'parcela_status', ['schedule_id' => $p['id']], 'mini-actions') ?>
                  <input name="proof" placeholder="comprovante" aria-label="Comprovante">
                  <button class="b b-xs" name="status" value="pago">Pago</button>
                  <button class="b b-xs b-danger" name="status" value="atrasado">Atrasado</button>
                </form>
              <?php endif ?>
            </li>
          <?php endforeach ?>
        </ul>
      <?php elseif ($name === 'parcelas'): ?><p class="empty">Sem parcelas no calendário.</p><?php endif ?>
      <?php if ($name === 'calendario'): ?>
        <?= case_form($c, 'parcela_add', [], 'g-form') ?>
          <label class="fld"><span>Descrição</span><input name="description" value="Parcela do acordo" required></label>
          <div class="row2">
            <label class="fld"><span>Primeiro vencimento</span><input type="date" name="due_date" required></label>
            <label class="fld"><span>Valor</span><input name="amount" inputmode="decimal"></label>
            <label class="fld"><span>Quantas parcelas mensais</span><input name="repeat" value="1" inputmode="numeric"></label>
          </div>
          <button class="b b-primary" type="submit">Lançar no calendário</button>
        </form>
      <?php endif ?>
    <?php break;

    case 'protocolo':
        $j = case_data($c, 'judicial', []); ?>
      <?= case_form($c, 'judicial', [], 'g-form') ?>
        <div class="row4">
          <label class="fld"><span>Número do processo</span><input name="process_number" value="<?= e($j['process_number'] ?? '') ?>" required></label>
          <label class="fld"><span>Juízo</span><input name="court" value="<?= e($j['court'] ?? '') ?>"></label>
          <label class="fld"><span>Protocolado em</span><input type="date" name="filed_at" value="<?= e($j['filed_at'] ?? '') ?>" required></label>
          <label class="fld"><span>Valor da causa</span><input name="cause_value" value="<?= e($j['cause_value'] ?? '') ?>"></label>
        </div>
        <label class="fld"><span>Pedidos (cada um com a prova correspondente)</span><textarea name="claims" rows="3"><?= e($j['claims'] ?? '') ?></textarea></label>
        <div class="row2">
          <label class="fld"><span>Medida urgente</span><select name="tutela"><?php foreach (['' => '—', 'nao_pedida' => 'Não pedida', 'pedida' => 'Pedida, sem decisão', 'concedida' => 'Concedida', 'parcial' => 'Concedida em parte', 'negada' => 'Negada'] as $tk => $tl): ?><option value="<?= $tk ?>"<?= selected($j['tutela'] ?? '', $tk) ?>><?= $tl ?></option><?php endforeach ?></select></label>
          <label class="fld"><span>Alcance da ordem</span><input name="tutela_note" value="<?= e($j['tutela_note'] ?? '') ?>"></label>
        </div>
        <button class="b b-primary" type="submit">Registrar protocolo</button>
      </form>
    <?php break;

    case 'processo':
        $j = case_data($c, 'judicial', []); ?>
      <p class="hint">Processo <strong><?= e($j['process_number'] ?? '—') ?></strong><?= !empty($j['court']) ? ' · ' . e($j['court']) : '' ?>. Último retorno ao cliente: <?= $c['last_client_update'] ? e(relative($c['last_client_update'])) : 'ainda não houve' ?>.</p>
      <?php guia_bloco('prazos', $c, $users) ?>
      <details class="more">
        <summary>Audiência marcada</summary>
        <?= case_form($c, 'judicial', [], 'stack') ?>
          <div class="row2">
            <label class="fld"><span>Data e hora</span><input type="datetime-local" name="hearing_at" value="<?= e(dt_local($j['hearing_at'] ?? null)) ?>"></label>
            <label class="fld"><span>Formato e instruções</span><input name="hearing_format" value="<?= e($j['hearing_format'] ?? '') ?>" placeholder="presencial / remota, link, documentos"></label>
          </div>
          <button class="b" type="submit">Salvar audiência</button>
        </form>
      </details>
      <details class="more" open>
        <summary>Saiu a decisão?</summary>
        <?= case_form($c, 'judicial', [], 'stack') ?>
          <div class="row2">
            <label class="fld"><span>Resultado</span><select name="decision_result" required><option value="">—</option><?php foreach (DECISION_RESULTS as $dk => $dr): ?><option value="<?= $dk ?>"<?= selected($j['decision_result'] ?? '', $dk) ?>><?= e($dr['label']) ?></option><?php endforeach ?></select></label>
            <label class="fld"><span>Data da decisão</span><input type="date" name="decision_date" value="<?= e($j['decision_date'] ?? '') ?>"></label>
          </div>
          <label class="fld"><span>Acolhido, rejeitado e não definido; efeitos práticos</span><textarea name="decision_summary" rows="3"><?= e($j['decision_summary'] ?? '') ?></textarea></label>
          <button class="b b-primary" type="submit">Registrar decisão</button>
        </form>
      </details>
    <?php break;

    case 'decisao_judicial':
        $j = case_data($c, 'judicial', []);
        $r = DECISION_RESULTS[$j['decision_result'] ?? ''] ?? null; ?>
      <?php if ($r): ?><p class="<?= in_array($j['decision_result'], ['favoravel', 'parcial'], true) ? 'ok-line' : 'warn-line' ?>">Decisão <?= e(mb_strtolower($r['label'])) ?><?= !empty($j['decision_date']) ? ' em ' . e(fmt_date($j['decision_date'])) : '' ?>. Próxima ação: <?= e($r['next']) ?></p><?php endif ?>
      <?= case_form($c, 'judicial', [], 'g-form') ?>
        <label class="fld"><span>O que foi acolhido, rejeitado e não definido; efeitos sobre saldo, pagamentos, posse, custas e honorários</span><textarea name="decision_summary" rows="4"><?= e($j['decision_summary'] ?? '') ?></textarea></label>
        <button class="b" type="submit">Salvar explicação</button>
      </form>
    <?php break;

    case 'recurso':
        $j = case_data($c, 'judicial', []); ?>
      <?= case_form($c, 'judicial', [], 'g-form') ?>
        <div class="row2">
          <label class="fld"><span>Decisão sobre recurso</span><select name="appeal" required><?php foreach (['' => '—', 'sim' => 'Recorrer', 'nao' => 'Não recorrer', 'banco' => 'Banco recorreu'] as $ak => $al): ?><option value="<?= $ak ?>"<?= selected($j['appeal'] ?? '', $ak) ?>><?= $al ?></option><?php endforeach ?></select></label>
          <label class="fld"><span>Trânsito em julgado</span><input type="date" name="transit_at" value="<?= e($j['transit_at'] ?? '') ?>"></label>
        </div>
        <label class="fld"><span>Fundamento, custo, risco e utilidade (e a decisão do cliente)</span><textarea name="appeal_reason" rows="3" required><?= e($j['appeal_reason'] ?? '') ?></textarea></label>
        <button class="b b-primary" type="submit">Registrar decisão sobre recurso</button>
      </form>
    <?php break;

    case 'apuracao':
        $j = case_data($c, 'judicial', []); ?>
      <?= case_form($c, 'judicial', [], 'g-form') ?>
        <div class="row4">
          <label class="fld"><span>Novo saldo</span><input name="new_balance" value="<?= e($j['new_balance'] ?? '') ?>"></label>
          <label class="fld"><span>Parcelas restantes</span><input name="remaining_installments" value="<?= e($j['remaining_installments'] ?? '') ?>"></label>
          <label class="fld"><span>Valores a devolver</span><input name="refund_value" value="<?= e($j['refund_value'] ?? '') ?>"></label>
          <label class="fld"><span>Montante compensado</span><input name="offset_value" value="<?= e($j['offset_value'] ?? '') ?>"></label>
        </div>
        <label class="fld"><span>Despesas e honorários</span><input name="fees_expenses" value="<?= e($j['fees_expenses'] ?? '') ?>"></label>
        <button class="b" type="submit">Salvar demonstrativo</button>
      </form>
    <?php break;

    case 'cumprimento':
        $j = case_data($c, 'judicial', []); ?>
      <?= case_form($c, 'judicial', [], 'g-form') ?>
        <label class="fld"><span>Situação do cumprimento</span><select name="compliance_status"><?php foreach (['' => '—', 'voluntario' => 'Cumprimento voluntário pedido', 'formal' => 'Cumprimento formal em curso', 'parcial' => 'Cumprido em parte', 'pendente' => 'Pendente (obstáculo)', 'cumprido' => 'Cumprido'] as $sk => $sl): ?><option value="<?= $sk ?>"<?= selected($j['compliance_status'] ?? '', $sk) ?>><?= $sl ?></option><?php endforeach ?></select></label>
        <label class="fld"><span>Medida realizada, obstáculo, próxima tentativa</span><input name="compliance_note" value="<?= e($j['compliance_note'] ?? '') ?>"></label>
        <button class="b" type="submit">Salvar</button>
      </form>
      <?php guia_bloco('prazos', $c, $users) ?>
    <?php break;

    case 'solucao':
        $sol = case_data($c, 'solution', []); ?>
      <p class="hint">Objetivo registrado: <strong><?= e($c['objective'] ?: '—') ?></strong><?= $c['objective_measure'] ? ' · comprovação: ' . e($c['objective_measure']) : '' ?></p>
      <?= case_form($c, 'solucao', [], 'g-form') ?>
        <div class="g-doclist">
          <?php foreach (SOLUTION_ITEMS as $k => $it): $row = $sol[$k] ?? []; ?>
            <div class="g-doc">
              <label class="chk"><input type="checkbox" name="applies[<?= $k ?>]" value="1"<?= checked(!empty($row['applies'])) ?>> <strong><?= e($it['label']) ?></strong> se aplica a este caso</label>
              <small class="muted">Concluir quando: <?= e($it['done']) ?></small>
              <div class="row2">
                <label class="fld"><span>Evidência guardada</span><input name="evidence[<?= $k ?>]" value="<?= e($row['evidence'] ?? '') ?>" placeholder="<?= e($it['evidence']) ?>"></label>
                <label class="chk"><input type="checkbox" name="done[<?= $k ?>]" value="1"<?= checked(!empty($row['done'])) ?>> Conferido</label>
              </div>
            </div>
          <?php endforeach ?>
        </div>
        <label class="fld"><span>SCR (Registrato): o que foi conferido</span><textarea name="scr" rows="2"><?= e(case_data($c, 'scr', '')) ?></textarea></label>
        <label class="fld"><span>Teste prático com o cliente</span><textarea name="practical_test" rows="2"><?= e(case_data($c, 'practical_test', '')) ?></textarea></label>
        <button class="b b-primary" type="submit">Salvar conferência</button>
      </form>
    <?php break;

    case 'lista_encerramento':
        $ck = case_checks($id, 'encerramento'); ?>
      <div class="cklist">
        <?php foreach (CLOSING_LIST as $k => $label): ?><?= check_item($c, 'encerramento', $k, $label, $ck[$k] ?? null, 'conferencia', true) ?><?php endforeach ?>
      </div>
    <?php break;

    case 'encerrar':
        $closing = case_data($c, 'closing', []); ?>
      <?= case_form($c, 'encerrar', [], 'g-form') ?>
        <fieldset class="g-radios">
          <legend>Desfecho</legend>
          <?php foreach (OUTCOMES as $ok => $o): ?>
            <label class="g-radio"><input type="radio" name="outcome" value="<?= $ok ?>" required<?= $c['outcome'] === $ok ? ' checked' : '' ?>><span><strong><?= e($o['label']) ?></strong><small><?= e($o['text']) ?></small></span></label>
          <?php endforeach ?>
        </fieldset>
        <label class="fld"><span>Resumo de uma página: problema inicial, caminho seguido, solução e efeito financeiro</span><textarea name="summary" rows="5" required><?= e($closing['summary'] ?? '') ?></textarea></label>
        <label class="fld"><span>O que continua com o cliente</span><textarea name="client_duties" rows="2"><?= e($closing['client_duties'] ?? '') ?></textarea></label>
        <button class="b b-primary" type="submit" data-confirm="Encerrar o caso? O contato de 30 dias será agendado.">Encerrar o caso</button>
      </form>
    <?php break;

    endswitch;
}
