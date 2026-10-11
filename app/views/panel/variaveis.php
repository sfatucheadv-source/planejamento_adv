<?php
/** Painel de variáveis: dados do escritório, personalizadas e catálogo completo. */
$canEdit = is_operator() || has_role('supervisor');
$groupNames = ['atendimento' => 'Atendimento', 'data' => 'Data', 'kit' => 'Kit', 'cliente' => 'Cliente', 'titular' => 'Titular', 'contrato' => 'Contrato de financiamento',
    'veiculo' => 'Veículo', 'analise' => 'Análise', 'proposta' => 'Proposta', 'procuracao' => 'Procuração', 'gratuidade' => 'Justiça gratuita',
    'documento' => 'Documento', 'testemunha1' => 'Testemunha 1', 'testemunha2' => 'Testemunha 2', 'escritorio' => 'Escritório'];
$usedIn = fn(string $k) => $used[$k] ? implode(', ', $used[$k]) : '—';
?>
<div class="page-head">
  <div>
    <h1>Variáveis dos documentos</h1>
    <p class="sub">Cada <code>{{variável}}</code> de um modelo é trocada pelo dado correspondente quando o kit é gerado. Aqui você preenche os dados do escritório, cria variáveis novas e consulta de onde vem cada uma.</p>
  </div>
  <a class="b" href="<?= e(url('painel/modelos-documentos')) ?>">Modelos de documentos</a>
</div>

<section class="box" id="escritorio">
  <div class="box-head"><h2>Dados do escritório</h2><span class="hint">Valem para todos os atendimentos</span></div>
  <form class="form stack" method="post" action="<?= e(url('painel/variaveis')) ?>">
    <?= csrf_field() ?><input type="hidden" name="op" value="escritorio">
    <div class="var-grid">
      <?php foreach (OFFICE_VARS as $k => $d): $name = str_replace('.', '_', $k); $cur = (string) setting($d['setting'], ''); ?>
        <div class="<?= $d['type'] === 'textarea' ? 'var-wide' : '' ?>">
          <label class="fld"><span><?= e($d['label']) ?> <code class="var-code">{{<?= e($k) ?>}}</code></span>
            <?php if ($d['type'] === 'choice'): ?>
              <select name="<?= e($name) ?>" <?= $canEdit ? '' : 'disabled' ?>><option value="">—</option><?php foreach ($d['options'] as $o => $l): ?><option value="<?= e($o) ?>" <?= selected($o, $cur) ?>><?= e($l) ?></option><?php endforeach ?></select>
            <?php elseif ($d['type'] === 'textarea'): ?>
              <textarea name="<?= e($name) ?>" rows="3" maxlength="<?= (int) $d['max'] ?>" <?= $canEdit ? '' : 'disabled' ?>><?= e($cur) ?></textarea>
            <?php else: ?>
              <input type="<?= $d['type'] === 'email' ? 'email' : 'text' ?>" name="<?= e($name) ?>" value="<?= e($cur) ?>" maxlength="<?= (int) $d['max'] ?>" placeholder="<?= e($d['ph'] ?? '') ?>" <?= $canEdit ? '' : 'disabled' ?>>
            <?php endif ?>
          </label>
          <?php if (!empty($d['hint'])): ?><p class="hint"><?= e($d['hint']) ?></p><?php endif ?>
        </div>
      <?php endforeach ?>
    </div>
    <h3>Padrões usados quando a ficha do atendimento estiver em branco</h3>
    <div class="var-grid">
      <?php foreach (CASE_VAR_DEFAULTS as $k => $d): ?>
        <div>
          <label class="fld"><span><?= e(template_var_labels()[$k]) ?> <code class="var-code">{{<?= e($k) ?>}}</code></span>
            <input name="<?= e($d['setting']) ?>" value="<?= e((string) setting($d['setting'], '')) ?>" maxlength="300" placeholder="<?= e($d['ph']) ?>" <?= $canEdit ? '' : 'disabled' ?>>
          </label>
        </div>
      <?php endforeach ?>
    </div>
    <p class="hint">O foro é independente de <code>{{data.cidade}}</code>: a cidade do escritório e a comarca do contrato podem ser diferentes. Em cada atendimento, o foro e o prazo de repasse podem ser trocados na ficha (bloco 3).</p>
    <p class="hint">Vêm do arquivo de configuração do site e não mudam aqui: <?= e(office('society')) ?> · <?= e(office('society_oab')) ?> · CNPJ <?= e(office('cnpj')) ?> · <?= e(office('lawyer')) ?> (<?= e(office('lawyer_oab')) ?>) · cidade <?= e(office('city')) ?>.</p>
    <?php if ($canEdit): ?><div class="inline-form"><button class="b b-primary" type="submit">Salvar dados do escritório</button></div><?php endif ?>
  </form>
</section>

<section class="box" id="personalizadas">
  <div class="box-head"><h2>Variáveis personalizadas</h2><span class="hint"><?= count($custom) ?> criada(s)</span></div>
  <p class="small">Crie uma variável quando um modelo precisar de um dado que ainda não existe. <strong>Do escritório</strong>: um valor só, preenchido aqui. <strong>Do atendimento</strong>: preenchida na ficha de cada caso (bloco 3); o valor daqui serve de padrão quando a ficha estiver em branco.</p>
  <?php if ($custom): ?>
    <div class="tbl-wrap">
      <table class="tbl tbl-compact">
        <thead><tr><th>Variável</th><th>Alcance</th><th>Valor ou padrão</th><th>Usada em</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($custom as $k => $r): ?>
          <tr id="v-<?= (int) $r['id'] ?>">
            <?php if ($edit === (int) $r['id'] && $canEdit): ?>
              <td colspan="5">
                <form class="form stack" method="post" action="<?= e(url('painel/variaveis')) ?>">
                  <?= csrf_field() ?><input type="hidden" name="op" value="alterar"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                  <p><code class="var-code">{{<?= e($k) ?>}}</code> · <?= e($r['scope'] === 'escritorio' ? 'do escritório' : 'do atendimento') ?> <small class="muted">(o nome da variável não muda depois de criada)</small></p>
                  <label class="fld"><span>Para que serve</span><input name="label" value="<?= e($r['label']) ?>" maxlength="120" required></label>
                  <label class="fld"><span><?= $r['scope'] === 'escritorio' ? 'Valor' : 'Valor padrão (opcional)' ?></span><textarea name="value" rows="3" maxlength="3000"><?= e((string) $r['value']) ?></textarea></label>
                  <label class="fld"><span>Orientação para preencher (opcional)</span><input name="help" value="<?= e((string) $r['help']) ?>" maxlength="300"></label>
                  <div class="inline-form"><button class="b b-primary" type="submit">Salvar</button><a class="b" href="<?= e(url('painel/variaveis')) ?>#personalizadas">Cancelar</a></div>
                </form>
              </td>
            <?php else: ?>
              <td><code class="var-code">{{<?= e($k) ?>}}</code><br><?= e($r['label']) ?><?= $r['help'] ? '<br><small class="muted">' . e($r['help']) . '</small>' : '' ?></td>
              <td><?= e($r['scope'] === 'escritorio' ? 'Escritório' : 'Atendimento') ?></td>
              <td><?= $r['value'] !== null ? e(mb_strimwidth($r['value'], 0, 140, '…')) : '<span class="muted">' . ($r['scope'] === 'escritorio' ? 'sem valor' : 'sem padrão') . '</span>' ?></td>
              <td><?= e($usedIn($k)) ?></td>
              <td>
                <?php if ($canEdit): ?>
                  <a class="b b-xs" href="<?= e(url('painel/variaveis', ['editar' => $r['id']])) ?>#v-<?= (int) $r['id'] ?>">Editar</a>
                  <?php if (!$used[$k]): ?>
                    <form class="inline-form" method="post" action="<?= e(url('painel/variaveis')) ?>" onsubmit="return confirm('Excluir a variável {{<?= e($k) ?>}}?<?= $r['scope'] === 'atendimento' ? ' Os valores preenchidos nas fichas também serão apagados.' : '' ?>')"><?= csrf_field() ?><input type="hidden" name="op" value="excluir"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button class="b b-xs" type="submit">Excluir</button></form>
                  <?php endif ?>
                <?php endif ?>
              </td>
            <?php endif ?>
          </tr>
        <?php endforeach ?>
        </tbody>
      </table>
    </div>
  <?php endif ?>
  <?php if ($canEdit): ?>
    <details class="at-edit" <?= $custom ? '' : 'open' ?>>
      <summary>Criar variável</summary>
      <form class="form stack" method="post" action="<?= e(url('painel/variaveis')) ?>">
        <?= csrf_field() ?><input type="hidden" name="op" value="criar">
        <div class="var-grid">
          <label class="fld"><span>Nome da variável</span><input name="key" placeholder="ex.: contrato.multa_rescisoria" maxlength="72" required spellcheck="false" autocomplete="off"></label>
          <label class="fld"><span>Para que serve</span><input name="label" placeholder="ex.: Multa em caso de rescisão" maxlength="120" required></label>
        </div>
        <fieldset class="radios"><legend class="lbl">Alcance</legend>
          <?php foreach (VAR_SCOPES as $sk => $sl): ?><label class="chk"><input type="radio" name="scope" value="<?= e($sk) ?>" <?= checked($sk === 'atendimento') ?>> <?= e($sl) ?></label><?php endforeach ?>
        </fieldset>
        <label class="fld"><span>Valor (do escritório) ou valor padrão (do atendimento)</span><textarea name="value" rows="2" maxlength="3000"></textarea></label>
        <label class="fld"><span>Orientação para preencher (opcional)</span><input name="help" maxlength="300"></label>
        <p class="hint">Formato <code>grupo.nome</code>, com letras sem acento, números e “_”. Acentos e espaços são convertidos: “Contrato.Multa rescisória” vira <code>contrato.multa_rescisoria</code>.</p>
        <div class="inline-form"><button class="b b-primary" type="submit">Criar variável</button></div>
      </form>
    </details>
  <?php endif ?>
</section>

<section class="box" id="catalogo">
  <div class="box-head"><h2>Todas as variáveis</h2><span class="hint">Para um texto padrão quando o dado faltar: <code>{{variável|texto}}</code></span></div>
  <?php foreach ($catalog as $g => $vars): ?>
    <h3><?= e($groupNames[$g] ?? ucfirst($g)) ?></h3>
    <div class="tbl-wrap">
      <table class="tbl tbl-compact var-tbl">
        <thead><tr><th>Variável</th><th>Conteúdo</th><th>De onde vem</th><th>Usada em</th></tr></thead>
        <tbody>
        <?php foreach ($vars as $k => $v): ?>
          <tr>
            <td><code class="var-code">{{<?= e($k) ?>}}</code></td>
            <td><?= e($v['label']) ?><?= $v['optional'] ? ' <span class="tag tag-gray">opcional</span>' : '' ?></td>
            <td class="small"><?= e(VAR_ORIGINS[$v['origin']]) ?></td>
            <td class="small"><?= e($usedIn($k)) ?></td>
          </tr>
        <?php endforeach ?>
        </tbody>
      </table>
    </div>
  <?php endforeach ?>
  <p class="hint">Variáveis opcionais, como as das testemunhas, devem ir no modelo com um texto padrão, por exemplo <code>{{testemunha1.nome|__________}}</code>. Assim o documento é publicado mesmo sem testemunhas.</p>
</section>
