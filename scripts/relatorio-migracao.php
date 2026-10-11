<?php
/**
 * Relatório de migração (plano de reforma, seção 15.2). Somente leitura:
 * lista os atendimentos do fluxo anterior por classe, as exceções e os campos
 * que não têm correspondência automática na ficha nova. Nada é alterado.
 *
 * Uso: php scripts/relatorio-migracao.php
 *      FATUCHE_DB=/caminho/arquivo.sqlite php scripts/relatorio-migracao.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/app/bootstrap.php';

$rows = all("SELECT * FROM cases WHERE workflow_version = 'legacy' ORDER BY id");
$byClass = [];
$exceptions = [];
foreach ($rows as $c) {
    [$cls, $text] = migration_class($c);
    $byClass[$cls][] = $c;
    if ($c['urgent'] && !$c['urgency_assessed_at']) {
        $exceptions[] = $c['protocol'] . ': urgência sem avaliação registrada';
    }
    if ((int) scalar("SELECT COUNT(*) FROM deadlines WHERE case_id = ? AND status = 'aberto'", [$c['id']])) {
        $exceptions[] = $c['protocol'] . ': prazo jurídico aberto (preservado em qualquer classe)';
    }
}
echo "Operador configurado: " . (operator_id() ? user_name(operator_id()) : 'NENHUM — configure em Configurações antes de migrar') . "\n";
echo "Atendimentos no fluxo anterior: " . count($rows) . "\n\n";
foreach ($byClass as $cls => $list) {
    echo strtoupper($cls) . ' (' . count($list) . ")\n";
    foreach ($list as $c) {
        echo '  ' . $c['protocol'] . ' · ' . $c['client_name'] . ' · etapa ' . stage_label($c['stage']) . ' · ' . migration_class($c)[1] . "\n";
    }
}
echo "\nExceções para conferência de Sidney:\n" . ($exceptions ? '  ' . implode("\n  ", $exceptions) : '  nenhuma') . "\n";
echo "\nCampos sem correspondência automática (ficam na ficha antiga, acessível pelo histórico):\n";
echo "  budget_installment, contact_time, summary_sentence, data.audit (cálculo validado vira texto em “Resultados”; objetivo vai para “Observações”)\n";
echo "\nA migração de cada pré-contratual é feita no painel, em Configurações › Migração, depois da conferência.\n";
