<?php
declare(strict_types=1);

/**
 * Documento do kit em PDF, montado no servidor com as regras do formatador
 * (as mesmas medidas, fontes e estilos do .docx: ver diagramacao.php).
 *
 * O motor monta a diagramação uma vez — páginas com operações de desenho em
 * milímetros, a partir do canto superior esquerdo — e dois desenhistas usam a
 * mesma diagramação: o PDF (FPDF) e o SVG da tela. Assim, o que o cliente vê ao
 * posicionar o selo é o que sai no arquivo, em qualquer tamanho de tela.
 *
 * Operações (todas em mm):
 *   ['t', x, base, fonte, pt, cor, texto cp1252, largura, sublinhado]
 *   ['r', x, y, l, a, cor]                       retângulo cheio
 *   ['l', x1, y1, x2, y2, espessura, cor]        linha
 *   ['rr', x, y, l, a, raio, borda, espessura, fundo]
 *   ['img', arquivo, x, y, l, a]                 PNG de public/assets/formatador
 *   ['rt', x, base, fonte, pt, cor, texto, largura]   texto girado (de baixo para cima)
 *   ['poly', [[x, y], ...], espessura, cor]      traço da rubrica
 *   ['qr', x, y, lado, linhas]                   QR Code (linhas de "0"/"1", com a margem)
 *   ['link', x, y, l, a, url]
 */

const PDOC_W = 210.0;
const PDOC_H = 297.0;
const PDOC_TOP = 32.0;
const PDOC_BOTTOM = 269.0; // 297 - 28
const PDOC_LEFT = 30.0;
const PDOC_TEXT_W = 150.0;
const PDOC_PT = 0.3527777778; // mm por ponto
const PDOC_TINTA = '222831';
const PDOC_ACENTO = '1800AD';
const PDOC_SECUNDARIA = '6E747E';
const PDOC_TENUE = 'A7ACB4';
const PDOC_REGUA = 'D6D9DE';
const PDOC_MARCA = 'FFF176';
const PDOC_PENDENTE = 'FFF1B8';

/** Entrelinha natural de cada família (como o Word mede "simples"). */
const PDOC_NATURAL = ['lora' => [1.28, 1.006], 'poppins' => [1.40, 1.05], 'arial' => [1.15, 0.905], 'times' => [1.15, 0.891]];

/** Fonte da família no peso e estilo pedidos (arquivo em app/vendor/fpdf/font). */
function pdoc_font_key(string $fam, int $weight = 400, bool $italic = false): string
{
    if ($fam === 'poppins') {
        if ($italic && $weight < 600) {
            return 'poppinsi';
        }
        return 'poppins' . match (true) { $weight >= 700 => 'b', $weight >= 600 => 'sb', $weight >= 500 => 'm', default => '' };
    }
    $fam = in_array($fam, ['lora', 'arial', 'times'], true) ? $fam : 'lora';
    return $fam . ($weight >= 600 ? 'b' : '') . ($italic ? 'i' : '');
}

function pdoc_family(string $key): string
{
    return preg_match('/^(lora|poppins|arial|times)/', $key, $m) ? $m[1] : 'lora';
}

/** Métricas da fonte: larguras (por byte cp1252) em milésimos do corpo. */
function pdoc_font(string $key): array
{
    static $cache = [];
    if (!isset($cache[$key])) {
        $j = json_decode((string) file_get_contents(APP_DIR . '/vendor/fpdf/font/' . $key . '.json'), true);
        $cache[$key] = ['cw' => $j['cw'], 'up' => $j['up'], 'ut' => $j['ut']];
    }
    return $cache[$key];
}

/** UTF-8 → cp1252 (o que as fontes do PDF desenham). */
function pdoc_enc(string $s): string
{
    $s = strtr($s, ['→' => '->', '←' => '<-', '✓' => 'v', '✔' => 'v', '−' => '-', '≤' => '<=', '≥' => '>=', "\u{00A0}" => ' ', "\u{202F}" => ' ', "\u{2009}" => ' ', "\u{200B}" => '', "\u{2011}" => '-', '⁄' => '/']);
    return (string) mb_convert_encoding($s, 'Windows-1252', 'UTF-8');
}

function pdoc_utf8(string $enc): string
{
    return (string) mb_convert_encoding($enc, 'UTF-8', 'Windows-1252');
}

/** Largura em mm de um texto já em cp1252. */
function pdoc_width(string $enc, string $key, float $pt): float
{
    $cw = pdoc_font($key)['cw'];
    $sum = 0;
    $n = strlen($enc);
    for ($i = 0; $i < $n; $i++) {
        $sum += $cw[ord($enc[$i])] ?? 500;
    }
    return $sum * $pt / 1000 * PDOC_PT;
}

/* ---------- Texto corrido: trechos, palavras e linhas ---------- */

/** Estilo de um trecho. */
function pdoc_style(array $base, array $over = []): array
{
    return $over + $base + ['f' => 'lora', 'w' => 400, 'i' => false, 's' => 12.0, 'c' => PDOC_TINTA, 'u' => false, 'm' => null];
}

/**
 * Trechos [texto, estilo] a partir da marcação do documento (negrito,
 * versalete, pendências e a formatação do editor), com o versalete já
 * convertido em maiúsculas menores.
 */
function pdoc_runs(string $text, array $base): array
{
    $out = [];
    foreach (doc_spans($text) as [$t, $bold, $pending, $caps, $st]) {
        $s = pdoc_style($base);
        if ($bold || $caps) {
            $s['w'] = max($s['w'], 700);
        }
        if (!empty($st['i'])) {
            $s['i'] = true;
        }
        if (!empty($st['u'])) {
            $s['u'] = true;
        }
        if (isset($st['f'])) {
            $s['f'] = $st['f'];
        }
        if (isset($st['t'])) {
            $s['s'] = (float) $st['t'];
        }
        if ($pending) {
            $s['m'] = PDOC_PENDENTE;
        } elseif (!empty($st['m'])) {
            $s['m'] = PDOC_MARCA;
        }
        if ($caps) {
            $s['caps'] = true;
        }
        $out[] = [$t, $s];
    }
    return pdoc_smallcaps($out);
}

/** Versalete: minúsculas viram maiúsculas a 80% do corpo. */
function pdoc_smallcaps(array $runs): array
{
    $out = [];
    foreach ($runs as [$t, $s]) {
        if (empty($s['caps'])) {
            $out[] = [$t, $s];
            continue;
        }
        unset($s['caps']);
        foreach (preg_split('/(\p{Ll}+)/u', $t, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $piece) {
            $out[] = preg_match('/^\p{Ll}/u', $piece) ? [mb_strtoupper($piece), ['s' => $s['s'] * 0.8] + $s] : [$piece, $s];
        }
    }
    return $out;
}

/** Itens para quebrar em linhas: palavras (com pedaços de estilos diferentes), espaços e quebras. */
function pdoc_items(array $runs): array
{
    $items = [];
    $word = [];
    $flush = function () use (&$items, &$word) {
        if ($word) {
            $items[] = ['w', $word, array_sum(array_column($word, 2))];
            $word = [];
        }
    };
    foreach ($runs as [$t, $s]) {
        $key = pdoc_font_key($s['f'], $s['w'], $s['i']);
        foreach (preg_split('/(\n|[ \t]+)/', $t, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $piece) {
            if ($piece === "\n") {
                $flush();
                $items[] = ['n', $s];
            } elseif (trim($piece) === '') {
                $flush();
                $items[] = ['s', $s, pdoc_width(' ', $key, $s['s'])];
            } else {
                $enc = pdoc_enc($piece);
                $word[] = [$enc, $s, pdoc_width($enc, $key, $s['s']), $key];
            }
        }
    }
    $flush();
    return $items;
}

/** Palavra mais larga que a linha: corta por caracteres. */
function pdoc_split_word(array $word, float $avail): array
{
    $parts = [];
    $cur = [];
    $w = 0.0;
    foreach ($word[1] as [$enc, $s, , $key]) {
        $buf = '';
        for ($i = 0; $i < strlen($enc); $i++) {
            $cw = pdoc_width($enc[$i], $key, $s['s']);
            if ($w + $cw > $avail && ($buf !== '' || $cur)) {
                if ($buf !== '') {
                    $cur[] = [$buf, $s, pdoc_width($buf, $key, $s['s']), $key];
                }
                $parts[] = ['w', $cur, array_sum(array_column($cur, 2))];
                [$cur, $buf, $w] = [[], '', 0.0];
            }
            $buf .= $enc[$i];
            $w += $cw;
        }
        if ($buf !== '') {
            $cur[] = [$buf, $s, pdoc_width($buf, $key, $s['s']), $key];
        }
    }
    if ($cur) {
        $parts[] = ['w', $cur, array_sum(array_column($cur, 2))];
    }
    return $parts;
}

/**
 * Quebra em linhas. $first: largura da 1ª linha; $rest: das demais.
 * Linha: ['segs' => [[espaço antes, palavra]], 'forced' => bool, 'pt', 'fam'].
 */
function pdoc_break(array $items, float $first, float $rest, array $base): array
{
    $lines = [];
    $cur = [];
    $curW = 0.0;
    $gap = 0.0;
    $last = $base;
    $close = function (bool $forced) use (&$lines, &$cur, &$curW, &$gap, &$last) {
        $pt = 0.0;
        $fam = $last['f'];
        foreach ($cur as [, $wd]) {
            foreach ($wd[1] as [, $s]) {
                if ($s['s'] > $pt) {
                    [$pt, $fam] = [$s['s'], $s['f']];
                }
            }
        }
        $lines[] = ['segs' => $cur, 'forced' => $forced, 'pt' => $pt ?: $last['s'], 'fam' => $fam, 'w' => $curW];
        [$cur, $curW, $gap] = [[], 0.0, 0.0];
    };
    $queue = $items;
    while ($queue) {
        $it = array_shift($queue);
        $avail = $lines ? $rest : $first;
        if ($it[0] === 'n') {
            $last = $it[1];
            $close(true);
            continue;
        }
        if ($it[0] === 's') {
            $last = $it[1];
            if ($cur) {
                $gap = $it[2];
            }
            continue;
        }
        if (!$cur) {
            if ($it[2] > $avail + 0.01) {
                array_unshift($queue, ...pdoc_split_word($it, $avail));
                $it = array_shift($queue);
            }
            $cur[] = [0.0, $it];
            $curW = $it[2];
            continue;
        }
        if ($curW + $gap + $it[2] <= $avail + 0.01) {
            $cur[] = [$gap, $it];
            $curW += $gap + $it[2];
            $gap = 0.0;
            continue;
        }
        $close(false);
        array_unshift($queue, $it);
    }
    if ($cur || !$lines) {
        $close(true);
    }
    return $lines;
}

/** Altura da linha e posição da linha de base, em mm. */
function pdoc_line_metrics(array $line, float $mult): array
{
    [$nat, $asc] = PDOC_NATURAL[$line['fam']] ?? PDOC_NATURAL['lora'];
    $em = $line['pt'] * PDOC_PT;
    $h = $em * $nat * $mult;
    return [$h, ($h - $em * $nat) / 2 + $em * $asc];
}

/** Desenha uma linha: realce, texto e sublinhado. */
function pdoc_draw_line(array &$ops, array $line, float $x0, float $avail, float $base, string $align, bool $last): void
{
    $gaps = max(0, count($line['segs']) - 1);
    $extra = 0.0;
    if ($align === 'J' && !$last && !$line['forced'] && $gaps) {
        $extra = max(0.0, ($avail - $line['w']) / $gaps);
    } elseif ($align === 'C') {
        $x0 += ($avail - $line['w']) / 2;
    } elseif ($align === 'R') {
        $x0 += $avail - $line['w'];
    }
    $x = $x0;
    $text = [];
    $under = [];
    foreach ($line['segs'] as $i => [$gap, $word]) {
        if ($i) {
            // o espaço entre palavras realçadas também fica realçado
            $prev = $line['segs'][$i - 1][1][1];
            $pm = end($prev)[1]['m'];
            $nm = $word[1][0][1]['m'];
            if ($pm && $pm === $nm) {
                $em = $word[1][0][1]['s'] * PDOC_PT;
                $under[] = ['r', $x, $base - $em * 0.92, $gap + $extra, $em * 1.2, $pm];
            }
            $x += $gap + $extra;
        }
        foreach ($word[1] as [$enc, $s, $w, $key]) {
            $em = $s['s'] * PDOC_PT;
            if ($s['m']) {
                $under[] = ['r', $x, $base - $em * 0.92, $w, $em * 1.2, $s['m']];
            }
            $text[] = ['t', $x, $base, $key, $s['s'], $s['c'], $enc, $w, $s['u']];
            if ($s['u']) {
                $f = pdoc_font($key);
                $text[] = ['l', $x, $base - $f['up'] / 1000 * $em, $x + $w, $base - $f['up'] / 1000 * $em, max(0.15, $f['ut'] / 1000 * $em), $s['c']];
            }
            $x += $w;
        }
    }
    array_push($ops, ...$under, ...$text);
}

/* ---------- Blocos → parágrafos ---------- */

/** Parágrafos com as medidas do .docx (diagramacao.php, docx_block). */
function pdoc_paras(array $blocks, float $sealH = 0.0): array
{
    $compact = doc_is_compact($blocks);
    $body = $compact ? 11.0 : 12.0;
    $mult = $compact ? 1.08 : DOC_ENTRELINHA;
    $esp = $compact ? 3.2 : DOC_ESPACO;
    $base = pdoc_style(['s' => $body]);
    $sans = fn(array $o) => pdoc_style(['f' => 'poppins'] + $o + ['s' => $body]);
    $blocks = array_values(array_filter($blocks, fn($b) => $b['type'] !== 'perfil'));
    $P = fn(array $o) => $o + ['runs' => [], 'align' => 'J', 'first' => 0.0, 'left' => 0.0, 'right' => 0.0, 'hang' => 0.0, 'before' => 0.0,
        'after' => 0.0, 'mult' => $mult, 'keep' => false, 'break' => false, 'deco' => null, 'group' => null, 'sig' => null, 'base' => $base];
    $out = [];
    $group = 0;
    foreach ($blocks as $i => $b) {
        $q = !empty($b['quebra']);
        $next = $blocks[$i + 1]['type'] ?? '';
        $closing = in_array($b['type'], ['direita', 'assinatura'], true) || ($b['type'] === 'rotulo' && $next === 'assinatura');
        $prevClosing = $i > 0 && (in_array($blocks[$i - 1]['type'], ['direita', 'assinatura'], true) || ($blocks[$i - 1]['type'] === 'rotulo' && $b['type'] === 'assinatura'));
        if ($closing && (!$prevClosing || $q)) {
            $group++;
        }
        $g = $closing ? $group : null;
        switch ($b['type']) {
            case 'titulo':
                $st = $sans(['s' => 13.0, 'w' => 700]);
                $out[] = $P(['runs' => pdoc_runs('^^' . $b['text'] . '^^', $st), 'align' => 'C', 'mult' => 1.1, 'after' => $b['sub'] !== '' ? 2 : 4, 'break' => $q, 'keep' => true, 'base' => $st]);
                if ($b['sub'] !== '') {
                    $st = $sans(['s' => 9.5, 'c' => PDOC_SECUNDARIA]);
                    $out[] = $P(['runs' => pdoc_smallcaps(array_map(fn($r) => [$r[0], ['caps' => true] + $r[1]], pdoc_runs($b['sub'], $st))), 'align' => 'C', 'mult' => 1.15, 'after' => 2, 'keep' => true, 'base' => $st]);
                }
                $out[] = $P(['rule' => true, 'after' => 6, 'keep' => true]);
                break;
            case 'secao':
                $st = $sans(['s' => 11.5, 'w' => 700]);
                $runs = $b['num'] !== '' ? pdoc_smallcaps([[$b['num'] . ' ', ['c' => PDOC_ACENTO, 'caps' => true] + $st]]) : [];
                $out[] = $P(['runs' => array_merge($runs, pdoc_smallcaps(array_map(fn($r) => [$r[0], ['caps' => true] + $r[1]], pdoc_runs($b['text'], $st)))),
                    'align' => 'L', 'mult' => 1.15, 'before' => 9, 'after' => 4, 'break' => $q, 'keep' => true, 'deco' => 'bottom', 'base' => $st]);
                break;
            case 'subsecao':
                $st = $sans(['s' => 10.5, 'w' => 700]);
                $out[] = $P(['runs' => pdoc_runs($b['text'], $st), 'align' => 'L', 'mult' => 1.15, 'before' => 6, 'after' => 2.5, 'break' => $q, 'keep' => true, 'base' => $st]);
                break;
            case 'rotulo':
            case 'referencia':
                $st = $sans(['s' => $b['type'] === 'rotulo' ? 9.5 : 9.0, 'c' => PDOC_SECUNDARIA]);
                $runs = pdoc_smallcaps(array_map(fn($r) => [$r[0], ['caps' => true] + $r[1]], pdoc_runs($b['text'], $st)));
                $out[] = $b['type'] === 'rotulo'
                    ? $P(['runs' => $runs, 'align' => 'L', 'mult' => 1.15, 'before' => 5, 'after' => 2, 'break' => $q, 'keep' => true, 'group' => $g, 'base' => $st])
                    : $P(['runs' => $runs, 'align' => 'L', 'mult' => 1.25, 'after' => 8, 'break' => $q, 'base' => $st]);
                break;
            case 'qualificacao':
                $out[] = $P(['runs' => pdoc_runs($b['text'], $base), 'after' => $esp, 'break' => $q]);
                break;
            case 'destaque':
                $out[] = $P(['runs' => pdoc_runs($b['text'], $base), 'before' => 4, 'after' => 4, 'left' => 6, 'break' => $q, 'deco' => 'leftbar']);
                break;
            case 'observacao':
                $st = pdoc_style(['s' => 10.5, 'c' => PDOC_SECUNDARIA]);
                $out[] = $P(['runs' => pdoc_runs($b['text'], $st), 'mult' => 1.04, 'before' => 2.5, 'after' => 3.5, 'left' => 6, 'break' => $q, 'base' => $st]);
                break;
            case 'centro':
                $out[] = $P(['runs' => pdoc_runs($b['text'], $base), 'align' => 'C', 'after' => $esp, 'break' => $q]);
                break;
            case 'direita':
                $st = pdoc_style(['s' => 11.0]);
                $out[] = $P(['runs' => pdoc_runs($b['text'], $st), 'align' => 'R', 'before' => $compact ? 4 : 8, 'break' => $q, 'keep' => true, 'group' => $g, 'base' => $st]);
                break;
            case 'dados':
                $runs = [];
                foreach ($b['rows'] as $k => [$label, $value]) {
                    if ($k) {
                        $runs[] = ["\n", $base];
                    }
                    if ($label !== '') {
                        $runs[] = [$label . ' ', ['w' => 700] + $base];
                    }
                    array_push($runs, ...pdoc_runs($value, $base));
                }
                $out[] = $P(['runs' => $runs, 'align' => 'L', 'after' => $esp, 'break' => $q]);
                break;
            case 'lista':
                foreach ($b['items'] as $k => $item) {
                    $out[] = $P(['runs' => array_merge([['— ', $base]], pdoc_runs($item, $base)), 'after' => 2, 'left' => 12, 'hang' => 5, 'break' => $q && !$k]);
                }
                break;
            case 'assinatura':
                $st = $sans(['s' => 11.0, 'w' => 700]);
                $name = $b['lines'][0];
                $out[] = $P(['runs' => pdoc_smallcaps(array_map(fn($r) => [$r[0], ['caps' => true] + $r[1]], pdoc_runs($name, $st))), 'align' => 'C', 'mult' => 1.2,
                    'before' => ($compact ? 10 : max(16, $sealH)) + 1.4, 'left' => 36, 'right' => 36, 'break' => $q, 'keep' => true, 'deco' => 'sigline', 'group' => $g, 'sig' => $name, 'base' => $st]);
                $rest = array_slice($b['lines'], 1);
                foreach ($rest as $k => $l) {
                    $st2 = $sans(['s' => 9.0, 'c' => PDOC_SECUNDARIA]);
                    $out[] = $P(['runs' => pdoc_runs($l, $st2), 'align' => 'C', 'mult' => 1.15, 'keep' => $k < count($rest) - 1 || $next === 'assinatura', 'group' => $g, 'base' => $st2]);
                }
                break;
            default:
                $out[] = $P(['runs' => pdoc_runs($b['text'], $base), 'first' => DOC_RECUO, 'after' => $esp, 'break' => $q]);
        }
    }
    return $out;
}

/** Linhas do parágrafo com altura e linha de base. */
function pdoc_para_lines(array $p): array
{
    if (!empty($p['rule'])) {
        return [['rule' => true, 'h' => 2.0, 'base' => 0.0, 'segs' => [], 'forced' => true, 'w' => 0.0]];
    }
    $avail = PDOC_TEXT_W - $p['left'] - $p['right'];
    $first = $p['hang'] ? $avail + $p['hang'] : $avail - $p['first'];
    $lines = pdoc_break(pdoc_items($p['runs']), $first, $avail, $p['base']);
    foreach ($lines as &$l) {
        [$l['h'], $l['base']] = pdoc_line_metrics($l, $p['mult']);
    }
    return $lines;
}

/**
 * Diagramação completa: ['pages' => [[op, ...], ...], 'sigs' => [[página, x1,
 * x2, y da linha, nome], ...]]. Sem timbrado, faixa ou selo (ver pdoc_chrome).
 * $sealH: altura do selo eletrônico — o espaço acima de cada linha de
 * assinatura passa a comportar o selo.
 */
function pdoc_layout(array $blocks, float $sealH = 0.0): array
{
    $paras = pdoc_paras($blocks, $sealH);
    $lines = array_map('pdoc_para_lines', $paras);
    $pages = [[]];
    $sigs = [];
    $y = PDOC_TOP;
    $pg = 0;
    $newPage = function () use (&$pages, &$pg, &$y) {
        $pages[] = [];
        $pg = count($pages) - 1;
        $y = PDOC_TOP;
    };
    $height = fn(array $ls, int $n = PHP_INT_MAX) => array_sum(array_column(array_slice($ls, 0, $n), 'h'));
    $n = count($paras);
    for ($i = 0; $i < $n; $i++) {
        $p = $paras[$i];
        $ls = $lines[$i];
        if ($p['break'] && $y > PDOC_TOP) {
            $newPage();
        }
        // Mantém com o seguinte: título com o começo do texto; o grupo de assinaturas inteiro.
        if (($p['keep'] || $p['group']) && $y > PDOC_TOP) {
            $need = $p['before'] + $height($ls) + $p['after'];
            for ($j = $i + 1; $j < $n; $j++) {
                $q = $paras[$j];
                $sameGroup = $p['group'] && $q['group'] === $p['group'];
                $need += $q['before'] + ($sameGroup || $paras[$j - 1]['keep'] && $q['keep'] ? $height($lines[$j]) + $q['after'] : $height($lines[$j], 2));
                if (!$sameGroup && !($paras[$j - 1]['keep'] && $q['keep'])) {
                    break;
                }
            }
            if ($y + $need > PDOC_BOTTOM && PDOC_TOP + $need <= PDOC_BOTTOM) {
                $newPage();
            }
        }
        $y += $y > PDOC_TOP ? $p['before'] : ($p['deco'] === 'sigline' ? 3.0 : 0.0);
        $k = 0;
        $count = count($ls);
        while ($k < $count) {
            $fit = 0;
            $hh = 0.0;
            while ($k + $fit < $count && $y + $hh + $ls[$k + $fit]['h'] <= PDOC_BOTTOM + 0.01) {
                $hh += $ls[$k + $fit]['h'];
                $fit++;
            }
            if ($fit < $count - $k) {
                // órfã: não deixa só a primeira linha no pé; viúva: não leva só a última
                if ($fit < 2 && $count - $k >= 2 && $y > PDOC_TOP) {
                    $fit = 0;
                } elseif ($count - $k - $fit === 1 && $fit >= 3) {
                    $fit--;
                }
            }
            if ($fit === 0 && $y > PDOC_TOP) {
                $newPage();
                continue;
            }
            $fit = max(1, $fit);
            $top = $y;
            for ($m = $k; $m < $k + $fit; $m++) {
                $l = $ls[$m];
                if (!empty($l['rule'])) {
                    $pages[$pg][] = ['l', PDOC_LEFT + 64, $y + 0.5, PDOC_LEFT + 86, $y + 0.5, 0.5 * PDOC_PT, PDOC_ACENTO];
                } else {
                    $left = PDOC_LEFT + $p['left'] + ($m === 0 ? ($p['hang'] ? -$p['hang'] : $p['first']) : 0);
                    $avail = PDOC_TEXT_W - $p['left'] - $p['right'] - ($m === 0 ? ($p['hang'] ? -$p['hang'] : $p['first']) : 0);
                    pdoc_draw_line($pages[$pg], $l, $left, $avail, $y + $l['base'], $p['align'], $m === $count - 1);
                }
                $y += $l['h'];
            }
            if ($p['deco'] === 'leftbar') {
                $pages[$pg][] = ['l', PDOC_LEFT + $p['left'] - 3.3, $top, PDOC_LEFT + $p['left'] - 3.3, $y, 1.5 * PDOC_PT, PDOC_ACENTO];
            }
            if ($p['deco'] === 'sigline' && $k === 0) {
                $x1 = PDOC_LEFT + $p['left'];
                $x2 = PDOC_LEFT + PDOC_TEXT_W - $p['right'];
                $ly = $top - 1.4;
                $pages[$pg][] = ['l', $x1, $ly, $x2, $ly, 0.75 * PDOC_PT, PDOC_TINTA];
                $sigs[] = [$pg + 1, $x1, $x2, $ly, (string) $p['sig']];
            }
            if ($p['deco'] === 'bottom' && $k + $fit === $count) {
                $pages[$pg][] = ['l', PDOC_LEFT, $y + 1.06, PDOC_LEFT + PDOC_TEXT_W, $y + 1.06, 0.5 * PDOC_PT, PDOC_REGUA];
                $y += 1.06;
            }
            $k += $fit;
            if ($k < $count) {
                $newPage();
            }
        }
        $y += $p['after'];
    }
    if (!$pages[count($pages) - 1] && count($pages) > 1) {
        array_pop($pages);
    }
    return ['pages' => $pages, 'sigs' => $sigs];
}

/** Timbrado (logotipo e rodapé) e o fólio "2 / 5" a partir da 2ª folha. */
function pdoc_chrome(array $layout): array
{
    $n = count($layout['pages']);
    foreach ($layout['pages'] as $i => $ops) {
        $head = [['img', 'timbrado-cabecalho.png', DOC_LOGO['x'], DOC_LOGO['y'], DOC_LOGO['l'], DOC_LOGO['a']],
            ['img', 'timbrado-rodape.png', DOC_RODAPE['x'], DOC_RODAPE['y'], DOC_RODAPE['l'], DOC_RODAPE['a']]];
        if ($i > 0) {
            $t = pdoc_enc(($i + 1) . ' / ' . $n);
            $w = pdoc_width($t, 'poppins', 7.5);
            $head[] = ['t', PDOC_W - DOC_MG['dir'] - $w, PDOC_BOTTOM + 3 + 7.5 * PDOC_PT * 0.9, 'poppins', 7.5, PDOC_TENUE, $t, $w, false];
        }
        $layout['pages'][$i] = array_merge($head, $ops);
    }
    return $layout;
}

/* ---------- QR Code ---------- */

/** Matriz do QR Code (nível M) com a margem de 4 módulos: lista de linhas "0101…". */
function pdoc_qr(string $text): array
{
    require_once APP_DIR . '/vendor/qrcode.php';
    $q = QRCode::getMinimumQRCode($text, QR_ERROR_CORRECT_LEVEL_M);
    $n = $q->getModuleCount();
    $rows = [];
    for ($r = -4; $r < $n + 4; $r++) {
        $row = '';
        for ($c = -4; $c < $n + 4; $c++) {
            $row .= $r >= 0 && $c >= 0 && $r < $n && $c < $n && $q->isDark($r, $c) ? '1' : '0';
        }
        $rows[] = $row;
    }
    return $rows;
}

/* ---------- Desenhistas ---------- */

function pdoc_rgb(string $hex): array
{
    return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
}

/** PDF (binário) a partir da diagramação. $meta: titulo, assunto, autor, palavras. */
function pdoc_pdf(array $layout, array $meta = []): string
{
    require_once APP_DIR . '/vendor/fpdf/fpdf.php';
    $pdf = new class('P', 'mm', 'A4') extends FPDF {
        public array $loaded = [];

        public function useFont(string $key, float $pt): void
        {
            if (!isset($this->loaded[$key])) {
                $this->AddFont($key, '', $key . '.json', APP_DIR . '/vendor/fpdf/font');
                $this->loaded[$key] = true;
            }
            $this->SetFont($key, '', $pt);
        }

        public function raw(string $s): void
        {
            $this->_out($s);
        }

        public function kk(): float
        {
            return $this->k;
        }

        public function hh(): float
        {
            return $this->h;
        }
    };
    $pdf->SetAutoPageBreak(false);
    $pdf->SetMargins(0, 0, 0);
    $pdf->SetCompression(true);
    $pdf->SetTitle((string) ($meta['titulo'] ?? 'Documento'), true);
    $pdf->SetAuthor((string) ($meta['autor'] ?? 'Sidney Fatuche'), true);
    $pdf->SetSubject((string) ($meta['assunto'] ?? ''), true);
    $pdf->SetKeywords((string) ($meta['palavras'] ?? ''), true);
    $pdf->SetCreator('Sidney Fatuche Advocacia · assinatura eletrônica', true);
    $k = $pdf->kk();
    $H = $pdf->hh();
    $img = ROOT_DIR . '/public/assets/formatador/';
    foreach ($layout['pages'] as $ops) {
        $pdf->AddPage();
        foreach ($ops as $op) {
            switch ($op[0]) {
                case 't':
                    $pdf->useFont($op[3], $op[4]);
                    $pdf->SetTextColor(...pdoc_rgb($op[5]));
                    $pdf->Text($op[1], $op[2], $op[6]);
                    break;
                case 'rt':
                    $pdf->useFont($op[3], $op[4]);
                    $pdf->SetTextColor(...pdoc_rgb($op[5]));
                    $cx = $op[1] * $k;
                    $cy = ($H - $op[2]) * $k;
                    $pdf->raw(sprintf('q 0 1 -1 0 %.2F %.2F cm 1 0 0 1 %.2F %.2F cm', $cx, $cy, -$cx, -$cy));
                    $pdf->Text($op[1], $op[2], $op[6]);
                    $pdf->raw('Q');
                    break;
                case 'r':
                    $pdf->SetFillColor(...pdoc_rgb($op[5]));
                    $pdf->Rect($op[1], $op[2], $op[3], $op[4], 'F');
                    break;
                case 'l':
                    $pdf->SetDrawColor(...pdoc_rgb($op[6]));
                    $pdf->SetLineWidth($op[5]);
                    $pdf->Line($op[1], $op[2], $op[3], $op[4]);
                    break;
                case 'rr':
                    [, $x, $y, $w, $h, $r, $stroke, $lw, $fill] = $op;
                    $c = 0.5523 * $r;
                    $P = fn($px, $py) => sprintf('%.2F %.2F', $px * $k, ($H - $py) * $k);
                    $path = $P($x + $r, $y) . ' m ' . $P($x + $w - $r, $y) . ' l '
                        . $P($x + $w - $r + $c, $y) . ' ' . $P($x + $w, $y + $r - $c) . ' ' . $P($x + $w, $y + $r) . ' c '
                        . $P($x + $w, $y + $h - $r) . ' l ' . $P($x + $w, $y + $h - $r + $c) . ' ' . $P($x + $w - $r + $c, $y + $h) . ' ' . $P($x + $w - $r, $y + $h) . ' c '
                        . $P($x + $r, $y + $h) . ' l ' . $P($x + $r - $c, $y + $h) . ' ' . $P($x, $y + $h - $r + $c) . ' ' . $P($x, $y + $h - $r) . ' c '
                        . $P($x, $y + $r) . ' l ' . $P($x, $y + $r - $c) . ' ' . $P($x + $r - $c, $y) . ' ' . $P($x + $r, $y) . ' c';
                    $pdf->SetFillColor(...pdoc_rgb($fill ?: 'FFFFFF'));
                    $pdf->SetDrawColor(...pdoc_rgb($stroke));
                    $pdf->SetLineWidth($lw);
                    $pdf->raw($path . ($fill ? ' B' : ' S'));
                    break;
                case 'img':
                    $pdf->Image($img . $op[1], $op[2], $op[3], $op[4], $op[5]);
                    break;
                case 'poly':
                    if (count($op[1]) < 2) {
                        break;
                    }
                    $pdf->SetDrawColor(...pdoc_rgb($op[3]));
                    $pdf->SetLineWidth($op[2]);
                    $s = '1 J 1 j ';
                    foreach ($op[1] as $j => [$px, $py]) {
                        $s .= sprintf('%.2F %.2F %s ', $px * $k, ($H - $py) * $k, $j ? 'l' : 'm');
                    }
                    $pdf->raw($s . 'S 0 J 0 j');
                    break;
                case 'qr':
                    [, $x, $y, $side, $rows] = $op;
                    $m = $side / count($rows);
                    $pdf->SetFillColor(255, 255, 255);
                    $pdf->Rect($x, $y, $side, $side, 'F');
                    $pdf->SetFillColor(0, 0, 0);
                    foreach ($rows as $r => $row) {
                        preg_match_all('/1+/', $row, $mm, PREG_OFFSET_CAPTURE);
                        foreach ($mm[0] as [$run, $off]) {
                            $pdf->Rect($x + $off * $m, $y + $r * $m, strlen($run) * $m + 0.005, $m + 0.005, 'F');
                        }
                    }
                    break;
                case 'link':
                    $pdf->Link($op[1], $op[2], $op[3], $op[4], $op[5]);
                    break;
            }
        }
    }
    return $pdf->Output('S');
}

/** Fonte CSS de cada arquivo de fonte (a tela usa as mesmas famílias, em woff2). */
function pdoc_css_font(string $key): array
{
    $fam = ['lora' => '"Lora", Georgia, serif', 'poppins' => '"Poppins", Arial, sans-serif', 'arial' => 'Arial, "Liberation Sans", Helvetica, sans-serif',
        'times' => '"Times New Roman", "Liberation Serif", Times, serif'][pdoc_family($key)];
    $weight = match (true) {
        str_ends_with($key, 'sb') => 600,
        str_ends_with($key, 'm') => 500,
        (bool) preg_match('/bi?$/', $key) => 700,
        default => 400,
    };
    return [$fam, $weight, str_ends_with($key, 'i') ? 'italic' : 'normal'];
}

function pdoc_n(float $v): string
{
    return rtrim(rtrim(number_format($v, 3, '.', ''), '0'), '.');
}

/** SVG de uma página (viewBox em mm). As larguras dos textos são as do PDF. */
function pdoc_svg(array $ops, array $o = []): string
{
    $out = '';
    $esc = fn(string $s) => htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    foreach ($ops as $op) {
        switch ($op[0]) {
            case 't':
            case 'rt':
                [$fam, $w, $st] = pdoc_css_font($op[3]);
                $rot = $op[0] === 'rt' ? ' transform="rotate(-90 ' . pdoc_n($op[1]) . ' ' . pdoc_n($op[2]) . ')"' : '';
                $out .= '<text x="' . pdoc_n($op[1]) . '" y="' . pdoc_n($op[2]) . '"' . $rot . ' font-family=\'' . $fam . '\' font-size="' . pdoc_n($op[4] * PDOC_PT) . '"'
                    . ($w !== 400 ? ' font-weight="' . $w . '"' : '') . ($st === 'italic' ? ' font-style="italic"' : '') . ($op[5] !== PDOC_TINTA ? ' fill="#' . $op[5] . '"' : '')
                    . ' textLength="' . pdoc_n($op[7]) . '" lengthAdjust="spacingAndGlyphs">' . $esc(pdoc_utf8($op[6])) . '</text>';
                break;
            case 'r':
                $out .= '<rect x="' . pdoc_n($op[1]) . '" y="' . pdoc_n($op[2]) . '" width="' . pdoc_n($op[3]) . '" height="' . pdoc_n($op[4]) . '" fill="#' . $op[5] . '"/>';
                break;
            case 'l':
                $out .= '<line x1="' . pdoc_n($op[1]) . '" y1="' . pdoc_n($op[2]) . '" x2="' . pdoc_n($op[3]) . '" y2="' . pdoc_n($op[4]) . '" stroke="#' . $op[6] . '" stroke-width="' . pdoc_n($op[5]) . '"/>';
                break;
            case 'rr':
                $out .= '<rect x="' . pdoc_n($op[1]) . '" y="' . pdoc_n($op[2]) . '" width="' . pdoc_n($op[3]) . '" height="' . pdoc_n($op[4]) . '" rx="' . pdoc_n($op[5]) . '" fill="' . ($op[8] ? '#' . $op[8] : 'none') . '" stroke="#' . $op[6] . '" stroke-width="' . pdoc_n($op[7]) . '"/>';
                break;
            case 'img':
                $out .= '<image href="' . $esc(asset('formatador/' . $op[1])) . '" x="' . pdoc_n($op[2]) . '" y="' . pdoc_n($op[3]) . '" width="' . pdoc_n($op[4]) . '" height="' . pdoc_n($op[5]) . '" preserveAspectRatio="none"/>';
                break;
            case 'poly':
                $out .= '<polyline fill="none" stroke="#' . $op[3] . '" stroke-width="' . pdoc_n($op[2]) . '" stroke-linecap="round" stroke-linejoin="round" points="'
                    . implode(' ', array_map(fn($p) => pdoc_n($p[0]) . ',' . pdoc_n($p[1]), $op[1])) . '"/>';
                break;
            case 'qr':
                [, $x, $y, $side, $rows] = $op;
                $m = $side / count($rows);
                $d = '';
                foreach ($rows as $r => $row) {
                    preg_match_all('/1+/', $row, $mm, PREG_OFFSET_CAPTURE);
                    foreach ($mm[0] as [$run, $off]) {
                        $d .= 'M' . pdoc_n($x + $off * $m) . ' ' . pdoc_n($y + $r * $m) . 'h' . pdoc_n(strlen($run) * $m) . 'v' . pdoc_n($m) . 'h-' . pdoc_n(strlen($run) * $m) . 'z';
                    }
                }
                $out .= '<rect x="' . pdoc_n($x) . '" y="' . pdoc_n($y) . '" width="' . pdoc_n($side) . '" height="' . pdoc_n($side) . '" fill="#fff"/><path d="' . $d . '" fill="#000" shape-rendering="crispEdges"/>';
                break;
            case 'link':
                if (!empty($o['links'])) {
                    $out .= '<a href="' . $esc($op[5]) . '" target="_blank" rel="noopener"><rect x="' . pdoc_n($op[1]) . '" y="' . pdoc_n($op[2]) . '" width="' . pdoc_n($op[3]) . '" height="' . pdoc_n($op[4]) . '" fill="transparent"/></a>';
                }
                break;
        }
    }
    return $out;
}
