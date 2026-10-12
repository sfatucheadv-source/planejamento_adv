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
$rows = array_column(sales_sim(22000, ['repasse' => true], $d0), null, 'key');
ok($rows['cartao_3']['cliente'] === 22847 && $rows['cartao_3']['liquido'] === 22001, 'cartão 3x de R$ 220 com repasse: cliente paga R$ 228,47 e você recebe R$ 220,01 (3,49% + R$ 0,49)');
ok($rows['pix']['liquido'] === 22000 - 199 && $rows['boleto']['taxas'] === 199, 'PIX e boleto: R$ 1,99 por pagamento');
$rows = array_column(sales_sim(300000, ['repasse' => false, 'desconto' => 5, 'parcelas_proprio' => 6], $d0), null, 'key');
ok($rows['pix']['cliente'] === 285000, 'desconto à vista de 5% no PIX');
ok($rows['cartao_12']['cliente'] === 300000 && $rows['cartao_12']['parcela'] === 25000 && $rows['cartao_12']['liquido'] === 300000 - 11970 - 49, 'cartão 12x sem repasse: 12x de R$ 250, líquido com 3,99% + R$ 0,49');
ok($rows['cartao_12']['antecipado'] < $rows['cartao_12']['liquido'] && !isset($rows['cartao_13']), 'antecipação reduz o líquido; cartão até 12x por padrão');
ok($rows['proprio']['n'] === 6 && $rows['proprio']['cliente'] === 300000 + 300000 / 6 * 21 / 100 && $rows['proprio']['taxas'] === 6 * 199, 'parcelado próprio em 6x com juros de 1% a.m. e um boleto por parcela');

echo "\nN2. Configuração: tabela da OAB, indicação e taxas\n";
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
ok(str_contains($h->html, 'Preços e negociação') && str_contains($h->html, 'Preencha os valores da tabela da OAB'), 'painel de controles avisa que a tabela da OAB está sem valores');
$h->get('/painel/configuracoes/precos');
ok(str_contains($h->html, 'oab_label[]') && str_contains($h->html, 'honorarios.oabpr.org.br') && str_contains($h->html, 'fee_cartao_2_6'), 'página de preços: tabela da OAB, indicação e taxas do Asaas');
$labels = array_column(OAB_SERVICES_DEFAULT, 'label');
$keys = array_column(OAB_SERVICES_DEFAULT, 'key');
$vals = ['500,00', '1.500,00', '3.000,00', '2.500,00', '', ''];
$fees = [];
foreach (ASAAS_FEE_DEFAULTS as $k => $v) {
    $fees['fee_' . $k] = (string) $v;
}
$fees['fee_cartao_max'] = '18';
$h->post('/painel/configuracoes', ['secao' => 'precos', 'oab_key' => array_merge($keys, ['']), 'oab_label' => array_merge($labels, ['Recurso de apelação']), 'oab_valor' => array_merge($vals, ['2.000,00']),
    'oab_nota' => array_merge(array_fill(0, 6, ''), ['item 9']), 'indicacao_pct' => '30'] + $fees);
setting('__reset');
$tab = array_column(oab_table(), null, 'key');
ok((int) $tab['revisional']['valor'] === 300000 && count($tab) === 7 && indicacao_pct() === 30.0, 'tabela da OAB gravada (com um serviço novo) e indicação de 30%');
ok(asaas_fees()['cartao_max'] === 18 && asaas_fees_custom(), 'taxas personalizadas: cartão até 18x');

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
$h->get('/painel/propostas/' . $id);
ok(str_contains($h->html, 'Negociação · ') && str_contains($h->html, 'data-kpis') && str_contains($h->html, 'Valor da OAB') && str_contains($h->html, 'Você recebe'), 'proposta abre como painel, com os indicadores no topo');
ok(str_contains($h->html, 'Guia de preço') && str_contains($h->html, 'interno') && str_contains($h->html, 'Simulador de vendas') && str_contains($h->html, 'Parcela sobre a renda'), 'guia de preço, simulador de vendas e perfil do cliente (parcela sobre a renda)');
ok(str_contains($h->html, 'Informe os honorários'), 'sem honorários, o simulador pede o valor');
$h->post('/painel/propostas/' . $id, ['fixos_valor' => '3.000,00', 'formas' => ['pix', 'credito'], 'pagamento_tipo' => 'cartao', 'parcelas' => '10', 'despesas' => 'sim', 'deslocamento' => 'nao']);
ok(str_contains($h->html, 'Proposta salva') && str_contains($h->html, 'Cartão em 10x <span class="neg-tag">na proposta</span>'), 'a linha da condição da proposta fica marcada no simulador');
ok(str_contains($h->html, 'Cartão em 18x') && str_contains($h->html, 'data-usar-tipo="cartao"'), 'simulador usa as taxas da conta (até 18x) e oferece “Usar” em cada linha');
preg_match('/name="_csrf" value="([^"]+)"/', $h->html, $cs);
$h->post('/painel/propostas/' . $id . '/previa', ['_csrf' => $cs[1], 'fixos_valor' => '2.000,00', 'formas' => ['pix'], 'pagamento_tipo' => 'avista', 'sim_valor' => '1.000,00', 'sim_repasse' => '1', 'sim_desconto' => '10']);
$j = json_decode($h->html, true);
ok(isset($j['vendas'], $j['kpis']) && str_contains($j['vendas'], 'R$ 900,00') && str_contains($j['vendas'], 'Simulando <strong>R$ 1.000,00</strong> (valor digitado)'), 'prévia ao vivo refaz o simulador com valor, desconto e repasse digitados');
ok(str_contains($j['kpis'], 'R$ 2.000,00'), 'e os indicadores com a proposta em edição');

echo "\nN4. Guia de preço\n";
$h->get('/painel/propostas/' . $id);
$h->post('/painel/propostas/' . $id . '/guia', ['servicos' => ['extrajudicial', 'revisional'], 'indicacao' => 'sim', 'indicado_por' => 'Cliente antigo', 'notas' => 'Comparou com outro escritório.']);
$g = proposal_guide($id);
$pp = guide_prices($g);
ok($pp['base'] === 450000 && $pp['referencia'] === 585000, 'valor da OAB = soma da tabela (R$ 4.500); com indicação, referência +30% (R$ 5.850)');
ok(str_contains($h->html, 'R$ 5.850,00') && str_contains($h->html, 'com indicação (+30%)') && !str_contains($h->html, 'abaixo do piso'), 'indicadores mostram a referência, sem trava de piso');
$h->post('/painel/propostas/' . $id . '/guia', ['servicos' => ['extrajudicial', 'revisional'], 'indicacao' => 'nao']);
ok(guide_prices(proposal_guide($id))['referencia'] === 450000, 'sem indicação, a referência é o piso da OAB');

echo "\nN5. Avaliação pela IA\n";
set_setting('llm_endpoint', "http://127.0.0.1:$mockPort/api/v1");
set_setting('llm_enabled', '1');
set_setting('llm_api_key', 'sk-or-v1-teste-valido-0000');
set_setting('llm_model', 'google/gemini-2.5-flash');
$h->get('/painel/propostas/' . $id);
$h->post('/painel/propostas/' . $id . '/avaliar', ['servicos' => ['extrajudicial', 'revisional'], 'indicacao' => 'sim', 'notas' => 'Prefere cartão.']);
$ev = negotiation_history($id, 1)[0] ?? null;
ok($ev && (int) $ev['result']['preco_sugerido'] === 643500 && $ev['result']['pagamento']['forma'] === 'cartao' && $ev['result']['pagamento']['parcelas'] === 10, 'IA sugere R$ 6.435 (referência com indicação + 10%), em 10x no cartão');
ok(str_contains($h->html, 'Preço sugerido') && str_contains($h->html, 'R$ 6.435,00') && str_contains($h->html, 'Perfil de consumo') && str_contains($h->html, 'O que ceder, nesta ordem') && str_contains($h->html, 'data-aplicar-ia'), 'painel mostra preço, faixa, perfil de consumo, concessões e “Aplicar à proposta”');
$in = (string) $ev['input'];
ok(str_contains($in, 'motorista de aplicativo') && str_contains($in, 'Tenho pressa') && str_contains($in, 'preco_referencia'), 'a IA recebe o perfil, as mensagens do cliente e o guia');
ok(!str_contains($in, '529.982.247-25') && !str_contains($in, 'Bruno Teste Souza') && !str_contains($in, 'bruno@exemplo.com') && !str_contains($in, 'ABC1D23') && !str_contains($in, 'masculino') && !str_contains($in, 'solteiro'), 'sem CPF, nome, e-mail, placa, gênero ou estado civil');
$h->post('/painel/propostas/' . $id . '/avaliar', ['servicos' => ['extrajudicial', 'revisional'], 'indicacao' => 'nao', 'notas' => 'teste-abaixo']);
$ev = negotiation_history($id, 1)[0];
ok((int) $ev['result']['preco_sugerido'] === 10000 && !str_contains(implode(' ', $ev['result']['alertas']), 'piso'), 'a sugestão é livre: o valor da OAB é referência, não trava (aceita abaixo dela)');
ok(count(negotiation_history($id, 10)) === 2 && str_contains($h->html, 'Avaliações anteriores'), 'histórico das avaliações');
$vars = json_encode(proposal_vars($id), JSON_UNESCAPED_UNICODE);
ok(!str_contains($vars, '6.435') && !str_contains($vars, 'teste-abaixo') && !str_contains($vars, 'Perfil'), 'guia e avaliação não entram nas variáveis do contrato');
$anon = new Http($base);
$anon->get('/painel/propostas/' . $id);
ok(str_contains($anon->url, '/painel/entrar'), 'o painel de negociação exige login no painel');
set_setting('llm_enabled', '0');
$h->post('/painel/propostas/' . $id . '/avaliar', ['servicos' => ['revisional'], 'indicacao' => 'nao']);
ok(str_contains($h->html, 'IA está desligada'), 'com a IA desligada, explica o motivo');
$h->post('/painel/propostas/' . $id . '/guia', ['servicos' => [], 'indicacao' => 'nao']);
set_setting('llm_enabled', '1');
$h->post('/painel/propostas/' . $id . '/avaliar', ['servicos' => [], 'indicacao' => 'nao']);
ok(count(negotiation_history($id, 10)) === 2 && str_contains($h->html, 'não chegou a um valor'), 'sem valor da OAB, a avaliação ainda é possível; sem valor sugerido, explica');

echo "\nN5b. Análise do cliente (dados, documentos, conversa e internet)\n";
$h->get('/painel/propostas/' . $id);
ok(str_contains($h->html, 'Analisar o cliente com a IA') && str_contains($h->html, 'Dados da ficha'), 'bloco Cliente com o botão de análise');
@unlink($mockLog . '.cliente');
@unlink($mockLog . '.web');
$h->post('/painel/propostas/' . $id . '/cliente', []);
$scan = negotiation_client_last($id);
$net = $scan['result']['internet'] ?? [];
ok($scan && $scan['result']['sensibilidade_preco'] === 'alta' && count($scan['result']['pontos']) === 2, 'lê ficha, documentos e conversa e traz pontos para a negociação');
ok($net['renda_estimada'] === 'media' && $net['vida_social'] === 'ativa' && count($net['links']) === 2, 'indicador da internet: renda estimada, vida social e só links válidos');
ok($net['links'][0]['citado'] === true && $net['links'][1]['citado'] === false, 'links conferidos com as fontes da pesquisa');
ok(str_contains($h->html, 'Renda estimada: <strong>média</strong>') && str_contains($h->html, 'https://www.instagram.com/bruno.souza.teste') && str_contains($h->html, 'rel="noopener noreferrer nofollow"') && str_contains($h->html, 'não confirmado na pesquisa') && !str_contains($h->html, 'javascript:alert'), 'painel mostra o indicador, os links e o aviso de homônimos');
$web = json_decode((string) @file_get_contents($mockLog . '.web'), true) ?: [];
ok(($web['plugins'][0]['id'] ?? '') === 'web' && str_contains($web['text'] ?? '', 'Bruno Teste Souza') && str_contains($web['text'] ?? '', 'Curitiba'), 'a pesquisa na internet usa a busca do modelo com nome, cidade e profissão');
ok(!str_contains($web['text'] ?? '', '529.982.247') && !str_contains($web['text'] ?? '', 'bruno@exemplo') && !str_contains($web['text'] ?? '', 'Tenho pressa'), 'para a internet não vão CPF, e-mail nem a conversa');
$cli = (string) @file_get_contents($mockLog . '.cliente');
ok(str_contains($cli, 'Tenho pressa') && str_contains($cli, 'motorista de aplicativo') && !str_contains($cli, '529.982.247-25') && !str_contains($cli, 'masculino'), 'a leitura do atendimento usa a conversa e a ficha, sem CPF nem gênero');
$h->post('/painel/propostas/' . $id . '/avaliar', ['servicos' => ['revisional'], 'indicacao' => 'nao']);
$ev = negotiation_history($id, 1)[0];
ok(str_contains((string) $ev['input'], 'renda_estimada') && str_contains((string) $ev['input'], 'Já paga parcela alta'), 'a avaliação de preço considera a análise do cliente');

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

echo "\n" . ($fails ? "FALHAS: $fails" : 'TUDO OK') . " · $passes verificações aprovadas\n";
exit($fails ? 1 : 0);
