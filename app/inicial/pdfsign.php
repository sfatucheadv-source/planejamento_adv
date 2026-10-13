<?php
declare(strict_types=1);

/**
 * PDF: leitura da estrutura, atualização incremental e assinatura digital.
 *
 * Toda mudança depois da geração é um acréscimo no fim do arquivo
 * (atualização incremental): os bytes anteriores ficam intactos, e uma
 * assinatura digital feita antes continua válida para a parte que assinou.
 *  - pdfx_sign(): assinatura com certificado A1 (CMS destacado, adbe.pkcs7.detached)
 *  - pdfx_add_annots(): selo e faixa do cliente como anotações (carimbo e link)
 *  - pdfx_signatures(): lista e confere as assinaturas digitais de um PDF
 */

const PDFX_SIG_BYTES = 16384; // espaço reservado para o CMS (bytes; o dobro em hexadecimal)

/* ---------- Leitura ---------- */

/** Mapa de objetos (número => [offset] ou [objstm, índice]) e o trailer mais recente. */
function pdfx_parse(string $pdf): array
{
    if (!preg_match_all('/startxref\s+(\d+)\s+%%EOF/', $pdf, $m)) {
        throw new RuntimeException('PDF sem startxref.');
    }
    $start = (int) end($m[1]);
    $map = [];
    $trailer = null;
    $seen = [];
    $pos = $start;
    while ($pos !== null && !isset($seen[$pos])) {
        $seen[$pos] = true;
        if (substr($pdf, $pos, 4) === 'xref') {
            $tpos = strpos($pdf, 'trailer', $pos);
            $table = substr($pdf, $pos + 4, $tpos - $pos - 4);
            preg_match_all('/(\d+)\s+(\d+)\s*\r?\n((?:\d{10} \d{5} [nf][ \r\n]{1,2})*)/', $table, $secs, PREG_SET_ORDER);
            foreach ($secs as $sec) {
                $first = (int) $sec[1];
                preg_match_all('/(\d{10}) (\d{5}) ([nf])/', $sec[3], $ents, PREG_SET_ORDER);
                foreach ($ents as $i => $e) {
                    if ($e[3] === 'n' && !isset($map[$first + $i])) {
                        $map[$first + $i] = [(int) $e[1]];
                    }
                }
            }
            $dict = pdfx_dict_at($pdf, strpos($pdf, '<<', $tpos));
        } else {
            // seção em fluxo (xref stream, PDF 1.5+)
            [$dict, $data] = pdfx_stream_at($pdf, $pos);
            preg_match('#/W\s*\[\s*(\d+)\s+(\d+)\s+(\d+)\s*\]#', $dict, $w);
            $size = (int) pdfx_get($dict, 'Size');
            $index = preg_match('#/Index\s*\[([^\]]*)\]#', $dict, $ix) ? array_map('intval', preg_split('/\s+/', trim($ix[1]))) : [0, $size];
            [$w1, $w2, $w3] = [(int) $w[1], (int) $w[2], (int) $w[3]];
            $row = $w1 + $w2 + $w3;
            $off = 0;
            for ($k = 0; $k < count($index); $k += 2) {
                for ($j = 0; $j < $index[$k + 1]; $j++) {
                    $rec = substr($data, $off, $row);
                    $off += $row;
                    $f = fn($s) => $s === '' ? 0 : (int) hexdec(bin2hex($s));
                    $type = $w1 ? $f(substr($rec, 0, $w1)) : 1;
                    $a = $f(substr($rec, $w1, $w2));
                    $b = $f(substr($rec, $w1 + $w2, $w3));
                    $num = $index[$k] + $j;
                    if (!isset($map[$num]) && $type === 1) {
                        $map[$num] = [$a];
                    } elseif (!isset($map[$num]) && $type === 2) {
                        $map[$num] = [null, $a, $b];
                    }
                }
            }
        }
        $trailer ??= $dict;
        $prev = pdfx_get($dict, 'Prev');
        $pos = $prev !== null ? (int) $prev : null;
    }
    return ['map' => $map, 'trailer' => $trailer, 'startxref' => $start, 'size' => (int) pdfx_get($trailer, 'Size')];
}

/** Dicionário << … >> que começa em $pos (com aninhamento). */
function pdfx_dict_at(string $pdf, int $pos): string
{
    $depth = 0;
    $n = strlen($pdf);
    for ($i = $pos; $i < $n; $i++) {
        $two = substr($pdf, $i, 2);
        if ($two === '<<') {
            $depth++;
            $i++;
        } elseif ($two === '>>') {
            $depth--;
            $i++;
            if ($depth === 0) {
                return substr($pdf, $pos, $i - $pos + 1);
            }
        } elseif ($pdf[$i] === '(') {
            // texto literal: pula até o parêntese que fecha (com escapes)
            $lvl = 0;
            for (; $i < $n; $i++) {
                if ($pdf[$i] === '\\') {
                    $i++;
                } elseif ($pdf[$i] === '(') {
                    $lvl++;
                } elseif ($pdf[$i] === ')' && --$lvl === 0) {
                    break;
                }
            }
        }
    }
    throw new RuntimeException('Dicionário sem fim.');
}

/** Valor simples de uma chave do dicionário (número, nome ou referência). */
function pdfx_get(?string $dict, string $key): ?string
{
    if ($dict !== null && preg_match('#/' . preg_quote($key, '#') . '(?=[\s/<\[(])\s*(\d+\s+\d+\s+R|\d+|/[^\s/<>\[\]()]+|true|false)#', $dict, $m)) {
        return trim($m[1]);
    }
    return null;
}

function pdfx_ref(?string $v): ?int
{
    return $v !== null && preg_match('/^(\d+)\s+\d+\s+R$/', $v, $m) ? (int) $m[1] : null;
}

/** Objeto indireto em $pos: [dicionário, dados do fluxo decodificados]. */
function pdfx_stream_at(string $pdf, int $pos): array
{
    $d0 = strpos($pdf, '<<', $pos);
    $dict = pdfx_dict_at($pdf, $d0);
    $s = strpos($pdf, 'stream', $d0 + strlen($dict));
    $s += 6;
    if ($pdf[$s] === "\r") {
        $s++;
    }
    if ($pdf[$s] === "\n") {
        $s++;
    }
    $len = pdfx_get($dict, 'Length');
    $raw = substr($pdf, $s, (int) $len);
    $data = str_contains($dict, '/FlateDecode') ? (string) @gzuncompress($raw) : $raw;
    if (preg_match('#/Predictor\s+(\d+)#', $dict, $pr) && (int) $pr[1] >= 10) {
        $cols = (int) (pdfx_get($dict, 'Columns') ?? 1);
        $out = '';
        $prev = str_repeat("\0", $cols);
        for ($i = 0; $i + $cols < strlen($data) + 1; $i += $cols + 1) {
            $ft = ord($data[$i]);
            $line = substr($data, $i + 1, $cols);
            $cur = '';
            for ($j = 0; $j < $cols; $j++) {
                $x = ord($line[$j] ?? "\0");
                $up = ord($prev[$j]);
                $left = $j ? ord($cur[$j - 1]) : 0;
                $cur .= chr(match ($ft) { 2 => ($x + $up) & 255, 1 => ($x + $left) & 255, default => $x });
            }
            $out .= $cur;
            $prev = $cur;
        }
        $data = $out;
    }
    return [$dict, $data];
}

/** Corpo do objeto (o que fica entre "N 0 obj" e "endobj"). */
function pdfx_object(string $pdf, array $info, int $num): string
{
    $e = $info['map'][$num] ?? null;
    if (!$e) {
        throw new RuntimeException('Objeto ' . $num . ' não encontrado.');
    }
    if ($e[0] !== null) {
        $start = strpos($pdf, 'obj', $e[0]) + 3;
        $end = strpos($pdf, 'endobj', $start);
        return trim(substr($pdf, $start, $end - $start));
    }
    // dentro de um fluxo de objetos
    [$dict, $data] = pdfx_stream_at($pdf, $info['map'][$e[1]][0]);
    $first = (int) pdfx_get($dict, 'First');
    $nums = preg_split('/\s+/', trim(substr($data, 0, $first)));
    for ($i = 0; $i < count($nums); $i += 2) {
        if ((int) $nums[$i] === $num) {
            $off = $first + (int) $nums[$i + 1];
            $next = isset($nums[$i + 3]) ? $first + (int) $nums[$i + 3] : strlen($data);
            return trim(substr($data, $off, $next - $off));
        }
    }
    throw new RuntimeException('Objeto ' . $num . ' não encontrado no fluxo.');
}

/** Números dos objetos de página, na ordem. */
function pdfx_pages(string $pdf, array $info): array
{
    $root = pdfx_object($pdf, $info, pdfx_ref(pdfx_get($info['trailer'], 'Root')));
    $out = [];
    $walk = function (int $n) use (&$walk, &$out, $pdf, $info) {
        $o = pdfx_object($pdf, $info, $n);
        if (preg_match('#/Type\s*/Page(?![s\w])#', $o)) {
            $out[] = $n;
            return;
        }
        if (preg_match('#/Kids\s*\[([^\]]*)\]#', $o, $k)) {
            preg_match_all('/(\d+)\s+\d+\s+R/', $k[1], $refs);
            foreach ($refs[1] as $r) {
                $walk((int) $r);
            }
        }
    };
    $walk(pdfx_ref(pdfx_get($root, 'Pages')));
    return $out;
}

/** Fontes do PDF: nome PostScript (BaseFont) => número do objeto. */
function pdfx_fonts(string $pdf, array $info): array
{
    $out = [];
    foreach (array_keys($info['map']) as $n) {
        try {
            $o = pdfx_object($pdf, $info, $n);
        } catch (Throwable) {
            continue;
        }
        if (preg_match('#/Type\s*/Font\b#', $o) && preg_match('#/BaseFont\s*/([^\s/<>\[\]]+)#', $o, $m)) {
            $out[preg_replace('/^[A-Z]{6}\+/', '', $m[1])] = $n;
        }
    }
    return $out;
}

/* ---------- Escrita incremental ---------- */

/**
 * Acrescenta objetos (número => corpo) ao fim do PDF, com uma nova tabela de
 * referências que aponta para a anterior. $trailerExtra substitui chaves do
 * trailer (ex.: /Root). Retorna o novo PDF.
 */
function pdfx_append(string $pdf, array $info, array $objects): string
{
    $out = rtrim($pdf, "\r\n") . "\n";
    $offsets = [];
    ksort($objects);
    foreach ($objects as $num => $body) {
        $offsets[$num] = strlen($out);
        $out .= $num . " 0 obj\n" . $body . "\nendobj\n";
    }
    $xref = strlen($out);
    $out .= "xref\n";
    $nums = array_keys($offsets);
    // subseções contínuas
    $groups = [];
    foreach ($nums as $n) {
        if ($groups && end($groups)[0] + count(end($groups)[1]) === $n) {
            $groups[count($groups) - 1][1][] = $offsets[$n];
        } else {
            $groups[] = [$n, [$offsets[$n]]];
        }
    }
    foreach ($groups as [$first, $offs]) {
        $out .= $first . ' ' . count($offs) . "\n";
        foreach ($offs as $o) {
            $out .= sprintf("%010d 00000 n \n", $o);
        }
    }
    $size = max($info['size'], max($nums) + 1);
    $tr = '<< /Size ' . $size . ' /Root ' . pdfx_get($info['trailer'], 'Root')
        . (pdfx_get($info['trailer'], 'Info') ? ' /Info ' . pdfx_get($info['trailer'], 'Info') : '')
        . (preg_match('#/ID\s*\[[^\]]*\]#', (string) $info['trailer'], $id) ? ' ' . $id[0] : '')
        . ' /Prev ' . $info['startxref'] . ' >>';
    return $out . "trailer\n" . $tr . "\nstartxref\n" . $xref . "\n%%EOF\n";
}

/** Página com a lista /Annots acrescida (cria a chave, se não houver). */
function pdfx_page_with_annots(string $page, array $refs): string
{
    $add = implode(' ', array_map(fn($r) => $r . ' 0 R', $refs));
    if (preg_match('#/Annots\s*\[([^\]]*)\]#', $page)) {
        return preg_replace('#/Annots\s*\[([^\]]*)\]#', '/Annots [$1 ' . $add . ']', $page, 1);
    }
    if (preg_match('#/Annots\s+(\d+)\s+0\s+R#', $page)) {
        throw new RuntimeException('Lista de anotações indireta não suportada.');
    }
    return preg_replace('#>>\s*$#', ' /Annots [' . $add . '] >>', $page, 1);
}

function pdfx_text(string $s): string
{
    return '(' . strtr($s, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => '\\r']) . ')';
}

/** Texto UTF-8 em texto PDF (UTF-16BE com marca), para nomes e motivos. */
function pdfx_utext(string $s): string
{
    return '<FEFF' . strtoupper(bin2hex((string) mb_convert_encoding($s, 'UTF-16BE', 'UTF-8'))) . '>';
}

/**
 * Fluxo de desenho (operadores PDF) para as operações da diagramação, num
 * espaço em mm com origem no canto superior esquerdo de uma caixa de altura
 * $h. $fonts: chave da fonte => nome do recurso (/F1…).
 */
function pdfx_draw(array $ops, float $h, array $fonts): string
{
    $k = 72 / 25.4;
    $X = fn(float $x) => sprintf('%.2F', $x * $k);
    $Y = fn(float $y) => sprintf('%.2F', ($h - $y) * $k);
    $rgb = fn(string $hex) => implode(' ', array_map(fn($c) => sprintf('%.3F', $c / 255), pdoc_rgb($hex)));
    $s = '';
    foreach ($ops as $op) {
        switch ($op[0]) {
            case 't':
            case 'rt':
                $f = $fonts[$op[3]] ?? null;
                if (!$f) {
                    break;
                }
                $tm = $op[0] === 'rt' ? '0 1 -1 0 ' : '1 0 0 1 ';
                $s .= 'BT /' . $f . ' ' . sprintf('%.2F', $op[4]) . ' Tf ' . $rgb($op[5]) . ' rg ' . $tm . $X($op[1]) . ' ' . $Y($op[2]) . ' Tm ' . pdfx_text($op[6]) . " Tj ET\n";
                break;
            case 'r':
                $s .= $rgb($op[5]) . ' rg ' . $X($op[1]) . ' ' . $Y($op[2] + $op[4]) . ' ' . sprintf('%.2F %.2F', $op[3] * $k, $op[4] * $k) . " re f\n";
                break;
            case 'l':
                $s .= $rgb($op[6]) . ' RG ' . sprintf('%.2F', $op[5] * $k) . ' w ' . $X($op[1]) . ' ' . $Y($op[2]) . ' m ' . $X($op[3]) . ' ' . $Y($op[4]) . " l S\n";
                break;
            case 'rr':
                [, $x, $y, $w, $hh, $r, $stroke, $lw, $fill] = $op;
                $c = 0.5523 * $r;
                $P = fn($px, $py) => $X($px) . ' ' . $Y($py);
                $s .= ($fill ? $rgb($fill) . ' rg ' : '') . $rgb($stroke) . ' RG ' . sprintf('%.2F', $lw * $k) . ' w '
                    . $P($x + $r, $y) . ' m ' . $P($x + $w - $r, $y) . ' l ' . $P($x + $w - $r + $c, $y) . ' ' . $P($x + $w, $y + $r - $c) . ' ' . $P($x + $w, $y + $r) . ' c '
                    . $P($x + $w, $y + $hh - $r) . ' l ' . $P($x + $w, $y + $hh - $r + $c) . ' ' . $P($x + $w - $r + $c, $y + $hh) . ' ' . $P($x + $w - $r, $y + $hh) . ' c '
                    . $P($x + $r, $y + $hh) . ' l ' . $P($x + $r - $c, $y + $hh) . ' ' . $P($x, $y + $hh - $r + $c) . ' ' . $P($x, $y + $hh - $r) . ' c '
                    . $P($x, $y + $r) . ' l ' . $P($x, $y + $r - $c) . ' ' . $P($x + $r - $c, $y) . ' ' . $P($x + $r, $y) . ' c ' . ($fill ? 'B' : 'S') . "\n";
                break;
            case 'poly':
                if (count($op[1]) < 2) {
                    break;
                }
                $s .= $rgb($op[3]) . ' RG ' . sprintf('%.2F', $op[2] * $k) . ' w 1 J 1 j ';
                foreach ($op[1] as $j => [$px, $py]) {
                    $s .= $X($px) . ' ' . $Y($py) . ($j ? ' l ' : ' m ');
                }
                $s .= "S 0 J 0 j\n";
                break;
            case 'qr':
                [, $x, $y, $side, $rows] = $op;
                $m = $side / count($rows);
                $s .= '1 1 1 rg ' . $X($x) . ' ' . $Y($y + $side) . ' ' . sprintf('%.2F %.2F', $side * $k, $side * $k) . " re f 0 0 0 rg\n";
                foreach ($rows as $r => $row) {
                    preg_match_all('/1+/', $row, $mm, PREG_OFFSET_CAPTURE);
                    foreach ($mm[0] as [$run, $off]) {
                        $s .= $X($x + $off * $m) . ' ' . $Y($y + ($r + 1) * $m) . ' ' . sprintf('%.3F %.3F', (strlen($run) * $m + 0.005) * $k, ($m + 0.005) * $k) . " re\n";
                    }
                }
                $s .= "f\n";
                break;
        }
    }
    return $s;
}

/**
 * Acrescenta anotações a páginas, numa atualização incremental. $groups:
 * [índice da página (0…) => [['draw' => ops em mm da página, 'rect' => [x,y,l,a] em mm] |
 * ['link' => url, 'rect' => [...]], ...]]. Os desenhos viram carimbos
 * (anotação /Stamp com aparência própria, impressos e travados).
 */
function pdfx_add_annots(string $pdf, array $groups, array $fontKeys): string
{
    $info = pdfx_parse($pdf);
    $pages = pdfx_pages($pdf, $info);
    $fontsByName = pdfx_fonts($pdf, $info);
    $res = [];
    $i = 1;
    foreach ($fontKeys as $key) {
        $name = json_decode((string) file_get_contents(APP_DIR . '/vendor/fpdf/font/' . $key . '.json'), true)['name'];
        if (isset($fontsByName[$name])) {
            $res[$key] = ['FX' . $i++, $fontsByName[$name]];
        }
    }
    $fontDict = '<< ' . implode(' ', array_map(fn($r) => '/' . $r[0] . ' ' . $r[1] . ' 0 R', $res)) . ' >>';
    $fmap = array_map(fn($r) => $r[0], $res);
    $next = $info['size'];
    $objs = [];
    $k = 72 / 25.4;
    foreach ($groups as $pi => $items) {
        $pnum = $pages[$pi];
        $page = pdfx_object($pdf, $info, $pnum);
        preg_match('#/MediaBox\s*\[\s*([\d.\-]+)\s+([\d.\-]+)\s+([\d.\-]+)\s+([\d.\-]+)\s*\]#', $page, $mb);
        $ph = $mb ? ((float) $mb[4] - (float) $mb[2]) / $k : PDOC_H;
        $refs = [];
        foreach ($items as $it) {
            [$x, $y, $w, $h] = $it['rect'];
            $rect = sprintf('[%.2F %.2F %.2F %.2F]', $x * $k, ($ph - $y - $h) * $k, ($x + $w) * $k, ($ph - $y) * $k);
            $an = $next++;
            if (isset($it['link'])) {
                $objs[$an] = '<< /Type /Annot /Subtype /Link /Rect ' . $rect . ' /Border [0 0 0] /F 4 /A << /S /URI /URI ' . pdfx_text($it['link']) . ' >> >>';
            } else {
                $ap = $next++;
                // desenho relativo ao canto da caixa
                $stream = pdfx_draw(pdoc_move($it['draw'], -$x, -$y), $h, $fmap);
                $z = gzcompress($stream);
                $objs[$ap] = '<< /Type /XObject /Subtype /Form /BBox [0 0 ' . sprintf('%.2F %.2F', $w * $k, $h * $k) . '] /Resources << /Font ' . $fontDict
                    . ' >> /Filter /FlateDecode /Length ' . strlen($z) . " >>\nstream\n" . $z . "\nendstream";
                $objs[$an] = '<< /Type /Annot /Subtype /Stamp /Rect ' . $rect . ' /F 196 /Name /' . ($it['name'] ?? 'Assinatura') . ' /Contents ' . pdfx_utext((string) ($it['title'] ?? 'Assinatura eletrônica'))
                    . ' /AP << /N ' . $ap . ' 0 R >> /P ' . $pnum . ' 0 R >>';
            }
            $refs[] = $an;
        }
        $objs[$pnum] = pdfx_page_with_annots($page, $refs);
    }
    return pdfx_append($pdf, $info, $objs);
}

/* ---------- Assinatura digital ---------- */

/** Certificado A1 (.pfx/.p12) aberto com a senha: [certificado, chave, cadeia] ou erro. */
function pdfx_open_pfx(string $pfx, string $password): array
{
    if (!openssl_pkcs12_read($pfx, $c, $password)) {
        return [null, 'Senha do certificado incorreta (ou arquivo inválido).'];
    }
    return [$c, null];
}

/** Dados do certificado: titular, CPF (ICP-Brasil), emissor, validade e impressão digital. */
function pdfx_cert_info(string $pem): array
{
    $x = openssl_x509_parse($pem) ?: [];
    $cn = (string) ($x['subject']['CN'] ?? '');
    $der = base64_decode(preg_replace('/-----[^-]+-----|\s+/', '', $pem));
    $cpf = null;
    // ICP-Brasil: OID 2.16.76.1.3.1 (data de nascimento + CPF) no nome alternativo
    if (($p = strpos($der, "\x06\x05\x60\x4c\x01\x03\x01")) !== false && preg_match('/\d{19,}/', substr($der, $p, 80), $d)) {
        $cpf = substr($d[0], 8, 11);
    } elseif (preg_match('/:(\d{11})$/', $cn, $d)) {
        $cpf = $d[1];
    }
    $issuer = $x['issuer'] ?? [];
    return ['titular' => trim(preg_replace('/:\d{11,14}$/', '', $cn)), 'cn' => $cn, 'cpf' => $cpf, 'emissor' => (string) ($issuer['CN'] ?? ''),
        'icp_brasil' => in_array('ICP-Brasil', (array) ($issuer['O'] ?? []), true) || ($issuer['O'] ?? '') === 'ICP-Brasil',
        'valido_de' => isset($x['validFrom_time_t']) ? date('Y-m-d H:i:s', $x['validFrom_time_t']) : null,
        'valido_ate' => isset($x['validTo_time_t']) ? date('Y-m-d H:i:s', $x['validTo_time_t']) : null,
        'serie' => (string) ($x['serialNumberHex'] ?? ''), 'sha256' => openssl_x509_fingerprint($pem, 'sha256') ?: ''];
}

/**
 * Assina o PDF com o certificado (atualização incremental): campo de
 * assinatura na página $pageIndex sobre $rect (mm, [x,y,l,a]; [0,0,0,0] =
 * invisível), CMS destacado sobre todo o arquivo menos o próprio conteúdo da
 * assinatura. Retorna [pdf, erro].
 */
function pdfx_sign(string $pdf, array $cert, int $pageIndex, array $rect, array $meta): array
{
    $info = pdfx_parse($pdf);
    $pages = pdfx_pages($pdf, $info);
    $pnum = $pages[$pageIndex] ?? $pages[0];
    $page = pdfx_object($pdf, $info, $pnum);
    $rootNum = pdfx_ref(pdfx_get($info['trailer'], 'Root'));
    $root = pdfx_object($pdf, $info, $rootNum);
    if (str_contains($root, '/AcroForm')) {
        return [null, 'O documento já tem formulário de assinaturas; assinatura adicional não suportada.'];
    }
    $k = 72 / 25.4;
    [$x, $y, $w, $h] = $rect;
    $sig = $info['size'];
    $wid = $sig + 1;
    $sigRect = $w > 0 ? sprintf('[%.2F %.2F %.2F %.2F]', $x * $k, (PDOC_H - $y - $h) * $k, ($x + $w) * $k, (PDOC_H - $y) * $k) : '[0 0 0 0]';
    $date = 'D:' . date('YmdHis') . str_replace(':', "'", date('P')) . "'";
    $placeholder = str_repeat('0', PDFX_SIG_BYTES * 2);
    $objs = [
        $sig => '<< /Type /Sig /Filter /Adobe.PPKLite /SubFilter /adbe.pkcs7.detached /ByteRange [0 ********** ********** **********] /Contents <' . $placeholder . '>'
            . ' /M (' . $date . ') /Name ' . pdfx_utext($meta['nome'] ?? '') . ' /Reason ' . pdfx_utext($meta['motivo'] ?? '') . ' /Location ' . pdfx_utext($meta['local'] ?? '')
            . ' /ContactInfo ' . pdfx_utext($meta['contato'] ?? '') . ' >>',
        $wid => '<< /Type /Annot /Subtype /Widget /FT /Sig /T ' . pdfx_utext($meta['campo'] ?? 'Assinatura do advogado') . ' /V ' . $sig . ' 0 R /F 132 /Rect ' . $sigRect . ' /P ' . $pnum . ' 0 R >>',
        $pnum => pdfx_page_with_annots($page, [$wid]),
        $rootNum => preg_replace('#>>\s*$#', ' /AcroForm << /Fields [' . $wid . ' 0 R] /SigFlags 3 >> >>', $root, 1),
    ];
    $out = pdfx_append($pdf, $info, $objs);
    // ByteRange: tudo, menos o conteúdo hexadecimal entre < e >
    $cpos = strpos($out, '/Contents <' . $placeholder) + strlen('/Contents ');
    $clen = strlen($placeholder) + 2;
    $br = [0, $cpos, $cpos + $clen, strlen($out) - $cpos - $clen];
    $brs = sprintf('[%d %d %d %d]', ...$br);
    $brs = str_pad($brs, strlen('[0 ********** ********** **********]'));
    $out = str_replace('[0 ********** ********** **********]', $brs, $out);
    $data = substr($out, 0, $br[1]) . substr($out, $br[2]);
    $tmp = sys_get_temp_dir() . '/pdfx-' . bin2hex(random_bytes(6));
    file_put_contents($tmp . '.in', $data);
    $extra = '';
    if (!empty($cert['extracerts'])) {
        file_put_contents($tmp . '.ca', implode("\n", $cert['extracerts']));
        $extra = $tmp . '.ca';
    }
    $ok = openssl_cms_sign($tmp . '.in', $tmp . '.out', $cert['cert'], $cert['pkey'], [], OPENSSL_CMS_DETACHED | OPENSSL_CMS_BINARY, OPENSSL_ENCODING_DER, $extra ?: null);
    $cms = $ok ? (string) file_get_contents($tmp . '.out') : '';
    foreach (['.in', '.out', '.ca'] as $e) {
        @unlink($tmp . $e);
    }
    if (!$ok || $cms === '') {
        return [null, 'Não foi possível assinar com o certificado: ' . (openssl_error_string() ?: 'erro do OpenSSL')];
    }
    $hex = strtoupper(bin2hex($cms));
    if (strlen($hex) > strlen($placeholder)) {
        return [null, 'A assinatura ficou maior que o espaço reservado.'];
    }
    $out = substr($out, 0, $cpos + 1) . str_pad($hex, strlen($placeholder), '0') . substr($out, $cpos + 1 + strlen($placeholder));
    return [$out, null];
}

/**
 * Assinaturas digitais do PDF: para cada uma, se o conteúdo assinado confere
 * (integridade), quem assinou (certificado), quando e quanto do arquivo cobre.
 */
function pdfx_signatures(string $pdf): array
{
    $out = [];
    preg_match_all('#/ByteRange\s*\[\s*(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s*\]#', $pdf, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
    foreach ($all as $m) {
        [$a, $b, $c, $d] = [(int) $m[1][0], (int) $m[2][0], (int) $m[3][0], (int) $m[4][0]];
        if ($a !== 0 || $b <= 0 || $c <= $b || $c + $d > strlen($pdf)) {
            continue;
        }
        $bin = (string) hex2bin(trim(substr($pdf, $b, $c - $b), '<>'));
        // tamanho real do CMS (DER), sem os zeros de preenchimento
        $l1 = ord($bin[1] ?? "\0");
        $len = $l1 < 0x80 ? $l1 + 2 : 2 + ($l1 & 0x7f) + (int) hexdec(bin2hex(substr($bin, 2, $l1 & 0x7f)));
        $cms = substr($bin, 0, $len);
        $data = substr($pdf, 0, $b) . substr($pdf, $c, $d);
        $tmp = sys_get_temp_dir() . '/pdfv-' . bin2hex(random_bytes(6));
        file_put_contents($tmp . '.sig', $cms);
        file_put_contents($tmp . '.data', $data);
        $ok = openssl_cms_verify($tmp . '.data', OPENSSL_CMS_NOVERIFY | OPENSSL_CMS_BINARY | OPENSSL_CMS_DETACHED, $tmp . '.crt', [], null, null, null, $tmp . '.sig', OPENSSL_ENCODING_DER);
        $crt = is_file($tmp . '.crt') ? (string) file_get_contents($tmp . '.crt') : '';
        foreach (['.sig', '.data', '.crt'] as $e) {
            @unlink($tmp . $e);
        }
        preg_match('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s', $crt, $first);
        $out[] = ['integra' => $ok === true, 'cobre_bytes' => $c + $d, 'cobre_tudo' => $c + $d === strlen($pdf), 'certificado' => $first ? pdfx_cert_info($first[0]) : null];
    }
    return $out;
}
