<?php
/** Minha conta: senha, qualificação (art. 319 do CPC), rubrica mecânica e certificado digital. */
$u = current_user();
$p = team_profile($u);
$miss = team_profile_missing($u);
$sig = team_signature($u);
$cert = json_decode((string) ($u['cert_info'] ?? ''), true) ?: null;
$f = fn(string $k, string $type = 'text', string $extra = '') => '<label class="fld"><span>' . e(TEAM_PROFILE_FIELDS[$k]) . (in_array($k, TEAM_PROFILE_REQUIRED, true) ? '' : ' <small>(opcional)</small>') . '</span><input type="' . $type . '" name="' . $k . '" value="' . e((string) ($p[$k] ?? '')) . '"' . $extra . '></label>';
?>
<div class="page-head"><div><h1>Minha conta</h1><p class="sub">Seus dados entram nas assinaturas que você faz pelo escritório (como testemunha ou advogado).</p></div></div>

<section class="box narrow-box" id="dados">
  <h2>Qualificação</h2>
  <?php if ($miss): ?><p class="warn-line">Falta para assinar: <?= e(implode(', ', $miss)) ?>.</p><?php else: ?><p class="ok-line"><?= e(team_qualification($u)) ?></p><?php endif ?>
  <form method="post" action="<?= e(url('painel/conta/dados')) ?>" class="stack">
    <?= csrf_field() ?>
    <?= $f('nome_completo', 'text', ' required autocomplete="name"') ?>
    <div class="row3">
      <?= $f('nacionalidade') ?>
      <label class="fld"><span>Estado civil</span><select name="estado_civil"><option value="">—</option><?php foreach (TEAM_ESTADOS_CIVIS as $ec): ?><option <?= ($p['estado_civil'] ?? '') === $ec ? 'selected' : '' ?>><?= e($ec) ?></option><?php endforeach ?></select></label>
      <label class="fld"><span>União estável</span><select name="uniao_estavel"><option value="nao">não</option><option value="sim" <?= ($p['uniao_estavel'] ?? '') === 'sim' ? 'selected' : '' ?>>sim</option></select></label>
    </div>
    <div class="row3"><?= $f('profissao') ?><?= $f('cpf', 'text', ' inputmode="numeric"') ?><?= $f('rg') ?></div>
    <div class="row3"><?= $f('email', 'email') ?><?= $f('telefone', 'tel') ?><?= $f('oab') ?></div>
    <div class="row3"><?= $f('cep', 'text', ' inputmode="numeric"') ?><?= $f('logradouro') ?><?= $f('numero') ?></div>
    <div class="row3"><?= $f('complemento') ?><?= $f('bairro') ?><?= $f('cidade') ?></div>
    <div class="row3"><?= $f('uf', 'text', ' maxlength="2"') ?></div>
    <button class="b b-primary" type="submit">Salvar dados</button>
  </form>
</section>

<section class="box narrow-box" id="rubrica">
  <h2>Rubrica</h2>
  <p class="hint">Desenhada uma vez e reaproveitada em todos os seus selos de assinatura. Você pode trocá-la quando quiser; selos já assinados não mudam.</p>
  <?php if ($sig): ?>
    <p class="small">Rubrica atual<?= $u['signature_at'] ? ' (desde ' . e(fmt_datetime((string) $u['signature_at'])) . ')' : '' ?>:</p>
    <div class="rubrica-atual"><svg viewBox="0 0 <?= SEAL_INK['w'] + 4 ?> <?= SEAL_INK['h'] + 4 ?>"><?= pdoc_svg(sig_strokes_fit($sig, ['x' => 2, 'y' => 2, 'w' => SEAL_INK['w'], 'h' => SEAL_INK['h']])) ?></svg></div>
  <?php endif ?>
  <form method="post" action="<?= e(url('painel/conta/rubrica')) ?>" data-rubrica-form>
    <?= csrf_field() ?><input type="hidden" name="tracos" data-rubrica-tracos>
    <canvas class="assin-pad rubrica-pad" width="600" height="180" data-rubrica-pad aria-label="Quadro para desenhar a rubrica"></canvas>
    <p class="row-acoes"><button class="b b-ghost b-sm" type="button" data-rubrica-limpar>Limpar</button> <button class="b b-primary" type="submit"><?= $sig ? 'Trocar a rubrica' : 'Salvar a rubrica' ?></button></p>
  </form>
</section>

<section class="box narrow-box" id="certificado">
  <h2>Certificado digital (advogado)</h2>
  <?php if ($cert): ?>
    <p class="ok-line"><strong><?= e($cert['titular']) ?></strong><?= $cert['cpf'] ? ' · CPF ' . e(sig_fmt_cpf($cert['cpf'])) : '' ?> · <?= e($cert['emissor']) ?> · válido até <?= e(fmt_date((string) $cert['valido_ate'])) ?></p>
    <form method="post" action="<?= e(url('painel/conta/certificado/remover')) ?>"><?= csrf_field() ?><button class="b b-ghost b-sm" type="submit">Remover do sistema</button></form>
  <?php endif ?>
  <p class="hint">Certificado A1 (arquivo .pfx ou .p12): fica guardado no servidor, protegido pela própria senha, que <strong>não é guardada</strong> — você a digita a cada assinatura. Com certificado A3 (token ou cartão), não é preciso cadastrar: na hora de assinar, baixe o PDF, assine no seu assinador e envie de volta.</p>
  <form method="post" enctype="multipart/form-data" action="<?= e(url('painel/conta/certificado')) ?>" class="stack">
    <?= csrf_field() ?>
    <div class="row2">
      <label class="fld"><span>Arquivo do certificado A1</span><input type="file" name="certificado" accept=".pfx,.p12,application/x-pkcs12" required></label>
      <label class="fld"><span>Senha (só para conferir agora)</span><input type="password" name="senha" autocomplete="off" required></label>
    </div>
    <button class="b" type="submit"><?= $cert ? 'Trocar o certificado' : 'Cadastrar o certificado' ?></button>
  </form>
</section>

<section class="box narrow-box">
  <h2>Senha de acesso</h2>
  <form method="post" action="<?= e(url('painel/conta')) ?>" class="stack">
    <?= csrf_field() ?>
    <label class="fld"><span>Senha atual</span><input type="password" name="current" autocomplete="current-password" required></label>
    <label class="fld"><span>Nova senha (mínimo 10 caracteres)</span><input type="password" name="password" autocomplete="new-password" minlength="10" required></label>
    <button class="b b-primary" type="submit">Alterar senha</button>
  </form>
</section>
<script src="<?= e(asset('js/equipe.js')) ?>" defer></script>
