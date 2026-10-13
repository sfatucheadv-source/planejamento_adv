<?php
/**
 * Migração 11: zera os atendimentos sem prejudicar o sistema. Banco e pasta
 * de dados temporários; cópia de segurança conferida.
 *
 * Uso: php tests/limpeza.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/fatuche-limpeza-' . getmypid();
@mkdir($tmp . '/uploads/1', 0700, true);
file_put_contents($tmp . '/uploads/1/doc.pdf', '%PDF-teste');
$db = $tmp . '/fatuche.sqlite';
$env = 'FATUCHE_DB=' . escapeshellarg($db) . ' FATUCHE_STORAGE=' . escapeshellarg($tmp);
$fails = 0;
$passes = 0;
function ok(bool $cond, string $msg): void
{
    global $fails, $passes;
    $cond ? $passes++ : $fails++;
    echo ($cond ? '  ✓ ' : '  ✗ ') . $msg . "\n";
}
$run = function (string $code) use ($env, $root): string {
    $f = tempnam(sys_get_temp_dir(), 'lp') . '.php';
    file_put_contents($f, "<?php require '$root/app/bootstrap.php';\n" . $code);
    $out = (string) shell_exec("$env php " . escapeshellarg($f) . ' 2>&1');
    unlink($f);
    return $out;
};

echo "L1. Banco com atendimentos, conta, conversa e proposta (versão 10)\n";
echo $run(<<<'PHP'
$uid = insert('users', ['name' => 'Sidney', 'email' => 's@t.local', 'roles' => 'supervisor', 'pass_hash' => 'x', 'active' => 1, 'created_at' => now_str()]);
set_setting('operator_user_id', (string) $uid);
seed_templates();
$a = create_initial_case(['nome' => 'Ana Teste', 'telefone' => '(41) 91111-1111', 'email' => 'ana@t.local', 'adaptacao' => ''], 'site');
$b = create_initial_case(['nome' => 'Ana Teste', 'telefone' => '(41) 91111-1111', 'email' => 'ana@t.local', 'adaptacao' => '', 'origem' => 'retorno'], 'retorno', null, (int) $a['id']);
$acc = insert('client_accounts', ['name' => 'Ana', 'email' => 'ana@t.local', 'created_at' => now_str()]);
q('UPDATE cases SET account_id = ?', [$acc]);
chat_post((int) $a['id'], 'client', 'oi');
proposal_save((int) $a['id'], proposal_defaults(), $uid);
insert('files', ['case_id' => $a['id'], 'original_name' => 'doc.pdf', 'stored_name' => 'doc.pdf', 'mime' => 'application/pdf', 'size' => 10, 'uploaded_at' => now_str()]);
set_setting('agent_tone', AGENT_TONE_PREVIOUS);
set_setting('agent_greeting', AGENT_GREETINGS_PREVIOUS[1]);
set_setting('agent_name', 'Sid-LEX');
db()->exec('PRAGMA user_version = 10');
PHP);
ok(is_file($db), 'banco de teste criado');

echo "\nL2. Migração 11\n";
$res = json_decode($run(<<<'PHP'
echo json_encode([
    'v' => (int) scalar('PRAGMA user_version'),
    'cases' => (int) scalar('SELECT COUNT(*) FROM cases'),
    'acc' => (int) scalar('SELECT COUNT(*) FROM client_accounts'),
    'chat' => (int) scalar('SELECT COUNT(*) FROM chat_messages'),
    'prop' => (int) scalar('SELECT COUNT(*) FROM proposals'),
    'files' => (int) scalar('SELECT COUNT(*) FROM files'),
    'users' => (int) scalar('SELECT COUNT(*) FROM users'),
    'tpl' => (int) scalar('SELECT COUNT(*) FROM doc_templates'),
    'tone' => setting('agent_tone'),
    'greeting' => setting('agent_greeting'),
    'name' => setting('agent_name'),
    'audit' => scalar("SELECT detail FROM audit WHERE action = 'atendimentos_zerados'"),
    'novo' => (function () { $c = create_initial_case(['nome' => '', 'telefone' => '', 'email' => ''], 'site'); return [$c['client_name'], $c['protocol'] !== '']; })(),
]);
PHP), true) ?: [];
ok(($res['v'] ?? 0) === 19, 'banco atualizado (versão 19)');
ok(($res['cases'] ?? 1) === 0 && ($res['acc'] ?? 1) === 0 && ($res['chat'] ?? 1) === 0 && ($res['prop'] ?? 1) === 0 && ($res['files'] ?? 1) === 0, 'atendimentos, contas, conversas, propostas e arquivos apagados (nenhum atendimento sobra)');
ok(($res['users'] ?? 0) === 1 && ($res['tpl'] ?? 0) >= 3 && ($res['name'] ?? '') === 'Sid-LEX', 'usuários, modelos e configurações do assistente mantidos');
ok(array_key_exists('tone', $res) && $res['tone'] === null && array_key_exists('greeting', $res) && $res['greeting'] === null, 'tom e saudação antigos padrão dão lugar aos novos (Sid-LEX objetivo)');
ok(str_contains((string) ($res['audit'] ?? ''), '2 atendimento(s) apagado(s)'), 'limpeza registrada na auditoria');
ok(($res['novo'] ?? []) === ['Novo contato', true], 'o sistema segue abrindo atendimentos normalmente');
$bk = glob($tmp . '/backup-antes-de-zerar-atendimentos/fatuche-*.sqlite');
$pdo = $bk ? new PDO('sqlite:' . $bk[0]) : null;
ok($pdo && (int) $pdo->query('SELECT COUNT(*) FROM cases')->fetchColumn() === 2, 'cópia de segurança do banco com os 2 atendimentos');
ok((bool) glob($tmp . '/backup-antes-de-zerar-atendimentos/uploads-*/1/doc.pdf') && !is_dir($tmp . '/uploads/1'), 'arquivos enviados guardados na cópia, fora da pasta em uso');
$again = $run("echo (int) scalar('SELECT COUNT(*) FROM cases');");
ok(trim($again) === '1', 'a limpeza roda uma vez só');

shell_exec('rm -rf ' . escapeshellarg($tmp));
echo "\n" . ($fails ? "FALHAS: $fails" : 'TUDO OK') . " · $passes verificações aprovadas\n";
exit($fails ? 1 : 0);
