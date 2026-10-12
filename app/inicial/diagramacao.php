<?php
declare(strict_types=1);

/**
 * Diagramação dos documentos do kit no padrão do escritório (formatador Fatuche):
 * timbrado, Lora no corpo, Poppins nos títulos, A4 com as margens e as
 * coordenadas do papel em produção.
 *
 * O texto vem dos modelos (com as variáveis já preenchidas) numa marcação leve.
 * Blocos são separados por linha em branco; a primeira linha diz o que o bloco é:
 *
 *   # Título | subtítulo opcional
 *   ## I. Título de seção            (o numeral vira o número em destaque)
 *   ### Cláusula primeira — do objeto
 *   #### Rótulo pequeno
 *   @referencia texto                (linha discreta em versalete)
 *   @qualificacao texto              (parágrafo sem recuo: qualificação das partes)
 *   @dados                           (linhas "Rótulo: valor"; o rótulo sai em negrito)
 *   @destaque texto                  (filete azul à esquerda)
 *   @observacao texto                (corpo menor, cinza)
 *   @centro texto · @direita texto   (alinhamentos; @direita é a linha de local e data)
 *   @assinatura Nome | linha | linha (traço para assinar, nome e linhas abaixo)
 *   @quebra                          (o bloco seguinte começa em página nova)
 *   @compacto                        (corpo 11 pt e espaços menores: procuração em uma folha)
 *   - item                           (lista com travessão)
 *
 * Dentro do texto: **negrito**. Linhas do mesmo bloco viram quebras de linha.
 * Formatação feita no editor da proposta: {|b i u m f=poppins t=14|}trecho{|/|}
 * (negrito, itálico, sublinhado, marca-texto, fonte e tamanho em pt).
 * O mesmo conjunto de blocos gera o HTML paginado e o .docx: são o mesmo documento.
 */

const DOC_LOGO = ['x' => 136.34, 'y' => 4.46, 'l' => 51.98, 'a' => 17.08];
const DOC_RODAPE = ['x' => 26.44, 'y' => 279.92, 'l' => 155.20, 'a' => 11.88];
const DOC_FOLHA = ['l' => 210.0, 'a' => 297.0];
const DOC_MG = ['topo' => 32.0, 'base' => 28.0, 'esq' => 30.0, 'dir' => 30.0];
const DOC_RECUO = 12.5;     // padrão da casa: recuo de 12,5 mm…
const DOC_ESPACO = 5.1;     // …e 5,1 mm entre parágrafos
const DOC_ENTRELINHA = 1.20; // = line-height 1.5 do CSS (ver formatador, REFERENCIA § 11)

/* ---------- Marcação → blocos ---------- */

function doc_blocks(string $content): array
{
    $content = str_replace(["\r\n", "\r"], "\n", $content);
    $out = [];
    $quebra = false;
    foreach (preg_split("/\n[ \t]*\n+/", trim($content)) as $raw) {
        $lines = array_values(array_filter(array_map('rtrim', explode("\n", $raw)), fn($l) => $l !== ''));
        if (!$lines) {
            continue;
        }
        if (trim($lines[0]) === '@compacto') {
            $out[] = ['type' => 'perfil', 'value' => 'compacto'];
            array_shift($lines);
            if (!$lines) {
                continue;
            }
        }
        if (trim($lines[0]) === '@quebra') {
            $quebra = true;
            array_shift($lines);
            if (!$lines) {
                continue;
            }
        }
        $b = doc_block($lines);
        if ($quebra) {
            $b['quebra'] = true;
            $quebra = false;
        }
        $out[] = $b;
    }
    return $out;
}

function doc_block(array $lines): array
{
    $first = ltrim($lines[0]);
    $rest = array_slice($lines, 1);
    $text = fn(string $head) => trim(implode("\n", array_merge($head !== '' ? [$head] : [], $rest)));

    if (preg_match('/^(#{1,4})\s+(.*)$/u', $first, $m)) {
        $level = strlen($m[1]);
        $t = $text(trim($m[2]));
        if ($level === 1) {
            [$title, $sub] = array_map('trim', explode('|', $t, 2)) + [1 => ''];
            return ['type' => 'titulo', 'text' => $title, 'sub' => $sub];
        }
        if ($level === 2 && preg_match('/^([IVXLC]+|\d+)(?:[.)]|\s+[—–-])?\s+(.+)$/su', $t, $n)) {
            return ['type' => 'secao', 'num' => $n[1], 'text' => trim($n[2])];
        }
        return ['type' => [2 => 'secao', 3 => 'subsecao', 4 => 'rotulo'][$level], 'num' => '', 'text' => $t];
    }
    if (preg_match('/^@(referencia|qualificacao|destaque|observacao|centro|direita|assinatura|dados)\b\s*(.*)$/u', $first, $m)) {
        $type = $m[1];
        $t = $text(trim($m[2]));
        if ($type === 'assinatura') {
            $parts = array_values(array_filter(array_map('trim', preg_split('/\s*\|\s*|\n/u', $t)), fn($x) => $x !== ''));
            return ['type' => 'assinatura', 'lines' => $parts ?: ['']];
        }
        if ($type === 'dados') {
            $rows = [];
            foreach (explode("\n", $t) as $l) {
                $l = trim($l);
                if ($l === '') {
                    continue;
                }
                $rows[] = preg_match('/^([^:]{1,80}):\s*(.*)$/u', $l, $kv) ? [$kv[1] . ':', $kv[2]] : ['', $l];
            }
            return ['type' => 'dados', 'rows' => $rows];
        }
        return ['type' => $type, 'text' => $t];
    }
    if (count(array_filter($lines, fn($l) => preg_match('/^\s*[-–—•]\s+/u', $l))) === count($lines)) {
        return ['type' => 'lista', 'items' => array_map(fn($l) => preg_replace('/^\s*[-–—•]\s+/u', '', $l), $lines)];
    }
    return ['type' => 'p', 'text' => trim(implode("\n", $lines))];
}

/** Fontes que o editor da proposta oferece: chave => [nome no Word, pilha CSS]. */
const DOC_FONTES = [
    'lora' => ['Lora', '"Lora", Georgia, serif'],
    'poppins' => ['Poppins', '"Poppins", Arial, sans-serif'],
    'arial' => ['Arial', 'Arial, Helvetica, sans-serif'],
    'times' => ['Times New Roman', '"Times New Roman", Times, serif'],
];
const DOC_TAMANHOS = [9, 10, 11, 12, 13, 14, 16, 18, 20, 24];

/** Lê "{|b i u m f=poppins t=14|}": só aceita o que o editor oferece. */
function doc_style_parse(string $spec): array
{
    $st = [];
    foreach (preg_split('/\s+/', trim($spec)) as $tok) {
        if (in_array($tok, ['b', 'i', 'u', 'm'], true)) {
            $st[$tok] = true;
        } elseif (preg_match('/^f=([a-z]+)$/', $tok, $m) && isset(DOC_FONTES[$m[1]])) {
            $st['f'] = $m[1];
        } elseif (preg_match('/^t=(\d{1,2})$/', $tok, $m) && in_array((int) $m[1], DOC_TAMANHOS, true)) {
            $st['t'] = (int) $m[1];
        }
    }
    return $st;
}

/**
 * Trechos de texto com estilo: [texto, negrito, pendente, versalete, formatação].
 * **negrito** e ^^versalete^^ (nomes das partes) ligam e desligam; [[…]] e
 * {{…}} que sobraram são pendências e saem destacados; {|…|} … {|/|} é a
 * formatação do editor (itálico, sublinhado, marca-texto, fonte, tamanho).
 */
function doc_spans(string $text): array
{
    $out = [];
    $bold = false;
    $caps = false;
    $st = [];
    foreach (preg_split('/(\*\*|\^\^|\{\|[a-z0-9=\/ ]*\|\}|\[\[[^\]]*\]\]|\{\{[^}]*\}\})/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $piece) {
        if ($piece === '**') {
            $bold = !$bold;
        } elseif ($piece === '^^') {
            $caps = !$caps;
        } elseif (str_starts_with($piece, '{|')) {
            $st = doc_style_parse(substr($piece, 2, -2));
        } elseif (str_starts_with($piece, '[[') || str_starts_with($piece, '{{')) {
            $out[] = [$piece, $bold || !empty($st['b']), true, $caps, $st];
        } else {
            $out[] = [$piece, $bold || !empty($st['b']), false, $caps, $st];
        }
    }
    return $out;
}

/** Pendências que sobraram no texto final (impedem um documento "perfeito"). */
function doc_pending(string $content): array
{
    preg_match_all('/\[\[[^\]]*\]\]|\{\{[^}]*\}\}/u', $content, $m);
    return array_values(array_unique($m[0]));
}

/* ---------- HTML no padrão do formatador ---------- */

function doc_is_compact(array $blocks): bool
{
    return (bool) array_filter($blocks, fn($b) => $b['type'] === 'perfil' && $b['value'] === 'compacto');
}

/** Perfil compacto do .docx (corpo, entrelinha e espaço entre parágrafos). */
function doc_compact(?bool $set = null): bool
{
    static $on = false;
    if ($set !== null) {
        $on = $set;
    }
    return $on;
}

function doc_espaco(): float
{
    return doc_compact() ? 3.2 : DOC_ESPACO;
}

function doc_inline_html(string $text): string
{
    $h = '';
    foreach (doc_spans($text) as [$t, $bold, $pending, $caps, $st]) {
        $t = nl2br(e($t), false);
        if ($caps) {
            $t = '<span class="parte">' . $t . '</span>';
        }
        if (!empty($st['u'])) {
            $t = '<u>' . $t . '</u>';
        }
        if (!empty($st['i'])) {
            $t = '<em>' . $t . '</em>';
        }
        if ($bold) {
            $t = '<strong>' . $t . '</strong>';
        }
        if (!empty($st['m'])) {
            $t = '<mark class="marca">' . $t . '</mark>';
        }
        if (isset($st['f']) || isset($st['t'])) {
            // tamanho relativo ao corpo de 12 pt: acompanha a prévia e a folha
            $css = (isset($st['f']) ? 'font-family:' . DOC_FONTES[$st['f']][1] . ';' : '') . (isset($st['t']) ? 'font-size:' . round($st['t'] / 12, 4) . 'em;' : '');
            $t = '<span' . (isset($st['f']) ? ' data-f="' . $st['f'] . '"' : '') . (isset($st['t']) ? ' data-t="' . $st['t'] . '"' : '') . ' style="' . e($css) . '">' . $t . '</span>';
        }
        $h .= $pending ? '<mark class="pendente">' . $t . '</mark>' : $t;
    }
    return str_replace("\n", '', $h);
}

/** Fragmento semântico (.corpo) com as classes do formatador. */
function doc_corpo_html(array $blocks): string
{
    $html = '';
    $group = '';
    $flush = function () use (&$html, &$group) {
        if ($group !== '') {
            $html .= '<div data-keep="together">' . $group . '</div>';
            $group = '';
        }
    };
    foreach ($blocks as $i => $b) {
        $q = !empty($b['quebra']) ? ' data-quebra="antes"' : '';
        if ($b['type'] === 'perfil') {
            continue;
        }
        $piece = match ($b['type']) {
            'titulo' => '<h1 class="titulo-peca"' . $q . '>' . doc_inline_html($b['text']) . ($b['sub'] !== '' ? ' <span class="-sub">' . doc_inline_html($b['sub']) . '</span>' : '') . '</h1>',
            'secao' => '<h2 class="secao"' . $q . '>' . ($b['num'] !== '' ? '<span class="-num">' . e($b['num']) . '</span> ' : '') . doc_inline_html($b['text']) . '</h2>',
            'subsecao' => '<h3 class="subsecao"' . $q . '>' . doc_inline_html($b['text']) . '</h3>',
            'rotulo' => '<h4' . $q . '>' . doc_inline_html($b['text']) . '</h4>',
            'referencia' => '<p class="referencia"' . $q . '>' . doc_inline_html($b['text']) . '</p>',
            'qualificacao' => '<p class="qualificacao"' . $q . '>' . doc_inline_html($b['text']) . '</p>',
            'destaque' => '<p class="paragrafo-chave"' . $q . '>' . doc_inline_html($b['text']) . '</p>',
            'observacao' => '<p class="fundamento"' . $q . '>' . doc_inline_html($b['text']) . '</p>',
            'centro' => '<p class="doc-centro"' . $q . '>' . doc_inline_html($b['text']) . '</p>',
            'direita' => '<p class="local-data"' . $q . '>' . doc_inline_html($b['text']) . '</p>',
            'dados' => '<p class="doc-dados"' . $q . '>' . implode('<br>', array_map(fn($r) => ($r[0] !== '' ? '<strong>' . e($r[0]) . '</strong> ' : '') . doc_inline_html($r[1]), $b['rows'])) . '</p>',
            'lista' => '<ul class="doc-lista"' . $q . '>' . implode('', array_map(fn($t) => '<li>' . doc_inline_html($t) . '</li>', $b['items'])) . '</ul>',
            'assinatura' => '<div class="doc-assinatura"' . $q . '><span class="doc-assinatura__nome">' . doc_inline_html($b['lines'][0]) . '</span>'
                . implode('', array_map(fn($l) => '<span class="doc-assinatura__linha">' . doc_inline_html($l) . '</span>', array_slice($b['lines'], 1))) . '</div>',
            default => '<p' . $q . '>' . doc_inline_html($b['text']) . '</p>',
        };
        // Local e data e as assinaturas seguintes não se separam entre páginas.
        $closing = in_array($b['type'], ['direita', 'assinatura'], true)
            || ($b['type'] === 'rotulo' && ($blocks[$i + 1]['type'] ?? '') === 'assinatura');
        if ($closing && empty($b['quebra'])) {
            $group .= $piece;
            continue;
        }
        $flush();
        $html .= $piece;
    }
    $flush();
    return '<div class="corpo">' . $html . '</div>';
}

function doc_svg(string $file): string
{
    $t = trim((string) file_get_contents(ROOT_DIR . '/public/assets/formatador/' . $file));
    if (str_starts_with($t, '<?xml')) {
        $t = trim(substr($t, strpos($t, '?>') + 2));
    }
    // sem largura e altura fixas: o CSS do formatador dimensiona o timbrado
    return preg_replace(['/\swidth="[^"]*"/', '/\sheight="[^"]*"/'], ['', ''], $t, 1);
}

/**
 * Página completa: folhas A4 paginadas pelo motor do formatador, com o
 * timbrado em todas as folhas. $actions: [rótulo => url] para a barra.
 */
function doc_page(string $title, array $blocks, array $actions, ?string $watermark = null): string
{
    $f = fn(string $p) => e(asset('formatador/' . $p));
    $fonts = '';
    foreach ([['Lora', 'lora', 'normal', [400, 700]], ['Lora', 'lora', 'italic', [400, 700]], ['Poppins', 'poppins', 'normal', [400, 500, 600, 700]]] as [$fam, $file, $style, $weights]) {
        foreach ($weights as $w) {
            foreach (['latin', 'latin-ext'] as $sub) {
                $range = $sub === 'latin' ? 'U+0000-00FF,U+0131,U+0152-0153,U+02BB-02BC,U+02C6,U+02DA,U+02DC,U+0304,U+0308,U+0329,U+2000-206F,U+20AC,U+2122,U+2191,U+2193,U+2212,U+2215,U+FEFF,U+FFFD'
                    : 'U+0100-02BA,U+02BD-02C5,U+02C7-02CC,U+02CE-02D7,U+02DD-02FF,U+0304,U+0308,U+0329,U+1D00-1DBF,U+1E00-1E9F,U+1EF2-1EFF,U+2020,U+20A0-20AB,U+20AD-20C0,U+2113,U+2C60-2C7F,U+A720-A7FF';
                $fonts .= "@font-face{font-family:'$fam';font-style:$style;font-weight:$w;font-display:block;src:url(" . asset("formatador/fonts/$file-$sub-$w-$style.woff2") . ") format('woff2');unicode-range:$range}\n";
            }
        }
    }
    $cfg = ['folio' => true, 'marcaDagua' => $watermark];
    $bar = '';
    foreach ($actions as $label => $href) {
        $bar .= $href === 'print'
            ? '<button class="-acento" type="button" data-imprimir>' . e($label) . '</button>'
            : '<a class="doc-botao" href="' . e($href) . '">' . e($label) . '</a>';
    }
    $json = fn($v) => json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
    return '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex, nofollow"><title>' . e($title) . '</title>'
        . '<style>' . $fonts . '</style><link rel="stylesheet" href="' . $f('peca.css') . '"><link rel="stylesheet" href="' . $f('documento.css') . '"></head>'
        . '<body' . (doc_is_compact($blocks) ? ' class="doc-compacto"' : '') . '><div id="fonte">' . doc_corpo_html($blocks) . '</div><div id="papel"></div>'
        . '<div class="barra">' . $bar . '</div>'
        . '<script type="application/json" id="documento-config">' . $json(['cfg' => $cfg, 'cabecalho' => doc_svg('timbrado-cabecalho.svg'), 'rodape' => doc_svg('timbrado-rodape.svg')]) . '</script>'
        . '<script src="' . $f('documento.js') . '"></script><script src="' . $f('paginar.js') . '"></script></body></html>';
}

/* ---------- .docx (mesmas medidas e tipografia do formatador) ---------- */

const DOCX_TINTA = '222831';
const DOCX_ACENTO = '1800AD';
const DOCX_SECUNDARIA = '6E747E';
const DOCX_TENUE = 'A7ACB4';
const DOCX_REGUA = 'D6D9DE';
const DOCX_SERIF = 'Lora';
const DOCX_SANS = 'Poppins';

function docx_tw(float $mm): int
{
    return (int) round($mm * 1440 / 25.4);
}

function docx_esc(string $t): string
{
    return htmlspecialchars($t, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/** Run com o estilo do formatador; "\n" vira quebra de linha. */
function docx_run(string $text, array $h = []): string
{
    if ($text === '') {
        return '';
    }
    $font = $h['font'] ?? DOCX_SERIF;
    $size = (int) round(($h['size'] ?? (doc_compact() ? 11 : 12)) * 2);
    $rPr = '<w:rPr><w:rFonts w:ascii="' . $font . '" w:hAnsi="' . $font . '" w:cs="' . $font . '" w:eastAsia="' . $font . '"/>'
        . (!empty($h['bold']) ? '<w:b/>' : '') . (!empty($h['italic']) ? '<w:i/>' : '') . (!empty($h['smallCaps']) ? '<w:smallCaps/>' : '')
        . '<w:color w:val="' . ($h['color'] ?? DOCX_TINTA) . '"/><w:sz w:val="' . $size . '"/><w:szCs w:val="' . $size . '"/>'
        . (!empty($h['highlight']) ? '<w:highlight w:val="yellow"/>' : '') . (!empty($h['underline']) ? '<w:u w:val="single"/>' : '') . '</w:rPr>';
    $out = '';
    foreach (explode("\n", $text) as $i => $part) {
        if ($i) {
            $out .= '<w:r>' . $rPr . '<w:br/></w:r>';
        }
        if ($part !== '') {
            $out .= '<w:r>' . $rPr . '<w:t xml:space="preserve">' . docx_esc($part) . '</w:t></w:r>';
        }
    }
    return $out;
}

function docx_runs(string $text, array $h = []): string
{
    $out = '';
    foreach (doc_spans($text) as [$t, $bold, $pending, $caps, $st]) {
        $fmt = ['italic' => !empty($st['i']), 'underline' => !empty($st['u'])]
            + (isset($st['f']) ? ['font' => DOC_FONTES[$st['f']][0]] : []) + (isset($st['t']) ? ['size' => $st['t']] : []);
        $out .= docx_run($t, $fmt + ['bold' => $bold || $caps || !empty($h['bold']), 'smallCaps' => $caps || !empty($h['smallCaps']), 'highlight' => $pending || !empty($st['m'])] + $h);
    }
    return $out;
}

/** Parágrafo. A ordem dos elementos do pPr é fixa no esquema OOXML. */
function docx_p(array $o): string
{
    // ordem do esquema: keepNext, pageBreakBefore, widowControl, pBdr, spacing, ind, jc
    $pPr = '<w:pPr>' . (!empty($o['keepNext']) ? '<w:keepNext/>' : '') . (!empty($o['quebra']) ? '<w:pageBreakBefore/>' : '') . '<w:widowControl/>';
    if (!empty($o['bordas'])) {
        $pPr .= '<w:pBdr>';
        foreach (['top', 'left', 'bottom', 'right'] as $lado) {
            foreach ($o['bordas'] as $b) {
                if ($b['lado'] === $lado) {
                    $pPr .= '<w:' . $lado . ' w:val="single" w:sz="' . ($b['sz'] ?? 6) . '" w:space="' . ($b['space'] ?? 6) . '" w:color="' . $b['cor'] . '"/>';
                }
            }
        }
        $pPr .= '</w:pBdr>';
    }
    $pPr .= '<w:spacing w:line="' . (int) round(240 * ($o['mult'] ?? (doc_compact() ? 1.08 : DOC_ENTRELINHA))) . '" w:lineRule="auto" w:before="' . docx_tw($o['antes'] ?? 0) . '" w:after="' . docx_tw($o['depois'] ?? 0) . '"/>';
    $esq = $o['esq'] ?? 0;
    $dir = $o['dir'] ?? 0;
    $primeira = $o['primeira'] ?? 0;
    $pend = $o['pend'] ?? 0;
    if ($esq || $dir || $primeira || $pend) {
        $pPr .= '<w:ind w:left="' . docx_tw($esq) . '"' . ($dir ? ' w:right="' . docx_tw($dir) . '"' : '')
            . ($pend ? ' w:hanging="' . docx_tw($pend) . '"' : ($primeira ? ' w:firstLine="' . docx_tw($primeira) . '"' : '')) . '/>';
    }
    $pPr .= '<w:jc w:val="' . ($o['alinha'] ?? 'both') . '"/></w:pPr>';
    return '<w:p>' . $pPr . ($o['runs'] ?? '') . '</w:p>';
}

function docx_block(array $b, bool $keepNext = false): string
{
    $q = !empty($b['quebra']);
    $sans = ['font' => DOCX_SANS];
    switch ($b['type']) {
        case 'titulo':
            $x = docx_p(['alinha' => 'center', 'mult' => 1.1, 'depois' => $b['sub'] !== '' ? 2 : 4, 'quebra' => $q, 'keepNext' => true,
                'runs' => docx_runs($b['text'], $sans + ['size' => 13, 'bold' => true, 'smallCaps' => true])]);
            if ($b['sub'] !== '') {
                $x .= docx_p(['alinha' => 'center', 'mult' => 1.15, 'depois' => 2, 'keepNext' => true,
                    'runs' => docx_runs($b['sub'], $sans + ['size' => 9.5, 'smallCaps' => true, 'color' => DOCX_SECUNDARIA])]);
            }
            // filete de 22 mm sob o título, como no HTML
            return $x . docx_p(['alinha' => 'center', 'mult' => 1.0, 'depois' => 6, 'esq' => 64, 'dir' => 64, 'keepNext' => true,
                'bordas' => [['lado' => 'top', 'cor' => DOCX_ACENTO, 'sz' => 4, 'space' => 1]]]);
        case 'secao':
            return docx_p(['alinha' => 'left', 'mult' => 1.15, 'antes' => 9, 'depois' => 4, 'quebra' => $q, 'keepNext' => true,
                'bordas' => [['lado' => 'bottom', 'cor' => DOCX_REGUA, 'sz' => 4, 'space' => 3]],
                'runs' => ($b['num'] !== '' ? docx_run($b['num'] . ' ', $sans + ['size' => 11.5, 'bold' => true, 'smallCaps' => true, 'color' => DOCX_ACENTO]) : '')
                    . docx_runs($b['text'], $sans + ['size' => 11.5, 'bold' => true, 'smallCaps' => true])]);
        case 'subsecao':
            return docx_p(['alinha' => 'left', 'mult' => 1.15, 'antes' => 6, 'depois' => 2.5, 'quebra' => $q, 'keepNext' => true,
                'runs' => docx_runs($b['text'], $sans + ['size' => 10.5, 'bold' => true])]);
        case 'rotulo':
            return docx_p(['alinha' => 'left', 'mult' => 1.15, 'antes' => 5, 'depois' => 2, 'quebra' => $q, 'keepNext' => true,
                'runs' => docx_runs($b['text'], $sans + ['size' => 9.5, 'smallCaps' => true, 'color' => DOCX_SECUNDARIA])]);
        case 'referencia':
            return docx_p(['alinha' => 'left', 'mult' => 1.25, 'depois' => 8, 'quebra' => $q,
                'runs' => docx_runs($b['text'], $sans + ['size' => 9, 'smallCaps' => true, 'color' => DOCX_SECUNDARIA])]);
        case 'qualificacao':
            return docx_p(['depois' => doc_espaco(), 'quebra' => $q, 'runs' => docx_runs($b['text'])]);
        case 'destaque':
            return docx_p(['antes' => 4, 'depois' => 4, 'esq' => 6, 'quebra' => $q,
                'bordas' => [['lado' => 'left', 'cor' => DOCX_ACENTO, 'sz' => 12, 'space' => 8]], 'runs' => docx_runs($b['text'])]);
        case 'observacao':
            return docx_p(['mult' => 1.04, 'antes' => 2.5, 'depois' => 3.5, 'esq' => 6, 'quebra' => $q,
                'runs' => docx_runs($b['text'], ['size' => 10.5, 'color' => DOCX_SECUNDARIA])]);
        case 'centro':
            return docx_p(['alinha' => 'center', 'depois' => doc_espaco(), 'quebra' => $q, 'keepNext' => $keepNext, 'runs' => docx_runs($b['text'])]);
        case 'direita':
            return docx_p(['alinha' => 'right', 'antes' => doc_compact() ? 4 : 8, 'quebra' => $q, 'keepNext' => true, 'runs' => docx_runs($b['text'], ['size' => 11])]);
        case 'dados':
            $runs = '';
            foreach ($b['rows'] as $i => [$label, $value]) {
                $runs .= ($i ? docx_run("\n") : '') . ($label !== '' ? docx_run($label . ' ', ['bold' => true]) : '') . docx_runs($value);
            }
            return docx_p(['alinha' => 'left', 'depois' => doc_espaco(), 'quebra' => $q, 'runs' => $runs]);
        case 'lista':
            $x = '';
            foreach ($b['items'] as $i => $item) {
                $x .= docx_p(['depois' => 2, 'esq' => 12, 'pend' => 5, 'quebra' => $q && !$i, 'runs' => docx_run('— ') . docx_runs($item)]);
            }
            return $x;
        case 'assinatura':
            // traço de 78 mm para assinar, centrado na mancha de 150 mm
            $x = docx_p(['alinha' => 'center', 'mult' => 1.2, 'antes' => doc_compact() ? 10 : 16, 'esq' => 36, 'dir' => 36, 'quebra' => $q, 'keepNext' => true,
                'bordas' => [['lado' => 'top', 'cor' => DOCX_TINTA, 'sz' => 6, 'space' => 4]],
                'runs' => docx_runs($b['lines'][0], $sans + ['size' => 11, 'bold' => true, 'smallCaps' => true])]);
            $rest = array_slice($b['lines'], 1);
            foreach ($rest as $i => $l) {
                $x .= docx_p(['alinha' => 'center', 'mult' => 1.15, 'keepNext' => $keepNext || $i < count($rest) - 1,
                    'runs' => docx_runs($l, $sans + ['size' => 9, 'color' => DOCX_SECUNDARIA])]);
            }
            return $x;
        default:
            return docx_p(['primeira' => DOC_RECUO, 'depois' => doc_espaco(), 'quebra' => $q, 'runs' => docx_runs($b['text'])]);
    }
}

/** Gera o .docx (binário) a partir dos blocos. */
function doc_docx(array $blocks, array $meta): string
{
    $ns = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" '
        . 'xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"';
    doc_compact(doc_is_compact($blocks));
    $blocks = array_values(array_filter($blocks, fn($b) => $b['type'] !== 'perfil'));
    $body = '';
    foreach ($blocks as $i => $b) {
        // local, data e assinaturas ficam juntos na mesma página
        $next = $blocks[$i + 1]['type'] ?? '';
        $body .= docx_block($b, in_array($b['type'], ['direita', 'assinatura', 'rotulo'], true) && $next === 'assinatura');
    }
    $sect = '<w:sectPr><w:headerReference w:type="default" r:id="rIdCab"/><w:footerReference w:type="default" r:id="rIdRod"/>'
        . '<w:pgSz w:w="' . docx_tw(DOC_FOLHA['l']) . '" w:h="' . docx_tw(DOC_FOLHA['a']) . '"/>'
        . '<w:pgMar w:top="' . docx_tw(DOC_MG['topo']) . '" w:right="' . docx_tw(DOC_MG['dir']) . '" w:bottom="' . docx_tw(DOC_MG['base']) . '" w:left="' . docx_tw(DOC_MG['esq'])
        . '" w:header="' . docx_tw(DOC_LOGO['y']) . '" w:footer="' . docx_tw(DOC_FOLHA['a'] - (DOC_RODAPE['y'] + DOC_RODAPE['a'])) . '" w:gutter="0"/>'
        . '<w:cols w:space="708"/><w:docGrid w:linePitch="360"/></w:sectPr>';
    $document = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document ' . $ns . '><w:body>' . $body . $sect . '</w:body></w:document>';

    $img = function (string $rid, string $name, array $d, int $id): string {
        $cx = (int) round($d['l'] * 36000);
        $cy = (int) round($d['a'] * 36000);
        return '<w:r><w:drawing><wp:inline distT="0" distB="0" distL="0" distR="0"><wp:extent cx="' . $cx . '" cy="' . $cy . '"/><wp:docPr id="' . $id . '" name="' . $name . '"/>'
            . '<a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:pic xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">'
            . '<pic:nvPicPr><pic:cNvPr id="' . $id . '" name="' . $name . '"/><pic:cNvPicPr/></pic:nvPicPr><pic:blipFill><a:blip r:embed="' . $rid . '"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill>'
            . '<pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $cx . '" cy="' . $cy . '"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr></pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing></w:r>';
    };
    // Imagem inline com recuo negativo: o logotipo cai na coordenada do papel
    // (imagem flutuante é reposicionada pelo Google Docs na conversão).
    $header = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:hdr ' . $ns . '><w:p><w:pPr><w:spacing w:line="240" w:lineRule="auto" w:after="0"/>'
        . '<w:ind w:right="' . docx_tw((DOC_FOLHA['l'] - DOC_MG['dir']) - (DOC_LOGO['x'] + DOC_LOGO['l'])) . '"/><w:jc w:val="right"/></w:pPr>'
        . $img('rIdImgCab', 'timbrado-cabecalho', DOC_LOGO, 101) . '</w:p></w:hdr>';
    $folio = ['font' => DOCX_SANS, 'size' => 7.5, 'color' => DOCX_TENUE];
    $field = function (string $name) use ($folio): string {
        $rPr = '<w:rPr><w:rFonts w:ascii="' . DOCX_SANS . '" w:hAnsi="' . DOCX_SANS . '"/><w:color w:val="' . DOCX_TENUE . '"/><w:sz w:val="15"/></w:rPr>';
        return '<w:r>' . $rPr . '<w:fldChar w:fldCharType="begin"/></w:r><w:r>' . $rPr . '<w:instrText xml:space="preserve"> ' . $name . ' </w:instrText></w:r><w:r>' . $rPr . '<w:fldChar w:fldCharType="end"/></w:r>';
    };
    $footer = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:ftr ' . $ns . '><w:p><w:pPr><w:spacing w:line="240" w:lineRule="auto" w:after="0"/>'
        . '<w:ind w:left="' . docx_tw(DOC_RODAPE['x'] - DOC_MG['esq']) . '"/><w:jc w:val="left"/></w:pPr>' . $img('rIdImgRod', 'timbrado-rodape', DOC_RODAPE, 102) . '</w:p>'
        . '<w:p><w:pPr><w:spacing w:line="240" w:lineRule="auto" w:after="0"/><w:jc w:val="right"/></w:pPr>' . $field('PAGE') . docx_run(' / ', $folio) . $field('NUMPAGES') . '</w:p></w:ftr>';
    $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:styles ' . $ns . '><w:docDefaults><w:rPrDefault><w:rPr>'
        . '<w:rFonts w:ascii="' . DOCX_SERIF . '" w:hAnsi="' . DOCX_SERIF . '" w:cs="' . DOCX_SERIF . '" w:eastAsia="' . DOCX_SERIF . '"/><w:color w:val="' . DOCX_TINTA . '"/>'
        . '<w:sz w:val="24"/><w:szCs w:val="24"/><w:lang w:val="pt-BR" w:eastAsia="pt-BR" w:bidi="ar-SA"/></w:rPr></w:rPrDefault>'
        . '<w:pPrDefault><w:pPr><w:spacing w:after="0" w:line="288" w:lineRule="auto"/><w:jc w:val="both"/></w:pPr></w:pPrDefault></w:docDefaults>'
        . '<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/><w:pPr><w:jc w:val="both"/></w:pPr></w:style></w:styles>';
    $settings = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:settings ' . $ns . '><w:zoom w:percent="100"/><w:autoHyphenation w:val="false"/>'
        . '<w:doNotHyphenateCaps w:val="true"/><w:compat><w:compatSetting w:name="compatibilityMode" w:uri="http://schemas.microsoft.com/office/word" w:val="15"/></w:compat>'
        . '<w:themeFontLang w:val="pt-BR"/></w:settings>';
    $rel = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/';
    $ct = 'application/vnd.openxmlformats-officedocument.wordprocessingml.';
    $files = [
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
            . '<Default Extension="png" ContentType="image/png"/><Override PartName="/word/document.xml" ContentType="' . $ct . 'document.main+xml"/>'
            . '<Override PartName="/word/styles.xml" ContentType="' . $ct . 'styles+xml"/><Override PartName="/word/settings.xml" ContentType="' . $ct . 'settings+xml"/>'
            . '<Override PartName="/word/header1.xml" ContentType="' . $ct . 'header+xml"/><Override PartName="/word/footer1.xml" ContentType="' . $ct . 'footer+xml"/>'
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/></Types>',
        '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="' . $rel . 'officeDocument" Target="word/document.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/></Relationships>',
        'docProps/core.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" '
            . 'xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:title>' . docx_esc($meta['titulo'] ?? 'Documento') . '</dc:title><dc:creator>' . docx_esc($meta['autor'] ?? 'Sidney Fatuche') . '</dc:creator>'
            . '<dc:subject>' . docx_esc($meta['assunto'] ?? '') . '</dc:subject><cp:lastModifiedBy>' . docx_esc($meta['autor'] ?? 'Sidney Fatuche') . '</cp:lastModifiedBy></cp:coreProperties>',
        'word/document.xml' => $document,
        'word/_rels/document.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rIdSty" Type="' . $rel . 'styles" Target="styles.xml"/><Relationship Id="rIdSet" Type="' . $rel . 'settings" Target="settings.xml"/>'
            . '<Relationship Id="rIdCab" Type="' . $rel . 'header" Target="header1.xml"/><Relationship Id="rIdRod" Type="' . $rel . 'footer" Target="footer1.xml"/></Relationships>',
        'word/styles.xml' => $styles,
        'word/settings.xml' => $settings,
        'word/header1.xml' => $header,
        'word/footer1.xml' => $footer,
        'word/_rels/header1.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rIdImgCab" Type="' . $rel . 'image" Target="media/timbrado-cabecalho.png"/></Relationships>',
        'word/_rels/footer1.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rIdImgRod" Type="' . $rel . 'image" Target="media/timbrado-rodape.png"/></Relationships>',
        'word/media/timbrado-cabecalho.png' => (string) file_get_contents(ROOT_DIR . '/public/assets/formatador/timbrado-cabecalho.png'),
        'word/media/timbrado-rodape.png' => (string) file_get_contents(ROOT_DIR . '/public/assets/formatador/timbrado-rodape.png'),
    ];
    $bin = zip_build($files);
    doc_compact(false);
    return $bin;
}

/** ZIP mínimo (deflate quando disponível), sem depender da extensão zip do PHP. */
function zip_build(array $files): string
{
    $data = '';
    $central = '';
    $offset = 0;
    [$time, $date] = [0, (2026 - 1980) << 9 | 1 << 5 | 1];
    foreach ($files as $name => $raw) {
        $crc = crc32($raw);
        $deflated = function_exists('gzdeflate') ? gzdeflate($raw, 6) : false;
        [$method, $stored] = $deflated !== false && strlen($deflated) < strlen($raw) ? [8, $deflated] : [0, $raw];
        $head = pack('vvvvvVVVvv', 20, 0x0800, $method, $time, $date, $crc, strlen($stored), strlen($raw), strlen($name), 0);
        $data .= "PK\x03\x04" . $head . $name . $stored;
        $central .= "PK\x01\x02" . pack('vvvvvvVVVvvvvvVV', 20, 20, 0x0800, $method, $time, $date, $crc, strlen($stored), strlen($raw), strlen($name), 0, 0, 0, 0, 0, $offset) . $name;
        $offset += 30 + strlen($name) + strlen($stored);
    }
    return $data . $central . "PK\x05\x06" . pack('vvvvVVv', 0, 0, count($files), count($files), strlen($central), $offset, 0);
}

/** Nome de arquivo seguro: "contrato-de-honorarios-AT-2026-0001-v3". */
function doc_filename(string $label, string $protocol, int $version): string
{
    $slug = strtolower((string) (iconv('UTF-8', 'ASCII//TRANSLIT', $label) ?: 'documento'));
    return trim((string) preg_replace('/[^a-z0-9]+/', '-', $slug), '-') . '-' . preg_replace('/[^A-Za-z0-9-]/', '', $protocol) . '-v' . $version;
}
