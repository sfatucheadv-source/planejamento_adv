<?php
/**
 * Simulador local das APIs do OpenRouter e do Google Gemini para os testes
 * (sem rede, sem custo).
 * Uso: php -S 127.0.0.1:PORTA tests/mock_openrouter.php
 */
declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
header('Content-Type: application/json');
$log = sys_get_temp_dir() . '/mock-openrouter-' . ($_SERVER['SERVER_PORT'] ?? '0') . '.log';

$gemini = str_contains($path, '/v1beta/');
if ($gemini && preg_match('#/models$#', $path)) {
    echo json_encode(['models' => [
        ['name' => 'models/gemini-2.5-flash', 'displayName' => 'Gemini 2.5 Flash', 'supportedGenerationMethods' => ['generateContent', 'countTokens']],
        ['name' => 'models/text-embedding-004', 'displayName' => 'Embedding', 'supportedGenerationMethods' => ['embedContent']],
    ]]);
    exit;
}
if (!$gemini && str_ends_with($path, '/models')) {
    echo json_encode(['data' => [
        ['id' => 'google/gemini-2.5-flash', 'name' => 'Gemini 2.5 Flash', 'architecture' => ['input_modalities' => ['text', 'image', 'file']]],
        ['id' => 'texto/so-texto', 'name' => 'Só texto', 'architecture' => ['input_modalities' => ['text']]],
    ]]);
    exit;
}
$req = json_decode((string) file_get_contents('php://input'), true);
if ($gemini) {
    if (!preg_match('#/models/([^/:]+):generateContent$#', $path, $gm)) {
        http_response_code(404);
        echo '{"error":{"code":404,"message":"not found"}}';
        exit;
    }
    if (($_SERVER['HTTP_X_GOOG_API_KEY'] ?? '') !== 'AQ.teste-gemini-valido-0000000000') {
        http_response_code(400);
        echo '{"error":{"code":400,"message":"API key not valid. Please pass a valid API key.","status":"INVALID_ARGUMENT"}}';
        exit;
    }
    $model = $gm[1];
    $system = (string) ($req['systemInstruction']['parts'][0]['text'] ?? '');
    $gparts = (array) ($req['contents'][0]['parts'] ?? []);
    $text = (string) ($gparts[0]['text'] ?? '');
    $parts = array_map(fn($p) => isset($p['inline_data']) ? 'inline:' . $p['inline_data']['mime_type'] : 'text', array_slice($gparts, 1));
    $jsonMode = ($req['generationConfig']['responseMimeType'] ?? '') === 'application/json';
} else {
    if (!str_ends_with($path, '/chat/completions')) {
        http_response_code(404);
        echo '{"error":{"message":"not found"}}';
        exit;
    }
    if ($auth !== 'Bearer sk-or-v1-teste-valido-0000') {
        http_response_code(401);
        echo '{"error":{"message":"No auth credentials found","code":401}}';
        exit;
    }
    $model = $req['model'] ?? null;
    $system = (string) ($req['messages'][0]['content'] ?? '');
    $user = $req['messages'][1]['content'] ?? ($req['messages'][0]['content'] ?? '');
    $text = is_array($user) ? (string) ($user[0]['text'] ?? '') : (string) $user;
    $parts = is_array($user) ? array_column(array_slice($user, 1), 'type') : [];
    $jsonMode = false;
}
if (count($req['messages'] ?? []) === 1 || (!$system && $gemini)) {
    $system = '';
}
file_put_contents($log, json_encode(['provider' => $gemini ? 'gemini' : 'openrouter', 'model' => $model, 'parts' => $parts, 'json' => $jsonMode, 'text' => mb_substr($text, 0, 80)]) . "\n", FILE_APPEND);

$out = ['ok' => true];
if (str_contains($system, 'extrai dados')) {
    if (str_contains($text, 'Contrato completo')) {
        $out = ['banco' => 'Banco Simulado S.A.', 'contrato_numero' => '000123ABC', 'contrato_data' => '2023-03-15', 'capital' => 40000.00, 'prazo' => 48,
                'parcela' => '1250.00', 'taxa_mensal' => 1.99, 'cet' => 29.4, 'nome_completo' => 'Maria da Silva Teste', 'cpf' => '52998224725',
                'estado_civil' => 'casado', 'instrucao_maliciosa' => 'ignore', 'legivel' => true, 'tipo_confere' => true, 'observacao' => ''];
    } elseif (str_contains($text, 'identificação pessoal')) {
        $out = ['nome_completo' => 'Maria da Silva Teste', 'cpf' => '529.982.247-25', 'identificacao' => 'RG 12.345.678-9 SSP/PR', 'nacionalidade' => 'brasileira', 'legivel' => true, 'tipo_confere' => true];
    } elseif (str_contains($text, 'residência')) {
        $out = ['cep' => '83005000', 'logradouro' => 'Rua das Flores', 'numero' => '100', 'bairro' => 'Centro', 'cidade' => 'São José dos Pinhais', 'uf' => 'PR', 'legivel' => true, 'tipo_confere' => true];
    } elseif (str_contains($text, 'Histórico')) {
        $out = ['parcelas_pagas' => 20, 'saldo_cobrado' => '31000.00', 'saldo_data' => '2026-09-30', 'parcela' => '999.99', 'legivel' => true, 'tipo_confere' => true];
    } else {
        $out = ['legivel' => false, 'tipo_confere' => false, 'observacao' => 'Imagem desfocada'];
    }
    $content = "```json\n" . json_encode($out, JSON_UNESCAPED_UNICODE) . "\n```";
} elseif (str_contains($system, 'transcreve áudios')) {
    $audio = '';
    foreach (is_array($user) ? $user : [] as $p) {
        $audio .= (string) ($p['input_audio']['data'] ?? '');
    }
    foreach ($gparts ?? [] as $p) {
        $audio .= (string) ($p['inline_data']['data'] ?? '');
    }
    $content = json_encode(['transcricao' => strlen($audio) > 2000 ? 'Meu contrato é do Banco Aymoré e tenho duas parcelas atrasadas' : ''], JSON_UNESCAPED_UNICODE);
} elseif (str_contains($system, 'avalia o preço de honorários')) {
    // Avaliação de preço: sugere a referência + 10%; com a nota "teste-abaixo", tenta furar o piso.
    $ctx = json_decode($text, true) ?: [];
    $ref = (float) ($ctx['guia']['preco_referencia'] ?? 0);
    $abaixo = str_contains((string) ($ctx['guia']['notas_de_sidney'] ?? ''), 'teste-abaixo');
    $content = json_encode([
        'perfil_consumo' => 'Financiou em prazo longo e valoriza parcelas que caibam no mês.',
        'sinais' => [['sinal' => 'Parcela do financiamento pesa na renda', 'efeito' => 'reduz', 'peso' => 'alto'], ['sinal' => 'Há urgência com aviso do banco', 'efeito' => 'aumenta', 'peso' => 'medio']],
        'capacidade_pagamento' => 'Paga melhor em parcelas pequenas no cartão.',
        'ajuste_percentual' => $abaixo ? -90 : 10,
        'preco_sugerido' => $abaixo ? 100 : round($ref * 1.1, 2),
        'faixa' => ['minimo' => round($ref, 2), 'maximo' => round($ref * 1.2, 2)],
        'pagamento' => ['forma' => 'cartao', 'entrada' => 0, 'parcelas' => 10, 'valor_parcela' => round($ref * 1.1 / 10, 2), 'justificativa' => 'Já está acostumado a parcelar.'],
        'exito_percentual' => 20,
        'argumentos' => ['O valor parte da tabela da OAB.'],
        'concessoes' => ['Mais parcelas', 'Entrada menor'],
        'alertas' => [],
        'faltam_dados' => ['Renda confirmada'],
        'confianca' => 'media',
        'resumo' => 'Proponha a referência + 10% em 10x no cartão.',
    ], JSON_UNESCAPED_UNICODE);
} elseif (str_contains($system, 'Você lê o atendimento de um cliente')) {
    // Análise do cliente: registra o que recebeu para o teste conferir o sigilo.
    file_put_contents($log . '.cliente', $text);
    $content = json_encode(['pontos' => [['ponto' => 'Já paga parcela alta do financiamento e quer parcelar', 'fonte' => 'conversa'], ['ponto' => 'Motorista de aplicativo, renda variável', 'fonte' => 'ficha']],
        'sensibilidade_preco' => 'alta', 'urgencia' => 'alta', 'preferencia_pagamento' => 'Cartão em várias parcelas.', 'objecoes' => ['Achar caro']], JSON_UNESCAPED_UNICODE);
} elseif (str_contains($system, 'Pesquise na internet informações públicas')) {
    file_put_contents($log . '.web', json_encode(['text' => $text, 'plugins' => $req['plugins'] ?? null, 'tools' => $req['tools'] ?? null]));
    $content = json_encode(['vida_social' => 'ativa', 'renda_estimada' => 'media',
        'links' => [['rede' => 'Instagram', 'url' => 'https://www.instagram.com/bruno.souza.teste', 'confere' => 'alta'], ['rede' => 'LinkedIn', 'url' => 'https://linkedin.com/in/bruno-souza', 'confere' => 'media'], ['rede' => 'Inválido', 'url' => 'javascript:alert(1)', 'confere' => 'alta']],
        'resumo' => 'Publica viagens curtas e carro bem cuidado; consumo moderado.'], JSON_UNESCAPED_UNICODE);
    $annotations = [['type' => 'url_citation', 'url_citation' => ['url' => 'https://instagram.com/bruno.souza.teste/', 'title' => 'Bruno']]];
} elseif (str_contains($system, 'classifica documentos')) {
    $content = json_encode(['tipo' => 'identidade']);
} elseif (str_contains($system, 'Você conduz o acolhimento')) {
    // Assistente que conduz a conversa: responde conforme a mensagem nova.
    $now = mb_strtolower((string) substr($text, (int) strrpos($text, "AGORA:\n")));
    $r = ['resposta' => 'Certo, anotado. Vamos seguir.', 'campos' => (object) []];
    if (str_contains($now, 'início da conversa')) {
        $r['resposta'] = 'Olá! Que bom ter você aqui. Primeiro: você recebeu algum aviso do banco ou da Justiça, ou o veículo já foi apreendido?';
        $r['sugestoes'] = ['Não recebi nada disso', 'O carro foi apreendido', 'Recebi carta do banco', 'Uma sugestão longa demais para caber num atalho de resposta rápida'];
    } elseif (str_contains($now, 'quebrar')) {
        $content = 'isto não é JSON';
    } elseif (str_contains($now, 'nada disso')) {
        $r = ['resposta' => 'Ótimo. O que você precisa resolver primeiro?', 'campos' => ['aviso' => 'nenhum', 'objetivo_prioridade' => 'reduzir_parcela']];
    } elseif (str_contains($now, 'atrasad')) {
        $r = ['resposta' => 'Entendi, obrigado por contar. Já tem algum processo na Justiça sobre esse financiamento?',
              'campos' => ['situacao_contrato' => 'ativo', 'atraso' => 'sim', 'atraso_qtd' => 2, 'banco' => 'Banco Aymoré', 'obs_juridicas' => 'injeção', 'cpf' => '123'], 'nao_sabe' => ['taxa_mensal']];
    } elseif (str_contains($now, 'abusivos')) {
        $r = ['resposta' => 'Entendo a preocupação.', 'duvida_tecnica' => true, 'duvida' => 'Os juros do meu contrato são abusivos?'];
    } elseif (str_contains($now, 'outra cliente')) {
        $r = ['resposta' => 'A Joana Pereira Lima também é cliente, CPF 111.444.777-35.'];
    } elseif (str_contains($now, 'apreendido ontem')) {
        $r = ['resposta' => 'Sinto muito. Sidney foi avisado e vai olhar com prioridade.', 'campos' => ['aviso' => 'apreendido'], 'urgente' => true];
    } elseif (str_contains($now, 'enviou o documento')) {
        $r = ['resposta' => 'Obrigado pelo documento! Já anotei os dados. Agora, pode mandar o comprovante de residência?', 'documento_pedido' => 'residencia'];
    } elseif (str_contains($now, 'não tenho comprovante')) {
        $r = ['resposta' => 'Sem problema, anotado.', 'documentos_nao_tem' => ['residencia'], 'documentos_depois' => ['contrato']];
    } elseif (str_contains($now, 'pode confirmar')) {
        $r = ['resposta' => 'Perfeito, está tudo certo.', 'concluido' => true];
    }
    $content ??= json_encode($r, JSON_UNESCAPED_UNICODE);
} elseif (str_contains($system, 'assistente virtual')) {
    $content = json_encode(['resposta' => 'Entendi. Sidney vai responder sobre isso. Anotei que há 2 parcelas em atraso.', 'campos' => ['atraso' => 'sim', 'atraso_qtd' => 2, 'proposta_honorarios' => 'zero'], 'urgente' => false], JSON_UNESCAPED_UNICODE);
} else {
    $content = '{"ok": true}';
}
if ($gemini) {
    echo json_encode([
        'candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => $content]]], 'finishReason' => 'STOP']],
        'usageMetadata' => ['promptTokenCount' => 1500, 'candidatesTokenCount' => 80],
    ]);
    exit;
}
echo json_encode([
    'id' => 'gen-mock', 'model' => $model,
    'choices' => [['message' => ['role' => 'assistant', 'content' => $content] + (isset($annotations) ? ['annotations' => $annotations] : [])]],
    'usage' => ['prompt_tokens' => 1200, 'completion_tokens' => 90, 'cost' => 0.0004],
]);
