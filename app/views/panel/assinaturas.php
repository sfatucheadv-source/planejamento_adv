<?php
/** Pedidos de assinatura do escritório: o que espera você, os em andamento e os assinantes padrão. */
$editor = has_role('supervisor') || is_operator($u);
$opts = function (?int $sel) use ($users): string {
    $h = '<option value="0">— escolher —</option>';
    foreach ($users as $x) {
        $h .= '<option value="' . (int) $x['id'] . '"' . ((int) $sel === (int) $x['id'] ? ' selected' : '') . '>' . e(team_profile($x)['nome_completo']) . '</option>';
    }
    return $h;
};
?>
<div class="page-head"><div><h1>Assinaturas</h1><p class="sub">Testemunhas aprovam aqui, com o próprio usuário; depois o advogado assina com o certificado digital; por fim, o documento vai para o cliente.</p></div></div>

<section class="box">
  <h2>Para você assinar</h2>
  <?php if (!$mine): ?>
    <p class="hint">Nada esperando a sua assinatura.</p>
  <?php else: ?>
    <ul class="sig-lista">
      <?php foreach ($mine as $m): ?>
        <li><a class="b b-primary b-sm" href="<?= e(url('painel/assinaturas/' . $m['id'])) ?>"><?= $m['role'] === 'advogado' ? 'Assinar com certificado' : 'Ver e assinar' ?></a>
          <strong><?= e(kit_label($m['kind'])) ?></strong> v<?= (int) $m['version'] ?> · <?= e($m['client_name']) ?> · <?= e($m['protocol']) ?> · <?= e(SIG_PARTY_LABELS[$m['role']]) ?><?= $m['role'] === 'testemunha' ? ' ' . (int) $m['ord'] : '' ?></li>
      <?php endforeach ?>
    </ul>
  <?php endif ?>
  <?php $miss = team_profile_missing($u); if ($miss || !team_signature($u)): ?>
    <p class="warn-line">Para assinar, complete <a href="<?= e(url('painel/conta')) ?>">Minha conta</a>: <?= e(implode(', ', array_merge($miss, team_signature($u) ? [] : ['rubrica']))) ?>.</p>
  <?php endif ?>
</section>

<section class="box">
  <h2>Em andamento</h2>
  <?php if (!$open): ?><p class="hint">Nenhum pedido ainda. Eles são criados quando o contrato é enviado ao cliente.</p><?php endif ?>
  <?php foreach ($open as $r): ?>
    <div class="sig-pedido">
      <p><strong><?= e(kit_label($r['kind'])) ?></strong> v<?= (int) $r['version'] ?> · <a href="<?= e(url('painel/atendimento/' . $r['case_id'])) ?>#kit"><?= e($r['client_name']) ?> · <?= e($r['protocol']) ?></a>
        · <span class="tag <?= $r['status'] === 'concluido' ? 'tag-green' : 'tag-amber' ?>"><?= e(SIG_REQUEST_STATES[$r['status']] ?? $r['status']) ?></span> · código <a href="<?= e(url('verificar/' . $r['code'])) ?>" target="_blank" rel="noopener"><?= e($r['code']) ?></a></p>
      <ul class="sig-partes">
        <?php foreach (sig_parties((int) $r['id']) as $p): $pu = $p['user_id'] ? one('SELECT * FROM users WHERE id = ?', [$p['user_id']]) : null; ?>
          <li class="<?= $p['signed_at'] ? 'is-ok' : '' ?>">
            <?= e(SIG_PARTY_LABELS[$p['role']]) ?><?= $p['role'] === 'testemunha' ? ' ' . (int) $p['ord'] : '' ?>:
            <?php if ($p['signed_at']): ?><strong><?= e($p['name']) ?></strong> · assinou em <?= e(fmt_datetime($p['signed_at'])) ?><?= $p['method'] !== 'aprovacao_no_sistema' ? ' · certificado digital' : '' ?>
            <?php else: ?>
              <strong><?= $pu ? e(team_profile($pu)['nome_completo']) : 'ninguém escolhido' ?></strong> · aguardando
              <?php if ($editor && $r['status'] !== 'concluido'): ?>
                <form class="inline" method="post" action="<?= e(url('painel/assinaturas/' . $p['id'] . '/pessoa')) ?>"><?= csrf_field() ?><select name="user_id" aria-label="Quem assina"><?= $opts($p['user_id'] ? (int) $p['user_id'] : null) ?></select><button class="b b-sm" type="submit">Trocar</button></form>
              <?php endif ?>
            <?php endif ?>
          </li>
        <?php endforeach ?>
        <li class="<?= $r['status'] === 'concluido' ? 'is-ok' : '' ?>">Cliente: <strong><?= e($r['client_name']) ?></strong> · <?= $r['status'] === 'concluido' ? 'assinou' : ($r['status'] === 'cliente' ? 'com o documento para assinar' : 'assina por último') ?></li>
      </ul>
    </div>
  <?php endforeach ?>
</section>

<?php if ($editor): ?>
<section class="box">
  <h2>Assinantes padrão</h2>
  <p class="hint">Usados nos próximos documentos enviados ao cliente. Em cada documento dá para trocar enquanto a pessoa não assinou.</p>
  <form method="post" action="<?= e(url('painel/assinaturas/padrao')) ?>" class="stack">
    <?= csrf_field() ?>
    <div class="row3">
      <label class="fld"><span>Testemunha 1</span><select name="testemunha1"><?= $opts($def['testemunhas'][0] ?? null) ?></select></label>
      <label class="fld"><span>Testemunha 2</span><select name="testemunha2"><?= $opts($def['testemunhas'][1] ?? null) ?></select></label>
      <label class="fld"><span>Advogado (certificado digital)</span><select name="advogado"><?= $opts($def['advogado'] ?: null) ?></select></label>
    </div>
    <button class="b" type="submit">Salvar</button>
    <p class="hint">Cadastre as pessoas da equipe em <a href="<?= e(url('painel/equipe')) ?>">Equipe</a>. Cada uma completa os próprios dados e a rubrica em Minha conta.</p>
  </form>
</section>
<?php endif ?>
