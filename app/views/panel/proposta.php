<?php
/** Proposta de um atendimento: condições à esquerda, simulação e cláusulas do contrato à direita. */
$id = (int) $c['id'];
$p = $d['pagamento'];
$tipo = $p['condicao'] === 'avista' ? 'avista' : $p['modalidade'];
$atividades = array_merge($d['atividades'], array_fill(0, max(1, 3 - count($d['atividades'])), ['descricao' => '', 'valor' => null]));
$custos = array_merge($d['custos'], array_fill(0, max(1, 3 - count($d['custos'])), ['tipo' => '', 'descricao' => '', 'valor' => null]));
$auto = proposal_auto_texts($d);
$prev = proposal_clause_preview($id, $d);
$ckd = fn(bool $b) => $b ? 'checked' : '';
$maisAberto = (bool) $d['custos'];
$g = proposal_guide($id);
$pp = guide_prices($g);
$hist = negotiation_history($id, 6);
$ev = $hist[0] ?? null;
$opt = sales_sim_options([], $d);
$perfil = negotiation_profile($id);
$scan = negotiation_client_last($id);
$nivel = ['baixa' => 'baixa', 'media' => 'média', 'alta' => 'alta', 'indefinida' => 'sem estimativa', 'ativa' => 'ativa', 'moderada' => 'moderada', 'discreta' => 'discreta', 'nao_encontrada' => 'não encontrada'];
$fonteLabel = ['ficha' => 'ficha', 'documento' => 'documento', 'reuniao' => 'reunião', 'conversa' => 'conversa'];
$efeito = ['aumenta' => ['↑', 'up'], 'reduz' => ['↓', 'down'], 'neutro' => ['→', 'flat']];
$formaLabel = ['pix' => 'PIX à vista', 'boleto' => 'boleto à vista', 'cartao' => 'cartão de crédito', 'parcelado_proprio' => 'parcelado próprio (boleto ou PIX)'];
?>
<div class="page-head">
  <div>
    <h1>Negociação · <?= e($c['client_name']) ?></h1>
    <p class="sub">Atendimento <?= e($c['protocol']) ?> · <a href="<?= e(url('painel/atendimento/' . $id)) ?>#kit">ficha e kit</a><?= $pr ? ' · salva em ' . e(fmt_datetime($pr['updated_at'])) : ' · ainda não salva' ?></p>
  </div>
  <a class="b" href="<?= e(url('painel/propostas')) ?>">Todas as propostas</a>
</div>

<?php if ($errors): ?>
  <div class="flash flash-error" role="alert">Nada foi salvo. Confira: <?= e(implode(' ', $errors)) ?></div>
<?php endif ?>

<section class="neg-kpis" data-kpis aria-label="Resumo da negociação"><?= render('panel/_proposta_kpis', ['c' => $c, 'd' => $d, 'g' => $g, 'ev' => $ev, 'opt' => $opt]) ?></section>

<div class="neg-painel">
<aside class="neg-guia stack" aria-label="Guia interno de preço">
  <section class="box neg-perfil" id="cliente">
    <div class="box-head"><h2>Cliente</h2><a class="hint" href="<?= e(url('painel/atendimento/' . $id)) ?>">ficha completa ›</a></div>
    <?php if ($scan): $sr = $scan['result']; $net = $sr['internet'] ?? []; ?>
      <div class="neg-ind-row">
        <?php if (!isset($net['erro'])): ?>
          <span class="neg-chip neg-<?= e($net['renda_estimada'] ?? 'indefinida') ?>">Renda estimada: <strong><?= e($nivel[$net['renda_estimada'] ?? 'indefinida']) ?></strong></span>
          <span class="neg-chip">Vida social: <strong><?= e($nivel[$net['vida_social'] ?? 'nao_encontrada']) ?></strong></span>
        <?php endif ?>
        <span class="neg-chip">Sensível a preço: <strong><?= e($nivel[$sr['sensibilidade_preco'] ?? 'media']) ?></strong></span>
        <span class="neg-chip">Urgência: <strong><?= e($nivel[$sr['urgencia'] ?? 'media']) ?></strong></span>
      </div>
      <?php if (!empty($net['links'])): ?>
        <ul class="neg-links">
          <?php foreach ($net['links'] as $l): ?><li><a href="<?= e($l['url']) ?>" target="_blank" rel="noopener noreferrer nofollow"><?= e($l['rede']) ?></a> <small class="muted">confere: <?= e($nivel[$l['confere']] ?? $l['confere']) ?><?= $l['citado'] ? '' : ' · não confirmado na pesquisa' ?></small></li><?php endforeach ?>
        </ul>
      <?php elseif (isset($net['erro'])): ?><p class="hint"><?= e($net['erro']) ?></p>
      <?php else: ?><p class="hint">Nenhum perfil público encontrado com segurança.</p><?php endif ?>
      <?php if (!empty($net['resumo'])): ?><p class="neg-resumo"><?= e($net['resumo']) ?></p><?php endif ?>
      <?php if ($sr['pontos']): ?>
        <h3>Para a negociação</h3>
        <ul class="neg-pontos"><?php foreach ($sr['pontos'] as $pt): ?><li><?= e($pt['ponto']) ?> <small class="muted"><?= e($fonteLabel[$pt['fonte']] ?? $pt['fonte']) ?></small></li><?php endforeach ?></ul>
      <?php endif ?>
      <?php if ($sr['preferencia_pagamento']): ?><p><strong>Pagamento:</strong> <?= e($sr['preferencia_pagamento']) ?></p><?php endif ?>
      <?php if ($sr['objecoes']): ?><p><strong>Objeções prováveis:</strong> <?= e(implode('; ', $sr['objecoes'])) ?>.</p><?php endif ?>
      <p class="hint">Análise de <?= e(fmt_datetime($scan['created_at'])) ?>. Os perfis vêm de pesquisa pública: confira antes de considerar (pode haver homônimos).</p>
    <?php endif ?>
    <details class="neg-ficha" <?= $scan ? '' : 'open' ?>><summary>Dados da ficha</summary>
      <?php if ($perfil): ?>
        <dl class="neg-dl"><?php foreach ($perfil as $k => $v): ?><div><dt><?= e($k) ?></dt><dd><?= e(mb_strimwidth($v, 0, 140, '…')) ?></dd></div><?php endforeach ?></dl>
      <?php else: ?><p class="hint">A ficha ainda não tem dados de renda, contrato e situação.</p><?php endif ?>
    </details>
    <form method="post" action="<?= e(url('painel/propostas/' . $id . '/cliente')) ?>" class="neg-scan" data-scan>
      <?= csrf_field() ?>
      <button class="b b-primary b-sm" type="submit" <?= llm_enabled() ? '' : 'disabled title="Ligue a IA em Configurações"' ?>><?= $scan ? 'Analisar de novo' : 'Analisar o cliente com a IA' ?></button>
      <span class="hint" data-scan-msg>Lê a ficha, os documentos e a conversa, e procura a presença pública na internet.</span>
    </form>
  </section>

  <form class="box form neg-guia-form" id="guia" method="post" action="<?= e(url('painel/propostas/' . $id . '/guia')) ?>">
    <?= csrf_field() ?>
    <div class="box-head"><h2>Guia de preço</h2><span class="neg-interno">interno</span></div>
    <fieldset class="neg-servicos"><legend class="lbl">Serviços (tabela da OAB)</legend>
      <?php foreach (oab_table() as $it): ?>
        <label class="chk"><input type="checkbox" name="servicos[]" value="<?= e($it['key']) ?>" <?= $ckd(in_array($it['key'], $g['servicos'], true)) ?>> <?= e($it['label']) ?> <small class="muted"><?= (int) ($it['valor'] ?? 0) ? e(brl((int) $it['valor'])) : 'sem valor' ?></small></label>
      <?php endforeach ?>
    </fieldset>
    <?php if ($pp['sem_valor'] || !oab_table_filled()): ?><p class="hint t-warn">Há serviço sem valor da OAB. <a href="<?= e(url('painel/configuracoes/precos')) ?>">Preencher a tabela</a> ou informe o valor abaixo.</p><?php endif ?>
    <label class="fld"><span>Valor da OAB para este caso <small>(opcional, substitui a soma)</small></span><input name="base_manual" inputmode="decimal" placeholder="<?= e($pp['base'] && !$pp['manual'] ? cents_input($pp['base']) : '0,00') ?>" value="<?= e($pp['manual'] ? cents_input($pp['base']) : '') ?>"></label>
    <fieldset class="neg-ind"><legend class="lbl">Como chegou</legend>
      <label class="chk"><input type="radio" name="indicacao" value="nao" <?= $ckd($g['indicacao'] === false) ?>> Sem indicação <small class="muted">(valor da OAB)</small></label>
      <label class="chk"><input type="radio" name="indicacao" value="sim" <?= $ckd($g['indicacao'] === true) ?>> Por indicação <small class="muted">(+<?= e(fmt_pct(indicacao_pct())) ?>)</small></label>
    </fieldset>
    <label class="fld"><span>Quem indicou <small>(opcional)</small></span><input name="indicado_por" maxlength="120" value="<?= e($g['indicado_por']) ?>"></label>
    <label class="fld"><span>Suas notas para a negociação</span><textarea name="notas" rows="3" maxlength="2000" placeholder="ex.: comparou com outro escritório; prefere pagar no cartão"><?= e($g['notas']) ?></textarea></label>
    <div class="neg-ref"><span>Referência</span><strong><?= $pp['referencia'] ? e(brl($pp['referencia'])) : '—' ?></strong><?php if ($pp['referencia'] && $pp['pct']): ?><small>OAB <?= e(brl($pp['base'])) ?> + <?= e(fmt_pct($pp['pct'])) ?></small><?php endif ?></div>
    <div class="inline-form">
      <button class="b" type="submit">Salvar guia</button>
      <button class="b b-primary" type="submit" formaction="<?= e(url('painel/propostas/' . $id . '/avaliar')) ?>" data-avaliar <?= llm_enabled() ? '' : 'disabled title="Ligue a IA em Configurações"' ?>>Pedir avaliação à IA</button>
    </div>
    <p class="hint" data-avaliando hidden aria-live="polite">A IA está lendo o perfil do cliente. Leva até um minuto.</p>
  </form>

  <section class="box neg-avaliacao" id="avaliacao">
    <div class="box-head"><h2>Avaliação da IA</h2><?php if ($ev): ?><span class="hint"><?= e(fmt_datetime($ev['created_at'])) ?> · confiança <?= e($ev['result']['confianca'] ?? 'media') ?></span><?php endif ?></div>
    <?php if (!$ev): ?>
      <p class="hint">Marque os serviços e se o cliente veio por indicação, depois peça a avaliação. A IA usa os dados deste atendimento e a análise do cliente, quando houver, e sugere preço, forma e prazo. É um guia para você: nada vai para o cliente.</p>
    <?php else: $r = $ev['result']; $pg = $r['pagamento']; ?>
      <div class="neg-sugestao">
        <div><span>Preço sugerido</span><strong><?= e(brl((int) $r['preco_sugerido'])) ?></strong><?php if ($r['referencia']): ?><small><?= ($r['ajuste_percentual'] >= 0 ? '+' : '') . e(fmt_pct((float) $r['ajuste_percentual'])) ?> em relação à referência de <?= e(brl((int) $r['referencia'])) ?></small><?php endif ?></div>
        <div><span>Faixa para negociar</span><strong><?= e(brl((int) $r['faixa']['minimo'])) ?> a <?= e(brl((int) $r['faixa']['maximo'])) ?></strong><?php if ($r['piso']): ?><small>valor da OAB: <?= e(brl((int) $r['piso'])) ?></small><?php endif ?></div>
      </div>
      <p class="neg-pag"><strong>Pagamento:</strong> <?= e($formaLabel[$pg['forma']] ?? $pg['forma']) ?><?= $pg['entrada'] ? ', entrada de ' . e(brl((int) $pg['entrada'])) : '' ?><?= $pg['parcelas'] > 1 ? ', ' . (int) $pg['parcelas'] . ' parcelas' . ($pg['valor_parcela'] ? ' de cerca de ' . e(brl((int) $pg['valor_parcela'])) : '') : '' ?>.<?= $pg['justificativa'] ? ' ' . e($pg['justificativa']) : '' ?></p>
      <?php if ($r['resumo']): ?><p class="neg-resumo"><?= e($r['resumo']) ?></p><?php endif ?>
      <button type="button" class="b b-sm" data-aplicar-ia data-valor="<?= e(cents_input((int) $r['preco_sugerido'])) ?>" data-tipo="<?= e($pg['forma'] === 'cartao' ? 'cartao' : ($pg['forma'] === 'parcelado_proprio' ? 'proprio' : 'avista')) ?>" data-parcelas="<?= (int) $pg['parcelas'] ?>" data-entrada="<?= $pg['entrada'] ? e(cents_input((int) $pg['entrada'])) : '' ?>" data-forma="<?= e(in_array($pg['forma'], ['pix', 'boleto'], true) ? $pg['forma'] : '') ?>">Aplicar à proposta</button>
      <span class="hint">preenche os campos; confira e salve</span>
      <?php if ($r['perfil_consumo']): ?><h3>Perfil de consumo</h3><p><?= e($r['perfil_consumo']) ?></p><?php endif ?>
      <?php if ($r['capacidade_pagamento']): ?><h3>Capacidade de pagamento</h3><p><?= e($r['capacidade_pagamento']) ?></p><?php endif ?>
      <?php if ($r['sinais']): ?><h3>Sinais considerados</h3><ul class="neg-sinais"><?php foreach ($r['sinais'] as $si): [$ar, $cl] = $efeito[$si['efeito']]; ?><li class="neg-<?= $cl ?>"><span aria-label="<?= e($si['efeito']) ?>"><?= $ar ?></span> <?= e($si['sinal']) ?> <small class="muted">peso <?= e($si['peso'] === 'medio' ? 'médio' : $si['peso']) ?></small></li><?php endforeach ?></ul><?php endif ?>
      <?php if ($r['argumentos']): ?><h3>Argumentos para a conversa</h3><ul><?php foreach ($r['argumentos'] as $x): ?><li><?= e($x) ?></li><?php endforeach ?></ul><?php endif ?>
      <?php if ($r['concessoes']): ?><h3>O que ceder, nesta ordem</h3><ol><?php foreach ($r['concessoes'] as $x): ?><li><?= e($x) ?></li><?php endforeach ?></ol><?php endif ?>
      <?php if ($r['exito_percentual'] !== null): ?><p><strong>Êxito sugerido:</strong> <?= e(fmt_pct((float) $r['exito_percentual'])) ?></p><?php endif ?>
      <?php if ($r['alertas']): ?><h3>Atenção</h3><ul class="neg-alertas"><?php foreach ($r['alertas'] as $x): ?><li><?= e($x) ?></li><?php endforeach ?></ul><?php endif ?>
      <?php if ($r['faltam_dados']): ?><h3>Faltam dados</h3><ul class="muted"><?php foreach ($r['faltam_dados'] as $x): ?><li><?= e($x) ?></li><?php endforeach ?></ul><?php endif ?>
      <?php if (count($hist) > 1): ?>
        <details class="neg-hist"><summary>Avaliações anteriores</summary><ul><?php foreach (array_slice($hist, 1) as $h): ?><li><?= e(fmt_datetime($h['created_at'])) ?> · <?= e(brl((int) ($h['result']['preco_sugerido'] ?? 0))) ?><?= $h['user_name'] ? ' · ' . e($h['user_name']) : '' ?></li><?php endforeach ?></ul></details>
      <?php endif ?>
    <?php endif ?>
  </section>
</aside>

<form class="form prop-form prop-layout" id="prop-form" method="post" action="<?= e(url('painel/propostas/' . $id)) ?>" data-proposta data-previa="<?= e(url('painel/propostas/' . $id . '/previa')) ?>">
  <?= csrf_field() ?>
  <div class="prop-campos stack">
    <section class="box">
      <div class="box-head"><h2>Honorários</h2></div>
      <div class="var-grid">
        <label class="fld"><span>Valor fixo (R$)</span><input name="fixos_valor" inputmode="decimal" placeholder="0,00" value="<?= e(cents_input($d['fixos']['valor'])) ?>"></label>
        <label class="fld"><span>O que remunera <small>(opcional)</small></span><input name="fixos_descricao" maxlength="500" placeholder="ex.: notificação e ação revisional em 1º grau" value="<?= e($d['fixos']['descricao']) ?>"></label>
      </div>
      <details class="prop-mais" <?= $d['atividades'] ? 'open' : '' ?>>
        <summary>Honorários por atividade</summary>
        <div class="prop-rows">
          <?php foreach ($atividades as $i => $a): ?>
            <div class="prop-row">
              <label class="fld"><span>Atividade</span><input name="atividade_descricao[]" maxlength="300" placeholder="ex.: audiência de conciliação" value="<?= e($a['descricao']) ?>"></label>
              <label class="fld prop-valor"><span>Valor (R$)</span><input name="atividade_valor[]" inputmode="decimal" placeholder="0,00" value="<?= e(cents_input($a['valor'])) ?>"></label>
            </div>
          <?php endforeach ?>
        </div>
      </details>
      <label class="chk prop-toggle"><input type="checkbox" name="exito_ativo" value="1" <?= $ckd($d['exito']['ativo']) ?>> Honorários de êxito</label>
      <div class="prop-if-exito prop-exito">
        <label class="fld prop-pct"><span>Percentual</span><input name="exito_percentual" inputmode="decimal" placeholder="20" value="<?= e($d['exito']['percentual']) ?>"></label>
        <label class="fld"><span>Sobre</span><textarea name="exito_base" rows="3" maxlength="1000"><?= e($d['exito']['base']) ?></textarea></label>
      </div>
    </section>

    <section class="box">
      <div class="box-head"><h2>Pagamento</h2></div>
      <div class="prop-tipos" role="radiogroup" aria-label="Condição de pagamento">
        <label><input type="radio" name="pagamento_tipo" value="avista" <?= $ckd($tipo === 'avista') ?>><span><strong>À vista</strong></span></label>
        <label><input type="radio" name="pagamento_tipo" value="proprio" <?= $ckd($tipo === 'proprio') ?>><span><strong>Parcelado</strong><small>juros de 1% a.m. + INPC</small></span></label>
        <label><input type="radio" name="pagamento_tipo" value="cartao" <?= $ckd($tipo === 'cartao') ?>><span><strong>Cartão de crédito</strong><small>taxa da operadora pelo cliente</small></span></label>
      </div>
      <div class="var-grid prop-if-avista">
        <label class="fld"><span>Vencimento</span><input type="date" name="vencimento" value="<?= e((string) $p['vencimento']) ?>"></label>
      </div>
      <div class="var-grid prop-if-parcelado">
        <label class="fld"><span>Parcelas</span><input type="number" name="parcelas" min="1" max="60" value="<?= (int) $p['parcelas'] ?>"></label>
        <label class="fld prop-if-proprio"><span>1º vencimento</span><input type="date" name="primeiro_vencimento" value="<?= e((string) $p['primeiro_vencimento']) ?>"></label>
        <label class="fld"><span>Entrada (R$, opcional)</span><input name="entrada" inputmode="decimal" placeholder="0,00" value="<?= e(cents_input($p['entrada'])) ?>"></label>
        <label class="fld"><span>Vencimento da entrada</span><input type="date" name="entrada_vencimento" value="<?= e((string) $p['entrada_vencimento']) ?>"></label>
      </div>
      <fieldset class="prop-formas"><legend class="lbl">Formas aceitas</legend>
        <?php foreach (PAYMENT_METHODS as $k => $l): ?>
          <label class="chk"><input type="checkbox" name="formas[]" value="<?= e($k) ?>" <?= $ckd(in_array($k, $p['formas'], true)) ?>> <?= e(mb_strtoupper(mb_substr($l, 0, 1)) . mb_substr($l, 1)) ?></label>
        <?php endforeach ?>
      </fieldset>
    </section>

    <details class="box prop-mais" <?= $maisAberto ? 'open' : '' ?>>
      <summary><h2>Valores cobrados à parte</h2><span class="hint">taxas, emolumentos, custas, impostos</span></summary>
      <p class="hint">Não são honorários.</p>
      <div class="prop-rows">
        <?php foreach ($custos as $x): ?>
          <div class="prop-row prop-row3">
            <label class="fld"><span>Tipo</span>
              <select name="custo_tipo[]"><option value="">—</option><?php foreach (COST_TYPES as $k => $l): ?><option value="<?= e($k) ?>" <?= selected($k, $x['tipo']) ?>><?= e($l) ?></option><?php endforeach ?></select>
            </label>
            <label class="fld"><span>Descrição</span><input name="custo_descricao[]" maxlength="300" placeholder="ex.: certidão de ônus do veículo" value="<?= e($x['descricao']) ?>"></label>
            <label class="fld prop-valor"><span>Estimativa</span><input name="custo_valor[]" inputmode="decimal" placeholder="a apurar" value="<?= e(cents_input($x['valor'])) ?>"></label>
          </div>
        <?php endforeach ?>
      </div>
      <?php if ($p['observacoes'] !== ''): ?><input type="hidden" name="pagamento_observacoes" value="<?= e($p['observacoes']) ?>"><?php endif ?>
    </details>

    <p class="hint">Deslocamento e despesas antecipadas pelo escritório são sempre reembolsados pelo cliente, mediante comprovação.</p>
    <div class="inline-form"><button class="b b-primary" type="submit">Salvar proposta</button><span class="hint" data-erros aria-live="polite"></span></div>
  </div>

  <aside class="prop-previa stack" aria-label="Prévia">
    <section class="box">
      <div class="box-head"><h2>Simulação</h2></div>
      <div data-sim><?= render('panel/_proposta_simulacao', ['d' => $d]) ?></div>
    </section>
    <section class="box neg-vendas" id="simulador">
      <div class="box-head"><h2>Simulador de vendas</h2><span class="hint">taxas do Asaas</span></div>
      <div class="neg-sim-ctl">
        <label class="fld"><span>Valor a simular</span><input name="sim_valor" inputmode="decimal" placeholder="<?= e(cents_input(proposal_totals($d)['total']) ?: '0,00') ?>" aria-describedby="sim-dica"></label>
        <label class="fld"><span>Desconto à vista (%)</span><input name="sim_desconto" inputmode="decimal" placeholder="0"></label>
        <label class="fld"><span>Parcelado próprio</span><input type="number" name="sim_parcelas" min="2" max="60" value="<?= (int) $opt['parcelas_proprio'] ?>"></label>
        <label class="chk"><input type="checkbox" name="sim_repasse" value="1" <?= $ckd($opt['repasse']) ?>> Taxa do cartão paga pelo cliente</label>
      </div>
      <p class="hint" id="sim-dica">Em branco, simula os honorários da proposta. “Usar” leva a condição para a proposta.</p>
      <div data-vendas><?= render('panel/_proposta_vendas', ['d' => $d, 'opt' => $opt]) ?></div>
      <?php if (asaas_enabled()): ?>
        <div class="inline-form"><button type="button" class="b b-sm" data-asaas-sim="<?= e(url('painel/propostas/' . $id . '/asaas-simular')) ?>">Conferir no Asaas</button><span class="hint">consulta o simulador oficial da sua conta</span></div>
        <div data-asaas-res aria-live="polite"></div>
      <?php endif ?>
    </section>
    <section class="box">
      <div class="box-head"><h2>Como fica no contrato</h2></div>
      <p class="hint">O texto em destaque é escrito pela proposta e pode ser ajustado aqui mesmo. O restante vem do modelo do contrato.</p>
      <?php foreach (PROPOSAL_TEXTS as $k => $label):
        $manual = ($d['texto'][$k] ?? null) !== null;
        $shown = $d['texto'][$k] ?? $auto[$k];
        $ref = $manual ? ($d['texto_ref'][$k] ?? $auto[$k]) : '';
        $velho = $manual && proposal_norm_text($ref) !== proposal_norm_text($auto[$k]);
      ?>
        <div class="prop-clausula <?= $manual ? 'is-manual' : '' ?> <?= $velho ? 'is-velho' : '' ?>" data-texto="<?= e($k) ?>">
          <div class="prop-doc" data-antes><?= $prev[$k]['antes'] ?? '' ?></div>
          <label class="prop-editavel"><span class="sr-only"><?= e($label) ?></span>
            <textarea name="texto_<?= e($k) ?>" rows="4" spellcheck="true" data-auto="<?= e($auto[$k]) ?>"><?= e($shown) ?></textarea>
          </label>
          <input type="hidden" name="texto_<?= e($k) ?>_base" value="<?= e($shown) ?>" data-base>
          <input type="hidden" name="texto_<?= e($k) ?>_manual" value="<?= $manual ? '1' : '0' ?>" data-manual>
          <input type="hidden" name="texto_<?= e($k) ?>_ref" value="<?= e($ref) ?>" data-ref>
          <p class="prop-estado"><span class="-auto">Texto automático: acompanha os valores.</span><span class="-manual">Ajustado por você.</span><span class="-velho"> Os valores mudaram depois do ajuste; confira.</span> <button type="button" class="b-link -manual" data-restaurar>Voltar ao texto automático</button></p>
          <div class="prop-doc" data-depois><?= $prev[$k]['depois'] ?? '' ?></div>
        </div>
      <?php endforeach ?>
      <?php if (!$prev): ?><p class="hint">O modelo do contrato não foi encontrado; a prévia mostra só os trechos da proposta.</p><?php endif ?>
    </section>
  </aside>
</form>
</div>

<?php
$charges = asaas_charges($id);
$active = array_values(array_filter($charges, fn($r) => $r['status'] !== 'DELETED'));
$blockers = asaas_blockers($c, $pr);
$plan = $pr ? asaas_plan($pr['data']) : [];
$today = now()->format('Y-m-d');
?>
<section class="box" id="asaas">
  <div class="box-head"><h2>Cobrança no Asaas</h2><?php if (asaas_enabled()): ?><span class="hint"><?= e(ASAAS_ENVS[asaas_env()]['label']) ?></span><?php endif ?></div>
  <?php if ($active): $sum = asaas_summary($id); ?>
    <p>Recebido <strong><?= e(brl($sum['pago'])) ?></strong> de <?= e(brl($sum['total'])) ?> · <?= (int) $sum['pagas'] ?> de <?= (int) $sum['n'] ?> cobrança(s) paga(s)<?= $sum['vencidas'] ? ' · <strong class="t-warn">' . (int) $sum['vencidas'] . ' vencida(s)</strong>' : '' ?></p>
    <div class="tbl-wrap">
      <table class="tbl tbl-compact">
        <thead><tr><th>Cobrança</th><th>Vencimento</th><th>Valor</th><th>Tipo</th><th>Situação</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($charges as $r): ?>
            <tr class="<?= $r['status'] === 'DELETED' ? 'muted' : '' ?>">
              <td><?= e($r['label']) ?></td>
              <td><?= e(fmt_date($r['due_date'])) ?></td>
              <td><?= e(brl((int) $r['value_cents'])) ?><?= (int) $r['installments'] > 1 ? ' <small>em ' . (int) $r['installments'] . 'x</small>' : '' ?></td>
              <td><?= e(ASAAS_BILLING[$r['billing_type']] ?? $r['billing_type']) ?></td>
              <td><?= e(ASAAS_STATUS[$r['status']] ?? $r['status']) ?><?= $r['paid_at'] ? ' em ' . e(fmt_date($r['paid_at'])) : '' ?></td>
              <td><?php if ($r['invoice_url'] && $r['status'] !== 'DELETED'): ?><a href="<?= e($r['invoice_url']) ?>" target="_blank" rel="noopener">fatura</a><?php endif ?></td>
            </tr>
          <?php endforeach ?>
        </tbody>
      </table>
    </div>
    <form class="inline-form" method="post" action="<?= e(url('painel/propostas/' . $id . '/asaas')) ?>">
      <?= csrf_field() ?>
      <button class="b" name="op" value="atualizar">Atualizar situação</button>
      <button class="b b-danger" name="op" value="cancelar" data-confirm="Cancelar no Asaas todas as cobranças ainda não pagas deste contrato?">Cancelar as não pagas</button>
    </form>
    <p class="hint">O link da fatura pode ser enviado ao cliente; o Asaas também avisa por e-mail e SMS conforme as notificações da sua conta.</p>
  <?php elseif ($blockers): ?>
    <p>Depois do contrato assinado, as cobranças são criadas no Asaas com os mesmos valores e vencimentos do contrato.</p>
    <ul class="prop-bloqueios"><?php foreach ($blockers as $b): ?><li><?= e($b) ?></li><?php endforeach ?></ul>
    <?php if (!asaas_enabled()): ?><p><a href="<?= e(url('painel/configuracoes/asaas')) ?>">Configurar o Asaas</a></p><?php endif ?>
  <?php else: $billing = asaas_billing_type($pr['data']); ?>
    <p>O contrato está assinado e confere com esta proposta. Serão criadas no Asaas:</p>
    <form method="post" action="<?= e(url('painel/propostas/' . $id . '/asaas')) ?>" class="form">
      <?= csrf_field() ?>
      <div class="tbl-wrap">
        <table class="tbl tbl-compact">
          <thead><tr><th>Cobrança</th><th>Valor do contrato</th><th>Vencimento</th></tr></thead>
          <tbody>
            <?php foreach ($plan as $item): $old = $item['vencimento'] && $item['vencimento'] < $today; ?>
              <tr>
                <td><?= e($item['rotulo']) ?></td>
                <td><strong><?= e(brl($item['valor'])) ?></strong></td>
                <td><input type="date" name="venc_<?= e($item['ref']) ?>" value="<?= e((string) ($old ? '' : $item['vencimento'])) ?>" min="<?= e($today) ?>" required aria-label="Vencimento de <?= e($item['rotulo']) ?>">
                  <?php if ($old): ?><br><small class="t-warn">No contrato: <?= e(fmt_date($item['vencimento'])) ?> (já passou)</small><?php endif ?></td>
              </tr>
            <?php endforeach ?>
          </tbody>
        </table>
      </div>
      <div class="var-grid">
        <?php if (!array_filter($plan, fn($i) => $i['ref'] === 'cartao') || count($plan) > 1): ?>
          <label class="fld"><span>Tipo de cobrança</span>
            <select name="billing_type"><?php foreach (ASAAS_BILLING as $bk => $bl): if ($bk === 'CREDIT_CARD' && $billing !== 'CREDIT_CARD') { continue; } ?><option value="<?= e($bk) ?>" <?= selected($bk, $billing) ?>><?= e($bl) ?></option><?php endforeach ?></select>
          </label>
        <?php endif ?>
        <?php if (array_filter($plan, fn($i) => $i['ref'] === 'cartao')): ?>
          <label class="fld"><span>Acréscimo da taxa da operadora (%)</span><input name="acrescimo_cartao" inputmode="decimal" placeholder="0"></label>
        <?php endif ?>
      </div>
      <p class="hint">Multa de <?= e(str_replace('.', ',', (string) asaas_late_fees()['multa'])) ?>% e juros de <?= e(str_replace('.', ',', (string) asaas_late_fees()['juros'])) ?>% ao mês após o vencimento, como na cláusula de atraso. O cliente é cadastrado no Asaas pelo CPF, com os dados da ficha.</p>
      <button class="b b-primary" name="op" value="enviar" data-confirm="Criar estas cobranças no Asaas (<?= e(ASAAS_ENVS[asaas_env()]['label']) ?>)?">Criar cobranças no Asaas</button>
    </form>
  <?php endif ?>
</section>
<script src="<?= e(asset('js/proposta.js')) ?>" defer></script>
