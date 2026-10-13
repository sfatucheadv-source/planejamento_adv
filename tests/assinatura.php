<?php
/**
 * Assinatura eletrônica pelo cliente (código por e-mail, nome, CPF, rubrica,
 * IP e localização), via assinada com folha de evidências, conferência pública,
 * bloqueio de impressão antes da assinatura e pedido de retificação com a
 * avaliação da IA no Kit.
 *
 * Uso: php tests/assinatura.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/fatuche-assinatura-' . getmypid();
@mkdir($tmp, 0700, true);
$db = $tmp . '/fatuche.sqlite';
putenv('FATUCHE_DB=' . $db);
putenv('FATUCHE_STORAGE=' . $tmp);
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
    public string $type = '';
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
    public function json(string $p, array $data): array { return json_decode($this->post($p, $data), true) ?: []; }
    private function req(string $method, string $path, array $data = []): string
    {
        $ch = curl_init($this->base . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Teste) Navegador/1.0']);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        }
        $out = (string) curl_exec($ch);
        $this->url = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $this->type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        if ($method === 'GET' || !str_starts_with(ltrim($out), '{')) {
            $this->html = $out;
        }
        return $out;
    }
}

$mails = function () use ($tmp): array {
    $out = [];
    foreach (glob($tmp . '/mail/*.eml') ?: [] as $f) {
        $raw = (string) file_get_contents($f);
        preg_match_all('/base64\r?\n\r?\n([A-Za-z0-9+\/=\r\n]+)/', $raw, $p);
        $out[] = $raw . implode('', array_map(fn($b) => base64_decode(preg_replace('/\s+/', '', $b)), $p[1]));
    }
    return $out;
};

echo "S1. Preparação: contrato e procuração publicados\n";
$uid = insert('users', ['name' => 'Sidney Teste', 'email' => 'sidney@teste.local', 'roles' => 'supervisor', 'pass_hash' => password_hash('senha-de-teste-1', PASSWORD_DEFAULT), 'active' => 1, 'created_at' => now_str()]);
set_setting('operator_user_id', (string) $uid);
set_setting('mail_driver', 'arquivo');
seed_templates();
$c = create_initial_case(['nome' => 'Bruno', 'telefone' => '(41) 97777-1111', 'email' => 'bruno@exemplo.com', 'adaptacao' => ''], 'site');
$id = (int) $c['id'];
$acc = insert('client_accounts', ['name' => 'Bruno', 'email' => 'bruno@exemplo.com', 'created_at' => now_str()]);
q('UPDATE cases SET account_id = ? WHERE id = ?', [$acc, $id]);
foreach (['nome_completo' => 'Bruno Teste Souza', 'cpf' => '529.982.247-25', 'genero' => 'masculino', 'nacionalidade' => 'brasileiro', 'estado_civil' => 'solteiro', 'profissao' => 'motorista',
    'identificacao' => 'RG 123', 'cep' => '80010-000', 'logradouro' => 'Rua A', 'numero' => '1', 'bairro' => 'Centro', 'cidade' => 'Curitiba', 'uf' => 'PR', 'banco' => 'Banco Exemplo S.A.', 'contrato_numero' => '987'] as $k => $v) {
    set_field($id, $k, $v, 'informado', 'contrato');
}
foreach (['honorarios', 'procuracao', 'parecer'] as $k) {
    kit_generate(find_case($id), $k);
    q("UPDATE case_artifact_versions SET status = 'publicado', published_at = ? WHERE case_id = ? AND kind = ?", [now_str(), $id, $k]);
}
$vh = kit_latest($id, 'honorarios');
$vp = kit_latest($id, 'procuracao');
$vpar = kit_latest($id, 'parecer');
ok($vh && $vp && $vh['status'] === 'publicado', 'documentos publicados ao cliente');

$port = 23000 + random_int(0, 900);
$mockPort = $port + 1000;
$pid = (int) shell_exec(sprintf('FATUCHE_DB=%s FATUCHE_STORAGE=%s php -S 127.0.0.1:%d -t %s %s > /dev/null 2>&1 & echo $!', escapeshellarg($db), escapeshellarg($tmp), $port, escapeshellarg($root . '/public'), escapeshellarg($root . '/public/index.php')));
$mockPid = (int) shell_exec(sprintf('php -S 127.0.0.1:%d %s > /dev/null 2>&1 & echo $!', $mockPort, escapeshellarg($root . '/tests/mock_openrouter.php')));
register_shutdown_function(function () use ($pid, $mockPid, $tmp) {
    $pid && posix_kill($pid, SIGTERM);
    $mockPid && posix_kill($mockPid, SIGTERM);
    shell_exec('rm -rf ' . escapeshellarg($tmp));
});
$base = "http://127.0.0.1:$port";
for ($i = 0; $i < 50 && (!@file_get_contents($base . '/robots.txt') || !@file_get_contents("http://127.0.0.1:$mockPort/api/v1/models")); $i++) {
    usleep(100000);
}

echo "\nS2. Antes de assinar: só leitura\n";
$cli = new Http($base);
$tok = substr(client_access_link($acc, $id), strrpos(client_access_link($acc, $id), '/') + 1);
$cli->get('/cliente/acesso/' . $tok);
$cli->get('/cliente?area=reuniao');
ok(str_contains($cli->html, 'Ler e assinar') && str_contains($cli->html, 'documento-kit/' . $vh['id']), 'área do cliente oferece “Ler e assinar”');
$cli->get('/cliente/documento-kit/' . $vh['id']);
$page = $cli->html;
ok(str_contains($page, 'data-assin') && str_contains($page, 'Assinar documento') && str_contains($page, 'Pedir retificação') && str_contains($page, 'assinatura.js'), 'documento com “Assinar” e “Pedir retificação”');
ok(!str_contains($page, 'Baixar .docx') && !str_contains($page, 'data-imprimir') && str_contains($page, '@media print') && str_contains($page, 'doc-sem-impressao'), 'sem .docx nem imprimir; impressão bloqueada até assinar');
$cli->get('/cliente/documento-kit/' . $vh['id'] . '?baixar=docx');
ok(!str_contains($cli->type, 'wordprocessingml'), 'o .docx não sai pela área do cliente');
$cli->get('/cliente/documento-kit/' . $vpar['id']);
ok(!str_contains($cli->html, 'data-assin') && str_contains($cli->html, 'data-imprimir'), 'parecer (só leitura) não é assinado');

echo "\nS3. Assinatura\n";
$cli->get('/cliente/documento-kit/' . $vh['id']);
$png = 'data:image/png;base64,' . base64_encode((function () {
    $im = imagecreatetruecolor(300, 90);
    imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
    imageline($im, 10, 60, 280, 20, imagecolorallocate($im, 24, 0, 173));
    ob_start();
    imagepng($im);
    return (string) ob_get_clean();
})());
$sign = ['nome' => 'bruno teste souza', 'cpf' => '529.982.247-25', 'aceite' => '1', 'rubrica' => $png, 'geo_lat' => '-25.4284', 'geo_lng' => '-49.2733', 'geo_acc' => '18', 'fuso' => 'America/Sao_Paulo', 'tela' => '390x844 @3'];
$j = $cli->json('/cliente/documento-kit/' . $vh['id'] . '/assinar', $sign + ['codigo' => '123456']);
ok(!$j['ok'] && str_contains((string) $j['erro'], 'Peça o código'), 'sem pedir o código, não assina');
$j = $cli->json('/cliente/documento-kit/' . $vh['id'] . '/codigo', []);
ok($j['ok'] && str_contains((string) $j['msg'], 'br•'), 'código enviado ao e-mail do cadastro (mascarado na tela)');
$m = implode('', $mails());
preg_match('/é: (\d{6})/u', $m, $cd);
ok(isset($cd[1]) && str_contains($m, 'bruno@exemplo.com'), 'e-mail com o código de 6 números');
$j = $cli->json('/cliente/documento-kit/' . $vh['id'] . '/assinar', array_merge($sign, ['nome' => 'Outra Pessoa', 'codigo' => $cd[1]]));
ok(!$j['ok'] && str_contains((string) $j['erro'], 'nome completo'), 'nome diferente do documento: recusado');
$j = $cli->json('/cliente/documento-kit/' . $vh['id'] . '/assinar', array_merge($sign, ['cpf' => '111.444.777-35', 'codigo' => $cd[1]]));
ok(!$j['ok'] && str_contains((string) $j['erro'], 'CPF não confere'), 'CPF diferente do cadastro: recusado');
$j = $cli->json('/cliente/documento-kit/' . $vh['id'] . '/assinar', array_merge($sign, ['rubrica' => 'data:image/png;base64,AAAA', 'codigo' => $cd[1]]));
ok(!$j['ok'] && str_contains((string) $j['erro'], 'rubrica'), 'sem rubrica: recusado');
$j = $cli->json('/cliente/documento-kit/' . $vh['id'] . '/assinar', array_merge($sign, ['codigo' => '000000']));
ok(!$j['ok'] && str_contains((string) $j['erro'], 'Código incorreto'), 'código errado: recusado');
$j = $cli->json('/cliente/documento-kit/' . $vh['id'] . '/assinar', array_merge($sign, ['codigo' => $cd[1]]));
ok($j['ok'] && str_contains((string) $j['ir'], 'assinado=1'), 'assinado com nome, CPF, rubrica e código');
$sig = sig_for_version((int) $vh['id']);
$ev = json_decode((string) ($sig['evidence'] ?? '{}'), true);
ok($sig && $sig['ip'] === '127.0.0.1' && str_contains((string) $sig['user_agent'], 'Navegador/1.0') && $sig['doc_hash'] === hash('sha256', (string) $vh['content']), 'evidências: IP, navegador e hash SHA-256 do texto');
ok(($ev['localizacao']['status'] ?? '') === 'ok' && $ev['localizacao']['lat'] === -25.4284 && $ev['localizacao']['precisao_m'] === 18 && $ev['aparelho']['fuso'] === 'America/Sao_Paulo' && $ev['email_confirmado']['para'] === 'bruno@exemplo.com', 'localização, fuso e e-mail confirmado registrados');
ok(str_contains((string) $ev['aceite'], 'MP 2.200-2/2001') && $ev['momento']['utc'] !== '', 'aceite com a base legal e data e hora UTC');
$f = one('SELECT * FROM files WHERE id = ?', [$sig['file_id']]);
$copy = (string) file_get_contents($tmp . '/uploads/' . $id . '/' . $f['stored_name']);
ok($f['kind'] === 'assinado' && (int) kit_latest($id, 'honorarios')['signed_file_id'] === (int) $f['id'] && hash('sha256', $copy) === $sig['file_hash'], 'via assinada guardada nos arquivos do atendimento, ligada à versão');
ok(str_contains($copy, 'Registro de assinatura eletrônica') && str_contains($copy, $sig['code']) && str_contains($copy, $sig['doc_hash']) && str_contains($copy, '-25.428400, -49.273300') && str_contains($copy, 'data:image/png;base64'), 'via assinada com a folha de evidências e a rubrica');
$last = implode('', array_filter($mails(), fn($x) => str_contains($x, 'Documento assinado')));
ok(str_contains($last, 'Documento assinado') && str_contains($last, 'Content-Disposition: attachment') && str_contains($last, 'assinado.html') && str_contains($last, $sig['code']), 'cópia assinada enviada por e-mail, em anexo');
$cli->get('/cliente/documento-kit/' . $vh['id'] . '?assinado=1');
ok(str_contains($cli->html, 'Documento assinado.') && str_contains($cli->html, 'data-imprimir') && !str_contains($cli->html, 'data-assin') && !str_contains($cli->html, 'doc-sem-impressao'), 'depois de assinar: a via pode ser impressa ou salva em PDF');
$cli->get('/cliente?area=reuniao');
$j = $cli->json('/cliente/documento-kit/' . $vh['id'] . '/codigo', []);
ok(!$j['ok'] && str_contains((string) $j['erro'], 'já foi assinado'), 'não assina duas vezes');
$pub = new Http($base);
$pub->get('/verificar/' . $sig['code']);
ok(str_contains($pub->html, 'Assinatura válida') && str_contains($pub->html, $sig['doc_hash']) && str_contains($pub->html, 'Bruno T. Souza') && !str_contains($pub->html, '529.982'), 'conferência pública pelo código (sem CPF, nome abreviado)');
$pub->get('/verificar/XXXX-0000-1111');
ok(str_contains($pub->html, 'Código não encontrado'), 'código inexistente');

echo "\nS4. Retificação com avaliação da IA\n";
set_setting('llm_endpoint', "http://127.0.0.1:$mockPort/api/v1");
set_setting('llm_enabled', '1');
set_setting('llm_api_key', 'sk-or-v1-teste-valido-0000');
set_setting('llm_model', 'google/gemini-2.5-flash');
$cli->get('/cliente/documento-kit/' . $vp['id']);
$j = $cli->json('/cliente/documento-kit/' . $vp['id'] . '/retificar', ['texto' => 'curto']);
ok(!$j['ok'], 'pedido vazio ou curto é recusado');
$j = $cli->json('/cliente/documento-kit/' . $vp['id'] . '/retificar', ['texto' => 'Meu estado civil é casado, não solteiro.']);
ok($j['ok'] && (bool) one("SELECT 1 FROM doc_rectifications WHERE version_id = ? AND status = 'aberta'", [$vp['id']]), 'pedido de retificação registrado');
for ($i = 0; $i < 30 && !scalar('SELECT ai_note FROM doc_rectifications WHERE version_id = ?', [$vp['id']]); $i++) {
    usleep(200000);
}
$note = json_decode((string) scalar('SELECT ai_note FROM doc_rectifications WHERE version_id = ?', [$vp['id']]), true);
ok(($note['avaliacao'] ?? '') === 'procedente' && ($note['sugestao'] ?? '') === 'casado', 'a IA avalia o pedido depois da resposta (procede, com sugestão)');
$j = $cli->json('/cliente/documento-kit/' . $vh['id'] . '/retificar', ['texto' => 'Quero mudar o valor dos honorários.']);
ok(!$j['ok'] && str_contains((string) $j['erro'], 'já foi assinado'), 'documento assinado não recebe retificação pela página');
$adm = new Http($base);
$adm->get('/painel/entrar');
$adm->post('/painel/entrar', ['email' => 'sidney@teste.local', 'password' => 'senha-de-teste-1']);
$adm->get('/painel/atendimento/' . $id);
ok(str_contains($adm->html, 'Retificação pedida pelo cliente') && str_contains($adm->html, 'Meu estado civil é casado') && str_contains($adm->html, 'IA: Procede') && str_contains($adm->html, 'Sugestão: “casado”'), 'Kit mostra o pedido e a avaliação da IA');
ok(str_contains($adm->html, 'assinatura eletrônica · ') && str_contains($adm->html, 'código ' . $sig['code']), 'Kit mostra a assinatura eletrônica com o código e a localização');
$rid = (int) scalar('SELECT id FROM doc_rectifications WHERE version_id = ?', [$vp['id']]);
$adm->post('/painel/atendimento/' . $id, ['op' => 'kit_retificacao', 'rid' => $rid, 'acao' => 'resolver', 'resolucao' => 'Estado civil corrigido']);
ok(scalar('SELECT status FROM doc_rectifications WHERE id = ?', [$rid]) === 'resolvida', 'Sidney marca a retificação como resolvida');
$adm->get('/painel/atendimento/' . $id . '/kit/' . $vh['id'] . '?baixar=docx');
ok(str_contains($adm->type, 'wordprocessingml'), 'o .docx continua disponível no painel do escritório');

echo "\n" . ($fails ? "FALHAS: $fails" : 'TUDO OK') . " · $passes verificações aprovadas\n";
exit($fails ? 1 : 0);
