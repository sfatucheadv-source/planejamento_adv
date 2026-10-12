<?php
declare(strict_types=1);

/**
 * Seção 10 — kit: modelos configuráveis (sem editar PHP), preenchimento pelos
 * campos aprovados, versões com fotografia dos dados, revisão e publicação.
 * Variável ausente fica sinalizada e impede a publicação daquele documento.
 */

/**
 * Modelos iniciais, em app/inicial/modelos/*.txt (marcação descrita em
 * diagramacao.php). Procuração, declaração e contrato seguem os modelos do
 * escritório; Sidney revisa e aprova cada um antes da primeira publicação.
 */
const TEMPLATE_TITLES = [
    'proposta' => 'Proposta de atendimento',
    'honorarios' => 'Contrato de honorários',
    'procuracao' => 'Procuração',
    'hipossuficiencia' => 'Declaração de hipossuficiência',
    'parecer' => 'Parecer de cálculos',
];

function default_templates(): array
{
    $out = [];
    foreach (TEMPLATE_TITLES as $key => $title) {
        $out[$key] = [$title, rtrim((string) file_get_contents(__DIR__ . '/modelos/' . $key . '.txt')) . "\n"];
    }
    return $out;
}

function seed_templates(): void
{
    foreach (default_templates() as $key => [$title, $body]) {
        q('INSERT OR IGNORE INTO doc_templates (key, title, body, version, updated_at) VALUES (?,?,?,1,?)', [$key, $title, $body, now_str()]);
    }
}

function doc_template(string $key): ?array
{
    seed_templates();
    return one('SELECT * FROM doc_templates WHERE key = ?', [$key]);
}

/** Variáveis aprovadas para os modelos (seção 14.2: somente chaves aprovadas). */
function template_var_labels(): array
{
    $out = builtin_var_labels();
    foreach (custom_vars() as $k => $r) {
        $out[$k] = $r['label'];
    }
    return $out;
}

function builtin_var_labels(): array
{
    return [
        'atendimento.protocolo' => 'Protocolo', 'data.hoje' => 'Data de hoje', 'data.cidade' => 'Cidade do escritório', 'kit.versao' => 'Versão do documento',
        'cliente.nome_completo' => 'Nome completo', 'cliente.cpf' => 'CPF', 'cliente.nacionalidade' => 'Nacionalidade', 'cliente.estado_civil' => 'Estado civil',
        'cliente.profissao' => 'Profissão', 'cliente.identificacao' => 'Documento de identificação', 'cliente.endereco' => 'Endereço completo',
        'cliente.telefone' => 'Telefone', 'cliente.email' => 'E-mail', 'titular.nome' => 'Titular do contrato',
        'contrato.banco' => 'Banco', 'contrato.numero' => 'Número do contrato', 'contrato.data' => 'Data do contrato', 'contrato.capital' => 'Valor financiado',
        'contrato.prazo' => 'Prazo', 'contrato.parcela' => 'Parcela', 'contrato.taxa_mensal' => 'Taxa mensal', 'contrato.cet' => 'CET',
        'contrato.parcelas_pagas' => 'Parcelas pagas', 'contrato.saldo' => 'Saldo e data-base',
        'contrato.situacao' => 'Situação do financiamento (adimplente, inadimplente ou quitado)', 'contrato.processos_notificacoes' => 'Processos, notificações e eventual apreensão existentes',
        'veiculo.marca' => 'Marca do veículo', 'veiculo.modelo' => 'Modelo do veículo', 'veiculo.ano' => 'Ano de fabricação/modelo', 'veiculo.placa' => 'Placa', 'veiculo.renavam' => 'Renavam',
        'analise.data_base' => 'Data-base', 'analise.bacen' => 'Referência BCB', 'analise.metodo' => 'Método', 'analise.resultados' => 'Resultados',
        'analise.limitacoes' => 'Limitações', 'analise.conclusao' => 'Conclusão', 'analise.conclusao_texto' => 'Conclusão por extenso',
        'analise.revisao_data' => 'Data de revisão', 'analise.documentos' => 'Documentos usados',
        'proposta.escopo' => 'Escopo', 'proposta.honorarios' => 'Honorários', 'proposta.base_exito' => 'Base de êxito', 'proposta.pagamento' => 'Pagamento',
        'proposta.despesas' => 'Despesas', 'proposta.riscos' => 'Riscos', 'proposta.exclusoes' => 'Exclusões', 'proposta.validade' => 'Validade',
        'proposta.prazo_repasse' => 'Prazo para repassar ao cliente o saldo recebido',
        'documento.foro' => 'Foro do contrato (comarca e UF)',
        'testemunha1.nome' => 'Testemunha 1 · nome', 'testemunha1.cpf' => 'Testemunha 1 · CPF', 'testemunha2.nome' => 'Testemunha 2 · nome', 'testemunha2.cpf' => 'Testemunha 2 · CPF',
        'procuracao.poderes' => 'Poderes da procuração', 'procuracao.finalidade' => 'Finalidade da procuração',
        'proposta.percentual_exito' => 'Honorários contratuais (percentual, por extenso)', 'cliente.genero' => 'Gênero do cliente (concordância)',
        'gratuidade.renda' => 'Atividade e renda',
        'escritorio.nome' => 'Sociedade', 'escritorio.oab' => 'Registro da sociedade', 'escritorio.cnpj' => 'CNPJ', 'escritorio.advogado' => 'Advogado',
        'escritorio.advogado_oab' => 'OAB do advogado',
    ] + array_map(fn($d) => $d['label'], OFFICE_VARS) + PROPOSAL_VAR_LABELS;
}

/** $proposal: dados de uma proposta ainda não salva (prévia da tela Propostas). */
function kit_vars(int $caseId, ?array $proposal = null): array
{
    $d = fn(string $f) => ($r = case_fields($caseId)[$f] ?? null) && $r['state'] === 'informado' ? field_display($f, $r) : null;
    $c = find_case($caseId);
    $bacen = null;
    if (fv($caseId, 'bacen_taxa')) {
        $bacen = 'Série ' . (fv($caseId, 'bacen_codigo') ?: '25471') . ' do Banco Central (taxa média de juros, aquisição de veículos, pessoa física), competência '
            . ($d('bacen_periodo') ?: '[[COMPLETAR: competência]]') . ': ' . $d('bacen_taxa') . '. Fonte: ' . ($d('bacen_fonte') ?: '[[COMPLETAR: fonte e data da consulta]]') . '.';
    }
    $saldo = $d('saldo_cobrado') ? $d('saldo_cobrado') . ($d('saldo_data') ? ' em ' . $d('saldo_data') : '') : null;
    $docs = array_column(all("SELECT label FROM documents WHERE case_id = ? AND status = 'conferido' ORDER BY id", [$caseId]), 'label');
    $renda = trim(implode(' · ', array_filter([$d('renda_atividade'), $d('renda_valor') ? 'renda aproximada de ' . $d('renda_valor') : null])));
    $titular = fv($caseId, 'relacao_contrato') === 'titular' || !fv($caseId, 'relacao_contrato') ? $d('nome_completo') : $d('titular_nome');
    $withDefault = fn(string $var) => $d(CASE_VAR_DEFAULTS[$var]['field']) ?? setting(CASE_VAR_DEFAULTS[$var]['setting']);
    $office = array_filter(array_map(fn($k) => office_var($k), array_combine(array_keys(OFFICE_VARS), array_keys(OFFICE_VARS))), fn($v) => $v !== null);
    $vars = custom_var_values($caseId) + $office + [
        'atendimento.protocolo' => $c['protocol'],
        'data.hoje' => fmt_date(now()->format('Y-m-d'), true),
        'data.cidade' => office('city') ?: null,
        'cliente.nome_completo' => $d('nome_completo'),
        'cliente.cpf' => $d('cpf'),
        'cliente.nacionalidade' => $d('nacionalidade'),
        'cliente.estado_civil' => $d('estado_civil') ? gendered_marital($d('estado_civil'), fv($caseId, 'genero')) : null,
        'cliente.genero' => fv($caseId, 'genero'),
        'cliente.profissao' => $d('profissao'),
        'cliente.identificacao' => $d('identificacao'),
        'cliente.endereco' => full_address($caseId),
        'cliente.telefone' => $d('telefone'),
        'cliente.email' => $d('email'),
        'titular.nome' => $titular,
        'contrato.banco' => $d('banco'),
        'contrato.numero' => $d('contrato_numero'),
        'contrato.data' => $d('contrato_data'),
        'contrato.capital' => $d('capital'),
        'contrato.prazo' => $d('prazo'),
        'contrato.parcela' => $d('parcela'),
        'contrato.taxa_mensal' => $d('taxa_mensal'),
        'contrato.cet' => $d('cet'),
        'contrato.parcelas_pagas' => $d('parcelas_pagas'),
        'contrato.saldo' => $saldo,
        'contrato.situacao' => ($sit = financing_standing($caseId)) ? mb_strtolower(FINANCING_STANDING[$sit]) : null,
        'contrato.processos_notificacoes' => $d('processos_notificacoes'),
        'veiculo.marca' => $d('veiculo_marca'),
        'veiculo.modelo' => $d('veiculo_modelo'),
        'veiculo.ano' => $d('veiculo_ano'),
        'veiculo.placa' => $d('veiculo_placa'),
        'veiculo.renavam' => $d('veiculo_renavam'),
        'analise.data_base' => $d('data_base'),
        'analise.bacen' => $bacen,
        'analise.metodo' => $d('metodo'),
        'analise.resultados' => $d('resultados'),
        'analise.limitacoes' => $d('limitacoes'),
        'analise.conclusao' => $d('conclusao'),
        'analise.conclusao_texto' => $d('conclusao_texto'),
        'analise.revisao_data' => $d('revisao_data'),
        'analise.documentos' => $docs ? implode('; ', $docs) : null,
        'proposta.escopo' => $d('proposta_escopo'),
        'proposta.honorarios' => $d('proposta_honorarios'),
        'proposta.base_exito' => $d('proposta_base_exito'),
        'proposta.pagamento' => $d('proposta_pagamento'),
        'proposta.despesas' => $d('proposta_despesas'),
        'proposta.riscos' => $d('proposta_riscos'),
        'proposta.exclusoes' => $d('proposta_exclusoes'),
        'proposta.validade' => $d('proposta_validade'),
        'proposta.prazo_repasse' => $withDefault('proposta.prazo_repasse'),
        'proposta.percentual_exito' => $withDefault('proposta.percentual_exito'),
        'procuracao.finalidade' => $withDefault('procuracao.finalidade'),
        'documento.foro' => $withDefault('documento.foro'),
        'testemunha1.nome' => $d('testemunha1_nome'),
        'testemunha1.cpf' => $d('testemunha1_cpf'),
        'testemunha2.nome' => $d('testemunha2_nome'),
        'testemunha2.cpf' => $d('testemunha2_cpf'),
        'procuracao.poderes' => $withDefault('procuracao.poderes'),
        'gratuidade.renda' => $renda ?: null,
        'escritorio.nome' => office('society') ?: null,
        'escritorio.oab' => office('society_oab') ?: null,
        'escritorio.cnpj' => office('cnpj') ?: null,
        'escritorio.advogado' => office('lawyer') ?: null,
        'escritorio.advogado_oab' => office('lawyer_oab') ?: null,
    ];
    // A proposta registrada (módulo Propostas) prevalece sobre os textos da ficha.
    foreach ($proposal !== null ? proposal_vars_data($proposal) : proposal_vars($caseId) as $k => $v) {
        if ($v !== null || isset(PROPOSAL_VAR_LABELS[$k])) {
            $vars[$k] = $v;
        }
    }
    // O padrão do escritório pode citar outras variáveis (ex.: a finalidade
    // da procuração cita o banco e o número do contrato): preenche uma vez.
    foreach (CASE_VAR_DEFAULTS as $var => $def) {
        if ($d($def['field']) === null && is_string($vars[$var] ?? null) && str_contains($vars[$var], '{{')) {
            $vars[$var] = render_template($vars[$var], array_diff_key($vars, CASE_VAR_DEFAULTS))[0];
        }
    }
    return $vars;
}

/**
 * Situação do financiamento para o contrato: a escolhida por Sidney ou, sem
 * ela, a que decorre do que o cliente informou (quitado, em atraso ou em dia).
 */
function financing_standing(int $caseId): ?string
{
    if ($v = fv($caseId, 'contrato_situacao')) {
        return $v;
    }
    if (fv($caseId, 'situacao_contrato') === 'quitado') {
        return 'quitado';
    }
    return match (fv($caseId, 'atraso')) {
        'sim' => 'inadimplente',
        'nao' => in_array(fv($caseId, 'situacao_contrato'), ['ativo', 'renegociado'], true) ? 'adimplente' : null,
        default => null,
    };
}

/**
 * Substitui {{chave}} ou {{chave|texto padrão}} em uma única passada: o texto
 * vindo do cliente é dado, nunca é reinterpretado como modelo.
 * Retorna [conteúdo, faltas].
 */
function render_template(string $body, array $vars): array
{
    $labels = template_var_labels();
    $missing = [];
    // Concordância de gênero: {{flex:inscrito/inscrita}}. Sem o gênero na ficha,
    // sai a forma dupla "inscrito(a)".
    $body = preg_replace_callback('/\{\{\s*flex:([^}\/]*)\/([^}]*)\}\}/u', fn($m) => flex_word(trim($m[1]), trim($m[2]), $vars['cliente.genero'] ?? null), $body);
    // Cláusulas condicionais e numeração automática (antes dos dados: o texto do cliente nunca vira comando)
    [$body, $condMissing] = template_conditions($body, $vars, $labels);
    $body = template_numbering($body);
    $missing = $condMissing;
    $out = preg_replace_callback('/\{\{\s*([a-z0-9_.]+)\s*(?:\|([^}]*))?\}\}/u', function ($m) use ($vars, $labels, &$missing) {
        $key = $m[1];
        if (!array_key_exists($key, $labels)) {
            $missing[] = 'variável não aprovada: ' . $key;
            return '[[FALTA: ' . $key . ']]';
        }
        $v = $vars[$key] ?? null;
        if ($v === null || trim((string) $v) === '') {
            if (array_key_exists(2, $m)) {
                return trim($m[2]);
            }
            $missing[] = $labels[$key];
            return '[[FALTA: ' . $labels[$key] . ']]';
        }
        // marca de negrito ou versalete sem par no dado desmontaria o resto do parágrafo
        $v = (string) $v;
        foreach (['**', '^^'] as $mk) {
            if (substr_count($v, $mk) % 2) {
                $v = str_replace($mk, '', $v);
            }
        }
        return $v;
    }, $body);
    // "S.A." no fim de um dado seguido do ponto do modelo: um ponto só
    $out = preg_replace('/(?<!\.)\.\.(?!\.)/u', '.', (string) $out);
    $out = preg_replace("/\n{3,}/", "\n\n", (string) $out);
    return [$out, array_values(array_unique(array_merge($missing, content_markers($out, $missing))))];
}

/** Marcadores [[...]] que sobraram no texto (inclusive em edição manual). */
/**
 * Blocos condicionais: linhas "@se chave" … "@senao" … "@fimse" (aninháveis).
 * O bloco entra quando a variável tem valor (não vazio e diferente de "0").
 */
function template_conditions(string $body, array $vars, array $labels): array
{
    $out = [];
    $stack = [];
    $missing = [];
    foreach (explode("\n", $body) as $line) {
        $t = trim($line);
        if (preg_match('/^@se\s+(nao\s+)?([a-z0-9_.]+)$/u', $t, $m)) {
            if (!array_key_exists($m[2], $labels)) {
                $missing[] = 'variável não aprovada: ' . $m[2];
            }
            $v = $vars[$m[2]] ?? null;
            $on = $v !== null && trim((string) $v) !== '' && (string) $v !== '0';
            $stack[] = $m[1] !== '' ? !$on : $on;
            continue;
        }
        if ($t === '@senao' && $stack) {
            $stack[] = !array_pop($stack);
            continue;
        }
        if ($t === '@fimse' && $stack) {
            array_pop($stack);
            continue;
        }
        if (!in_array(false, $stack, true)) {
            $out[] = $line;
        }
    }
    if ($stack) {
        $missing[] = 'modelo: “@se” sem “@fimse”';
    }
    return [preg_replace("/\n{3,}/", "\n\n", implode("\n", $out)), $missing];
}

/**
 * Numeração automática: "### Cláusula # — título {#chave}" vira "Cláusula
 * primeira — título"; "**Parágrafo #.**" é contado dentro de cada cláusula
 * (um só vira "Parágrafo único"); "{{clausula:chave}}" vira "Cláusula Quarta".
 * Assim, excluir uma cláusula ou um parágrafo não deixa a numeração saltar.
 */
function template_numbering(string $body): string
{
    $lines = explode("\n", $body);
    $refs = [];
    $n = 0;
    $clauseOf = [];
    $current = -1;
    foreach ($lines as $i => $line) {
        if (preg_match('/^###\s+Cláusula\s+#\s+(.*?)(?:\s*\{#([a-z0-9_]+)\})?\s*$/u', $line, $m)) {
            $n++;
            $current = $i;
            if (!empty($m[2])) {
                $refs[$m[2]] = $n;
            }
            $lines[$i] = '### Cláusula ' . ordinal_feminino($n) . ' ' . $m[1];
        } elseif (preg_match('/^#{1,3}\s/u', $line)) {
            $current = -1;
        }
        if (str_contains($line, '**Parágrafo #.**')) {
            $clauseOf[$i] = $current;
        }
    }
    $count = array_count_values(array_map('strval', $clauseOf));
    $seen = [];
    foreach ($clauseOf as $i => $c) {
        $seen[$c] = ($seen[$c] ?? 0) + 1;
        $word = ($count[(string) $c] ?? 0) === 1 ? 'único' : ordinal_masculino($seen[$c]);
        $lines[$i] = str_replace('**Parágrafo #.**', '**Parágrafo ' . $word . '.**', $lines[$i]);
    }
    $out = implode("\n", $lines);
    return preg_replace_callback('/\{\{\s*clausula:([a-z0-9_]+)\s*\}\}/u', fn($m) => isset($refs[$m[1]])
        ? 'Cláusula ' . implode(' ', array_map(fn($w) => mb_strtoupper(mb_substr($w, 0, 1)) . mb_substr($w, 1), explode(' ', ordinal_feminino($refs[$m[1]]))))
        : '[[FALTA: cláusula ' . $m[1] . ']]', $out);
}

function flex_word(string $masc, string $fem, ?string $genero): string
{
    if ($genero === 'feminino') {
        return $fem;
    }
    if ($genero === 'masculino') {
        return $masc;
    }
    if ($masc !== '' && mb_substr($fem, 0, -1) === mb_substr($masc, 0, -1) && mb_substr($masc, -1) === 'o' && mb_substr($fem, -1) === 'a') {
        return $masc . '(a)';
    }
    return $masc . '(a)' === $fem ? $fem : $masc . '/' . $fem;
}

/** Estado civil concordando com o gênero: "Solteiro(a)" → solteira. */
function gendered_marital(string $label, ?string $genero): string
{
    $label = mb_strtolower($label);
    return match ($genero) {
        'feminino' => preg_replace('/o\(a\)/u', 'a', $label),
        'masculino' => preg_replace('/\(a\)/u', '', $label),
        default => $label,
    };
}

function content_markers(string $content, array $known = []): array
{
    preg_match_all('/\[\[(?:FALTA|COMPLETAR|A COMPLETAR):?\s*([^\]]*)\]\]/u', $content, $m);
    $out = [];
    foreach ($m[1] as $x) {
        $x = trim($x);
        if (!in_array($x, $known, true) && !in_array('variável não aprovada: ' . $x, $known, true)) {
            $out[] = 'completar: ' . $x;
        }
    }
    return $out;
}

/* ---------- Ficha para adequação da inicial (seção 10.3) ---------- */

function ficha_inicial_content(int $caseId): string
{
    $f = case_fields($caseId);
    $d = fn(string $k) => isset($f[$k]) ? field_display($k, $f[$k]) : '';
    $ni = fn(string $k) => ($v = $d($k)) !== '' ? $v : '(não informado)';
    $c = find_case($caseId);
    $L = [];
    $L[] = 'FICHA PARA ADEQUAÇÃO DA INICIAL · atendimento ' . $c['protocol'] . ' · ' . fmt_date(now()->format('Y-m-d'));
    $L[] = "\n1. Autor, representação e endereço";
    $L[] = 'Nome: ' . $ni('nome_completo') . ' · CPF: ' . $ni('cpf') . ' · ' . $ni('estado_civil') . ' · ' . $ni('profissao');
    $L[] = 'Endereço: ' . (full_address($caseId) ?? '(não informado)');
    $L[] = 'Relação com o contrato: ' . $ni('relacao_contrato') . (fv($caseId, 'relacao_contrato') && fv($caseId, 'relacao_contrato') !== 'titular' ? ' · titular: ' . $ni('titular_nome') . ' · ' . $ni('representacao') : '');
    $L[] = "\n2. Credor";
    $L[] = $ni('banco') . ' · CNPJ: ' . $ni('credor_cnpj');
    $L[] = "\n3. Contrato";
    $L[] = 'Nº ' . $ni('contrato_numero') . ' · data ' . $ni('contrato_data') . ' · modalidade ' . $ni('modalidade');
    $L[] = 'Valor do veículo ' . $ni('valor_veiculo') . ' · entrada ' . $ni('entrada') . ' · financiado ' . $ni('capital') . ' · ' . $ni('prazo') . ' parcelas de ' . $ni('parcela');
    $L[] = 'Taxa ' . $ni('taxa_mensal') . ' · ' . $ni('taxa_anual') . ' · CET ' . $ni('cet');
    $L[] = "\n4. Fatos em ordem cronológica";
    $L[] = $d('cronologia') ?: '[[COMPLETAR: cronologia dos fatos]]';
    if ($d('objetivo_texto')) {
        $L[] = 'Relato do cliente: ' . $d('objetivo_texto');
    }
    $L[] = "\n5. Pagamentos e saldo";
    $L[] = 'Parcelas pagas: ' . $ni('parcelas_pagas') . ' · em atraso: ' . $ni('atraso') . ($d('atraso_qtd') ? ' (' . $d('atraso_qtd') . ')' : '') . ' · saldo cobrado ' . $ni('saldo_cobrado') . ' em ' . $ni('saldo_data');
    if ($d('renegociacao')) {
        $L[] = 'Aditivos/renegociação: ' . $d('renegociacao');
    }
    $L[] = "\n6. Encargos discutidos";
    $L[] = $d('encargos_discutidos') ?: '[[COMPLETAR: encargos discutidos (Sidney)]]';
    $L[] = "\n7. Resultado dos cálculos";
    $L[] = ($d('conclusao') ?: '(sem conclusão registrada)') . ($d('resultados') ? "\n" . $d('resultados') : '');
    $L[] = "\n8. Objetivo do cliente";
    $L[] = $ni('objetivo_prioridade');
    $L[] = "\n9. Urgência e fonte";
    $L[] = $c['urgency_signal'] ? URGENCY_SIGNALS[$c['urgency_signal']] . ': ' . $c['urgency_reason'] . ' · relato de ' . $ni('aviso_data') . ($c['urgency_opinion'] ? ' · parecer de Sidney: ' . $c['urgency_opinion'] : ' · ainda não conferida') : 'Sem urgência relatada.';
    $L[] = "\n10. Documentos disponíveis";
    foreach (all('SELECT label, status FROM documents WHERE case_id = ? ORDER BY id', [$caseId]) as $doc) {
        $L[] = '- ' . $doc['label'] . ': ' . (DOC_STATUS[$doc['status']]['label'] ?? $doc['status']);
    }
    $L[] = "\n11. Gratuidade pretendida";
    $L[] = $ni('gratuidade') . ($d('renda_atividade') ? ' · ' . $d('renda_atividade') : '');
    $L[] = "\n12. Observações jurídicas de Sidney";
    $L[] = $d('obs_juridicas') ?: '[[COMPLETAR: fundamentos, pedidos e valor da causa são definidos por Sidney]]';
    return implode("\n", $L);
}

/* ---------- Versões e estados ---------- */

function kit_latest(int $caseId, string $kind): ?array
{
    return one("SELECT * FROM case_artifact_versions WHERE case_id = ? AND kind = ? AND status <> 'substituido' ORDER BY version DESC LIMIT 1", [$caseId, $kind]);
}

function kit_applicable(int $caseId, string $kind): bool
{
    return $kind !== 'hipossuficiencia' || fv($caseId, 'gratuidade') === 'sim';
}

/** Conteúdo e hash que o item teria agora, para detectar alteração não revisada. */
function kit_fresh(int $caseId, string $kind): array
{
    if ($kind === 'ficha_inicial') {
        $content = ficha_inicial_content($caseId);
        $snap = ['ficha' => $content];
        return [$content, content_markers($content), $snap, sha1(preg_replace('/^FICHA[^\n]*\n/', '', $content)), null];
    }
    $t = doc_template($kind);
    $vars = kit_vars($caseId);
    $vars['kit.versao'] = 'v' . (((int) scalar('SELECT MAX(version) FROM case_artifact_versions WHERE case_id = ? AND kind = ?', [$caseId, $kind])) + 1);
    [$content, $missing] = render_template($t['body'], $vars);
    // A fotografia guarda só as variáveis que o modelo usa: mudar um dado que
    // não aparece no documento não o marca como desatualizado.
    preg_match_all('/\{\{\s*([a-z0-9_.]+)/u', $t['body'], $m);
    $used = array_diff(array_unique($m[1]), ['data.hoje', 'kit.versao']);
    if (str_contains($t['body'], '{{flex:')) {
        $used[] = 'cliente.genero';
    }
    preg_match_all('/^\s*@se\s+(?:nao\s+)?([a-z0-9_.]+)\s*$/mu', $t['body'], $cm);
    $used = array_merge($used, $cm[1]);
    $snap = array_intersect_key($vars, array_flip($used));
    ksort($snap);
    return [$content, $missing, $snap, sha1(json_encode($snap) . '|' . $t['version']), (int) $t['version']];
}

function kit_is_stale(int $caseId, string $kind, ?array $v = null): bool
{
    $v ??= kit_latest($caseId, $kind);
    if (!$v || $v['status'] === 'nao_aplicavel' || !$v['data_hash']) {
        return false;
    }
    return kit_fresh($caseId, $kind)[3] !== $v['data_hash'];
}

function kit_has_stale(int $caseId): bool
{
    foreach (array_keys(KIT_ITEMS) as $k) {
        if (kit_is_stale($caseId, $k)) {
            return true;
        }
    }
    return false;
}

/** Estado do kit, derivado dos itens (seção 10.2). */
function kit_status(array $c): array
{
    $id = (int) $c['id'];
    $items = [];
    $missing = [];
    foreach (KIT_ITEMS as $k => $meta) {
        $v = kit_latest($id, $k);
        $applicable = kit_applicable($id, $k);
        $state = $v ? $v['status'] : ($applicable ? 'nao_preparado' : 'nao_aplicavel');
        $stale = $v ? kit_is_stale($id, $k, $v) : false;
        $items[$k] = ['state' => $state, 'version' => $v, 'applicable' => $applicable && $state !== 'nao_aplicavel', 'stale' => $stale];
        if ($state !== 'nao_aplicavel' && !in_array($state, ['revisado', 'publicado'], true)) {
            $missing[] = $meta['label'] . ' (' . KIT_STATES[$state] . ')';
        } elseif ($stale) {
            $missing[] = $meta['label'] . ' (há alterações ainda não revisadas)';
        }
    }
    if (scalar("SELECT 1 FROM documents WHERE case_id = ? AND status = 'recebido'", [$id])) {
        $missing[] = 'checklist: há documento recebido ainda não conferido';
    }
    if (!held_meeting($id)) {
        $missing[] = 'reunião registrada';
    }
    return ['items' => $items, 'ready' => !$missing, 'missing' => $missing];
}

function kit_generate(array $c, string $kind): ?string
{
    if (!isset(KIT_ITEMS[$kind])) {
        return 'Documento desconhecido.';
    }
    $id = (int) $c['id'];
    [$content, $missing, $snap, $hash, $tv] = kit_fresh($id, $kind);
    $prev = kit_latest($id, $kind);
    $next = ((int) scalar('SELECT MAX(version) FROM case_artifact_versions WHERE case_id = ? AND kind = ?', [$id, $kind])) + 1;
    db()->beginTransaction();
    if ($prev && in_array($prev['status'], ['minuta', 'revisado', 'nao_aplicavel'], true)) {
        q("UPDATE case_artifact_versions SET status = 'substituido' WHERE id = ?", [$prev['id']]);
    }
    insert('case_artifact_versions', [
        'case_id' => $id, 'kind' => $kind, 'version' => $next, 'template_version' => $tv, 'data_hash' => $hash,
        'data_snapshot' => json_encode($snap, JSON_UNESCAPED_UNICODE), 'content' => $content, 'missing' => json_encode($missing, JSON_UNESCAPED_UNICODE),
        'status' => 'minuta', 'created_at' => now_str(), 'created_by' => current_user()['id'] ?? null,
    ]);
    if ($kind === 'honorarios' && in_array($c['contract_state'], ['nao_preparado'], true)) {
        bump_case($id, ['contract_state' => 'minuta']);
    }
    db()->commit();
    add_event($id, 'kit', KIT_ITEMS[$kind]['label'] . ': minuta v' . $next, $missing ? 'Falta: ' . implode('; ', $missing) : null);
    return null;
}

function kit_version(array $c, int $vid): ?array
{
    return one('SELECT * FROM case_artifact_versions WHERE id = ? AND case_id = ?', [$vid, $c['id']]);
}

function kit_edit(array $c, int $vid, string $content): ?string
{
    $v = kit_version($c, $vid);
    if (!$v || !in_array($v['status'], ['minuta', 'revisado'], true)) {
        return 'Só a minuta ou a versão revisada ainda não publicada pode ser editada. Gere uma nova versão.';
    }
    $content = str_replace("\r\n", "\n", mb_substr($content, 0, 60000));
    q("UPDATE case_artifact_versions SET content = ?, missing = ?, status = 'minuta', reviewed_at = NULL WHERE id = ?", [$content, json_encode(content_markers($content), JSON_UNESCAPED_UNICODE), $vid]);
    add_event((int) $c['id'], 'kit', KIT_ITEMS[$v['kind']]['label'] . ': minuta v' . $v['version'] . ' editada por Sidney');
    return null;
}

function kit_review(array $c, int $vid): ?string
{
    $v = kit_version($c, $vid);
    if (!$v || $v['status'] !== 'minuta') {
        return 'Só uma minuta pode ser marcada como revisada.';
    }
    $missing = json_decode($v['missing'], true) ?: [];
    if ($missing) {
        return 'Complete antes de revisar: ' . implode('; ', $missing) . '.';
    }
    if (kit_is_stale((int) $c['id'], $v['kind'], $v)) {
        return 'Os dados mudaram depois desta minuta. Gere uma nova versão.';
    }
    q("UPDATE case_artifact_versions SET status = 'revisado', reviewed_at = ? WHERE id = ?", [now_str(), $vid]);
    add_event((int) $c['id'], 'kit', KIT_ITEMS[$v['kind']]['label'] . ' v' . $v['version'] . ' revisado');
    return null;
}

function kit_publish(array $c, int $vid): ?string
{
    $v = kit_version($c, $vid);
    if (!$v || $v['status'] !== 'revisado') {
        return 'Publique somente a versão revisada.';
    }
    if ($v['kind'] === 'ficha_inicial') {
        return 'A ficha para a inicial é de uso interno e não é publicada ao cliente.';
    }
    $t = doc_template($v['kind']);
    if (!$t || !$t['approved_at'] || (int) $t['version'] !== (int) $v['template_version']) {
        return 'O modelo deste documento ainda não foi aprovado por Sidney (Modelos de documentos). A publicação fica bloqueada.';
    }
    if (kit_is_stale((int) $c['id'], $v['kind'], $v)) {
        return 'Há alterações ainda não revisadas. Gere e revise uma nova versão.';
    }
    q("UPDATE case_artifact_versions SET status = 'publicado', published_at = ? WHERE id = ?", [now_str(), $vid]);
    if (in_array($v['kind'], ['honorarios', 'procuracao'], true) && in_array($c['contract_state'], ['nao_preparado', 'minuta'], true)) {
        bump_case((int) $c['id'], ['contract_state' => 'enviado']);
    }
    $label = KIT_ITEMS[$v['kind']]['label'];
    add_event((int) $c['id'], 'kit', 'Disponível para você: ' . $label, 'Versão ' . $v['version'] . '.', true);
    chat_post((int) $c['id'], 'operator', 'Disponibilizei para você: ' . $label . ' (versão ' . $v['version'] . '). Veja em “Reunião e proposta”.');
    return null;
}

function kit_not_applicable(array $c, string $kind, string $reason): ?string
{
    if (!isset(KIT_ITEMS[$kind])) {
        return 'Documento desconhecido.';
    }
    if (mb_strlen(trim($reason)) < 3) {
        return 'Diga por que o documento não se aplica.';
    }
    $id = (int) $c['id'];
    $prev = kit_latest($id, $kind);
    if ($prev && $prev['status'] === 'publicado') {
        return 'Este documento já foi publicado; a versão publicada é mantida.';
    }
    if ($prev) {
        q("UPDATE case_artifact_versions SET status = 'substituido' WHERE id = ?", [$prev['id']]);
    }
    $next = ((int) scalar('SELECT MAX(version) FROM case_artifact_versions WHERE case_id = ? AND kind = ?', [$id, $kind])) + 1;
    insert('case_artifact_versions', ['case_id' => $id, 'kind' => $kind, 'version' => $next, 'content' => trim($reason), 'status' => 'nao_aplicavel', 'created_at' => now_str(), 'created_by' => current_user()['id'] ?? null]);
    add_event($id, 'kit', KIT_ITEMS[$kind]['label'] . ': não se aplica', trim($reason));
    return null;
}

/** Variáveis que um modelo usa (dados e condições), na ordem em que aparecem. */
function template_used_vars(string $body): array
{
    preg_match_all('/\{\{\s*([a-z0-9_.]+)\s*(?:\|[^}]*)?\}\}|^\s*@se\s+(?:nao\s+)?([a-z0-9_.]+)\s*$/mu', $body, $m, PREG_SET_ORDER);
    $out = [];
    foreach ($m as $x) {
        $k = ($x[2] ?? '') !== '' ? $x[2] : $x[1];
        if ($k !== '' && !str_starts_with($k, 'clausula') && !in_array($k, $out, true)) {
            $out[] = $k;
        }
    }
    return $out;
}
