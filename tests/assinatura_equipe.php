<?php
/**
 * Assinaturas do escritório antes do cliente: testemunhas aprovam no painel
 * (sem e-mail), o advogado assina com o certificado digital (A1 no servidor ou
 * A3 fora, devolvendo o PDF), e o cliente assina por último, com o selo e a
 * faixa acrescentados sem invalidar a assinatura digital.
 *
 * Uso: php tests/assinatura_equipe.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/fatuche-equipe-' . getmypid();
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
    public string $type = '';
    public string $url = '';
    private string $jar;
    public function __construct(private string $base) { $this->jar = tempnam(sys_get_temp_dir(), 'jar'); }
    public function get(string $p): string { return $this->req('GET', $p); }
    public function post(string $p, array $data, bool $multipart = false): string
    {
        if (!isset($data['_csrf']) && preg_match('/name="_csrf" value="([^"]+)"/', $this->html, $m)) {
            $data['_csrf'] = $m[1];
        }
        return $this->req('POST', $p, $data, $multipart);
    }
    public function json(string $p, array $data): array { return json_decode($this->post($p, $data), true) ?: []; }
    public function login(string $email, string $pass): void
    {
        $this->get('/painel/entrar');
        $this->post('/painel/entrar', ['email' => $email, 'password' => $pass]);
    }
    private function req(string $method, string $path, array $data = [], bool $multipart = false): string
    {
        $ch = curl_init($this->base . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_FOLLOWLOCATION => true, CURLOPT_USERAGENT => 'Teste/1.0']);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $multipart ? $data : http_build_query($data));
        }
        $out = (string) curl_exec($ch);
        $this->type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $this->url = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        if (!str_starts_with(ltrim($out), '{') && !str_starts_with($out, '%PDF')) {
            $this->html = $out;
        }
        return $out;
    }
}

function cpf_valido(string $nove): string
{
    $d = array_map('intval', str_split($nove));
    for ($t = 9; $t < 11; $t++) {
        $s = 0;
        for ($i = 0; $i < $t; $i++) {
            $s += $d[$i] * (($t + 1) - $i);
        }
        $d[$t] = ((10 * $s) % 11) % 10;
    }
    return implode('', $d);
}

/** Certificado A1 de teste (AC própria), com o CPF no nome, como no e-CPF. */
function cert_teste(string $nome, string $cpf, string $senha): string
{
    $caKey = openssl_pkey_new(['private_key_bits' => 2048]);
    $ca = openssl_csr_sign(openssl_csr_new(['countryName' => 'BR', 'organizationName' => 'ICP-Brasil', 'commonName' => 'AC Teste'], $caKey), null, $caKey, 365, [], 1001);
    $key = openssl_pkey_new(['private_key_bits' => 2048]);
    $crt = openssl_csr_sign(openssl_csr_new(['countryName' => 'BR', 'organizationName' => 'ICP-Brasil', 'commonName' => $nome . ':' . $cpf], $key), $ca, $caKey, 365, [], 2002); // séries distintas, como nos certificados reais
    openssl_x509_export($ca, $caPem);
    openssl_pkcs12_export($crt, $p12, $key, $senha, ['extracerts' => [$caPem]]);
    return $p12;
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

/** Assinatura com token (A3) simulada: baixa o PDF, assina "no token" e envia de volta. */
$a3 = function (Http $h, int $pid, array $cert, ?array $selo = null) use (&$tmp): string {
    $h->get('/painel/assinaturas/' . $pid);
    $pdf = $h->post('/painel/assinaturas/' . $pid . '/pdf-a3', $selo ? ['selo' => json_encode($selo)] : []);
    [$signed] = pdfx_sign($pdf, $cert, 0, [0, 0, 0, 0], ['nome' => 'token']);
    file_put_contents($tmp . '/token-' . $pid . '.pdf', (string) $signed);
    $h->get('/painel/assinaturas/' . $pid);
    $h->post('/painel/assinaturas/' . $pid . '/a3', ['pdf' => new CURLFile($tmp . '/token-' . $pid . '.pdf', 'application/pdf', 'assinado.pdf')], true);
    return $pdf;
};

echo "E1. Equipe: qualificação, rubrica e certificado\n";
set_setting('mail_driver', 'arquivo');
seed_templates();
$pw = 'senha-de-teste-1';
$mk = fn(string $n, string $e, string $roles) => insert('users', ['name' => $n, 'email' => $e, 'roles' => $roles, 'pass_hash' => password_hash($pw, PASSWORD_DEFAULT), 'active' => 1, 'created_at' => now_str()]);
$sid = $mk('Sidney', 'sidney@teste.local', 'supervisor');
$ana = $mk('Ana', 'ana@teste.local', 'gestor');
$car = $mk('Carlos', 'carlos@teste.local', 'gestor');
set_setting('operator_user_id', (string) $sid);
$cpfS = cpf_valido('123456789');
$cpfA = cpf_valido('987654321');
$cpfC = cpf_valido('111222333');
$tr = json_encode(['w' => 500, 'h' => 150, 's' => [[[20, 110], [60, 40], [90, 100], [130, 30], [170, 90]], [[200, 60], [300, 70]]]]);

$port = 25000 + random_int(0, 900);
$pid = (int) shell_exec(sprintf('FATUCHE_DB=%s FATUCHE_STORAGE=%s php -S 127.0.0.1:%d -t %s %s > /dev/null 2>&1 & echo $!', escapeshellarg($db), escapeshellarg($tmp), $port, escapeshellarg($root . '/public'), escapeshellarg($root . '/public/index.php')));
register_shutdown_function(function () use ($pid, $tmp) {
    $pid && posix_kill($pid, SIGTERM);
    shell_exec('chmod -R u+w ' . escapeshellarg($tmp) . '; rm -rf ' . escapeshellarg($tmp));
});
$base = "http://127.0.0.1:$port";
for ($i = 0; $i < 50 && !@file_get_contents($base . '/robots.txt'); $i++) {
    usleep(100000);
}
$qual = fn(string $nome, string $cpf) => ['nome_completo' => $nome, 'nacionalidade' => 'brasileira', 'estado_civil' => 'solteiro(a)', 'uniao_estavel' => 'nao', 'profissao' => 'assistente jurídica',
    'cpf' => $cpf, 'rg' => '12.345.678-9 SESP/PR', 'email' => 'pessoal@teste.local', 'telefone' => '(41) 99999-0000', 'cep' => '80010-000', 'logradouro' => 'Rua B', 'numero' => '10',
    'bairro' => 'Centro', 'cidade' => 'Curitiba', 'uf' => 'pr'];
$hs = new Http($base);
$hs->login('sidney@teste.local', $pw);
$hs->get('/painel/conta');
ok(str_contains($hs->html, 'Qualificação') && str_contains($hs->html, 'data-rubrica-pad') && str_contains($hs->html, 'Como você assina'), 'Minha conta: qualificação, rubrica e certificado');
$hs->post('/painel/conta/dados', $qual('Sidney Fatuche', '000.000.000-00'));
ok(str_contains($hs->html, 'CPF inválido'), 'CPF inválido é recusado');
$hs->post('/painel/conta/dados', ['oab' => 'PR 131.033', 'profissao' => 'advogado'] + $qual('Sidney Fatuche', $cpfS));
$su = one('SELECT * FROM users WHERE id = ?', [$sid]);
ok(team_profile_missing($su) === [] && team_profile($su)['uf'] === 'PR' && str_contains(team_qualification($su), 'OAB PR 131.033'), 'qualificação completa (art. 319 do CPC), com OAB');
$hs->post('/painel/conta/rubrica', ['tracos' => $tr]);
ok((bool) team_signature(one('SELECT * FROM users WHERE id = ?', [$sid])) && str_contains($hs->html, 'Rubrica salva'), 'rubrica desenhada uma vez e guardada');
$pfx = cert_teste('SIDNEY FATUCHE', $cpfS, 'segredo123');
file_put_contents($tmp . '/a1.pfx', $pfx);
$hs->post('/painel/conta/certificado', ['senha' => 'errada', 'certificado' => new CURLFile($tmp . '/a1.pfx', 'application/x-pkcs12', 'a1.pfx')], true);
ok(str_contains($hs->html, 'Senha do certificado incorreta'), 'certificado com senha errada: recusado');
$hs->get('/painel/conta');
$hs->post('/painel/conta/certificado', ['senha' => 'segredo123', 'certificado' => new CURLFile($tmp . '/a1.pfx', 'application/x-pkcs12', 'a1.pfx')], true);
$su = one('SELECT * FROM users WHERE id = ?', [$sid]);
$ci = json_decode((string) $su['cert_info'], true);
ok($su['cert_path'] && $ci['cpf'] === $cpfS && $ci['icp_brasil'] && !str_contains((string) json_encode($su), 'segredo123'), 'certificado A1 guardado (titular e CPF lidos); a senha não fica guardada');
foreach ([[$ana, 'Ana Paula Ribeiro', $cpfA], [$car, 'Carlos Eduardo Lima', $cpfC]] as [$uid, $nome, $cpf]) {
    update('users', $uid, ['profile' => json_encode($qual($nome, $cpf)), 'signature_strokes' => $tr, 'signature_at' => now_str()]);
}
$hs->get('/painel/assinaturas');
$hs->post('/painel/assinaturas/padrao', ['testemunha1' => $ana, 'testemunha2' => $car, 'advogado' => $sid]);
ok(sig_default_users()['testemunhas'] === [$ana, $car] && sig_default_users()['advogado'] === $sid, 'assinantes padrão: duas testemunhas e o advogado');

echo "\nE2. Contrato enviado: as testemunhas assinam primeiro\n";
$c = create_initial_case(['nome' => 'Bruno', 'telefone' => '(41) 97777-1111', 'email' => 'bruno@exemplo.com', 'adaptacao' => ''], 'site');
$id = (int) $c['id'];
$acc = insert('client_accounts', ['name' => 'Bruno', 'email' => 'bruno@exemplo.com', 'created_at' => now_str()]);
q('UPDATE cases SET account_id = ? WHERE id = ?', [$acc, $id]);
foreach (['nome_completo' => 'Bruno Teste Souza', 'cpf' => '529.982.247-25', 'genero' => 'masculino', 'nacionalidade' => 'brasileiro', 'estado_civil' => 'solteiro', 'profissao' => 'motorista',
    'identificacao' => 'RG 123', 'cep' => '80010-000', 'logradouro' => 'Rua A', 'numero' => '1', 'bairro' => 'Centro', 'cidade' => 'Curitiba', 'uf' => 'PR', 'banco' => 'Banco Exemplo S.A.', 'contrato_numero' => '987'] as $k => $v) {
    set_field($id, $k, $v, 'informado', 'contrato');
}
foreach (['honorarios', 'procuracao'] as $k) {
    kit_generate(find_case($id), $k);
    q("UPDATE case_artifact_versions SET status = 'publicado', published_at = ? WHERE case_id = ? AND kind = ?", [now_str(), $id, $k]);
}
$vh = kit_latest($id, 'honorarios');
$vp = kit_latest($id, 'procuracao');
$req = sig_request_open(find_case($id), $vh);
$parts = sig_parties((int) $req['id']);
ok($req && $req['status'] === 'testemunhas' && count($parts) === 3 && (int) $parts[0]['user_id'] === $ana && (int) $parts[1]['user_id'] === $car && (int) $parts[2]['user_id'] === $sid, 'contrato: pedido com 2 testemunhas e o advogado (padrão), na ordem');
ok(sig_request_open(find_case($id), $vp) === null, 'procuração (só o cliente assina): sem pedido do escritório');
$L = sig_doc_layout($vh, $req);
$roles = array_column($L['sigs'], 5, 4);
ok(isset($roles['Ana Paula Ribeiro'], $roles['Carlos Eduardo Lima'], $roles['Bruno Teste Souza']) && $roles['Ana Paula Ribeiro'] === 'testemunha1' && in_array('escritorio', $roles, true), 'linhas das testemunhas com o nome de cada uma; cada linha sabe de quem é');

$cli = new Http($base);
$tok = substr(client_access_link($acc, $id), strrpos(client_access_link($acc, $id), '/') + 1);
$cli->get('/cliente/acesso/' . $tok);
$cli->get('/cliente/documento-kit/' . $vh['id']);
ok(str_contains($cli->html, 'O escritório está assinando este documento') && !str_contains($cli->html, 'data-abrir="assinar"') && str_contains($cli->html, 'Pedir retificação'), 'cliente: lê e pode pedir retificação, mas só assina depois do escritório');
$j = $cli->json('/cliente/documento-kit/' . $vh['id'] . '/assinar', ['nome' => 'Bruno Teste Souza', 'cpf' => '529.982.247-25', 'aceite' => '1', 'codigo' => '123456', 'tracos' => $tr]);
ok(!$j['ok'] && str_contains((string) $j['erro'], 'escritório ainda está assinando'), 'assinatura do cliente bloqueada no servidor enquanto o escritório assina');

$pS = $parts[2];
$hs->get('/painel/assinaturas/' . $pS['id']);
ok(str_contains($hs->html, 'O advogado assina depois das testemunhas'), 'advogado não assina antes das testemunhas');
$ha = new Http($base);
$ha->login('ana@teste.local', $pw);
$ha->get('/painel/assinaturas');
ok(str_contains($ha->html, 'Para você assinar') && str_contains($ha->html, 'Ver e assinar') && str_contains($ha->html, 'Bruno'), 'a testemunha vê o pedido no painel (sem e-mail)');
$ha->get('/painel/assinaturas/' . $parts[1]['id']);
ok(!str_contains($ha->html, 'selo-dados') && !str_contains($ha->html, 'Aprovar e assinar'), 'uma testemunha não vê nem assina o pedido da outra');
$ha->post('/painel/assinaturas/' . $parts[1]['id'] . '/assinar', ['aceite' => '1']);
ok(!one('SELECT signed_at FROM sig_parties WHERE id = ?', [$parts[1]['id']])['signed_at'], 'nem forçando o envio: a assinatura da outra testemunha continua pendente');
$ha->get('/painel/assinaturas/' . $parts[0]['id']);
preg_match('#<script type="application/json" id="selo-dados">(.*?)</script>#s', $ha->html, $sd);
$sd = json_decode($sd[1] ?? '{}', true);
$t1 = array_values(array_filter($L['sigs'], fn($x) => $x[5] === 'testemunha1'))[0];
ok(($sd['selo']['pagina'] ?? 0) === $t1[0] && $sd['selo']['y'] < $t1[3] && str_contains($ha->html, 'TESTEMUNHA · ASSINADO ELETRONICAMENTE') && str_contains($ha->html, 'id="selo-modelo"'), 'selo da testemunha sugerido sobre a linha dela, com a rubrica guardada');
$ha->post('/painel/assinaturas/' . $parts[0]['id'] . '/assinar', ['selo' => json_encode($sd['selo'])]);
ok(str_contains($ha->html, 'Marque a declaração'), 'sem a declaração, não assina');
$ha->get('/painel/assinaturas/' . $parts[0]['id']);
ok(str_contains($ha->html, 'Você assina com: <strong>Aprovação no painel') && !str_contains($ha->html, 'name="senha"') && str_contains($ha->html, 'Aprovar e assinar como testemunha'), 'sem certificado: a testemunha só aprova na própria tela (sem senha)');
$ha->post('/painel/assinaturas/' . $parts[0]['id'] . '/assinar', ['aceite' => '1', 'selo' => json_encode(['pagina' => $sd['selo']['pagina'], 'x' => $sd['selo']['x'] + 3, 'y' => $sd['selo']['y'], 'largura_folha_px' => 800])]);
$p1 = one('SELECT * FROM sig_parties WHERE id = ?', [$parts[0]['id']]);
$ev = json_decode((string) $p1['evidence'], true);
ok($p1['signed_at'] && $p1['name'] === 'Ana Paula Ribeiro' && $p1['cpf'] === $cpfA && $p1['method'] === 'aprovacao' && str_contains($ev['meio'], 'aprovação no painel') && str_contains($ev['usuario']['qualificacao'], 'CPF') && $ev['selo']['x'] == $sd['selo']['x'] + 3, 'testemunha 1 assinou: nome, CPF, qualificação, posição do selo e evidências');
$blocked = function (string $sql): bool {
    try { q($sql); return false; } catch (Throwable $e) { return true; }
};
ok($blocked("UPDATE sig_parties SET name = 'X' WHERE id = " . (int) $p1['id']), 'assinatura da testemunha protegida contra alteração');
$hc = new Http($base);
$hc->login('carlos@teste.local', $pw);
$hc->get('/painel/assinaturas/' . $parts[1]['id']);
preg_match('#id="selo-dados">(.*?)</script>#s', $hc->html, $sd2);
$sd2 = json_decode($sd2[1] ?? '{}', true);
$rect1 = [$ev['selo']['x'], $ev['selo']['y']];
ok(!(($sd2['selo']['pagina'] ?? 0) === $ev['selo']['pagina'] && abs($sd2['selo']['x'] - $rect1[0]) < SEAL_W && abs($sd2['selo']['y'] - $rect1[1]) < SEAL_H), 'selo da testemunha 2 sugerido sem cobrir o da testemunha 1');
// Carlos tem token (A3): cadastra pelo PDF de cadastro assinado no token
$pfxC = cert_teste('CARLOS EDUARDO LIMA', $cpfC, 'tokenC');
openssl_pkcs12_read($pfxC, $certC, 'tokenC');
$hc->get('/painel/conta');
ok(str_contains($hc->html, 'Certificado A3 (token ou cartão)') && str_contains($hc->html, 'PDF de cadastro'), 'Minha conta oferece o certificado A3 (token), com o PDF de cadastro');
$cad = $hc->get('/painel/conta/certificado-a3/pdf');
file_put_contents($tmp . '/cadastro.pdf', (string) pdfx_sign($cad, $certC, 0, [0, 0, 0, 0], ['nome' => 'Carlos'])[0]);
$hc->get('/painel/conta');
$hc->post('/painel/conta/certificado-a3', ['arquivo' => new CURLFile($tmp . '/cadastro.pdf', 'application/pdf', 'cadastro.pdf')], true);
$cu = one('SELECT * FROM users WHERE id = ?', [$car]);
ok(str_starts_with($cad, '%PDF') && team_sign_method($cu) === 'a3' && team_cert($cu)['cpf'] === $cpfC && !$cu['cert_path'], 'A3 cadastrado pelo PDF assinado no token: só o certificado público fica no sistema');
$hc->get('/painel/assinaturas/' . $parts[1]['id']);
ok(str_contains($hc->html, 'Certificado A3 (token ou cartão)') && str_contains($hc->html, 'Baixar o PDF para assinar no token') && str_contains($hc->html, 'TESTEMUNHA · CERTIFICADO ICP-BRASIL'), 'testemunha com token: baixa, assina no token e envia; selo indica o certificado');
$a3($hc, (int) $parts[1]['id'], $certC, $sd2['selo']);
$p2 = one('SELECT * FROM sig_parties WHERE id = ?', [$parts[1]['id']]);
$req = one('SELECT * FROM sig_requests WHERE id = ?', [$req['id']]);
ok($p2['method'] === 'certificado_a3' && json_decode((string) $p2['cert_info'], true)['cpf'] === $cpfC, 'testemunha 2 assinou com o certificado A3');
ok($req['status'] === 'advogado' && str_contains((string) find_case($id)['next_action'], 'Assinar pelo escritório'), 'testemunhas assinaram: vez do advogado');

echo "\nE3. Advogado assina com o certificado A1\n";
$hs->get('/painel/assinaturas');
ok(str_contains($hs->html, 'Ver e assinar') && str_contains($hs->html, 'Advogado'), 'o pedido aparece para o advogado');
$hs->get('/painel/assinaturas/' . $pS['id']);
ok(str_contains($hs->html, 'SIDNEY FATUCHE') && str_contains($hs->html, 'senha_certificado') && str_contains($hs->html, 'Certificado A1') && str_contains($hs->html, 'ASSINADO COM CERTIFICADO ICP-BRASIL'), 'tela do advogado: assina com o A1 cadastrado (senha do certificado)');
ok(substr_count($hs->html, 'TESTEMUNHA · ') === 2, 'os selos das testemunhas já aparecem no documento');
preg_match('#id="selo-dados">(.*?)</script>#s', $hs->html, $sd3);
$sd3 = json_decode($sd3[1] ?? '{}', true);
$hs->post('/painel/assinaturas/' . $pS['id'] . '/assinar', ['aceite' => '1', 'senha_certificado' => 'errada', 'selo' => json_encode($sd3['selo'])]);
ok(str_contains($hs->html, 'Senha do certificado incorreta'), 'senha errada: não assina');
$hs->get('/painel/assinaturas/' . $pS['id']);
$hs->post('/painel/assinaturas/' . $pS['id'] . '/assinar', ['aceite' => '1', 'senha_certificado' => 'segredo123', 'selo' => json_encode($sd3['selo'])]);
$req = one('SELECT * FROM sig_requests WHERE id = ?', [$req['id']]);
$office = (string) @file_get_contents($tmp . '/assinados/' . $req['signed_path']);
$ds = pdfx_signatures($office);
ok($req['status'] === 'cliente' && $office !== '' && hash('sha256', $office) === $req['signed_sha256'] && count($ds) === 2 && $ds[0]['integra'] && $ds[1]['integra'] && $ds[0]['certificado']['cpf'] === $cpfC && $ds[1]['cobre_tudo'] && $ds[1]['certificado']['cpf'] === $cpfS, 'PDF do escritório com as duas assinaturas digitais (testemunha A3 e advogado A1), íntegras, em sequência');
$ps = one('SELECT * FROM sig_parties WHERE id = ?', [$pS['id']]);
ok($ps['method'] === 'certificado_a1' && json_decode((string) $ps['cert_info'], true)['cpf'] === $cpfS && (bool) one("SELECT 1 FROM sig_log WHERE code = ? AND event = 'advogado_assinou'", [$req['code']]), 'assinatura do advogado registrada com os dados do certificado e no histórico');
$sigTxt = (string) shell_exec('pdfsig ' . escapeshellarg($tmp . '/assinados/' . $req['signed_path']) . ' 2>&1');
if ($sigTxt !== '' && !str_contains($sigTxt, 'not found')) {
    ok(str_contains($sigTxt, 'Signature is Valid') && str_contains($sigTxt, 'Total document signed'), 'leitor independente (pdfsig): assinatura válida, documento inteiro');
}
$m = implode('', array_filter($mails(), fn($x) => str_contains($x, 'pronto para assinar')));
ok(str_contains($m, 'bruno@exemplo.com') && str_contains($m, 'Contrato de honor'), 'cliente avisado por e-mail que o documento está pronto');

echo "\nE4. Cliente assina por último\n";
$cli->get('/cliente/documento-kit/' . $vh['id']);
ok(str_contains($cli->html, 'data-abrir="assinar"') && substr_count($cli->html, 'TESTEMUNHA · ') === 2 && str_contains($cli->html, 'ASSINADO COM CERTIFICADO'), 'cliente vê os selos do escritório e agora pode assinar');
ok(str_contains($cli->html, '>Ana</text>') || str_contains($cli->html, 'Ana'), 'a faixa da prévia já lista quem assinou');
preg_match('#id="assin-dados">(.*?)</script>#s', $cli->html, $cd0);
$cd0 = json_decode($cd0[1] ?? '{}', true);
$officeSeals = sig_party_rects($req);
$overlap = false;
foreach ($officeSeals[$cd0['selo']['pagina']] ?? [] as [$x, $y, $w, $h]) {
    $overlap = $overlap || (abs($cd0['selo']['x'] - $x) < SEAL_W - 0.5 && abs($cd0['selo']['y'] - $y) < SEAL_H - 0.5);
}
ok(!$overlap, 'selo do cliente sugerido sobre a linha dele, sem cobrir os selos do escritório');
$cli->json('/cliente/documento-kit/' . $vh['id'] . '/dados', ['nome' => 'Bruno Teste Souza', 'cpf' => '529.982.247-25']);
$cli->json('/cliente/documento-kit/' . $vh['id'] . '/codigo', []);
preg_match_all('/é: (\d{6})/u', implode('', $mails()), $codes);
$j = $cli->json('/cliente/documento-kit/' . $vh['id'] . '/assinar', ['nome' => 'Bruno Teste Souza', 'cpf' => '529.982.247-25', 'aceite' => '1', 'codigo' => end($codes[1]), 'tracos' => $tr,
    'selo' => json_encode($cd0['selo'])]);
$sig = sig_for_version((int) $vh['id']);
$final = $sig ? (string) file_get_contents($tmp . '/assinados/' . $sig['pdf_path']) : '';
ok(($j['ok'] ?? false) && $sig && $sig['code'] === $req['code'] && one('SELECT status FROM sig_requests WHERE id = ?', [$req['id']])['status'] === 'concluido', 'cliente assinou; o mesmo código de verificação do pedido');
ok(str_starts_with($final, $office) && strlen($final) > strlen($office), 'o PDF do escritório fica intacto: selo e faixa do cliente acrescentados ao fim');
$fs = pdfx_signatures($final);
ok(count($fs) === 2 && $fs[0]['integra'] && $fs[1]['integra'] && !$fs[1]['cobre_tudo'], 'assinaturas digitais continuam válidas depois do acréscimo do cliente');
$txt = preg_replace('/\s+/', ' ', (string) shell_exec('pdftotext ' . escapeshellarg($tmp . '/assinados/' . $sig['pdf_path']) . ' - 2>/dev/null'));
if ($txt !== ' ' && $txt !== '') {
    ok(str_contains($txt, 'Bruno Teste Souza') && str_contains($txt, 'Ana Paula Ribeiro') && str_contains($txt, 'Carlos Eduardo Lima') && str_contains($txt, 'Sidney Fatuche') && str_contains($txt, $sig['code']), 'faixa com o cliente, as testemunhas e o advogado, e o código');
}
$pdfsig2 = (string) shell_exec('pdfsig ' . escapeshellarg($tmp . '/assinados/' . $sig['pdf_path']) . ' 2>&1');
if ($pdfsig2 !== '') {
    ok(str_contains($pdfsig2, 'Signature is Valid'), 'pdfsig: a assinatura do advogado continua válida no PDF final');
}
preg_match_all('#/Type /Page\b(?!s)#', $office, $pp);
ok((int) $sig['pages'] === count($L['pages']), 'mesmo número de páginas: nenhuma folha a mais');

echo "\nE5. Conferência pública com todos os assinantes\n";
$pub = new Http($base);
$pub->get('/verificar/' . $sig['code']);
ok(str_contains($pub->html, 'Ana Paula Ribeiro') && str_contains($pub->html, 'Carlos Eduardo Lima') && str_contains($pub->html, 'Sidney Fatuche') && str_contains($pub->html, 'Bruno Teste Souza'), 'conferência mostra todos os assinantes');
ok(str_contains($pub->html, 'Testemunha 1') && str_contains($pub->html, 'Certificado digital A1 ICP-Brasil') && str_contains($pub->html, 'Certificado digital A3 (token) ICP-Brasil') && str_contains($pub->html, 'aprovação no painel') && str_contains($pub->html, 'Assinatura digital íntegra') && !str_contains($pub->html, $cpfA), 'papel e meio de cada um; certificado conferido; CPFs mascarados');
ok(str_contains($pub->html, 'Testemunha assinou') && str_contains($pub->html, 'Advogado assinou pelo escritório'), 'histórico com cada assinatura');
$pub->get('/verificar/' . $sig['code']);
$j = $pub->json('/verificar/' . $sig['code'] . '/conferir', ['hash' => hash('sha256', $final)]);
ok(($j['resultado'] ?? '') === 'identica', 'a via final confere pelo hash');

echo "\nE6. Advogado com certificado A3 (token)\n";
// Sidney troca o A1 pelo token: cadastra enviando o certificado exportado (.cer)
openssl_pkcs12_read($pfx, $certS, 'segredo123');
file_put_contents($tmp . '/sidney.cer', base64_decode(preg_replace('/-----[^-]+-----|\s+/', '', $certS['cert'])));
$hs->get('/painel/conta');
$hs->post('/painel/conta/certificado-a3', ['arquivo' => new CURLFile($tmp . '/sidney.cer', 'application/pkix-cert', 'sidney.cer')], true);
$su = one('SELECT * FROM users WHERE id = ?', [$sid]);
ok(team_sign_method($su) === 'a3' && !$su['cert_path'] && !glob($tmp . '/certificados/u' . $sid . '-*'), 'A3 cadastrado pelo .cer exportado; o arquivo A1 anterior é apagado');
$c2 = create_initial_case(['nome' => 'Dora', 'telefone' => '(41) 96666-2222', 'email' => 'dora@exemplo.com', 'adaptacao' => ''], 'site');
foreach (['nome_completo' => 'Dora Lima', 'cpf' => cpf_valido('222333444')] as $k => $v) {
    set_field((int) $c2['id'], $k, $v, 'informado', 'contrato');
}
kit_generate(find_case((int) $c2['id']), 'honorarios');
q("UPDATE case_artifact_versions SET status = 'publicado', published_at = ? WHERE case_id = ? AND kind = 'honorarios'", [now_str(), $c2['id']]);
$v2 = kit_latest((int) $c2['id'], 'honorarios');
$r2 = sig_request_open(find_case((int) $c2['id']), $v2);
$pp2 = sig_parties((int) $r2['id']);
$ha->get('/painel/assinaturas/' . $pp2[0]['id']);
$ha->post('/painel/assinaturas/' . $pp2[0]['id'] . '/assinar', ['aceite' => '1']);
$a3($hc, (int) $pp2[1]['id'], $certC);
$hs->get('/painel/assinaturas/' . $pp2[2]['id']);
ok(str_contains($hs->html, 'Baixar o PDF para assinar no token') && !str_contains($hs->html, 'senha_certificado'), 'tela do advogado com token: baixar, assinar e enviar');
$baseA3 = $hs->post('/painel/assinaturas/' . $pp2[2]['id'] . '/pdf-a3', []);
ok(str_starts_with($baseA3, '%PDF') && count(pdfx_signatures($baseA3)) === 1, 'baixa o PDF com o selo, já com a assinatura da testemunha A3');
file_put_contents($tmp . '/outro.pdf', (string) pdfx_sign(pdoc_pdf(sig_doc_layout($v2)), $certS, 0, [0, 0, 0, 0], [])[0]);
$hs->get('/painel/assinaturas/' . $pp2[2]['id']);
$hs->post('/painel/assinaturas/' . $pp2[2]['id'] . '/a3', ['pdf' => new CURLFile($tmp . '/outro.pdf', 'application/pdf', 'outro.pdf')], true);
ok(str_contains($hs->html, 'não é o PDF baixado'), 'PDF diferente do baixado: recusado');
file_put_contents($tmp . '/fora.pdf', (string) pdfx_sign($baseA3, $certC, 0, [0, 0, 0, 0], [])[0]);
$hs->get('/painel/assinaturas/' . $pp2[2]['id']);
$hs->post('/painel/assinaturas/' . $pp2[2]['id'] . '/a3', ['pdf' => new CURLFile($tmp . '/fora.pdf', 'application/pdf', 'fora.pdf')], true);
ok(str_contains($hs->html, 'não é o do seu CPF'), 'assinado com o token de outra pessoa: recusado');
file_put_contents($tmp . '/fora.pdf', (string) pdfx_sign($baseA3, $certS, 0, [0, 0, 0, 0], [])[0]);
$hs->get('/painel/assinaturas/' . $pp2[2]['id']);
$hs->post('/painel/assinaturas/' . $pp2[2]['id'] . '/a3', ['pdf' => new CURLFile($tmp . '/fora.pdf', 'application/pdf', 'fora.pdf')], true);
$r2 = one('SELECT * FROM sig_requests WHERE id = ?', [$r2['id']]);
$fin2 = (string) file_get_contents($tmp . '/assinados/' . $r2['signed_path']);
ok($r2['status'] === 'cliente' && one('SELECT method FROM sig_parties WHERE id = ?', [$pp2[2]['id']])['method'] === 'certificado_a3' && count(array_filter(pdfx_signatures($fin2), fn($x) => $x['integra'])) === 2, 'PDF assinado no token conferido (mesmo arquivo, íntegro, CPF do advogado): vai para o cliente');

echo "\nE6b. Sem certificado: aprovação no painel, para todos\n";
$hc->get('/painel/conta');
$hc->post('/painel/conta/certificado/remover', []);
$hs->get('/painel/conta');
$hs->post('/painel/conta/certificado/remover', []);
ok(team_sign_method(one('SELECT * FROM users WHERE id = ?', [$car])) === 'aprovacao' && team_sign_method(one('SELECT * FROM users WHERE id = ?', [$sid])) === 'aprovacao', 'certificado removido: a pessoa passa a assinar pela aprovação no painel');
$c3 = create_initial_case(['nome' => 'Elias', 'telefone' => '(41) 95555-3333', 'email' => 'elias@exemplo.com', 'adaptacao' => ''], 'site');
set_field((int) $c3['id'], 'nome_completo', 'Elias Rocha', 'informado', 'contrato');
kit_generate(find_case((int) $c3['id']), 'honorarios');
q("UPDATE case_artifact_versions SET status = 'publicado', published_at = ? WHERE case_id = ? AND kind = 'honorarios'", [now_str(), $c3['id']]);
$r3 = sig_request_open(find_case((int) $c3['id']), kit_latest((int) $c3['id'], 'honorarios'));
foreach (array_map(null, sig_parties((int) $r3['id']), [$ha, $hc, $hs]) as [$pt, $h]) {
    $h->get('/painel/assinaturas/' . $pt['id']);
    $h->post('/painel/assinaturas/' . $pt['id'] . '/assinar', ['aceite' => '1']);
}
$r3 = one('SELECT * FROM sig_requests WHERE id = ?', [$r3['id']]);
$methods = array_column(sig_parties((int) $r3['id']), 'method');
ok($r3['status'] === 'cliente' && $methods === ['aprovacao', 'aprovacao', 'aprovacao'] && str_contains(implode('', array_map(fn($p) => (string) $p['seal'], sig_parties((int) $r3['id']))), 'ASSINADO ELETRONICAMENTE'), 'testemunhas e advogado sem certificado assinam pela aprovação no painel; o documento vai para o cliente');

echo "\nE7. Contratação formalizada com todos os documentos assinados\n";
$cli->get('/cliente/documento-kit/' . $vp['id']);
$cli->json('/cliente/documento-kit/' . $vp['id'] . '/codigo', []);
preg_match_all('/é: (\d{6})/u', implode('', $mails()), $codes);
$cli->json('/cliente/documento-kit/' . $vp['id'] . '/assinar', ['nome' => 'Bruno Teste Souza', 'cpf' => '529.982.247-25', 'aceite' => '1', 'codigo' => end($codes[1]), 'tracos' => $tr]);
ok(find_case($id)['contract_state'] === 'formalizado', 'contrato (escritório + cliente) e procuração assinados: formalizado');

echo "\nE8. Teste da assinatura conjunta: contrato gerado ao escolher o cliente\n";
// Carlos volta a ter o token (A3); Ana e Sidney sem certificado: aprovação no painel
$hc->get('/painel/conta');
$hc->post('/painel/conta/certificado-a3', ['arquivo' => new CURLFile($tmp . '/cadastro.pdf', 'application/pdf', 'cadastro.pdf')], true);
$hs->get('/painel/assinaturas');
ok(str_contains($hs->html, 'Testar a assinatura conjunta'), 'Assinaturas leva à tela de teste');
$hs->get('/painel/assinaturas/teste');
ok(str_contains($hs->html, 'Gerar contrato de teste') && str_contains($hs->html, 'Bruno · ') && str_contains($hs->html, 'Certificado A3 (token ou cartão)') && str_contains($hs->html, 'Aprovação no painel'), 'tela de teste: escolhe o cliente e mostra como cada pessoa assina');
$ha->get('/painel/assinaturas/teste');
ok(!str_contains($ha->html, 'Gerar contrato de teste'), 'só quem gerencia cria testes');
$kitAntes = kit_latest($id, 'honorarios')['id'];
$estadoAntes = find_case($id)['contract_state'];
$eventosAntes = (int) scalar('SELECT COUNT(*) FROM events WHERE case_id = ?', [$id]);
$chatAntes = (int) scalar('SELECT COUNT(*) FROM chat_messages WHERE case_id = ?', [$id]);
$mailsAntes = count($mails());
$mailsBruno = count(array_filter($mails(), fn($m) => str_starts_with($m, 'To: bruno@exemplo.com')));
$hs->post('/painel/assinaturas/teste', ['case_id' => $id, 'testemunha1' => $ana, 'testemunha2' => $car, 'advogado' => $sid]);
$rt = one('SELECT * FROM sig_requests WHERE teste = 1 ORDER BY id DESC LIMIT 1');
$vt = $rt ? one('SELECT * FROM case_artifact_versions WHERE id = ?', [$rt['version_id']]) : null;
ok($rt && $vt['status'] === 'teste' && (int) $rt['teste_por'] === $sid && str_contains($hs->html, 'Contrato de teste gerado') && str_contains($vt['content'], 'Bruno Teste Souza'), 'contrato de teste gerado com os dados do cliente escolhido');
ok(kit_latest($id, 'honorarios')['id'] === $kitAntes && find_case($id)['contract_state'] === $estadoAntes, 'o kit e a contratação do atendimento não mudam');
$tp = sig_parties((int) $rt['id']);
ok(count($tp) === 3 && (int) $tp[0]['user_id'] === $ana && (int) $tp[1]['user_id'] === $car && (int) $tp[2]['user_id'] === $sid && $rt['status'] === 'testemunhas', 'pedido de teste: testemunhas escolhidas e o advogado, na ordem');
$hs->get('/painel/assinaturas');
ok(!str_contains($hs->html, 'código <a href="' . url('verificar/' . $rt['code'])), 'o teste não se mistura aos pedidos reais em andamento');
$ha->get('/painel/assinaturas');
ok(str_contains($ha->html, 'painel/assinaturas/' . $tp[0]['id']) && str_contains($ha->html, '>teste</span>'), 'a testemunha vê o teste em "Para você assinar", marcado como teste');
$ha->get('/painel/assinaturas/' . $tp[0]['id']);
ok(str_contains($ha->html, 'DOCUMENTO DE TESTE') && str_contains($ha->html, 'Aprovar e assinar como testemunha') && !str_contains($ha->html, 'name="senha"'), 'tela da testemunha: documento marcado como teste; sem certificado, só aprova');
$ha->post('/painel/assinaturas/' . $tp[0]['id'] . '/assinar', ['aceite' => '1']);
$a3pdf = $a3($hc, (int) $tp[1]['id'], $certC);
$hs->get('/painel/assinaturas/' . $tp[2]['id']);
ok(str_contains($hs->html, 'Aprovar e assinar') && !str_contains($hs->html, 'senha_certificado'), 'advogado sem certificado: aprova no painel');
$hs->post('/painel/assinaturas/' . $tp[2]['id'] . '/assinar', ['aceite' => '1']);
$rt = one('SELECT * FROM sig_requests WHERE id = ?', [$rt['id']]);
ok($rt['status'] === 'cliente' && array_column(sig_parties((int) $rt['id']), 'method') === ['aprovacao', 'certificado_a3', 'aprovacao'] && str_contains($hs->html, 'falta assinar como o cliente'), 'testemunhas (aprovação e token) e advogado (aprovação) assinaram; vez do cliente');
ok((int) scalar('SELECT COUNT(*) FROM events WHERE case_id = ?', [$id]) === $eventosAntes && (int) scalar('SELECT COUNT(*) FROM chat_messages WHERE case_id = ?', [$id]) === $chatAntes && count($mails()) === $mailsAntes, 'nada foi para o atendimento: sem eventos, conversa ou e-mail ao cliente');
$hs->get('/painel/assinaturas/teste');
ok(str_contains($hs->html, 'Assinar como o cliente') && str_contains($hs->html, 'Abrir o PDF atual'), 'teste mostra o andamento e o botão para assinar como o cliente');
$hs->get('/painel/assinaturas/teste/' . $rt['id'] . '/cliente');
ok(str_contains($hs->html, 'você assina como o cliente (simulado)') && str_contains($hs->html, 'data-base="' . url('painel/assinaturas/teste/' . $rt['id'] . '/cliente') . '"') && !str_contains($hs->html, 'data-abrir="retificar"'), 'tela do cliente simulado: a mesma da área do cliente, com o código no e-mail de quem testa');
preg_match('#id="assin-dados">(.*?)</script>#s', $hs->html, $cdt);
$cdt = json_decode($cdt[1] ?? '{}', true);
$j = $hs->json('/painel/assinaturas/teste/' . $rt['id'] . '/cliente/dados', ['nome' => 'Bruno Teste Souza', 'cpf' => '529.982.247-25']);
ok($j['ok'] ?? false, 'nome e CPF do cliente conferidos');
$j = $hs->json('/painel/assinaturas/teste/' . $rt['id'] . '/cliente/codigo', []);
$toSid = array_values(array_filter($mails(), fn($m) => str_starts_with($m, 'To: sidney@teste.local') && str_contains($m, 'Código para assinar')));
ok(($j['ok'] ?? false) && count($toSid) === 1 && count(array_filter($mails(), fn($m) => str_starts_with($m, 'To: bruno@exemplo.com'))) === $mailsBruno, 'código vai para o e-mail de quem testa, não para o cliente');
preg_match_all('/é: (\d{6})/u', $toSid[0] ?? '', $codes);
$j = $hs->json('/painel/assinaturas/teste/' . $rt['id'] . '/cliente/assinar', ['nome' => 'Bruno Teste Souza', 'cpf' => '529.982.247-25', 'aceite' => '1', 'codigo' => end($codes[1]), 'tracos' => $tr, 'selo' => json_encode($cdt['selo'])]);
$st = sig_for_version((int) $vt['id']);
ok(($j['ok'] ?? false) && $st && $st['code'] === $rt['code'] && one('SELECT status FROM sig_requests WHERE id = ?', [$rt['id']])['status'] === 'concluido', 'cliente simulado assinou: teste concluído');
$fin = $hs->get('/painel/assinaturas/teste/' . $rt['id'] . '/pdf');
$fsig = pdfx_signatures($fin);
ok(str_starts_with($fin, '%PDF') && count($fsig) === 1 && $fsig[0]['integra'] && hash('sha256', $fin) === $st['pdf_sha256'], 'PDF final do teste: a assinatura do token continua íntegra; mesmo hash do registro');
file_put_contents($tmp . '/teste-final.pdf', $fin);
$txt = preg_replace('/\s+/', ' ', (string) shell_exec('pdftotext ' . escapeshellarg($tmp . '/teste-final.pdf') . ' - 2>/dev/null'));
if (trim($txt) !== '') {
    ok(str_contains($txt, 'DOCUMENTO DE TESTE') && str_contains($txt, 'versão teste 1') && str_contains($txt, 'Ana Paula Ribeiro') && str_contains($txt, 'Carlos Eduardo Lima') && str_contains($txt, 'Bruno Teste Souza'), 'PDF marcado como teste em todas as páginas, com todos na faixa');
}
ok(kit_latest($id, 'honorarios')['id'] === $kitAntes && find_case($id)['contract_state'] === $estadoAntes && (int) scalar('SELECT COUNT(*) FROM events WHERE case_id = ?', [$id]) === $eventosAntes
    && !one("SELECT 1 FROM files WHERE case_id = ? AND kind = 'assinado' AND original_name LIKE '%9001%'", [$id]), 'depois de tudo, o atendimento continua igual (sem arquivo, evento ou mudança de estado)');
$pub->get('/verificar/' . $rt['code']);
ok(str_contains($pub->html, 'Documento de teste, sem valor jurídico') && str_contains($pub->html, 'aprovação no painel') && str_contains($pub->html, 'Certificado digital A3'), 'verificação pública avisa que é teste e mostra o meio de cada um');

echo "\n" . ($fails ? "FALHAS: $fails" : 'TUDO OK') . " · $passes verificações aprovadas\n";
exit($fails ? 1 : 0);
