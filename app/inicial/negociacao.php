<?php
declare(strict_types=1);

/**
 * Painel de negociação da proposta (uso interno, só no painel de propostas):
 *
 * - simulador de vendas do Asaas: para um valor, quanto o cliente paga e
 *   quanto o escritório recebe em cada forma (PIX, boleto, cartão em até 21x,
 *   parcelamento próprio), com repasse da taxa e antecipação;
 * - guia de preço: parte da tabela de honorários da OAB (piso), com 30% a
 *   mais para quem chega por indicação;
 * - avaliação pela IA: lê o perfil de consumo do cliente (só os dados deste
 *   atendimento) e sugere preço, forma e prazo. Nada disso vai para o cliente
 *   nem para o contrato.
 */

/* ---------- Taxas do Asaas ---------- */

/** Taxas de referência do Asaas; cada conta pode ter as suas (Configurações › Preços e negociação). */
const ASAAS_FEE_DEFAULTS = [
    'pix_fixo' => 1.99, 'pix_pct' => 0.0, 'boleto_fixo' => 1.99,
    'cartao_fixo' => 0.49, 'cartao_1' => 2.99, 'cartao_2_6' => 3.49, 'cartao_7_12' => 3.99, 'cartao_13_21' => 4.29,
    'antecipacao' => 1.70, 'cartao_max' => 12,
];

const ASAAS_FEE_LABELS = [
    'pix_fixo' => 'PIX: taxa fixa (R$)',
    'pix_pct' => 'PIX: percentual (%)',
    'boleto_fixo' => 'Boleto: taxa por boleto pago (R$)',
    'cartao_fixo' => 'Cartão: taxa fixa por cobrança (R$)',
    'cartao_1' => 'Cartão à vista (%)',
    'cartao_2_6' => 'Cartão de 2 a 6 parcelas (%)',
    'cartao_7_12' => 'Cartão de 7 a 12 parcelas (%)',
    'cartao_13_21' => 'Cartão de 13 a 21 parcelas (%)',
    'antecipacao' => 'Antecipação do cartão (% ao mês)',
    'cartao_max' => 'Máximo de parcelas no cartão',
];

function asaas_fees(): array
{
    $saved = json_decode((string) setting('asaas_fees', '{}'), true) ?: [];
    $f = [];
    foreach (ASAAS_FEE_DEFAULTS as $k => $def) {
        $v = $saved[$k] ?? null;
        $f[$k] = is_numeric($v) ? (float) $v : (float) $def;
    }
    $f['cartao_max'] = max(1, min(21, (int) $f['cartao_max']));
    return $f;
}

function asaas_fees_custom(): bool
{
    return (bool) (json_decode((string) setting('asaas_fees', '{}'), true) ?: []);
}

function asaas_card_pct(int $n, array $f): float
{
    return ($n <= 1 ? $f['cartao_1'] : ($n <= 6 ? $f['cartao_2_6'] : ($n <= 12 ? $f['cartao_7_12'] : $f['cartao_13_21']))) / 100;
}

/* ---------- Simulador de vendas ---------- */

const SIM_PARCELAS = [2, 3, 4, 5, 6, 8, 10, 12];

/**
 * Simula o valor em cada forma de pagamento. Valores em centavos.
 * PIX direto na chave do escritório não tem taxa do Asaas. Parcelado no
 * escritório: parcelas fixas com juros de 1% a.m. (tabela Price). Cartão:
 * taxa repassada ao cliente, parcela mínima de R$ 500,00.
 * $opt: desconto (% à vista).
 */
function sales_sim(int $value, array $opt, array $d): array
{
    $f = asaas_fees();
    $desc = max(0.0, min(30.0, (float) ($opt['desconto'] ?? 0))) / 100;
    $min = pricing()['cartao_min_parcela'];
    $rows = [];
    if ($value <= 0) {
        return $rows;
    }
    $avista = (int) round($value * (1 - $desc));
    $tagDesc = $desc ? ' (' . fmt_pct($desc * 100) . ' de desconto)' : '';
    $rows[] = ['key' => 'pix', 'grupo' => 'pix', 'sub' => 'avista', 'label' => 'PIX à vista na chave' . $tagDesc, 'n' => 1,
        'cliente' => $avista, 'parcela' => $avista, 'liquido' => $avista, 'taxas' => 0, 'recebe' => 'na hora, sem taxa', 'antecipado' => null];
    $bolFee = (int) round($f['boleto_fixo'] * 100);
    $pixFee = (int) round($f['pix_fixo'] * 100);
    $rows[] = ['key' => 'boleto', 'grupo' => 'boleto', 'sub' => 'avista', 'label' => 'Boleto à vista' . $tagDesc, 'n' => 1,
        'cliente' => $avista, 'parcela' => $avista, 'liquido' => $avista - $bolFee, 'taxas' => $bolFee, 'recebe' => '1 dia útil após o pagamento', 'antecipado' => null];
    foreach (SIM_PARCELAS as $n) {
        $tab = price_table($value, $n);
        $tot = array_sum(array_column($tab, 'valor'));
        $par = $tab[0]['valor'];
        $rows[] = ['key' => 'pixparc_' . $n, 'grupo' => 'pix', 'sub' => 'parcelado', 'label' => 'PIX parcelado no escritório em ' . $n . 'x', 'n' => $n,
            'cliente' => $tot, 'parcela' => $par, 'liquido' => $tot, 'taxas' => 0, 'recebe' => 'mês a mês, sem taxa (risco de atraso é seu)', 'antecipado' => null, 'risco' => true];
        $rows[] = ['key' => 'pixrec_' . $n, 'grupo' => 'pix', 'sub' => 'recorrente', 'label' => 'PIX recorrente no Asaas em ' . $n . 'x', 'n' => $n,
            'cliente' => $tot, 'parcela' => $par, 'liquido' => $tot - $n * $pixFee, 'taxas' => $n * $pixFee, 'recebe' => 'cobrança automática mensal', 'antecipado' => null, 'risco' => true];
        $rows[] = ['key' => 'boletoparc_' . $n, 'grupo' => 'boleto', 'sub' => 'parcelado', 'label' => 'Boleto parcelado em ' . $n . 'x', 'n' => $n,
            'cliente' => $tot, 'parcela' => $par, 'liquido' => $tot - $n * $bolFee, 'taxas' => $n * $bolFee, 'recebe' => 'mês a mês', 'antecipado' => null, 'risco' => true];
    }
    $fixed = (int) round($f['cartao_fixo'] * 100);
    for ($n = 1; $n <= $f['cartao_max']; $n++) {
        $pct = asaas_card_pct($n, $f);
        // A taxa é repassada: o cliente paga o suficiente para o escritório receber o valor cheio.
        $gross = (int) ceil(($value + $fixed) / (1 - $pct));
        if ($n > 1 && intdiv($gross, $n) < $min) {
            break;
        }
        $fee = (int) round($gross * $pct) + $fixed;
        $net = $gross - $fee;
        $antecipCost = (int) round($net * $f['antecipacao'] / 100 * ($n + 1) / 2);
        $rows[] = ['key' => 'cartao_' . $n, 'grupo' => 'cartao', 'sub' => 'cartao', 'label' => $n === 1 ? 'Cartão à vista' : 'Cartão em ' . $n . 'x', 'n' => $n,
            'cliente' => $gross, 'parcela' => (int) round($gross / $n), 'liquido' => $net, 'taxas' => $fee,
            'recebe' => $n === 1 ? 'em 32 dias' : $n . ' repasses mensais (o 1º em 32 dias)', 'antecipado' => $net - $antecipCost, 'pct' => $pct * 100];
    }
    return $rows;
}

/** Valor de partida do simulador: padrão, sugestão da IA ou o que está na proposta. [centavos, rótulo]. */
function sales_sim_base(string $base, array $d, ?int $caseId): array
{
    if ($caseId && $base === 'padrao') {
        return [pricing_standard($caseId)['valor'], 'precificação padrão'];
    }
    if ($caseId && $base === 'ia' && ($ia = pricing_ia($caseId))) {
        return [$ia, 'sugestão da IA'];
    }
    return [proposal_totals($d)['agora'], 'valor da proposta'];
}

function fmt_pct(float $v): string
{
    return rtrim(rtrim(number_format($v, 2, ',', '.'), '0'), ',') . '%';
}

/** Opções do simulador vindas do formulário (não fazem parte da proposta). */
function sales_sim_options(array $in, array $d, ?int $caseId = null): array
{
    $p = $d['pagamento'];
    $cur = sales_sim_current_key($d);
    $meioCur = str_starts_with($cur, 'cartao') ? 'cartao' : (str_starts_with($cur, 'boleto') ? 'boleto' : 'pix');
    // Sem escolha: o modo da proposta; sem valor na proposta, a precificação padrão.
    $base = in_array($in['sim_base'] ?? '', ['padrao', 'ia', 'proposta'], true) ? (string) $in['sim_base']
        : (in_array($d['precificacao']['modo'] ?? '', ['padrao', 'ia'], true) ? $d['precificacao']['modo'] : ($caseId && !proposal_totals($d)['agora'] ? 'padrao' : 'proposta'));
    $valor = money_cents((string) ($in['sim_valor'] ?? ''));
    [$auto, $rot] = sales_sim_base($base, $d, $caseId);
    return [
        'valor' => $valor ?: $auto,
        'valor_manual' => (bool) $valor,
        'base' => $base,
        'base_rotulo' => $valor ? 'valor digitado' : $rot,
        'meio' => in_array($in['sim_meio'] ?? '', ['pix', 'boleto', 'cartao'], true) ? (string) $in['sim_meio'] : $meioCur,
        'pix' => in_array($in['sim_pix'] ?? '', ['avista', 'parcelado', 'recorrente'], true) ? (string) $in['sim_pix']
            : (str_starts_with($cur, 'pixrec') ? 'recorrente' : (str_starts_with($cur, 'pixparc') ? 'parcelado' : 'avista')),
        'repasse' => true,
        'desconto' => (float) str_replace(',', '.', (string) ($in['sim_desconto'] ?? '0')),
        'parcelas_proprio' => (int) ($p['parcelas'] ?? 6),
    ];
}

/** Linha do simulador que corresponde à condição escolhida na proposta. */
function sales_sim_current_key(array $d): string
{
    $p = $d['pagamento'];
    if ($p['condicao'] === 'avista') {
        return in_array('boleto', $p['formas'], true) && !in_array('pix', $p['formas'], true) ? 'boleto' : 'pix';
    }
    if ($p['modalidade'] === 'cartao') {
        return 'cartao_' . max(1, (int) $p['cartao_parcelas']);
    }
    $n = max(2, (int) $p['parcelas']);
    return match ($p['meio'] ?? 'pix') { 'boleto' => 'boletoparc_' . $n, 'pix_recorrente' => 'pixrec_' . $n, default => 'pixparc_' . $n };
}

/**
 * Conferência no próprio Asaas (simulador de vendas da API, POST /payments/simulate).
 * Devolve as linhas que a API informar, sem supor o formato exato da resposta.
 */
function asaas_simulate_api(int $value, int $installments): array
{
    $r = asaas_request('POST', '/payments/simulate', ['value' => round($value / 100, 2), 'installmentCount' => max(1, $installments), 'billingTypes' => ['BOLETO', 'CREDIT_CARD', 'PIX']]);
    if (!$r['ok']) {
        return ['ok' => false, 'error' => $r['error'], 'rows' => []];
    }
    $labels = ['bankSlip' => 'Boleto', 'creditCard' => 'Cartão de crédito', 'pix' => 'PIX', 'BOLETO' => 'Boleto', 'CREDIT_CARD' => 'Cartão de crédito', 'PIX' => 'PIX'];
    $names = ['value' => 'valor', 'netValue' => 'você recebe', 'feeValue' => 'taxa', 'feePercentage' => 'taxa (%)', 'operationFee' => 'taxa fixa',
        'installmentValue' => 'valor da parcela', 'paymentValue' => 'valor da parcela', 'paymentNetValue' => 'líquido da parcela', 'installmentCount' => 'parcelas', 'totalValue' => 'total'];
    $rows = [];
    $walk = function (array $node, string $prefix) use (&$walk, &$rows, $names) {
        foreach ($node as $k => $v) {
            if (is_array($v)) {
                $walk($v, $prefix . (is_int($k) ? '' : ($names[$k] ?? $k) . ' · '));
            } elseif (is_numeric($v)) {
                $rows[] = [$prefix . ($names[$k] ?? $k), (float) $v, str_contains(strtolower((string) $k), 'percent') || $k === 'feePercentage'];
            }
        }
    };
    $out = [];
    foreach ($r['data'] as $k => $v) {
        if (is_array($v)) {
            $rows = [];
            $walk($v, '');
            if ($rows) {
                $out[] = ['tipo' => $labels[$k] ?? (string) $k, 'itens' => $rows];
            }
        }
    }
    return ['ok' => true, 'error' => null, 'rows' => $out];
}

/* ---------- Tabela da OAB e guia de preço ---------- */

const OAB_SERVICES_DEFAULT = [
    ['key' => 'consulta', 'label' => 'Consulta ou parecer', 'valor' => null],
    ['key' => 'extrajudicial', 'label' => 'Atuação extrajudicial (notificação e negociação com o banco)', 'valor' => null],
    ['key' => 'revisional', 'label' => 'Ação revisional de contrato bancário', 'valor' => null],
    ['key' => 'busca_apreensao', 'label' => 'Defesa em ação de busca e apreensão', 'valor' => null],
    ['key' => 'exibicao', 'label' => 'Ação de exibição de documentos', 'valor' => null],
    ['key' => 'prestacao_contas', 'label' => 'Prestação de contas da venda do veículo', 'valor' => null],
];

/** Itens da tabela de honorários da OAB usados como piso (valores em centavos). */
function oab_table(): array
{
    $t = json_decode((string) setting('oab_tabela', ''), true);
    return is_array($t) && $t ? $t : OAB_SERVICES_DEFAULT;
}

function oab_table_filled(): bool
{
    return (bool) array_filter(oab_table(), fn($i) => (int) ($i['valor'] ?? 0) > 0);
}

function indicacao_pct(): float
{
    $v = (float) str_replace(',', '.', (string) setting('indicacao_pct', '30'));
    return max(0.0, min(200.0, $v));
}

function proposal_guide_defaults(): array
{
    return ['servicos' => [], 'indicacao' => null, 'indicado_por' => '', 'base_manual' => null, 'notas' => ''];
}

function proposal_guide(int $caseId): array
{
    $r = one('SELECT data FROM proposal_guides WHERE case_id = ?', [$caseId]);
    return array_replace(proposal_guide_defaults(), $r ? (json_decode((string) $r['data'], true) ?: []) : []);
}

function proposal_guide_from_input(array $in): array
{
    $keys = array_column(oab_table(), 'key');
    $ind = (string) ($in['indicacao'] ?? '');
    return [
        'servicos' => array_values(array_intersect($keys, (array) ($in['servicos'] ?? []))),
        'indicacao' => $ind === 'sim' ? true : ($ind === 'nao' ? false : null),
        'indicado_por' => trim(mb_substr((string) ($in['indicado_por'] ?? ''), 0, 120)),
        'base_manual' => money_cents((string) ($in['base_manual'] ?? '')),
        'notas' => trim(mb_substr(str_replace("\r\n", "\n", (string) ($in['notas'] ?? '')), 0, 2000)),
    ];
}

function proposal_guide_save(int $caseId, array $g, ?int $userId): void
{
    q('INSERT INTO proposal_guides (case_id, data, updated_at, updated_by) VALUES (?,?,?,?)
       ON CONFLICT(case_id) DO UPDATE SET data = excluded.data, updated_at = excluded.updated_at, updated_by = excluded.updated_by',
        [$caseId, json_encode($g, JSON_UNESCAPED_UNICODE), now_str(), $userId]);
}

/** Referências de preço do caso: a precificação padrão (5% do veículo, mínimo por processo). */
function guide_prices(array $g, ?int $caseId = null): array
{
    $std = $caseId ? pricing_standard($caseId) : null;
    return ['itens' => [], 'sem_valor' => [], 'base' => $std['valor'] ?? null, 'manual' => false, 'pct' => 0.0, 'referencia' => $std['valor'] ?? null, 'padrao' => $std];
}

/* ---------- Avaliação pela IA ---------- */

/** Campos que nunca vão para a avaliação: identificação, contato, endereço e dados sem relação com consumo. */
const NEGOTIATION_BLOCKED_FIELDS = ['nome', 'nome_completo', 'telefone', 'email', 'cpf', 'identificacao', 'genero', 'estado_civil', 'nacionalidade',
    'cep', 'logradouro', 'numero', 'complemento', 'bairro', 'titular_nome', 'credor_cnpj', 'contrato_numero', 'veiculo_placa', 'veiculo_renavam',
    'testemunha1_nome', 'testemunha1_cpf', 'testemunha2_nome', 'testemunha2_cpf', 'adaptacao', 'reuniao_disponibilidade'];

/** Fatos do atendimento para a avaliação (somente deste cliente). */
function negotiation_context(array $c, array $d, array $g): array
{
    $id = (int) $c['id'];
    $fatos = [];
    foreach (case_fields($id, true) as $k => $row) {
        if (in_array($k, NEGOTIATION_BLOCKED_FIELDS, true) || str_starts_with($k, 'proposta_') || str_starts_with($k, 'procuracao_')) {
            continue;
        }
        $v = trim(field_display($k, $row));
        if ($v !== '' && $v !== '—') {
            $fatos[field_def($k)['label'] ?? $k] = mb_strimwidth($v, 0, 400, '…');
        }
    }
    $msgs = array_reverse(all("SELECT body FROM chat_messages WHERE case_id = ? AND author = 'client' ORDER BY id DESC LIMIT 25", [$id]));
    $docs = array_column(all("SELECT label, status FROM documents WHERE case_id = ?", [$id]), 'status', 'label');
    $prices = guide_prices($g, $id);
    $std = $prices['padrao'];
    $t = proposal_totals($d);
    $f = asaas_fees();
    return [
        'fatos' => $fatos,
        'mensagens_do_cliente' => array_map(fn($m) => mb_strimwidth(preg_replace('/\s+/u', ' ', (string) $m['body']), 0, 300, '…'), $msgs),
        'documentos' => $docs,
        'atendimento' => [
            'urgencia' => $c['urgency_signal'] ?? null,
            'aberto_em' => substr((string) $c['created_at'], 0, 10),
            'origem' => $c['source'] ?? null,
            // anotação do assistente; escala em que "alta" = tem condições de contratar
            'capacidade_contratacao' => case_data($c, 'capacidade'),
        ],
        'guia' => [
            'preco_padrao' => $std ? $std['valor'] / 100 : null,
            'regra_padrao' => $std ? fmt_pct($std['pct']) . ' do valor do veículo, mínimo de R$ ' . number_format($std['minimo'] / 100, 2, ',', '.') . ' por processo, mais ' . fmt_pct($std['exito']) . ' de êxito sobre a economia total' : null,
            'calculo' => $std['nota'] ?? null,
            'notas_de_sidney' => $g['notas'],
        ],
        'proposta_atual' => [
            'honorarios' => $t['total'] / 100,
            'exito_pct' => $d['exito']['ativo'] ? $d['exito']['percentual'] : null,
            'condicao' => $d['pagamento']['condicao'] === 'avista' ? 'à vista' : ($d['pagamento']['modalidade'] === 'cartao' ? 'cartão em ' . $d['pagamento']['cartao_parcelas'] . 'x' : 'parcelado próprio em ' . $d['pagamento']['parcelas'] . 'x'),
        ],
        'custos_de_recebimento' => [
            'pix_a_vista' => 'direto na chave, sem taxa', 'cartao_ate_6x_pct' => $f['cartao_2_6'], 'cartao_7_a_12x_pct' => $f['cartao_7_12'], 'cartao' => 'taxa repassada ao cliente, parcela mínima de R$ 500',
            'parcelado_proprio' => 'parcelas fixas com juros de 1% a.m., boleto ou PIX, risco de inadimplência do escritório',
            'facilidades' => 'desconto em % e parte (ou tudo) para receber ao final do processo, corrigida pelo INPC + 1% a.m., devida mesmo sem êxito',
        ],
    ];
}

function negotiation_system_prompt(): string
{
    return implode("\n", [
        'Você avalia o preço de honorários para Sidney Fatuche, advogado, numa negociação de financiamento de veículo. É um guia interno só para ele; o cliente nunca vê.',
        'Como pensar:',
        '1. Referência de Sidney: a precificação padrão (preco_padrao: 5% do valor do veículo, com mínimo de R$ 3.500 por processo, mais 10% de êxito sobre a economia total). É ponto de partida, não limite: sugira acima ou abaixo se o perfil indicar, e diga se cabe desconto ou deixar parte para o final do processo.',
        '2. Leia o padrão de comportamento de consumo do cliente: renda e comprometimento com a parcela do financiamento, valor do veículo e do contrato, prazo que costuma aceitar, histórico de atrasos, urgência (aviso, busca e apreensão, processo), o que quer resolver primeiro e como se expressa nas mensagens.',
        '3. Diga se é possível e necessário aplicar um valor maior ou menor para alinhar a proposta aos hábitos de consumo dele, e qual forma e prazo de pagamento ele tende a aceitar (à vista com desconto, cartão parcelado, entrada mais parcelas).',
        'Não use gênero, estado civil, origem, religião, raça ou qualquer critério discriminatório. Não invente dados: se faltar informação, diga o que falta e reduza a confiança. Considere o custo de receber: o cartão tem taxa e o parcelamento próprio tem risco de inadimplência.',
        'Responda só com JSON, sem texto fora dele, neste formato (valores em reais, números sem símbolo):',
        '{"perfil_consumo":"2 a 4 frases","sinais":[{"sinal":"...","efeito":"aumenta|reduz|neutro","peso":"baixo|medio|alto"}],"capacidade_pagamento":"1 a 2 frases","ajuste_percentual":0,"preco_sugerido":0,"faixa":{"minimo":0,"maximo":0},"pagamento":{"forma":"pix|boleto|cartao|parcelado_proprio","entrada":0,"parcelas":1,"valor_parcela":0,"justificativa":"..."},"exito_percentual":null,"argumentos":["..."],"concessoes":["em ordem, do que ceder primeiro"],"alertas":["..."],"faltam_dados":["..."],"confianca":"baixa|media|alta","resumo":"1 frase com a recomendação"}',
    ]);
}

/** Pede a avaliação à IA, confere os números e grava no histórico. @return array{0:bool,1:array|string} */
function negotiation_evaluate(array $c, array $d, array $g, ?int $userId): array
{
    if (!llm_enabled()) {
        return [false, 'A IA está desligada ou sem chave (Configurações › Inteligência artificial).'];
    }
    $prices = guide_prices($g, (int) $c['id']);
    $ctx = negotiation_context($c, $d, $g);
    $res = llm_chat([
        ['role' => 'system', 'content' => negotiation_system_prompt()],
        ['role' => 'user', 'content' => json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)],
    ], (int) $c['id'], 'negociacao', 1800, 120);
    $j = $res['ok'] ? llm_json($res['content']) : null;
    if (!$j) {
        return [false, 'A IA não respondeu no formato esperado' . ($res['error'] ? ': ' . $res['error'] : '') . '. Tente de novo.'];
    }
    $j = negotiation_normalize($j, $prices);
    if (!$j['preco_sugerido']) {
        return [false, 'A IA não chegou a um valor. Complete a ficha (valor do veículo) e tente de novo.'];
    }
    insert('proposal_evaluations', [
        'case_id' => $c['id'], 'user_id' => $userId, 'kind' => 'preco', 'input' => json_encode($ctx, JSON_UNESCAPED_UNICODE), 'result' => json_encode($j, JSON_UNESCAPED_UNICODE),
        'model' => llm_provider() . ':' . llm_model(), 'created_at' => now_str(),
    ]);
    add_event((int) $c['id'], 'negociacao', 'Avaliação de preço pela IA (guia interno)', 'Sugestão: ' . brl($j['preco_sugerido']) . '.', false);
    return [true, $j];
}

/** Números da IA em centavos e listas limpas. A sugestão é livre: as referências da OAB e da indicação não a limitam. */
function negotiation_normalize(array $j, array $prices): array
{
    $cents = fn($v) => is_numeric($v) && (float) $v >= 0 ? (int) round(((float) $v) * 100) : null;
    $ref = (int) ($prices['referencia'] ?? 0);
    $ajuste = is_numeric($j['ajuste_percentual'] ?? null) ? (float) $j['ajuste_percentual'] : 0.0;
    $preco = $cents($j['preco_sugerido'] ?? null) ?: ($ref ? (int) round($ref * (1 + $ajuste / 100)) : 0);
    $min = $cents($j['faixa']['minimo'] ?? null) ?? $preco;
    $top = $cents($j['faixa']['maximo'] ?? null) ?? $preco;
    $pag = (array) ($j['pagamento'] ?? []);
    $forma = in_array($pag['forma'] ?? '', ['pix', 'boleto', 'cartao', 'parcelado_proprio'], true) ? $pag['forma'] : 'pix';
    $list = fn($v) => array_values(array_filter(array_map(fn($x) => is_string($x) ? trim(mb_substr($x, 0, 400)) : '', (array) $v)));
    $sinais = [];
    foreach ((array) ($j['sinais'] ?? []) as $s) {
        if (is_array($s) && trim((string) ($s['sinal'] ?? '')) !== '') {
            $sinais[] = ['sinal' => mb_substr(trim((string) $s['sinal']), 0, 300), 'efeito' => in_array($s['efeito'] ?? '', ['aumenta', 'reduz', 'neutro'], true) ? $s['efeito'] : 'neutro',
                'peso' => in_array($s['peso'] ?? '', ['baixo', 'medio', 'alto'], true) ? $s['peso'] : 'medio'];
        }
    }
    return [
        'preco_sugerido' => $preco,
        'ajuste_percentual' => $ref && $preco ? round(($preco / $ref - 1) * 100, 1) : $ajuste,
        'faixa' => ['minimo' => min($min, $preco), 'maximo' => max($top, $preco)],
        'piso' => (int) ($prices['base'] ?? 0),
        'referencia' => $ref,
        'pagamento' => [
            'forma' => $forma,
            'entrada' => max(0, min($preco ?: PHP_INT_MAX, $cents($pag['entrada'] ?? 0) ?? 0)),
            'parcelas' => max(1, min($forma === 'cartao' ? 21 : 60, (int) ($pag['parcelas'] ?? 1))),
            'valor_parcela' => $cents($pag['valor_parcela'] ?? null),
            'justificativa' => mb_substr(trim((string) ($pag['justificativa'] ?? '')), 0, 600),
        ],
        'exito_percentual' => is_numeric($j['exito_percentual'] ?? null) ? max(0, min(100, (float) $j['exito_percentual'])) : null,
        'perfil_consumo' => mb_substr(trim((string) ($j['perfil_consumo'] ?? '')), 0, 1200),
        'capacidade_pagamento' => mb_substr(trim((string) ($j['capacidade_pagamento'] ?? '')), 0, 600),
        'sinais' => array_slice($sinais, 0, 10),
        'argumentos' => array_slice($list($j['argumentos'] ?? []), 0, 8),
        'concessoes' => array_slice($list($j['concessoes'] ?? []), 0, 6),
        'alertas' => array_slice($list($j['alertas'] ?? []), 0, 6),
        'faltam_dados' => array_slice($list($j['faltam_dados'] ?? []), 0, 8),
        'confianca' => in_array($j['confianca'] ?? '', ['baixa', 'media', 'alta'], true) ? $j['confianca'] : 'media',
        'resumo' => mb_substr(trim((string) ($j['resumo'] ?? '')), 0, 400),
    ];
}

function negotiation_history(int $caseId, int $limit = 5): array
{
    return array_map(function ($r) {
        $r['result'] = json_decode((string) $r['result'], true) ?: [];
        return $r;
    }, all("SELECT e.*, u.name AS user_name FROM proposal_evaluations e LEFT JOIN users u ON u.id = e.user_id WHERE e.case_id = ? AND e.kind = 'preco' ORDER BY e.id DESC LIMIT " . max(1, $limit), [$caseId]));
}

/** Perfil do cliente em poucas linhas para o topo do painel. */
function negotiation_profile(int $caseId): array
{
    $show = ['objetivo_prioridade', 'aviso', 'situacao_contrato', 'atraso_qtd', 'banco', 'valor_veiculo', 'capital', 'parcela', 'prazo', 'parcelas_pagas',
        'saldo_cobrado', 'profissao', 'renda_valor', 'renda_atividade', 'cidade'];
    $f = case_fields($caseId);
    $out = [];
    foreach ($show as $k) {
        if (isset($f[$k])) {
            $v = trim(field_display($k, $f[$k]));
            if ($v !== '' && $v !== '—') {
                $out[field_def($k)['label']] = $v;
            }
        }
    }
    $veic = trim(implode(' ', array_filter([fv($caseId, 'veiculo_marca'), fv($caseId, 'veiculo_modelo'), fv($caseId, 'veiculo_ano')])));
    if ($veic !== '') {
        $out = ['Veículo' => $veic] + $out;
    }
    $cap = case_data(find_case($caseId), 'capacidade');
    if ($cap) {
        $out['Capacidade de contratação'] = (CAPACITY_LEVELS[$cap['nivel']] ?? $cap['nivel']) . ($cap['obs'] ? ' — ' . $cap['obs'] : '');
    }
    $renda = fv($caseId, 'renda_valor');
    $parcela = fv($caseId, 'parcela');
    if ($renda && $parcela && (int) $renda > 0) {
        $out['Parcela sobre a renda'] = fmt_pct(((int) $parcela) / ((int) $renda) * 100);
    }
    return $out;
}
