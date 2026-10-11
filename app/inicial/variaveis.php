<?php
declare(strict_types=1);

/**
 * Variáveis dos modelos de documentos: catálogo completo, dados fixos do
 * escritório (editados no painel de variáveis) e variáveis personalizadas
 * criadas por Sidney sem mexer no código.
 *
 * Uma variável personalizada tem um de dois alcances:
 *  - escritorio: um valor único, usado em todos os documentos;
 *  - atendimento: um valor por atendimento, preenchido na ficha (campo var_{id}),
 *    com um valor padrão opcional para quando a ficha estiver em branco.
 */

const PIX_TYPES = ['telefone' => 'telefone', 'cnpj' => 'CNPJ', 'email' => 'e-mail', 'aleatoria' => 'chave aleatória'];

/** Dados do escritório guardados no banco. A chave PIX fica aqui, não no texto do modelo. */
const OFFICE_VARS = [
    'escritorio.endereco'             => ['setting' => 'office_address', 'label' => 'Endereço do escritório', 'type' => 'text', 'max' => 300, 'ph' => 'Rua, número, bairro, cidade/UF, CEP'],
    'escritorio.telefone'             => ['setting' => 'office_phone', 'label' => 'Telefone oficial de atendimento', 'type' => 'text', 'max' => 40, 'ph' => '(11) 99999-9999'],
    'escritorio.email'                => ['setting' => 'office_email', 'label' => 'E-mail oficial do escritório', 'type' => 'email', 'max' => 160],
    'escritorio.prazo_resposta'       => ['setting' => 'office_response_days', 'label' => 'Prazo usual de resposta, em dias úteis', 'type' => 'text', 'max' => 60, 'ph' => 'ex.: 2 (dois)',
                                          'hint' => 'Escreva como deve aparecer no texto. Se o modelo já diz “dias úteis”, informe só o número.'],
    'escritorio.orientacao_urgencias' => ['setting' => 'office_urgent', 'label' => 'Canal e procedimento para situações urgentes', 'type' => 'textarea', 'max' => 1000],
    'data.cidade'                     => ['setting' => 'office_city', 'label' => 'Cidade da data dos documentos', 'type' => 'text', 'max' => 80, 'ph' => 'ex.: Curitiba',
                                          'hint' => 'Aparece em “Curitiba, 11 de outubro de 2026.”. Em branco, vale a cidade do arquivo de configuração.'],
    'escritorio.pix_tipo'             => ['setting' => 'office_pix_type', 'label' => 'Tipo da chave PIX', 'type' => 'choice', 'options' => PIX_TYPES],
    'escritorio.pix_chave'            => ['setting' => 'office_pix_key', 'label' => 'Chave PIX do escritório', 'type' => 'text', 'max' => 120,
                                          'hint' => 'Use uma chave de titularidade da sociedade: o beneficiário pode ser escrito com {{escritorio.nome}}.'],
];

/**
 * Variáveis preenchidas na ficha que têm um padrão do escritório.
 * O padrão vale enquanto a ficha do atendimento estiver em branco.
 */
const CASE_VAR_DEFAULTS = [
    'documento.foro'         => ['field' => 'foro', 'setting' => 'default_foro', 'ph' => 'ex.: Comarca de São Paulo/SP'],
    'proposta.prazo_repasse' => ['field' => 'proposta_prazo_repasse', 'setting' => 'default_prazo_repasse', 'ph' => 'ex.: 5 (cinco) dias úteis contados do recebimento'],
    'proposta.percentual_exito' => ['field' => 'proposta_percentual_exito', 'setting' => 'default_percentual_exito', 'ph' => 'ex.: 25% (vinte e cinco por cento)'],
    'procuracao.finalidade' => ['field' => 'procuracao_finalidade', 'setting' => 'default_procuracao_finalidade', 'textarea' => true, 'max' => 2000,
                                'ph' => 'Pode citar variáveis, ex.: revisão do contrato n.º {{contrato.numero}} firmado com {{contrato.banco}}'],
    'procuracao.poderes' => ['field' => 'procuracao_poderes', 'setting' => 'default_procuracao_poderes', 'textarea' => true, 'max' => 3000, 'ph' => ''],
];

/** Variáveis que podem ficar sem valor, conforme a forma de assinatura. */
const OPTIONAL_VARS = ['testemunha1.nome', 'testemunha1.cpf', 'testemunha2.nome', 'testemunha2.cpf'];

const VAR_SCOPES = ['escritorio' => 'Do escritório (um valor para todos os documentos)', 'atendimento' => 'Do atendimento (preenchida na ficha de cada caso)'];

function office_var(string $key): ?string
{
    $d = OFFICE_VARS[$key] ?? null;
    if (!$d) {
        return null;
    }
    $v = setting($d['setting']);
    if ($v === null) {
        return null;
    }
    return $d['type'] === 'choice' ? ($d['options'][$v] ?? $v) : (string) $v;
}

/**
 * Confere um valor do escritório. Retorna [valor normalizado, erro].
 * A chave PIX é conferida contra o tipo escolhido.
 */
function normalize_office_var(string $key, string $raw, ?string $pixType = null): array
{
    $d = OFFICE_VARS[$key];
    $raw = trim(str_replace("\r\n", "\n", $raw));
    if ($raw === '') {
        return [null, null];
    }
    if ($d['type'] === 'choice') {
        return isset($d['options'][$raw]) ? [$raw, null] : [null, 'Escolha uma das opções.'];
    }
    if ($d['type'] === 'email') {
        return filter_var($raw, FILTER_VALIDATE_EMAIL) ? [mb_strtolower($raw), null] : [null, 'Confira o e-mail.'];
    }
    if ($key === 'escritorio.pix_chave' && $pixType) {
        $digits = only_digits($raw);
        $err = match ($pixType) {
            'cnpj' => strlen($digits) === 14 ? null : 'a chave CNPJ tem 14 números',
            'telefone' => strlen($digits) >= 10 && strlen($digits) <= 13 ? null : 'a chave telefone precisa do DDD',
            'email' => filter_var($raw, FILTER_VALIDATE_EMAIL) ? null : 'a chave e-mail não parece um e-mail',
            'aleatoria' => preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $raw) ? null : 'a chave aleatória tem o formato 0a1b2c3d-…, com 36 caracteres',
            default => null,
        };
        if ($err) {
            return [null, 'Confira a chave PIX: ' . $err . '.'];
        }
    }
    return [mb_substr($raw, 0, $d['max'] ?? 300), null];
}

/* ---------- Variáveis personalizadas ---------- */

function custom_vars(bool $reset = false): array
{
    static $rows = null;
    if ($reset) {
        $rows = null;
        field_defs(true);
        return [];
    }
    if ($rows === null) {
        $rows = [];
        foreach (all('SELECT * FROM template_vars ORDER BY key') as $r) {
            $rows[$r['key']] = $r;
        }
    }
    return $rows;
}

/** As personalizadas por atendimento viram campos da ficha (grupo próprio). */
function custom_field_defs(): array
{
    $out = [];
    foreach (custom_vars() as $r) {
        if ($r['scope'] === 'atendimento') {
            $out['var_' . $r['id']] = ['g' => 'personalizadas', 'label' => $r['label'], 'type' => 'textarea', 'max' => 3000, 'sidney' => true, 'var' => $r['key']];
        }
    }
    return $out;
}

/** Transforma o que Sidney digitou em uma chave válida: "Escritório.Banco PIX" → escritorio.banco_pix. */
function normalize_var_key(string $raw): string
{
    $k = mb_strtolower(trim($raw));
    $k = strtr($k, ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ü' => 'u', 'ç' => 'c']);
    $k = preg_replace('/[{}\s|]+/u', ' ', $k);
    $k = trim((string) $k);
    $k = preg_replace('/\s*\.\s*/', '.', $k);
    $k = preg_replace('/[\s-]+/', '_', (string) $k);
    return (string) $k;
}

function valid_var_key(string $k): bool
{
    return (bool) preg_match('/^[a-z][a-z0-9_]{0,30}\.[a-z][a-z0-9_]{0,40}$/', $k);
}

/** Modelos (título) que usam a variável. */
function templates_using_var(string $key): array
{
    $out = [];
    foreach (all('SELECT key, title, body FROM doc_templates ORDER BY key') as $t) {
        if (preg_match('/\{\{\s*' . preg_quote($key, '/') . '\s*(\||\}\})/u', $t['body'])) {
            $out[$t['key']] = $t['title'];
        }
    }
    return $out;
}

/** Cria ou altera uma variável personalizada. Retorna mensagem de erro ou null. */
function save_custom_var(?int $id, array $in): ?string
{
    $label = trim(mb_substr((string) ($in['label'] ?? ''), 0, 120));
    $value = trim(str_replace("\r\n", "\n", mb_substr((string) ($in['value'] ?? ''), 0, 3000)));
    $help = trim(mb_substr((string) ($in['help'] ?? ''), 0, 300));
    if ($label === '') {
        return 'Dê um nome à variável (para que serve).';
    }
    if ($id) {
        $cur = one('SELECT * FROM template_vars WHERE id = ?', [$id]);
        if (!$cur) {
            return 'Variável não encontrada.';
        }
        q('UPDATE template_vars SET label = ?, value = ?, help = ?, updated_at = ? WHERE id = ?', [$label, $value !== '' ? $value : null, $help ?: null, now_str(), $id]);
        audit('variavel_alterada', null, $cur['key']);
        custom_vars(true);
        return null;
    }
    $key = normalize_var_key((string) ($in['key'] ?? ''));
    if (!valid_var_key($key)) {
        return 'Use o formato grupo.nome, só com letras sem acento, números e “_” (ex.: escritorio.banco_pix ou contrato.multa).';
    }
    $scope = (string) ($in['scope'] ?? '');
    if (!isset(VAR_SCOPES[$scope])) {
        return 'Escolha se a variável é do escritório ou do atendimento.';
    }
    if (isset(builtin_var_labels()[$key]) || isset(custom_vars()[$key])) {
        return 'A variável {{' . $key . '}} já existe.';
    }
    q('INSERT INTO template_vars (key, label, scope, value, help, created_at, updated_at) VALUES (?,?,?,?,?,?,?)',
        [$key, $label, $scope, $value !== '' ? $value : null, $help ?: null, now_str(), now_str()]);
    audit('variavel_criada', null, $key);
    custom_vars(true);
    return null;
}

/** Exclui uma personalizada que nenhum modelo usa (e os valores dela nas fichas). */
function delete_custom_var(int $id): ?string
{
    $cur = one('SELECT * FROM template_vars WHERE id = ?', [$id]);
    if (!$cur) {
        return 'Variável não encontrada.';
    }
    if ($used = templates_using_var($cur['key'])) {
        return 'A variável {{' . $cur['key'] . '}} ainda é usada em: ' . implode(', ', $used) . '. Tire-a desses modelos antes de excluir.';
    }
    q('DELETE FROM case_fields WHERE field = ?', ['var_' . $id]);
    q('DELETE FROM template_vars WHERE id = ?', [$id]);
    audit('variavel_excluida', null, $cur['key']);
    custom_vars(true);
    return null;
}

/* ---------- Catálogo ---------- */

/** Origem de cada variável embutida, para o painel. */
function builtin_var_origin(string $key): string
{
    if (isset(OFFICE_VARS[$key])) {
        return 'escritorio';
    }
    if (isset(CASE_VAR_DEFAULTS[$key])) {
        return 'ficha_padrao';
    }
    if (in_array($key, ['escritorio.nome', 'escritorio.oab', 'escritorio.cnpj', 'escritorio.advogado', 'escritorio.advogado_oab', 'data.cidade'], true)) {
        return 'configuracao';
    }
    if (in_array($key, ['atendimento.protocolo', 'data.hoje', 'kit.versao', 'analise.documentos', 'cliente.endereco', 'contrato.saldo', 'analise.bacen', 'gratuidade.renda', 'titular.nome'], true)) {
        return 'automatico';
    }
    return 'ficha';
}

const VAR_ORIGINS = [
    'ficha'        => 'Ficha do atendimento',
    'ficha_padrao' => 'Ficha do atendimento, com padrão do escritório',
    'automatico'   => 'Montada pelo sistema a partir da ficha',
    'escritorio'   => 'Dados do escritório (esta página)',
    'configuracao' => 'Arquivo de configuração do site',
    'personalizada'=> 'Personalizada',
];

/** Todas as variáveis, agrupadas pelo prefixo: [grupo => [chave => [label, origem, opcional]]]. */
function template_var_catalog(): array
{
    $out = [];
    foreach (template_var_labels() as $k => $label) {
        $custom = custom_vars()[$k] ?? null;
        $out[explode('.', $k)[0]][$k] = [
            'label' => $label,
            'origin' => $custom ? 'personalizada' : builtin_var_origin($k),
            'optional' => in_array($k, OPTIONAL_VARS, true),
            'custom' => $custom,
        ];
    }
    return $out;
}

/** Valores das personalizadas para um atendimento. */
function custom_var_values(int $caseId): array
{
    $out = [];
    foreach (custom_vars() as $k => $r) {
        if ($r['scope'] === 'escritorio') {
            $out[$k] = $r['value'];
            continue;
        }
        $row = case_fields($caseId)['var_' . $r['id']] ?? null;
        $out[$k] = $row && $row['state'] === 'informado' ? $row['value'] : $r['value'];
    }
    return $out;
}
