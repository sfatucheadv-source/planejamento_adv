<div class="page-head"><div><h1>Fluxo de trabalho</h1><p class="sub">Procedimento operacional: da primeira conversa à comprovação do resultado para o cliente.</p></div></div>
<section class="box">
  <h2>Regra de encerramento</h2>
  <p>Contrato assinado, acordo celebrado, liminar ou sentença favorável não bastam. O caso permanece em execução até serem conferidos os efeitos devidos: novo saldo e boletos, recebimento da restituição, quitação, baixa de gravame ou regularização dos registros. Quando o resultado for inviável ou negado definitivamente, entregar a análise das alternativas e registrar como encerramento sem alcance do objetivo, nunca como problema resolvido.</p>
  <p class="hint"><strong>Jornada:</strong> acolher → identificar urgência → reunir documentos → auditar o contrato → apresentar opções → contratar → negociar → formalizar e cumprir o acordo. Se o acordo não resolver e houver fundamento: preparar a ação → tratar medidas urgentes → acompanhar provas e audiências → avaliar decisão e recursos → apurar valores → exigir cumprimento → conferir resultado → prestar contas → encerrar e acompanhar. A urgência corre em paralelo à documentação; a negociação pode continuar durante o processo.</p>
</section>
<div class="grid-2col">
  <section class="box">
    <h2>Responsáveis</h2>
    <div class="tbl-wrap"><table class="tbl tbl-compact"><thead><tr><th>Papel</th><th>Responsabilidade</th><th>Decide</th></tr></thead><tbody>
      <?php foreach (ROLE_DUTIES as $r => [$resp, $dec]): ?><tr><td><?= e(ROLES[$r]) ?></td><td><?= e($resp) ?></td><td><?= e($dec) ?></td></tr><?php endforeach ?>
    </tbody></table></div>
    <p class="hint">Em equipe pequena, uma pessoa pode acumular funções. O responsável por cada tarefa e o substituto devem continuar identificados.</p>
  </section>
  <section class="box">
    <h2>Metas internas</h2>
    <div class="tbl-wrap"><table class="tbl tbl-compact"><thead><tr><th>Evento</th><th>Meta</th><th>Se não cumprida</th></tr></thead><tbody>
      <?php foreach (SERVICE_GOALS as $g): ?><tr><td><?= e($g['event']) ?></td><td><?= e($g['goal']) ?></td><td><?= e($g['miss']) ?></td></tr><?php endforeach ?>
    </tbody></table></div>
    <p class="hint">Horário: dias úteis, <?= open_hour() ?>h às <?= close_hour() ?>h (Brasília). Prazos legais curtos exigem prioridade e substituição; as metas internas não podem retardar a atuação necessária.</p>
  </section>
</div>
<?php foreach (CLIENT_PHASES as $pk => $ph): ?>
  <h2 class="phase-title"><?= e($ph['label']) ?> <small>· o que o cliente vê</small></h2>
  <div class="grid-2col">
    <?php foreach (stages() as $k => $s): if ($s['phase'] !== $pk) continue; ?>
      <section class="box">
        <h3><?= $s['n'] ?>. <?= e($s['title']) ?></h3>
        <p class="small"><strong>Responsável:</strong> <?= e(implode(', ', array_map(fn($r) => ROLES[$r], $s['owner']))) ?> · <strong>Meta:</strong> <?= e($s['goal']) ?></p>
        <ol class="small-ol"><?php foreach ($s['steps'] as $t): ?><li><?= e($t) ?></li><?php endforeach ?></ol>
        <?php if ($s['gates']): ?><p class="small"><strong>Condição para avançar:</strong> <?= e(implode('; ', array_map(fn($g) => $g['label'], $s['gates']))) ?>.</p><?php endif ?>
        <p class="small"><strong>Saída:</strong> <?= e($s['deliverable']) ?></p>
      </section>
    <?php endforeach ?>
  </div>
<?php endforeach ?>
<section class="box">
  <h2>Exceções que precisam ter próximo passo</h2>
  <div class="tbl-wrap"><table class="tbl tbl-compact"><thead><tr><th>Ocorrência</th><th>Quem assume</th><th>Providência</th></tr></thead><tbody>
    <?php foreach (EXCEPTIONS as $x): ?><tr><td><?= e($x['label']) ?></td><td><?= e($x['who']) ?></td><td><?= e($x['action']) ?></td></tr><?php endforeach ?>
  </tbody></table></div>
  <p class="hint">Decisões de viabilidade, liminar, pagamento, acordo, recurso e encerramento dependem de revisão humana. A automação não dá diagnóstico jurídico nem interpreta o silêncio como consentimento.</p>
</section>
