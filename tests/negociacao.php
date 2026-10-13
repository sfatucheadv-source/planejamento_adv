<?php
/**
 * Painel de negociação da proposta: simulador de vendas do Asaas (taxas,
 * repasse, antecipação, parcelamento próprio), guia de preço a partir da
 * tabela da OAB com acréscimo por indicação, avaliação de preço pela IA
 * (simulador local da API) e sigilo do guia.
 *
 * Uso: php tests/negociacao.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$db = sys_get_temp_dir() . '/fatuche-negociacao-' . getmypid() . '.sqlite';
@unlink($db);
putenv('FATUCHE_DB=' . $db);
require $root . '/app/bootstrap.php';

$fails = 0;
$passes = 0;
function ok(bool $cond, string $msg): void
{
    global $fails, $passes;
    $cond ? $passes++ : $fails++;
    echo ($cond ? '  ✓ ' : '  ✗ ') . $msg . "\n";
}

final class Http
{
    public string $html = '';
    public string $url = '';
    private string $jar;
    public function __construct(private string $base) { $this->jar = tempnam(sys_get_temp_dir(), 'jar'); }
    public function get(string $p): string { return $this->req('GET', $p); }
    public function post(string $p, array $data): string
    {
        if (!isset($data['_csrf']) && preg_match('/name="_csrf" value="([^"]+)"/', $this->html, $m)) {
            $data['_csrf'] = $m[1];
        }
        return $this->req('POST', $p, $data);
    }
    private function req(string $method, string $path, array $data = []): string
    {
        $ch = curl_init($this->base . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_FOLLOWLOCATION => true]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        }
        $this->html = (string) curl_exec($ch);
        $this->url = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        return $this->html;
    }
}


echo "\nN1. Simulador de vendas (cálculo)\n";
$d0 = proposal_defaults();
$f = asaas_fees();
$rows = array_column(sales_sim(300000, [], $d0), null, 'key');
ok($rows['pix']['liquido'] === 300000 && $rows['pix']['taxas'] === 0 && str_contains($rows['pix']['recebe'], 'sem taxa'), 'PIX à vista direto na chave: sem taxa do Asaas');
ok($rows['boleto']['taxas'] === 199 && $rows['boleto']['liquido'] === 300000 - 199, 'boleto: R$ 1,99 por pagamento');
$c3 = $rows['cartao_3'];
ok($c3['cliente'] - $c3['taxas'] === $c3['liquido'] && $c3['liquido'] >= 300000 && $c3['liquido'] - 300000 < 5, 'cartão 3x: taxa repassada ao cliente, o escritório recebe o valor cheio');
ok(isset($rows['cartao_6']) && !isset($rows['cartao_7']) && intdiv($rows['cartao_6']['cliente'], 6) >= 50000, 'cartão: para na parcela mínima de R$ 500 (R$ 3.000 vai até 6x)');
$pt = price_table(300000, 6);
ok($rows['pixparc_6']['parcela'] === $pt[0]['valor'] && $rows['pixparc_6']['taxas'] === 0 && count(array_unique(array_column($pt, 'valor'))) === 1, 'PIX parcelado no escritório: 6 parcelas fixas e iguais (1% a.m.), sem taxa');
ok($rows['boletoparc_6']['taxas'] === 6 * 199 && $rows['pixrec_6']['taxas'] === 6 * (int) round($f['pix_fixo'] * 100), 'boleto parcelado e PIX recorrente no Asaas: taxa por pagamento');
$rows = array_column(sales_sim(300000, ['desconto' => 5], $d0), null, 'key');
ok($rows['pix']['cliente'] === 285000, 'desconto à vista de 5% no PIX');
ok(price_table(100000, 1)[0]['valor'] === 101000 && price_table(240000, 3)[0]['valor'] === 81605, 'parcela fixa como na Calculadora do Cidadão: R$ 2.400 em 3x a 1% a.m. = R$ 816,05');

echo "\nN2. Configuração: precificação padrão e taxas\n";
$uid = insert('users', ['name' => 'Sidney Teste', 'email' => 'sidney@teste.local', 'roles' => 'supervisor', 'pass_hash' => password_hash('senha-de-teste-1', PASSWORD_DEFAULT), 'active' => 1, 'created_at' => now_str()]);
set_setting('operator_user_id', (string) $uid);
$port = 23000 + random_int(0, 900);
$pid = (int) shell_exec(sprintf('FATUCHE_DB=%s php -S 127.0.0.1:%d -t %s %s > /dev/null 2>&1 & echo $!', escapeshellarg($db), $port, escapeshellarg($root . '/public'), escapeshellarg($root . '/public/index.php')));
$mockPort = $port + 1000;
$mockPid = (int) shell_exec(sprintf('php -S 127.0.0.1:%d %s > /dev/null 2>&1 & echo $!', $mockPort, escapeshellarg($root . '/tests/mock_openrouter.php')));
$mockLog = sys_get_temp_dir() . '/mock-openrouter-' . $mockPort . '.log';
register_shutdown_function(function () use ($pid, $mockPid, $db) {
    $pid && posix_kill($pid, SIGTERM);
    $mockPid && posix_kill($mockPid, SIGTERM);
    @unlink($db);
});
$base = "http://127.0.0.1:$port";
for ($i = 0; $i < 50 && (!@file_get_contents($base . '/robots.txt') || !@file_get_contents("http://127.0.0.1:$mockPort/api/v1/models")); $i++) {
    usleep(100000);
}
$h = new Http($base);
$h->get('/painel/entrar');
$h->post('/painel/entrar', ['email' => 'sidney@teste.local', 'password' => 'senha-de-teste-1']);
$h->get('/painel/configuracoes');
ok(str_contains($h->html, 'Preços e negociação') && str_contains($h->html, '5% do veículo') && !str_contains($h->html, 'tabela da OAB'), 'painel de controles mostra a precificação padrão (sem tabela da OAB)');
$h->get('/painel/configuracoes/precos');
ok(str_contains($h->html, 'name="pct_veiculo"') && str_contains($h->html, 'value="3.500,00"') && str_contains($h->html, 'name="exito"') && str_contains($h->html, 'name="cartao_min_parcela"') && str_contains($h->html, 'fee_cartao_2_6') && !str_contains($h->html, 'oab_label[]'), 'página de preços: 5% do veículo, mínimo de R$ 3.500, êxito, parcela mínima no cartão e taxas');
$fees = [];
foreach (ASAAS_FEE_DEFAULTS as $k => $v) {
    $fees['fee_' . $k] = (string) $v;
}
$fees['fee_cartao_max'] = '18';
$h->post('/painel/configuracoes', ['secao' => 'precos', 'pct_veiculo' => '6', 'minimo' => '4.000,00', 'exito' => '12', 'cartao_min_parcela' => '500,00'] + $fees);
setting('__reset');
ok(pricing()['pct_veiculo'] === 6.0 && pricing()['minimo'] === 400000 && pricing()['exito'] === 12.0, 'precificação padrão gravada (6%, mínimo R$ 4.000, êxito 12%)');
ok(asaas_fees()['cartao_max'] === 18 && asaas_fees_custom(), 'taxas personalizadas: cartão até 18x');
$h->post('/painel/configuracoes', ['secao' => 'precos', 'pct_veiculo' => '5', 'minimo' => '3.500,00', 'exito' => '10', 'cartao_min_parcela' => '500,00'] + $fees);
setting('__reset');
ok(setting('precificacao') === null && pricing() === PRICING_DEFAULTS, 'de volta ao padrão: nada fica gravado');

echo "\nN3. Painel de negociação\n";
$c = create_initial_case(['nome' => 'Bruno', 'telefone' => '(41) 97777-1111', 'email' => 'bruno@exemplo.com', 'adaptacao' => ''], 'site');
$id = (int) $c['id'];
foreach (['nome_completo' => 'Bruno Teste Souza', 'cpf' => '529.982.247-25', 'genero' => 'masculino', 'estado_civil' => 'solteiro', 'profissao' => 'motorista de aplicativo',
    'cidade' => 'Curitiba', 'uf' => 'PR', 'banco' => 'Banco Exemplo S.A.', 'veiculo_marca' => 'Fiat', 'veiculo_modelo' => 'Argo', 'veiculo_placa' => 'ABC1D23'] as $k => $v) {
    set_field($id, $k, $v, 'informado', 'contrato');
}
set_field($id, 'parcela', '125000', 'informado', 'contrato');
set_field($id, 'renda_valor', '400000', 'informado', 'cliente');
chat_post($id, 'client', 'Tenho pressa, o banco mandou notificação. Consigo pagar no cartão.');
ok(pricing_standard($id)['valor'] === 350000 && str_contains(pricing_standard($id)['nota'], 'vale o mínimo'), 'sem valor do veículo: vale o mínimo de R$ 3.500');
set_field($id, 'valor_veiculo', '90.000,00', 'informado', 'contrato');
case_fields($id, true);
ok(pricing_standard($id)['valor'] === 450000, 'veículo de R$ 90.000: 5% = R$ 4.500');
$h->get('/painel/propostas/' . $id);
ok(str_contains($h->html, 'Proposta · ') && str_contains($h->html, 'data-kpis') && str_contains($h->html, 'Padrão') && str_contains($h->html, 'R$ 4.500,00') && str_contains($h->html, 'Você recebe') && !str_contains($h->html, 'Valor da OAB'), 'proposta abre com os indicadores (padrão de R$ 4.500), sem a OAB');
ok(str_contains($h->html, 'data-abas') && str_contains($h->html, 'data-aba-btn="produto"') && str_contains($h->html, 'data-aba-btn="simulacao"') && str_contains($h->html, 'data-aba-btn="contrato"')
    && strpos($h->html, 'id="produto"') < strpos($h->html, 'id="simulacao"') && strpos($h->html, 'id="simulacao"') < strpos($h->html, 'id="contrato"'), 'três abas, nesta ordem: honorários, simulação, contrato');
$abaProduto = substr($h->html, strpos($h->html, 'id="produto"'), strpos($h->html, 'id="simulacao"') - strpos($h->html, 'id="produto"'));
$abaSim = substr($h->html, strpos($h->html, 'id="simulacao"'), strpos($h->html, 'id="contrato"') - strpos($h->html, 'id="simulacao"'));
ok(!str_contains($abaProduto, 'name="servicos[]"') && str_contains($abaProduto, 'name="fixos_valor"') && str_contains($abaProduto, 'name="atividade_valor[]"') && str_contains($abaProduto, 'name="exito_ativo"') && str_contains($abaProduto, 'name="custo_tipo[]"') && !str_contains($abaProduto, 'name="pagamento_tipo"'), 'aba 1: honorários, sem o campo produto');
ok(str_contains($abaProduto, 'Facilitação') && str_contains($abaProduto, 'name="desconto"') && str_contains($abaProduto, 'name="final_pct"') && str_contains($abaProduto, 'name="tudo_final"'), 'aba 1: facilitação com desconto e parte para o final do processo');
ok(str_contains($abaSim, 'name="pagamento_tipo"') && str_contains($abaSim, 'name="precificacao_modo"') && str_contains($abaSim, 'name="meio"') && !str_contains($abaSim, 'name="formas[]"') && str_contains($abaSim, 'data-sim'), 'aba 2: precificação (padrão ou IA), forma de pagamento e parcelas');
ok(strpos($abaSim, 'sim-sug') !== false && strpos($abaSim, 'sim-sug') < strpos($abaSim, 'name="pagamento_tipo"') && str_contains($abaSim, 'name="sim_base"') && str_contains($abaSim, 'name="sim_meio"'), 'aba 2: sugestões de recebimento no topo e seletor de modo do simulador');
ok(str_contains($abaSim, 'Simulando <strong>R$ 4.500,00</strong> (precificação padrão)'), 'sem valor na proposta, o simulador parte da precificação padrão');
ok(substr_count($h->html, '<form class="form prop-form"') === 1 && !str_contains($h->html, 'id="guia" method'), 'um só formulário');
$h->post('/painel/propostas/' . $id, ['fixos_valor' => '3.000,00', 'pagamento_tipo' => 'cartao', 'parcelas' => '6']);
ok(str_contains($h->html, 'Proposta salva') && str_contains($h->html, 'Cartão em 6x <span class="neg-tag">na proposta</span>'), 'a linha da condição da proposta fica marcada no simulador');
ok(str_contains($h->html, 'data-usar-tipo="cartao"') && !str_contains($h->html, 'Cartão em 7x'), 'simulador oferece “Usar” e respeita a parcela mínima do cartão');
preg_match('/name="_csrf" value="([^"]+)"/', $h->html, $cs);
$h->post('/painel/propostas/' . $id . '/previa', ['_csrf' => $cs[1], 'fixos_valor' => '2.000,00', 'pagamento_tipo' => 'avista', 'sim_valor' => '1.000,00', 'sim_desconto' => '10', 'sim_meio' => 'pix', 'sim_pix' => 'avista']);
$j = json_decode($h->html, true);
ok(isset($j['vendas'], $j['kpis']) && str_contains($j['vendas'], 'R$ 900,00') && str_contains($j['vendas'], 'Simulando <strong>R$ 1.000,00</strong> (valor digitado)'), 'prévia ao vivo refaz o simulador com valor e desconto digitados');
ok(str_contains($j['kpis'], 'R$ 2.000,00'), 'e os indicadores com a proposta em edição');
$h->post('/painel/propostas/' . $id . '/previa', ['_csrf' => $cs[1], 'fixos_valor' => '2.000,00', 'pagamento_tipo' => 'avista', 'sim_base' => 'padrao', 'sim_meio' => 'pix', 'sim_pix' => 'parcelado']);
$j = json_decode($h->html, true);
ok(str_contains($j['vendas'], 'Simulando <strong>R$ 4.500,00</strong> (precificação padrão)') && str_contains($j['vendas'], 'PIX parcelado no escritório em 6x') && !str_contains($j['vendas'], '<td>Cartão em'), 'modo: padrão + PIX parcelado mostra só as linhas do PIX parcelado');
$h->post('/painel/propostas/' . $id . '/previa', ['_csrf' => $cs[1], 'fixos_valor' => '2.000,00', 'pagamento_tipo' => 'avista', 'sim_base' => 'proposta', 'sim_meio' => 'boleto']);
$j = json_decode($h->html, true);
ok(str_contains($j['vendas'], 'Boleto parcelado em 3x') && !str_contains($j['vendas'], 'PIX recorrente'), 'modo: boleto parcelado');

echo "\nN4. Precificação padrão e facilitação\n";
$h->get('/painel/propostas/' . $id);
$h->post('/painel/propostas/' . $id, ['precificacao_modo' => 'padrao', 'pagamento_tipo' => 'avista', 'notas' => 'Comparou com outro escritório.', 'aba' => 'simulacao']);
$pd = proposal_get($id)['data'];
ok(str_contains($h->html, 'Proposta salva') && $pd['fixos']['valor'] === 450000 && $pd['exito']['ativo'] && $pd['exito']['percentual'] === '10' && proposal_guide($id)['notas'] === 'Comparou com outro escritório.', 'padrão: R$ 4.500 (5% do veículo) + 10% de êxito; notas gravadas');
$h->post('/painel/propostas/' . $id, ['precificacao_modo' => 'padrao', 'exito_ativo' => '1', 'exito_percentual' => '10', 'desconto' => '10', 'final_pct' => '30', 'pagamento_tipo' => 'proprio', 'meio' => 'pix', 'parcelas' => '5', 'primeiro_vencimento' => '2030-02-10']);
$pd = proposal_get($id)['data'];
$t = proposal_totals($pd);
ok($t['bruto'] === 450000 && $t['desconto'] === 45000 && $t['total'] === 405000 && $t['final'] === 121500 && $t['agora'] === 283500, 'desconto de 10% e 30% para o final: R$ 4.050, dos quais R$ 1.215 ao final e R$ 2.835 agora');
$s = proposal_schedule($pd);
ok(count($s) === 5 && count(array_unique(array_column($s, 'valor'))) === 1 && $s[0]['valor'] === price_table(283500, 5)[0]['valor'], 'parte de agora em 5 parcelas fixas');
$auto = proposal_auto_texts($pd);
ok(str_contains($auto['pagamento'], 'Condição especial') && str_contains($auto['pagamento'], 'INPC') && str_contains($auto['pagamento'], 'R$ 1.215,00') && str_contains($auto['pagamento'], 'ainda que não haja êxito'), 'contrato: condição especial da parte ao final (INPC + 1% a.m., devida mesmo sem êxito)');
ok(str_contains($auto['honorarios'], 'desconto') || str_contains($auto['pagamento'], 'desconto'), 'contrato: menciona o desconto concedido');
$h->post('/painel/propostas/' . $id, ['precificacao_modo' => 'padrao', 'exito_ativo' => '1', 'exito_percentual' => '10', 'tudo_final' => '1', 'pagamento_tipo' => 'proprio', 'parcelas' => '5', 'primeiro_vencimento' => '2030-02-10']);
$pd = proposal_get($id)['data'];
$t = proposal_totals($pd);
ok($pd && $t['final'] === 450000 && $t['agora'] === 0 && $pd['pagamento']['condicao'] === 'avista' && !proposal_schedule($pd), 'tudo ao final do processo: nada a pagar agora, sem parcelas');

echo "\nN5. Avaliação pela IA\n";
set_setting('llm_endpoint', "http://127.0.0.1:$mockPort/api/v1");
set_setting('llm_enabled', '1');
set_setting('llm_api_key', 'sk-or-v1-teste-valido-0000');
set_setting('llm_model', 'google/gemini-2.5-flash');
$h->get('/painel/propostas/' . $id);
$h->post('/painel/propostas/' . $id . '/avaliar', ['notas' => 'Prefere cartão.']);
$ev = negotiation_history($id, 1)[0] ?? null;
ok($ev && (int) $ev['result']['preco_sugerido'] === 495000 && $ev['result']['pagamento']['forma'] === 'cartao' && $ev['result']['pagamento']['parcelas'] === 10, 'IA sugere R$ 4.950 (padrão + 10%), em 10x no cartão');
ok(str_contains($h->html, 'Preço sugerido') && str_contains($h->html, 'R$ 4.950,00') && str_contains($h->html, 'Perfil de consumo') && str_contains($h->html, 'O que ceder, nesta ordem') && str_contains($h->html, 'data-aplicar-ia'), 'painel mostra preço, faixa, perfil de consumo, concessões e “Aplicar à proposta”');
ok(pricing_ia($id) === 495000 && str_contains($h->html, 'value="ia"') && !preg_match('/value="ia"[^>]*disabled/', $h->html), 'a proposta da IA vira opção de precificação');
$in = (string) $ev['input'];
ok(str_contains($in, 'motorista de aplicativo') && str_contains($in, 'Tenho pressa') && str_contains($in, 'preco_padrao'), 'a IA recebe o perfil, as mensagens do cliente e o preço padrão');
ok(!str_contains($in, '529.982.247-25') && !str_contains($in, 'Bruno Teste Souza') && !str_contains($in, 'bruno@exemplo.com') && !str_contains($in, 'ABC1D23') && !str_contains($in, 'masculino') && !str_contains($in, 'solteiro'), 'sem CPF, nome, e-mail, placa, gênero ou estado civil');
$h->post('/painel/propostas/' . $id, ['precificacao_modo' => 'ia', 'pagamento_tipo' => 'avista']);
ok(proposal_get($id)['data']['fixos']['valor'] === 495000, 'precificação pela proposta da IA: R$ 4.950');
$h->post('/painel/propostas/' . $id . '/avaliar', ['notas' => 'teste-abaixo']);
$ev = negotiation_history($id, 1)[0];
ok((int) $ev['result']['preco_sugerido'] === 10000 && !str_contains(implode(' ', $ev['result']['alertas']), 'piso'), 'a sugestão é livre: o padrão é referência, não trava');
ok(count(negotiation_history($id, 10)) === 2 && str_contains($h->html, 'Avaliações anteriores'), 'histórico das avaliações');
$vars = json_encode(proposal_vars($id), JSON_UNESCAPED_UNICODE);
ok(!str_contains($vars, 'teste-abaixo') && !str_contains($vars, 'Perfil'), 'guia e avaliação não entram nas variáveis do contrato');
$anon = new Http($base);
$anon->get('/painel/propostas/' . $id);
ok(str_contains($anon->url, '/painel/entrar'), 'o painel de negociação exige login no painel');
set_setting('llm_enabled', '0');
$h->post('/painel/propostas/' . $id . '/avaliar', ['indicacao' => 'nao']);
ok(str_contains($h->html, 'IA está desligada'), 'com a IA desligada, explica o motivo');
set_setting('llm_enabled', '1');

echo "\nN5b. Sem análise do perfil do cliente pela IA\n";
$h->get('/painel/propostas/' . $id);
ok(!str_contains($h->html, 'Analisar o cliente') && !str_contains($h->html, 'Renda estimada') && !function_exists('negotiation_client_scan') && !function_exists('client_web_scan'), 'a análise do perfil e a pesquisa na internet saíram');
$h->post('/painel/propostas/' . $id . '/cliente', ['_csrf' => 'x']);
ok(!str_contains($h->html, 'Análise do cliente pronta'), 'a rota da análise do cliente não existe mais');
$h->get('/painel/propostas/' . $id);
$h->post('/painel/propostas/' . $id . '/avaliar', ['fixos_valor' => '4.200,00', 'formas' => ['pix'], 'pagamento_tipo' => 'avista', 'servicos' => ['revisional'], 'indicacao' => 'nao']);
$ev = negotiation_history($id, 1)[0];
ok(!str_contains((string) $ev['input'], 'analise_cliente') && proposal_totals(proposal_get($id)['data'])['total'] === 420000, 'a avaliação usa e salva a proposta da tela, sem análise do perfil');

echo "\nN5c. Reembolsos e validade\n";
$h->get('/painel/propostas/' . $id);
ok(!str_contains($h->html, 'name="deslocamento"') && !str_contains($h->html, 'name="despesas"') && !str_contains($h->html, 'name="validade"') && str_contains($h->html, 'sempre reembolsados'), 'sem escolha de deslocamento, despesas ou validade');
$h->post('/painel/propostas/' . $id, ['fixos_valor' => '3.000,00', 'formas' => ['pix'], 'pagamento_tipo' => 'avista', 'deslocamento' => 'nao', 'despesas' => 'nao', 'validade' => '2026-12-31']);
$pd = proposal_get($id)['data'];
ok($pd['deslocamento']['repassa'] && $pd['despesas']['repassa'] && $pd['validade'] === null, 'deslocamento e despesas sempre reembolsados; validade não é gravada');
$vars = proposal_vars($id);
ok($vars['proposta.repassa_deslocamento'] && $vars['proposta.repassa_despesas'] && !str_contains((string) (doc_template('honorarios')['body'] ?? ''), 'proposta.validade') && !str_contains((string) (doc_template('proposta')['body'] ?? ''), 'proposta.validade'), 'contrato com as cláusulas de reembolso e sem a validade da proposta');

echo "\nN6. Conferência no simulador do Asaas (API simulada)\n";
set_setting('asaas_api_key', '$aact_hmlg_teste0000000000000000');
set_setting('asaas_env', 'sandbox');
$sent = null;
$GLOBALS['asaas_http'] = function (string $m, string $url, ?array $body) use (&$sent) {
    $sent = [$m, $url, $body];
    return [200, json_encode(['creditCard' => ['netValue' => 2884.18, 'feePercentage' => 3.99, 'installment' => ['paymentValue' => 300.0, 'paymentNetValue' => 288.42]], 'pix' => ['netValue' => 2998.01, 'feeValue' => 1.99]])];
};
$r = asaas_simulate_api(300000, 10);
unset($GLOBALS['asaas_http']);
ok($sent[0] === 'POST' && str_ends_with($sent[1], '/payments/simulate') && $sent[2]['value'] == 3000 && $sent[2]['installmentCount'] === 10, 'consulta POST /payments/simulate com valor e parcelas');
$html = render('panel/_proposta_asaas_api', ['r' => $r, 'valor' => 300000, 'n' => 10]);
ok($r['ok'] && str_contains($html, 'Cartão de crédito') && str_contains($html, 'R$ 2.884,18') && str_contains($html, '3,99%') && str_contains($html, 'PIX'), 'mostra os valores devolvidos pelo Asaas por forma de pagamento');

echo "\nN7. Honorários na aba Reunião do atendimento\n";
$c2 = create_initial_case(['nome' => 'Carla', 'telefone' => '(41) 97777-2222', 'email' => 'carla@exemplo.com', 'adaptacao' => ''], 'site');
$id2 = (int) $c2['id'];
foreach (['nome_completo' => 'Carla Teste Lima', 'cpf' => '111.444.777-35', 'banco' => 'Banco Exemplo S.A.', 'valor_veiculo' => '100.000,00'] as $k => $v) {
    set_field($id2, $k, $v, 'informado', 'contrato');
}
$h->get('/painel/atendimento/' . $id2);
$reu = substr($h->html, (int) strpos($h->html, 'id="reuniao"'));
ok(str_contains($reu, 'id="honorarios"') && str_contains($reu, 'só você vê') && str_contains($reu, 'Padrão · R$ 5.000,00') && str_contains($reu, 'value="10"'), 'aba Reunião: honorários só para você, já com o padrão (5% de R$ 100.000) e 10% de êxito');
ok(str_contains($reu, 'name="desconto"') && str_contains($reu, 'name="tudo_final"') && str_contains($reu, 'PIX à vista') && str_contains($reu, 'Parcelado no escritório') && str_contains($reu, 'Cartão de crédito'), 'desconto, tudo ao final e as três formas de pagamento');
ok(str_contains($reu, 'Cláusulas do contrato sobre os honorários') && str_contains($reu, '<details class="hon-clausulas">') && str_contains($reu, 'hon-mark'), 'cláusulas do contrato retráteis, com os trechos em destaque');
$hon = ['op' => 'honorarios_reuniao', 'anchor' => 'honorarios', 'precificacao_modo' => 'padrao', 'exito_percentual' => '10', 'desconto' => '10', 'final_pct' => '30',
    'pagamento_tipo' => 'proprio', 'meio' => 'pix', 'parcelas' => '4', 'primeiro_vencimento' => '2030-03-10'];
$h->post('/painel/atendimento/' . $id2, $hon + ['acao' => 'calcular']);
ok(!proposal_get($id2) && str_contains($h->html, 'R$ 4.500,00') && str_contains($h->html, 'R$ 1.350,00') && str_contains($h->html, '4 parcelas fixas de'), 'recalcular: R$ 4.500 com 10% de desconto, R$ 1.350 ao final, 4 parcelas fixas (nada gravado)');
$h->post('/painel/atendimento/' . $id2, array_merge($hon, ['pagamento_tipo' => 'cartao', 'parcelas' => '12', 'acao' => 'registrar']));
ok(!proposal_get($id2) && str_contains($h->html, 'parcela mínima é de R$ 500,00'), 'cartão abaixo da parcela mínima: recusado, nada gravado');
$h->post('/painel/atendimento/' . $id2, $hon + ['acao' => 'registrar']);
$pr2 = proposal_get($id2);
$t2 = $pr2 ? proposal_totals($pr2['data']) : [];
ok($pr2 && $t2['total'] === 450000 && $t2['final'] === 135000 && $pr2['data']['pagamento']['parcelas'] === 4 && $pr2['data']['exito']['ativo'], 'cliente aceitou: condições gravadas na proposta');
$k2 = kit_latest($id2, 'honorarios');
ok($k2 && str_contains($k2['content'], 'Condição especial') && str_contains($k2['content'], 'INPC') && str_contains($h->html, 'nova minuta'), 'e o contrato ganha uma nova minuta com a condição especial');
ok(proposal_lock($id2) && proposal_lock($id2)['total'] === 450000, 'com o aceite, a proposta fica aprovada (travada)');
$tudo = ['op' => 'honorarios_reuniao', 'anchor' => 'honorarios', 'exito_percentual' => '10', 'desconto' => '', 'final_pct' => '', 'tudo_final' => '1', 'acao' => 'registrar'];
$h->post('/painel/atendimento/' . $id2, $tudo);
ok(str_contains($h->html, 'informe a senha de um administrador') && proposal_totals(proposal_get($id2)['data'])['total'] === 450000, 'aprovada: alterar sem a senha de administrador é recusado');
$h->post('/painel/atendimento/' . $id2, $tudo + ['senha_admin' => 'errada']);
ok(str_contains($h->html, 'Senha de administrador incorreta') && proposal_totals(proposal_get($id2)['data'])['final'] === 135000, 'senha errada: nada muda');
$h->post('/painel/propostas/' . $id2, ['fixos_valor' => '9.000,00', 'pagamento_tipo' => 'avista']);
ok(str_contains($h->html, 'Nada foi salvo') && str_contains($h->html, 'informe a senha de um administrador') && proposal_totals(proposal_get($id2)['data'])['total'] === 450000, 'página Proposta também exige a senha');
$h->get('/painel/propostas/' . $id2);
ok(str_contains($h->html, 'Somente visualização') && str_contains($h->html, '<fieldset class="prop-ro" disabled>') && str_contains($h->html, 'data-readonly') && !str_contains($h->html, '>Salvar proposta<') && str_contains($h->html, '/editar'), 'página da proposta aprovada: só visualização, com o botão Editar');
$h->post('/painel/propostas/' . $id2 . '/editar', ['senha_admin' => 'errada']);
ok(str_contains($h->html, 'Senha de administrador incorreta') && str_contains($h->html, 'Somente visualização'), 'Editar com senha errada: continua bloqueada');
$h->post('/painel/propostas/' . $id2 . '/editar', ['senha_admin' => 'senha-de-teste-1']);
ok(str_contains($h->html, 'Edição liberada') && !str_contains($h->html, '<fieldset class="prop-ro" disabled>') && str_contains($h->html, '>Salvar proposta<'), 'Editar com a senha de administrador: campos liberados');
$h->post('/painel/atendimento/' . $id2, $tudo);
$t2 = proposal_totals(proposal_get($id2)['data']);
ok($t2['final'] === 500000 && $t2['agora'] === 0, 'senha de administrador: alteração gravada (tudo para o final, R$ 5.000 com INPC + 1% a.m.)');
ok(proposal_lock($id2)['total'] === 500000 && (bool) scalar("SELECT 1 FROM events WHERE case_id = ? AND title = 'Proposta aprovada liberada para edição'", [$id2]), 'segue aprovada com o novo valor; a autorização fica no histórico');
$h->get('/painel/propostas/' . $id2);
ok(str_contains($h->html, 'Somente visualização'), 'depois de gravar, volta a ficar só para visualização');
$cl = proposal_auto_texts(proposal_get($id2)['data'])['pagamento'];
ok(str_contains($cl, 'A totalidade dos honorários contratuais') && str_contains($cl, 'ainda que não haja êxito'), 'cláusula específica para todo o valor ao final');
$h->post('/painel/atendimento/' . $id2, array_merge($tudo, ['final_pct' => '50', 'tudo_final' => '', 'senha_admin' => 'senha-de-teste-1']));
ok(proposal_totals(proposal_get($id2)['data'])['final'] === 250000, 'no quadro da reunião, a senha também pode ir junto com a alteração');
$anon2 = new Http($base);
$anon2->get('/painel/atendimento/' . $id2);
ok(!str_contains($anon2->html, 'hon-reu'), 'os valores não aparecem fora do painel');

echo "\n" . ($fails ? "FALHAS: $fails" : 'TUDO OK') . " · $passes verificações aprovadas\n";
exit($fails ? 1 : 0);
