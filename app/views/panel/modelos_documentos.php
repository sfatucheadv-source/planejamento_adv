<?php
/**
 * Modelos de documentos: uma aba por modelo. À esquerda o texto, com a barra
 * de diagramação do padrão do escritório; à direita o documento no timbrado,
 * refeito a cada alteração, com os dados de um atendimento escolhido para
 * simular (e ajustes digitados só para a simulação).
 */
$key = $t['key'] ?? '';
$caseQ = $case ? ['caso' => (int) $case['id']] : [];
$rascunho = $_SESSION['modelo_rascunho'][$key] ?? null;
unset($_SESSION['modelo_rascunho'][$key]);
$body = $rascunho ?? ($t['body'] ?? '');
$conds = array_filter($vars, fn($l) => str_starts_with($l, 'Condição'));
$dados = array_diff_key($vars, $conds);
$blocos = [
    'titulo' => ['Título', "# Título do documento | subtítulo"],
    'secao' => ['Seção', "## I. Seção"],
    'clausula' => ['Cláusula numerada', "### Cláusula # — do objeto"],
    'paragrafo' => ['Parágrafo numerado', "**Parágrafo #.** Texto do parágrafo."],
    'rotulo' => ['Rótulo pequeno', "#### Rótulo"],
    'texto' => ['Parágrafo comum', "Texto do parágrafo, com recuo na primeira linha."],
    'qualificacao' => ['Qualificação (sem recuo)', "@qualificacao ^^{{cliente.nome_completo}}^^, {{cliente.nacionalidade}}, {{cliente.estado_civil}}, {{cliente.profissao}}, CPF {{cliente.cpf}}, residente em {{cliente.endereco}}."],
    'dados' => ['Dados (Rótulo: valor)', "@dados\nRótulo: valor\nRótulo: valor"],
    'destaque' => ['Parágrafo em destaque', "@destaque Texto em destaque, com filete azul."],
    'observacao' => ['Observação (menor)', "@observacao Observação em corpo menor."],
    'referencia' => ['Referência (versalete)', "@referencia Referência: {{atendimento.protocolo}}"],
    'centro' => ['Centralizado', "@centro Texto centralizado"],
    'direita' => ['Local e data', "@direita {{data.cidade}}, {{data.hoje}}."],
    'assinatura' => ['Assinatura', "@assinatura {{cliente.nome_completo}} | CPF {{cliente.cpf}} | CONTRATANTE"],
    'lista' => ['Lista com travessão', "- primeiro item\n- segundo item"],
    'quebra' => ['Quebra de página', "@quebra"],
    'compacto' => ['Perfil compacto (uma folha)', "@compacto"],
    'completar' => ['Trecho a completar', "[[COMPLETAR: descrição]]"],
];
?>
<div class="page-head">
  <div>
    <h1>Modelos de documentos</h1>
    <p class="sub">Cada modelo numa aba. Você edita o texto e vê o documento no timbrado ao mesmo tempo, com os dados de um atendimento à sua escolha. Salvar cria uma nova versão, que precisa da sua aprovação antes de qualquer publicação ao cliente.</p>
  </div>
  <a class="b" href="<?= e(url('painel/variaveis')) ?>">Variáveis dos documentos</a>
</div>

<nav class="prop-abas mdl-abas" aria-label="Modelos">
  <?php foreach ($templates as $m): $on = $m['key'] === $key; ?>
    <a href="<?= e(url('painel/modelos-documentos', ['modelo' => $m['key']] + $caseQ)) ?>" class="mdl-aba" aria-selected="<?= $on ? 'true' : 'false' ?>"<?= $on ? ' aria-current="page"' : '' ?>>
      <span class="mdl-dot <?= $m['approved_at'] ? 'is-ok' : 'is-wait' ?>" aria-hidden="true"></span><?= e($m['title']) ?>
    </a>
  <?php endforeach ?>
</nav>

<?php if ($t): ?>
<div class="mdl-grid" data-mdl data-simular="<?= e(url('painel/modelos-documentos/' . $key . '/simular')) ?>">
  <section class="box mdl-editor">
    <form method="post" action="<?= e(url('painel/modelos-documentos')) ?>" class="stack" id="mdl-form">
      <?= csrf_field() ?><input type="hidden" name="key" value="<?= e($key) ?>"><input type="hidden" name="op" value="salvar">
      <?php if ($case): ?><input type="hidden" name="caso" value="<?= (int) $case['id'] ?>"><?php endif ?>
      <div class="mdl-head">
        <label class="fld mdl-titulo"><span>Título</span><input name="title" value="<?= e($t['title']) ?>" maxlength="120"></label>
        <p class="mdl-versao">versão <?= (int) $t['version'] ?> · <?php if ($t['approved_at']): ?><span class="tag tag-green">aprovado em <?= e(fmt_date($t['approved_at'])) ?></span><?php else: ?><span class="tag tag-amber">aguardando aprovação</span><?php endif ?></p>
      </div>

      <div class="ed-barra mdl-barra" role="toolbar" aria-label="Diagramação do documento" data-mdl-barra hidden>
        <label class="sr-only" for="mdl-bloco">Inserir bloco</label>
        <select id="mdl-bloco" data-ins-bloco title="Blocos do padrão do escritório">
          <option value="">+ Bloco…</option>
          <?php foreach ($blocos as $bk => [$bl, $bt]): ?><option value="<?= e($bt) ?>"><?= e($bl) ?></option><?php endforeach ?>
        </select>
        <span class="ed-sep" aria-hidden="true"></span>
        <button type="button" data-wrap="**|**" title="Negrito"><b>N</b></button>
        <button type="button" data-wrap="{|i|}|{|/|}" title="Itálico"><i>I</i></button>
        <button type="button" data-wrap="{|u|}|{|/|}" title="Sublinhado"><u>S</u></button>
        <button type="button" data-wrap="{|m|}|{|/|}" title="Marca-texto"><span class="ed-marca">Marca</span></button>
        <button type="button" data-wrap="^^|^^" title="Versalete (nome das partes)">Versalete</button>
        <label class="sr-only" for="mdl-fonte">Fonte</label>
        <select id="mdl-fonte" data-ins-estilo="f" title="Fonte do trecho">
          <option value="">Fonte…</option>
          <?php foreach (DOC_FONTES as $fk => [$fn]): ?><option value="<?= e($fk) ?>"><?= e($fn) ?></option><?php endforeach ?>
        </select>
        <label class="sr-only" for="mdl-tam">Tamanho</label>
        <select id="mdl-tam" data-ins-estilo="t" title="Tamanho do trecho">
          <option value="">Tamanho…</option>
          <?php foreach (DOC_TAMANHOS as $tm): ?><option value="<?= $tm ?>"><?= $tm ?> pt</option><?php endforeach ?>
        </select>
        <span class="ed-sep" aria-hidden="true"></span>
        <label class="sr-only" for="mdl-var">Inserir variável</label>
        <select id="mdl-var" data-ins-var title="Dado do atendimento ou do escritório">
          <option value="">{{ }} Variável…</option>
          <?php foreach ($dados as $vk => $vl): ?><option value="<?= e($vk) ?>"><?= e($vl) ?> · <?= e($vk) ?></option><?php endforeach ?>
        </select>
        <label class="sr-only" for="mdl-se">Inserir condição</label>
        <select id="mdl-se" data-ins-se title="Trecho que só entra quando a condição vale">
          <option value="">@se Condição…</option>
          <?php foreach ($conds as $vk => $vl): ?><option value="<?= e($vk) ?>"><?= e(preg_replace('/^Condição:\s*/u', '', $vl)) ?></option><?php endforeach ?>
        </select>
        <button type="button" data-wrap="{{flex:|}}" data-exemplo="inscrito/inscrita" title="Palavra que concorda com o gênero do cliente">Gênero</button>
      </div>

      <label class="fld"><span class="sr-only">Texto do modelo</span>
        <textarea name="body" rows="28" class="at-doc-edit mdl-body" spellcheck="true" data-mdl-body><?= e($body) ?></textarea>
      </label>
      <div class="inline-form mdl-acoes">
        <button class="b b-primary" type="submit">Salvar nova versão</button>
        <span class="hint" data-mdl-estado aria-live="polite"><?= $rascunho !== null ? 'Texto não salvo recuperado: corrija e salve.' : '' ?></span>
      </div>
    </form>
    <?php if (!$t['approved_at']): ?>
      <form method="post" action="<?= e(url('painel/modelos-documentos')) ?>" class="inline-form mdl-aprovar"><?= csrf_field() ?><input type="hidden" name="key" value="<?= e($key) ?>"><input type="hidden" name="op" value="aprovar"><?php if ($case): ?><input type="hidden" name="caso" value="<?= (int) $case['id'] ?>"><?php endif ?><button class="b" type="submit">Revisei: aprovar a versão <?= (int) $t['version'] ?></button></form>
    <?php endif ?>

    <details class="mdl-ajuda">
      <summary>Como escrever e diagramar</summary>
      <p class="small">Cada bloco é separado por uma linha em branco; o começo do bloco diz o que ele é. Use a barra acima para inserir sem decorar.</p>
      <ul class="small at-vars">
        <li><code># Título | subtítulo</code> · <code>## I. Seção</code> · <code>### Cláusula # — título</code> · <code>#### Rótulo</code></li>
        <li><code>**Parágrafo #.**</code> numera os parágrafos dentro da cláusula; um só vira “Parágrafo único”</li>
        <li><code>@qualificacao</code> · <code>@dados</code> · <code>@destaque</code> · <code>@observacao</code> · <code>@referencia</code> · <code>@centro</code> · <code>@direita</code> (local e data)</li>
        <li><code>@assinatura Nome | linha | linha</code> · <code>@quebra</code> (página nova) · <code>@compacto</code> (uma folha) · <code>- item</code> (lista)</li>
        <li><code>**negrito**</code> · <code>^^versalete^^</code> · <code>{|i u m f=poppins t=14|}trecho{|/|}</code> (itálico, sublinhado, marca-texto, fonte e tamanho)</li>
        <li><code>{{variável}}</code> ou <code>{{variável|texto padrão}}</code> · <code>{{flex:inscrito/inscrita}}</code> · <code>{{clausula:chave}}</code> com <code>{#chave}</code> na cláusula</li>
        <li><code>@se condição</code> … <code>@senao</code> … <code>@fimse</code> · <code>[[COMPLETAR: …]]</code> bloqueia a publicação até ser escrito</li>
      </ul>
    </details>
  </section>

  <section class="box mdl-previa">
    <form method="get" action="<?= e(url('painel/modelos-documentos')) ?>" class="mdl-caso" data-mdl-caso>
      <input type="hidden" name="modelo" value="<?= e($key) ?>">
      <label class="fld"><span>Simular com o atendimento</span>
        <select name="caso">
          <option value="">Nenhum: ver o modelo com o que falta marcado</option>
          <?php foreach ($cases as $cs): ?><option value="<?= (int) $cs['id'] ?>" <?= selected((string) $cs['id'], (string) ($case['id'] ?? '')) ?>><?= e($cs['client_name']) ?> · <?= e(client_case_title($cs)) ?> · <?= e($cs['protocol']) ?></option><?php endforeach ?>
        </select>
      </label>
      <noscript><button class="b b-sm" type="submit">Simular</button></noscript>
    </form>

    <form method="post" action="<?= e(url('painel/modelos-documentos/' . $key . '/simular')) ?>" target="mdl-frame" id="mdl-sim" data-mdl-sim>
      <?= csrf_field() ?>
      <input type="hidden" name="caso" value="<?= (int) ($case['id'] ?? 0) ?>">
      <input type="hidden" name="zoom" value="0.75" data-mdl-zoom>
      <textarea name="body" hidden data-mdl-sim-body><?= e($body) ?></textarea>
      <details class="mdl-dados" <?= $case ? 'open' : '' ?>>
        <summary>Dados da simulação <span class="hint">(<?= count($simVars) ?> usados neste modelo)</span></summary>
        <p class="hint"><?= $case ? 'Vêm do atendimento escolhido.' : 'Sem atendimento, os dados aparecem como pendência.' ?> Altere aqui para ver como o documento fica: vale só para esta simulação, não muda a ficha.</p>
        <div class="mdl-vars">
          <?php foreach ($simVars as $vk => $sv): ?>
            <label class="fld"><span><?= e($sv['label']) ?> <small class="mono"><?= e($vk) ?></small></span>
              <input name="v[<?= e($vk) ?>]" placeholder="<?= e($sv['value'] ?? ($case ? 'sem dado no atendimento' : '')) ?>" data-mdl-v>
            </label>
          <?php endforeach ?>
          <?php if (!$simVars): ?><p class="hint">Este modelo ainda não usa variáveis.</p><?php endif ?>
        </div>
      </details>
      <noscript><button class="b b-sm" type="submit">Atualizar a prévia</button></noscript>
    </form>

    <div class="mdl-frame-wrap">
      <div class="mdl-zoom" role="group" aria-label="Zoom da prévia" data-mdl-zoom-btns hidden>
        <button type="button" class="b b-sm" data-zoom="-0.1" aria-label="Diminuir">−</button>
        <span data-zoom-label>75%</span>
        <button type="button" class="b b-sm" data-zoom="0.1" aria-label="Aumentar">+</button>
        <a class="b b-sm" href="<?= e(url('painel/modelos-documentos/' . $key . '/previa')) ?>" target="_blank" rel="noopener">Abrir o modelo salvo</a>
      </div>
      <iframe name="mdl-frame" class="mdl-frame" title="Prévia do documento no timbrado" src="<?= e(url('painel/modelos-documentos/' . $key . '/previa')) ?>" data-mdl-frame></iframe>
    </div>
  </section>
</div>
<script src="<?= e(asset('js/modelos.js')) ?>" defer></script>
<?php else: ?>
  <p class="q-empty">Nenhum modelo cadastrado.</p>
<?php endif ?>
