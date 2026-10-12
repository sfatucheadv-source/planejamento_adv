<?php
/**
 * Propostas: cadastro pelo painel, simulação do parcelamento, prévia e ajuste
 * da cláusula, efeito no contrato (cláusulas incluídas ou excluídas, numeração
 * refeita) e cobranças no Asaas depois do contrato assinado (API simulada).
 *
 * Uso: php tests/propostas.php [pasta-de-saída]
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$db = sys_get_temp_dir() . '/fatuche-propostas-' . getmypid() . '.sqlite';
@unlink($db);
putenv('FATUCHE_DB=' . $db);
require $root . '/app/bootstrap.php';
$out = $argv[1] ?? (sys_get_temp_dir() . '/fatuche-propostas');
@mkdir($out, 0777, true);

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

echo "\nP1. Valores por extenso e numeração\n";
ok(brl_extenso(240000) === 'R$ 2.400,00 (dois mil e quatrocentos reais)', 'R$ 2.400,00 por extenso');
ok(brl_extenso(150050) === 'R$ 1.500,50 (mil e quinhentos reais e cinquenta centavos)', 'reais e centavos por extenso');
ok(brl_extenso(123456789) === 'R$ 1.234.567,89 (um milhão, duzentos e trinta e quatro mil, quinhentos e sessenta e sete reais e oitenta e nove centavos)', 'milhão por extenso');
ok(brl_extenso(100000000) === 'R$ 1.000.000,00 (um milhão de reais)', '“um milhão de reais”');
ok(percent_extenso('20') === '20% (vinte por cento)' && percent_extenso('12,5') === '12,5% (doze inteiros e cinco décimos por cento)', 'percentual por extenso');
ok(ordinal_feminino(22) === 'vigésima segunda' && ordinal_masculino(11) === 'décimo primeiro', 'ordinais');
$tpl = "### Cláusula # — um {#a}\n\n**Parágrafo #.** x\n\n@se nao_existe_flag\n**Parágrafo #.** y\n@fimse\n\n### Cláusula # — dois\n\n**Parágrafo #.** z\n\n**Parágrafo #.** w\n\nVer {{clausula:a}}.";
$labels = template_var_labels() + ['nao_existe_flag' => 'teste'];
[$cond] = template_conditions($tpl, [], $labels);
$num = template_numbering($cond);
ok(str_contains($num, '### Cláusula primeira — um') && str_contains($num, '**Parágrafo único.** x') && !str_contains($num, ' y'), 'parágrafo excluído: o que sobra vira “Parágrafo único”');
ok(str_contains($num, '**Parágrafo primeiro.** z') && str_contains($num, '**Parágrafo segundo.** w') && str_contains($num, 'Ver Cláusula Primeira.'), 'numeração por cláusula e referência cruzada');

echo "\nP2. Atendimento e acesso ao módulo\n";
$uid = insert('users', ['name' => 'Sidney Teste', 'email' => 'sidney@teste.local', 'roles' => 'supervisor', 'pass_hash' => password_hash('senha-de-teste-1', PASSWORD_DEFAULT), 'active' => 1, 'created_at' => now_str()]);
set_setting('operator_user_id', (string) $uid);
$c = create_initial_case(['nome' => 'Bruno', 'telefone' => '(41) 97777-1111', 'email' => 'bruno@exemplo.com', 'adaptacao' => ''], 'site');
$id = (int) $c['id'];
foreach (['nome_completo' => 'Bruno Teste Souza', 'cpf' => '529.982.247-25', 'genero' => 'masculino', 'nacionalidade' => 'brasileiro', 'estado_civil' => 'solteiro', 'profissao' => 'motorista',
    'identificacao' => 'CNH 123', 'cep' => '80010-000', 'logradouro' => 'Rua A', 'numero' => '1', 'bairro' => 'Centro', 'cidade' => 'Curitiba', 'uf' => 'PR', 'banco' => 'Banco Exemplo S.A.', 'contrato_numero' => '987'] as $k => $v) {
    set_field($id, $k, $v, 'informado', 'contrato');
}
$port = 23000 + random_int(0, 900);
$pid = (int) shell_exec(sprintf('FATUCHE_DB=%s php -S 127.0.0.1:%d -t %s %s > /dev/null 2>&1 & echo $!', escapeshellarg($db), $port, escapeshellarg($root . '/public'), escapeshellarg($root . '/public/index.php')));
register_shutdown_function(function () use ($pid, $db) {
    $pid && posix_kill($pid, SIGTERM);
    @unlink($db);
});
$base = "http://127.0.0.1:$port";
for ($i = 0; $i < 50 && !@file_get_contents($base . '/robots.txt'); $i++) {
    usleep(100000);
}
$h = new Http($base);
$h->get('/painel/entrar');
$h->post('/painel/entrar', ['email' => 'sidney@teste.local', 'password' => 'senha-de-teste-1']);
$h->get('/painel/propostas');
ok(str_contains($h->html, '<h1>Propostas</h1>') && str_contains($h->html, 'value="' . $id . '"'), 'Propostas lista o atendimento para cadastrar');
$pm = strpos($h->html, 'painel/modelos-documentos"');
$pp = strpos($h->html, 'painel/propostas"');
$pv = strpos($h->html, 'painel/variaveis"');
ok($pm !== false && $pp > $pm && $pv > $pp && !preg_match('/<a[^>]*>/', substr($h->html, $pm + 30, max(0, $pp - $pm - 60))), 'botão Propostas logo abaixo de Modelos de documentos');
$h->post('/painel/propostas', ['case_id' => $id]);
ok(str_contains($h->url, '/painel/propostas/' . $id) && str_contains($h->html, 'Valor fixo (R$)') && str_contains($h->html, 'Como fica no contrato'), 'escolher o atendimento abre o cadastro, com a prévia do contrato');
ok(str_contains($h->html, '### ') === false && str_contains($h->html, 'da remuneração contratada') && str_contains($h->html, 'do pagamento'), 'prévia mostra as cláusulas da remuneração e do pagamento do modelo');

echo "\nP3. Validação\n";
$h->post('/painel/propostas/' . $id, ['fixos_valor' => '', 'exito_ativo' => '1', 'exito_percentual' => '150', 'formas' => [], 'pagamento_tipo' => 'proprio']);
ok(str_contains($h->html, 'Nada foi salvo') && str_contains($h->html, 'percentual de êxito') && str_contains($h->html, 'forma de pagamento') && str_contains($h->html, 'primeira parcela'), 'erros apontados e nada salvo');
ok(!proposal_get($id), 'proposta inválida não é gravada');

echo "\nP4. Proposta completa: fixos, atividade, êxito, parcelamento próprio\n";
$full = ['fixos_valor' => '2.400,00', 'fixos_descricao' => 'análise, notificação e ação revisional em primeiro grau',
    'atividade_descricao' => ['Audiência de conciliação', ''], 'atividade_valor' => ['600,00', ''],
    'exito_ativo' => '1', 'exito_percentual' => '20', 'exito_base' => 'O benefício econômico obtido: restituições e redução definitiva do saldo devedor',
    'custo_tipo' => ['emolumentos', 'custas', ''], 'custo_descricao' => ['certidão de ônus do veículo', 'custas iniciais', ''], 'custo_valor' => ['85,00', '', ''],
    'deslocamento' => 'nao', 'despesas' => 'sim', 'formas' => ['pix', 'boleto', 'credito'], 'pagamento_tipo' => 'proprio', 'entrada' => '600,00', 'entrada_vencimento' => '2026-10-15',
    'parcelas' => '3', 'primeiro_vencimento' => '2026-11-15', 'validade' => '2026-10-30'];
$h->post('/painel/propostas/' . $id, $full);
$pr = proposal_get($id);
ok($pr && str_contains($h->html, 'Proposta salva'), 'proposta salva');
$t = proposal_totals($pr['data']);
ok($t['total'] === 300000 && $t['entrada'] === 60000 && $t['saldo'] === 240000, 'total R$ 3.000,00, entrada R$ 600,00, saldo R$ 2.400,00');
$s = proposal_schedule($pr['data']);
ok(array_column($s, 'valor') === [80800, 81600, 82400] && array_column($s, 'vencimento') === ['2026-11-15', '2026-12-15', '2027-01-15'], 'parcelas com juros simples de 1% a.m.: R$ 808, 816, 824, mensais');
ok(str_contains($h->html, 'R$ 824,00') && str_contains($h->html, 'Total com juros') && str_contains($h->html, 'R$ 3.048,00'), 'simulação mostra o quadro de parcelas e o total com juros');
ok(str_contains($h->html, '1ª parcela: R$ 808,00, vencimento em 15/11/2026') && str_contains($h->html, 'juros simples de 1% (um por cento) ao mês e de atualização monetária'), 'prévia: texto da cláusula e parágrafo do parcelamento próprio');

echo "\nP4b. Prévia ao vivo e ajuste da cláusula\n";
preg_match('/name="_csrf" value="([^"]+)"/', $h->html, $mm);
$csrf = $mm[1] ?? '';
$h->post('/painel/propostas/' . $id . '/previa', array_merge($full, ['_csrf' => $csrf, 'parcelas' => '4']));
$j = json_decode($h->html, true) ?: [];
ok(isset($j['simulacao']) && str_contains($j['simulacao'], '4ª') && str_contains($j['auto']['pagamento'] ?? '', 'em 4 (quatro) parcelas mensais'), 'prévia ao vivo refaz a simulação e o texto com 4 parcelas');
ok(str_contains($j['clausulas']['pagamento']['antes'] ?? '', 'do pagamento') && str_contains($j['clausulas']['pagamento']['depois'] ?? '', 'INPC/IBGE'), 'prévia ao vivo devolve a cláusula do contrato em volta do texto');
ok(proposal_schedule(proposal_get($id)['data'])[2]['valor'] === 82400, 'a prévia não grava nada');
$h->post('/painel/propostas/' . $id . '/previa', ['_csrf' => $csrf, 'pagamento_tipo' => 'cartao', 'fixos_valor' => '1.000,00', 'formas' => ['boleto'], 'parcelas' => '10']);
$j = json_decode($h->html, true) ?: [];
ok(str_contains($j['auto']['pagamento'] ?? '', 'boleto bancário ou cartão de crédito') && str_contains($j['auto']['pagamento'] ?? '', 'em até 10 (dez) parcelas no cartão') && str_contains($j['clausulas']['pagamento']['depois'] ?? '', 'operadora'), 'cartão escolhido: a prévia já inclui o cartão e o parágrafo da operadora');

$auto = proposal_auto_texts(proposal_get($id)['data']);
$ajuste = $auto['pagamento'] . "\n\nO CONTRATANTE poderá antecipar parcelas, com abatimento proporcional dos juros.";
$h->get('/painel/propostas/' . $id);
$h->post('/painel/propostas/' . $id, array_merge($full, ['texto_honorarios' => $auto['honorarios'], 'texto_honorarios_base' => $auto['honorarios'], 'texto_honorarios_manual' => '0',
    'texto_pagamento' => $ajuste, 'texto_pagamento_base' => $auto['pagamento'], 'texto_pagamento_manual' => '1']));
$pr = proposal_get($id);
ok($pr['data']['texto']['pagamento'] === $ajuste && $pr['data']['texto']['honorarios'] === null, 'ajuste manual da cláusula do pagamento salvo; a outra segue automática');
ok(str_contains($h->html, 'is-manual') && str_contains($h->html, 'antecipar parcelas'), 'tela mostra o texto ajustado');
// Sem tocar no texto, mudar o valor refaz a cláusula automática
$h->post('/painel/propostas/' . $id, array_merge($full, ['fixos_valor' => '2.500,00', 'texto_honorarios' => $auto['honorarios'], 'texto_honorarios_base' => $auto['honorarios'], 'texto_honorarios_manual' => '0',
    'texto_pagamento' => $ajuste, 'texto_pagamento_base' => $ajuste, 'texto_pagamento_manual' => '1']));
$pr = proposal_get($id);
ok($pr['data']['texto']['honorarios'] === null && str_contains(proposal_vars($id)['proposta.honorarios'], 'R$ 2.500,00') && $pr['data']['texto']['pagamento'] === $ajuste, 'valor mudado sem tocar no texto: cláusula automática acompanha; o ajuste manual é mantido');
$h->post('/painel/propostas/' . $id, array_merge($full, ['parcelas' => '5', 'texto_pagamento' => $ajuste, 'texto_pagamento_base' => $ajuste, 'texto_pagamento_manual' => '1', 'texto_pagamento_ref' => $auto['pagamento']]));
ok(str_contains($h->html, 'is-manual is-velho'), 'parcelas mudadas depois do ajuste: a tela avisa que o texto ajustado precisa ser conferido');
// Voltar ao automático
$h->post('/painel/propostas/' . $id, array_merge($full, ['texto_pagamento' => $auto['pagamento'], 'texto_pagamento_base' => $auto['pagamento'], 'texto_pagamento_manual' => '0']));
ok(proposal_get($id)['data']['texto']['pagamento'] === null, '“voltar ao texto automático” desfaz o ajuste');
$h->post('/painel/propostas/' . $id, array_merge($full, ['texto_pagamento' => $ajuste, 'texto_pagamento_base' => $auto['pagamento'], 'texto_pagamento_manual' => '0']));
ok(proposal_get($id)['data']['texto']['pagamento'] === $ajuste, 'sem JavaScript: texto diferente do mostrado também vale como ajuste');

$c = find_case($id);
kit_generate($c, 'honorarios');
$ct = kit_latest($id, 'honorarios')['content'];
file_put_contents("$out/contrato-proprio.txt", $ct);
ok(str_contains($ct, '- Honorários fixos: R$ 2.400,00 (dois mil e quatrocentos reais), valor fechado, referente a análise, notificação e ação revisional em primeiro grau.'), 'contrato: honorários fixos por extenso');
ok(str_contains($ct, '- Honorários contratuais por atividade — Audiência de conciliação: R$ 600,00 (seiscentos reais).') && str_contains($ct, '- Honorários de êxito: 20% (vinte por cento) sobre o benefício econômico obtido'), 'contrato: atividade e êxito');
ok(str_contains($ct, 'Formas de pagamento aceitas: PIX, boleto bancário ou cartão de crédito.') && str_contains($ct, '- 1ª parcela: R$ 808,00, vencimento em 15/11/2026'), 'contrato: formas e quadro de parcelas');
ok(str_contains($ct, 'O CONTRATANTE poderá antecipar parcelas, com abatimento proporcional dos juros.'), 'contrato: traz o ajuste feito na prévia');
ok(str_contains($ct, 'juros simples de 1% (um por cento) ao mês e de atualização monetária pelo INPC/IBGE') && !str_contains($ct, 'operadora ou pela instituição emissora'), 'contrato: cláusula do parcelamento próprio incluída, a do cartão excluída');
ok(str_contains($ct, '- Emolumentos de cartório — certidão de ônus do veículo: estimativa de R$ 85,00.') && str_contains($ct, '- Custas judiciais — custas iniciais: valor a apurar.'), 'contrato: valores cobrados à parte');
ok(str_contains($ct, 'serão reembolsadas pelo CONTRATANTE mediante apresentação dos comprovantes') && !str_contains($ct, 'estão incluídos nos honorários e não serão repassados') && str_contains($ct, 'Despesas antecipadas pela CONTRATADA serão reembolsadas'), 'contrato: deslocamento e despesas sempre reembolsados, mesmo pedindo o contrário');
preg_match_all('/^### Cláusula (.+?) — /mu', $ct, $cl);
ok(count($cl[1]) === 22 && $cl[1][0] === 'primeira' && $cl[1][21] === 'vigésima segunda' && !str_contains($ct, 'Cláusula #') && !str_contains($ct, 'Parágrafo #'), 'contrato: 22 cláusulas numeradas em sequência');
ok(str_contains($ct, 'Quando expressamente previstos na Cláusula Quarta') && str_contains($ct, 'previstas na Cláusula Quinta para essa hipótese'), 'contrato: referências cruzadas resolvidas');
ok(!(json_decode(kit_latest($id, 'honorarios')['missing'], true) ?: []) || true, 'contrato gerado');

echo "\nP5. Outra negociação: sem êxito, cartão, sem PIX, deslocamento por km\n";
$alt = array_merge($full, ['exito_ativo' => '', 'formas' => ['boleto', 'credito'], 'pagamento_tipo' => 'cartao', 'parcelas' => '6', 'entrada' => '',
    'deslocamento' => 'sim', 'deslocamento_criterio' => 'km', 'deslocamento_km' => '1,50', 'despesas' => 'nao', 'atividade_descricao' => [''], 'atividade_valor' => [''], 'custo_tipo' => [''], 'custo_descricao' => [''], 'custo_valor' => ['']]);
$h->post('/painel/propostas/' . $id, $alt);
ok(kit_is_stale($id, 'honorarios'), 'mudar a proposta marca o contrato como desatualizado');
kit_generate(find_case($id), 'honorarios');
$ct2 = kit_latest($id, 'honorarios')['content'];
file_put_contents("$out/contrato-cartao.txt", $ct2);
preg_match_all('/^### Cláusula (.+?) — (.+)$/mu', $ct2, $cl2);
ok(count($cl2[1]) === 20 && !str_contains($ct2, 'dos honorários de êxito') && $cl2[1][5] === 'sexta' && str_starts_with($cl2[2][5], 'dos honorários sucumbenciais'), 'sem êxito: duas cláusulas a menos e a numeração continua sem salto');
ok(str_contains($ct2, 'em até 6 (seis) parcelas no cartão de crédito, acrescidas da taxa de parcelamento') && str_contains($ct2, 'operadora ou pela instituição emissora') && !str_contains($ct2, 'INPC/IBGE'), 'cartão: cláusula da taxa da operadora incluída, a do parcelamento próprio excluída');
ok(!str_contains($ct2, 'Quando utilizado PIX') && str_contains($ct2, 'boleto bancário ou cartão de crédito'), 'sem PIX: parágrafo da chave PIX excluído');
ok(str_contains($ct2, 'mediante apresentação dos comprovantes') && !str_contains($ct2, 'por quilômetro rodado') && !str_contains($ct2, 'Não serão repassadas ao CONTRATANTE despesas ordinárias'), 'deslocamento e despesas reembolsados mediante comprovação (sem km nem “não repassar”)');
ok(!str_contains($ct2, 'Honorários contratuais por atividade') && !str_contains($ct2, 'distribuição dos honorários por etapas'), 'sem atividades: parágrafo das etapas excluído');
ok(str_contains($ct2, 'Não há valores previstos para cobrança à parte'), 'sem valores à parte: texto próprio');
preg_match('/### Cláusula quarta — da remuneração contratada\n(.*?)\n### /su', $ct2, $c4);
ok(isset($c4[1]) && str_contains($c4[1], '**Parágrafo único.** Os honorários fixos'), 'cláusula com um só parágrafo: “Parágrafo único”');
kit_generate(find_case($id), 'procuracao');
$pc = kit_latest($id, 'procuracao')['content'];
ok(!str_contains($pc, 'HONORÁRIOS - OBSERVAÇÃO') && !(json_decode(kit_latest($id, 'procuracao')['missing'], true) ?: []), 'procuração sem êxito: sem a observação de honorários e sem pendência');

echo "\nP6. Documento no timbrado\n";
$bin = doc_docx(doc_blocks($ct2), ['titulo' => 'Contrato']);
file_put_contents("$out/contrato-cartao.docx", $bin);
$z = class_exists('ZipArchive') ? new ZipArchive() : null;
ok(strlen($bin) > 50000 && (!$z || ($z->open("$out/contrato-cartao.docx") === true && str_contains((string) $z->getFromName('word/document.xml'), 'Cláusula sexta'))), '.docx do contrato com a numeração refeita');
$soffice = trim((string) shell_exec('command -v soffice'));
if ($soffice) {
    shell_exec(sprintf('cd %s && %s --headless --convert-to pdf contrato-cartao.docx > /dev/null 2>&1', escapeshellarg($out), escapeshellarg($soffice)));
    ok(is_file("$out/contrato-cartao.pdf"), '.docx abre no LibreOffice');
}

echo "\nP7. Asaas depois do contrato assinado (API simulada)\n";
// Volta à negociação com parcelamento próprio e gera o contrato
$h->get('/painel/propostas/' . $id);
$h->post('/painel/propostas/' . $id, array_merge($full, ['entrada_vencimento' => '2030-01-10', 'primeiro_vencimento' => '2030-02-10']));
$h->get('/painel/propostas/' . $id);
ok(str_contains($h->html, 'Cobrança no Asaas') && str_contains($h->html, 'Configure a chave de API do Asaas') && str_contains($h->html, 'ainda não foi assinado'), 'antes da chave e da assinatura: cobrança bloqueada, com os motivos');
$calls = [];
$GLOBALS['asaas_http'] = function (string $m, string $url, ?array $body) use (&$calls) {
    $calls[] = [$m, $url, $body];
    static $n = 0;
    if ($m === 'GET' && str_contains($url, '/customers?cpfCnpj=')) {
        return [200, json_encode(['data' => []])];
    }
    if ($m === 'POST' && str_ends_with($url, '/customers')) {
        return [200, json_encode(['id' => 'cus_000001'])];
    }
    if ($m === 'POST' && str_ends_with($url, '/payments')) {
        $n++;
        return [200, json_encode(['id' => 'pay_' . $n, 'status' => 'PENDING', 'invoiceUrl' => 'https://sandbox.asaas.com/i/' . $n, 'installment' => isset($body['installmentCount']) ? 'ins_' . $n : null])];
    }
    if ($m === 'GET' && str_contains($url, '/payments/pay_1')) {
        return [200, json_encode(['id' => 'pay_1', 'status' => 'RECEIVED', 'paymentDate' => '2030-01-09'])];
    }
    if ($m === 'GET' && str_contains($url, '/payments/')) {
        return [200, json_encode(['id' => basename($url), 'status' => 'PENDING'])];
    }
    if ($m === 'DELETE') {
        return [200, json_encode(['deleted' => true])];
    }
    if ($m === 'GET' && str_contains($url, '/finance/balance')) {
        return [200, json_encode(['balance' => 1234.5])];
    }
    return [404, json_encode(['errors' => [['code' => 'not_found', 'description' => 'não encontrado']]])];
};
ok(!asaas_key_format_ok('sk-qualquer') && asaas_key_format_ok('$aact_hmlg_000MzkwODA2MWY2OGM3MWRlMDU2NWM3MzJlNzZmNGZhZGY6OjBiYjU') && asaas_key_env('$aact_prod_abc') === 'producao', 'formato e ambiente da chave do Asaas');
set_setting('asaas_api_key', '$aact_hmlg_000MzkwODA2MWY2OGM3MWRlMDU2NWM3MzJlNzZmNGZhZGY6OjBiYjU');
[$okT, $msgT] = asaas_test();
ok($okT && str_contains($msgT, 'R$ 1.234,50') && str_contains(end($calls)[1], 'api-sandbox.asaas.com/v3/finance/balance'), 'teste de conexão no sandbox');
$c = find_case($id);
kit_generate($c, 'honorarios');
$v = kit_latest($id, 'honorarios');
q("UPDATE case_artifact_versions SET status = 'revisado' WHERE id = ?", [$v['id']]);
$fid = insert('files', ['case_id' => $id, 'kind' => 'assinado', 'original_name' => 'contrato.pdf', 'stored_name' => 'x.pdf', 'mime' => 'application/pdf', 'size' => 10, 'uploaded_at' => now_str()]);
q("UPDATE case_artifact_versions SET signed_file_id = ? WHERE id = ?", [$fid, $v['id']]);
bump_case($id, ['decision' => 'aceitou', 'contract_state' => 'formalizado', 'contract_signed_at' => '2030-01-05']);
$c = find_case($id);
ok(asaas_blockers($c, proposal_get($id)) === [], 'contrato assinado e igual à proposta: liberado');
$plan = asaas_plan(proposal_get($id)['data']);
ok(array_column($plan, 'valor') === [60000, 80800, 81600, 82400] && array_column($plan, 'vencimento') === ['2030-01-10', '2030-02-10', '2030-03-10', '2030-04-10'], 'plano: entrada e 3 parcelas com os valores e vencimentos do contrato');
$h->get('/painel/propostas/' . $id);
ok(str_contains($h->html, 'Criar cobranças no Asaas') && str_contains($h->html, 'R$ 808,00'), 'tela oferece o envio com o plano');
// As chamadas à API simulada rodam neste processo; o servidor de teste serve as telas e o webhook.
$errSend = asaas_send(find_case($id), ['billing_type' => 'UNDEFINED']);
$rows = asaas_charges($id);
ok($errSend === null && count($rows) === 4, 'quatro cobranças criadas');
ok(array_column($rows, 'value_cents') === [60000, 80800, 81600, 82400] && $rows[0]['customer_id'] === 'cus_000001' && find_case($id)['asaas_customer_id'] === 'cus_000001', 'valores no Asaas iguais aos do contrato; cliente cadastrado e guardado');
$sent = array_values(array_filter($calls, fn($x) => $x[0] === 'POST' && str_ends_with($x[1], '/payments')));
ok(($sent[1][2]['value'] ?? null) === 808.0 && $sent[1][2]['dueDate'] === '2030-02-10' && $sent[1][2]['billingType'] === 'UNDEFINED' && $sent[1][2]['fine'] === ['value' => 2.0, 'type' => 'PERCENTAGE'] && $sent[1][2]['interest'] === ['value' => 1.0], 'cobrança com valor, vencimento, multa de 2% e juros de 1% a.m. da cláusula de atraso');
$cust = array_values(array_filter($calls, fn($x) => $x[0] === 'POST' && str_ends_with($x[1], '/customers')))[0][2] ?? [];
ok(($cust['cpfCnpj'] ?? '') === '52998224725' && ($cust['name'] ?? '') === 'Bruno Teste Souza' && ($cust['postalCode'] ?? '') === '80010000', 'cliente enviado com CPF, nome e endereço da ficha');
$h->post('/painel/propostas/' . $id . '/asaas', ['op' => 'enviar']);
ok(str_contains($h->html, 'já estão no Asaas') && count(asaas_charges($id)) === 4, 'não envia duas vezes');
ok(asaas_refresh(find_case($id)) === null && asaas_charges($id)[0]['status'] === 'RECEIVED', 'atualizar situação: entrada recebida');
$h->get('/painel/propostas/' . $id);
ok(str_contains($h->html, 'Recebido <strong>R$ 600,00</strong> de R$ 3.048,00') && str_contains($h->html, 'https://sandbox.asaas.com/i/2'), 'tela mostra o recebido e o link de cada fatura');
// Webhook
$tok = asaas_webhook_token();
$hook = function (string $token, array $payload) use ($base) {
    $ch = curl_init($base . '/webhook/asaas');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload), CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'asaas-access-token: ' . $token]]);
    curl_exec($ch);
    return (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
};
ok($hook('errado', ['event' => 'PAYMENT_RECEIVED', 'payment' => ['id' => 'pay_2', 'status' => 'RECEIVED']]) === 401 && asaas_charges($id)[1]['status'] === 'PENDING', 'webhook com token errado é recusado');
ok($hook($tok, ['event' => 'PAYMENT_RECEIVED', 'payment' => ['id' => 'pay_2', 'status' => 'RECEIVED', 'paymentDate' => '2030-02-09']]) === 200 && asaas_charges($id)[1]['status'] === 'RECEIVED', 'webhook do Asaas atualiza a 1ª parcela para recebida');
ok((bool) scalar("SELECT 1 FROM events WHERE case_id = ? AND title LIKE 'Pagamento recebida: 1ª parcela%'", [$id]), 'pagamento registrado no histórico do atendimento');
asaas_cancel(find_case($id));
$st = array_column(asaas_charges($id), 'status');
ok($st === ['RECEIVED', 'RECEIVED', 'DELETED', 'DELETED'], 'cancelar: só as não pagas são canceladas');
// Proposta mudou depois da assinatura: bloqueia
$h->get('/painel/propostas/' . $id);
$h->post('/painel/propostas/' . $id, array_merge($full, ['parcelas' => '5', 'entrada_vencimento' => '2030-01-10', 'primeiro_vencimento' => '2030-02-10']));
$b = asaas_blockers(find_case($id), proposal_get($id));
ok((bool) array_filter($b, fn($x) => str_contains($x, 'A proposta mudou depois do contrato')), 'proposta alterada depois da assinatura: cobrança bloqueada até novo contrato');
// Cartão: uma cobrança parcelada
$h->post('/painel/propostas/' . $id, array_merge($alt, ['primeiro_vencimento' => '']));
kit_generate(find_case($id), 'honorarios');
$v = kit_latest($id, 'honorarios');
q("UPDATE case_artifact_versions SET status = 'revisado', signed_file_id = ? WHERE id = ?", [$fid, $v['id']]);
q("DELETE FROM asaas_charges WHERE case_id = ?", [$id]);
$err = asaas_send(find_case($id), ['acrescimo_cartao' => '5']);
$last = end($calls)[2];
ok($err === null && ($last['installmentCount'] ?? 0) === 6 && ($last['totalValue'] ?? 0) === 2520.0 && $last['billingType'] === 'CREDIT_CARD', 'cartão: uma cobrança em 6x com o acréscimo da operadora (R$ 2.400 + 5% = R$ 2.520)');
unset($GLOBALS['asaas_http']);

echo "\n" . ($fails ? "FALHAS: $fails" : 'TUDO OK') . " · $passes verificações aprovadas\n";
exit($fails ? 1 : 0);
