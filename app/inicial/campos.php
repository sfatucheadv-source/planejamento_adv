<?php
declare(strict_types=1);

/**
 * Seção 6 — ficha de dados. Cada informação é um campo estruturado, com estado
 * (informado, desconhecido, depois, não se aplica) e origem (cliente, contrato,
 * Sidney). Dinheiro é gravado em centavos inteiros; taxas em decimal com unidade.
 */

const FIELD_GROUPS = [
    'abertura'      => 'Abertura',
    'urgencia'      => 'Urgência',
    'situacao'      => 'Situação do contrato',
    'qualificacao'  => 'Qualificação',
    'endereco'      => 'Endereço',
    'relacao'       => 'Relação com o contrato',
    'financiamento' => 'Financiamento',
    'veiculo'       => 'Veículo',
    'pagamentos'    => 'Pagamentos',
    'contexto'      => 'Contexto',
    'gratuidade'    => 'Justiça gratuita',
    'reuniao'       => 'Preferência para a conversa',
    'analise'       => 'Análise (Sidney)',
    'proposta'      => 'Proposta (Sidney)',
    'juridico'      => 'Pontos jurídicos (Sidney)',
    'documento'     => 'Contrato de honorários e assinatura (Sidney)',
    'personalizadas'=> 'Variáveis personalizadas (Sidney)',
];

const YES_NO_UNSURE = ['sim' => 'Sim', 'nao' => 'Não', 'nao_sei' => 'Não sei'];

const FINANCING_STANDING = ['adimplente' => 'Adimplente', 'inadimplente' => 'Inadimplente', 'quitado' => 'Quitado'];

/**
 * Campos da ficha. As variáveis personalizadas "por atendimento" (painel de
 * variáveis) entram aqui como campos var_{id}; field_defs(true) recarrega.
 */
function field_defs(bool $reset = false): array
{
    static $merged = null;
    if ($reset) {
        $merged = null;
    }
    if ($merged === null) {
        $merged = base_field_defs() + custom_field_defs();
    }
    return $merged;
}

function base_field_defs(): array
{
    static $defs = null;
    if ($defs !== null) {
        return $defs;
    }
    $prio = array_map(fn($p) => $p['label'], PRIORITY_OPTIONS);
    $meet = array_map(fn($m) => $m['label'], MEETING_CHANNELS);
    $ufs = array_combine(UFS, UFS);
    $defs = [
        // Abertura
        'nome'               => ['g' => 'abertura', 'label' => 'Como prefere ser chamado(a)', 'type' => 'text', 'max' => 120],
        'telefone'           => ['g' => 'abertura', 'label' => 'Telefone ou WhatsApp', 'type' => 'phone'],
        'email'              => ['g' => 'abertura', 'label' => 'E-mail', 'type' => 'email'],
        'adaptacao'          => ['g' => 'abertura', 'label' => 'Adaptação para conversar', 'type' => 'text', 'max' => 300],
        'canal_contato'      => ['g' => 'abertura', 'label' => 'Canal preferido para avisos', 'type' => 'choice', 'options' => CONTACT_PREFS],
        'objetivo_prioridade'=> ['g' => 'abertura', 'label' => 'O que deseja resolver primeiro', 'type' => 'choice', 'options' => $prio],
        'objetivo_texto'     => ['g' => 'abertura', 'label' => 'Contexto contado pelo cliente', 'type' => 'textarea', 'max' => 3000],
        'resumo_confirmado'  => ['g' => 'abertura', 'label' => 'Resumo confirmado pelo cliente', 'type' => 'choice', 'options' => ['sim' => 'Sim', 'corrigiu' => 'Corrigiu']],
        'resumo_correcao'    => ['g' => 'abertura', 'label' => 'Correção do cliente ao resumo', 'type' => 'textarea', 'max' => 600],
        // Urgência (seção 5.3: relato não é prazo confirmado)
        'aviso'              => ['g' => 'urgencia', 'label' => 'Aviso, apreensão ou processo', 'type' => 'choice', 'options' => NOTICE_OPTIONS],
        'aviso_data'         => ['g' => 'urgencia', 'label' => 'Quando recebeu (relato)', 'type' => 'date'],
        'aviso_descricao'    => ['g' => 'urgencia', 'label' => 'Descrição do que recebeu', 'type' => 'textarea', 'max' => 1500],
        // Situação
        'situacao_contrato'  => ['g' => 'situacao', 'label' => 'Situação do contrato', 'type' => 'choice', 'options' => ['ativo' => 'Ativo, ainda pagando', 'quitado' => 'Quitado', 'renegociado' => 'Renegociado', 'nao_sei' => 'Não sei']],
        'atraso'             => ['g' => 'situacao', 'label' => 'Há parcelas em atraso', 'type' => 'choice', 'options' => YES_NO_UNSURE],
        'atraso_qtd'         => ['g' => 'situacao', 'label' => 'Quantas parcelas em atraso', 'type' => 'int', 'min' => 0, 'max' => 600],
        'processo_existente' => ['g' => 'situacao', 'label' => 'Já existe processo sobre o financiamento', 'type' => 'choice', 'options' => YES_NO_UNSURE],
        'outro_advogado'     => ['g' => 'situacao', 'label' => 'Outro advogado atua no caso', 'type' => 'choice', 'options' => YES_NO_UNSURE],
        // Qualificação
        'nome_completo'      => ['g' => 'qualificacao', 'label' => 'Nome completo', 'type' => 'text', 'max' => 160],
        'cpf'                => ['g' => 'qualificacao', 'label' => 'CPF', 'type' => 'cpf'],
        'nacionalidade'      => ['g' => 'qualificacao', 'label' => 'Nacionalidade', 'type' => 'text', 'max' => 60],
        'estado_civil'       => ['g' => 'qualificacao', 'label' => 'Estado civil', 'type' => 'choice', 'options' => ['solteiro' => 'Solteiro(a)', 'casado' => 'Casado(a)', 'uniao_estavel' => 'União estável', 'divorciado' => 'Divorciado(a)', 'viuvo' => 'Viúvo(a)', 'separado' => 'Separado(a)']],
        'profissao'          => ['g' => 'qualificacao', 'label' => 'Profissão', 'type' => 'text', 'max' => 80],
        'identificacao'      => ['g' => 'qualificacao', 'label' => 'Documento de identificação (tipo, número e órgão)', 'type' => 'text', 'max' => 80],
        // Endereço
        'cep'                => ['g' => 'endereco', 'label' => 'CEP', 'type' => 'cep'],
        'logradouro'         => ['g' => 'endereco', 'label' => 'Rua, avenida', 'type' => 'text', 'max' => 160],
        'numero'             => ['g' => 'endereco', 'label' => 'Número (ou “sem número”)', 'type' => 'text', 'max' => 20],
        'complemento'        => ['g' => 'endereco', 'label' => 'Complemento', 'type' => 'text', 'max' => 80],
        'bairro'             => ['g' => 'endereco', 'label' => 'Bairro', 'type' => 'text', 'max' => 80],
        'cidade'             => ['g' => 'endereco', 'label' => 'Cidade', 'type' => 'text', 'max' => 80],
        'uf'                 => ['g' => 'endereco', 'label' => 'UF', 'type' => 'choice', 'options' => $ufs],
        // Relação com o contrato
        'relacao_contrato'   => ['g' => 'relacao', 'label' => 'Relação com o contrato', 'type' => 'choice', 'options' => ['titular' => 'Sou o titular', 'representante' => 'Represento o titular', 'terceiro' => 'Sou outra pessoa interessada']],
        'titular_nome'       => ['g' => 'relacao', 'label' => 'Nome do titular do contrato', 'type' => 'text', 'max' => 160],
        'representacao'      => ['g' => 'relacao', 'label' => 'Como representa o titular', 'type' => 'text', 'max' => 300],
        // Financiamento
        'banco'              => ['g' => 'financiamento', 'label' => 'Banco ou financeira', 'type' => 'text', 'max' => 80, 'material' => true],
        'credor_cnpj'        => ['g' => 'financiamento', 'label' => 'CNPJ do credor', 'type' => 'text', 'max' => 20],
        'contrato_numero'    => ['g' => 'financiamento', 'label' => 'Número do contrato', 'type' => 'text', 'max' => 40, 'material' => true],
        'contrato_data'      => ['g' => 'financiamento', 'label' => 'Data do contrato', 'type' => 'date', 'material' => true],
        'modalidade'         => ['g' => 'financiamento', 'label' => 'Modalidade', 'type' => 'text', 'max' => 80],
        'valor_veiculo'      => ['g' => 'financiamento', 'label' => 'Valor do veículo', 'type' => 'money'],
        'entrada'            => ['g' => 'financiamento', 'label' => 'Entrada', 'type' => 'money', 'material' => true],
        'capital'            => ['g' => 'financiamento', 'label' => 'Valor financiado (capital)', 'type' => 'money', 'material' => true],
        'prazo'              => ['g' => 'financiamento', 'label' => 'Número de parcelas', 'type' => 'int', 'min' => 1, 'max' => 120, 'material' => true],
        'parcela'            => ['g' => 'financiamento', 'label' => 'Valor da parcela', 'type' => 'money', 'material' => true],
        'taxa_mensal'        => ['g' => 'financiamento', 'label' => 'Taxa de juros mensal', 'type' => 'rate', 'unit' => '% a.m.', 'material' => true],
        'taxa_anual'         => ['g' => 'financiamento', 'label' => 'Taxa de juros anual', 'type' => 'rate', 'unit' => '% a.a.', 'material' => true],
        'cet'                => ['g' => 'financiamento', 'label' => 'CET (custo efetivo total)', 'type' => 'rate', 'unit' => '% a.a.', 'material' => true],
        // Veículo (contrato ou CRLV)
        'veiculo_marca'      => ['g' => 'veiculo', 'label' => 'Marca do veículo', 'type' => 'text', 'max' => 60],
        'veiculo_modelo'     => ['g' => 'veiculo', 'label' => 'Modelo', 'type' => 'text', 'max' => 120],
        'veiculo_ano'        => ['g' => 'veiculo', 'label' => 'Ano de fabricação/modelo', 'type' => 'year', 'max' => 9],
        'veiculo_placa'      => ['g' => 'veiculo', 'label' => 'Placa', 'type' => 'plate'],
        'veiculo_renavam'    => ['g' => 'veiculo', 'label' => 'Renavam', 'type' => 'renavam'],
        // Pagamentos
        'parcelas_pagas'     => ['g' => 'pagamentos', 'label' => 'Parcelas pagas', 'type' => 'int', 'min' => 0, 'max' => 120, 'material' => true],
        'saldo_cobrado'      => ['g' => 'pagamentos', 'label' => 'Saldo cobrado pelo banco', 'type' => 'money', 'material' => true],
        'saldo_data'         => ['g' => 'pagamentos', 'label' => 'Data-base do saldo', 'type' => 'date', 'material' => true],
        'renegociacao'       => ['g' => 'pagamentos', 'label' => 'Aditivos ou renegociações', 'type' => 'textarea', 'max' => 1000, 'material' => true],
        'pagamentos_obs'     => ['g' => 'pagamentos', 'label' => 'Observações sobre pagamentos', 'type' => 'textarea', 'max' => 1500],
        // Contexto
        'cronologia'         => ['g' => 'contexto', 'label' => 'Cronologia dos fatos', 'type' => 'textarea', 'max' => 4000, 'material' => true],
        'observacoes'        => ['g' => 'contexto', 'label' => 'Observações', 'type' => 'textarea', 'max' => 3000],
        // Gratuidade (seção 5.2, B08)
        'gratuidade'         => ['g' => 'gratuidade', 'label' => 'Deseja que Sidney avalie justiça gratuita', 'type' => 'choice', 'options' => ['sim' => 'Sim', 'nao' => 'Não', 'orientacao' => 'Quero orientação']],
        'renda_atividade'    => ['g' => 'gratuidade', 'label' => 'Atividade e fonte de renda', 'type' => 'text', 'max' => 200],
        'renda_valor'        => ['g' => 'gratuidade', 'label' => 'Renda mensal aproximada', 'type' => 'money'],
        // Reunião (preferência não é agendamento)
        'reuniao_canal'      => ['g' => 'reuniao', 'label' => 'Formato preferido', 'type' => 'choice', 'options' => $meet],
        'reuniao_disponibilidade' => ['g' => 'reuniao', 'label' => 'Dias e horários possíveis', 'type' => 'text', 'max' => 300],
        // Análise (seção 8)
        'data_base'          => ['g' => 'analise', 'label' => 'Data-base da análise', 'type' => 'date', 'sidney' => true],
        'bacen_codigo'       => ['g' => 'analise', 'label' => 'Série BCB', 'type' => 'text', 'max' => 20, 'sidney' => true],
        'bacen_periodo'      => ['g' => 'analise', 'label' => 'Competência consultada', 'type' => 'text', 'max' => 20, 'sidney' => true],
        'bacen_taxa'         => ['g' => 'analise', 'label' => 'Taxa média de referência', 'type' => 'rate', 'unit' => '% a.m.', 'sidney' => true],
        'bacen_fonte'        => ['g' => 'analise', 'label' => 'Fonte e data da consulta', 'type' => 'text', 'max' => 200, 'sidney' => true],
        'metodo'             => ['g' => 'analise', 'label' => 'Método', 'type' => 'textarea', 'max' => 3000, 'sidney' => true],
        'resultados'         => ['g' => 'analise', 'label' => 'Resultados', 'type' => 'textarea', 'max' => 4000, 'sidney' => true],
        'limitacoes'         => ['g' => 'analise', 'label' => 'Limitações', 'type' => 'textarea', 'max' => 2000, 'sidney' => true],
        'conclusao'          => ['g' => 'analise', 'label' => 'Conclusão', 'type' => 'choice', 'options' => AUDIT_CONCLUSIONS + ['limitada' => 'Análise limitada (identificada)'], 'sidney' => true],
        'conclusao_texto'    => ['g' => 'analise', 'label' => 'Conclusão por extenso', 'type' => 'textarea', 'max' => 3000, 'sidney' => true],
        'memoria'            => ['g' => 'analise', 'label' => 'Memória de cálculo (resumo)', 'type' => 'textarea', 'max' => 6000, 'sidney' => true],
        'revisao_data'       => ['g' => 'analise', 'label' => 'Data de revisão', 'type' => 'date', 'sidney' => true],
        'conflito'           => ['g' => 'analise', 'label' => 'Conflito de interesses', 'type' => 'choice', 'options' => ['sem_conflito' => 'Sem conflito', 'tratado' => 'Conflito tratado', 'impedimento' => 'Impedimento'], 'sidney' => true],
        'legitimidade'       => ['g' => 'analise', 'label' => 'Titularidade e mandato', 'type' => 'choice', 'options' => ['ok' => 'Conferidos', 'pendente' => 'Pendente de conferência', 'impedimento' => 'Impedimento'], 'sidney' => true],
        'impedimento_motivo' => ['g' => 'analise', 'label' => 'Fundamento do impedimento', 'type' => 'textarea', 'max' => 1000, 'sidney' => true],
        'perguntas_reuniao'  => ['g' => 'analise', 'label' => 'Perguntas para a reunião (uma por linha)', 'type' => 'textarea', 'max' => 3000, 'sidney' => true],
        // Proposta (seção 6.1: não fixar preços nesta reforma)
        'proposta_escopo'    => ['g' => 'proposta', 'label' => 'Escopo', 'type' => 'textarea', 'max' => 3000, 'sidney' => true, 'material' => true],
        'proposta_honorarios'=> ['g' => 'proposta', 'label' => 'Honorários', 'type' => 'textarea', 'max' => 2000, 'sidney' => true, 'material' => true],
        'proposta_base_exito'=> ['g' => 'proposta', 'label' => 'Base de êxito, se houver', 'type' => 'textarea', 'max' => 1000, 'sidney' => true, 'material' => true],
        'proposta_pagamento' => ['g' => 'proposta', 'label' => 'Forma de pagamento', 'type' => 'textarea', 'max' => 1000, 'sidney' => true, 'material' => true],
        'proposta_despesas'  => ['g' => 'proposta', 'label' => 'Despesas', 'type' => 'textarea', 'max' => 1000, 'sidney' => true],
        'proposta_riscos'    => ['g' => 'proposta', 'label' => 'Riscos explicados', 'type' => 'textarea', 'max' => 2000, 'sidney' => true],
        'proposta_exclusoes' => ['g' => 'proposta', 'label' => 'O que não está incluído', 'type' => 'textarea', 'max' => 1500, 'sidney' => true],
        'proposta_validade'  => ['g' => 'proposta', 'label' => 'Validade da proposta', 'type' => 'date', 'sidney' => true],
        'proposta_prazo_repasse' => ['g' => 'proposta', 'label' => 'Prazo para repassar ao cliente o saldo recebido', 'type' => 'text', 'max' => 300, 'sidney' => true],
        'procuracao_poderes' => ['g' => 'proposta', 'label' => 'Poderes da procuração', 'type' => 'textarea', 'max' => 2000, 'sidney' => true],
        // Pontos jurídicos para a ficha da inicial (seção 10.3)
        'encargos_discutidos'=> ['g' => 'juridico', 'label' => 'Encargos discutidos', 'type' => 'textarea', 'max' => 2000, 'sidney' => true],
        'obs_juridicas'      => ['g' => 'juridico', 'label' => 'Observações jurídicas de Sidney', 'type' => 'textarea', 'max' => 4000, 'sidney' => true],
        // Contrato de honorários e assinatura
        'contrato_situacao'  => ['g' => 'documento', 'label' => 'Situação do financiamento no contrato', 'type' => 'choice', 'options' => FINANCING_STANDING, 'sidney' => true],
        'processos_notificacoes' => ['g' => 'documento', 'label' => 'Processos, notificações e eventual apreensão existentes', 'type' => 'textarea', 'max' => 2000, 'sidney' => true],
        'foro'               => ['g' => 'documento', 'label' => 'Foro do contrato (comarca e UF)', 'type' => 'text', 'max' => 120, 'sidney' => true],
        'testemunha1_nome'   => ['g' => 'documento', 'label' => 'Testemunha 1 · nome', 'type' => 'text', 'max' => 160, 'sidney' => true],
        'testemunha1_cpf'    => ['g' => 'documento', 'label' => 'Testemunha 1 · CPF', 'type' => 'cpf', 'sidney' => true],
        'testemunha2_nome'   => ['g' => 'documento', 'label' => 'Testemunha 2 · nome', 'type' => 'text', 'max' => 160, 'sidney' => true],
        'testemunha2_cpf'    => ['g' => 'documento', 'label' => 'Testemunha 2 · CPF', 'type' => 'cpf', 'sidney' => true],
    ];
    return $defs;
}

function field_def(string $key): ?array
{
    return field_defs()[$key] ?? null;
}

/** Campos que o cliente pode editar (seção 14.2: lista explícita). */
function client_editable_fields(): array
{
    return array_keys(array_filter(field_defs(), fn($d) => empty($d['sidney'])));
}

/** Todos os campos de um caso, indexados pelo nome. */
function case_fields(int $caseId, bool $fresh = false): array
{
    static $cache = [];
    if ($fresh || !isset($cache[$caseId])) {
        $cache[$caseId] = [];
        foreach (all('SELECT * FROM case_fields WHERE case_id = ?', [$caseId]) as $r) {
            $cache[$caseId][$r['field']] = $r;
        }
    }
    return $cache[$caseId];
}

function fv(int $caseId, string $field): ?string
{
    $r = case_fields($caseId)[$field] ?? null;
    return $r && $r['state'] === 'informado' ? $r['value'] : null;
}

function field_answered(int $caseId, string $field): bool
{
    return isset(case_fields($caseId)[$field]);
}

/**
 * Normaliza o valor digitado. Retorna [valor, erro].
 * Vírgula decimal e formato monetário brasileiro são aceitos na interface.
 */
function normalize_field(array $def, string $raw): array
{
    $raw = trim($raw);
    if ($raw === '') {
        return [null, 'Preencha ou escolha “não sei”.'];
    }
    switch ($def['type']) {
        case 'text':
        case 'textarea':
            return [mb_substr($raw, 0, $def['max'] ?? 300), null];
        case 'phone':
            $d = only_digits($raw);
            return strlen($d) >= 10 && strlen($d) <= 13 ? [$d, null] : [null, 'Informe o telefone com DDD.'];
        case 'email':
            return filter_var($raw, FILTER_VALIDATE_EMAIL) ? [mb_strtolower($raw), null] : [null, 'Confira o e-mail.'];
        case 'cpf':
            $d = only_digits($raw);
            return valid_cpf($d) ? [$d, null] : [null, 'Confira o CPF: são 11 números.'];
        case 'cep':
            $d = only_digits($raw);
            return strlen($d) === 8 ? [$d, null] : [null, 'O CEP tem 8 números.'];
        case 'date':
            $d = parse_br_date($raw);
            return $d ? [$d, null] : [null, 'Use a data no formato dia/mês/ano.'];
        case 'money':
            if (preg_match('/^(R\$\s*)?\d{1,3}(\.\d{3})+$/u', $raw)) {
                $raw = str_replace('.', '', $raw);
            }
            $v = parse_money($raw);
            if ($v === null) {
                return [null, 'Informe um valor, por exemplo 1.450,00.'];
            }
            return $v < 0 ? [null, 'O valor não pode ser negativo.'] : [(string) (int) round($v * 100), null];
        case 'rate':
            $v = parse_money(str_replace('%', '', $raw));
            if ($v === null || $v < 0 || $v > 1000) {
                return [null, 'Informe a taxa em percentual, por exemplo 1,89.'];
            }
            return [rtrim(rtrim(number_format($v, 6, '.', ''), '0'), '.'), null];
        case 'int':
            if (!preg_match('/^\d+$/', $raw)) {
                return [null, 'Informe um número inteiro.'];
            }
            $n = (int) $raw;
            if ($n < ($def['min'] ?? 0) || $n > ($def['max'] ?? PHP_INT_MAX)) {
                return [null, 'Número fora do esperado.'];
            }
            return [(string) $n, null];
        case 'choice':
            return isset($def['options'][$raw]) ? [$raw, null] : [null, 'Escolha uma das opções.'];
        case 'year':
            if (preg_match('#^((?:19|20)\d{2})(?:\s*[/-]\s*((?:19|20)?\d{2}))?$#', $raw, $m)) {
                $model = isset($m[2]) ? (strlen($m[2]) === 2 ? substr($m[1], 0, 2) . $m[2] : $m[2]) : null;
                return [$model && $model !== $m[1] ? $m[1] . '/' . $model : $m[1], null];
            }
            return [null, 'Informe o ano, por exemplo 2020/2021.'];
        case 'plate':
            $p = strtoupper(preg_replace('/[\s-]/', '', $raw));
            return preg_match('/^[A-Z]{3}\d[A-Z0-9]\d{2}$/', $p) ? [$p, null] : [null, 'Confira a placa: três letras e quatro caracteres (ex.: ABC1D23 ou ABC-1234).'];
        case 'renavam':
            $d = only_digits($raw);
            return valid_renavam($d) ? [str_pad($d, 11, '0', STR_PAD_LEFT), null] : [null, 'Confira o Renavam: são 11 números (os antigos têm 9).'];
    }
    return [null, 'Campo desconhecido.'];
}

/** Renavam: 11 dígitos (os de 9 recebem zeros à esquerda) com dígito verificador. */
function valid_renavam(string $d): bool
{
    if (!preg_match('/^\d{9,11}$/', $d)) {
        return false;
    }
    $d = str_pad($d, 11, '0', STR_PAD_LEFT);
    if (preg_match('/^(\d)\1{10}$/', $d)) {
        return false;
    }
    $w = [3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
    $sum = 0;
    for ($i = 0; $i < 10; $i++) {
        $sum += (int) $d[$i] * $w[$i];
    }
    $dv = ($sum * 10) % 11;
    return ($dv === 10 ? 0 : $dv) === (int) $d[10];
}

function valid_cpf(string $d): bool
{
    if (strlen($d) !== 11 || preg_match('/^(\d)\1{10}$/', $d)) {
        return false;
    }
    for ($t = 9; $t < 11; $t++) {
        $sum = 0;
        for ($i = 0; $i < $t; $i++) {
            $sum += (int) $d[$i] * (($t + 1) - $i);
        }
        $dig = ((10 * $sum) % 11) % 10;
        if ((int) $d[$t] !== $dig) {
            return false;
        }
    }
    return true;
}

function parse_br_date(string $raw): ?string
{
    $raw = trim($raw);
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m)) {
        [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
    } elseif (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $raw, $m)) {
        [$y, $mo, $d] = [(int) $m[3], (int) $m[2], (int) $m[1]];
    } else {
        return null;
    }
    return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
}

/** Valor legível para a tela e para os modelos. */
function field_display(string $key, ?array $row): string
{
    $def = field_def($key);
    if (!$row) {
        return '';
    }
    if ($row['state'] === 'desconhecido') {
        return 'Não sabe informar';
    }
    if ($row['state'] === 'depois') {
        return 'Vai informar depois';
    }
    if ($row['state'] === 'nao_se_aplica') {
        return 'Não se aplica';
    }
    $v = (string) $row['value'];
    return match ($def['type'] ?? 'text') {
        'money' => money(((int) $v) / 100),
        'rate' => number_format((float) $v, 2, ',', '.') . ' ' . ($def['unit'] ?? '%'),
        'date' => fmt_date($v),
        'cpf' => preg_replace('/^(\d{3})(\d{3})(\d{3})(\d{2})$/', '$1.$2.$3-$4', $v),
        'cep' => preg_replace('/^(\d{5})(\d{3})$/', '$1-$2', $v),
        'phone' => format_phone($v),
        'plate' => preg_match('/^[A-Z]{3}\d{4}$/', $v) ? substr($v, 0, 3) . '-' . substr($v, 3) : $v,
        'choice' => (string) ($def['options'][$v] ?? $v),
        default => $v,
    };
}

/** Valor para preencher o campo de edição. */
function field_input_value(string $key, ?array $row): string
{
    if (!$row || $row['state'] !== 'informado') {
        return '';
    }
    $def = field_def($key);
    $v = (string) $row['value'];
    return match ($def['type']) {
        'money' => number_format(((int) $v) / 100, 2, ',', '.'),
        'rate' => str_replace('.', ',', $v),
        default => $v,
    };
}

function format_phone(string $d): string
{
    if (str_starts_with($d, '55') && strlen($d) > 11) {
        $d = substr($d, 2);
    }
    return match (strlen($d)) {
        11 => sprintf('(%s) %s-%s', substr($d, 0, 2), substr($d, 2, 5), substr($d, 7)),
        10 => sprintf('(%s) %s-%s', substr($d, 0, 2), substr($d, 2, 4), substr($d, 6)),
        default => $d,
    };
}

const FIELD_SOURCES = [
    'cliente'  => 'informado pelo cliente',
    'contrato' => 'conferido no contrato',
    'sidney'   => 'corrigido por Sidney',
    'migracao' => 'trazido do cadastro anterior',
    'anterior' => 'trazido do atendimento anterior (confirmar atualidade)',
    'ia'       => 'extraído do documento pela IA (conferir)',
];

/**
 * Grava um campo. $state: informado | desconhecido | depois | nao_se_aplica.
 * Retorna mensagem de erro ou null. Mudança material depois da análise
 * aprovada acende o aviso de revisão (seção 8.3), sem alterar o kit já gerado.
 */
function set_field(int $caseId, string $key, ?string $raw, string $state = 'informado', string $source = 'cliente', ?int $docId = null): ?string
{
    $def = field_def($key);
    if (!$def) {
        return 'Campo desconhecido.';
    }
    $value = null;
    if ($state === 'informado') {
        [$value, $err] = normalize_field($def, (string) $raw);
        if ($err) {
            return $err;
        }
    } elseif (!in_array($state, ['desconhecido', 'depois', 'nao_se_aplica'], true)) {
        return 'Estado inválido.';
    }
    $old = case_fields($caseId)[$key] ?? null;
    if ($old && $old['value'] === $value && $old['state'] === $state && $old['source'] === $source) {
        return null;
    }
    $u = current_user();
    q('INSERT INTO case_fields (case_id, field, value, state, source, document_id, updated_at, updated_by) VALUES (?,?,?,?,?,?,?,?)
       ON CONFLICT(case_id, field) DO UPDATE SET value = excluded.value, state = excluded.state, source = excluded.source,
       document_id = COALESCE(excluded.document_id, document_id), updated_at = excluded.updated_at, updated_by = excluded.updated_by',
        [$caseId, $key, $value, $state, $source, $docId, now_str(), $source === 'cliente' ? null : ($u['id'] ?? null)]);
    case_fields($caseId, true);
    sync_case_columns($caseId, $key, $value, $state);

    $c = one('SELECT preparation_at, review_flag FROM cases WHERE id = ?', [$caseId]);
    if ($old && !empty($def['material']) && $c && $c['preparation_at']) {
        bump_case($caseId, ['review_flag' => 'analise']);
        add_event($caseId, 'revisao', 'Análise/kit precisa de revisão', 'Mudou “' . $def['label'] . '” depois da análise aprovada (' . (FIELD_SOURCES[$source] ?? $source) . ').');
    } elseif ($old) {
        add_event($caseId, 'campo', 'Dado alterado: ' . $def['label'], FIELD_SOURCES[$source] ?? $source);
    }
    return null;
}

/** Mantém as colunas antigas atualizadas para listas, busca e execução. */
function sync_case_columns(int $caseId, string $key, ?string $value, string $state): void
{
    $map = [
        'nome' => 'client_name', 'telefone' => 'client_phone', 'email' => 'client_email', 'cidade' => 'client_city', 'uf' => 'client_uf',
        'banco' => 'bank', 'aviso' => 'notice', 'aviso_data' => 'notice_date', 'objetivo_prioridade' => 'priority',
        'situacao_contrato' => 'contract_status', 'atraso' => 'overdue', 'atraso_qtd' => 'overdue_count', 'outro_advogado' => 'other_lawyer',
        'adaptacao' => 'accessibility', 'canal_contato' => 'contact_pref', 'objetivo_texto' => 'description',
    ];
    if ($key === 'parcela') {
        q('UPDATE cases SET installment_value = ? WHERE id = ?', [$state === 'informado' && $value !== null ? ((int) $value) / 100 : null, $caseId]);
        return;
    }
    if (!isset($map[$key]) || ($key === 'nome' && $value === null)) {
        return;
    }
    q('UPDATE cases SET ' . $map[$key] . ' = ? WHERE id = ?', [$state === 'informado' ? $value : null, $caseId]);
}

/** Endereço completo em uma linha (para os modelos). */
function full_address(int $caseId): ?string
{
    $f = fn($k) => fv($caseId, $k);
    if (!$f('logradouro') || !$f('cidade') || !$f('uf')) {
        return null;
    }
    $num = $f('numero') ?: 's/n';
    $parts = [$f('logradouro') . ', ' . $num];
    if ($f('complemento')) {
        $parts[] = $f('complemento');
    }
    if ($f('bairro')) {
        $parts[] = $f('bairro');
    }
    $parts[] = $f('cidade') . '/' . $f('uf');
    if ($f('cep')) {
        $parts[] = 'CEP ' . field_display('cep', case_fields($caseId)['cep']);
    }
    return implode(', ', $parts);
}
