<?php
/**
 * Google Drive do escritório (simulado, sem rede): pasta do atendimento com o
 * protocolo como nome, arquivos enviados e o resumo do caso em .md.
 *
 * Uso: php tests/drive.php
 */
declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/fatuche-drive-' . getmypid();
@mkdir($tmp, 0700, true);
putenv('FATUCHE_DB=' . $tmp . '/fatuche.sqlite');
putenv('FATUCHE_STORAGE=' . $tmp);
require dirname(__DIR__) . '/app/bootstrap.php';
register_shutdown_function(fn() => shell_exec('rm -rf ' . escapeshellarg($tmp)));

$fails = 0;
$passes = 0;
function ok(bool $cond, string $msg): void
{
    global $fails, $passes;
    $cond ? $passes++ : $fails++;
    echo ($cond ? '  ✓ ' : '  ✗ ') . $msg . "\n";
}

// Drive simulado: guarda pastas e arquivos em memória.
$drive = ['folders' => [], 'files' => [], 'patch' => 0, 'n' => 0, 'deleted' => [], 'scope' => 'https://www.googleapis.com/auth/drive', 'api_off' => false, 'readonly' => []];
$GLOBALS['google_http'] = function (string $method, string $url, ?array $body) use (&$drive) {
    if (str_ends_with(parse_url($url, PHP_URL_PATH), '/token')) {
        return [200, ['access_token' => 'at', 'expires_in' => 3599, 'scope' => 'https://www.googleapis.com/auth/drive']];
    }
    $path = parse_url($url, PHP_URL_PATH);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $qs);
    if (str_ends_with($path, '/tokeninfo')) {
        return [200, ['scope' => $drive['scope']]];
    }
    if ($drive['api_off'] && str_contains($path, '/drive/v3/')) {
        return [403, ['error' => ['code' => 403, 'message' => 'Google Drive API has not been used in project 1 before or it is disabled.', 'errors' => [['reason' => 'accessNotConfigured']], 'status' => 'PERMISSION_DENIED']]];
    }
    if ($method === 'GET' && $path === '/drive/v3/about') {
        return [200, ['user' => ['emailAddress' => 'sidney@t.local'], 'storageQuota' => ['limit' => '100', 'usage' => '10']]];
    }
    if ($method === 'GET' && preg_match('#^/drive/v3/files/(.+)$#', $path, $m)) {
        $hit = array_values(array_filter($drive['folders'], fn($f) => $f['id'] === $m[1]));
        if (!$hit) {
            return [404, ['error' => ['code' => 404, 'message' => 'File not found: ' . $m[1], 'errors' => [['reason' => 'notFound']]]]];
        }
        return [200, ['id' => $m[1], 'name' => $hit[0]['name'], 'trashed' => false, 'capabilities' => ['canAddChildren' => !in_array($m[1], $drive['readonly'], true)], 'owners' => [['emailAddress' => 'outra@t.local']]]];
    }
    if ($method === 'DELETE' && preg_match('#^/drive/v3/files/(.+)$#', $path, $m)) {
        $drive['deleted'][] = $m[1];
        unset($drive['files'][$m[1]]);
        return [204, []];
    }
    if ($method === 'GET' && $path === '/drive/v3/files') {
        preg_match("/name = '(.*?)' and '(.*?)' in parents/", $qs['q'], $m);
        $hit = array_values(array_filter($drive['folders'], fn($f) => $f['name'] === stripslashes($m[1]) && $f['parent'] === $m[2]));
        return [200, ['files' => $hit ? [['id' => $hit[0]['id'], 'name' => $hit[0]['name']]] : []]];
    }
    if ($method === 'POST' && $path === '/drive/v3/files') {
        $id = 'pasta' . (++$drive['n']);
        $drive['folders'][] = ['id' => $id, 'name' => $body['name'], 'parent' => $body['parents'][0]];
        return [200, ['id' => $id]];
    }
    if ($method === 'POST' && str_contains($path, '/upload/drive/v3/files')) {
        preg_match('/\{.*?\}\r\n/s', $body['_raw'], $mm);
        $meta = json_decode(trim($mm[0]), true);
        $id = 'arq' . (++$drive['n']);
        $drive['files'][$id] = ['name' => $meta['name'], 'parent' => $meta['parents'][0], 'raw' => $body['_raw']];
        return [200, ['id' => $id]];
    }
    if ($method === 'PATCH' && str_contains($path, '/upload/drive/v3/files/')) {
        $id = basename($path);
        $drive['patch']++;
        $drive['files'][$id]['raw'] = $body['_raw'];
        return [200, ['id' => $id]];
    }
    return [404, ['error' => ['message' => 'não simulado']]];
};

$uid = insert('users', ['name' => 'Sidney', 'email' => 's@t.local', 'roles' => 'supervisor', 'pass_hash' => 'x', 'active' => 1, 'created_at' => now_str()]);
set_setting('operator_user_id', (string) $uid);
seed_templates();
$a = create_initial_case(['nome' => 'Thais Ribeiro Lima', 'telefone' => '(41) 91111-1111', 'email' => 'thais@t.local', 'adaptacao' => ''], 'site');
$id = (int) $a['id'];
agent_ensure_docs($id);
foreach (['banco' => 'Creditas', 'veiculo_marca' => 'Chevrolet', 'veiculo_modelo' => 'Onix', 'veiculo_placa' => 'BRA2E19', 'parcela' => '1890,50', 'objetivo_texto' => 'Preciso adequar a parcela para manter o carro.'] as $k => $v) {
    set_field($id, $k, $v, 'informado', 'cliente');
}
chat_post($id, 'client', 'Preciso adequar a parcela, uso o carro para trabalhar.');
$doc = one("SELECT * FROM documents WHERE case_id = ? AND kind = 'contrato'", [$id]);
@mkdir($tmp . '/uploads/' . $id, 0700, true);
file_put_contents($tmp . '/uploads/' . $id . '/c1.pdf', '%PDF-1.4 contrato');
insert('files', ['case_id' => $id, 'document_id' => $doc['id'], 'original_name' => 'contrato.pdf', 'stored_name' => 'c1.pdf', 'mime' => 'application/pdf', 'size' => 17, 'uploaded_by_client' => 1, 'uploaded_at' => now_str()]);
q("UPDATE documents SET status = 'recebido' WHERE id = ?", [$doc['id']]);
set_case_data($id, 'doc_notas', ['contrato' => ['label' => $doc['label'], 'at' => now_str(), 'obs' => 'Contrato legível, taxa de 2,1% a.m.', 'lidos' => ['banco'], 'divergencias' => ['Banco: ficha “Creditas”, documento “Creditas SCD”']]]);

echo "R1. Arquivo .md do caso\n";
$md = case_markdown($id);
$prot = find_case($id)['protocol'];
ok(str_starts_with($md, '# Atendimento ' . $prot . ' — Thais Ribeiro Lima'), 'título com protocolo e nome');
ok(str_contains($md, '## Contexto do caso') && str_contains($md, 'Thais, entendemos que você tem um financiamento do Chevrolet Onix'), 'contexto do caso (sem IA: montado pela ficha)');
ok(str_contains($md, '## Dados conhecidos') && str_contains($md, '| Banco ou financeira | Creditas | cliente |') && str_contains($md, '| Valor da parcela | R$ 1.890,50 | cliente |'), 'todas as variáveis conhecidas, com a fonte');
ok(str_contains($md, '## Pontos relevantes para o processo') && str_contains($md, 'Creditas SCD'), 'divergência da leitura do documento entra como ponto relevante');
ok(str_contains($md, '**Contrato completo, anexos e renegociações:** recebido (contrato.pdf) — leitura: Contrato legível'), 'documentos com arquivos e o que a leitura encontrou');
ok(str_contains($md, '## Conversa') && str_contains($md, 'uso o carro para trabalhar'), 'conversa completa no final');
set_case_data($id, 'contexto_ia', ['contexto' => 'Thais quer reduzir a parcela do Onix para manter o carro, que usa para trabalhar.', 'necessidades' => ['Reduzir a parcela'], 'relevante' => ['Contrato recente'], 'hash' => 'x']);
$md = case_markdown($id);
ok(str_contains($md, 'Thais quer reduzir a parcela') && str_contains($md, "## Principais necessidades\n\n- Reduzir a parcela") && str_contains($md, '- Contrato recente'), 'com IA: contexto, principais necessidades e pontos relevantes');

echo "\nR2. Google Drive\n";
ok(!drive_enabled() && drive_sync_case($id)[0] === 0, 'sem Google conectado, nada é enviado');
set_setting('google_client_id', '1-a.apps.googleusercontent.com');
set_setting('google_client_secret', 'GOCSPX-x');
set_setting('google_refresh_token', 'rt');
set_setting('google_scopes', 'https://www.googleapis.com/auth/calendar.events');
ok(!drive_enabled(), 'conectado só com a agenda: Drive pede nova conexão');
set_setting('google_scopes', 'openid https://www.googleapis.com/auth/drive');
ok(drive_enabled() && drive_path() === '000 - Sites/Atendimentos Site', 'Drive liberado; pasta padrão “000 - Sites/Atendimentos Site”');
[$n, $err] = drive_sync_case($id);
$f = $drive['folders'];
ok($err === null && $n === 1, 'um arquivo enviado, sem erro');
ok(count($f) === 3 && $f[0]['name'] === '000 - Sites' && $f[0]['parent'] === 'root' && $f[1]['name'] === 'Atendimentos Site' && $f[1]['parent'] === $f[0]['id'] && $f[2]['name'] === $prot && $f[2]['parent'] === $f[1]['id'], 'pastas criadas em Meu Drive: 000 - Sites › Atendimentos Site › ' . $prot);
$names = array_column($drive['files'], 'name');
ok(in_array('Contrato completo, anexos e renegociações - contrato.pdf', $names, true) && in_array($prot . '.md', $names, true), 'arquivo do contrato e ' . $prot . '.md na pasta do atendimento');
ok(count(array_filter($drive['files'], fn($x) => $x['parent'] === $f[2]['id'])) === 2 && str_contains($drive['files'][case_data(find_case($id), 'drive_md')]['raw'], '## Dados conhecidos'), 'o .md vai com o conteúdo completo');
ok((bool) scalar('SELECT drive_id FROM files WHERE case_id = ?', [$id]), 'arquivo marcado como enviado');
chat_post($id, 'client', 'Lembrei: tenho duas parcelas pagas.');
[$n2] = drive_sync_case($id);
ok($n2 === 0 && $drive['patch'] === 1 && count($drive['files']) === 2 && str_contains($drive['files'][case_data(find_case($id), 'drive_md')]['raw'], 'duas parcelas pagas'), 'nova gravação: não repete arquivos e atualiza o mesmo .md');
$b = create_initial_case(['nome' => 'Bruno Teste', 'telefone' => '(41) 92222-2222', 'email' => 'b@t.local', 'adaptacao' => ''], 'site');
drive_sync_case((int) $b['id']);
ok(count($drive['folders']) === 4 && end($drive['folders'])['parent'] === $f[1]['id'], 'outro atendimento: só cria a pasta dele, reaproveitando “Atendimentos Site”');

echo "\nR3. Painel do caso: variáveis críticas e pergunta ao cliente\n";
$miss = critical_missing($id);
ok(in_array('cpf', $miss, true) && in_array('cep', $miss, true) && !in_array('banco', $miss, true) && !in_array('veiculo_placa', $miss, true), 'pendentes: CPF e endereço; banco e placa já informados');
$st = critical_status($id);
ok(isset($st['Qualificação'], $st['Endereço'], $st['Financiamento e veículo'], $st['Para a análise']), 'grupos: qualificação, endereço, financiamento e análise');
$q = critical_question(find_case($id), ['cpf', 'estado_civil', 'cep']);
ok(str_starts_with($q, 'Olá, Thais! Aqui é Sidney Fatuche.') && str_contains($q, "• CPF\n• estado civil\n• CEP") && str_contains($q, find_case($id)['protocol']), 'uma única mensagem com tudo o que falta');
ok(str_contains(whatsapp_link($q, '41911111111'), 'https://wa.me/5541911111111?text='), 'link do WhatsApp com a pergunta pronta');
set_field($id, 'cpf', '52998224725', 'informado', 'cliente');
ok(!in_array('cpf', critical_missing($id), true), 'informado o CPF, sai da lista');
ok(analysis_ai_suggest($id)[1] !== null, 'sem IA ligada, a sugestão da análise avisa e não preenche nada');

echo "\nR4. Versão final do documento no Drive (.docx)\n";
$v = ['id' => 77, 'kind' => 'procuracao', 'version' => 3, 'content' => "# PROCURAÇÃO\n\nOutorgante: Thais Ribeiro Lima."];
[$did, $err] = drive_save_kit(find_case($id), $v);
$arq = $drive['files'][$did] ?? null;
ok($err === null && $arq && $arq['name'] === 'Procuração - ' . $prot . ' - v3.docx' && $arq['parent'] === $f[2]['id'], 'Procuração v3 gravada como .docx na pasta do atendimento');
ok($arq && str_contains($arq['raw'], 'wordprocessingml') && str_contains($arq['raw'], 'PK'), 'arquivo é um .docx de verdade');
$antes = count($drive['files']);
drive_save_kit(find_case($id), $v);
ok(count($drive['files']) === $antes, 'a mesma versão não é gravada duas vezes');

echo "\nR5. Erro 403: diagnóstico e recuperação\n";
$drive['scope'] = 'openid https://www.googleapis.com/auth/calendar.events';
$steps = drive_diagnose();
$last = end($steps);
ok($last[0] === 'Permissão do Google Drive' && !$last[1] && str_contains($last[2], 'Conectar de novo') && setting('google_scopes') === $drive['scope'], 'Drive desmarcado no consentimento: o teste aponta “Conectar de novo” e atualiza as permissões');
$drive['scope'] = 'openid https://www.googleapis.com/auth/drive';
$drive['api_off'] = true;
$steps = drive_diagnose();
$last = end($steps);
ok($last[0] === 'Google Drive API ativa' && !$last[1] && str_contains($last[2], 'Google respondeu 403') && str_contains($last[2], 'ative a API indicada'), 'API do Drive desligada no projeto: 403 com a orientação de ativar');
ok(str_contains((string) setting('drive_diag'), 'Google Drive API ativa'), 'o resultado fica guardado para a tela de Configurações');
$drive['api_off'] = false;
$antes = count($drive['files']);
$steps = drive_diagnose();
ok(count(array_filter($steps, fn($x) => !$x[1])) === 0 && count($steps) === 7 && end($steps)[0] === 'Gravar um arquivo de teste', 'tudo certo: conta, permissão, API, pasta e gravação de teste');
ok(count($drive['files']) === $antes && count($drive['deleted']) === 1, 'o arquivo de teste é apagado em seguida');
$drive['readonly'][] = $f[1]['id'];
$steps = drive_diagnose();
$last = end($steps);
ok($last[0] === 'Permissão de gravar na pasta' && !$last[1] && str_contains($last[2], 'outra@t.local'), 'pasta só de leitura: aponta a conta dona');
$drive['readonly'] = [];
// Pasta guardada que sumiu (ou é de outra conta): procura de novo, sem erro.
set_case_data($id, 'drive_pasta', 'pasta-de-outra-conta');
chat_post($id, 'client', 'Mais uma mensagem.');
[$n3, $err3] = drive_sync_case($id);
ok($err3 === null && case_data(find_case($id), 'drive_pasta') === $f[2]['id'], 'pasta guardada inválida (apagada ou de outra conta): volta a usar a pasta certa');
set_case_data((int) $b['id'], 'drive_pasta', 'pasta-velha');
set_setting('drive_root_id', 'x|velha');
set_setting('google_account_email', 'antiga@t.local');
drive_forget_folders();
ok(setting('drive_root_id') === null && case_data(find_case((int) $b['id']), 'drive_pasta') === null, 'trocar a conta do Google esquece as pastas guardadas');
ok(str_contains(google_error(403, ['error' => ['message' => 'Insufficient Permission', 'errors' => [['reason' => 'insufficientPermissions']]]]), 'Conectar de novo'), 'erro 403 de permissão explica o que fazer');

set_setting('drive_off', '1');
ok(!drive_enabled(), 'gravação no Drive pode ser desligada');

echo "\n" . ($fails ? "FALHAS: $fails" : 'TUDO OK') . " · $passes verificações aprovadas\n";
exit($fails ? 1 : 0);
