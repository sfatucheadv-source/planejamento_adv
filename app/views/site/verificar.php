<?php
/** Conferência pública de uma via assinada eletronicamente (pelo código impresso nela). */
$mask = function (string $n): string {
    $p = preg_split('/\s+/u', trim($n));
    return implode(' ', array_map(fn($w, $i) => $i === 0 || $i === count($p) - 1 ? $w : mb_substr($w, 0, 1) . '.', $p, array_keys($p)));
};
?>
<section class="page-hero">
  <div class="wrap narrow">
    <p class="kicker">Assinatura eletrônica</p>
    <h1>Conferir documento assinado</h1>
    <p class="lead">Confira se a via que você tem em mãos corresponde ao documento assinado no sistema do escritório.</p>
  </div>
</section>
<section class="section">
  <div class="wrap narrow prose">
    <?php if (!$sig): ?>
      <p><strong>Código não encontrado.</strong> Confira se digitou o código exatamente como aparece na folha “Registro de assinatura eletrônica”.</p>
    <?php else: ?>
      <p class="<?= $intact ? 'verif-ok' : 'verif-erro' ?>"><strong><?= $intact ? 'Assinatura válida.' : 'Atenção: o texto do documento no sistema não confere com o assinado.' ?></strong></p>
      <ul>
        <li><strong>Documento:</strong> <?= e(kit_label($sig['kind'])) ?>, versão <?= (int) $sig['version'] ?> · atendimento <?= e($sig['protocol']) ?></li>
        <li><strong>Assinado por:</strong> <?= e($mask((string) $sig['signer_name'])) ?></li>
        <li><strong>Data e hora:</strong> <?= e(fmt_datetime((string) $sig['signed_at'])) ?> (horário de Brasília)</li>
        <li><strong>Código:</strong> <?= e($sig['code']) ?></li>
        <li><strong>Hash SHA-256 do texto assinado:</strong> <code style="word-break:break-all"><?= e($sig['doc_hash']) ?></code></li>
        <?php if ($sig['file_hash']): ?><li><strong>Hash SHA-256 da via assinada (arquivo):</strong> <code style="word-break:break-all"><?= e($sig['file_hash']) ?></code></li><?php endif ?>
      </ul>
      <p class="muted">A autoria foi confirmada pela conta do assinante na área do cliente, pelo nome e CPF e por um código enviado ao e-mail do cadastro. Os demais registros (IP, aparelho e localização) ficam com o escritório.</p>
    <?php endif ?>
  </div>
</section>
