<?php
/**
 * Cria dados FICTÍCIOS para treinar a equipe e conhecer o painel.
 * Não use no banco de produção. Por padrão grava em storage/demo.sqlite.
 *
 * Uso: php scripts/demo.php            (cria storage/demo.sqlite)
 *      FATUCHE_DB=/caminho/arquivo.sqlite php scripts/demo.php
 * Para usar o banco de demonstração no servidor local:
 *      FATUCHE_DB=storage/demo.sqlite php -S 127.0.0.1:8080 -t public public/index.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}
$root = dirname(__DIR__);
if (!getenv('FATUCHE_DB')) {
    putenv('FATUCHE_DB=' . $root . '/storage/demo.sqlite');
}
$dbFile = getenv('FATUCHE_DB');
if (is_file($dbFile)) {
    unlink($dbFile);
}
require $root . '/app/bootstrap.php';

$senha = 'demonstracao2026';
$users = [
    ['Sidney Fatuche', 'sidney@demo.local', 'advogado,supervisor,calculos'],
    ['Gestora Demonstração', 'gestora@demo.local', 'gestor,negociador,financeiro'],
];
foreach ($users as [$n, $e, $r]) {
    insert('users', ['name' => $n, 'email' => $e, 'roles' => $r, 'pass_hash' => password_hash($senha, PASSWORD_DEFAULT), 'active' => 1, 'created_at' => now_str()]);
}
$_SESSION = [];
$GLOBALS['__uid'] = 2;
$sidney = 1;
$gestora = 2;

function at(string $when): void
{
    $GLOBALS['FAKE_NOW'] = (new DateTimeImmutable($when))->format('Y-m-d H:i:s');
}

function act_as(int $uid): void
{
    $_SESSION['uid'] = $uid;
}

function demo_case(array $over, string $when): array
{
    at($when);
    $base = [
        'notice' => 'nenhum', 'notice_date' => '', 'bank' => 'Banco Exemplo S.A.', 'contract_status' => 'ativo',
        'installment_value' => 1290.0, 'overdue' => 'nao', 'overdue_count' => '', 'has_contract' => 'sim',
        'city' => 'Curitiba', 'uf' => 'PR', 'priority' => 'reduzir_parcela', 'priority_text' => '',
        'description' => '', 'budget_installment' => 900.0, 'other_lawyer' => 'nao', 'contact_pref' => 'whatsapp',
        'contact_time' => 'Tarde', 'accessibility' => '', 'email' => '', 'origin' => 'demo', 'summary_confirmed' => true,
    ];
    $a = array_merge($base, $over);
    $a['summary'] = summary_sentence($a);
    return create_case_from_triage($a, $over['source'] ?? 'site');
}

function first_contact(array $c, string $when): void
{
    at($when);
    act_as(2);
    touch_case((int) $c['id'], ['first_human_contact_at' => now_str()]);
    close_tasks_of_kind((int) $c['id'], 'primeiro_contato', 'WhatsApp');
    add_event((int) $c['id'], 'contato', 'Primeiro contato: Gestora se apresentou e vai acompanhar o seu caso', 'Canal: WhatsApp', true);
    foreach (['apresentacao', 'pergunta_urgencia', 'minimo', 'prioridade', 'resumo', 'registro', 'cliente_compreende', 'urgencias_encaminhadas', 'conflito_tratado'] as $k) {
        set_check((int) $c['id'], 'acolhimento', $k, true);
    }
}

function go(array $c, string $to, string $when, string $reason = ''): array
{
    at($when);
    $c = find_case((int) $c['id']);
    q('UPDATE cases SET stage = ?, stage_since = ? WHERE id = ?', [$to, now_str(), $c['id']]);
    add_event((int) $c['id'], 'etapa', 'Etapa: ' . stage_label($c['stage']) . ' → ' . stage_label($to), $reason ?: null);
    if (stage($c['stage'])['phase'] !== stage($to)['phase']) {
        add_event((int) $c['id'], 'fase', 'Seu caso avançou para: ' . CLIENT_PHASES[stage($to)['phase']]['label'], CLIENT_PHASES[stage($to)['phase']]['text'], true);
    }
    return find_case((int) $c['id']);
}

function next_action(array $c, string $text, string $due, ?string $client = null, ?string $update = null): void
{
    touch_case((int) $c['id'], ['next_action' => $text, 'next_action_due' => $due, 'next_action_owner_id' => 2, 'client_next_action' => $client, 'next_client_update' => $update ?? $due]);
}

$now = new DateTimeImmutable('now');
$d = fn(string $mod) => $now->modify($mod)->format('Y-m-d H:i:s');

/* 1. Novo pelo site, aguardando primeiro contato */
$c1 = demo_case(['name' => 'Ana Exemplo (demo)', 'phone' => '(41) 90000-0001', 'priority' => 'manter_veiculo', 'overdue' => 'sim', 'overdue_count' => '2 ou 3 parcelas', 'description' => 'Tenho duas parcelas atrasadas e uso o carro para trabalhar como entregadora.'], $d('-40 minutes'));

/* 2. Apreensão: urgência sem avaliação */
$c2 = demo_case(['name' => 'Bruno Exemplo (demo)', 'phone' => '(11) 90000-0002', 'notice' => 'apreendido', 'notice_date' => $now->modify('-1 day')->format('Y-m-d'), 'priority' => 'apreensao', 'overdue' => 'sim', 'overdue_count' => 'mais de 3 parcelas', 'uf' => 'SP', 'city' => 'Campinas'], $d('-5 hours'));
first_contact($c2, $d('-4 hours 30 minutes'));
$c2 = go($c2, 'urgencia', $d('-4 hours 20 minutes'), 'Apreensão ontem; cliente enviou foto do auto de apreensão.');
touch_case((int) $c2['id'], ['objective' => 'Avaliar a recuperação do veículo e a defesa a tempo', 'objective_measure' => 'Plano urgente com medida, prazo e responsável']);
next_action($c2, 'Avaliação do advogado: rito, prazos e via de restituição', $d('+30 minutes'), 'Enviar foto de todas as páginas do mandado');

/* 3. Documentos: sem contrato, pedido ao banco */
$c3 = demo_case(['name' => 'Carla Exemplo (demo)', 'phone' => '(41) 90000-0003', 'has_contract' => 'nao', 'priority' => 'reduzir_parcela', 'installment_value' => 1580.0, 'budget_installment' => 1100.0], $d('-6 days'));
first_contact($c3, $d('-6 days +40 minutes'));
touch_case((int) $c3['id'], ['objective' => 'Parcela de até R$ 1.100 sem perder o carro', 'objective_measure' => 'Novo demonstrativo e primeiro boleto no valor acordado', 'urgency_class' => 'em_dia', 'urgency_assessed_at' => $d('-6 days +2 hours')]);
$c3 = go($c3, 'documentos', $d('-6 days +3 hours'));
set_case_data((int) $c3['id'], 'bank_requests', [['channel' => 'SAC do banco', 'protocol' => 'DEMO-12345', 'requested_at' => $now->modify('-5 days')->format('Y-m-d'), 'bank_deadline' => $now->modify('-1 day')->format('Y-m-d'), 'items' => 'Cópia integral do contrato, demonstrativo e histórico']]);
q("UPDATE documents SET status = 'conferido', received_at = ?, reviewed_at = ? WHERE case_id = ? AND kind IN ('titular','veiculo')", [$d('-5 days'), $d('-5 days'), $c3['id']]);
add_task((int) $c3['id'], 'Cobrar banco: cópia do contrato (protocolo DEMO-12345)', 2, $now->modify('+1 day')->setTime(10, 0), 'banco', 'cobrar_banco');
next_action($c3, 'Cobrar o banco pela cópia do contrato', $d('+1 day'), 'Nenhuma no momento', $d('+2 days'));

/* 4. Auditoria em andamento */
$c4 = demo_case(['name' => 'Diego Exemplo (demo)', 'phone' => '(48) 90000-0004', 'uf' => 'SC', 'city' => 'Joinville', 'priority' => 'restituicao', 'installment_value' => 1873.25], $d('-9 days'));
first_contact($c4, $d('-9 days +30 minutes'));
touch_case((int) $c4['id'], ['objective' => 'Verificar juros e tarifas e recuperar o que foi pago indevidamente', 'objective_measure' => 'Parecer com memória de cálculo e, se houver, valor restituído comprovado', 'urgency_class' => 'em_dia', 'urgency_assessed_at' => $d('-9 days +1 hour'), 'docs_sufficient_at' => $d('-3 days')]);
q("UPDATE documents SET status = 'conferido', received_at = ?, reviewed_at = ? WHERE case_id = ?", [$d('-4 days'), $d('-3 days'), $c4['id']]);
set_check((int) $c4['id'], 'documentos', 'dossie_conferido', true);
set_check((int) $c4['id'], 'documentos', 'suficiencia', true);
$c4 = go($c4, 'auditoria', $d('-3 days'));
set_case_data((int) $c4['id'], 'audit', ['contract_date' => '2024-03-15', 'principal' => number_format(1873.25 * (1 - (1.0199) ** -48) / 0.0199, 2, ',', ''), 'down_payment' => '15000', 'installments' => '48', 'installment_value' => '1873,25', 'monthly_rate' => '1,99', 'cet_annual' => '31,2', 'evaluation_fee' => '450', 'registration_fee' => '280', 'insurance' => '1890', 'paid_installments' => '30', 'bcb_rate' => '1,62', 'bcb_reference' => '03/2024', 'bcb_consulted_at' => $now->modify('-2 days')->format('Y-m-d'), 'bcb_source' => 'BCB SGS 25471', 'reference_date' => $now->format('Y-m-d')]);
next_action($c4, 'Concluir memória de cálculo e levar para validação', $d('-2 hours'), null, $d('+1 day'));

/* 5. Decisão do cliente pendente */
$c5 = demo_case(['name' => 'Elisa Exemplo (demo)', 'phone' => '(41) 90000-0005', 'priority' => 'quitar', 'overdue' => 'sim', 'overdue_count' => '1 parcela'], $d('-15 days'));
first_contact($c5, $d('-15 days +20 minutes'));
touch_case((int) $c5['id'], ['objective' => 'Quitar a dívida com desconto e retirar o gravame', 'objective_measure' => 'Termo de quitação e baixa do gravame consultada', 'urgency_class' => 'atraso', 'urgency_assessed_at' => $d('-15 days +1 hour'), 'docs_sufficient_at' => $d('-10 days')]);
q("UPDATE documents SET status = 'conferido', received_at = ?, reviewed_at = ? WHERE case_id = ?", [$d('-11 days'), $d('-10 days'), $c5['id']]);
set_case_data((int) $c5['id'], 'audit', ['principal' => '38000', 'installments' => '36', 'installment_value' => '1499,00', 'monthly_rate' => '2,49', 'bcb_rate' => '1,85', 'paid_installments' => '20', 'conclusion' => 'negociacao', 'summary_client' => "A taxa do seu contrato está acima da média do Banco Central na época, mas a diferença sozinha não garante a revisão.\nComo seu objetivo é quitar, o caminho mais rápido é negociar a quitação usando esses números como argumento.", 'validated_at' => $d('-8 days'), 'validated_by' => 1]);
set_check((int) $c5['id'], 'auditoria', 'memoria', true);
$c5 = go($c5, 'decisao', $d('-8 days'));
set_check((int) $c5['id'], 'decisao', 'objetivo_mensuravel', true);
at($d('-2 days'));
insert('decisions', ['case_id' => $c5['id'], 'kind' => 'caminho', 'question' => 'Qual caminho você prefere seguir?', 'context' => 'Recomendação do advogado: Negociar com o credor, porque o objetivo é quitar e a revisão judicial levaria mais tempo e custo.', 'options' => json_encode(array_map(fn($p) => $p['label'], array_values(PATHS)), JSON_UNESCAPED_UNICODE), 'deadline' => $now->modify('+3 days')->format('Y-m-d'), 'requested_by' => 1, 'requested_at' => now_str()]);
add_event((int) $c5['id'], 'decisao', 'Uma decisão depende de você', 'Qual caminho você prefere seguir?', true);
next_action($c5, 'Aguardar escolha do caminho e confirmar por ligação', $d('+1 day'), 'Responder: qual caminho você prefere seguir?');

/* 6. Negociação: oferta recebida sem comunicar */
$c6 = demo_case(['name' => 'Fábio Exemplo (demo)', 'phone' => '(31) 90000-0006', 'uf' => 'MG', 'city' => 'Belo Horizonte', 'priority' => 'reduzir_parcela', 'overdue' => 'sim', 'overdue_count' => '2 ou 3 parcelas', 'installment_value' => 1350.0, 'budget_installment' => 850.0], $d('-25 days'));
first_contact($c6, $d('-25 days +15 minutes'));
touch_case((int) $c6['id'], ['objective' => 'Parcela de até R$ 850 e manter o carro', 'objective_measure' => 'Instrumento assinado e primeiro boleto correto', 'urgency_class' => 'atraso', 'urgency_assessed_at' => $d('-25 days +1 hour')]);
$c6 = go($c6, 'negociacao', $d('-6 days'));
set_case_data((int) $c6['id'], 'negotiation', ['max_down' => 1500.0, 'max_installment' => 850.0, 'pay_date' => 'dia 10', 'priority' => 'Manter o carro', 'creditor' => 'Banco Exemplo S.A.', 'authorized_at' => $now->modify('-6 days')->format('Y-m-d')]);
at($d('-30 hours'));
insert('offers', ['case_id' => $c6['id'], 'round' => 1, 'origin' => 'credor', 'creditor' => 'Banco Exemplo S.A.', 'ref_balance' => 28400.0, 'ref_date' => $now->modify('-2 days')->format('Y-m-d'), 'down_payment' => 1200.0, 'installments' => 36, 'installment_value' => 890.0, 'valid_until' => $now->modify('+1 day')->format('Y-m-d'), 'effects' => 'Mantém o financiamento e a alienação até o fim', 'status' => 'recebida', 'created_by' => 2, 'created_at' => now_str()]);
add_task((int) $c6['id'], 'Comunicar oferta ao cliente (meta: 1 dia útil)', 2, $now->modify('-6 hours'), 'equipe', 'comunicar_oferta');
next_action($c6, 'Comunicar a oferta ao cliente com a avaliação do advogado', $d('-6 hours'));

/* 7. Acordo em cumprimento */
$c7 = demo_case(['name' => 'Gabriela Exemplo (demo)', 'phone' => '(41) 90000-0007', 'priority' => 'quitar'], $d('-50 days'));
first_contact($c7, $d('-50 days +10 minutes'));
touch_case((int) $c7['id'], ['objective' => 'Quitar em 4 vezes e retirar o gravame', 'objective_measure' => 'Termo de quitação e baixa do gravame']);
$c7 = go($c7, 'acordo', $d('-20 days'));
set_case_data((int) $c7['id'], 'agreement', ['signed_at' => $now->modify('-18 days')->format('Y-m-d'), 'instrument' => 'Termo de acordo para quitação', 'balance' => 9600.0, 'discount' => 2400.0, 'releases' => 'Quitação após a 4ª parcela; banco dá baixa no gravame em até 10 dias', 'boleto_checked' => true]);
set_check((int) $c7['id'], 'acordo', 'formal', true);
for ($k = 0; $k < 4; $k++) {
    insert('schedule', ['case_id' => $c7['id'], 'description' => 'Parcela do acordo (' . ($k + 1) . '/4)', 'due_date' => $now->modify('-15 days')->modify("+$k month")->format('Y-m-d'), 'amount' => 1800.0, 'status' => $k === 0 ? 'pago' : 'pendente', 'confirmed_at' => $k === 0 ? $d('-15 days') : null]);
}
next_action($c7, 'Avisar o cliente 3 dias antes da 2ª parcela', $now->modify('+12 days')->format('Y-m-d 10:00:00'));

/* 8. Processo em curso */
$c8 = demo_case(['name' => 'Henrique Exemplo (demo)', 'phone' => '(41) 90000-0008', 'notice' => 'justica', 'priority' => 'apreensao'], $d('-70 days'));
first_contact($c8, $d('-70 days +5 minutes'));
touch_case((int) $c8['id'], ['objective' => 'Defesa na busca e apreensão e revisão das tarifas', 'objective_measure' => 'Decisão cumprida e novo saldo conferido', 'urgency_class' => 'apreensao_prazo', 'urgency_assessed_at' => $d('-70 days +1 hour')]);
$c8 = go($c8, 'processo', $d('-40 days'));
set_case_data((int) $c8['id'], 'judicial', ['process_number' => '0000000-00.2026.8.16.0000 (fictício)', 'court' => '1ª Vara Cível (fictícia)', 'filed_at' => $now->modify('-40 days')->format('Y-m-d'), 'claims' => 'Exclusão da tarifa de avaliação; restituição simples', 'tutela' => 'pedida']);
insert('deadlines', ['case_id' => $c8['id'], 'description' => 'Réplica à contestação', 'trigger_event' => 'Intimação (fictícia)', 'due_at' => $now->modify('+1 day')->format('Y-m-d 18:00:00'), 'owner_id' => 1, 'substitute_id' => 2, 'status' => 'aberto']);
close_tasks_of_kind((int) $c8['id'], 'avaliacao_urgencia', 'Classificado pelo advogado');
close_tasks_of_kind((int) $c8['id'], 'primeiro_contato', 'WhatsApp');
next_action($c8, 'Protocolar réplica', $d('+1 day'), null, $d('+10 days'));

/* 9. Conferência */
$c9 = demo_case(['name' => 'Isabela Exemplo (demo)', 'phone' => '(41) 90000-0009', 'priority' => 'reduzir_parcela'], $d('-90 days'));
first_contact($c9, $d('-90 days +12 minutes'));
touch_case((int) $c9['id'], ['objective' => 'Parcela de até R$ 1.000', 'objective_measure' => 'Primeiro boleto renegociado correto']);
$c9 = go($c9, 'conferencia', $d('-3 days'));
set_case_data((int) $c9['id'], 'solution', ['renegociada' => ['applies' => true, 'evidence' => 'Termo assinado; boleto de outubro emitido', 'done' => false]]);
next_action($c9, 'Teste prático: confirmar com a cliente o primeiro boleto', $d('+1 day'), 'Confirmar se o boleto de outubro chegou com R$ 980');

/* 10. Encerrado */
$c10 = demo_case(['name' => 'João Exemplo (demo)', 'phone' => '(41) 90000-0010', 'priority' => 'quitar'], $d('-120 days'));
first_contact($c10, $d('-120 days +25 minutes'));
q("UPDATE cases SET stage = 'encerramento', status = 'encerrado', outcome = 'resolvido', closed_at = ?, objective = 'Quitar e retirar o gravame' WHERE id = ?", [$d('-10 days'), $c10['id']]);
at($d('-10 days'));
add_event((int) $c10['id'], 'encerramento', 'Caso encerrado: Resolvido', 'Quitação registrada e gravame baixado. Checagem de 30 dias agendada.', true);
insert('feedback', ['case_id' => $c10['id'], 'kind' => 'encerramento', 'ease' => 5, 'simplify' => 'Poderia ter um aviso por mensagem quando o banco respondesse.', 'answers' => '{}', 'created_at' => now_str()]);

// Acesso de demonstração à área do cliente para o caso 5
q('UPDATE cases SET portal_pass_hash = ? WHERE id = ?', [password_hash('cliente2026', PASSWORD_DEFAULT), $c5['id']]);

/* ---------- Atendimento inicial (fluxo novo: operador único e assistente) ---------- */
unset($GLOBALS['FAKE_NOW']);
set_setting('operator_user_id', (string) $sidney);
set_setting('office_address', 'Rua de Demonstração, 100, Centro, São José dos Pinhais/PR');
act_as($sidney);
seed_templates();
$bot = function (int $id, array $answers): void {
    foreach ($answers as $k => $v) {
        $state = $v === null ? 'desconhecido' : 'informado';
        chat_post($id, 'bot', 'Pergunta do assistente: ' . field_def($k)['label'], $k);
        set_field($id, $k, $v, $state, 'cliente');
        chat_post($id, 'client', $v === null ? 'Não sei' : field_display($k, case_fields($id)[$k]), $k);
        bot_after_answer($id, $k);
    }
};
$i1 = create_initial_case(['nome' => 'Larissa Exemplo (demo)', 'telefone' => '(41) 90000-1001', 'email' => '', 'adaptacao' => ''], 'site');
chat_post((int) $i1['id'], 'bot', BOT_GREETING);
$bot((int) $i1['id'], ['aviso' => 'apreendido', 'aviso_data' => date('Y-m-d', strtotime('-2 days')), 'objetivo_prioridade' => 'apreensao', 'objetivo_texto' => 'Levaram o carro ontem de manhã, preciso dele para trabalhar.']);
$i2 = create_initial_case(['nome' => 'Paulo Exemplo (demo)', 'telefone' => '(41) 90000-1002', 'email' => 'paulo@exemplo.invalid', 'adaptacao' => ''], 'site');
$bot((int) $i2['id'], ['aviso' => 'nenhum', 'objetivo_prioridade' => 'reduzir_parcela', 'situacao_contrato' => 'ativo', 'atraso' => 'nao', 'banco' => 'Banco Exemplo S.A.', 'capital' => '45000,00', 'prazo' => '48', 'parcela' => '1.390,00', 'taxa_mensal' => '1,99', 'reuniao_canal' => 'meet']);
foreach (all('SELECT * FROM documents WHERE case_id = ?', [$i2['id']]) as $d) {
    q("UPDATE documents SET status = 'conferido', received_at = ?, reviewed_at = ? WHERE id = ?", [now_str(), now_str(), $d['id']]);
}
initial_command(find_case((int) $i2['id']), 'liberar_analise', ['note' => 'Contrato e extrato legíveis']);
$i3 = create_initial_case(['nome' => 'Renata Exemplo (demo)', 'telefone' => '(41) 90000-1003', 'email' => '', 'adaptacao' => ''], 'site');
$bot((int) $i3['id'], ['aviso' => 'banco', 'objetivo_prioridade' => 'manter_veiculo', 'situacao_contrato' => 'ativo', 'atraso' => 'sim', 'banco' => 'Financeira Exemplo', 'capital' => '38000,00', 'prazo' => '60', 'parcela' => '1.120,00', 'taxa_mensal' => '2,10', 'nome_completo' => 'Renata Exemplo da Silva', 'reuniao_canal' => 'phone']);
foreach (all('SELECT * FROM documents WHERE case_id = ?', [$i3['id']]) as $d) {
    q("UPDATE documents SET status = 'conferido' WHERE id = ?", [$d['id']]);
}
initial_command(find_case((int) $i3['id']), 'liberar_analise', ['note' => 'ok']);
foreach (['data_base' => date('Y-m-d'), 'bacen_codigo' => '25471', 'bacen_taxa' => '1,62', 'conclusao' => 'negociacao', 'resultados' => 'Taxa contratada acima da média de mercado do período (exemplo).', 'conflito' => 'sem_conflito', 'legitimidade' => 'ok',
          'proposta_escopo' => 'Negociação com o credor (exemplo).', 'proposta_honorarios' => 'A definir por Sidney.', 'proposta_riscos' => 'O credor pode recusar (exemplo).', 'perguntas_reuniao' => "Qual parcela cabe no orçamento?"] as $k => $v) {
    set_field((int) $i3['id'], $k, $v, 'informado', 'sidney');
}
initial_command(find_case((int) $i3['id']), 'aprovar_preparacao', []);
initial_command(find_case((int) $i3['id']), 'confirmar_reuniao', ['channel' => 'phone', 'starts_at' => date('Y-m-d', strtotime('+2 weekdays')) . ' 15:00', 'duration' => 30, 'phone' => '41900001003']);
$i4 = create_initial_case(['nome' => 'Carlos Exemplo (demo)', 'telefone' => '(41) 90000-1004', 'email' => '', 'adaptacao' => ''], 'site');
q("UPDATE cases SET initial_status = 'awaiting_decision', preparation_at = ?, sufficiency_at = ?, decision = 'aceitou', decision_at = ?, contract_state = 'assinatura_pendente', next_action = 'Receber o contrato assinado', next_action_due = ? WHERE id = ?", [now_str(), now_str(), now_str(), date('Y-m-d 18:00:00', strtotime('+2 days')), $i4['id']]);
$i5 = create_initial_case(['nome' => 'Beatriz Exemplo (demo)', 'telefone' => '(41) 90000-1005', 'email' => '', 'adaptacao' => ''], 'site');
initial_command(find_case((int) $i5['id']), 'encerrar_antes', ['decision' => 'desistiu', 'note' => 'Resolveu direto com o banco; orientada a guardar os comprovantes.']);
login_portal((int) $i2['id'], password_hash('cliente2026', PASSWORD_DEFAULT));
unset($_SESSION['portal_account'], $_SESSION['portal_case']);

echo "Banco de demonstração criado em $dbFile\n";
echo "Painel: sidney@demo.local ou gestora@demo.local · senha $senha\n";
echo "Área do cliente: protocolo " . find_case((int) $c5['id'])['protocol'] . " (execução) ou " . find_case((int) $i2['id'])['protocol'] . " (atendimento inicial) · senha cliente2026\n";
echo "Operador dos atendimentos: sidney@demo.local\n";
