<?php
declare(strict_types=1);

/**
 * Propostas: as condições negociadas com o cliente em cada atendimento.
 *
 * Honorários fixos (valor fechado), honorários contratuais por atividade,
 * êxito em percentual, valores cobrados à parte (taxas, emolumentos, custas,
 * impostos), repasse de deslocamento e de despesas, formas de pagamento e
 * parcelamento (próprio, com juros simples de 1% a.m. e INPC, ou no cartão,
 * com a taxa da operadora por conta do cliente).
 *
 * Cada escolha vira variáveis e condições ({@se …}) dos modelos: o contrato
 * inclui ou exclui as cláusulas correspondentes.
 */

const PAYMENT_METHODS = [
    'pix'            => 'PIX',
    'boleto'         => 'boleto bancário',
    'credito'        => 'cartão de crédito',
    'debito'         => 'cartão de débito',
    'transferencia'  => 'transferência bancária (TED)',
    'deposito'       => 'depósito bancário',
    'link'           => 'link de pagamento',
    'dinheiro'       => 'dinheiro',
    'cheque'         => 'cheque',
];

const COST_TYPES = [
    'taxa_servico'  => 'Taxa de serviço',
    'emolumentos'   => 'Emolumentos de cartório',
    'custas'        => 'Custas judiciais',
    'pericia'       => 'Perícia ou assistente técnico',
    'imposto'       => 'Impostos',
    'outro'         => 'Outros custos',
];

const PROPOSAL_INTEREST = 0.01; // juros simples de 1% ao mês no parcelamento próprio

/** Base de cálculo sugerida para o êxito (Sidney ajusta na proposta). */
const PROPOSAL_EXITO_BASE = 'o benefício econômico efetivamente obtido: valores restituídos ou compensados e redução definitiva do saldo devedor';

/** Trechos das cláusulas que a proposta escreve e que podem ser ajustados à mão. */
const PROPOSAL_TEXTS = [
    'honorarios' => 'Cláusula da remuneração',
    'pagamento'  => 'Cláusula do pagamento',
];

function proposal_defaults(): array
{
    return [
        'fixos' => ['ativo' => false, 'valor' => null, 'descricao' => ''],
        'atividades' => [],
        'exito' => ['ativo' => false, 'percentual' => '', 'base' => PROPOSAL_EXITO_BASE],
        'custos' => [],
        // Deslocamento e despesas antecipadas são sempre reembolsados, mediante comprovação.
        'deslocamento' => ['repassa' => true, 'criterio' => 'comprovacao', 'valor_km' => null],
        'despesas' => ['repassa' => true],
        'pagamento' => ['formas' => ['pix'], 'condicao' => 'avista', 'vencimento' => null, 'entrada' => null, 'entrada_vencimento' => null,
            'parcelas' => 2, 'primeiro_vencimento' => null, 'modalidade' => 'proprio', 'cartao_parcelas' => 2, 'observacoes' => ''],
        'validade' => null,
        // Texto ajustado à mão (null = texto automático, refeito a cada mudança)
        'texto' => ['honorarios' => null, 'pagamento' => null],
        // Texto automático da época do ajuste: avisa quando os valores mudaram depois
        'texto_ref' => ['honorarios' => null, 'pagamento' => null],
    ];
}

function proposal_get(int $caseId): ?array
{
    $r = one('SELECT * FROM proposals WHERE case_id = ?', [$caseId]);
    if (!$r) {
        return null;
    }
    $r['data'] = array_replace_recursive(proposal_defaults(), json_decode((string) $r['data'], true) ?: []);
    // Regras fixas também para propostas gravadas antes delas.
    $r['data']['deslocamento'] = ['repassa' => true, 'criterio' => 'comprovacao', 'valor_km' => null];
    $r['data']['despesas']['repassa'] = true;
    $r['data']['validade'] = null;
    return $r;
}

/* ---------- Valores ---------- */

/** "1.234,56" → centavos (null quando vazio ou inválido). */
function money_cents(?string $raw): ?int
{
    $raw = trim((string) $raw);
    if ($raw === '') {
        return null;
    }
    if (preg_match('/^(R\$\s*)?\d{1,3}(\.\d{3})+$/u', $raw)) {
        $raw = str_replace('.', '', $raw);
    }
    $v = parse_money($raw);
    return $v === null || $v < 0 ? null : (int) round($v * 100);
}

function brl(?int $cents): string
{
    return $cents === null ? '' : 'R$ ' . number_format($cents / 100, 2, ',', '.');
}

function extenso_ate_999(int $n): string
{
    $u = ['', 'um', 'dois', 'três', 'quatro', 'cinco', 'seis', 'sete', 'oito', 'nove', 'dez', 'onze', 'doze', 'treze', 'quatorze', 'quinze', 'dezesseis', 'dezessete', 'dezoito', 'dezenove'];
    $d = ['', '', 'vinte', 'trinta', 'quarenta', 'cinquenta', 'sessenta', 'setenta', 'oitenta', 'noventa'];
    $c = ['', 'cento', 'duzentos', 'trezentos', 'quatrocentos', 'quinhentos', 'seiscentos', 'setecentos', 'oitocentos', 'novecentos'];
    if ($n === 100) {
        return 'cem';
    }
    $parts = [];
    if ($n >= 100) {
        $parts[] = $c[intdiv($n, 100)];
        $n %= 100;
    }
    if ($n >= 20) {
        $parts[] = $d[intdiv($n, 10)];
        $n %= 10;
    }
    if ($n > 0) {
        $parts[] = $u[$n];
    }
    return implode(' e ', $parts);
}

/** Número inteiro por extenso (até bilhões). */
function numero_extenso(int $n): string
{
    if ($n === 0) {
        return 'zero';
    }
    $grupos = [];
    foreach ([[1000000000, 'bilhão', 'bilhões'], [1000000, 'milhão', 'milhões'], [1000, 'mil', 'mil'], [1, '', '']] as [$div, $sing, $plur]) {
        $q = intdiv($n, $div);
        $n %= $div;
        if ($q === 0) {
            continue;
        }
        $txt = ($div === 1000 && $q === 1) ? 'mil' : extenso_ate_999($q) . ($sing !== '' ? ' ' . ($q === 1 ? $sing : $plur) : '');
        $grupos[] = [$txt, $q, $div];
    }
    $out = '';
    foreach ($grupos as $i => [$txt, $q, $div]) {
        if ($i > 0) {
            // "e" antes do último grupo quando ele é menor que 100 ou centena redonda
            $last = $i === count($grupos) - 1;
            $out .= ($last && ($q < 100 || $q % 100 === 0)) ? ' e ' : ', ';
        }
        $out .= $txt;
    }
    return $out;
}

/** "R$ 2.400,00 (dois mil e quatrocentos reais)". */
function brl_extenso(int $cents): string
{
    $reais = intdiv($cents, 100);
    $cent = $cents % 100;
    $parts = [];
    if ($reais > 0) {
        $w = numero_extenso($reais);
        $parts[] = $w . (preg_match('/(milhão|milhões|bilhão|bilhões)$/u', $w) ? ' de' : '') . ($reais === 1 ? ' real' : ' reais');
    }
    if ($cent > 0) {
        $parts[] = numero_extenso($cent) . ($cent === 1 ? ' centavo' : ' centavos');
    }
    return brl($cents) . ' (' . ($parts ? implode(' e ', $parts) : 'zero real') . ')';
}

/** "20" → "20% (vinte por cento)"; aceita decimais ("12,5"). */
function percent_extenso(string $raw): string
{
    $v = parse_money(str_replace('%', '', $raw));
    if ($v === null) {
        return trim($raw);
    }
    $int = (int) floor($v);
    $dec = (int) round(($v - $int) * 10);
    $num = $dec ? $int . ',' . $dec : (string) $int;
    $txt = numero_extenso($int) . ($dec ? ' inteiros e ' . numero_extenso($dec) . ' décimos' : '');
    return $num . '% (' . $txt . ' por cento)';
}

function ordinal_feminino(int $n): string
{
    $u = ['', 'primeira', 'segunda', 'terceira', 'quarta', 'quinta', 'sexta', 'sétima', 'oitava', 'nona'];
    $d = ['', 'décima', 'vigésima', 'trigésima', 'quadragésima', 'quinquagésima'];
    return trim($d[intdiv($n, 10)] . ' ' . $u[$n % 10]);
}

function ordinal_masculino(int $n): string
{
    $u = ['', 'primeiro', 'segundo', 'terceiro', 'quarto', 'quinto', 'sexto', 'sétimo', 'oitavo', 'nono'];
    $d = ['', 'décimo', 'vigésimo', 'trigésimo'];
    return trim($d[intdiv($n, 10)] . ' ' . $u[$n % 10]);
}

/* ---------- Totais e parcelas ---------- */

function proposal_totals(array $d): array
{
    $fixos = $d['fixos']['ativo'] ? (int) ($d['fixos']['valor'] ?? 0) : 0;
    $ativ = array_sum(array_map(fn($a) => (int) ($a['valor'] ?? 0), $d['atividades']));
    $total = $fixos + $ativ;
    $p = $d['pagamento'];
    $entrada = $p['condicao'] === 'parcelado' ? min((int) ($p['entrada'] ?? 0), $total) : 0;
    return ['fixos' => $fixos, 'atividades' => $ativ, 'total' => $total, 'entrada' => $entrada, 'saldo' => $total - $entrada];
}

function add_months(string $ymd, int $months): string
{
    $d = new DateTimeImmutable($ymd);
    $day = (int) $d->format('j');
    $first = $d->modify('first day of this month')->modify("+$months month");
    $last = (int) $first->format('t');
    return $first->setDate((int) $first->format('Y'), (int) $first->format('n'), min($day, $last))->format('Y-m-d');
}

/**
 * Parcelamento próprio: o saldo é dividido em parcelas iguais e cada uma
 * recebe juros simples de 1% por mês contado da contratação até o seu
 * vencimento (1ª parcela = 1 mês, 2ª = 2 meses…). A atualização pelo INPC
 * é aplicada na data de cada pagamento e não entra no quadro.
 */
function proposal_schedule(array $d): array
{
    $t = proposal_totals($d);
    $p = $d['pagamento'];
    if ($p['condicao'] !== 'parcelado' || $t['saldo'] <= 0) {
        return [];
    }
    $n = max(1, (int) ($p['modalidade'] === 'cartao' ? $p['cartao_parcelas'] : $p['parcelas']));
    $base = intdiv($t['saldo'], $n);
    $rest = $t['saldo'] - $base * $n;
    $out = [];
    for ($k = 1; $k <= $n; $k++) {
        $principal = $base + ($k === $n ? $rest : 0);
        $juros = $p['modalidade'] === 'proprio' ? (int) round($principal * PROPOSAL_INTEREST * $k) : 0;
        $out[] = ['n' => $k, 'principal' => $principal, 'juros' => $juros, 'valor' => $principal + $juros,
            'vencimento' => $p['primeiro_vencimento'] ? add_months($p['primeiro_vencimento'], $k - 1) : null];
    }
    return $out;
}

/* ---------- Gravação ---------- */

/**
 * Monta e confere a proposta a partir do formulário. Retorna [dados, erros].
 *
 * Os textos das cláusulas (texto_honorarios, texto_pagamento) só viram ajuste
 * manual quando diferem do texto que a tela mostrou (texto_*_base) ou quando
 * já eram manuais: assim, mudar um valor sem tocar no texto refaz a cláusula.
 */
function proposal_from_input(array $in): array
{
    $d = proposal_defaults();
    $err = [];
    $txt = fn($v, int $max = 500) => trim(mb_substr(str_replace("\r\n", "\n", (string) $v), 0, $max));

    $d['fixos']['valor'] = money_cents($in['fixos_valor'] ?? '');
    $d['fixos']['ativo'] = (bool) $d['fixos']['valor'];
    $d['fixos']['descricao'] = $txt($in['fixos_descricao'] ?? '');
    foreach ((array) ($in['atividade_descricao'] ?? []) as $i => $desc) {
        $desc = $txt($desc, 300);
        $val = money_cents($in['atividade_valor'][$i] ?? '');
        if ($desc === '' && $val === null) {
            continue;
        }
        if ($desc === '' || !$val) {
            $err[] = 'Cada atividade precisa de descrição e valor (linha ' . ($i + 1) . ').';
            continue;
        }
        $d['atividades'][] = ['descricao' => $desc, 'valor' => $val];
    }
    $d['exito']['ativo'] = !empty($in['exito_ativo']);
    $d['exito']['percentual'] = $txt($in['exito_percentual'] ?? '', 10);
    $d['exito']['base'] = $txt($in['exito_base'] ?? '', 1000) ?: PROPOSAL_EXITO_BASE;
    if ($d['exito']['ativo']) {
        $pc = parse_money(str_replace('%', '', $d['exito']['percentual']));
        if ($pc === null || $pc <= 0 || $pc > 100) {
            $err[] = 'Informe o percentual de êxito (por exemplo, 20).';
        }
    }
    foreach ((array) ($in['custo_tipo'] ?? []) as $i => $tipo) {
        $desc = $txt($in['custo_descricao'][$i] ?? '', 300);
        $val = money_cents($in['custo_valor'][$i] ?? '');
        if ($tipo === '' && $desc === '' && $val === null) {
            continue;
        }
        if (!isset(COST_TYPES[$tipo])) {
            $err[] = 'Escolha o tipo do valor cobrado à parte (linha ' . ($i + 1) . ').';
            continue;
        }
        $d['custos'][] = ['tipo' => $tipo, 'descricao' => $desc, 'valor' => $val];
    }
    // Deslocamento e despesas antecipadas: sempre reembolsados mediante comprovação.
    $d['deslocamento'] = ['repassa' => true, 'criterio' => 'comprovacao', 'valor_km' => null];
    $d['despesas']['repassa'] = true;

    $p = &$d['pagamento'];
    $p['formas'] = array_values(array_intersect(array_keys(PAYMENT_METHODS), (array) ($in['formas'] ?? [])));
    $tipo = (string) ($in['pagamento_tipo'] ?? 'avista');
    $p['condicao'] = in_array($tipo, ['proprio', 'cartao'], true) ? 'parcelado' : 'avista';
    $p['modalidade'] = $tipo === 'cartao' ? 'cartao' : 'proprio';
    if ($tipo === 'cartao' && !in_array('credito', $p['formas'], true)) {
        $p['formas'][] = 'credito';
    }
    if (!$p['formas']) {
        $err[] = 'Escolha ao menos uma forma de pagamento.';
    }
    $p['vencimento'] = parse_br_date((string) ($in['vencimento'] ?? '')) ?: null;
    $p['entrada'] = money_cents($in['entrada'] ?? '');
    $p['entrada_vencimento'] = parse_br_date((string) ($in['entrada_vencimento'] ?? '')) ?: null;
    $p['parcelas'] = $p['cartao_parcelas'] = max(1, min($tipo === 'cartao' ? 24 : 60, (int) ($in['parcelas'] ?? 2)));
    $p['primeiro_vencimento'] = parse_br_date((string) ($in['primeiro_vencimento'] ?? '')) ?: null;
    $p['observacoes'] = $txt($in['pagamento_observacoes'] ?? '', 1000);
    if ($tipo === 'proprio' && !$p['primeiro_vencimento']) {
        $err[] = 'Informe o vencimento da primeira parcela.';
    }
    unset($p);
    $d['validade'] = null; // a validade não é exposta ao cliente

    $t = proposal_totals($d);
    if ($t['total'] === 0 && !$d['exito']['ativo']) {
        $err[] = 'Informe ao menos um honorário: valor fixo, por atividade ou êxito.';
    }
    if ($d['pagamento']['condicao'] === 'parcelado' && $t['saldo'] <= 0) {
        $err[] = 'Não há saldo a parcelar: confira os honorários e a entrada.';
    }

    $auto = proposal_auto_texts($d);
    foreach (PROPOSAL_TEXTS as $k => $label) {
        if (!array_key_exists('texto_' . $k, $in)) {
            continue;
        }
        $posted = proposal_norm_text((string) $in['texto_' . $k]);
        $base = proposal_norm_text((string) ($in['texto_' . $k . '_base'] ?? ''));
        $manual = $posted !== $base || ($in['texto_' . $k . '_manual'] ?? '') === '1';
        if ($manual && $posted !== '' && $posted !== proposal_norm_text($auto[$k])) {
            $d['texto'][$k] = mb_substr($posted, 0, 8000);
            $ref = proposal_norm_text((string) ($in['texto_' . $k . '_ref'] ?? ''));
            $d['texto_ref'][$k] = mb_substr($ref !== '' ? $ref : ($base !== '' ? $base : $auto[$k]), 0, 8000);
        }
    }
    return [$d, $err];
}

function proposal_norm_text(string $s): string
{
    $s = preg_replace("/[ \t]+\n/", "\n", str_replace(["\r\n", "\r"], "\n", $s));
    return trim(preg_replace("/\n{3,}/", "\n\n", $s));
}

function proposal_save(int $caseId, array $d, ?int $userId): void
{
    $json = json_encode($d, JSON_UNESCAPED_UNICODE);
    if (one('SELECT id FROM proposals WHERE case_id = ?', [$caseId])) {
        q('UPDATE proposals SET data = ?, updated_at = ?, updated_by = ? WHERE case_id = ?', [$json, now_str(), $userId, $caseId]);
    } else {
        insert('proposals', ['case_id' => $caseId, 'data' => $json, 'created_at' => now_str(), 'updated_at' => now_str(), 'updated_by' => $userId]);
    }
    $t = proposal_totals($d);
    add_event($caseId, 'proposta', 'Proposta registrada', 'Honorários ' . brl($t['total']) . ($d['exito']['ativo'] ? ' + êxito de ' . percent_extenso($d['exito']['percentual']) : '') . '.');
}

/* ---------- Variáveis dos documentos ---------- */

/** Variáveis e condições que a proposta entrega aos modelos. */
function proposal_vars(int $caseId): array
{
    $pr = proposal_get($caseId);
    if (!$pr) {
        // Sem proposta registrada: os modelos seguem com os textos da ficha.
        return ['proposta.sem_cadastro' => '1', 'proposta.tem_fixos' => '1', 'proposta.tem_atividades' => '1', 'proposta.tem_exito' => '1',
            'proposta.pix' => '1', 'proposta.repassa_despesas' => '1'];
    }
    return proposal_vars_data($pr['data']);
}

/** Textos automáticos das cláusulas de remuneração e de pagamento. */
function proposal_auto_texts(array $d): array
{
    $t = proposal_totals($d);
    $p = $d['pagamento'];

    $hon = [];
    if ($d['fixos']['ativo']) {
        $hon[] = '- Honorários fixos: ' . brl_extenso($t['fixos']) . ', valor fechado' . ($d['fixos']['descricao'] !== '' ? ', referente a ' . rtrim($d['fixos']['descricao'], '.') : '') . '.';
    }
    foreach ($d['atividades'] as $a) {
        $hon[] = '- Honorários contratuais por atividade — ' . rtrim($a['descricao'], '.') . ': ' . brl_extenso((int) $a['valor']) . '.';
    }
    if ($d['exito']['ativo']) {
        $hon[] = '- Honorários de êxito: ' . percent_extenso($d['exito']['percentual']) . ' sobre ' . rtrim(lcfirst_pt($d['exito']['base']), '.') . '.';
    }
    if ($t['total'] && ($d['fixos']['ativo'] ? 1 : 0) + count($d['atividades']) > 1) {
        $hon[] = '- Total dos honorários, sem o êxito: ' . brl_extenso($t['total']) . '.';
    }

    $formas = array_map(fn($f) => PAYMENT_METHODS[$f], $p['formas']);
    $lista = count($formas) > 1 ? implode(', ', array_slice($formas, 0, -1)) . ' ou ' . end($formas) : ($formas[0] ?? '');
    $pag = ['Formas de pagamento aceitas: ' . $lista . '.'];
    if ($p['condicao'] === 'avista') {
        $pag[] = 'Pagamento à vista' . ($t['total'] ? ', no valor de ' . brl_extenso($t['total']) : '') . ($p['vencimento'] ? ', com vencimento em ' . fmt_date($p['vencimento']) : '') . '.';
    } else {
        $sched = proposal_schedule($d);
        $n = count($sched);
        $ent = $t['entrada'] ? 'Entrada de ' . brl_extenso($t['entrada']) . ($p['entrada_vencimento'] ? ', com vencimento em ' . fmt_date($p['entrada_vencimento']) : '') . ', e saldo' : 'Valor';
        if ($p['modalidade'] === 'proprio') {
            $pag[] = $ent . ' de ' . brl_extenso($t['saldo']) . ' em ' . $n . ' (' . numero_extenso($n) . ') parcela' . ($n > 1 ? 's mensais' : '') . ', com juros simples de 1% (um por cento) ao mês e atualização monetária pelo INPC/IBGE na data de cada pagamento, conforme o quadro abaixo:';
            $rows = [];
            foreach ($sched as $s) {
                $rows[] = '- ' . $s['n'] . 'ª parcela: ' . brl($s['valor']) . ($s['vencimento'] ? ', vencimento em ' . fmt_date($s['vencimento']) : '') . ' (' . brl($s['principal']) . ' + ' . brl($s['juros']) . ' de juros).';
            }
            $pag[] = implode("\n", $rows);
        } else {
            $pag[] = $ent . ' de ' . brl_extenso($t['saldo']) . ' em até ' . $n . ' (' . numero_extenso($n) . ') parcelas no cartão de crédito, acrescidas da taxa de parcelamento cobrada pela operadora do cartão.';
        }
    }
    if ($p['observacoes'] !== '') {
        $pag[] = $p['observacoes'];
    }

    return ['honorarios' => implode("\n", $hon), 'pagamento' => implode("\n\n", $pag)];
}

/** Variáveis e condições a partir dos dados da proposta (salva ou ainda em edição). */
function proposal_vars_data(array $d): array
{
    $t = proposal_totals($d);
    $p = $d['pagamento'];
    $flag = fn(bool $b) => $b ? '1' : null;
    $auto = proposal_auto_texts($d);
    $formas = array_map(fn($f) => PAYMENT_METHODS[$f], $p['formas']);
    $lista = count($formas) > 1 ? implode(', ', array_slice($formas, 0, -1)) . ' ou ' . end($formas) : ($formas[0] ?? '');

    $custos = [];
    foreach ($d['custos'] as $c) {
        $custos[] = '- ' . COST_TYPES[$c['tipo']] . ($c['descricao'] !== '' ? ' — ' . rtrim($c['descricao'], '.') : '') . ': ' . ($c['valor'] ? 'estimativa de ' . brl($c['valor']) : 'valor a apurar') . '.';
    }
    $desp = $custos ? "Serão cobrados à parte dos honorários, sob responsabilidade do CONTRATANTE, os seguintes valores, pagos diretamente ou reembolsados mediante comprovação:\n\n" . implode("\n", $custos)
        : 'Não há valores previstos para cobrança à parte dos honorários. Custas, emolumentos, impostos ou outros custos que vierem a ser necessários serão informados previamente ao CONTRATANTE.';

    $crit = $d['deslocamento']['criterio'] === 'km'
        ? 'à razão de ' . brl((int) $d['deslocamento']['valor_km']) . ' por quilômetro rodado, além de pedágios e estacionamento, mediante comprovação'
        : 'mediante apresentação dos comprovantes';

    return [
        'proposta.honorarios' => $d['texto']['honorarios'] ?? $auto['honorarios'],
        'proposta.pagamento' => $d['texto']['pagamento'] ?? $auto['pagamento'],
        'proposta.despesas' => $desp,
        'proposta.base_exito' => $d['exito']['ativo'] ? $d['exito']['base'] : null,
        'proposta.percentual_exito' => $d['exito']['ativo'] ? percent_extenso($d['exito']['percentual']) : null,
        'proposta.valor_total' => $t['total'] ? brl_extenso($t['total']) : null,
        'proposta.formas_pagamento' => $lista,
        'proposta.deslocamento_criterio' => $crit,
        'proposta.validade' => $d['validade'] ? fmt_date($d['validade']) : null,
        'proposta.tem_fixos' => $flag($d['fixos']['ativo']),
        'proposta.tem_atividades' => $flag((bool) $d['atividades']),
        'proposta.tem_exito' => $flag($d['exito']['ativo']),
        'proposta.tem_custos' => $flag((bool) $d['custos']),
        'proposta.parcelado' => $flag($p['condicao'] === 'parcelado'),
        'proposta.parcelamento_proprio' => $flag($p['condicao'] === 'parcelado' && $p['modalidade'] === 'proprio'),
        'proposta.parcelamento_cartao' => $flag($p['condicao'] === 'parcelado' && $p['modalidade'] === 'cartao'),
        'proposta.pix' => $flag(in_array('pix', $p['formas'], true)),
        'proposta.repassa_deslocamento' => $flag($d['deslocamento']['repassa']),
        'proposta.nao_repassa_deslocamento' => $flag(!$d['deslocamento']['repassa']),
        'proposta.repassa_despesas' => $flag($d['despesas']['repassa']),
        'proposta.nao_repassa_despesas' => $flag(!$d['despesas']['repassa']),
        'proposta.sem_cadastro' => null,
    ];
}

/** Primeira letra minúscula, salvo sigla ("INPC"). */
function lcfirst_pt(string $s): string
{
    if (preg_match('/^\p{Lu}{2}/u', $s)) {
        return $s;
    }
    return mb_strtolower(mb_substr($s, 0, 1)) . mb_substr($s, 1);
}

/** Rótulos das variáveis e condições da proposta (catálogo e validação dos modelos). */
const PROPOSAL_VAR_LABELS = [
    'proposta.valor_total' => 'Total dos honorários (sem o êxito), por extenso',
    'proposta.formas_pagamento' => 'Formas de pagamento aceitas',
    'proposta.deslocamento_criterio' => 'Critério de reembolso do deslocamento',
    'proposta.tem_fixos' => 'Condição: há honorários fixos',
    'proposta.tem_atividades' => 'Condição: há honorários por atividade',
    'proposta.tem_exito' => 'Condição: há honorários de êxito',
    'proposta.tem_custos' => 'Condição: há valores cobrados à parte',
    'proposta.parcelado' => 'Condição: pagamento parcelado',
    'proposta.parcelamento_proprio' => 'Condição: parcelamento próprio (juros 1% a.m. + INPC)',
    'proposta.parcelamento_cartao' => 'Condição: parcelamento no cartão (taxa da operadora pelo cliente)',
    'proposta.pix' => 'Condição: PIX entre as formas de pagamento',
    'proposta.repassa_deslocamento' => 'Condição: repassa deslocamento',
    'proposta.nao_repassa_deslocamento' => 'Condição: não repassa deslocamento',
    'proposta.repassa_despesas' => 'Condição: repassa despesas',
    'proposta.nao_repassa_despesas' => 'Condição: não repassa despesas',
    'proposta.sem_cadastro' => 'Condição: atendimento sem proposta registrada',
];

/** O que a proposta faz no contrato: cláusulas incluídas e excluídas. */
function proposal_effects(array $d): array
{
    $p = $d['pagamento'];
    $parc = $p['condicao'] === 'parcelado';
    return [
        ['Parágrafo dos honorários fixos (independem do resultado)', $d['fixos']['ativo']],
        ['Parágrafo da distribuição por etapas (honorários por atividade)', (bool) $d['atividades']],
        ['Cláusulas dos honorários de êxito e da sua exigibilidade', $d['exito']['ativo']],
        ['Observação de honorários na procuração', $d['exito']['ativo']],
        ['Parágrafo do parcelamento próprio (juros simples de 1% a.m. e INPC)', $parc && $p['modalidade'] === 'proprio'],
        ['Parágrafo do parcelamento no cartão (taxa da operadora pelo cliente)', $parc && $p['modalidade'] === 'cartao'],
        ['Parágrafo da chave PIX', in_array('pix', $p['formas'], true)],
        ['Lista de valores cobrados à parte', (bool) $d['custos']],
        ['Reembolso de despesas antecipadas', $d['despesas']['repassa']],
        ['Despesas ordinárias não repassadas', !$d['despesas']['repassa']],
        ['Reembolso do deslocamento', $d['deslocamento']['repassa']],
        ['Deslocamento incluído nos honorários', !$d['deslocamento']['repassa']],
    ];
}

/**
 * Prévia das cláusulas de remuneração e de pagamento como sairão no contrato
 * (modelo aprovado, numeração e condições aplicadas), com o ponto em que entra
 * o texto ajustável. Retorna [chave => ['antes' => html, 'depois' => html]].
 */
function proposal_clause_preview(int $caseId, array $d): array
{
    $tpl = doc_template('honorarios');
    if (!$tpl) {
        return [];
    }
    $vars = kit_vars($caseId, $d);
    $mark = [];
    foreach (array_keys(PROPOSAL_TEXTS) as $k) {
        $mark[$k] = '§§' . strtoupper($k) . '§§';
        $vars['proposta.' . $k] = $mark[$k];
    }
    $vars['kit.versao'] = 'prévia';
    [$content] = render_template($tpl['body'], $vars);
    $out = [];
    foreach (preg_split('/^(?=###\s)/mu', $content) as $chunk) {
        $chunk = preg_split('/^(?=##\s)/mu', $chunk)[0];
        foreach ($mark as $k => $m) {
            if (!isset($out[$k]) && str_contains($chunk, $m)) {
                [$antes, $depois] = explode($m, $chunk, 2);
                $html = fn(string $x) => trim($x) === '' ? '' : doc_corpo_html(doc_blocks(trim($x)));
                $out[$k] = ['antes' => $html($antes), 'depois' => $html($depois)];
            }
        }
    }
    return $out;
}

/** Valor em centavos para o campo do formulário ("1.234,56"). */
function cents_input(?int $c): string
{
    return $c === null ? '' : number_format($c / 100, 2, ',', '.');
}
