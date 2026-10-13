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

const PROPOSAL_INTEREST = 0.01; // juros de 1% ao mês no parcelamento próprio (parcelas fixas)

/** Precificação padrão de Sidney (ajustável em Configurações › Preços e negociação). */
const PRICING_DEFAULTS = ['pct_veiculo' => 5.0, 'minimo' => 350000, 'exito' => 10.0, 'cartao_min_parcela' => 50000];

function pricing(): array
{
    $s = json_decode((string) setting('precificacao', '{}'), true) ?: [];
    $out = PRICING_DEFAULTS;
    foreach ($out as $k => $def) {
        if (isset($s[$k]) && is_numeric($s[$k])) {
            $out[$k] = is_int($def) ? (int) $s[$k] : (float) $s[$k];
        }
    }
    return $out;
}

/**
 * Honorários pela regra padrão: 5% do valor do veículo, com mínimo de
 * R$ 3.500,00 por processo, mais 10% de êxito sobre a economia total.
 */
function pricing_standard(int $caseId): array
{
    $pr = pricing();
    $veic = fv($caseId, 'valor_veiculo') !== null ? (int) fv($caseId, 'valor_veiculo') : null;
    $calc = $veic ? (int) round($veic * $pr['pct_veiculo'] / 100) : 0;
    $valor = max($calc, (int) $pr['minimo']);
    return [
        'valor' => $valor, 'veiculo' => $veic, 'calculado' => $calc, 'minimo' => (int) $pr['minimo'], 'pct' => $pr['pct_veiculo'], 'exito' => $pr['exito'],
        'nota' => $veic ? fmt_pct($pr['pct_veiculo']) . ' de ' . brl($veic) . ' (valor do veículo) = ' . brl($calc) . ($calc < $pr['minimo'] ? '; vale o mínimo de ' . brl((int) $pr['minimo']) : '')
            : 'Valor do veículo não informado: vale o mínimo de ' . brl((int) $pr['minimo']),
    ];
}

/** Preço sugerido na última avaliação da IA (centavos), se houver. */
function pricing_ia(int $caseId): ?int
{
    $ev = negotiation_history($caseId, 1)[0] ?? null;
    return $ev ? ((int) ($ev['result']['preco_sugerido'] ?? 0) ?: null) : null;
}

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
            'parcelas' => 2, 'primeiro_vencimento' => null, 'modalidade' => 'proprio', 'meio' => 'pix', 'cartao_parcelas' => 2, 'observacoes' => ''],
        // Como o valor foi definido e as facilidades: desconto e parte para receber ao final do processo.
        'precificacao' => ['modo' => 'manual', 'desconto' => '', 'final_pct' => ''],
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

/** Percentual digitado ("10", "12,5") limitado a 0–100. */
function pct_value(mixed $raw): float
{
    $v = parse_money(str_replace('%', '', (string) $raw));
    return $v === null ? 0.0 : max(0.0, min(100.0, (float) $v));
}

/**
 * Totais: bruto (fixos + atividades), desconto, total contratado, parte a
 * receber ao final do processo e parte paga agora (entrada + saldo).
 */
function proposal_totals(array $d): array
{
    $fixos = $d['fixos']['ativo'] ? (int) ($d['fixos']['valor'] ?? 0) : 0;
    $ativ = array_sum(array_map(fn($a) => (int) ($a['valor'] ?? 0), $d['atividades']));
    $bruto = $fixos + $ativ;
    $pc = $d['precificacao'] ?? [];
    $desc = (int) round($bruto * pct_value($pc['desconto'] ?? '') / 100);
    $total = $bruto - $desc;
    $final = (int) round($total * pct_value($pc['final_pct'] ?? '') / 100);
    $agora = $total - $final;
    $p = $d['pagamento'];
    $entrada = $p['condicao'] === 'parcelado' ? min((int) ($p['entrada'] ?? 0), $agora) : 0;
    return ['fixos' => $fixos, 'atividades' => $ativ, 'bruto' => $bruto, 'desconto' => $desc, 'total' => $total,
        'final' => $final, 'agora' => $agora, 'entrada' => $entrada, 'saldo' => $agora - $entrada];
}

/**
 * Parcelas fixas e iguais com juros de 1% ao mês: sistema de prestações
 * fixas (tabela Price), o mesmo da Calculadora do Cidadão do Banco Central.
 * [['n','principal','juros','valor','saldo']] em centavos.
 */
function price_table(int $saldo, int $n, float $rate = PROPOSAL_INTEREST): array
{
    if ($saldo <= 0 || $n < 1) {
        return [];
    }
    $pmt = $rate > 0 ? (int) round($saldo * $rate / (1 - (1 + $rate) ** -$n)) : (int) ceil($saldo / $n);
    $bal = $saldo;
    $out = [];
    for ($k = 1; $k <= $n; $k++) {
        // Parcelas de valor idêntico: o centavo do arredondamento fica nos juros da última.
        $principal = $k === $n ? $bal : $pmt - (int) round($bal * $rate);
        $bal -= $principal;
        $out[] = ['n' => $k, 'principal' => $principal, 'juros' => $pmt - $principal, 'valor' => $pmt, 'saldo' => max(0, $bal)];
    }
    return $out;
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
 * Parcelas da parte paga agora. Parcelamento próprio: fixas e iguais, com
 * juros de 1% ao mês (tabela Price). Cartão: divisão simples, com a taxa da
 * operadora somada pelo cliente.
 */
function proposal_schedule(array $d): array
{
    $t = proposal_totals($d);
    $p = $d['pagamento'];
    if ($p['condicao'] !== 'parcelado' || $t['saldo'] <= 0) {
        return [];
    }
    $n = max(1, (int) ($p['modalidade'] === 'cartao' ? $p['cartao_parcelas'] : $p['parcelas']));
    if ($p['modalidade'] === 'proprio') {
        $rows = price_table($t['saldo'], $n);
    } else {
        $base = intdiv($t['saldo'], $n);
        $rest = $t['saldo'] - $base * $n;
        $rows = [];
        for ($k = 1; $k <= $n; $k++) {
            $principal = $base + ($k === $n ? $rest : 0);
            $rows[] = ['n' => $k, 'principal' => $principal, 'juros' => 0, 'valor' => $principal];
        }
    }
    foreach ($rows as &$r) {
        $r['vencimento'] = $p['primeiro_vencimento'] ? add_months($p['primeiro_vencimento'], $r['n'] - 1) : null;
    }
    return $rows;
}

/**
 * Proposta gravada de volta no formato do formulário: base para a aba Reunião
 * ajustar só os honorários e o pagamento, mantendo custos, atividades e textos.
 */
function proposal_input_from(array $d): array
{
    $p = $d['pagamento'];
    $in = [
        'precificacao_modo' => $d['precificacao']['modo'] ?? 'manual',
        'desconto' => $d['precificacao']['desconto'] ?? '',
        'final_pct' => $d['precificacao']['final_pct'] ?? '',
        'fixos_valor' => cents_input($d['fixos']['valor']),
        'fixos_descricao' => $d['fixos']['descricao'],
        'exito_ativo' => $d['exito']['ativo'] ? '1' : '',
        'exito_percentual' => $d['exito']['percentual'],
        'exito_base' => $d['exito']['base'],
        'pagamento_tipo' => $p['condicao'] === 'avista' ? 'avista' : ($p['modalidade'] === 'cartao' ? 'cartao' : 'proprio'),
        'meio' => $p['meio'] ?? 'pix',
        'formas' => $p['formas'],
        'vencimento' => (string) $p['vencimento'],
        'entrada' => cents_input($p['entrada']),
        'entrada_vencimento' => (string) $p['entrada_vencimento'],
        'parcelas' => (string) ($p['modalidade'] === 'cartao' ? $p['cartao_parcelas'] : $p['parcelas']),
        'primeiro_vencimento' => (string) $p['primeiro_vencimento'],
        'pagamento_observacoes' => $p['observacoes'],
    ];
    foreach ($d['atividades'] as $i => $a) {
        $in['atividade_descricao'][$i] = $a['descricao'];
        $in['atividade_valor'][$i] = cents_input($a['valor']);
    }
    foreach ($d['custos'] as $i => $c) {
        $in['custo_tipo'][$i] = $c['tipo'];
        $in['custo_descricao'][$i] = $c['descricao'];
        $in['custo_valor'][$i] = cents_input($c['valor']);
    }
    foreach (array_keys(PROPOSAL_TEXTS) as $k) {
        if ($d['texto'][$k] !== null) {
            $in['texto_' . $k] = $in['texto_' . $k . '_base'] = $d['texto'][$k];
            $in['texto_' . $k . '_manual'] = '1';
            $in['texto_' . $k . '_ref'] = (string) ($d['texto_ref'][$k] ?? '');
        }
    }
    return $in;
}

/**
 * Condições da aba Reunião: a proposta gravada (ou a precificação padrão,
 * sem proposta) com o que foi ajustado na tela. Retorna [dados, erros].
 */
function meeting_fees(int $caseId, array $posted = []): array
{
    $pr = proposal_get($caseId);
    $base = $pr ? proposal_input_from($pr['data']) : [
        'precificacao_modo' => 'padrao',
        'exito_ativo' => '1',
        'exito_percentual' => rtrim(rtrim(number_format(pricing()['exito'], 2, ',', ''), '0'), ','),
        'pagamento_tipo' => 'avista',
        'meio' => 'pix',
    ];
    $in = array_replace($base, $posted);
    if (array_key_exists('exito_percentual', $posted)) {
        $in['exito_ativo'] = pct_value($posted['exito_percentual']) > 0 ? '1' : '';
    }
    if (array_key_exists('pagamento_tipo', $posted) && empty($posted['tudo_final'])) {
        unset($in['tudo_final']);
    }
    if (($in['pagamento_tipo'] ?? '') === 'proprio' && trim((string) ($in['primeiro_vencimento'] ?? '')) === '') {
        $in['primeiro_vencimento'] = (new DateTimeImmutable('today'))->modify('+30 days')->format('Y-m-d');
    }
    // Sem o formulário de formas, a forma decorre do meio escolhido.
    if (array_key_exists('pagamento_tipo', $posted)) {
        unset($in['formas']);
    }
    return proposal_from_input($in, $caseId);
}

/* ---------- Proposta aprovada: alteração só com a senha de um administrador ---------- */

/** Aprovação da proposta (com o aceite do cliente na reunião), ou null. */
function proposal_lock(int $caseId): ?array
{
    $c = find_case_or_null($caseId);
    $l = $c ? case_data($c, 'proposta_aprovada') : null;
    return is_array($l) ? $l : null;
}

function proposal_lock_set(int $caseId, array $d, ?array $by): void
{
    set_case_data($caseId, 'proposta_aprovada', ['at' => now_str(), 'by' => $by['name'] ?? null, 'total' => proposal_totals($d)['total']]);
}

/** Administrador (supervisor) ativo com esta senha, ou null. */
function admin_by_password(string $pass): ?array
{
    if ($pass === '') {
        return null;
    }
    foreach (all("SELECT * FROM users WHERE active = 1 AND (',' || roles || ',') LIKE '%,supervisor,%'") as $u) {
        if (password_verify($pass, (string) $u['pass_hash'])) {
            return $u;
        }
    }
    return null;
}

/** Proposta aprovada liberada para edição nesta sessão (pela senha de administrador)? */
function proposal_unlocked(int $caseId): bool
{
    return (int) ($_SESSION['prop_edit'][$caseId] ?? 0) > time();
}

/** Confere a senha de administrador (5 erros em 15 min bloqueiam). [administrador, erro]. */
function proposal_admin_check(int $caseId, string $pass): array
{
    $tries = array_filter((array) ($_SESSION['senha_admin_erros'] ?? []), fn($t) => $t > time() - 900);
    if (count($tries) >= 5) {
        return [null, 'Muitas tentativas de senha: aguarde 15 minutos.'];
    }
    if ($pass === '') {
        return [null, 'Informe a senha de um administrador.'];
    }
    $u = admin_by_password($pass);
    if (!$u) {
        $tries[] = time();
        $_SESSION['senha_admin_erros'] = array_values($tries);
        audit('proposta_senha_incorreta', $caseId);
        return [null, 'Senha de administrador incorreta. Nada foi alterado.'];
    }
    unset($_SESSION['senha_admin_erros']);
    return [$u, null];
}

/** Botão "Editar": libera a proposta aprovada por 30 minutos nesta sessão. Retorna erro ou null. */
function proposal_unlock(int $caseId, string $pass): ?string
{
    [$u, $err] = proposal_admin_check($caseId, $pass);
    if (!$u) {
        return $err;
    }
    $_SESSION['prop_edit'][$caseId] = time() + 1800;
    audit('proposta_liberada_edicao', $caseId, 'autorizado por ' . $u['name']);
    add_event($caseId, 'proposta', 'Proposta aprovada liberada para edição', 'Autorizado com a senha de administrador de ' . $u['name'] . '.');
    return null;
}

/**
 * Antes de gravar uma proposta aprovada: precisa estar liberada (botão
 * Editar) ou vir com a senha de um administrador (campo senha_admin).
 * Retorna erro ou null.
 */
function proposal_unlock_guard(int $caseId, array $in): ?string
{
    $lock = proposal_lock($caseId);
    if (!$lock || proposal_unlocked($caseId)) {
        return null;
    }
    $quando = 'Proposta aprovada em ' . fmt_datetime((string) $lock['at']) . ($lock['by'] ? ' por ' . $lock['by'] : '');
    $pass = (string) ($in['senha_admin'] ?? '');
    if ($pass === '') {
        return $quando . ': para alterar, clique em Editar e informe a senha de um administrador.';
    }
    [$u, $err] = proposal_admin_check($caseId, $pass);
    if (!$u) {
        return $err;
    }
    audit('proposta_alterada_apos_aprovacao', $caseId, 'autorizado por ' . $u['name']);
    add_event($caseId, 'proposta', 'Proposta aprovada alterada', 'Autorizado com a senha de administrador de ' . $u['name'] . '.');
    return null;
}

/** Depois de gravar: segue aprovada (com os novos valores) e volta a ficar só para visualização. */
function proposal_relock(int $caseId, array $d, ?array $by): void
{
    if (proposal_lock($caseId)) {
        proposal_lock_set($caseId, $d, $by);
        unset($_SESSION['prop_edit'][$caseId]);
    }
}

/* ---------- Gravação ---------- */

/**
 * Monta e confere a proposta a partir do formulário. Retorna [dados, erros].
 *
 * Os textos das cláusulas (texto_honorarios, texto_pagamento) só viram ajuste
 * manual quando diferem do texto que a tela mostrou (texto_*_base) ou quando
 * já eram manuais: assim, mudar um valor sem tocar no texto refaz a cláusula.
 */
function proposal_from_input(array $in, ?int $caseId = null): array
{
    $d = proposal_defaults();
    $err = [];
    $txt = fn($v, int $max = 500) => trim(mb_substr(str_replace("\r\n", "\n", (string) $v), 0, $max));

    // Valor dos honorários: padrão (5% do veículo, mínimo por processo), sugestão da IA ou digitado.
    $modo = in_array($in['precificacao_modo'] ?? '', ['padrao', 'ia', 'manual'], true) ? (string) $in['precificacao_modo'] : 'manual';
    $d['precificacao']['modo'] = $modo;
    $d['precificacao']['desconto'] = $txt($in['desconto'] ?? '', 6);
    $d['precificacao']['final_pct'] = !empty($in['tudo_final']) ? '100' : $txt($in['final_pct'] ?? '', 6);
    if ($caseId && $modo === 'padrao') {
        $std = pricing_standard($caseId);
        $in['fixos_valor'] = cents_input($std['valor']);
        if (!array_key_exists('exito_percentual', $in) || trim((string) $in['exito_percentual']) === '') {
            $in['exito_ativo'] = '1';
            $in['exito_percentual'] = rtrim(rtrim(number_format($std['exito'], 2, ',', ''), '0'), ',');
        }
    } elseif ($caseId && $modo === 'ia') {
        if ($ia = pricing_ia($caseId)) {
            $in['fixos_valor'] = cents_input($ia);
        } else {
            $err[] = 'Não há sugestão da IA: peça a avaliação ou use a precificação padrão.';
        }
    }

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
    $tipo = (string) ($in['pagamento_tipo'] ?? 'avista');
    $p['condicao'] = in_array($tipo, ['proprio', 'cartao'], true) ? 'parcelado' : 'avista';
    $p['modalidade'] = $tipo === 'cartao' ? 'cartao' : 'proprio';
    $p['meio'] = in_array($in['meio'] ?? '', ['pix', 'boleto', 'pix_recorrente'], true) ? (string) $in['meio'] : 'pix';
    // Formas aceitas: as marcadas ou, sem marcação, a que decorre da condição escolhida.
    $p['formas'] = array_values(array_intersect(array_keys(PAYMENT_METHODS), (array) ($in['formas'] ?? [])));
    if (!$p['formas']) {
        $p['formas'] = match (true) {
            $tipo === 'cartao' => ['credito'],
            $tipo === 'proprio' && $p['meio'] === 'boleto' => ['boleto'],
            default => ['pix'],
        };
    }
    if ($tipo === 'cartao' && !in_array('credito', $p['formas'], true)) {
        $p['formas'][] = 'credito';
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
    if ($t['total'] > 0 && $t['agora'] === 0) {
        $d['pagamento']['condicao'] = 'avista'; // tudo para o final do processo: nada a parcelar agora
    }
    if ($t['total'] === 0 && !$d['exito']['ativo']) {
        $err[] = 'Informe ao menos um honorário: valor fixo, por atividade ou êxito.';
    }
    if ($d['pagamento']['condicao'] === 'parcelado' && $t['saldo'] <= 0) {
        $err[] = 'Não há saldo a parcelar: confira os honorários, a entrada e a parte para o final.';
    }
    $min = pricing()['cartao_min_parcela'];
    if ($d['pagamento']['condicao'] === 'parcelado' && $d['pagamento']['modalidade'] === 'cartao' && $t['saldo'] > 0
        && intdiv($t['saldo'], max(1, (int) $d['pagamento']['cartao_parcelas'])) < $min) {
        $err[] = 'No cartão, a parcela mínima é de ' . brl($min) . ': no máximo ' . max(1, intdiv($t['saldo'], $min)) . ' parcela(s).';
    }
    if (pct_value($d['precificacao']['desconto']) > 50) {
        $err[] = 'Desconto acima de 50%: confira.';
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
            $d['texto'][$k] = mb_substr($posted, 0, 20000);
            $ref = proposal_norm_text((string) ($in['texto_' . $k . '_ref'] ?? ''));
            $d['texto_ref'][$k] = mb_substr($ref !== '' ? $ref : ($base !== '' ? $base : $auto[$k]), 0, 8000);
        }
    }
    return [$d, $err];
}

/**
 * Texto da cláusula (marcação do documento) como HTML do editor da aba
 * Contrato: parágrafos, parágrafo em destaque, listas e a formatação de cada
 * trecho. O editor devolve a mesma marcação (proposta.js).
 */
function proposal_editor_html(string $markup): string
{
    $h = '';
    foreach (doc_blocks($markup) as $b) {
        $h .= match ($b['type']) {
            'perfil' => '',
            'lista' => '<ul>' . implode('', array_map(fn($t) => '<li>' . doc_inline_html($t) . '</li>', $b['items'])) . '</ul>',
            'destaque' => '<p data-bloco="destaque">' . doc_inline_html($b['text']) . '</p>',
            'dados' => '<p>' . implode('<br>', array_map(fn($r) => doc_inline_html(trim($r[0] . ' ' . $r[1])), $b['rows'])) . '</p>',
            'assinatura' => '<p>' . doc_inline_html(implode("\n", $b['lines'])) . '</p>',
            default => '<p>' . doc_inline_html($b['text']) . '</p>',
        };
    }
    return $h !== '' ? $h : '<p><br></p>';
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

/** Texto da condição especial: parte dos honorários paga ao final do processo (INPC + 1% a.m., devida em qualquer resultado). */
function proposal_deferred_text(array $d): ?string
{
    $t = proposal_totals($d);
    if ($t['final'] <= 0) {
        return null;
    }
    $pct = pct_value($d['precificacao']['final_pct'] ?? '');
    return ($pct >= 100 ? 'A totalidade dos honorários contratuais, no valor de ' . brl_extenso($t['final']) . ','
            : 'Do valor dos honorários contratuais, a parcela de ' . brl_extenso($t['final']) . ', correspondente a ' . percent_extenso(str_replace('.', ',', (string) $pct)) . ' do total,')
        . ' será paga ao final do processo, assim entendido o seu encerramento por decisão transitada em julgado, acordo ou desistência, '
        . 'corrigida monetariamente pelo INPC/IBGE, mês a mês, desde a data de assinatura deste contrato, e acrescida de juros de 1% (um por cento) ao mês. '
        . 'Esse valor é devido independentemente do resultado do processo, ainda que não haja êxito, e será pago em até 10 (dez) dias do encerramento.';
}

/** Textos automáticos das cláusulas de remuneração e de pagamento. */
function proposal_auto_texts(array $d): array
{
    $t = proposal_totals($d);
    $p = $d['pagamento'];
    $descPct = pct_value($d['precificacao']['desconto'] ?? '');

    $hon = [];
    if ($d['fixos']['ativo']) {
        $hon[] = '- Honorários fixos: ' . brl_extenso($t['fixos'] - ($d['atividades'] ? 0 : $t['desconto'])) . ', valor fechado' . ($d['fixos']['descricao'] !== '' ? ', referente a ' . rtrim($d['fixos']['descricao'], '.') : '')
            . ($t['desconto'] && !$d['atividades'] ? ', já com desconto de ' . percent_extenso(str_replace('.', ',', (string) $descPct)) : '') . '.';
    }
    foreach ($d['atividades'] as $a) {
        $hon[] = '- Honorários contratuais por atividade — ' . rtrim($a['descricao'], '.') . ': ' . brl_extenso((int) $a['valor']) . '.';
    }
    if ($t['desconto'] && $d['atividades']) {
        $hon[] = '- Desconto concedido: ' . percent_extenso(str_replace('.', ',', (string) $descPct)) . ', no valor de ' . brl_extenso($t['desconto']) . '.';
    }
    if ($d['exito']['ativo']) {
        $hon[] = '- Honorários de êxito: ' . percent_extenso($d['exito']['percentual']) . ' sobre ' . rtrim(lcfirst_pt($d['exito']['base']), '.') . '.';
    }
    if ($t['total'] && (($d['fixos']['ativo'] ? 1 : 0) + count($d['atividades']) > 1 || ($t['desconto'] && $d['atividades']))) {
        $hon[] = '- Total dos honorários, sem o êxito: ' . brl_extenso($t['total']) . '.';
    }

    $formas = array_map(fn($f) => PAYMENT_METHODS[$f], $p['formas']);
    $lista = count($formas) > 1 ? implode(', ', array_slice($formas, 0, -1)) . ' ou ' . end($formas) : ($formas[0] ?? '');
    $pag = [];
    if ($t['agora'] > 0) {
        $pag[] = 'Formas de pagamento aceitas: ' . $lista . '.';
    }
    $meio = ['pix' => 'por PIX', 'boleto' => 'por boleto bancário', 'pix_recorrente' => 'por PIX recorrente (cobrança mensal automática)'][$p['meio'] ?? 'pix'] ?? 'por PIX';
    if ($t['agora'] <= 0) {
        // tudo para o final: só a condição especial
    } elseif ($p['condicao'] === 'avista') {
        $pag[] = 'Pagamento à vista' . (in_array('pix', $p['formas'], true) && count($p['formas']) === 1 ? ', por PIX,' : '') . ' no valor de ' . brl_extenso($t['agora']) . ($p['vencimento'] ? ', com vencimento em ' . fmt_date($p['vencimento']) : '') . '.';
    } else {
        $sched = proposal_schedule($d);
        $n = count($sched);
        $ent = $t['entrada'] ? 'Entrada de ' . brl_extenso($t['entrada']) . ($p['entrada_vencimento'] ? ', com vencimento em ' . fmt_date($p['entrada_vencimento']) : '') . ', e saldo' : 'Valor';
        if ($p['modalidade'] === 'proprio') {
            $pag[] = $ent . ' de ' . brl_extenso($t['saldo']) . ' em ' . $n . ' (' . numero_extenso($n) . ') parcela' . ($n > 1 ? 's mensais, fixas e iguais,' : '') . ' de ' . brl_extenso($sched[0]['valor'] ?? 0) . ', ' . $meio
                . ', já acrescidas de juros de 1% (um por cento) ao mês pelo sistema de prestações fixas da Calculadora do Cidadão do Banco Central, conforme o quadro abaixo:';
            $rows = [];
            foreach ($sched as $s) {
                $rows[] = '- ' . $s['n'] . 'ª parcela: ' . brl($s['valor']) . ($s['vencimento'] ? ', vencimento em ' . fmt_date($s['vencimento']) : '') . '.';
            }
            $pag[] = implode("\n", $rows);
        } else {
            $pag[] = $ent . ' de ' . brl_extenso($t['saldo']) . ' em até ' . $n . ' (' . numero_extenso($n) . ') parcelas no cartão de crédito, acrescidas da taxa de parcelamento cobrada pela operadora do cartão, com parcela mínima de ' . brl_extenso(pricing()['cartao_min_parcela']) . '.';
        }
    }
    if ($def = proposal_deferred_text($d)) {
        $pag[] = '**Condição especial:** ' . $def;
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
        'proposta.tem_diferido' => $flag($t['final'] > 0),
        'proposta.diferido' => proposal_deferred_text($d),
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
    'proposta.parcelamento_proprio' => 'Condição: parcelamento próprio (parcelas fixas, juros de 1% a.m.)',
    'proposta.tem_diferido' => 'Condição: parte dos honorários paga ao final do processo',
    'proposta.diferido' => 'Texto da parte paga ao final do processo (INPC + 1% a.m.)',
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
        ['Parágrafo do parcelamento próprio (parcelas fixas, juros de 1% a.m.)', $parc && $p['modalidade'] === 'proprio'],
        ['Condição especial: parte dos honorários ao final do processo (INPC + 1% a.m.)', proposal_totals($d)['final'] > 0],
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
