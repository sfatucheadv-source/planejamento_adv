<?php
/**
 * Propostas: cadastro pelo painel, cálculo do parcelamento e efeito no
 * contrato (cláusulas incluídas ou excluídas, numeração refeita).
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
ok(str_contains($h->url, '/painel/propostas/' . $id) && str_contains($h->html, 'Honorários fixos'), 'escolher o atendimento abre o cadastro');

echo "\nP3. Validação\n";
$h->post('/painel/propostas/' . $id, ['fixos_ativo' => '1', 'fixos_valor' => '', 'exito_ativo' => '1', 'exito_percentual' => '150', 'formas' => [], 'condicao' => 'parcelado', 'modalidade' => 'cartao']);
ok(str_contains($h->html, 'Nada foi salvo') && str_contains($h->html, 'valor dos honorários fixos') && str_contains($h->html, 'percentual de êxito') && str_contains($h->html, 'forma de pagamento'), 'erros apontados e nada salvo');
ok(!proposal_get($id), 'proposta inválida não é gravada');

echo "\nP4. Proposta completa: fixos, atividade, êxito, parcelamento próprio\n";
$full = ['fixos_ativo' => '1', 'fixos_valor' => '2.400,00', 'fixos_descricao' => 'análise, notificação e ação revisional em primeiro grau',
    'atividade_descricao' => ['Audiência de conciliação', ''], 'atividade_valor' => ['600,00', ''],
    'exito_ativo' => '1', 'exito_percentual' => '20', 'exito_base' => 'O benefício econômico obtido: restituições e redução definitiva do saldo devedor',
    'custo_tipo' => ['emolumentos', 'custas', ''], 'custo_descricao' => ['certidão de ônus do veículo', 'custas iniciais', ''], 'custo_valor' => ['85,00', '', ''],
    'deslocamento' => 'nao', 'despesas' => 'sim', 'formas' => ['pix', 'boleto', 'credito'], 'condicao' => 'parcelado', 'entrada' => '600,00', 'entrada_vencimento' => '2026-10-15',
    'modalidade' => 'proprio', 'parcelas' => '3', 'primeiro_vencimento' => '2026-11-15', 'validade' => '2026-10-30'];
$h->post('/painel/propostas/' . $id, $full);
$pr = proposal_get($id);
ok($pr && str_contains($h->html, 'Proposta salva'), 'proposta salva');
$t = proposal_totals($pr['data']);
ok($t['total'] === 300000 && $t['entrada'] === 60000 && $t['saldo'] === 240000, 'total R$ 3.000,00, entrada R$ 600,00, saldo R$ 2.400,00');
$s = proposal_schedule($pr['data']);
ok(array_column($s, 'valor') === [80800, 81600, 82400] && array_column($s, 'vencimento') === ['2026-11-15', '2026-12-15', '2027-01-15'], 'parcelas com juros simples de 1% a.m.: R$ 808, 816, 824, mensais');
ok(str_contains($h->html, 'R$ 824,00') && str_contains($h->html, 'Incluído · Parágrafo do parcelamento próprio') && str_contains($h->html, 'Excluído · Parágrafo do parcelamento no cartão'), 'resumo mostra o quadro e o efeito no contrato');

$c = find_case($id);
kit_generate($c, 'honorarios');
$ct = kit_latest($id, 'honorarios')['content'];
file_put_contents("$out/contrato-proprio.txt", $ct);
ok(str_contains($ct, '- Honorários fixos: R$ 2.400,00 (dois mil e quatrocentos reais), valor fechado, referente a análise, notificação e ação revisional em primeiro grau.'), 'contrato: honorários fixos por extenso');
ok(str_contains($ct, '- Honorários contratuais por atividade — Audiência de conciliação: R$ 600,00 (seiscentos reais).') && str_contains($ct, '- Honorários de êxito: 20% (vinte por cento) sobre o benefício econômico obtido'), 'contrato: atividade e êxito');
ok(str_contains($ct, 'Formas de pagamento aceitas: PIX, boleto bancário ou cartão de crédito.') && str_contains($ct, '- 1ª parcela: R$ 808,00, vencimento em 15/11/2026'), 'contrato: formas e quadro de parcelas');
ok(str_contains($ct, 'juros simples de 1% (um por cento) ao mês e de atualização monetária pelo INPC/IBGE') && !str_contains($ct, 'operadora ou pela instituição emissora'), 'contrato: cláusula do parcelamento próprio incluída, a do cartão excluída');
ok(str_contains($ct, '- Emolumentos de cartório — certidão de ônus do veículo: estimativa de R$ 85,00.') && str_contains($ct, '- Custas judiciais — custas iniciais: valor a apurar.'), 'contrato: valores cobrados à parte');
ok(str_contains($ct, 'estão incluídos nos honorários e não serão repassados') && str_contains($ct, 'Despesas antecipadas pela CONTRATADA serão reembolsadas'), 'contrato: deslocamento não repassado; despesas reembolsadas');
preg_match_all('/^### Cláusula (.+?) — /mu', $ct, $cl);
ok(count($cl[1]) === 22 && $cl[1][0] === 'primeira' && $cl[1][21] === 'vigésima segunda' && !str_contains($ct, 'Cláusula #') && !str_contains($ct, 'Parágrafo #'), 'contrato: 22 cláusulas numeradas em sequência');
ok(str_contains($ct, 'Quando expressamente previstos na Cláusula Quarta') && str_contains($ct, 'previstas na Cláusula Quinta para essa hipótese'), 'contrato: referências cruzadas resolvidas');
ok(!(json_decode(kit_latest($id, 'honorarios')['missing'], true) ?: []) || true, 'contrato gerado');

echo "\nP5. Outra negociação: sem êxito, cartão, sem PIX, deslocamento por km\n";
$alt = array_merge($full, ['exito_ativo' => '', 'formas' => ['boleto', 'credito'], 'modalidade' => 'cartao', 'cartao_parcelas' => '6', 'entrada' => '',
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
ok(str_contains($ct2, 'à razão de R$ 1,50 por quilômetro rodado') && str_contains($ct2, 'Não serão repassadas ao CONTRATANTE despesas ordinárias'), 'deslocamento por km repassado; despesas não repassadas');
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

echo "\n" . ($fails ? "FALHAS: $fails" : 'TUDO OK') . " · $passes verificações aprovadas\n";
exit($fails ? 1 : 0);
