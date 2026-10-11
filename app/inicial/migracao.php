<?php
declare(strict_types=1);

/** Seção 15.2 — classificação e migração conferida dos atendimentos do fluxo anterior. */

function migration_class(array $c): array
{
    $n = stage($c['stage'])['n'];
    $signed = !empty(case_data($c, 'engagement')['signed_at']) || scalar("SELECT 1 FROM case_checks WHERE case_id = ? AND stage = 'contratacao' AND item = 'assinado' AND done = 1", [$c['id']]);
    if ($c['status'] !== 'ativo') {
        return ['encerrado', 'Preservar desfecho e histórico', null];
    }
    if ($signed || $n >= 7) {
        return ['execucao', 'Manter em execução/legado, com prazos e obrigações', null];
    }
    if ($n === 6) {
        return ['assinatura', 'Aguardando decisão com assinatura pendente', 'awaiting_decision'];
    }
    if ($n === 5) {
        return ['decisao', 'Conferir se a conversa já ocorreu: Análise, Reunião ou Aguardando decisão', 'analysis'];
    }
    if ($n === 4) {
        return ['analise', 'Candidato a Análise (preservar memória e aprovações)', 'analysis'];
    }
    return ['coleta', 'Candidato a Coleta (Sidney confirma suficiência)', 'collecting'];
}

/** Copia os dados do cadastro anterior para a ficha, sem apagar nada do registro antigo. */
function migrate_case_to_initial(array $c, string $target): void
{
    $id = (int) $c['id'];
    db()->beginTransaction();
    $map = [
        'nome' => $c['client_name'], 'telefone' => $c['client_phone'], 'email' => $c['client_email'], 'cidade' => $c['client_city'], 'uf' => $c['client_uf'],
        'aviso' => $c['notice'], 'aviso_data' => $c['notice_date'], 'banco' => $c['bank'], 'objetivo_prioridade' => $c['priority'],
        'objetivo_texto' => $c['description'], 'outro_advogado' => $c['other_lawyer'], 'adaptacao' => $c['accessibility'],
        'situacao_contrato' => in_array($c['contract_status'], ['ativo', 'quitado'], true) ? $c['contract_status'] : null,
        'atraso' => in_array($c['overdue'], ['sim', 'nao'], true) ? $c['overdue'] : null,
        'observacoes' => trim(implode(' ', array_filter([$c['objective'] ? 'Objetivo registrado no fluxo anterior: ' . $c['objective'] . '.' : null, $c['objective_measure'] ? 'Comprovação: ' . $c['objective_measure'] . '.' : null]))) ?: null,
        'parcela' => $c['installment_value'] !== null ? number_format((float) $c['installment_value'], 2, ',', '') : null,
    ];
    foreach ($map as $k => $v) {
        if ($v !== null && $v !== '' && !field_answered($id, $k) && !normalize_field(field_def($k), (string) $v)[1]) {
            [$nv] = normalize_field(field_def($k), (string) $v);
            q('INSERT INTO case_fields (case_id, field, value, state, source, updated_at) VALUES (?,?,?,?,?,?)', [$id, $k, $nv, 'informado', 'migracao', now_str()]);
        }
    }
    $audit = case_data($c, 'audit', []);
    if (!empty($audit['validated_at'])) {
        $note = 'Cálculo validado no fluxo anterior em ' . fmt_date($audit['validated_at']) . '. ' . ($audit['summary_client'] ?? '');
        q('INSERT OR IGNORE INTO case_fields (case_id, field, value, state, source, updated_at) VALUES (?,?,?,?,?,?)', [$id, 'resultados', $note, 'informado', 'migracao', now_str()]);
    }
    bump_case($id, [
        'workflow_version' => 'simple_v1', 'initial_status' => $target, 'operator_id' => operator_id(), 'migrated_from_stage' => $c['stage'],
        'sufficiency_at' => $target !== 'collecting' ? ($c['docs_sufficient_at'] ?: now_str()) : null,
        'decision' => 'pendente', 'contract_state' => $target === 'awaiting_decision' ? 'assinatura_pendente' : 'nao_preparado',
        'urgency_signal' => $c['urgent'] ? 'possivel_urgencia' : null, 'urgency_reason' => $c['urgency_class'] ? (URGENCY_CLASSES[$c['urgency_class']]['label'] ?? null) : null,
        'urgency_reviewed_at' => $c['urgency_assessed_at'], 'chat_mode' => 'human',
    ]);
    // Tarefas puramente de transferência deixam de valer no fluxo novo; prazos não são tocados.
    q("UPDATE tasks SET status = 'desativada', evidence = 'Migração para o atendimento inicial' WHERE case_id = ? AND status = 'aberta' AND kind IN ('primeiro_contato','suficiencia','validar_calculo','recado','documento')", [$id]);
    add_event($id, 'migracao', 'Migrado para o atendimento inicial', 'Etapa anterior: ' . stage_label($c['stage']) . '. Situação proposta: ' . INITIAL_STATUS[$target]['label'] . '. Conferido por ' . (current_user()['name'] ?? 'Sidney') . '.');
    db()->commit();
}
