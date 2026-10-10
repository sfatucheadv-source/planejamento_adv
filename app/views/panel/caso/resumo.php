<?php
$checks = case_checks((int) $c['id'], $c['stage']);
$problems = gate_problems($c);
$blocking = array_filter($problems, fn($p) => $p['block']);
$earlier = array_filter(stages(), fn($st) => $st['n'] < $s['n']);
?>
<div class="grid-main-side">
  <div>
    <section class="box">
      <div class="box-head">
        <h2>Etapa <?= $s['n'] ?> · <?= e($s['title']) ?></h2>
        <span class="tag tag-blue"><?= e(implode(' · ', array_map(fn($r) => ROLES[$r], $s['owner']))) ?></span>
      </div>
      <dl class="mini-dl">
        <div><dt>Entrada</dt><dd><?= e($s['entry']) ?></dd></div>
        <div><dt>Meta</dt><dd><?= e($s['goal']) ?></dd></div>
        <div><dt>Entrega</dt><dd><?= e($s['deliverable']) ?></dd></div>
      </dl>

      <h3>Passos</h3>
      <div class="cklist">
        <?php foreach ($s['steps'] as $k => $label): ?><?= check_item($c, $c['stage'], $k, $label, $checks[$k] ?? null, 'resumo') ?><?php endforeach ?>
      </div>

      <?php if ($s['gates']): ?>
        <h3>Condição para avançar</h3>
        <div class="cklist">
          <?php foreach ($s['gates'] as $k => $g): ?><?= check_item($c, $c['stage'], $k, $g['label'], $checks[$k] ?? null, 'resumo', $g['block']) ?><?php endforeach ?>
        </div>
      <?php endif ?>

      <?php if (!empty($s['message'])): ?>
        <p class="hint"><strong>Mensagem sugerida:</strong> “<?= e($s['message']) ?>”</p>
      <?php endif ?>
    </section>

    <?php if ($c['status'] === 'ativo' && $s['next']): ?>
    <section class="box">
      <h2>Mover o caso</h2>
      <?php if ($problems): ?>
        <ul class="problems">
          <?php foreach ($problems as $p): ?><li class="<?= $p['block'] ? 'blk' : '' ?>"><?= $p['block'] ? 'Bloqueia o avanço: ' : 'Recomendado: ' ?><?= e($p['label']) ?></li><?php endforeach ?>
        </ul>
      <?php else: ?>
        <p class="ok-line">Condições desta etapa cumpridas.</p>
      <?php endif ?>
      <?= case_form($c, 'etapa', [], 'inline-form') ?>
        <label class="fld"><span>Próxima etapa</span>
          <select name="to"><?php foreach ($s['next'] as $nk): ?><option value="<?= e($nk) ?>"><?= stage($nk)['n'] ?>. <?= e(stage_label($nk)) ?></option><?php endforeach ?></select>
        </label>
        <label class="fld grow"><span>Motivo / observação <small>(obrigatório ao voltar etapa)</small></span><input name="reason" placeholder="Ex.: oferta aceita pelo cliente em 12/10"></label>
        <button class="b b-primary" type="submit"<?= $blocking ? ' title="Há condições obrigatórias pendentes"' : '' ?>>Mover</button>
      </form>
      <?php if ($earlier): ?>
        <details class="more">
          <summary>Voltar a uma etapa anterior (documento faltante, divergência, reabertura)</summary>
          <?= case_form($c, 'etapa', [], 'inline-form') ?>
            <label class="fld"><span>Etapa</span><select name="to"><?php foreach ($earlier as $ek => $es): ?><option value="<?= e($ek) ?>"><?= $es['n'] ?>. <?= e($es['label']) ?></option><?php endforeach ?></select></label>
            <label class="fld grow"><span>Motivo e próxima ação</span><input name="reason" required></label>
            <button class="b" type="submit">Voltar</button>
          </form>
        </details>
      <?php endif ?>
    </section>
    <?php endif ?>

    <?php if ($c['status'] !== 'ativo'): ?>
    <section class="box">
      <h2>Reabrir o caso</h2>
      <p class="hint">Use quando houver falha relacionada à entrega (ex.: novo boleto volta ao valor antigo). A reabertura entra no indicador de reaberturas.</p>
      <?= case_form($c, 'reabrir', [], 'inline-form') ?>
        <label class="fld"><span>Voltar para</span><select name="to"><?php foreach (['conferencia', 'acordo', 'apuracao', 'negociacao'] as $rk): ?><option value="<?= $rk ?>"><?= e(stage_label($rk)) ?></option><?php endforeach ?></select></label>
        <label class="fld grow"><span>Motivo</span><input name="reason" required></label>
        <button class="b">Reabrir</button>
      </form>
    </section>
    <?php endif ?>
  </div>

  <aside>
    <section class="box">
      <h2>Cartão de acompanhamento</h2>
      <p class="hint">É isso que o cliente vê na área do cliente. Todo caso ativo precisa de próxima ação com data e responsável.</p>
      <?= case_form($c, 'proxima_acao', [], 'stack') ?>
        <label class="fld"><span>Próximo passo da equipe</span><input name="next_action" value="<?= e($c['next_action']) ?>" maxlength="240"></label>
        <div class="row2">
          <label class="fld"><span>Prazo interno</span><input type="datetime-local" name="next_action_due" value="<?= e(dt_local($c['next_action_due'])) ?>"></label>
          <label class="fld"><span>Responsável</span><?= user_select('next_action_owner_id', $c['next_action_owner_id'] ? (int) $c['next_action_owner_id'] : null, $users) ?></label>
        </div>
        <label class="fld"><span>Ação do cliente <small>(ou deixe vazio = nenhuma)</small></span><input name="client_next_action" value="<?= e($c['client_next_action']) ?>" maxlength="240"></label>
        <label class="fld"><span>Próximo retorno ao cliente</span><input type="datetime-local" name="next_client_update" value="<?= e(dt_local($c['next_client_update'])) ?>"></label>
        <label class="fld"><span>Atenção imediata <small>(só com fato concreto)</small></span><input name="client_alert" value="<?= e(case_data($c, 'client_alert')) ?>"></label>
        <button class="b b-primary" type="submit">Salvar cartão</button>
      </form>
      <p class="hint">Último retorno ao cliente: <?= $c['last_client_update'] ? e(relative($c['last_client_update'])) : 'ainda não houve' ?>. Ciclo máximo sem notícia: <?= (int) config('update_cycle_days') ?> dias.</p>
    </section>

    <section class="box">
      <h2>Responsáveis</h2>
      <?= case_form($c, 'equipe', [], 'stack') ?>
        <label class="fld"><span>Gestor do caso</span><?= user_select('gestor_id', $c['gestor_id'] ? (int) $c['gestor_id'] : null, $users) ?></label>
        <label class="fld"><span>Advogado responsável</span><?= user_select('advogado_id', $c['advogado_id'] ? (int) $c['advogado_id'] : null, $users) ?></label>
        <label class="fld"><span>Substituto</span><?= user_select('substituto_id', $c['substituto_id'] ? (int) $c['substituto_id'] : null, $users) ?></label>
        <button class="b" type="submit">Salvar</button>
      </form>
    </section>

    <section class="box">
      <h2>Urgência</h2>
      <p class="hint">Urgência é um marcador transversal, visível em qualquer etapa.</p>
      <?= case_form($c, 'marcar_urgente', ['urgent' => $c['urgent'] ? '0' : '1'], 'stack') ?>
        <label class="fld"><span>Motivo</span><input name="reason" placeholder="Ex.: cliente recebeu intimação hoje"></label>
        <button class="b <?= $c['urgent'] ? '' : 'b-danger' ?>" type="submit"><?= $c['urgent'] ? 'Remover marcação de urgência' : 'Marcar como urgente' ?></button>
      </form>
    </section>

    <section class="box">
      <h2>Nota interna</h2>
      <?= case_form($c, 'nota', ['back' => 'resumo'], 'stack') ?>
        <textarea name="text" rows="3" placeholder="Registre algo que o próximo colega precisa saber antes de falar com o cliente."></textarea>
        <button class="b" type="submit">Registrar</button>
      </form>
    </section>
  </aside>
</div>
