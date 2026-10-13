<?php
declare(strict_types=1);

/**
 * Painel do caso na ficha do atendimento: variáveis críticas (qualificação,
 * procuração e contrato) ainda pendentes, pergunta pronta para o cliente
 * (WhatsApp ou conversa do site), sugestões da IA para a análise e o .docx
 * da versão final dos documentos do kit gravado na pasta do Drive.
 */

/** Variáveis sem as quais a procuração e o contrato não saem completos. */
const CRITICAL_FIELDS = [
    'Qualificação' => ['nome_completo', 'nacionalidade', 'estado_civil', 'profissao', 'cpf', 'identificacao'],
    'Endereço' => ['cep', 'logradouro', 'numero', 'bairro', 'cidade', 'uf'],
    'Financiamento e veículo' => ['banco', 'contrato_numero', 'contrato_data', 'veiculo_marca', 'veiculo_modelo', 'veiculo_placa'],
    'Para a análise' => ['capital', 'prazo', 'parcela', 'taxa_mensal', 'parcelas_pagas'],
];

/** Texto da pergunta ao cliente para cada variável (linguagem simples). */
const CRITICAL_ASK = [
    'nome_completo' => 'nome completo', 'nacionalidade' => 'nacionalidade', 'estado_civil' => 'estado civil', 'profissao' => 'profissão',
    'cpf' => 'CPF', 'identificacao' => 'número do RG ou da CNH e o órgão que emitiu', 'cep' => 'CEP', 'logradouro' => 'rua ou avenida',
    'numero' => 'número da casa', 'bairro' => 'bairro', 'cidade' => 'cidade', 'uf' => 'estado (UF)', 'banco' => 'banco do financiamento',
    'contrato_numero' => 'número do contrato', 'contrato_data' => 'data em que assinou o contrato', 'veiculo_marca' => 'marca do carro',
    'veiculo_modelo' => 'modelo do carro', 'veiculo_placa' => 'placa', 'capital' => 'valor financiado', 'prazo' => 'número de parcelas',
    'parcela' => 'valor da parcela', 'taxa_mensal' => 'taxa de juros ao mês (está no contrato)', 'parcelas_pagas' => 'quantas parcelas já pagou',
];

/** [grupo => [['key', 'label', 'ok'], ...]] */
function critical_status(int $id): array
{
    $f = case_fields($id);
    $out = [];
    foreach (CRITICAL_FIELDS as $g => $keys) {
        foreach ($keys as $k) {
            $r = $f[$k] ?? null;
            $out[$g][] = ['key' => $k, 'label' => field_def($k)['label'], 'ok' => $r !== null && $r['state'] !== 'depois', 'row' => $r];
        }
    }
    return $out;
}

/** Chaves críticas ainda pendentes. */
function critical_missing(int $id): array
{
    $out = [];
    foreach (critical_status($id) as $items) {
        foreach ($items as $it) {
            if (!$it['ok']) {
                $out[] = $it['key'];
            }
        }
    }
    return $out;
}

/** Mensagem única pedindo ao cliente tudo o que falta. */
function critical_question(array $c, array $keys): string
{
    $id = (int) $c['id'];
    $nome = (string) strtok(trim((string) (fv($id, 'nome') ?: fv($id, 'nome_completo') ?: '')), ' ');
    $lines = array_map(fn($k) => '• ' . (CRITICAL_ASK[$k] ?? mb_strtolower(field_def($k)['label'])), $keys);
    return 'Olá' . ($nome !== '' && $c['client_name'] !== CLIENT_NAME_PENDING ? ', ' . $nome : '') . '! Aqui é Sidney Fatuche. Para preparar sua procuração e o contrato'
        . ($c['protocol'] ? ' (atendimento ' . $c['protocol'] . ')' : '') . ", só falta confirmar:\n" . implode("\n", $lines)
        . "\nPode me responder por aqui mesmo. Se preferir, mande a foto do documento que eu tiro as informações dele.";
}

/* ---------- Sugestões da IA para a análise (rascunho para Sidney revisar) ---------- */

const ANALYSIS_AI_FIELDS = ['perguntas_reuniao', 'processos_notificacoes', 'proposta_escopo', 'proposta_riscos', 'proposta_exclusoes'];

/** Preenche só os campos vazios, marcados "IA · conferir". Retorna [preenchidos, erro]. */
function analysis_ai_suggest(int $id): array
{
    if (!llm_enabled()) {
        return [[], 'A IA está desligada (Configurações › Inteligência artificial).'];
    }
    $empty = array_values(array_filter(ANALYSIS_AI_FIELDS, fn($k) => !isset(case_fields($id, true)[$k])));
    if (!$empty) {
        return [[], 'Os campos que a IA sugere já estão preenchidos.'];
    }
    $ctx = (array) case_data(find_case($id), 'contexto_ia', []);
    $res = llm_chat([
        ['role' => 'system', 'content' => 'Você prepara rascunhos para o advogado Sidney Fatuche (financiamento de veículo). Use só os fatos informados; '
            . 'não afirme ilegalidade nem prometa resultado; texto objetivo em português do Brasil, que Sidney vai revisar. Responda SOMENTE com JSON com as chaves pedidas.'],
        ['role' => 'user', 'content' => "CHAVES (preencha só estas):\n" . ia_field_spec($empty)
            . "\n- perguntas_reuniao: perguntas que Sidney deve fazer na reunião, uma por linha"
            . "\n- processos_notificacoes: o que o cliente relatou sobre processos, notificações ou apreensão (ou \"Nenhum relatado\")"
            . "\n- proposta_escopo: o serviço a ser prestado, em 2 a 4 frases"
            . "\n- proposta_riscos: riscos e limites do caso que precisam ser explicados ao cliente"
            . "\n- proposta_exclusoes: o que não está incluído no serviço"
            . "\n\nCONTEXTO:\n" . (string) ($ctx['contexto'] ?? case_story($id)) . "\n\nFICHA:\n" . case_facts_text($id)],
    ], $id, 'analise:sugestao', 1400, 90);
    $json = $res['ok'] ? llm_json($res['content']) : null;
    if (!$json) {
        return [[], 'A IA não respondeu no formato esperado. Tente de novo.'];
    }
    $done = [];
    foreach ($empty as $k) {
        $v = $json[$k] ?? null;
        if (is_array($v)) {
            $v = implode("\n", array_map(fn($x) => is_scalar($x) ? (string) $x : '', $v));
        }
        $v = trim((string) (is_scalar($v) ? $v : ''));
        if ($v !== '' && set_field($id, $k, mb_substr($v, 0, (int) (field_def($k)['max'] ?? 3000)), 'informado', 'ia') === null) {
            $done[] = $k;
        }
    }
    if ($done) {
        add_event($id, 'ia', 'IA sugeriu campos da análise', implode(', ', array_map(fn($k) => field_def($k)['label'], $done)) . '. Conferir antes de validar.');
    }
    return [$done, $done ? null : 'A IA não trouxe sugestões.'];
}

/* ---------- Versão final do kit no Drive (.docx) ---------- */

/** Grava o .docx da versão na pasta do atendimento. [id no Drive, erro]. */
function drive_save_kit(array $c, array $v): array
{
    if (!drive_enabled()) {
        return [null, 'Drive não conectado.'];
    }
    $saved = (array) case_data($c, 'drive_kit', []);
    if (!empty($saved[$v['id']])) {
        return [$saved[$v['id']], null];
    }
    [$folder, $err] = drive_case_folder($c);
    if (!$folder) {
        return [null, $err];
    }
    $label = KIT_ITEMS[$v['kind']]['label'];
    $bin = doc_docx(doc_blocks((string) $v['content']), ['titulo' => $label, 'assunto' => 'Atendimento ' . $c['protocol'] . ' · versão ' . $v['version'], 'autor' => office('lawyer') ?: 'Sidney Fatuche']);
    [$did, $err] = drive_upload(['name' => $label . ' - ' . $c['protocol'] . ' - v' . $v['version'] . '.docx', 'parents' => [$folder]], $bin,
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    if ($did) {
        $saved[$v['id']] = $did;
        set_case_data((int) $c['id'], 'drive_kit', $saved);
        add_event((int) $c['id'], 'drive', $label . ' v' . $v['version'] . ' gravado no Google Drive (.docx)');
    } else {
        add_event((int) $c['id'], 'drive', $label . ' v' . $v['version'] . ' não foi para o Google Drive', $err);
    }
    return [$did, $err];
}
