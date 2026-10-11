<?php
/**
 * Simulador local da API do OpenRouter para os testes (sem rede, sem custo).
 * Uso: php -S 127.0.0.1:PORTA tests/mock_openrouter.php
 */
declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
header('Content-Type: application/json');
$log = sys_get_temp_dir() . '/mock-openrouter-' . ($_SERVER['SERVER_PORT'] ?? '0') . '.log';

if (str_ends_with($path, '/models')) {
    echo json_encode(['data' => [
        ['id' => 'google/gemini-2.5-flash', 'name' => 'Gemini 2.5 Flash', 'architecture' => ['input_modalities' => ['text', 'image', 'file']]],
        ['id' => 'texto/so-texto', 'name' => 'Só texto', 'architecture' => ['input_modalities' => ['text']]],
    ]]);
    exit;
}
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
$req = json_decode((string) file_get_contents('php://input'), true);
$system = (string) ($req['messages'][0]['content'] ?? '');
$user = $req['messages'][1]['content'] ?? ($req['messages'][0]['content'] ?? '');
$text = is_array($user) ? (string) ($user[0]['text'] ?? '') : (string) $user;
$parts = is_array($user) ? array_column(array_slice($user, 1), 'type') : [];
file_put_contents($log, json_encode(['model' => $req['model'] ?? null, 'parts' => $parts, 'text' => mb_substr($text, 0, 80)]) . "\n", FILE_APPEND);

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
} elseif (str_contains($system, 'assistente virtual')) {
    $content = json_encode(['resposta' => 'Entendi. Sidney vai responder sobre isso. Anotei que há 2 parcelas em atraso.', 'campos' => ['atraso' => 'sim', 'atraso_qtd' => 2, 'proposta_honorarios' => 'zero'], 'urgente' => false], JSON_UNESCAPED_UNICODE);
} else {
    $content = '{"ok": true}';
}
echo json_encode([
    'id' => 'gen-mock', 'model' => $req['model'] ?? '',
    'choices' => [['message' => ['role' => 'assistant', 'content' => $content]]],
    'usage' => ['prompt_tokens' => 1200, 'completion_tokens' => 90, 'cost' => 0.0004],
]);
