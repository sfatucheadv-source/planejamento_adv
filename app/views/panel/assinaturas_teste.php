<?php
/**
 * Teste da assinatura conjunta: gera um contrato de teste com os dados de um
 * cliente e percorre o fluxo inteiro (testemunhas → advogado → cliente), cada
 * pessoa na própria tela. O contrato fica fora do kit e nada vai ao cliente.
 */
$opts = function (?int $sel) use ($users): string {
    $h = '<option value="0">— escolher —</option>';
    foreach ($users as $x) {
        $h .= '<option value="' . (int) $x['id'] . '"' . ((int) $sel === (int) $x['id'] ? ' selected' : '') . '>' . e(team_profile($x)['nome_completo']) . '</option>';
    }
    return $h;
};
$methodTag = fn(string $m) => '<span class="tag ' . ($m === 'aprovacao' ? 'tag-gray' : 'tag-blue') . '">' . e(TEAM_SIGN_METHODS[$m]) . '</span>';
?>
<div class="page-head"><div><h1>Testar a assinatura conjunta</h1><p class="sub">Gera um contrato de teste com os dados do cliente escolhido e percorre o fluxo inteiro: testemunhas, advogado e, por último, o cliente (simulado por você).</p></div>
  <a class="b b-ghost" href="<?= e(url('painel/assinaturas')) ?>">Voltar para Assinaturas</a></div>

<section class="box">
  <h2>Novo teste</h2>
  <p class="hint">O contrato sai do modelo atual com os dados do atendimento, marcado <strong>DOCUMENTO DE TESTE · SEM VALOR JURÍDICO</strong> em todas as páginas. Ele não entra no kit do atendimento, não aparece para o cliente e não muda a contratação.</p>
  <form method="post" action="<?= e(url('painel/assinaturas/teste')) ?>" class="stack">
    <?= csrf_field() ?>
    <label class="fld"><span>Cliente (atendimento)</span>
      <select name="case_id" required>
        <option value="">— escolher —</option>
        <?php foreach ($cases as $cs): ?><option value="<?= (int) $cs['id'] ?>"><?= e($cs['client_name']) ?> · <?= e($cs['protocol']) ?></option><?php endforeach ?>
      </select></label>
    <div class="row3">
      <label class="fld"><span>Testemunha 1</span><select name="testemunha1"><?= $opts($def['testemunhas'][0] ?? null) ?></select></label>
      <label class="fld"><span>Testemunha 2</span><select name="testemunha2"><?= $opts($def['testemunhas'][1] ?? null) ?></select></label>
      <label class="fld"><span>Advogado (pelo escritório)</span><select name="advogado"><?= $opts($def['advogado'] ?: null) ?></select></label>
    </div>
    <button class="b b-primary" type="submit">Gerar contrato de teste</button>
  </form>
</section>

<section class="box">
  <h2>Como cada pessoa assina</h2>
  <p class="hint">Com certificado digital cadastrado, a pessoa assina com ele (A1: senha do certificado; A3: token). Sem certificado, assina pela aprovação no painel: abre o documento na própria tela, confere e aprova. Os dados e a rubrica ficam em Minha conta de cada um.</p>
  <div class="tbl-wrap"><table class="tbl">
    <thead><tr><th>Pessoa</th><th>Forma de assinar</th><th>Pronta para assinar?</th></tr></thead>
    <tbody>
      <?php foreach ($users as $x): $rd = sig_user_readiness($x); ?>
        <tr><td><?= e(team_profile($x)['nome_completo']) ?></td><td><?= $methodTag($rd['metodo']) ?></td>
          <td><?= $rd['falta'] ? '<span class="tag tag-amber">falta: ' . e(implode(', ', $rd['falta'])) . '</span>' : '<span class="tag tag-green">sim</span>' ?></td></tr>
      <?php endforeach ?>
    </tbody>
  </table></div>
</section>

<section class="box">
  <h2>Testes</h2>
  <?php if (!$tests): ?><p class="hint">Nenhum teste ainda.</p><?php endif ?>
  <?php foreach ($tests as $r): $missing = json_decode((string) $r['missing'], true) ?: []; $vt = sig_vtxt(['status' => 'teste', 'version' => $r['version']]); ?>
    <div class="sig-pedido" id="teste-<?= (int) $r['id'] ?>">
      <p><strong><?= e(kit_label($r['kind'])) ?></strong> · <?= e($vt) ?> · <?= e($r['client_name']) ?> · <?= e($r['protocol']) ?>
        · <span class="tag <?= $r['status'] === 'concluido' ? 'tag-green' : 'tag-amber' ?>"><?= e($r['status'] === 'concluido' ? 'Concluído: todos assinaram' : (SIG_REQUEST_STATES[$r['status']] ?? $r['status'])) ?></span>
        · código <a href="<?= e(url('verificar/' . $r['code'])) ?>" target="_blank" rel="noopener"><?= e($r['code']) ?></a> · criado em <?= e(fmt_datetime($r['created_at'])) ?></p>
      <?php if ($missing): ?><p class="warn-line">Dados que faltam no atendimento (marcados no texto): <?= e(implode('; ', $missing)) ?>.</p><?php endif ?>
      <ol class="sig-partes">
        <?php foreach (sig_parties((int) $r['id']) as $p): $pu = $p['user_id'] ? one('SELECT * FROM users WHERE id = ?', [$p['user_id']]) : null; $mine = $pu && (int) $pu['id'] === (int) $u['id']; ?>
          <li class="<?= $p['signed_at'] ? 'is-ok' : '' ?>">
            <?= e(SIG_PARTY_LABELS[$p['role']]) ?><?= $p['role'] === 'testemunha' ? ' ' . (int) $p['ord'] : '' ?>:
            <?php if ($p['signed_at']): ?>
              <strong><?= e($p['name']) ?></strong> · assinou em <?= e(fmt_datetime($p['signed_at'])) ?> · <?= e(['aprovacao' => 'aprovação no painel', 'senha' => 'senha', 'certificado_a1' => 'certificado A1', 'certificado_a3' => 'certificado A3 (token)'][$p['method']] ?? $p['method']) ?>
            <?php else: ?>
              <strong><?= $pu ? e(team_profile($pu)['nome_completo']) : 'ninguém escolhido' ?></strong>
              <?php if ($pu): $rd = sig_user_readiness($pu); ?> · <?= $methodTag($rd['metodo']) ?><?= $rd['falta'] ? ' <span class="tag tag-amber">falta: ' . e(implode(', ', $rd['falta'])) . '</span>' : '' ?><?php endif ?>
              · <?= ($p['role'] === 'testemunha' && $r['status'] === 'testemunhas') || ($p['role'] === 'advogado' && $r['status'] === 'advogado') ? 'pode assinar agora' : 'aguardando a vez' ?>
              <?php if ($pu): ?> · <a href="<?= e(url('painel/assinaturas/' . $p['id'])) ?>"><?= $mine ? 'Abrir e assinar' : 'Ver a tela' ?></a><?php endif ?>
              <?php if ($r['status'] !== 'concluido' && !($p['role'] === 'testemunha' && $r['signed_path'])): ?>
                <form class="inline" method="post" action="<?= e(url('painel/assinaturas/' . $p['id'] . '/pessoa')) ?>"><?= csrf_field() ?><select name="user_id" aria-label="Quem assina"><?= $opts($p['user_id'] ? (int) $p['user_id'] : null) ?></select><button class="b b-sm" type="submit">Trocar</button></form>
              <?php endif ?>
            <?php endif ?>
          </li>
        <?php endforeach ?>
        <li class="<?= $r['status'] === 'concluido' ? 'is-ok' : '' ?>">Cliente (simulado por você): <strong><?= e(sig_expected_name(find_case((int) $r['case_id']))) ?></strong> ·
          <?php if ($r['status'] === 'concluido'): ?>assinou · <a href="<?= e(url('painel/assinaturas/teste/' . $r['id'] . '/cliente')) ?>">Ver a via assinada</a>
          <?php elseif ($r['status'] === 'cliente'): ?><a class="b b-primary b-sm" href="<?= e(url('painel/assinaturas/teste/' . $r['id'] . '/cliente')) ?>">Assinar como o cliente</a>
          <?php else: ?>assina por último<?php endif ?></li>
      </ol>
      <p class="sig-acoes">
        <?php if ($r['signed_path']): ?><a class="b b-sm" href="<?= e(url('painel/assinaturas/teste/' . $r['id'] . '/pdf')) ?>" target="_blank" rel="noopener"><?= $r['status'] === 'concluido' ? 'Abrir o PDF final' : 'Abrir o PDF atual' ?></a>
          <a class="b b-ghost b-sm" href="<?= e(url('painel/assinaturas/teste/' . $r['id'] . '/pdf', ['baixar' => '1'])) ?>">Baixar</a><?php endif ?>
        <a class="b b-ghost b-sm" href="<?= e(url('verificar/' . $r['code'])) ?>" target="_blank" rel="noopener">Página de verificação</a>
      </p>
    </div>
  <?php endforeach ?>
</section>
