<?php
declare(strict_types=1);

/**
 * Asaas: depois do contrato assinado, a proposta vira cobranças no Asaas com
 * os mesmos valores e vencimentos do contrato (entrada, parcelas com os juros
 * do parcelamento próprio, pagamento à vista ou parcelamento no cartão).
 *
 * A chave de API fica só na tabela settings (nunca no repositório) e não volta
 * para a tela. O webhook (/webhook/asaas) atualiza a situação das cobranças.
 */

const ASAAS_ENVS = [
    'sandbox'  => ['label' => 'Sandbox (testes)', 'url' => 'https://api-sandbox.asaas.com/v3', 'painel' => 'https://sandbox.asaas.com'],
    'producao' => ['label' => 'Produção',         'url' => 'https://api.asaas.com/v3',         'painel' => 'https://www.asaas.com'],
];

const ASAAS_BILLING = [
    'UNDEFINED'   => 'Cliente escolhe (boleto, PIX ou cartão)',
    'BOLETO'      => 'Boleto',
    'PIX'         => 'PIX',
    'CREDIT_CARD' => 'Cartão de crédito',
];

const ASAAS_STATUS = [
    'PENDING'                      => 'Aguardando pagamento',
    'AWAITING_RISK_ANALYSIS'       => 'Em análise',
    'CONFIRMED'                    => 'Confirmada',
    'RECEIVED'                     => 'Recebida',
    'RECEIVED_IN_CASH'             => 'Recebida em dinheiro',
    'OVERDUE'                      => 'Vencida',
    'REFUND_REQUESTED'             => 'Estorno solicitado',
    'REFUND_IN_PROGRESS'           => 'Estorno em andamento',
    'REFUNDED'                     => 'Estornada',
    'CHARGEBACK_REQUESTED'         => 'Contestação',
    'CHARGEBACK_DISPUTE'           => 'Contestação em disputa',
    'AWAITING_CHARGEBACK_REVERSAL' => 'Contestação revertida',
    'DUNNING_REQUESTED'            => 'Negativação solicitada',
    'DUNNING_RECEIVED'             => 'Recuperada',
    'DELETED'                      => 'Cancelada',
];

/** Situações em que a cobrança ainda pode ser cancelada pelo painel. */
const ASAAS_CANCELABLE = ['PENDING', 'OVERDUE', 'AWAITING_RISK_ANALYSIS'];

function asaas_env(): string
{
    $e = (string) setting('asaas_env', 'sandbox');
    return isset(ASAAS_ENVS[$e]) ? $e : 'sandbox';
}

function asaas_key(): ?string
{
    $k = (string) setting('asaas_api_key', '');
    return $k !== '' ? $k : null;
}

function asaas_enabled(): bool
{
    return asaas_key() !== null;
}

function asaas_key_hint(): string
{
    $k = asaas_key();
    return $k ? '…' . substr($k, -4) : '';
}

/** A chave do Asaas começa com "$aact_" (produção: "$aact_prod_", sandbox: "$aact_hmlg_"). */
function asaas_key_format_ok(string $key): bool
{
    return (bool) preg_match('/^\$aact_[A-Za-z0-9_:$.=+\/-]{20,}$/', $key);
}

/** Ambiente indicado pelo prefixo da chave (null quando a chave não diz). */
function asaas_key_env(string $key): ?string
{
    return str_starts_with($key, '$aact_prod_') ? 'producao' : (str_starts_with($key, '$aact_hmlg_') ? 'sandbox' : null);
}

function asaas_webhook_token(): string
{
    $t = (string) setting('asaas_webhook_token', '');
    if ($t === '') {
        $t = 'fatuche_' . bin2hex(random_bytes(20));
        set_setting('asaas_webhook_token', $t);
    }
    return $t;
}

/** Endereço deste sistema (o do domínio acessado agora, não o do site institucional). */
function asaas_webhook_url(): string
{
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    $root = $host !== '' ? 'https://' . $host . base_path() : rtrim(office('base_url'), '/');
    return $root . '/webhook/asaas';
}

/** Multa e juros de mora enviados ao Asaas: os mesmos da cláusula de atraso do contrato. */
function asaas_late_fees(): array
{
    $n = fn(string $k, string $def) => (float) str_replace(',', '.', (string) setting($k, $def));
    return ['multa' => $n('asaas_multa', '2'), 'juros' => $n('asaas_juros', '1')];
}

/**
 * Chamada à API v3. Retorna ['ok', 'status', 'data', 'error'].
 * Nos testes, $GLOBALS['asaas_http'] substitui a rede: fn($method, $url, $body) => [status, json].
 */
function asaas_request(string $method, string $path, ?array $body = null): array
{
    $key = asaas_key();
    if (!$key) {
        return ['ok' => false, 'status' => 0, 'data' => [], 'error' => 'Asaas não configurado (Configurações › Asaas).'];
    }
    $url = ASAAS_ENVS[asaas_env()]['url'] . $path;
    $json = $body !== null ? json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
    if (isset($GLOBALS['asaas_http']) && is_callable($GLOBALS['asaas_http'])) {
        [$status, $raw] = ($GLOBALS['asaas_http'])($method, $url, $body);
        $curlErr = '';
    } elseif (!function_exists('curl_init')) {
        return ['ok' => false, 'status' => 0, 'data' => [], 'error' => 'O servidor não tem a extensão cURL.'];
    } else {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['access_token: ' . $key, 'Content-Type: application/json', 'Accept: application/json', 'User-Agent: FatucheAtendimento/1.0'],
        ]);
        if ($json !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        }
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);
    }
    $data = is_string($raw) ? (json_decode($raw, true) ?: []) : [];
    $error = null;
    if ($raw === false) {
        $error = 'Falha de conexão com o Asaas: ' . $curlErr;
    } elseif ($status === 401) {
        $error = 'O Asaas recusou a chave de API (401). Confira a chave e o ambiente em Configurações.';
    } elseif ($status >= 400 || !empty($data['errors'])) {
        $msgs = array_map(fn($e) => (string) ($e['description'] ?? $e['code'] ?? ''), (array) ($data['errors'] ?? []));
        $error = 'O Asaas respondeu ' . $status . ': ' . (implode(' ', array_filter($msgs)) ?: mb_substr((string) $raw, 0, 300));
    }
    if ($error) {
        error_log('[fatuche][asaas] ' . $method . ' ' . $path . ': ' . $error);
    }
    return ['ok' => $error === null, 'status' => $status, 'data' => $data, 'error' => $error];
}

function asaas_test(): array
{
    $r = asaas_request('GET', '/finance/balance');
    if (!$r['ok']) {
        return [false, $r['error']];
    }
    return [true, 'conexão com o Asaas (' . ASAAS_ENVS[asaas_env()]['label'] . ') funcionando. Saldo: ' . brl((int) round(((float) ($r['data']['balance'] ?? 0)) * 100)) . '.'];
}

/* ---------- Plano de cobrança (o que o contrato manda cobrar) ---------- */

/** Tipo de cobrança a partir das formas aceitas na proposta. */
function asaas_billing_type(array $d): string
{
    $p = $d['pagamento'];
    if ($p['condicao'] === 'parcelado' && $p['modalidade'] === 'cartao') {
        return 'CREDIT_CARD';
    }
    if ($p['condicao'] === 'parcelado' && in_array($p['meio'] ?? '', ['pix', 'pix_recorrente'], true)) {
        return 'PIX';
    }
    if ($p['condicao'] === 'parcelado' && ($p['meio'] ?? '') === 'boleto') {
        return 'BOLETO';
    }
    $map = ['pix' => 'PIX', 'boleto' => 'BOLETO', 'credito' => 'CREDIT_CARD'];
    $types = array_values(array_unique(array_filter(array_map(fn($f) => $map[$f] ?? null, $p['formas']))));
    return count($types) === 1 && count($p['formas']) === 1 ? $types[0] : 'UNDEFINED';
}

/**
 * Cobranças que espelham o contrato: entrada, parcelas (com os juros do
 * parcelamento próprio), pagamento à vista ou parcelamento no cartão.
 * Cada item: ref, rotulo, valor (centavos), vencimento, parcelas (cartão).
 */
function asaas_plan(array $d): array
{
    $t = proposal_totals($d);
    $p = $d['pagamento'];
    if ($t['agora'] <= 0) {
        return []; // tudo para o final do processo: cobrado no encerramento, corrigido
    }
    if ($p['condicao'] === 'avista') {
        return [['ref' => 'avista', 'rotulo' => 'Pagamento à vista', 'valor' => $t['agora'], 'vencimento' => $p['vencimento'], 'parcelas' => 1]];
    }
    $out = [];
    if ($t['entrada']) {
        $out[] = ['ref' => 'entrada', 'rotulo' => 'Entrada', 'valor' => $t['entrada'], 'vencimento' => $p['entrada_vencimento'], 'parcelas' => 1];
    }
    $sched = proposal_schedule($d);
    if ($p['modalidade'] === 'cartao') {
        $out[] = ['ref' => 'cartao', 'rotulo' => 'Saldo em até ' . count($sched) . 'x no cartão', 'valor' => $t['saldo'], 'vencimento' => $p['primeiro_vencimento'] ?: now()->format('Y-m-d'), 'parcelas' => count($sched)];
        return $out;
    }
    foreach ($sched as $s) {
        $out[] = ['ref' => 'parcela-' . $s['n'], 'rotulo' => $s['n'] . 'ª parcela de ' . count($sched), 'valor' => $s['valor'], 'vencimento' => $s['vencimento'], 'parcelas' => 1];
    }
    return $out;
}

/** Versão do contrato que vale para a cobrança: a assinada ou, sem anexo, a última revisada/publicada. */
function asaas_contract_version(int $caseId): ?array
{
    return one("SELECT * FROM case_artifact_versions WHERE case_id = ? AND kind = 'honorarios' AND signed_file_id IS NOT NULL ORDER BY version DESC LIMIT 1", [$caseId])
        ?: one("SELECT * FROM case_artifact_versions WHERE case_id = ? AND kind = 'honorarios' AND status IN ('revisado','publicado') ORDER BY version DESC LIMIT 1", [$caseId]);
}

/**
 * Motivos que impedem a cobrança. A conferência central: os textos da proposta
 * gravados no contrato assinado precisam ser os mesmos da proposta atual —
 * assim o Asaas recebe exatamente os valores do contrato.
 */
function asaas_blockers(array $c, ?array $pr): array
{
    $why = [];
    if (!asaas_enabled()) {
        $why[] = 'Configure a chave de API do Asaas em Configurações.';
    }
    if (($c['contract_state'] ?? '') !== 'formalizado') {
        $why[] = 'O contrato ainda não foi assinado: registre a contratação como “Formalizado” na ficha do atendimento.';
    }
    if (!$pr) {
        $why[] = 'Não há proposta registrada.';
        return $why;
    }
    $v = asaas_contract_version((int) $c['id']);
    if (!$v) {
        $why[] = 'Não há versão revisada do contrato de honorários.';
    } else {
        $snap = json_decode((string) $v['data_snapshot'], true) ?: [];
        $cur = proposal_vars_data($pr['data']);
        $diff = [];
        foreach ($snap as $k => $val) {
            if (str_starts_with($k, 'proposta.') && array_key_exists($k, $cur) && (string) $val !== (string) ($cur[$k] ?? '')) {
                $diff[] = $k;
            }
        }
        if ($diff || ($snap['proposta.sem_cadastro'] ?? null)) {
            $why[] = 'A proposta mudou depois do contrato v' . $v['version'] . ' (ou o contrato foi gerado sem ela): os valores não coincidem. Gere, revise e assine uma nova versão do contrato.';
        }
    }
    if (!asaas_plan($pr['data'])) {
        $why[] = 'A proposta só tem honorários de êxito: não há valor a cobrar agora.';
    }
    if (!preg_match('/^\d{11}$|^\d{14}$/', (string) fv((int) $c['id'], 'cpf'))) {
        $why[] = 'Informe o CPF do cliente na ficha: o Asaas exige o CPF para emitir boleto e PIX.';
    }
    return $why;
}

function asaas_charges(int $caseId): array
{
    return all('SELECT * FROM asaas_charges WHERE case_id = ? ORDER BY id', [$caseId]);
}

function asaas_active_charges(int $caseId): array
{
    return array_values(array_filter(asaas_charges($caseId), fn($r) => $r['status'] !== 'DELETED'));
}

/* ---------- Envio ---------- */

/** Cliente no Asaas: procura pelo CPF e, se não houver, cadastra. Retorna [id, erro]. */
function asaas_customer(array $c): array
{
    $id = (int) $c['id'];
    if (!empty($c['asaas_customer_id'])) {
        return [$c['asaas_customer_id'], null];
    }
    $cpf = (string) fv($id, 'cpf');
    $r = asaas_request('GET', '/customers?cpfCnpj=' . $cpf);
    if (!$r['ok']) {
        return [null, $r['error']];
    }
    $cust = $r['data']['data'][0]['id'] ?? null;
    if (!$cust) {
        $body = array_filter([
            'name' => fv($id, 'nome_completo') ?: $c['client_name'],
            'cpfCnpj' => $cpf,
            'email' => fv($id, 'email') ?: ($c['client_email'] ?? null),
            'mobilePhone' => preg_replace('/\D/', '', (string) (fv($id, 'telefone') ?: ($c['client_phone'] ?? ''))) ?: null,
            'postalCode' => fv($id, 'cep'),
            'address' => fv($id, 'logradouro'),
            'addressNumber' => fv($id, 'numero'),
            'complement' => fv($id, 'complemento'),
            'province' => fv($id, 'bairro'),
            'externalReference' => $c['protocol'],
        ], fn($v) => $v !== null && $v !== '');
        $r = asaas_request('POST', '/customers', $body);
        if (!$r['ok']) {
            return [null, $r['error']];
        }
        $cust = $r['data']['id'] ?? null;
    }
    if (!$cust) {
        return [null, 'O Asaas não devolveu o identificador do cliente.'];
    }
    q('UPDATE cases SET asaas_customer_id = ? WHERE id = ?', [$cust, $id]);
    return [$cust, null];
}

/**
 * Cria as cobranças do contrato no Asaas. $in: vencimentos ajustados
 * (venc_{ref}, quando a data do contrato já passou), tipo de cobrança e
 * acréscimo da operadora no cartão. Retorna a mensagem de erro ou null.
 */
function asaas_send(array $c, array $in): ?string
{
    $id = (int) $c['id'];
    $pr = proposal_get($id);
    if ($why = asaas_blockers($c, $pr)) {
        return implode(' ', $why);
    }
    if (asaas_active_charges($id)) {
        return 'As cobranças deste contrato já estão no Asaas. Para enviar de novo, cancele as atuais.';
    }
    $d = $pr['data'];
    $billing = isset(ASAAS_BILLING[(string) ($in['billing_type'] ?? '')]) ? (string) $in['billing_type'] : asaas_billing_type($d);
    $plan = asaas_plan($d);
    $today = now()->format('Y-m-d');
    foreach ($plan as &$item) {
        $item['vencimento'] = parse_br_date((string) ($in['venc_' . $item['ref']] ?? '')) ?: $item['vencimento'];
        if (!$item['vencimento']) {
            return 'Informe o vencimento de “' . $item['rotulo'] . '”.';
        }
        if ($item['vencimento'] < $today) {
            return 'O vencimento de “' . $item['rotulo'] . '” (' . fmt_date($item['vencimento']) . ') já passou; o Asaas só aceita datas a partir de hoje. Ajuste a data.';
        }
    }
    unset($item);
    $acrescimo = parse_money(str_replace('%', '', (string) ($in['acrescimo_cartao'] ?? ''))) ?? 0.0;
    if ($acrescimo < 0 || $acrescimo > 30) {
        return 'O acréscimo da operadora deve ficar entre 0% e 30%.';
    }

    [$cust, $err] = asaas_customer($c);
    if ($err) {
        return 'Cliente no Asaas: ' . $err;
    }
    $fees = asaas_late_fees();
    $created = 0;
    foreach ($plan as $item) {
        $valor = $item['valor'];
        $type = $item['ref'] === 'cartao' ? 'CREDIT_CARD' : $billing;
        if ($item['ref'] === 'cartao' && $acrescimo > 0) {
            $valor = (int) round($valor * (1 + $acrescimo / 100));
        }
        $body = [
            'customer' => $cust,
            'billingType' => $type,
            'dueDate' => $item['vencimento'],
            'description' => 'Honorários advocatícios · ' . $c['protocol'] . ' · ' . $item['rotulo'] . ' · contrato de honorários',
            'externalReference' => $c['protocol'] . ':' . $item['ref'],
            'postalService' => false,
        ];
        if ($fees['multa'] > 0) {
            $body['fine'] = ['value' => $fees['multa'], 'type' => 'PERCENTAGE'];
        }
        if ($fees['juros'] > 0) {
            $body['interest'] = ['value' => $fees['juros']];
        }
        if ($item['parcelas'] > 1) {
            $body['installmentCount'] = $item['parcelas'];
            $body['totalValue'] = round($valor / 100, 2);
        } else {
            $body['value'] = round($valor / 100, 2);
        }
        $r = asaas_request('POST', '/payments', $body);
        if (!$r['ok']) {
            add_event($id, 'cobranca', 'Asaas: envio interrompido', $created . ' cobrança(s) criada(s) antes do erro. ' . $r['error']);
            return 'Envio interrompido em “' . $item['rotulo'] . '”: ' . $r['error'] . ($created ? ' As ' . $created . ' cobrança(s) já criadas aparecem abaixo; cancele-as antes de reenviar.' : '');
        }
        $pdata = $r['data'];
        insert('asaas_charges', [
            'case_id' => $id, 'ref' => $item['ref'], 'label' => $item['rotulo'], 'env' => asaas_env(),
            'asaas_id' => (string) ($pdata['id'] ?? ''), 'installment_id' => $pdata['installment'] ?? null, 'customer_id' => $cust,
            'billing_type' => $type, 'value_cents' => $valor, 'installments' => $item['parcelas'], 'due_date' => $item['vencimento'],
            'status' => (string) ($pdata['status'] ?? 'PENDING'), 'invoice_url' => $pdata['invoiceUrl'] ?? null,
            'created_at' => now_str(), 'updated_at' => now_str(),
        ]);
        $created++;
    }
    $total = array_sum(array_map(fn($r) => (int) $r['value_cents'], asaas_active_charges($id)));
    add_event($id, 'cobranca', 'Cobranças enviadas ao Asaas', $created . ' cobrança(s), total de ' . brl($total) . ' (' . ASAAS_ENVS[asaas_env()]['label'] . ').');
    audit('asaas_envio', $id, $created . ' cobrança(s)');
    return null;
}

/** Consulta a situação de cada cobrança no Asaas. */
function asaas_refresh(array $c): ?string
{
    $errs = [];
    foreach (asaas_active_charges((int) $c['id']) as $row) {
        $r = asaas_request('GET', '/payments/' . rawurlencode($row['asaas_id']));
        if (!$r['ok']) {
            $errs[] = $row['label'] . ': ' . $r['error'];
            continue;
        }
        asaas_apply_payment($row, $r['data']);
    }
    return $errs ? implode(' ', $errs) : null;
}

/** Cancela as cobranças ainda não pagas (parcelamento no cartão: o parcelamento inteiro). */
function asaas_cancel(array $c): ?string
{
    $errs = [];
    $n = 0;
    foreach (asaas_active_charges((int) $c['id']) as $row) {
        if (!in_array($row['status'], ASAAS_CANCELABLE, true)) {
            continue;
        }
        $r = $row['installment_id']
            ? asaas_request('DELETE', '/installments/' . rawurlencode($row['installment_id']))
            : asaas_request('DELETE', '/payments/' . rawurlencode($row['asaas_id']));
        if (!$r['ok'] && $r['status'] !== 404) {
            $errs[] = $row['label'] . ': ' . $r['error'];
            continue;
        }
        q("UPDATE asaas_charges SET status = 'DELETED', updated_at = ? WHERE id = ?", [now_str(), $row['id']]);
        $n++;
    }
    if ($n) {
        add_event((int) $c['id'], 'cobranca', 'Cobranças canceladas no Asaas', $n . ' cobrança(s) não paga(s).');
    }
    return $errs ? implode(' ', $errs) : ($n ? null : 'Não há cobrança pendente para cancelar.');
}

function asaas_apply_payment(array $row, array $p): void
{
    $status = (string) ($p['status'] ?? $row['status']);
    if (!empty($p['deleted'])) {
        $status = 'DELETED';
    }
    q('UPDATE asaas_charges SET status = ?, invoice_url = COALESCE(?, invoice_url), paid_at = COALESCE(?, paid_at), updated_at = ? WHERE id = ?', [
        $status, $p['invoiceUrl'] ?? null, $p['clientPaymentDate'] ?? $p['paymentDate'] ?? null, now_str(), $row['id'],
    ]);
    if ($status !== $row['status'] && in_array($status, ['RECEIVED', 'CONFIRMED', 'RECEIVED_IN_CASH'], true)) {
        add_event((int) $row['case_id'], 'cobranca', 'Pagamento ' . mb_strtolower(ASAAS_STATUS[$status]) . ': ' . $row['label'], brl((int) $row['value_cents']) . ' · Asaas.');
    }
}

/** Webhook do Asaas: confere o token e atualiza a cobrança. Retorna o código HTTP. */
function asaas_webhook(string $token, array $payload): int
{
    $expected = (string) setting('asaas_webhook_token', '');
    if ($expected === '' || !hash_equals($expected, $token)) {
        return 401;
    }
    $p = (array) ($payload['payment'] ?? []);
    if (!empty($p['id']) && ($row = one('SELECT * FROM asaas_charges WHERE asaas_id = ?', [(string) $p['id']]))) {
        if (($payload['event'] ?? '') === 'PAYMENT_DELETED') {
            $p['deleted'] = true;
        }
        asaas_apply_payment($row, $p);
    }
    return 200;
}

/** Resumo para a ficha e a lista: recebido / total. */
function asaas_summary(int $caseId): ?array
{
    $rows = asaas_active_charges($caseId);
    if (!$rows) {
        return null;
    }
    $paid = array_filter($rows, fn($r) => in_array($r['status'], ['RECEIVED', 'CONFIRMED', 'RECEIVED_IN_CASH'], true));
    $late = array_filter($rows, fn($r) => $r['status'] === 'OVERDUE');
    return ['total' => array_sum(array_column($rows, 'value_cents')), 'pago' => array_sum(array_column($paid, 'value_cents')),
        'n' => count($rows), 'pagas' => count($paid), 'vencidas' => count($late)];
}
