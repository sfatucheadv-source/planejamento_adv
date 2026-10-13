<?php
/**
 * Assinatura eletrônica pelo cliente (código por e-mail, nome, CPF, rubrica,
 * IP e localização), via assinada em PDF com o selo onde o cliente o pôs e a
 * faixa de verificação em todas as páginas (sem folha a mais), guardada byte a
 * byte, conferência pública com comparação de hash, histórico protegido,
 * bloqueio de impressão antes da assinatura e retificação com a IA no Kit.
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
ok(!str_contains($page, 'Baixar .docx') && !str_contains($page, 'data-imprimir') && str_contains($page, '-sem-impressao') && str_contains($page, 'folhas.css'), 'sem .docx nem imprimir; impressão bloqueada até assinar');
$layout = sig_doc_layout(kit_latest($id, 'honorarios'));
$nPages = count($layout['pages']);
ok(substr_count($page, 'class="folha"') === $nPages && str_contains($page, '<svg viewBox="0 0 210 297"') && str_contains($page, '>Prévia</text>'), 'folhas como saem no PDF (' . $nPages . '), com a prévia da faixa lateral');
preg_match('#<script type="application/json" id="assin-dados">(.*?)</script>#s', $page, $jd);
$dados = json_decode($jd[1] ?? '{}', true);
$def = $layout['sigs'][array_key_last(array_filter($layout['sigs'], fn($x) => str_contains(sig_norm_name($x[4]), 'bruno teste souza')))];
ok(($dados['selo']['pagina'] ?? 0) === $def[0] && $dados['selo']['y'] >= $def[3] - SEAL_H - 1.01 && $dados['selo']['y'] < $def[3] && str_contains($page, 'id="selo-modelo"'), 'selo sugerido sobre a linha de assinatura do cliente (página ' . $def[0] . ')');
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
$tracos = json_encode(['w' => 516, 'h' => 150, 's' => [[[20, 110], [60, 40], [90, 100], [130, 30], [170, 90]], [[200, 60], [260, 70], [330, 50], [400, 80]]]]);
$j = $cli->json('/cliente/documento-kit/' . $vh['id'] . '/dados', ['nome' => 'Outra Pessoa', 'cpf' => '529.982.247-25']);
ok(!$j['ok'] && str_contains((string) $j['erro'], 'nome completo'), 'passo 1: nome diferente do documento é recusado antes do selo');
$j = $cli->json('/cliente/documento-kit/' . $vh['id'] . '/dados', ['nome' => 'bruno teste souza', 'cpf' => '529.982.247-25']);
ok($j['ok'] === true, 'passo 1: nome e CPF conferidos');
$seal = ['pagina' => $nPages - 1, 'x' => 120.5, 'y' => 180.25, 'w' => 64, 'h' => 24, 'largura_folha_px' => 374, 'zoom' => 1, 'dpr' => 3, 'orientacao' => 0, 'tela' => '390x844'];
$sign = ['nome' => 'bruno teste souza', 'cpf' => '529.982.247-25', 'aceite' => '1', 'rubrica' => $png, 'tracos' => $tracos, 'selo' => json_encode($seal),
    'geo_lat' => '-25.4284', 'geo_lng' => '-49.2733', 'geo_acc' => '18', 'fuso' => 'America/Sao_Paulo', 'tela' => '390x844 @3'];
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
$j = $cli->json('/cliente/documento-kit/' . $vh['id'] . '/assinar', array_merge($sign, ['tracos' => json_encode(['w' => 500, 'h' => 150, 's' => [[[10, 10], [12, 11]]]]), 'codigo' => $cd[1]]));
ok(!$j['ok'] && str_contains((string) $j['erro'], 'rubrica'), 'sem rubrica (ou só um ponto): recusado');
$j = $cli->json('/cliente/documento-kit/' . $vh['id'] . '/assinar', array_merge($sign, ['codigo' => '000000']));
ok(!$j['ok'] && str_contains((string) $j['erro'], 'Código incorreto'), 'código errado: recusado');
$antes = time();
$j = $cli->json('/cliente/documento-kit/' . $vh['id'] . '/assinar', array_merge($sign, ['codigo' => $cd[1]]));
ok($j['ok'] && str_contains((string) $j['ir'], 'assinado=1'), 'assinado com nome, CPF, rubrica, selo posicionado e código');
$sig = sig_for_version((int) $vh['id']);
$ev = json_decode((string) ($sig['evidence'] ?? '{}'), true);
ok($sig && $sig['ip'] === '127.0.0.1' && str_contains((string) $sig['user_agent'], 'Navegador/1.0') && $sig['doc_hash'] === hash('sha256', (string) $vh['content']), 'evidências: IP, navegador e hash SHA-256 do texto aceito');
ok(($ev['localizacao']['status'] ?? '') === 'ok' && $ev['localizacao']['lat'] === -25.4284 && $ev['localizacao']['precisao_m'] === 18 && $ev['aparelho']['fuso'] === 'America/Sao_Paulo' && $ev['email_confirmado']['para'] === 'bruno@exemplo.com', 'localização, fuso e e-mail confirmado registrados');
ok(str_contains((string) $ev['aceite'], 'MP 2.200-2/2001') && $ev['momento']['utc'] !== '' && $ev['momento']['fuso'] === '-03:00' && str_contains((string) $ev['autenticacao']['meio'], 'área do cliente'), 'aceite com a base legal, data e hora com o fuso e a autenticação');
ok(($ev['selo']['pagina'] ?? 0) === $nPages - 1 && $ev['selo']['x'] === 120.5 && $ev['selo']['y'] === 180.25 && $ev['selo']['visualizacao']['largura_folha_px'] === 374 && $ev['selo']['largura_mm'] == SEAL_W, 'posição do selo (página, x, y em mm), tamanho e dados da tela guardados');
$path = $tmp . '/assinados/' . $sig['pdf_path'];
$pdf = is_file($path) ? (string) file_get_contents($path) : '';
ok(str_starts_with($pdf, '%PDF-') && hash('sha256', $pdf) === $sig['pdf_sha256'] && strlen($pdf) === (int) $sig['pdf_size'], 'via em PDF guardada, com o hash completo registrado');
preg_match_all('#/Type /Page\b(?!s)#', $pdf, $pp);
ok(count($pp[0]) === $nPages && (int) $sig['pages'] === $nPages, 'mesmo número de páginas do documento: sem folha de registro a mais');
ok(substr_count($pdf, '/URI (' . $sig['verify_url'] . ')') === $nPages + 1 && str_contains($sig['verify_url'], '/verificar/' . $sig['code']), 'faixa clicável em todas as páginas e o selo clicável, levando ao registro');
$txt = trim((string) shell_exec('pdftotext ' . escapeshellarg($path) . ' - 2>/dev/null'));
if ($txt !== '') {
    $txt = preg_replace('/\s+/', ' ', $txt);
    ok(substr_count(preg_replace('/\s+/', ' ', $txt), $sig['code']) >= 2 * $nPages + 1 && str_contains($txt, 'Página 1 de ' . $nPages) && str_contains($txt, 'Página ' . $nPages . ' de ' . $nPages), 'faixa com código, endereço e página em todas as folhas');
    ok(str_contains($txt, 'ASSINADO ELETRONICAMENTE') && str_contains($txt, 'Código ' . $sig['code']) && str_contains($txt, 'UTC-03:00'), 'selo com “Assinado eletronicamente”, data e hora com o fuso e o código');
}
$f = one('SELECT * FROM files WHERE id = ?', [$sig['file_id']]);
$copy = (string) file_get_contents($tmp . '/uploads/' . $id . '/' . $f['stored_name']);
ok($f['kind'] === 'assinado' && $f['mime'] === 'application/pdf' && (int) kit_latest($id, 'honorarios')['signed_file_id'] === (int) $f['id'] && $copy === $pdf, 'cópia idêntica nos arquivos do atendimento (vai para o Drive), ligada à versão');
$last = implode('', array_filter($mails(), fn($x) => str_contains($x, 'Documento assinado')));
preg_match('/filename="?[^"\r\n]*assinado\.pdf"?\r?\n(?:[^\r\n]+\r?\n)*\r?\n([A-Za-z0-9+\/=\r\n]+)/', implode('', array_map(fn($f2) => (string) file_get_contents($f2), glob($tmp . '/mail/*.eml'))), $att);
ok(str_contains($last, 'Content-Disposition: attachment') && str_contains($last, 'assinado.pdf') && isset($att[1]) && base64_decode(preg_replace('/\s+/', '', $att[1])) === $pdf, 'cópia enviada por e-mail: o mesmo PDF, byte a byte');
$cli->get('/cliente/documento-kit/' . $vh['id'] . '?assinado=1');
ok(str_contains($cli->html, 'Documento assinado.') && str_contains($cli->html, 'Baixar o PDF') && !str_contains($cli->html, 'data-assin') && !str_contains($cli->html, '-sem-impressao') && str_contains($cli->html, 'ASSINADO ELETRONICAMENTE'), 'depois de assinar: as folhas com o selo e o PDF para baixar');
$dl = $cli->get('/cliente/documento-kit/' . $vh['id'] . '/pdf?baixar=1');
ok($dl === $pdf && str_contains($cli->type, 'application/pdf'), 'download na área do cliente: os mesmos bytes, sem gerar de novo');
$dl2 = $cli->get('/cliente/documento-kit/' . $vh['id'] . '/pdf');
ok($dl2 === $pdf, 'segundo download: idêntico');
$cli->get('/cliente?area=reuniao');
$j = $cli->json('/cliente/documento-kit/' . $vh['id'] . '/codigo', []);
ok(!$j['ok'] && str_contains((string) $j['erro'], 'já foi assinado'), 'não assina duas vezes');

echo "\nS3b. Conferência pública e proteção do registro\n";
$pub = new Http($base);
$pub->get('/verificar/' . $sig['code']);
ok(str_contains($pub->html, 'Registro de assinatura encontrado e preservado') && str_contains($pub->html, $sig['pdf_sha256']) && str_contains($pub->html, 'Bruno T. Souza') && !str_contains($pub->html, '529.982') && str_contains($pub->html, 'UTC-03:00'), 'o link do selo abre o registro direto: estado, signatário, data e hora com fuso e o hash do PDF');
ok(str_contains($pub->html, 'Conferir minha cópia') && str_contains($pub->html, 'verificar.js') && str_contains($pub->html, 'não é enviado nem guardado') && str_contains($pub->html, 'não indica fraude'), 'conferência da cópia no navegador, com a explicação sobre reimpressões');
ok($pub->get('/verificar/' . $sig['code'] . '/original') === $pdf, 'via original para ver e baixar: os mesmos bytes');
$storeBefore = shell_exec('find ' . escapeshellarg($tmp) . ' -type f -not -path "*/sessions/*" -not -name "*.sqlite*" | sort | xargs md5sum');
$pub->get('/verificar/' . $sig['code']);
$j = $pub->json('/verificar/' . $sig['code'] . '/conferir', ['hash' => hash('sha256', $pdf)]);
ok(($j['resultado'] ?? '') === 'identica', '“Cópia idêntica à versão assinada registrada.”');
$fake = hash('sha256', $pdf . ' ');
$j = $pub->json('/verificar/' . $sig['code'] . '/conferir', ['hash' => $fake]);
ok(($j['resultado'] ?? '') === 'diferente', '“Cópia diferente da versão assinada registrada.”');
$j = $pub->json('/verificar/' . $sig['code'] . '/conferir', ['hash' => 'nao-e-hash']);
ok(($j['resultado'] ?? '') === 'erro', '“Não foi possível realizar a conferência.”');
$storeAfter = shell_exec('find ' . escapeshellarg($tmp) . ' -type f -not -path "*/sessions/*" -not -name "*.sqlite*" | sort | xargs md5sum');
$found = false;
foreach (array_column(all("SELECT name FROM sqlite_master WHERE type = 'table'"), 'name') as $t) {
    foreach (all('SELECT * FROM "' . $t . '"') as $row) {
        $found = $found || str_contains(implode('|', array_map('strval', $row)), $fake);
    }
}
ok($storeBefore !== null && $storeBefore === $storeAfter && !$found, 'nada da cópia conferida é guardado (nem arquivo, nem hash em nenhuma tabela)');
$pub->get('/verificar/XXXX-0000-1111');
ok(str_contains($pub->html, 'Código não encontrado'), 'código inexistente');
$blocked = function (string $sql): bool {
    try { q($sql); return false; } catch (Throwable $e) { return true; }
};
ok($blocked("UPDATE doc_signatures SET pdf_sha256 = 'x' WHERE id = " . (int) $sig['id']) && $blocked("UPDATE doc_signatures SET evidence = '{}' WHERE id = " . (int) $sig['id']), 'hash e evidências não podem ser trocados no banco');
ok($blocked('DELETE FROM sig_log') && $blocked("UPDATE sig_log SET detail = 'x'"), 'histórico encadeado não aceita alteração nem exclusão');
[$chain, $log] = sig_log_check($sig['code']);
ok($chain && in_array('assinatura_registrada', array_column($log, 'event'), true) && in_array('via_enviada_email', array_column($log, 'event'), true), 'histórico: assinatura registrada e via enviada, corrente íntegra');
@chmod($path, 0600);
file_put_contents($path, $pdf . '%alterado');
$pub->get('/verificar/' . $sig['code'] . '/original');
ok(str_contains($pub->html, 'não confere com o registro') && (bool) one("SELECT 1 FROM sig_log WHERE code = ? AND event = 'divergencia_arquivo'", [$sig['code']]), 'arquivo trocado em silêncio: não é entregue e fica no histórico');
$pub->get('/verificar/' . $sig['code']);
ok(str_contains($pub->html, 'com alerta'), 'a conferência mostra o alerta');
file_put_contents($path, $pdf);

echo "\nS3c. Registro anterior ao PDF (via .html)\n";
$legacyHtml = '<html><body>via antiga</body></html>';
@mkdir($tmp . '/uploads/' . $id, 0700, true);
file_put_contents($tmp . '/uploads/' . $id . '/legado.html', $legacyHtml);
$lf = insert('files', ['case_id' => $id, 'original_name' => 'legado.html', 'stored_name' => 'legado.html', 'mime' => 'text/html', 'size' => strlen($legacyHtml), 'uploaded_by_client' => 1, 'uploaded_at' => now_str(), 'kind' => 'assinado', 'internal' => 0]);
insert('doc_signatures', ['case_id' => $id, 'version_id' => $vp['id'], 'code' => 'AAAA-BBBB-CCCC', 'signer_name' => 'Bruno Teste Souza', 'doc_hash' => hash('sha256', (string) $vp['content']),
    'file_id' => $lf, 'file_hash' => hash('sha256', $legacyHtml), 'evidence' => '{}', 'signed_at' => now_str()]);
$pub->get('/verificar/AAAA-BBBB-CCCC');
ok(str_contains($pub->html, 'SHA-256 da via entregue (arquivo .html)') && str_contains($pub->html, hash('sha256', $legacyHtml)) && str_contains($pub->html, 'SHA-256 do texto aceito') && str_contains($pub->html, 'anterior à via em PDF'), 'registro antigo: hash do arquivo entregue e hash do texto, cada um com o nome certo');
$j = $pub->json('/verificar/AAAA-BBBB-CCCC/conferir', ['hash' => hash('sha256', $legacyHtml)]);
ok(($j['resultado'] ?? '') === 'identica', 'a cópia .html entregue na época confere com o registro');
q("DELETE FROM doc_signatures WHERE code = 'AAAA-BBBB-CCCC'");

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
ok(str_contains($adm->html, 'assinatura eletrônica · ') && str_contains($adm->html, 'código ' . $sig['code']) && str_contains($adm->html, 'selo na página ' . ($nPages - 1)) && str_contains($adm->html, $sig['pdf_sha256']), 'Kit mostra a assinatura eletrônica com o código, a página do selo e o hash do PDF');
$rid = (int) scalar('SELECT id FROM doc_rectifications WHERE version_id = ?', [$vp['id']]);
$adm->post('/painel/atendimento/' . $id, ['op' => 'kit_retificacao', 'rid' => $rid, 'acao' => 'resolver', 'resolucao' => 'Estado civil corrigido']);
ok(scalar('SELECT status FROM doc_rectifications WHERE id = ?', [$rid]) === 'resolvida', 'Sidney marca a retificação como resolvida');
$adm->get('/painel/atendimento/' . $id . '/kit/' . $vh['id'] . '?baixar=docx');
ok(str_contains($adm->type, 'wordprocessingml'), 'o .docx continua disponível no painel do escritório');

echo "\n" . ($fails ? "FALHAS: $fails" : 'TUDO OK') . " · $passes verificações aprovadas\n";
exit($fails ? 1 : 0);
