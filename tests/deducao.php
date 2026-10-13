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

echo "\nD5. Documentos: CPF ou RG bastam; profissão e situação do financiamento\n";
seed_templates();
[$id5, $doc5] = $novo('Paula Souza Lima');
foreach (['estado_civil' => 'solteiro', 'nacionalidade' => 'brasileira', 'cep' => '83005000', 'logradouro' => 'Rua A', 'numero' => '10', 'bairro' => 'Centro', 'cidade' => 'Curitiba', 'uf' => 'PR', 'telefone' => '41911112222', 'email' => 'p@t.local', 'genero' => 'feminino', 'renda_atividade' => 'Motorista de aplicativo'] as $k => $v) {
    set_field($id5, $k, $v, 'informado', 'cliente');
}
ok(fv($id5, 'profissao') === 'Motorista de aplicativo', 'atividade da justiça gratuita vira a profissão, se ela estiver vazia');
set_field($id5, 'identificacao', 'CNH 0123456789 DETRAN/PR', 'informado', 'cliente');
$vars = kit_vars($id5);
[$txt, $miss] = render_template(doc_template('procuracao')['body'], $vars);
ok(!in_array('CPF', $miss, true) && !in_array('CPF ou documento de identificação', $miss, true) && str_contains($txt, 'documento de identificação CNH 0123456789') && !str_contains($txt, 'FALTA: CPF'), 'só com a CNH: procuração sai sem pendência de CPF');
set_field($id5, 'cpf', '52998224725', 'informado', 'cliente');
q("DELETE FROM case_fields WHERE case_id = ? AND field = 'identificacao'", [$id5]);
case_fields($id5, true);
[$txt, $miss] = render_template(doc_template('honorarios')['body'], kit_vars($id5));
ok(!in_array('Documento de identificação', $miss, true) && str_contains($txt, 'inscrita no CPF sob o n.º 529.982.247-25') && !str_contains($txt, 'documento de identificação'), 'só com o CPF: contrato sem a linha do documento de identificação e sem pendência');
ok(!in_array('Prazo usual de resposta, em dias úteis', $miss, true) && !in_array('Canal e procedimento para situações urgentes', $miss, true), 'prazo de resposta e urgências não são mais pendência do contrato');
set_field($id5, 'atraso', 'nao', 'informado', 'cliente');
ok(financing_standing($id5) === 'adimplente', 'sem atraso informado: adimplente');
set_field($id5, 'atraso_qtd', '2', 'informado', 'cliente');
ok(financing_standing($id5) === 'inadimplente', 'com parcelas em atraso: inadimplente');
$st5 = critical_status($id5);
$cpfOk = array_values(array_filter($st5['Qualificação'], fn($x) => $x['key'] === 'identificacao'))[0]['ok'];
ok($cpfOk, 'no painel, com o CPF o documento de identificação não aparece como pendente');

echo "\nD6. Migração 16 nos modelos guardados\n";
$h = doc_template('honorarios');
$old = str_replace('{{cliente.documentos}}', "{{flex:inscrito/inscrita}} no CPF sob o n.º\u{00A0}**{{cliente.cpf}}**, documento de identificação **{{cliente.identificacao}}**", $h['body']);
$old = str_replace('Canal oficial do escritório: {{escritorio.telefone}} e {{escritorio.email}}', "Canal oficial do escritório: {{escritorio.telefone}} e {{escritorio.email}}\nPrazo usual de resposta: {{escritorio.prazo_resposta}} dias úteis\nProcedimento para situações urgentes: {{escritorio.orientacao_urgencias}}", $old);
q('UPDATE doc_templates SET body = ? WHERE key = ?', [$old, 'honorarios']);
migrate_templates_v16(db());
$novoBody = (string) scalar('SELECT body FROM doc_templates WHERE key = ?', ['honorarios']);
ok(str_contains($novoBody, '{{cliente.documentos}}') && !str_contains($novoBody, 'escritorio.prazo_resposta') && !str_contains($novoBody, 'orientacao_urgencias'), 'modelo guardado atualizado (qualificação e linhas fixas)');

echo "\nD7. Uma pergunta de qualificação antes do resumo\n";
[$id7] = $novo('Rita Lima');
ok(qualif_missing($id7) === ['estado_civil', 'profissao', 'nacionalidade', 'cpf'], 'faltam estado civil, profissão, nacionalidade e CPF ou RG');
$nota = agent_closing_note($id7);
ok(str_contains($nota, 'UMA única pergunta') && str_contains($nota, 'CPF ou RG') && str_contains($nota, 'não insista'), 'a IA pergunta tudo de uma vez, sem insistir');
ok(qualif_waiting(find_case($id7)) && str_contains(agent_closing_note($id7), 'não pergunte mais nada sobre qualificação'), 'pergunta feita só uma vez; o resumo espera a resposta');
chat_post($id7, 'client', 'Sou casada, professora.');
ok(!qualif_waiting(find_case($id7)), 'respondeu (mesmo em parte): o resumo libera');

echo "\nD8. Parecer: taxa média no site do Banco Central\n";
$urls = [];
$GLOBALS['bcb_http'] = function (string $url) use (&$urls) {
    $urls[] = $url;
    return str_contains($url, 'dataInicial=01/06/2026') ? [200, '[{"data":"01/06/2026","valor":"1.89"}]'] : [200, '[]'];
};
set_field($id7, 'contrato_data', '2026-06-10', 'informado', 'cliente');
[$done, $aviso] = parecer_bcb($id7);
ok(str_contains($urls[0], 'bcdata.sgs.25471') && fv($id7, 'bacen_periodo') === '06/2026' && (float) fv($id7, 'bacen_taxa') === 1.89 && str_contains((string) fv($id7, 'bacen_fonte'), 'api.bcb.gov.br'), 'série 25471 do mês da contratação, com a fonte e a data da consulta');
ok(in_array('data_base', $done, true) && fv($id7, 'data_base') === now()->format('Y-m-d'), 'data-base preenchida com a data da análise');
[$id8] = $novo('Beto Lima');
set_field($id8, 'contrato_data', '2030-01-10', 'informado', 'cliente');
$GLOBALS['bcb_http'] = fn(string $url) => str_contains($url, 'ultimos/1') ? [200, '[{"data":"01/08/2026","valor":"1,95"}]'] : [200, '[]'];
[, $aviso] = parecer_bcb($id8);
ok(fv($id8, 'bacen_periodo') === '08/2026' && str_contains((string) $aviso, 'ainda não divulgou'), 'mês ainda sem divulgação: usa a última taxa publicada e avisa');
[$d9, $f9, $e9] = parecer_fill($id8);
ok($e9 !== null && str_contains($e9, 'IA está desligada'), 'sem IA, só a taxa do BCB é consultada (e avisa)');
unset($GLOBALS['bcb_http']);

echo "\n" . ($fails ? "FALHAS: $fails" : 'TUDO OK') . " · $passes verificações aprovadas\n";
exit($fails ? 1 : 0);
