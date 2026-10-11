<?php
/**
 * Variáveis dos documentos: dados do escritório, campos novos da ficha
 * (veículo, foro, testemunhas, situação, processos, prazo de repasse),
 * variáveis personalizadas e uso nos modelos.
 *
 * Uso: php tests/variaveis.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$db = sys_get_temp_dir() . '/fatuche-variaveis-' . getmypid() . '.sqlite';
@unlink($db);
putenv('FATUCHE_DB=' . $db);
require $root . '/app/bootstrap.php';

$codeFile = config('storage_dir') . '/codigo-instalacao.txt';
@unlink($codeFile);

$port = 21000 + random_int(0, 900);
$cmd = sprintf('FATUCHE_DB=%s php -S 127.0.0.1:%d -t %s %s > /dev/null 2>&1 & echo $!', escapeshellarg($db), $port, escapeshellarg($root . '/public'), escapeshellarg($root . '/public/index.php'));
$pid = (int) shell_exec($cmd);
register_shutdown_function(function () use ($pid, $db) {
    if ($pid) {
        posix_kill($pid, SIGTERM);
    }
    @unlink($db);
    @unlink($db . '-wal');
    @unlink($db . '-shm');
});
$base = "http://127.0.0.1:$port";
for ($i = 0; $i < 50 && !@file_get_contents($base . '/robots.txt'); $i++) {
    usleep(100000);
}

$fails = 0;
$passes = 0;
function ok(bool $cond, string $msg): void
{
    global $fails, $passes;
    if ($cond) {
        $passes++;
        echo "  ✓ $msg\n";
    } else {
        $fails++;
        echo "  ✗ $msg\n";
    }
}

final class Browser
{
    public string $html = '';
    public int $status = 0;
    public string $location = '';
    public string $url = '';
    private string $jar;

    public function __construct(private string $base)
    {
        $this->jar = tempnam(sys_get_temp_dir(), 'jar');
    }

    public function get(string $path): string
    {
        return $this->req('GET', $path);
    }

    public function post(string $path, array $data, bool $follow = true, array $files = []): string
    {
        if (!isset($data['_csrf'])) {
            $data['_csrf'] = $this->csrf();
        }
        foreach ($files as $k => $f) {
            $data[$k] = new CURLFile($f[0], $f[1], basename($f[0]));
        }
        return $this->req('POST', $path, $data, $follow, (bool) $files);
    }

    public function csrf(): string
    {
        if (preg_match('/name="_csrf" value="([^"]+)"/', $this->html, $m)) {
            return $m[1];
        }
        $this->get('/comecar');
        preg_match('/name="_csrf" value="([^"]+)"/', $this->html, $m);
        return $m[1] ?? '';
    }

    private function req(string $method, string $path, array $data = [], bool $follow = true, bool $multipart = false): string
    {
        $ch = curl_init($this->base . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar,
            CURLOPT_FOLLOWLOCATION => $follow, CURLOPT_HEADER => false,
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $multipart ? $data : http_build_query($data));
        }
        $this->html = (string) curl_exec($ch);
        $this->status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $this->location = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        $this->url = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        return $this->html;
    }
}

function c(int $id): array
{
    return one('SELECT * FROM cases WHERE id = ?', [$id]);
}

function field(int $id, string $f): ?array
{
    return one('SELECT * FROM case_fields WHERE case_id = ? AND field = ?', [$id, $f]);
}

/** Ação na ficha do operador. */
function op(Browser $b, int $id, array $data, array $files = []): void
{
    $b->get('/painel/atendimento/' . $id);
    if (!isset($data['row_version']) && preg_match('/name="row_version" value="(\d+)"/', $b->html, $m)) {
        $data['row_version'] = $m[1];
    }
    $b->post('/painel/atendimento/' . $id, $data, true, $files);
}

function flash_error(Browser $b): string
{
    return preg_match('/flash-error">([^<]+)/', $b->html, $m) ? html_entity_decode($m[1]) : '';
}

/** Pergunta da vez no chat do cliente: [chave, tipo, id do documento]. */
function current_q(Browser $b): ?array
{
    $b->get('/cliente?area=conversa');
    if (preg_match('#cliente/conversa/documento/(\d+)"#', $b->html, $m) && str_contains($b->html, 'id="pergunta"') && preg_match('#id="pergunta".*?conversa/documento/(\d+)#s', $b->html, $n)) {
        return ['doc', 'doc', (int) $n[1]];
    }
    if (preg_match('#id="pergunta".*?name="q" value="([a-z_]+)"#s', $b->html, $m)) {
        return [$m[1], 'q', 0];
    }
    return null;
}

$pdf = tempnam(sys_get_temp_dir(), 'doc') . '.pdf';
file_put_contents($pdf, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
$txt = tempnam(sys_get_temp_dir(), 'doc') . '.txt';
file_put_contents($txt, "texto simples, não é PDF\n");

echo "\nV1. Instalação e caso de teste\n";
$sid = new Browser($base);
$sid->get('/painel/instalar');
$code = trim((string) file_get_contents($codeFile));
$sid->post('/painel/instalar', ['code' => $code, 'name' => 'Sidney Teste', 'email' => 'sidney@teste.local', 'password' => 'senha-do-sidney-1', 'password2' => 'senha-do-sidney-1']);
setting('__reset');
ok(operator_id() !== null, 'operador configurado');
$cli = new Browser($base);
$cli->get('/comecar?origem=home');
preg_match('/name="form_key" value="([^"]+)"/', $cli->html, $fk);
sleep(3);
$cli->post('/comecar', ['nome' => 'Ana Teste', 'telefone' => '(41) 98888-2222', 'email' => 'ana@teste.local', 'adaptacao' => '', 'consent' => '1', 'website' => '', 'origin' => 'home', 'aviso' => 'banco', 'form_key' => $fk[1] ?? '']);
$id = (int) scalar('SELECT MAX(id) FROM cases');
ok($id > 0, 'atendimento criado pela entrada do site');

echo "\nV2. Painel: dados do escritório\n";
$sid->get('/painel/variaveis');
ok($sid->status === 200 && str_contains($sid->html, 'Variáveis dos documentos'), 'painel de variáveis abre');
foreach (['veiculo.renavam', 'contrato.situacao', 'contrato.processos_notificacoes', 'escritorio.orientacao_urgencias', 'proposta.prazo_repasse', 'documento.foro', 'testemunha2.cpf', 'escritorio.pix_chave'] as $k) {
    ok(str_contains($sid->html, '{{' . $k . '}}'), "catálogo lista {{{$k}}}");
}
ok(str_contains($sid->html, 'Variáveis dos documentos</span>') || str_contains($sid->html, 'painel/variaveis'), 'menu do painel tem o link');
$office = ['op' => 'escritorio', 'escritorio_endereco' => 'Rua A, 10, Centro, Curitiba/PR', 'escritorio_telefone' => '(41) 3333-4444', 'escritorio_email' => 'Contato@Fatuche.adv.br',
    'escritorio_prazo_resposta' => '2 (dois)', 'escritorio_orientacao_urgencias' => "Ligue para o telefone oficial e envie foto do documento.", 'escritorio_pix_tipo' => 'cnpj', 'escritorio_pix_chave' => '123',
    'default_foro' => 'Comarca de Curitiba/PR', 'default_prazo_repasse' => '5 (cinco) dias úteis contados do recebimento'];
$sid->post('/painel/variaveis', $office);
setting('__reset');
ok(str_contains(flash_error($sid), 'chave CNPJ tem 14'), 'chave PIX incompatível com o tipo é recusada');
ok(setting('office_phone') === '(41) 3333-4444' && setting('office_pix_key') === 'sidney@fatuche.adv.br', 'os demais dados são salvos; a chave errada não substitui a anterior');
$office['escritorio_pix_chave'] = '12.345.678/0001-90';
$sid->post('/painel/variaveis', $office);
setting('__reset');
ok(setting('office_pix_key') === '12.345.678/0001-90' && setting('office_email') === 'contato@fatuche.adv.br', 'chave PIX e e-mail gravados');
ok(setting('default_foro') === 'Comarca de Curitiba/PR', 'foro padrão gravado');
$sid->get('/painel/configuracoes');
$sid->post('/painel/configuracoes', ['operator_user_id' => (string) operator_id(), 'intake_workflow' => 'simple_v1']);
setting('__reset');
ok(setting('office_address') === 'Rua A, 10, Centro, Curitiba/PR', 'salvar as Configurações não apaga o endereço (agora editado nas variáveis)');

echo "\nV3. Variáveis personalizadas\n";
$sid->get('/painel/variaveis');
$sid->post('/painel/variaveis', ['op' => 'criar', 'key' => 'Contrato.Multa rescisória', 'label' => 'Multa em caso de rescisão', 'scope' => 'atendimento', 'value' => '10% dos honorários pendentes', 'help' => 'Percentual e base']);
custom_vars(true);
ok(isset(custom_vars()['contrato.multa_rescisoria']), 'nome com acento e espaço vira contrato.multa_rescisoria');
$sid->post('/painel/variaveis', ['op' => 'criar', 'key' => 'escritorio.banco_pix', 'label' => 'Banco da conta PIX', 'scope' => 'escritorio', 'value' => 'Banco Exemplo S.A.']);
$sid->post('/painel/variaveis', ['op' => 'criar', 'key' => 'veiculo.placa', 'label' => 'Duplicada', 'scope' => 'escritorio']);
ok(str_contains(flash_error($sid), 'já existe'), 'não cria variável com nome de uma existente');
$sid->post('/painel/variaveis', ['op' => 'criar', 'key' => 'semponto', 'label' => 'Inválida', 'scope' => 'escritorio']);
ok(str_contains(flash_error($sid), 'grupo.nome'), 'nome fora do formato é recusado');
$sid->post('/painel/variaveis', ['op' => 'criar', 'key' => 'documento.temporaria', 'label' => 'Para excluir', 'scope' => 'atendimento']);
custom_vars(true);
ok(count(custom_vars()) === 3, 'três variáveis personalizadas criadas');
$multa = custom_vars()['contrato.multa_rescisoria'];
$sid->post('/painel/variaveis', ['op' => 'alterar', 'id' => $multa['id'], 'label' => 'Multa rescisória', 'value' => '10% dos honorários', 'key' => 'outra.chave']);
custom_vars(true);
ok(custom_vars()['contrato.multa_rescisoria']['label'] === 'Multa rescisória' && !isset(custom_vars()['outra.chave']), 'alteração muda o rótulo e o valor, nunca o nome');

echo "\nV4. Ficha: veículo, contrato de honorários e personalizadas\n";
$sid->get('/painel/atendimento/' . $id);
ok(str_contains($sid->html, 'Contrato de honorários e assinatura') && str_contains($sid->html, 'f[var_' . $multa['id'] . ']'), 'ficha mostra o bloco do contrato e a variável personalizada');
ok(str_contains($sid->html, 'id="g-veiculo"'), 'ficha tem o grupo Veículo');
op($sid, $id, ['op' => 'dados', 'source' => 'contrato', 'f' => ['veiculo_marca' => 'Fiat', 'veiculo_modelo' => 'Argo Drive 1.0', 'veiculo_ano' => '2020/21', 'veiculo_placa' => 'abc-1d2', 'veiculo_renavam' => '12345678901']]);
ok(str_contains(flash_error($sid), 'placa') && str_contains(flash_error($sid), 'Renavam'), 'placa e Renavam inválidos são recusados');
case_fields($id, true);
ok(fv($id, 'veiculo_ano') === '2020/2021' && fv($id, 'veiculo_marca') === 'Fiat', 'ano “2020/21” vira 2020/2021; os campos válidos são salvos');
op($sid, $id, ['op' => 'dados', 'source' => 'contrato', 'f' => ['veiculo_placa' => 'abc 1d23', 'veiculo_renavam' => '00639884962']]);
case_fields($id, true);
ok(fv($id, 'veiculo_placa') === 'ABC1D23' && fv($id, 'veiculo_renavam') === '00639884962', 'placa Mercosul e Renavam com dígito verificador válidos');
ok(valid_renavam('639884962') && !valid_renavam('00639884961'), 'Renavam antigo de 9 dígitos aceito; dígito errado recusado');
op($sid, $id, ['op' => 'dados', 'source' => 'cliente', 'st' => ['situacao_contrato' => 'informado', 'atraso' => 'informado'], 'f' => ['situacao_contrato' => 'ativo', 'atraso' => 'sim']]);
op($sid, $id, ['op' => 'analise', 'f' => ['testemunha1_nome' => 'Carlos Testemunha', 'testemunha1_cpf' => '529.982.247-25', 'processos_notificacoes' => 'Notificação extrajudicial do banco em 02/09/2026; sem processo nem apreensão.', 'var_' . $multa['id'] => '20% dos honorários pendentes']]);
case_fields($id, true);
ok(fv($id, 'testemunha1_cpf') === '52998224725' && fv($id, 'var_' . $multa['id']) === '20% dos honorários pendentes', 'testemunha e personalizada gravadas pela ficha');

echo "\nV5. Valores no kit\n";
custom_vars(true);
$v = kit_vars($id);
ok($v['veiculo.placa'] === 'ABC1D23' && $v['veiculo.renavam'] === '00639884962' && $v['veiculo.modelo'] === 'Argo Drive 1.0', 'variáveis do veículo');
ok($v['contrato.situacao'] === 'inadimplente', 'situação derivada do relato: em atraso → inadimplente');
op($sid, $id, ['op' => 'analise', 'f' => ['contrato_situacao' => 'adimplente', 'foro' => 'Comarca de São José dos Pinhais/PR']]);
case_fields($id, true);
$v = kit_vars($id);
ok($v['contrato.situacao'] === 'adimplente', 'situação escolhida por Sidney prevalece');
ok($v['documento.foro'] === 'Comarca de São José dos Pinhais/PR' && $v['data.cidade'] !== $v['documento.foro'], 'foro do atendimento prevalece sobre o padrão e é independente da cidade');
ok($v['proposta.prazo_repasse'] === '5 (cinco) dias úteis contados do recebimento', 'prazo de repasse em branco usa o padrão do escritório');
ok($v['escritorio.pix_tipo'] === 'CNPJ' && $v['escritorio.pix_chave'] === '12.345.678/0001-90' && $v['escritorio.telefone'] === '(41) 3333-4444', 'dados do escritório');
ok($v['escritorio.endereco'] === 'Rua A, 10, Centro, Curitiba/PR', 'endereço do escritório continua disponível');
ok($v['contrato.multa_rescisoria'] === '20% dos honorários pendentes' && $v['escritorio.banco_pix'] === 'Banco Exemplo S.A.', 'personalizadas: valor da ficha e valor do escritório');
ok($v['documento.temporaria'] === null && $v['testemunha2.nome'] === null, 'sem valor nem padrão, a variável fica vazia');
ok(str_contains($v['contrato.processos_notificacoes'], 'Notificação extrajudicial'), 'processos e notificações');

echo "\nV6. Uso nos modelos\n";
seed_templates();
$body = "PIX {{escritorio.pix_tipo}} {{escritorio.pix_chave}} em nome de {{escritorio.nome}}. Veículo {{veiculo.marca}} {{veiculo.modelo}}, placa {{veiculo.placa}}. Foro: {{documento.foro}}. Multa: {{contrato.multa_rescisoria}}.\nTestemunha: {{testemunha2.nome|______________}}";
$sid->get('/painel/modelos-documentos?editar=honorarios');
ok(str_contains($sid->html, 'painel/variaveis'), 'modelos de documentos levam ao painel de variáveis');
$sid->post('/painel/modelos-documentos', ['op' => 'salvar', 'key' => 'honorarios', 'title' => 'Contrato de honorários', 'body' => $body . ' {{contrato.inexistente}}']);
ok(str_contains(flash_error($sid), 'contrato.inexistente'), 'modelo com variável inexistente é recusado');
$sid->post('/painel/modelos-documentos', ['op' => 'salvar', 'key' => 'honorarios', 'title' => 'Contrato de honorários', 'body' => $body]);
ok(doc_template('honorarios')['body'] === $body, 'modelo com as variáveis novas e personalizadas é aceito');
[$out, $missing] = render_template($body, kit_vars($id));
ok(str_contains($out, 'PIX CNPJ 12.345.678/0001-90') && str_contains($out, 'placa ABC1D23') && str_contains($out, 'Multa: 20% dos honorários pendentes'), 'texto gerado com os valores');
ok(str_contains($out, 'Testemunha: ______________') && !$missing, 'testemunha opcional usa o texto padrão e não bloqueia');
$sid->get('/painel/variaveis');
ok(str_contains($sid->html, 'Contrato de honorários</td>') || str_contains($sid->html, '>Contrato de honorários<'), 'catálogo mostra em que modelo a variável é usada');
$sid->post('/painel/variaveis', ['op' => 'excluir', 'id' => $multa['id']]);
custom_vars(true);
ok(isset(custom_vars()['contrato.multa_rescisoria']) && str_contains(flash_error($sid), 'ainda é usada'), 'variável usada em modelo não pode ser excluída');
$tmp = custom_vars()['documento.temporaria'];
q("INSERT INTO case_fields (case_id, field, value, state, source, updated_at) VALUES (?, ?, 'x', 'informado', 'sidney', ?)", [$id, 'var_' . $tmp['id'], now_str()]);
$sid->post('/painel/variaveis', ['op' => 'excluir', 'id' => $tmp['id']]);
custom_vars(true);
ok(!isset(custom_vars()['documento.temporaria']) && !field($id, 'var_' . $tmp['id']), 'variável sem uso é excluída com os valores das fichas');

echo "\nV7. Leitura do CRLV pela IA\n";
ok(in_array('veiculo_renavam', ia_doc_fields('veiculo'), true) && in_array('veiculo_placa', ia_doc_fields('contrato'), true), 'contrato e CRLV trazem os dados do veículo para a IA');
ok(str_contains(ia_field_spec(['veiculo_placa']), 'ABC1D23'), 'formato da placa explicado ao modelo');

echo "\n" . ($fails ? "FALHAS: $fails" : 'TUDO OK') . " · $passes verificações aprovadas\n";
exit($fails ? 1 : 0);
