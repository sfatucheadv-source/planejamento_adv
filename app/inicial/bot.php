<?php
declare(strict_types=1);

/**
 * Seção 5 — assistente virtual por roteiro determinístico (sem IA).
 * Cada resposta grava campos estruturados e a conversa. A pergunta atual é
 * calculada a partir do que já foi respondido: fechar a página e voltar
 * retoma do ponto certo, e só o que falta é perguntado de novo.
 */

const BOT_GREETING = 'Olá! Sou o assistente virtual da Fatuche Advocacia. Vou organizar suas informações e documentos para a análise de Sidney. Você poderá conversar com ele depois. Vamos começar?';
const BOT_RECEIVED = 'Arquivo recebido. Sidney ainda vai conferir se está completo e legível.';
const BOT_OFFSCRIPT = 'Obrigado. Sua mensagem foi registrada e Sidney vai conferir. Enquanto isso, podemos continuar organizando suas informações, ou você pode aguardar o contato dele.';
const BOT_DONE = 'Suas informações foram organizadas. Sidney irá conferir os documentos e preparar a conversa. Veja abaixo se ficou algo pendente.';

/** Lista ordenada de perguntas do roteiro (B02 a B10). */
function bot_script(): array
{
    $hasContract = fn(int $id) => in_array(scalar("SELECT status FROM documents WHERE case_id = ? AND kind = 'contrato'", [$id]), ['recebido', 'conferido'], true);
    $checkContract = fn(int $id) => $hasContract($id) ? 'Posso deixar para Sidney conferir no contrato' : 'Não sei';
    $active = fn(int $id) => in_array(fv($id, 'situacao_contrato'), ['ativo', 'renegociado', 'nao_sei', null], true);
    return [
        // B02 — urgência
        ['key' => 'aviso', 'block' => 'B02', 'field' => 'aviso', 'kind' => 'choice',
         'ask' => 'Primeiro, uma verificação importante: você recebeu algum destes avisos, ou o veículo já foi apreendido?'],
        ['key' => 'aviso_data', 'block' => 'B02', 'field' => 'aviso_data', 'kind' => 'date', 'unknown' => 'Não lembro a data',
         'ask' => 'Quando você recebeu ou quando aconteceu?', 'when' => fn($id) => !in_array(fv($id, 'aviso'), [null, 'nenhum'], true)],
        ['key' => 'aviso_descricao', 'block' => 'B02', 'field' => 'aviso_descricao', 'kind' => 'textarea', 'later' => 'Vou mandar uma foto depois',
         'ask' => 'Conte com suas palavras o que você recebeu, de quem veio e o que dizia. Se tiver uma foto, poderá enviar na parte de documentos.',
         'when' => fn($id) => fv($id, 'aviso') === 'nao_sei'],
        // B03 — objetivo
        ['key' => 'objetivo_prioridade', 'block' => 'B03', 'field' => 'objetivo_prioridade', 'kind' => 'choice',
         'ask' => 'O que você precisa resolver primeiro?'],
        ['key' => 'objetivo_texto', 'block' => 'B03', 'field' => 'objetivo_texto', 'kind' => 'textarea', 'skip' => 'Não tenho mais nada a acrescentar agora',
         'ask' => 'Se quiser, conte mais sobre a sua situação. Tudo o que você escrever chega para Sidney do jeito que você escreveu.'],
        ['key' => 'resumo_confirmado', 'block' => 'B03', 'field' => 'resumo_confirmado', 'kind' => 'confirm',
         'ask' => fn($id) => 'Deixa eu confirmar se entendi: ' . bot_summary_sentence($id)],
        // B04 — situação
        ['key' => 'situacao_contrato', 'block' => 'B04', 'field' => 'situacao_contrato', 'kind' => 'choice',
         'ask' => 'Como está o contrato de financiamento hoje?'],
        ['key' => 'atraso', 'block' => 'B04', 'field' => 'atraso', 'kind' => 'choice',
         'ask' => 'Há parcelas em atraso?', 'when' => fn($id) => fv($id, 'situacao_contrato') !== 'quitado'],
        ['key' => 'atraso_qtd', 'block' => 'B04', 'field' => 'atraso_qtd', 'kind' => 'int', 'unknown' => 'Não sei ao certo',
         'ask' => 'Quantas parcelas estão em atraso?', 'when' => fn($id) => fv($id, 'atraso') === 'sim'],
        ['key' => 'processo_existente', 'block' => 'B04', 'field' => 'processo_existente', 'kind' => 'choice',
         'ask' => 'Já existe algum processo na Justiça sobre esse financiamento?'],
        ['key' => 'outro_advogado', 'block' => 'B04', 'field' => 'outro_advogado', 'kind' => 'choice',
         'ask' => 'Algum outro advogado está cuidando deste assunto?'],
        // B05 — qualificação (atendimento reservado)
        ['key' => 'relacao_contrato', 'block' => 'B05', 'field' => 'relacao_contrato', 'kind' => 'choice',
         'ask' => 'Agora alguns dados para os documentos. O contrato está no seu nome?'],
        ['key' => 'titular_nome', 'block' => 'B05', 'field' => 'titular_nome', 'kind' => 'text', 'unknown' => 'Não sei o nome completo',
         'ask' => 'Qual é o nome do titular do contrato?', 'when' => fn($id) => in_array(fv($id, 'relacao_contrato'), ['representante', 'terceiro'], true)],
        ['key' => 'representacao', 'block' => 'B05', 'field' => 'representacao', 'kind' => 'text', 'later' => 'Explico para Sidney na conversa',
         'ask' => 'Como você representa o titular? Por exemplo: procuração, curatela, inventário.', 'when' => fn($id) => fv($id, 'relacao_contrato') === 'representante'],
        ['key' => 'nome_completo', 'block' => 'B05', 'field' => 'nome_completo', 'kind' => 'text', 'later' => 'Informo depois',
         'ask' => 'Qual é o seu nome completo, como está no documento?'],
        ['key' => 'cpf', 'block' => 'B05', 'field' => 'cpf', 'kind' => 'cpf', 'later' => 'Informo depois',
         'ask' => 'Qual é o seu CPF?'],
        ['key' => 'estado_civil', 'block' => 'B05', 'field' => 'estado_civil', 'kind' => 'choice', 'later' => 'Prefiro informar depois',
         'ask' => 'Qual é o seu estado civil?'],
        ['key' => 'profissao', 'block' => 'B05', 'field' => 'profissao', 'kind' => 'text', 'later' => 'Informo depois',
         'ask' => 'Qual é a sua profissão ou ocupação?'],
        ['key' => 'nacionalidade', 'block' => 'B05', 'field' => 'nacionalidade', 'kind' => 'text', 'suggest' => 'brasileira',
         'ask' => 'Qual é a sua nacionalidade?'],
        ['key' => 'endereco', 'block' => 'B05', 'kind' => 'group', 'later' => 'Informo o endereço depois',
         'fields' => ['cep', 'logradouro', 'numero', 'complemento', 'bairro', 'cidade', 'uf'], 'optional' => ['complemento'],
         'ask' => 'Qual é o seu endereço? Se não souber o CEP, pode deixar em branco e preencher o resto.'],
        // B06 — documentos (uma pergunta por documento pendente)
        ['key' => 'docs', 'block' => 'B06', 'kind' => 'docs'],
        // B07 — financeiro ("não sei" sempre permitido)
        ['key' => 'banco', 'block' => 'B07', 'field' => 'banco', 'kind' => 'text', 'unknown' => $checkContract,
         'ask' => 'Qual é o banco ou a financeira do contrato?'],
        ['key' => 'contrato_data', 'block' => 'B07', 'field' => 'contrato_data', 'kind' => 'date', 'unknown' => $checkContract,
         'ask' => 'Quando o contrato foi assinado? A data aproximada já ajuda.'],
        ['key' => 'capital', 'block' => 'B07', 'field' => 'capital', 'kind' => 'money', 'unknown' => $checkContract,
         'ask' => 'Qual foi o valor financiado (sem contar a entrada)?'],
        ['key' => 'prazo', 'block' => 'B07', 'field' => 'prazo', 'kind' => 'int', 'unknown' => $checkContract,
         'ask' => 'Em quantas parcelas foi o financiamento?'],
        ['key' => 'parcela', 'block' => 'B07', 'field' => 'parcela', 'kind' => 'money', 'unknown' => $checkContract,
         'ask' => 'Qual é o valor da parcela?'],
        ['key' => 'taxa_mensal', 'block' => 'B07', 'field' => 'taxa_mensal', 'kind' => 'rate', 'unknown' => $checkContract,
         'ask' => 'Você sabe a taxa de juros ao mês que está no contrato? (por exemplo, 1,89%)'],
        ['key' => 'cet', 'block' => 'B07', 'field' => 'cet', 'kind' => 'rate', 'unknown' => $checkContract,
         'ask' => 'E o CET (custo efetivo total) ao ano?'],
        ['key' => 'parcelas_pagas', 'block' => 'B07', 'field' => 'parcelas_pagas', 'kind' => 'int', 'unknown' => $checkContract,
         'ask' => 'Quantas parcelas você já pagou?', 'when' => $active],
        // B08 — gratuidade
        ['key' => 'gratuidade', 'block' => 'B08', 'field' => 'gratuidade', 'kind' => 'choice',
         'ask' => 'Você quer que Sidney avalie um pedido de justiça gratuita? Isso não é decidido aqui: ele analisa com você.'],
        ['key' => 'renda_atividade', 'block' => 'B08', 'field' => 'renda_atividade', 'kind' => 'text', 'later' => 'Explico depois',
         'ask' => 'Qual é a sua atividade e a sua fonte de renda?', 'when' => fn($id) => fv($id, 'gratuidade') === 'sim'],
        // B09 — preferência de reunião (não é agendamento)
        ['key' => 'reuniao_canal', 'block' => 'B09', 'field' => 'reuniao_canal', 'kind' => 'choice',
         'ask' => 'A conversa pode ser por vídeo no Google Meet. Se preferir, pode ser por telefone, WhatsApp por texto ou por este chat. Qual formato funciona melhor?'],
        ['key' => 'reuniao_disponibilidade', 'block' => 'B09', 'field' => 'reuniao_disponibilidade', 'kind' => 'text', 'skip' => 'Qualquer horário comercial',
         'ask' => 'Quais dias e horários costumam ser melhores para você? Sidney confirma o horário depois.'],
        // B10 — resumo
        ['key' => 'final', 'block' => 'B10', 'kind' => 'final', 'ask' => 'Pronto! Confira o resumo do que você informou.'],
    ];
}

function bot_summary_sentence(int $id): string
{
    $p = fv($id, 'objetivo_prioridade');
    $s = 'sua prioridade é ' . (PRIORITY_OPTIONS[$p]['sentence'] ?? 'resolver sua situação');
    if (in_array(fv($id, 'aviso'), ['apreendido', 'justica', 'cartorio'], true)) {
        $s .= '. Como há ' . match (fv($id, 'aviso')) { 'apreendido' => 'uma apreensão', 'justica' => 'um documento da Justiça', default => 'uma notificação de cartório' } . ', Sidney vai olhar esse ponto primeiro';
    }
    return $s . '. Está correto?';
}

/** Perguntas aplicáveis, já expandindo a de documentos. */
function bot_questions(int $id): array
{
    $out = [];
    foreach (bot_script() as $q) {
        if (isset($q['when']) && !($q['when'])($id)) {
            continue;
        }
        if ($q['kind'] === 'docs') {
            foreach (all("SELECT * FROM documents WHERE case_id = ? AND status <> 'dispensado' ORDER BY essential DESC, id", [$id]) as $d) {
                $out[] = ['key' => 'doc_' . $d['kind'], 'block' => 'B06', 'kind' => 'doc', 'doc' => $d,
                          'ask' => doc_question_text($d)];
            }
            continue;
        }
        if (is_callable($q['ask'] ?? null)) {
            $q['ask'] = ($q['ask'])($id);
        }
        foreach (['unknown', 'later', 'skip'] as $k) {
            if (isset($q[$k]) && is_callable($q[$k])) {
                $q[$k] = ($q[$k])($id);
            }
        }
        $out[] = $q;
    }
    return $out;
}

function doc_question_text(array $d): string
{
    $cat = DOC_CATALOG[$d['kind']] ?? null;
    $t = ($d['status'] === 'pendencia' ? 'Precisamos de uma nova cópia: ' : 'Você tem ') . mb_strtolower($d['label']) . ($d['status'] === 'pendencia' ? '.' : '?');
    if ($d['status'] === 'pendencia' && $d['reject_reason']) {
        $t .= ' Motivo: ' . $d['reject_reason'] . '.';
    }
    if ($cat) {
        $t .= ' ' . $cat['why'] . ' ' . $cat['how'];
    }
    return $t;
}

function bot_answered(int $id, array $q): bool
{
    switch ($q['kind']) {
        case 'group':
            foreach ($q['fields'] as $f) {
                if (!field_answered($id, $f)) {
                    return false;
                }
            }
            return true;
        case 'doc':
            $d = one('SELECT status FROM documents WHERE id = ?', [$q['doc']['id']]);
            if (!$d || !in_array($d['status'], ['falta', 'ajuda', 'pendencia'], true)) {
                return true;
            }
            return $d['status'] !== 'pendencia' && isset(case_data(find_case($id), 'doc_later', [])[$q['doc']['kind']]);
        case 'final':
            return (bool) scalar('SELECT intake_done_at FROM cases WHERE id = ?', [$id]);
        default:
            return field_answered($id, $q['field']);
    }
}

/** Pergunta da vez, ou null quando o assistente não deve perguntar nada. */
function bot_current(array $c): ?array
{
    if ($c['chat_mode'] !== 'bot' || !is_initial($c) || $c['initial_status'] === 'completed') {
        return null;
    }
    $id = (int) $c['id'];
    $qs = bot_questions($id);
    if ($c['intake_edit']) {
        foreach ($qs as $q) {
            if ($q['key'] === $c['intake_edit']) {
                $q['editing'] = true;
                return $q;
            }
        }
    }
    foreach ($qs as $q) {
        if (!bot_answered($id, $q)) {
            return $q;
        }
    }
    return null;
}

/** Texto curto da resposta, para a conversa. */
function bot_answer_text(int $id, array $q): string
{
    if ($q['kind'] === 'group') {
        $parts = [];
        foreach ($q['fields'] as $f) {
            $r = case_fields($id)[$f] ?? null;
            if ($r && $r['state'] === 'informado') {
                $parts[] = field_display($f, $r);
            } elseif ($r && $r['state'] === 'depois') {
                return 'Vou informar depois';
            }
        }
        return implode(', ', $parts) ?: '—';
    }
    return field_display($q['field'], case_fields($id)[$q['field']] ?? null) ?: '—';
}

/**
 * Registra a resposta à pergunta $key. Retorna erro ou null.
 * $key de envio (idempotência): o mesmo envio repetido não duplica nada.
 */
function bot_answer(array $c, string $qkey, array $in, string $sendKey): ?string
{
    $id = (int) $c['id'];
    $q = null;
    foreach (bot_questions($id) as $cand) {
        if ($cand['key'] === $qkey) {
            $q = $cand;
            break;
        }
    }
    $current = bot_current($c);
    if (!$q || !$current) {
        return 'Esta pergunta já foi respondida. Veja a próxima abaixo.';
    }
    // Só se responde a pergunta da vez ou uma correção pedida.
    if ($current['key'] !== $qkey) {
        return 'Esta pergunta já foi respondida. Para mudar, use “Corrigir resposta”.';
    }
    if ($sendKey !== '' && scalar('SELECT 1 FROM chat_messages WHERE case_id = ? AND client_key = ?', [$id, $sendKey])) {
        return null; // retentativa: já gravado
    }
    $mode = (string) ($in['mode'] ?? 'answer'); // answer | unknown | later | skip
    $errors = [];
    $writes = [];
    switch ($q['kind']) {
        case 'group':
            if ($mode === 'later') {
                foreach ($q['fields'] as $f) {
                    $writes[] = [$f, null, 'depois'];
                }
                break;
            }
            foreach ($q['fields'] as $f) {
                $raw = trim((string) ($in[$f] ?? ''));
                if ($raw === '') {
                    $writes[] = [$f, null, in_array($f, $q['optional'] ?? [], true) ? 'nao_se_aplica' : 'desconhecido'];
                    continue;
                }
                [, $err] = normalize_field(field_def($f), $raw);
                if ($err) {
                    $errors[] = field_def($f)['label'] . ': ' . $err;
                }
                $writes[] = [$f, $raw, 'informado'];
            }
            $filled = array_filter($writes, fn($w) => $w[2] === 'informado');
            if (!array_filter($filled, fn($w) => in_array($w[0], ['logradouro', 'cidade'], true))) {
                $errors[] = 'Informe pelo menos a rua e a cidade, ou escolha “informo depois”.';
            }
            break;
        case 'confirm':
            if (($in['value'] ?? '') === 'sim') {
                $writes[] = ['resumo_confirmado', 'sim', 'informado'];
            } else {
                $fix = trim((string) ($in['correction'] ?? ''));
                if ($fix === '') {
                    return 'Escreva o que precisa ser corrigido no resumo.';
                }
                $writes[] = ['resumo_confirmado', 'corrigiu', 'informado'];
                $writes[] = ['resumo_correcao', $fix, 'informado'];
            }
            break;
        case 'final':
            break;
        case 'doc':
            return 'Use os botões do documento.';
        default:
            $state = match ($mode) {
                'unknown' => isset($q['unknown']) ? 'desconhecido' : null,
                'later' => isset($q['later']) ? 'depois' : null,
                'skip' => isset($q['skip']) ? 'nao_se_aplica' : null,
                default => 'informado',
            };
            if ($state === null) {
                return 'Responda à pergunta para continuar.';
            }
            $raw = $state === 'informado' ? (string) ($in['value'] ?? '') : null;
            if ($state === 'informado') {
                [, $err] = normalize_field(field_def($q['field']), (string) $raw);
                if ($err) {
                    return $err;
                }
            }
            $writes[] = [$q['field'], $raw, $state];
    }
    if ($errors) {
        return implode(' ', $errors);
    }

    db()->beginTransaction();
    try {
        chat_post($id, 'bot', $q['ask'], $q['key']);
        foreach ($writes as [$f, $raw, $state]) {
            $e = set_field($id, $f, $raw, $state, 'cliente');
            if ($e) {
                db()->rollBack();
                return $e;
            }
        }
        if ($q['kind'] === 'final') {
            $answer = 'Confirmo o resumo.';
            bump_case($id, ['intake_done_at' => now_str(), 'intake_edit' => null, 'activity_at' => now_str()]);
            chat_post($id, 'client', $answer, $q['key'], $sendKey ?: null);
            chat_post($id, 'bot', BOT_DONE);
            add_event($id, 'coleta', 'Coleta organizada pelo assistente', 'Sidney vai conferir os documentos e preparar a conversa.', true, 'cliente');
            if (!scalar("SELECT 1 FROM tasks WHERE case_id = ? AND kind = 'conferir_coleta' AND status = 'aberta'", [$id])) {
                set_next($id, 'Conferir a coleta e os documentos', in_business_days(1), in_business_days(1));
            }
        } else {
            chat_post($id, 'client', bot_answer_text($id, $q), $q['key'], $sendKey ?: null);
            bump_case($id, ['intake_edit' => null, 'activity_at' => now_str()]);
        }
        bot_after_answer($id, $q['key']);
        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        throw $e;
    }
    return null;
}

/** Efeitos de uma resposta: urgência imediata e checklist condicional. */
function bot_after_answer(int $id, string $key): void
{
    if (in_array($key, ['aviso', 'aviso_data'], true)) {
        update_urgency_signal($id);
    }
    if (in_array($key, ['aviso', 'processo_existente', 'objetivo_prioridade', 'gratuidade', 'situacao_contrato'], true)) {
        sync_intake_documents($id);
    }
    if ($key === 'gratuidade' && fv($id, 'gratuidade') === 'orientacao'
        && !scalar("SELECT 1 FROM case_pending_items WHERE case_id = ? AND ref = 'gratuidade' AND status = 'aberta'", [$id])) {
        add_pending($id, 'esclarecimento', 'Orientar sobre justiça gratuita (o cliente pediu orientação)', 'pontual', 'gratuidade');
    }
}

/** Resposta sobre um documento: envio, "não tenho" ou "vou enviar depois". */
function bot_doc_answer(array $c, int $docId, string $choice, array $files, string $sendKey, bool $byClient = true): ?string
{
    $id = (int) $c['id'];
    $d = one('SELECT * FROM documents WHERE id = ? AND case_id = ?', [$docId, $id]);
    if (!$d) {
        return 'Documento não encontrado.';
    }
    if ($sendKey !== '' && scalar('SELECT 1 FROM chat_messages WHERE case_id = ? AND client_key = ?', [$id, $sendKey])) {
        return null;
    }
    $ask = doc_question_text($d);
    if ($choice === 'enviar') {
        if (!$files) {
            return 'Escolha o arquivo para enviar.';
        }
        $saved = 0;
        foreach ($files as $i => $f) {
            $r = store_case_file($f, $id, $docId, $byClient, $sendKey !== '' ? $sendKey . '-' . $i : null);
            if (is_string($r)) {
                return $f['name'] . ': ' . $r;
            }
            $saved++;
        }
        q("UPDATE documents SET status = 'recebido', received_at = ?, reject_reason = NULL WHERE id = ?", [now_str(), $docId]);
        $later = case_data($c, 'doc_later', []);
        unset($later[$d['kind']]);
        set_case_data($id, 'doc_later', $later);
        chat_post($id, 'bot', $ask, 'doc_' . $d['kind']);
        chat_post($id, 'client', 'Enviei ' . plural($saved, 'arquivo', 'arquivos') . ': ' . $d['label'], 'doc_' . $d['kind'], $sendKey ?: null);
        chat_post($id, 'bot', BOT_RECEIVED);
        add_event($id, 'documento', 'Recebemos: ' . $d['label'], 'Sidney confere se está completo e legível.', true, $byClient ? 'cliente' : 'equipe');
        mark_client_activity($id);
        $c2 = find_case($id);
        if ($c2['preparation_at'] && in_array($d['kind'], ['contrato', 'historico'], true)) {
            bump_case($id, ['review_flag' => 'analise']);
            add_event($id, 'revisao', 'Análise/kit precisa de revisão', 'Novo arquivo de “' . $d['label'] . '” depois da análise aprovada.');
        }
        return null;
    }
    if ($choice === 'nao_tenho') {
        q("UPDATE documents SET status = 'nao_possui' WHERE id = ?", [$docId]);
        chat_post($id, 'bot', $ask, 'doc_' . $d['kind']);
        chat_post($id, 'client', 'Não tenho: ' . $d['label'], 'doc_' . $d['kind'], $sendKey ?: null);
        chat_post($id, 'bot', $d['kind'] === 'contrato'
            ? 'Sem problema: seu atendimento continua aberto. Sidney vai orientar como pedir a cópia ao banco. Enquanto isso, vamos seguir.'
            : 'Tudo bem, anotado. Sidney vai avaliar se é possível seguir sem ele ou como obtê-lo.');
        add_event($id, 'documento', 'Você informou que não tem: ' . $d['label'], null, true, 'cliente');
        mark_client_activity($id);
        return null;
    }
    if ($choice === 'depois') {
        $later = case_data($c, 'doc_later', []);
        $later[$d['kind']] = now_str();
        set_case_data($id, 'doc_later', $later);
        chat_post($id, 'bot', $ask, 'doc_' . $d['kind']);
        chat_post($id, 'client', 'Vou enviar depois: ' . $d['label'], 'doc_' . $d['kind'], $sendKey ?: null);
        return null;
    }
    return 'Escolha uma opção.';
}

/** Grava arquivo com chave de idempotência, tipo e visibilidade. */
function store_case_file(array $file, int $caseId, ?int $docId, bool $byClient, ?string $key = null, ?string $kind = null, bool $internal = false): int|string
{
    if ($key !== null && ($fid = scalar('SELECT id FROM files WHERE case_id = ? AND client_key = ?', [$caseId, $key]))) {
        return (int) $fid;
    }
    $r = store_upload($file, $caseId, $docId, $byClient);
    if (is_int($r)) {
        q('UPDATE files SET client_key = ?, kind = ?, internal = ? WHERE id = ?', [$key, $kind, $internal ? 1 : 0, $r]);
    }
    return $r;
}
