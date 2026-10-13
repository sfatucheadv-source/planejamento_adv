<?php
/**
 * O que veio nos documentos não é perguntado de novo: a titularidade sai do
 * contrato lido, e a IA recebe esses dados como informação já recebida.
 *
 * Uso: php tests/deducao.php
 */
declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/fatuche-deducao-' . getmypid();
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

$uid = insert('users', ['name' => 'Sidney', 'email' => 's@t.local', 'roles' => 'supervisor', 'pass_hash' => 'x', 'active' => 1, 'created_at' => now_str()]);
set_setting('operator_user_id', (string) $uid);
seed_templates();
$novo = function (string $nome) {
    $c = create_initial_case(['nome' => '', 'telefone' => '', 'email' => '', 'adaptacao' => ''], 'site');
    $id = (int) $c['id'];
    agent_ensure_docs($id);
    $doc = (int) scalar("SELECT id FROM documents WHERE case_id = ? AND kind = 'contrato'", [$id]);
    if ($nome !== '') {
        set_field($id, 'nome_completo', $nome, 'informado', 'cliente');
    }
    return [$id, $doc];
};

echo "D1. Contrato no nome de quem conversa (sem nome informado antes)\n";
[$id, $doc] = $novo('');
ok(in_array('relacao_contrato', agent_missing_fields($id), true), 'antes do contrato, a relação com o contrato falta');
set_field($id, 'nome_completo', 'Thais Ribeiro Lima', 'informado', 'ia', $doc);
set_field($id, 'titular_nome', 'Thais Ribeiro Lima', 'informado', 'ia', $doc);
set_field($id, 'banco', 'Creditas', 'informado', 'ia', $doc);
agent_infer_fields($id);
ok(fv($id, 'relacao_contrato') === 'titular', 'contrato lido: ela é a titular, sem perguntar');
ok(!in_array('relacao_contrato', agent_missing_fields($id), true), 'a titularidade sai da lista do que falta');
$ctx = agent_context($id, 'Mensagem nova do cliente: preciso adequar a parcela');
$falta = substr($ctx, strpos($ctx, 'DADOS MÍNIMOS QUE FALTAM'), strpos($ctx, 'OUTROS DADOS') - strpos($ctx, 'DADOS MÍNIMOS QUE FALTAM'));
ok(str_contains($ctx, 'JÁ SABEMOS (informação recebida: não pergunte nem confirme)') && str_contains($ctx, 'relacao_contrato (Relação com o contrato): Sou o titular [lido de documento]'), 'a IA recebe a titularidade como informação já recebida');
ok(!str_contains($falta, 'relacao_contrato') && !str_contains($falta, '- banco') && !str_contains($falta, 'nome_completo'), 'nada do que veio do contrato aparece como pergunta');
ok((bool) scalar("SELECT 1 FROM events WHERE case_id = ? AND title = 'Titularidade deduzida do contrato'", [$id]), 'Sidney vê na ficha que a titularidade veio do contrato');

echo "\nD2. Mesma pessoa pelo primeiro nome\n";
[$id2, $doc2] = $novo('Thais');
set_field($id2, 'titular_nome', 'THAÍS RIBEIRO LIMA', 'informado', 'ia', $doc2);
agent_infer_fields($id2);
ok(fv($id2, 'relacao_contrato') === 'titular', '“Thais” na conversa e “THAÍS RIBEIRO LIMA” no contrato: titular');

echo "\nD3. Outro nome: pode ser representante, então pergunta\n";
[$id3, $doc3] = $novo('Marcos Lima');
set_field($id3, 'titular_nome', 'Thais Ribeiro Lima', 'informado', 'ia', $doc3);
agent_infer_fields($id3);
ok(!field_answered($id3, 'relacao_contrato'), 'nome diferente do titular: a relação continua a perguntar');

echo "\nD4. Regras fixas da IA\n";
$sys = agent_system_prompt();
ok(str_contains($sys, 'nunca pergunte de novo nem peça confirmação'), 'não pergunta nem confirma o que já se sabe');
ok(str_contains($sys, 'Nunca comece a resposta com "registrei"'), 'não recapitula o que a pessoa disse');
ok(str_contains($sys, 'Pergunte só o que está em "DADOS MÍNIMOS QUE FALTAM"'), 'só pergunta o essencial que falta');
ok(str_contains($sys, 'ela é a titular'), 'deduz o óbvio dos documentos');

echo "\n" . ($fails ? "FALHAS: $fails" : 'TUDO OK') . " · $passes verificações aprovadas\n";
exit($fails ? 1 : 0);
