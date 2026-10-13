<?php
/**
 * Conferência pública de uma via assinada eletronicamente. O link do selo, da
 * faixa lateral e do QR Code já traz o código: o registro abre direto. Abrir o
 * registro confirma que ele existe; a integridade da cópia que a pessoa tem só
 * é confirmada comparando o hash dela com o da via original.
 */
$mask = function (string $n): string {
    $p = preg_split('/\s+/u', trim($n));
    return implode(' ', array_map(fn($w, $i) => $i === 0 || $i === count($p) - 1 ? $w : mb_substr($w, 0, 1) . '.', $p, array_keys($p)));
};
$pdf = $sig && $sig['pdf_sha256'];
$ref = $sig ? (string) ($sig['pdf_sha256'] ?: $sig['file_hash']) : '';
$ev = $sig ? (json_decode((string) $sig['evidence'], true) ?: []) : [];
$fuso = $ev['momento']['fuso'] ?? '-03:00';
$events = ['assinatura_registrada' => 'Assinatura registrada e via em PDF guardada', 'via_enviada_email' => 'Via assinada enviada ao e-mail do assinante',
    'via_nao_enviada_email' => 'Tentativa de envio da via por e-mail sem sucesso', 'registro_legado' => 'Registro anterior à via em PDF: hashes do texto e do arquivo passam a ser exibidos separadamente',
    'divergencia_arquivo' => 'Divergência detectada no arquivo guardado'];
?>
<section class="page-hero">
  <div class="wrap narrow">
    <p class="kicker">Assinatura eletrônica · Sidney Fatuche Advocacia</p>
    <h1>Conferir documento assinado</h1>
    <p class="lead">Registro da assinatura e conferência da sua cópia.</p>
  </div>
</section>
<section class="section">
  <div class="wrap narrow prose verif">
    <?php if (!$sig): ?>
      <p class="verif-estado -erro"><strong>Código não encontrado.</strong> Confira se o código está igual ao impresso no selo ou na margem do documento.</p>
    <?php else: ?>
      <p class="verif-estado <?= $state['original'] && $state['cadeia'] ? '-ok' : '-erro' ?>">
        <?php if ($state['original'] && $state['cadeia']): ?>
          <strong>Registro de assinatura encontrado e preservado.</strong> Você abriu o registro <?= e($sig['code']) ?> pelo código do documento. Isso confirma que este registro existe no sistema do escritório — não que a cópia que você tem seja idêntica. Para isso, use “Conferir minha cópia” abaixo.
        <?php else: ?>
          <strong>Registro encontrado, mas com alerta:</strong> <?= !$state['original'] ? 'a via original guardada não confere com o hash registrado.' : 'o histórico de registros não confere.' ?> O escritório foi avisado. Não considere esta conferência concluída.
        <?php endif ?>
      </p>

      <h2>Registro <?= e($sig['code']) ?></h2>
      <ul class="verif-dados">
        <li><span>Documento</span><strong><?= e(kit_label($sig['kind'])) ?></strong> · versão <?= (int) $sig['version'] ?> · atendimento <?= e($sig['protocol']) ?></li>
        <li><span>Signatário</span><strong><?= e($mask((string) $sig['signer_name'])) ?></strong> · assinatura eletrônica com confirmação por código no e-mail do cadastro</li>
        <li><span>Data e hora</span><?= e(dt($sig['signed_at'])->format('d/m/Y \à\s H:i:s')) ?> (horário de Brasília, UTC<?= e($fuso) ?>)</li>
        <li><span>Situação</span><?= $state['original'] ? 'Assinado · via original guardada sem alteração' : 'Assinado · via original com divergência' ?><?= $state['texto'] ? '' : ' · o texto da versão no sistema difere do aceito' ?></li>
        <?php if ($pdf): ?>
          <li><span>Via original (PDF)</span><?= (int) $sig['pages'] ?> <?= (int) $sig['pages'] === 1 ? 'página' : 'páginas' ?> · <?= e(number_format((int) $sig['pdf_size'], 0, ',', '.')) ?> bytes</li>
          <li><span>SHA-256 da via original</span><code class="verif-hash"><?= e($sig['pdf_sha256']) ?></code></li>
        <?php else: ?>
          <li><span>SHA-256 da via entregue (arquivo .html)</span><code class="verif-hash"><?= e((string) $sig['file_hash']) ?></code></li>
        <?php endif ?>
        <li><span>SHA-256 do texto aceito</span><code class="verif-hash"><?= e($sig['doc_hash']) ?></code></li>
      </ul>
      <?php if (!$pdf): ?>
        <p class="verif-nota">Este registro é anterior à via em PDF. Antes, esta página mostrava só o hash do texto aceito, que não é o hash do arquivo entregue por e-mail; agora os dois aparecem separados. A conferência da cópia usa o hash do arquivo entregue.</p>
      <?php endif ?>
      <?php if ($state['original']): ?>
        <p class="verif-acoes">
          <a class="btn btn-ghost btn-small" href="<?= e(url('verificar/' . $sig['code'] . '/original')) ?>" target="_blank" rel="noopener">Ver a via original</a>
          <a class="btn btn-ghost btn-small" href="<?= e(url('verificar/' . $sig['code'] . '/original', ['baixar' => '1'])) ?>">Baixar a via original</a>
        </p>
      <?php endif ?>

      <h2 id="conferir">Conferir minha cópia</h2>
      <p>Selecione ou arraste o arquivo que você recebeu. O hash SHA-256 é calculado <strong>no seu aparelho</strong>: o arquivo não é enviado nem guardado — só o hash vai para comparação.</p>
      <form class="verif-conferir" data-conferir data-url="<?= e(url('verificar/' . $sig['code'] . '/conferir')) ?>" data-ref-len="<?= strlen($ref) ?>">
        <?= csrf_field() ?>
        <label class="verif-zona" data-zona>
          <input type="file" data-arquivo accept="<?= $pdf ? 'application/pdf,.pdf' : '.html,.htm,text/html' ?>">
          <span><strong>Escolher arquivo</strong> ou arraste aqui</span>
          <small data-nome>nenhum arquivo selecionado</small>
        </label>
        <p class="verif-resultado" data-resultado aria-live="polite"></p>
        <p class="verif-hash-calc" data-hash hidden></p>
      </form>
      <p class="verif-nota">Reimpressões, digitalizações, “salvar como PDF” e novas exportações produzem arquivos diferentes, mesmo que pareçam iguais na tela. Uma cópia diferente não indica fraude por si só: para confirmar o conteúdo, compare com a via original acima.</p>

      <?php if ($state['log']): ?>
        <h2>Histórico do registro</h2>
        <ol class="verif-historico">
          <?php foreach ($state['log'] as $r): ?><li><span><?= e(fmt_datetime((string) $r['at'])) ?></span> <?= e($events[$r['event']] ?? $r['event']) ?></li><?php endforeach ?>
        </ol>
        <p class="verif-nota">Cada entrada do histórico leva o hash da anterior; o sistema não aceita alterar nem apagar entradas. Corrente <?= $state['cadeia'] ? 'conferida agora, sem quebras' : 'com quebra — o escritório foi avisado' ?>.</p>
      <?php endif ?>
      <p class="muted">A autoria foi confirmada pela conta do assinante na área do cliente, pelo nome e CPF e por um código enviado ao e-mail do cadastro. Os registros técnicos completos (IP, aparelho, localização e posição do selo) ficam guardados no escritório. Assinatura eletrônica nos termos da MP 2.200-2/2001, art. 10, § 2º, sem certificado ICP-Brasil.</p>
    <?php endif ?>
  </div>
</section>
<script src="<?= e(asset('js/verificar.js')) ?>" defer></script>
