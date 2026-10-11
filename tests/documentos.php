<?php
/**
 * Documentos do kit no padrão do escritório: procuração, declaração de
 * hipossuficiência e contrato de honorários (mais proposta e parecer).
 *
 * Confere, com um atendimento completo:
 *  - preenchimento: nenhuma variável ou marcador pendente, concordância de
 *    gênero, padrões do escritório (poderes, finalidade com variáveis aninhadas);
 *  - .docx: pacote válido, XML bem formado, timbrado no cabeçalho e no rodapé,
 *    A4 com as margens do formatador, todo o texto presente;
 *  - HTML: paginação no navegador sem texto perdido e sem folha transbordando
 *    (se houver Node com Playwright);
 *  - conversão do .docx em PDF pelo LibreOffice (se instalado).
 *
 * Uso: php tests/documentos.php [pasta-de-saída]
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$db = sys_get_temp_dir() . '/fatuche-docs-' . getmypid() . '.sqlite';
@unlink($db);
putenv('FATUCHE_DB=' . $db);
require $root . '/app/bootstrap.php';

$out = $argv[1] ?? (sys_get_temp_dir() . '/fatuche-documentos');
@mkdir($out, 0777, true);

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

echo "\nD1. Atendimento completo\n";
$uid = insert('users', ['name' => 'Sidney Teste', 'email' => 'sidney@teste.local', 'roles' => 'supervisor', 'pass_hash' => password_hash('senha-de-teste-1', PASSWORD_DEFAULT), 'active' => 1, 'created_at' => now_str()]);
set_setting('operator_user_id', (string) $uid);
$c = create_initial_case(['nome' => 'Ana', 'telefone' => '(41) 98888-3333', 'email' => 'ana.teste@exemplo.com', 'adaptacao' => ''], 'site');
$id = (int) $c['id'];
$fields = [
    'nome_completo' => 'Ana Paula Ribeiro Teste', 'cpf' => '529.982.247-25', 'nacionalidade' => 'brasileira', 'estado_civil' => 'casado', 'genero' => 'feminino',
    'profissao' => 'professora', 'identificacao' => 'RG 12.345.678-9 SESP/PR', 'telefone' => '(41) 98888-3333', 'email' => 'ana.teste@exemplo.com',
    'cep' => '80010-000', 'logradouro' => 'Rua XV de Novembro', 'numero' => '1000', 'complemento' => 'ap. 12', 'bairro' => 'Centro', 'cidade' => 'Curitiba', 'uf' => 'PR',
    'relacao_contrato' => 'titular', 'banco' => 'Banco Exemplo S.A.', 'contrato_numero' => '123456789', 'contrato_data' => '15/03/2023', 'capital' => '45.000,00',
    'prazo' => '48', 'parcela' => '1.450,00', 'taxa_mensal' => '2,19', 'cet' => '31,5', 'parcelas_pagas' => '30', 'saldo_cobrado' => '22.000,00', 'saldo_data' => '01/10/2026',
    'situacao_contrato' => 'ativo', 'atraso' => 'nao',
    'veiculo_marca' => 'Fiat', 'veiculo_modelo' => 'Argo Drive 1.0', 'veiculo_ano' => '2022/2023', 'veiculo_placa' => 'BRA2E19', 'veiculo_renavam' => '00639884962',
];
$sidney = ['data_base' => '01/10/2026', 'bacen_taxa' => '1,8', 'bacen_periodo' => '03/2023', 'bacen_fonte' => 'SGS/BCB, consulta em 01/10/2026',
    'metodo' => 'Recálculo das parcelas pela taxa média de mercado da série 25471, mantidos prazo e capital.', 'resultados' => 'Diferença estimada de R$ 4.300,00 no período.',
    'limitacoes' => 'Sem acesso ao extrato completo de encargos.', 'conclusao' => 'revisao', 'conclusao_texto' => 'A taxa contratada supera a média de mercado do período.',
    'revisao_data' => '01/12/2026', 'proposta_escopo' => "- análise do contrato e dos pagamentos;\n- notificação extrajudicial ao banco;\n- ação revisional em primeiro grau.",
    'proposta_exclusoes' => 'Recursos aos tribunais superiores e defesa em ação de busca e apreensão.', 'proposta_honorarios' => 'R$ 2.400,00 fixos, em duas etapas, e 20% sobre o benefício econômico.',
    'proposta_base_exito' => 'Restituições e redução definitiva do saldo devedor.', 'proposta_pagamento' => 'Entrada de R$ 800,00 e duas parcelas mensais de R$ 800,00.',
    'proposta_despesas' => 'Custas e perícia contábil, quando necessárias, pagas diretamente pelo cliente.', 'proposta_riscos' => 'Possibilidade de sucumbência em caso de improcedência.',
    'proposta_validade' => '30/10/2026', 'proposta_percentual_exito' => '20% (vinte por cento)', 'processos_notificacoes' => 'Não há processo, notificação ou apreensão.',
    'testemunha1_nome' => 'Carlos Testemunha', 'testemunha1_cpf' => '111.444.777-35'];
foreach ($fields as $k => $v) {
    $e = set_field($id, $k, $v, 'informado', 'contrato');
    ok($e === null, "campo $k" . ($e ? ": $e" : ''));
}
foreach ($sidney as $k => $v) {
    $e = set_field($id, $k, $v, 'informado', 'sidney');
    if ($e) {
        ok(false, "campo $k: $e");
    }
}
sync_intake_documents($id);
q("UPDATE documents SET status = 'conferido' WHERE case_id = ?", [$id]);
set_setting('office_response_days', '2 (dois)');
set_setting('office_urgent', 'Ligar para o telefone oficial e enviar foto do documento recebido pelo WhatsApp.');
set_setting('default_foro', 'Comarca de Curitiba/PR');
set_setting('default_prazo_repasse', '5 (cinco) dias úteis');
setting('__reset');
case_fields($id, true);

echo "\nD2. Preenchimento dos modelos\n";
$c = find_case($id);
$docs = [];
foreach (['procuracao', 'hipossuficiencia', 'honorarios', 'proposta', 'parecer'] as $k) {
    $err = kit_generate($c, $k);
    $v = kit_latest($id, $k);
    $docs[$k] = $v;
    $miss = json_decode((string) $v['missing'], true) ?: [];
    ok($err === null && !$miss && !doc_pending((string) $v['content']), KIT_ITEMS[$k]['label'] . ': gerado sem pendências' . ($miss ? ' (' . implode('; ', $miss) . ')' : '') . (doc_pending((string) $v['content']) ? ' [' . implode(' ', doc_pending((string) $v['content'])) . ']' : ''));
}
$p = $docs['procuracao']['content'];
ok(str_contains($p, 'inscrita junto ao CPF/MF: 529.982.247-25') && str_contains($p, 'residente e domiciliada à Rua XV de Novembro, 1000'), 'procuração: concordância no feminino e endereço completo');
ok(str_contains($p, '^^Ana Paula Ribeiro Teste^^, casada, professora'), 'procuração: estado civil concordando com o gênero');
ok(str_contains($p, 'contrato de financiamento de veículo n.º 123456789, celebrado com Banco Exemplo S.A.') && !str_contains($p, 'S.A..'), 'procuração: finalidade padrão com as variáveis do contrato preenchidas (sem ponto duplo)');
ok(str_contains($p, '**PODERES ESPECÍFICOS**') && str_contains($p, 'Para representar os interesses da outorgante em juízo') && str_contains($p, 'em especial para representar os interesses da outorgante'), 'procuração: poderes do modelo do escritório, com concordância');
ok(str_contains($p, 'honorários contratuais no valor de 20% (vinte por cento)'), 'procuração: percentual de honorários da ficha');
ok(str_contains($p, 'Curitiba, ' . fmt_date(now()->format('Y-m-d'), true)), 'procuração: local e data');
$h = $docs['hipossuficiencia']['content'];
ok(str_contains($h, '^^Ana Paula Ribeiro Teste^^, brasileira, casada, professora, inscrita no CPF'), 'declaração: qualificação completa');
$ct = $docs['honorarios']['content'];
ok(str_contains($ct, 'Veículo: Fiat Argo Drive 1.0, ano 2022/2023, placa BRA2E19, Renavam 00639884962'), 'contrato: veículo');
ok(str_contains($ct, 'Situação atual: adimplente') && str_contains($ct, 'Processos ou notificações existentes: Não há processo'), 'contrato: situação e processos');
ok(str_contains($ct, 'a chave informada é o e-mail: **sidney@fatuche.adv.br**, de titularidade de **Sidney Fatuche Sociedade Individual de Advocacia**'), 'contrato: PIX pelas variáveis do escritório');
ok(str_contains($ct, 'Canal oficial do escritório: (41) 99906-2936 e sidney@fatuche.adv.br') && str_contains($ct, 'Prazo usual de resposta: 2 (dois) dias úteis'), 'contrato: canal e prazo de resposta');
ok(str_contains($ct, 'em até 5 (cinco) dias úteis após') && str_contains($ct, 'foro da **Comarca de Curitiba/PR**'), 'contrato: repasse e foro');
ok(str_contains($ct, 'inscrita no CPF sob o n.º') && str_contains($ct, 'doravante denominada **CONTRATANTE**'), 'contrato: concordância no feminino');
ok(str_contains($ct, '@assinatura Carlos Testemunha | CPF 111.444.777-35') && str_contains($ct, '@assinatura Nome: | CPF ____________________'), 'contrato: testemunha preenchida e a segunda em branco para assinatura física');
ok(!preg_match('/\[[^\[\]\n]{2,}\]/u', str_replace(['[[', ']]'], '', $ct)), 'contrato: nenhum colchete do gabarito ficou para trás');

echo "\nD3. Gênero não informado\n";
q("DELETE FROM case_fields WHERE case_id = ? AND field = 'genero'", [$id]);
case_fields($id, true);
[$alt] = render_template(doc_template('procuracao')['body'], kit_vars($id));
ok(str_contains($alt, 'inscrito(a) junto ao CPF') && str_contains($alt, 'casado(a)') && str_contains($alt, 'interesses do(a) outorgante'), 'sem gênero, sai a forma dupla “inscrito(a)”');
set_field($id, 'genero', 'feminino', 'informado', 'sidney');
case_fields($id, true);

echo "\nD4. Diagramação em blocos\n";
$b = doc_blocks($ct);
$types = array_count_values(array_column($b, 'type'));
ok(($types['titulo'] ?? 0) === 1 && ($types['secao'] ?? 0) === 9 && ($types['subsecao'] ?? 0) === 22, 'contrato: título, 8 seções + anexo, 22 cláusulas');
ok(($types['assinatura'] ?? 0) === 4 && ($types['qualificacao'] ?? 0) === 2 && ($types['lista'] ?? 0) === 1, 'contrato: 4 assinaturas, qualificação das partes e escopo em lista');
$anexo = array_values(array_filter($b, fn($x) => $x['type'] === 'secao' && str_starts_with($x['text'], 'Anexo')))[0] ?? [];
ok(!empty($anexo['quebra']), 'Anexo I começa em página nova');
$sec = array_values(array_filter($b, fn($x) => $x['type'] === 'secao'));
ok($sec[0]['num'] === 'I' && $sec[0]['text'] === 'Do objeto e da extensão dos serviços', 'numeral da seção separado do título');

echo "\nD5. .docx\n";
$zipOk = class_exists('ZipArchive');
foreach ($docs as $k => $v) {
    $bin = doc_docx(doc_blocks((string) $v['content']), ['titulo' => KIT_ITEMS[$k]['label']]);
    $file = "$out/$k.docx";
    file_put_contents($file, $bin);
    $parts = [];
    if ($zipOk) {
        $z = new ZipArchive();
        $okZip = $z->open($file) === true;
        for ($i = 0; $okZip && $i < $z->numFiles; $i++) {
            $parts[$z->getNameIndex($i)] = $z->getFromIndex($i);
        }
    } else {
        $okZip = true;
        exec('cd ' . escapeshellarg($out) . ' && python3 -c ' . escapeshellarg('import zipfile,sys,json; z=zipfile.ZipFile(sys.argv[1]); print(json.dumps({n: z.read(n).decode("latin-1") for n in z.namelist()}))') . ' ' . escapeshellarg($file), $o2, $rc);
        $parts = $rc === 0 ? array_map(fn($s) => mb_convert_encoding($s, 'ISO-8859-1', 'UTF-8'), json_decode(implode('', $o2), true)) : [];
    }
    $xmlOk = true;
    foreach ($parts as $n => $data) {
        if (str_ends_with($n, '.xml') || str_ends_with($n, '.rels')) {
            $d = new DOMDocument();
            $xmlOk = $xmlOk && @$d->loadXML($data) !== false;
        }
    }
    $doc = $parts['word/document.xml'] ?? '';
    $text = html_entity_decode(strip_tags(str_replace(['<w:br/>', '</w:p>'], ["\n", "\n"], $doc)), ENT_QUOTES | ENT_XML1, 'UTF-8');
    $plain = fn($s) => preg_replace('/\s+/u', ' ', str_replace(['**', '^^'], '', $s));
    $missingText = [];
    foreach (doc_blocks((string) $v['content']) as $blk) {
        foreach (array_merge([$blk['text'] ?? ''], $blk['lines'] ?? [], $blk['items'] ?? [], array_map(fn($r) => $r[1], $blk['rows'] ?? [])) as $t) {
            if ($t !== '' && !str_contains($plain($text), trim($plain($t)))) {
                $missingText[] = mb_substr($t, 0, 50);
            }
        }
    }
    ok($okZip && $xmlOk && isset($parts['word/media/timbrado-cabecalho.png'], $parts['word/media/timbrado-rodape.png']) && str_contains($parts['word/header1.xml'] ?? '', 'rIdImgCab'),
        KIT_ITEMS[$k]['label'] . ': .docx válido, com timbrado (' . round(strlen($bin) / 1024) . ' KB)');
    ok(!$missingText, KIT_ITEMS[$k]['label'] . ': todo o texto está no .docx' . ($missingText ? ' — falta: ' . implode(' | ', array_slice($missingText, 0, 3)) : ''));
    if ($k === 'honorarios') {
        ok(str_contains($doc, '<w:pgSz w:w="11906" w:h="16838"/>') && str_contains($doc, 'w:top="1814"') && str_contains($doc, 'w:left="1701"'), 'contrato: A4, margens 32/30 mm do formatador');
        ok(str_contains($doc, 'w:ascii="Lora"') && str_contains($doc, 'w:ascii="Poppins"') && str_contains($doc, '<w:smallCaps/>'), 'contrato: Lora no texto, Poppins e versalete nos títulos');
        ok(str_contains($doc, '<w:pageBreakBefore/>'), 'contrato: quebra de página antes do Anexo I');
        ok(str_contains($doc, 'w:firstLine="709"') && str_contains($doc, 'w:after="289"'), 'contrato: recuo 12,5 mm e 5,1 mm entre parágrafos');
    }
}

echo "\nD6. Página no timbrado (HTML)\n";
$port = 22000 + random_int(0, 900);
$pid = (int) shell_exec(sprintf('FATUCHE_DB=%s php -S 127.0.0.1:%d -t %s %s > /dev/null 2>&1 & echo $!', escapeshellarg($db), $port, escapeshellarg($root . '/public'), escapeshellarg($root . '/public/index.php')));
register_shutdown_function(function () use ($pid, $db) {
    if ($pid) {
        posix_kill($pid, SIGTERM);
    }
    @unlink($db);
});
$base = "http://127.0.0.1:$port";
for ($i = 0; $i < 50 && !@file_get_contents($base . '/robots.txt'); $i++) {
    usleep(100000);
}
$urls = [];
foreach ($docs as $k => $v) {
    $urls[$k] = '/painel/atendimento/' . $id . '/kit/' . $v['id'];
}
$urls['previa'] = '/painel/modelos-documentos/honorarios/previa';
$label = fn($k) => KIT_ITEMS[$k]['label'] ?? 'Prévia do modelo do contrato';
$node = trim((string) shell_exec('command -v node'));
$pw = is_dir('/opt/node22/lib/node_modules/playwright') ? '/opt/node22/lib/node_modules/playwright' : trim((string) shell_exec('npm root -g 2>/dev/null')) . '/playwright';
if ($node && is_dir($pw)) {
    $cmd = sprintf('NODE_PATH=%s %s %s %s %s %s', escapeshellarg(dirname($pw)), escapeshellarg($node), escapeshellarg(__DIR__ . '/documentos_navegador.mjs'),
        escapeshellarg($base), escapeshellarg(json_encode($urls)), escapeshellarg($out));
    $res = json_decode((string) shell_exec($cmd . ' 2>&1'), true);
    if (!$res) {
        ok(false, 'verificação no navegador não rodou');
    }
    foreach ($res ?? [] as $k => $r) {
        ok(($r['folhas'] ?? 0) > 0 && empty($r['erros']), $label($k) . ': ' . ($r['folhas'] ?? 0) . ' folha(s), paginado sem erro de JS' . (!empty($r['erros']) ? ' — ' . implode('; ', $r['erros']) : ''));
        ok(($r['transbordo'] ?? 1) === 0, $label($k) . ': nenhuma folha transborda');
        ok(!empty($r['identico']), $label($k) . ': texto das folhas idêntico ao do documento (nada perdido na paginação)');
        ok(($r['timbrado'] ?? 0) === ($r['folhas'] ?? -1), $label($k) . ': timbrado em todas as folhas');
    }
} else {
    echo "  · Node com Playwright indisponível: verificação no navegador pulada\n";
}

echo "\nD7. LibreOffice (.docx → PDF)\n";
$soffice = trim((string) shell_exec('command -v soffice'));
if ($soffice) {
    shell_exec(sprintf('cd %s && %s --headless --convert-to pdf *.docx > /dev/null 2>&1', escapeshellarg($out), escapeshellarg($soffice)));
    foreach (array_keys($docs) as $k) {
        $pdf = "$out/$k.pdf";
        $pages = is_file($pdf) ? (int) trim((string) shell_exec('pdfinfo ' . escapeshellarg($pdf) . " 2>/dev/null | awk '/^Pages:/{print \$2}'")) : 0;
        $txt = is_file($pdf) ? (string) shell_exec('pdftotext -layout ' . escapeshellarg($pdf) . ' - 2>/dev/null') : '';
        ok($pages > 0 && str_contains(mb_strtoupper(preg_replace('/\s+/u', ' ', $txt)), 'BANCO EXEMPLO') || str_contains(mb_strtoupper(preg_replace('/\s+/u', ' ', $txt)), 'ANA PAULA RIBEIRO TESTE'),
            KIT_ITEMS[$k]['label'] . ": abre no LibreOffice ($pages página(s))");
    }
} else {
    echo "  · LibreOffice indisponível: conversão pulada\n";
}

echo "\nArquivos em $out\n";
echo "\n" . ($fails ? "FALHAS: $fails" : 'TUDO OK') . " · $passes verificações aprovadas\n";
exit($fails ? 1 : 0);
