<?php
declare(strict_types=1);

/**
 * Assinatura eletrônica dos documentos do kit pelo cliente, sem certificado
 * digital: identificação pela conta da área do cliente, confirmação do nome e
 * do CPF, código de uso único enviado ao e-mail do cadastro, rubrica desenhada
 * na tela e o aceite expresso. Ficam registrados o IP, o navegador, a data e
 * hora do servidor com o fuso e, se o cliente permitir, a localização.
 *
 * A via assinada é um PDF com as páginas normais do documento (sem folha a
 * mais): o selo — rubrica, nome, "Assinado eletronicamente", data e hora e o
 * código — fica onde o cliente o arrastou, e a margem esquerda de todas as
 * páginas leva a faixa de verificação com QR Code. Selo e faixa são links para
 * /verificar/{código}. O PDF é gerado uma vez, guardado com o hash SHA-256 e
 * entregue sempre com os mesmos bytes; o histórico de cada registro é
 * encadeado e não aceita alteração (migração 20).
 *
 * Base: MP 2.200-2/2001, art. 10, § 2º (validade de outros meios de
 * comprovação de autoria e integridade aceitos pelas partes) e Código Civil,
 * art. 107 (forma livre). O aceite do cliente diz isso expressamente.
 *
 * O cliente também pode pedir retificação: o texto vai para Sidney com a
 * avaliação da IA, na aba Kit do atendimento.
 */

const SIGN_CODE_MINUTES = 10;
const SIGN_CONSENT = 'Li o documento por inteiro, concordo com o seu conteúdo e o assino eletronicamente. '
    . 'Reconheço esta assinatura eletrônica como válida e com o mesmo efeito da assinatura de próprio punho '
    . '(MP 2.200-2/2001, art. 10, § 2º, e Código Civil, art. 107), e autorizo o registro do meu IP, do navegador, '
    . 'da data e hora, do código enviado ao meu e-mail e, se eu permitir, da localização do aparelho, como prova desta assinatura.';

function sig_for_version(int $versionId): ?array
{
    return one('SELECT * FROM doc_signatures WHERE version_id = ? ORDER BY id DESC LIMIT 1', [$versionId]);
}

function sig_doc_hash(array $v): string
{
    return hash('sha256', (string) $v['content']);
}

function sig_mask_email(string $e): string
{
    [$u, $d] = array_pad(explode('@', $e, 2), 2, '');
    return mb_substr($u, 0, 2) . str_repeat('•', max(1, mb_strlen($u) - 2)) . '@' . $d;
}

function sig_fmt_cpf(string $d): string
{
    $d = only_digits($d);
    return strlen($d) === 11 ? substr($d, 0, 3) . '.' . substr($d, 3, 3) . '.' . substr($d, 6, 3) . '-' . substr($d, 9) : $d;
}

/** Texto para a folha de evidências: sem a marcação do editor de documentos. */
function sig_plain(?string $s): string
{
    return trim(preg_replace('/\s+/u', ' ', str_replace(['*', '{', '}', '|', '[', ']'], ' ', (string) $s)));
}

function sig_norm_name(string $s): string
{
    $s = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $s)));
    return strtr($s, ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ü' => 'u', 'ç' => 'c']);
}

/** Nome que o cliente precisa digitar (o do documento). */
function sig_expected_name(array $c): string
{
    return trim((string) (fv((int) $c['id'], 'nome_completo') ?: $c['client_name']));
}

/** Envia o código de uso único ao e-mail do cadastro (no teste, ao de quem testa). Retorna erro ou null. */
function sig_send_code(array $c, array $v, ?string $to = null): ?string
{
    $email = $to ?? (string) $c['client_email'];
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 'Seu atendimento não tem e-mail cadastrado. Informe o e-mail na conversa para poder assinar.';
    }
    $bucket = 'assinatura:' . $v['id'];
    if (too_many_attempts($bucket, 5, 30)) {
        return 'Muitos códigos pedidos seguidos. Aguarde meia hora ou fale com Sidney.';
    }
    record_attempt($bucket);
    $code = (string) random_int(100000, 999999);
    $_SESSION['sig_code'][$v['id']] = ['hash' => password_hash($code, PASSWORD_DEFAULT), 'exp' => time() + SIGN_CODE_MINUTES * 60, 'tries' => 0, 'sent_at' => now_str(), 'to' => $email];
    $label = kit_label($v['kind']);
    $lines = ['Seu código para assinar o documento “' . $label . '” (versão ' . sig_vtxt($v) . ', atendimento ' . $c['protocol'] . ') é: ' . $code,
        'Ele vale por ' . SIGN_CODE_MINUTES . ' minutos e só pode ser usado uma vez. Se você não pediu este código, ignore este e-mail e avise Sidney.'];
    [$ok, $err] = send_mail($email, 'Código para assinar: ' . $code, implode("\n\n", $lines), mail_html('Código para assinar o documento', $lines), (int) $c['id'], 'assinatura');
    if (!$ok) {
        unset($_SESSION['sig_code'][$v['id']]);
        return 'Não foi possível enviar o código agora: ' . $err;
    }
    $to === null && add_event((int) $c['id'], 'assinatura', 'Código de assinatura enviado', $label . ' v' . $v['version'] . ' · ' . sig_mask_email($email), false, 'cliente');
    return null;
}

/** Confere o código (no máximo 5 tentativas). [dados do envio, erro]. */
function sig_check_code(array $v, string $code): array
{
    $s = $_SESSION['sig_code'][$v['id']] ?? null;
    if (!$s) {
        return [null, 'Peça o código de confirmação no seu e-mail.'];
    }
    if ($s['exp'] < time()) {
        unset($_SESSION['sig_code'][$v['id']]);
        return [null, 'O código expirou. Peça um novo.'];
    }
    if ($s['tries'] >= 5) {
        unset($_SESSION['sig_code'][$v['id']]);
        return [null, 'Muitas tentativas. Peça um novo código.'];
    }
    $_SESSION['sig_code'][$v['id']]['tries']++;
    if (!password_verify(only_digits($code), $s['hash'])) {
        return [null, 'Código incorreto. Confira o último e-mail recebido.'];
    }
    unset($_SESSION['sig_code'][$v['id']]);
    return [$s, null];
}

/** Rubrica desenhada: PNG em data URL, até 300 KB. */
function sig_image_ok(string $png): bool
{
    if (!preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', $png, $m) || strlen($png) > 400000) {
        return false;
    }
    $bin = base64_decode($m[1], true);
    return $bin !== false && str_starts_with($bin, "\x89PNG") && strlen($bin) > 200;
}

/** Localização informada pelo navegador (ou o motivo de não haver). */
function sig_geo(array $in): array
{
    $lat = is_numeric($in['geo_lat'] ?? null) ? (float) $in['geo_lat'] : null;
    $lng = is_numeric($in['geo_lng'] ?? null) ? (float) $in['geo_lng'] : null;
    if ($lat !== null && $lng !== null && abs($lat) <= 90 && abs($lng) <= 180) {
        return ['status' => 'ok', 'lat' => round($lat, 6), 'lng' => round($lng, 6), 'precisao_m' => is_numeric($in['geo_acc'] ?? null) ? (int) round((float) $in['geo_acc']) : null];
    }
    $st = (string) ($in['geo_status'] ?? '');
    return ['status' => in_array($st, ['negada', 'indisponivel', 'tempo'], true) ? $st : 'nao_informada'];
}

function sig_geo_text(array $g): string
{
    return match ($g['status'] ?? '') {
        'ok' => number_format($g['lat'], 6, '.', '') . ', ' . number_format($g['lng'], 6, '.', '') . ($g['precisao_m'] ? ' (precisão de ' . $g['precisao_m'] . ' m)' : ''),
        'negada' => 'não autorizada pelo assinante no navegador',
        'indisponivel', 'tempo' => 'não disponível no aparelho',
        default => 'não informada',
    };
}

/* ---------- Selo, faixa lateral e via em PDF ---------- */

const SEAL_W = 64.0;          // selo: 64 × 22 mm
const SEAL_H = 22.0;
const SEAL_INK = ['x' => 2.0, 'y' => 2.0, 'w' => 22.0, 'h' => 18.0]; // área da rubrica dentro do selo
const BAND_X = 7.0;           // faixa lateral: de 7 mm (fora da área que a impressora corta)…
const BAND_MAX_W = 20.0;      // …até 27 mm, no máximo (o texto começa em 30 mm)
const BAND_TOP = 22.0;        // o texto da faixa sobe até 22 mm do topo
const BAND_QR = 20.0;         // QR Code de 20 mm, já com a margem de leitura (módulo de 0,54 mm)
const BAND_QR_Y = 248.5;      // termina em 268,5 mm, acima do rodapé
const BAND_SIZES = [8.5, 8.0, 7.5, 7.0];
const SEAL_MIN_X = 27.5;      // o selo não cobre a faixa lateral

/** Data e hora como aparecem no selo e na faixa. */
function sig_when(DateTimeImmutable $t): array
{
    return [$t->format('d/m/Y \à\s H:i:s'), 'horário de Brasília, UTC' . $t->format('P')];
}

/** Dados impressos no selo e na faixa (os definitivos ficam guardados no registro). */
/** Versões de teste de assinatura ficam acima desta numeração (fora do kit). */
const SIG_TEST_VERSION = 9000;

/** Número da versão para mostrar ("3" ou "teste 1"). */
function sig_vtxt(array $v): string
{
    return ($v['status'] ?? '') === 'teste' ? 'teste ' . ((int) $v['version'] - SIG_TEST_VERSION) : (string) (int) $v['version'];
}

function sig_meta(array $c, array $v, ?array $done = null, ?array $req = null): array
{
    $m = ['nome' => sig_expected_name($c), 'titulo' => kit_label($v['kind']), 'protocolo' => (string) $c['protocol'], 'versao' => sig_vtxt($v),
        'quando' => $done['quando'] ?? null, 'fuso' => $done['fuso'] ?? null, 'codigo' => $done['codigo'] ?? ($req['code'] ?? null), 'url' => $done['url'] ?? null,
        'host' => preg_replace('#^https?://#', '', rtrim(public_root(), '/'))];
    if ($req) {
        // a faixa leva o cliente e quem do escritório já assinou
        $m['assinantes'] = [['nome' => $m['nome'], 'papel' => 'cliente', 'quando' => $m['quando']]];
        foreach (sig_parties((int) $req['id']) as $p) {
            if ($p['signed_at']) {
                $m['assinantes'][] = ['nome' => $p['name'], 'papel' => $p['role'], 'quando' => sig_when(dt($p['signed_at']))[0]];
            }
        }
        $m['fuso'] ??= 'horário de Brasília, UTC' . now()->format('P');
    }
    return $m;
}

/** Rubrica: traços desenhados no quadro, {"w","h","s":[[[x,y],…],…]} em px do quadro. */
function sig_strokes(mixed $raw): ?array
{
    $j = is_string($raw) ? json_decode($raw, true) : $raw;
    if (!is_array($j) || !is_numeric($j['w'] ?? null) || !is_numeric($j['h'] ?? null) || !is_array($j['s'] ?? null) || count($j['s']) > 80) {
        return null;
    }
    $out = [];
    $total = 0;
    [$minx, $miny, $maxx, $maxy] = [INF, INF, -INF, -INF];
    foreach ($j['s'] as $st) {
        if (!is_array($st)) {
            return null;
        }
        $pts = [];
        foreach ($st as $p) {
            if (!is_array($p) || !is_numeric($p[0] ?? null) || !is_numeric($p[1] ?? null) || abs((float) $p[0]) > 5000 || abs((float) $p[1]) > 5000) {
                return null;
            }
            $pts[] = [round((float) $p[0], 1), round((float) $p[1], 1)];
            $minx = min($minx, $pts[count($pts) - 1][0]);
            $maxx = max($maxx, $pts[count($pts) - 1][0]);
            $miny = min($miny, $pts[count($pts) - 1][1]);
            $maxy = max($maxy, $pts[count($pts) - 1][1]);
        }
        $total += count($pts);
        if (count($pts) >= 2) {
            $out[] = $pts;
        }
    }
    if (!$out || $total > 8000 || max($maxx - $minx, $maxy - $miny) < 8) {
        return null;
    }
    return ['w' => round((float) $j['w'], 1), 'h' => round((float) $j['h'], 1), 's' => $out];
}

/** Traços encaixados numa caixa, mantendo a proporção (a tela faz a mesma conta). */
function sig_strokes_fit(array $strokes, array $box): array
{
    $all = array_merge(...$strokes['s']);
    $xs = array_column($all, 0);
    $ys = array_column($all, 1);
    $bw = max(max($xs) - min($xs), 1.0);
    $bh = max(max($ys) - min($ys), 1.0);
    $sc = min(($box['w'] - 2) / $bw, ($box['h'] - 2) / $bh);
    $ox = $box['x'] + ($box['w'] - $bw * $sc) / 2 - min($xs) * $sc;
    $oy = $box['y'] + ($box['h'] - $bh * $sc) / 2 - min($ys) * $sc;
    return array_map(fn($st) => ['poly', array_map(fn($p) => [$ox + $p[0] * $sc, $oy + $p[1] * $sc], $st), 0.45, PDOC_ACENTO], $strokes['s']);
}

/** Desloca operações de desenho. */
function pdoc_move(array $ops, float $dx, float $dy): array
{
    foreach ($ops as &$op) {
        switch ($op[0]) {
            case 't':
            case 'rt':
                $op[1] += $dx;
                $op[2] += $dy;
                break;
            case 'r':
            case 'rr':
            case 'link':
            case 'qr':
                $op[1] += $dx;
                $op[2] += $dy;
                break;
            case 'img':
                $op[2] += $dx;
                $op[3] += $dy;
                break;
            case 'l':
                $op[1] += $dx;
                $op[2] += $dy;
                $op[3] += $dx;
                $op[4] += $dy;
                break;
            case 'poly':
                $op[1] = array_map(fn($p) => [$p[0] + $dx, $p[1] + $dy], $op[1]);
                break;
        }
    }
    return $ops;
}

/** Texto de uma linha só, encolhido até caber na largura. [cp1252, pt, largura]. */
function sig_fit_text(string $text, string $key, float $pt, float $max, float $min = 4.5): array
{
    $enc = pdoc_enc($text);
    while ($pt > $min && pdoc_width($enc, $key, $pt) > $max) {
        $pt -= 0.25;
    }
    return [$enc, $pt, pdoc_width($enc, $key, $pt)];
}

/**
 * Selo (em mm, a partir do canto do selo): rubrica, nome por extenso,
 * "Assinado eletronicamente", data e hora com o fuso e o código. Sem os dados
 * definitivos (prévia), mostra onde eles vão entrar.
 */
function sig_seal_ops(array $m, ?array $strokes): array
{
    $ops = [['rr', 0.0, 0.0, SEAL_W, SEAL_H, 2.0, PDOC_ACENTO, 0.35, 'FFFFFF'], ['l', 26.0, 3.0, 26.0, SEAL_H - 3.0, 0.2, PDOC_REGUA]];
    if ($strokes) {
        array_push($ops, ...sig_strokes_fit($strokes, SEAL_INK));
    }
    $x = 28.0;
    $w = SEAL_W - $x - 2.0;
    $lines = [];
    $lines[] = [...sig_fit_text($m['rotulo'] ?? 'ASSINADO ELETRONICAMENTE', 'poppinssb', 5.8, $w), 'poppinssb', PDOC_ACENTO];
    // nome por extenso: até duas linhas, diminuindo o corpo se preciso
    foreach ([7.5, 7.0, 6.5, 6.0] as $pt) {
        $nl = pdoc_break(pdoc_items([[$m['nome'], pdoc_style(['f' => 'poppins', 'w' => 700, 's' => $pt])]]), $w, $w, pdoc_style(['f' => 'poppins', 's' => $pt]));
        if (count($nl) <= 2) {
            break;
        }
    }
    foreach (array_slice($nl, 0, 2) as $l) {
        $enc = implode(' ', array_map(fn($sg) => implode('', array_column($sg[1][1], 0)), $l['segs']));
        $lines[] = [...sig_fit_text(pdoc_utf8($enc), 'poppinsb', $pt, $w), 'poppinsb', PDOC_TINTA];
    }
    $lines[] = [...sig_fit_text($m['quando'] ?? 'Data e hora: registradas ao concluir', 'poppins', 6.5, $w), 'poppins', $m['quando'] ? PDOC_TINTA : PDOC_SECUNDARIA];
    $lines[] = [...sig_fit_text($m['fuso'] ?? 'pelo relógio do servidor, com o fuso', 'poppins', 5.5, $w), 'poppins', PDOC_SECUNDARIA];
    $lines[] = [...sig_fit_text($m['codigo'] ? 'Código ' . $m['codigo'] : 'Código: gerado ao concluir', 'poppinssb', 6.2, $w), 'poppinssb', $m['codigo'] ? PDOC_ACENTO : PDOC_SECUNDARIA];
    $lines[] = [...sig_fit_text('Confira: clique no selo ou leia o QR Code', 'poppins', 5.0, $w, 3.5), 'poppins', PDOC_SECUNDARIA];
    $lh = fn(float $pt) => $pt * PDOC_PT * 1.32;
    $total = array_sum(array_map(fn($l) => $lh($l[1]), $lines));
    $y = (SEAL_H - $total) / 2;
    foreach ($lines as [$enc, $pt, $tw, $key, $color]) {
        $ops[] = ['t', $x, $y + $pt * PDOC_PT * 1.02, $key, $pt, $color, $enc, $tw, false];
        $y += $lh($pt);
    }
    return $ops;
}

/**
 * Faixa da margem esquerda (de baixo para cima): assinatura eletrônica,
 * signatário, data e hora com o fuso, documento e versão, código, endereço de
 * conferência e página. O corpo começa em 8,5 pt; a faixa cresce até 20 mm
 * antes de a letra diminuir. QR Code embaixo; a faixa toda é um link.
 */
function sig_band_ops(array $m, int $page, int $total): array
{
    $done = (bool) $m['quando'];
    $papel = ['cliente' => 'contratante', 'testemunha' => 'testemunha', 'advogado' => 'advogado, certificado digital'];
    if (!empty($m['assinantes'])) {
        // cliente e equipe: nome, papel e data e hora de cada um
        $parts = [[$done ? 'Documento assinado eletronicamente' : 'Prévia das assinaturas eletrônicas', 600, PDOC_ACENTO]];
        foreach ($m['assinantes'] as $a) {
            $parts[] = [$a['nome'], 700, PDOC_TINTA];
            $parts[] = ['(' . ($papel[$a['papel']] ?? $a['papel']) . ($a['quando'] ? ', ' . $a['quando'] : ', ao concluir') . ')', 400, $a['quando'] ? PDOC_TINTA : PDOC_SECUNDARIA];
        }
        $parts[] = [$m['fuso'] ?? 'horário de Brasília', 400, PDOC_TINTA];
        $parts[] = [$m['titulo'] . ', versão ' . $m['versao'] . ' · atendimento ' . $m['protocolo'], 400, PDOC_TINTA];
        if ($m['codigo']) {
            $parts[] = ['Código de verificação ' . $m['codigo'], 600, PDOC_TINTA];
        }
        if ($done) {
            $parts[] = ['Confira em ' . $m['url'], 400, PDOC_TINTA];
        }
        $parts[] = ['Página ' . $page . ' de ' . $total, 400, PDOC_SECUNDARIA];
    } else {
        $parts = $done
            ? [['Documento assinado eletronicamente', 600, PDOC_ACENTO], ['por ' . $m['nome'], 700, PDOC_TINTA], ['em ' . $m['quando'] . ' (' . $m['fuso'] . ')', 400, PDOC_TINTA],
                [$m['titulo'] . ', versão ' . $m['versao'] . ' · atendimento ' . $m['protocolo'], 400, PDOC_TINTA], ['Código de verificação ' . $m['codigo'], 600, PDOC_TINTA],
                ['Confira em ' . $m['url'], 400, PDOC_TINTA], ['Página ' . $page . ' de ' . $total, 400, PDOC_SECUNDARIA]]
            : [['Prévia da assinatura eletrônica', 600, PDOC_ACENTO], ['por ' . $m['nome'], 700, PDOC_TINTA], ['data, hora e código de verificação registrados ao concluir', 400, PDOC_SECUNDARIA],
                [$m['titulo'] . ', versão ' . $m['versao'] . ' · atendimento ' . $m['protocolo'], 400, PDOC_TINTA], ['Página ' . $page . ' de ' . $total, 400, PDOC_SECUNDARIA]];
    }
    $len = BAND_QR_Y - 3.0 - BAND_TOP;
    foreach (BAND_SIZES as $pt) {
        $runs = [];
        foreach ($parts as $i => [$t, $wt, $color]) {
            if ($i) {
                $runs[] = [str_starts_with($t, '(') ? ' ' : ' · ', pdoc_style(['f' => 'poppins', 's' => $pt, 'c' => PDOC_SECUNDARIA])];
            }
            $runs[] = [$t, pdoc_style(['f' => 'poppins', 'w' => $wt, 's' => $pt, 'c' => $color])];
        }
        $lines = pdoc_break(pdoc_items($runs), $len, $len, pdoc_style(['f' => 'poppins', 's' => $pt]));
        $lh = $pt * PDOC_PT * 1.4;
        if (count($lines) * $lh <= BAND_MAX_W) {
            break;
        }
    }
    $ops = [];
    $em = $pt * PDOC_PT;
    foreach ($lines as $i => $l) {
        $bx = BAND_X + $i * $lh + $em * 1.05;
        $d = 0.0;
        foreach ($l['segs'] as [$gap, $word]) {
            $d += $gap;
            foreach ($word[1] as [$enc, $s, $w, $key]) {
                $ops[] = ['rt', $bx, BAND_QR_Y - 3.0 - $d, $key, $s['s'], $s['c'], $enc, $w];
                $d += $w;
            }
        }
    }
    if ($done) {
        $ops[] = ['qr', BAND_X, BAND_QR_Y, BAND_QR, pdoc_qr(sig_qr_text($m['url'], $m['codigo']))];
        $ops[] = ['link', BAND_X - 0.5, BAND_TOP - 1.0, BAND_MAX_W + 1.0, BAND_QR_Y + BAND_QR - BAND_TOP + 1.0, $m['url']];
    } else {
        $ops[] = ['rr', BAND_X, BAND_QR_Y, BAND_QR, BAND_QR, 1.2, PDOC_REGUA, 0.3, 'F4F5F7'];
        [$enc, $p2, $w2] = sig_fit_text('QR Code', 'poppins', 6.0, BAND_QR - 2);
        $ops[] = ['t', BAND_X + (BAND_QR - $w2) / 2, BAND_QR_Y + BAND_QR / 2 + 0.8, 'poppins', $p2, PDOC_SECUNDARIA, $enc, $w2, false];
    }
    return $ops;
}

/**
 * Conteúdo do QR Code: o mesmo registro por um endereço curto e em maiúsculas
 * (HTTPS://HOST/V/CÓDIGO), que cabe no modo alfanumérico — menos módulos,
 * módulos maiores, leitura mais fácil no papel. /V/{código} leva a /verificar.
 */
function sig_qr_text(string $url, string $code): string
{
    $u = parse_url($url);
    $root = strtoupper(($u['scheme'] ?? 'https') . '://' . ($u['host'] ?? '') . (isset($u['port']) ? ':' . $u['port'] : ''));
    $path = preg_replace('#/verificar/[^/]*$#', '', (string) ($u['path'] ?? ''));
    return $root . $path . '/V/' . $code;
}

/**
 * Diagramação do documento (cache por versão: o texto publicado não muda).
 * Com o pedido de assinaturas da equipe, as linhas das testemunhas levam o
 * nome e o CPF de cada uma, e cada linha de assinatura sabe de quem é.
 */
function sig_doc_layout(array $v, ?array $req = null): array
{
    static $cache = [];
    $c = find_case((int) $v['case_id']);
    $w = sig_request_witnesses($req);
    return $cache[$v['id'] . ':' . sig_doc_hash($v) . ':' . md5(json_encode($w))]
        ??= sig_test_mark($v, pdoc_chrome(pdoc_layout(sig_tag_blocks(doc_blocks((string) $v['content']), $c, $w), SEAL_H + 2.5)));
}

/** Contrato de teste: aviso no alto de cada página (não muda a diagramação). */
function sig_test_mark(array $v, array $L): array
{
    if (($v['status'] ?? '') !== 'teste') {
        return $L;
    }
    // no alto, à esquerda: o logotipo fica à direita
    [$enc, $pt, $w] = sig_fit_text('DOCUMENTO DE TESTE · SEM VALOR JURÍDICO', 'poppinsb', 8.0, 95.0);
    foreach ($L['pages'] as $i => $ops) {
        $L['pages'][$i][] = ['t', PDOC_LEFT, 12.0, 'poppinsb', $pt, 'B42318', $enc, $w, false];
    }
    return $L;
}

/**
 * Lugar para um selo sobre a linha de assinatura do papel pedido: logo acima
 * da linha, no centro ou nos lados, onde não cobrir texto nem outro selo.
 */
function sig_seal_place(array $L, string $role, array $avoid = []): array
{
    $pick = null;
    foreach ($L['sigs'] as $s) {
        if (($s[5] ?? null) === $role) {
            $pick = $s;
            break;
        }
    }
    $pick ??= end($L['sigs']) ?: null;
    if (!$pick) {
        return sig_seal_clamp(['pagina' => count($L['pages']), 'x' => (PDOC_W - SEAL_W) / 2, 'y' => 200.0], count($L['pages']));
    }
    $best = null;
    $taken = $avoid[$pick[0]] ?? [];
    foreach ([($pick[1] + $pick[2]) / 2 - SEAL_W / 2, PDOC_LEFT, PDOC_LEFT + PDOC_TEXT_W - SEAL_W] as $x) {
        $free = PDOC_TOP;
        foreach ($L['pages'][$pick[0] - 1] as $op) {
            if ($op[0] === 't' && $op[2] < $pick[3] - 0.5 && $op[2] + $op[4] * PDOC_PT * 0.3 > $free && $op[1] < $x + SEAL_W && $op[1] + $op[7] > $x) {
                $free = $op[2] + $op[4] * PDOC_PT * 0.3;
            }
        }
        $y = min(max($free + 1.0, $pick[3] - SEAL_H - 1.0), $pick[3] - SEAL_H + 1.0);
        $over = max(0.0, $free + 1.0 - $y);
        foreach ($taken as [$tx, $ty, $tw, $th]) {
            $ix = max(0.0, min($x + SEAL_W, $tx + $tw) - max($x, $tx));
            $iy = max(0.0, min($y + SEAL_H, $ty + $th) - max($y, $ty));
            $over += $ix * $iy;
        }
        if (!$best || $over < $best[2] - 0.01) {
            $best = [$x, $y, $over];
        }
    }
    return sig_seal_clamp(['pagina' => $pick[0], 'x' => $best[0], 'y' => $best[1]], count($L['pages']));
}

/** Posição sugerida do selo do cliente: sobre a linha dele, sem cobrir os selos do escritório. */
function sig_seal_default(array $c, array $v): array
{
    $req = function_exists('sig_request') ? sig_request((int) $v['id']) : null;
    return sig_seal_place(sig_doc_layout($v, $req), 'cliente', sig_party_rects($req));
}

function sig_seal_clamp(array $s, int $pages): array
{
    return ['pagina' => max(1, min($pages, (int) $s['pagina'])),
        'x' => round(max(SEAL_MIN_X, min(PDOC_W - SEAL_W - 2.0, (float) $s['x'])), 2),
        'y' => round(max(2.0, min(PDOC_H - SEAL_H - 2.0, (float) $s['y'])), 2)];
}

/**
 * Páginas com os selos da equipe já assinados, a faixa em todas e o selo do
 * cliente no lugar escolhido. Sem $strokes: a prévia (sem o selo do cliente).
 */
function sig_signed_layout(array $v, array $m, ?array $strokes, ?array $seal, ?array $req = null): array
{
    $L = sig_doc_layout($v, $req);
    foreach (sig_party_seals($req) as $s) {
        $L['pages'][$s['pagina'] - 1] = array_merge($L['pages'][$s['pagina'] - 1], $s['ops']);
    }
    $n = count($L['pages']);
    foreach ($L['pages'] as $i => $ops) {
        $L['pages'][$i] = array_merge($ops, sig_band_ops($m, $i + 1, $n));
    }
    if ($seal && $strokes) {
        $p = $seal['pagina'] - 1;
        $L['pages'][$p] = array_merge($L['pages'][$p], pdoc_move(sig_seal_ops($m, $strokes), $seal['x'], $seal['y']));
        if ($m['url']) {
            $L['pages'][$p][] = ['link', $seal['x'], $seal['y'], SEAL_W, SEAL_H, $m['url']];
        }
    }
    return $L;
}

function sig_storage_dir(): string
{
    $dir = config('storage_dir') . '/assinados';
    is_dir($dir) || mkdir($dir, 0700, true);
    return $dir;
}

/* ---------- Histórico encadeado ---------- */

function sig_log(string $code, string $event, array|string $detail = ''): void
{
    $detail = is_array($detail) ? json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $detail;
    $prev = (string) (scalar('SELECT hash FROM sig_log ORDER BY id DESC LIMIT 1') ?: str_repeat('0', 64));
    $at = now_str();
    insert('sig_log', ['code' => $code, 'event' => $event, 'detail' => $detail, 'at' => $at, 'prev_hash' => $prev, 'hash' => sig_log_hash($prev, $code, $event, $detail, $at)]);
}

/** Confere a corrente inteira. [íntegra?, entradas do código]. */
function sig_log_check(string $code): array
{
    $prev = str_repeat('0', 64);
    $ok = true;
    $mine = [];
    foreach (all('SELECT * FROM sig_log ORDER BY id') as $r) {
        if ($r['prev_hash'] !== $prev || !hash_equals($r['hash'], sig_log_hash($prev, $r['code'], $r['event'], $r['detail'], $r['at']))) {
            $ok = false;
        }
        $prev = $r['hash'];
        if ($r['code'] === $code) {
            $mine[] = $r;
        }
    }
    return [$ok, $mine];
}

/**
 * Bytes da via original, conferidos com o hash registrado antes de sair.
 * [bytes, tipo, nome] ou null (arquivo ausente ou alterado: fica no histórico).
 */
function sig_original(array $sig): ?array
{
    $v = one('SELECT * FROM case_artifact_versions WHERE id = ?', [$sig['version_id']]);
    $c = one('SELECT protocol FROM cases WHERE id = ?', [$sig['case_id']]);
    $base = doc_filename(kit_label((string) ($v['kind'] ?? 'documento')), (string) ($c['protocol'] ?? $sig['code']), (int) ($v['version'] ?? 0)) . '-assinado';
    if ($sig['pdf_sha256']) {
        $path = sig_storage_dir() . '/' . basename((string) $sig['pdf_path']);
        $bin = is_file($path) ? (string) file_get_contents($path) : null;
        $want = (string) $sig['pdf_sha256'];
        [$type, $name] = ['application/pdf', $base . '.pdf'];
    } else {
        $f = $sig['file_id'] ? one('SELECT * FROM files WHERE id = ?', [$sig['file_id']]) : null;
        $path = $f ? config('storage_dir') . '/uploads/' . $sig['case_id'] . '/' . $f['stored_name'] : '';
        $bin = $path !== '' && is_file($path) ? (string) file_get_contents($path) : null;
        $want = (string) $sig['file_hash'];
        [$type, $name] = ['text/html; charset=UTF-8', $base . '.html'];
    }
    if ($bin === null || $want === '' || !hash_equals($want, hash('sha256', $bin))) {
        $last = one("SELECT at FROM sig_log WHERE code = ? AND event = 'divergencia_arquivo' ORDER BY id DESC LIMIT 1", [$sig['code']]);
        if (!$last || (dt($last['at'])?->getTimestamp() ?? 0) < time() - 3600) {
            sig_log((string) $sig['code'], 'divergencia_arquivo', ['arquivo' => $bin === null ? 'ausente' : 'conteúdo diferente do registrado', 'hash_encontrado' => $bin === null ? null : hash('sha256', $bin)]);
        }
        return null;
    }
    return [$bin, $type, $name];
}

/**
 * Assina a versão publicada. $in: nome, cpf, codigo, aceite, tracos (rubrica
 * desenhada), rubrica (PNG do quadro, guardada como prova), selo (posição),
 * geo_* e dados do aparelho. Gera e guarda a via em PDF. Retorna [assinatura, erro].
 */
function sig_sign(array $c, array $v, array $in): array
{
    $id = (int) $c['id'];
    $test = $v['status'] === 'teste'; // teste da assinatura conjunta: cliente simulado, nada vai para o atendimento
    if ($v['status'] !== 'publicado' && !$test) {
        return [null, 'Este documento não está disponível para assinatura.'];
    }
    if (sig_for_version((int) $v['id'])) {
        return [null, 'Este documento já foi assinado.'];
    }
    // Com testemunhas ou o escritório no documento, o cliente assina por último.
    $req = sig_request_open($c, $v);
    if ($test && !$req) {
        return [null, 'Pedido de teste não encontrado.'];
    }
    if ($req && $req['status'] !== 'cliente') {
        return [null, 'O escritório ainda está assinando este documento. Você será avisado quando ele estiver pronto para a sua assinatura.'];
    }
    if (($in['aceite'] ?? '') !== '1') {
        return [null, 'Marque que leu e concorda com o documento.'];
    }
    [$nome, $cpf, $err] = sig_check_identity($c, $in);
    if ($err) {
        return [null, $err];
    }
    $strokes = sig_strokes($in['tracos'] ?? null);
    if (!$strokes) {
        return [null, 'Faça a sua rubrica no quadro de assinatura.'];
    }
    $png = (string) ($in['rubrica'] ?? '');
    $png = sig_image_ok($png) ? $png : null;
    $L = sig_doc_layout($v, $req);
    $pages = count($L['pages']);
    $posIn = is_string($in['selo'] ?? null) ? json_decode((string) $in['selo'], true) : null;
    $seal = is_array($posIn) && is_numeric($posIn['pagina'] ?? null) && is_numeric($posIn['x'] ?? null) && is_numeric($posIn['y'] ?? null)
        ? sig_seal_clamp($posIn, $pages) : sig_seal_default($c, $v);
    [$otp, $err] = sig_check_code($v, (string) ($in['codigo'] ?? ''));
    if (!$otp) {
        return [null, $err];
    }
    if (!$test && only_digits((string) fv($id, 'cpf')) === '') {
        set_field($id, 'cpf', $cpf, 'informado', 'cliente');
    }
    $now = now();
    $utc = $now->setTimezone(new DateTimeZone('UTC'));
    $geo = sig_geo($in);
    if ($req) {
        $code = (string) $req['code'];
    } else {
        do {
            $code = strtoupper(implode('-', str_split(substr(bin2hex(random_bytes(6)), 0, 12), 4)));
        } while (one('SELECT 1 FROM doc_signatures WHERE code = ?', [$code]) || one('SELECT 1 FROM sig_log WHERE code = ?', [$code]));
    }
    [$quando, $fuso] = sig_when($now);
    $url = public_link('verificar/' . $code);
    $m = sig_meta($c, $v, ['quando' => $quando, 'fuso' => $fuso, 'codigo' => $code, 'url' => $url], $req);
    if ($req) {
        // O PDF assinado pelo escritório fica intacto: o selo e a faixa do cliente entram como acréscimo.
        $office = (string) @file_get_contents(sig_storage_dir() . '/' . basename((string) $req['signed_path']));
        if ($office === '' || !hash_equals((string) $req['signed_sha256'], hash('sha256', $office))) {
            sig_log($code, 'divergencia_arquivo', ['arquivo' => 'PDF assinado pelo escritório ausente ou alterado']);
            return [null, 'O documento assinado pelo escritório não confere. Fale com Sidney.'];
        }
        $groups = [];
        $band = [BAND_X - 1.0, BAND_TOP - 1.0, BAND_MAX_W + 2.0, BAND_QR_Y + BAND_QR - BAND_TOP + 2.0];
        for ($i = 0; $i < $pages; $i++) {
            $groups[$i][] = ['draw' => sig_band_ops($m, $i + 1, $pages), 'rect' => $band, 'name' => 'Verificacao', 'title' => 'Assinaturas e verificação · ' . $code];
            $groups[$i][] = ['link' => $url, 'rect' => $band];
        }
        $groups[$seal['pagina'] - 1][] = ['draw' => pdoc_move(sig_seal_ops($m, $strokes), $seal['x'], $seal['y']), 'rect' => [$seal['x'], $seal['y'], SEAL_W, SEAL_H], 'name' => 'Assinatura', 'title' => 'Assinatura eletrônica de ' . $m['nome']];
        $groups[$seal['pagina'] - 1][] = ['link' => $url, 'rect' => [$seal['x'], $seal['y'], SEAL_W, SEAL_H]];
        $pdf = pdfx_add_annots($office, $groups, ['poppins', 'poppinsb', 'poppinssb']);
    } else {
        // A via definitiva: as páginas do documento, a faixa em todas e o selo onde o cliente pôs.
        $pdf = pdoc_pdf(sig_signed_layout($v, $m, $strokes, $seal), ['titulo' => $m['titulo'] . ' · ' . $m['protocolo'] . ' · v' . $m['versao'] . ' · assinado',
            'assunto' => 'Assinado eletronicamente por ' . $m['nome'] . ' em ' . $quando . ' (' . $fuso . ')', 'palavras' => 'verificação ' . $code . ' ' . $url, 'autor' => office('lawyer') ?: 'Sidney Fatuche']);
    }
    $sha = hash('sha256', $pdf);
    $file = $code . '.pdf';
    $path = sig_storage_dir() . '/' . $file;
    if (file_exists($path) || file_put_contents($path . '.tmp', $pdf, LOCK_EX) !== strlen($pdf) || !rename($path . '.tmp', $path)) {
        return [null, 'Não foi possível guardar a via assinada. Tente de novo.'];
    }
    @chmod($path, 0440);
    $ua = mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 400);
    $view = is_array($posIn) ? array_intersect_key($posIn, array_flip(['largura_folha_px', 'zoom', 'dpr', 'orientacao', 'tela'])) : [];
    $evidence = [
        'documento' => ['tipo' => $v['kind'], 'titulo' => kit_label($v['kind']), 'versao' => (int) $v['version'], 'teste' => $test, 'publicado_em' => $v['published_at'], 'hash_sha256_texto' => sig_doc_hash($v),
            'via_pdf' => ['hash_sha256' => $sha, 'bytes' => strlen($pdf), 'paginas' => $pages]],
        'assinante' => ['nome' => sig_expected_name($c), 'nome_digitado' => $nome, 'cpf' => $cpf, 'conta' => portal_account_id(), 'email' => $otp['to']],
        'autenticacao' => $test ? ['meio' => 'teste: cliente simulado por usuário da equipe no painel', 'usuario' => current_user()['id'] ?? null, 'sessao' => hash('sha256', session_id())]
            : ['meio' => 'conta da área do cliente (acesso por link enviado ao e-mail do cadastro)', 'conta' => portal_account_id(), 'sessao' => hash('sha256', session_id())],
        'email_confirmado' => ['para' => $otp['to'], 'codigo_enviado_em' => $otp['sent_at'], 'confirmado_em' => $now->format('Y-m-d H:i:s')],
        'momento' => ['brasilia' => $now->format('Y-m-d H:i:s'), 'utc' => $utc->format('Y-m-d\TH:i:s\Z'), 'fuso' => $now->format('P')],
        'rede' => ['ip' => client_ip(), 'encaminhado_por' => mb_substr((string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''), 0, 200) ?: null],
        'aparelho' => ['navegador' => $ua, 'idioma' => mb_substr((string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''), 0, 100),
            'fuso' => mb_substr((string) ($in['fuso'] ?? ''), 0, 60), 'tela' => mb_substr((string) ($in['tela'] ?? ''), 0, 40),
            'plataforma' => mb_substr((string) ($in['plataforma'] ?? ''), 0, 60)],
        'localizacao' => $geo,
        'selo' => $seal + ['largura_mm' => SEAL_W, 'altura_mm' => SEAL_H, 'folha_mm' => [PDOC_W, PDOC_H], 'rotacao_folha' => 0, 'visualizacao' => $view],
        'aceite' => SIGN_CONSENT,
    ];
    $sid = insert('doc_signatures', [
        'case_id' => $id, 'version_id' => $v['id'], 'code' => $code, 'signer_name' => sig_expected_name($c), 'signer_cpf' => $cpf, 'signer_email' => $otp['to'],
        'doc_hash' => sig_doc_hash($v), 'file_hash' => $sha, 'ip' => client_ip(), 'user_agent' => $ua, 'geo' => json_encode($geo),
        'evidence' => json_encode($evidence, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'signature_image' => $png, 'signed_at' => $now->format('Y-m-d H:i:s'),
        'pdf_path' => $file, 'pdf_sha256' => $sha, 'pdf_size' => strlen($pdf), 'pages' => $pages, 'verify_url' => $url,
        'seal' => json_encode($seal + ['meta' => $m], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'strokes' => json_encode($strokes),
    ]);
    sig_log($code, 'assinatura_registrada', ['versao_id' => (int) $v['id'], 'versao' => (int) $v['version'], 'hash_texto' => sig_doc_hash($v), 'hash_pdf' => $sha,
        'bytes' => strlen($pdf), 'paginas' => $pages, 'assinante' => sig_expected_name($c), 'assinado_em' => $now->format('Y-m-d H:i:s') . ' (' . $now->format('P') . ')', 'selo' => $seal]);
    if ($req) {
        q("UPDATE sig_requests SET status = 'concluido', updated_at = ? WHERE id = ?", [now_str(), $req['id']]);
    }
    if ($test) {
        return [one('SELECT * FROM doc_signatures WHERE id = ?', [$sid]), null];
    }
    // Cópia idêntica (mesmos bytes) nos arquivos do atendimento, que também vai para o Drive.
    $dir = config('storage_dir') . '/uploads/' . $id;
    is_dir($dir) || mkdir($dir, 0700, true);
    $stored = bin2hex(random_bytes(16)) . '.pdf';
    file_put_contents($dir . '/' . $stored, $pdf);
    $fid = insert('files', ['case_id' => $id, 'original_name' => doc_filename(kit_label($v['kind']), (string) $c['protocol'], (int) $v['version']) . '-assinado.pdf', 'stored_name' => $stored,
        'mime' => 'application/pdf', 'size' => strlen($pdf), 'uploaded_by_client' => 1, 'uploaded_at' => now_str(), 'kind' => 'assinado', 'internal' => 0]);
    q('UPDATE doc_signatures SET file_id = ? WHERE id = ?', [$fid, $sid]);
    q('UPDATE case_artifact_versions SET signed_file_id = ? WHERE id = ?', [$fid, $v['id']]);
    add_event($id, 'assinatura', 'Cliente assinou eletronicamente: ' . kit_label($v['kind']) . ' v' . $v['version'],
        'Código ' . $code . ' · IP ' . client_ip() . ' · localização: ' . sig_geo_text($geo) . ' · selo na página ' . $seal['pagina'] . '. Confira e registre a contratação.', true, 'cliente');
    $sig = one('SELECT * FROM doc_signatures WHERE id = ?', [$sid]);
    sig_mail_copy($c, $v, $sig, $pdf);
    // Todos os documentos assinados: a contratação passa sozinha a formalizada.
    if (contract_sync($id) !== 'formalizado') {
        $faltam = array_column(array_filter(contract_docs($id), fn($d) => !$d['sig']), 'label');
        set_next($id, $faltam ? 'Aguardar a assinatura do cliente: ' . implode(', ', $faltam) : 'Conferir a assinatura eletrônica (' . kit_label($v['kind']) . ')', in_business_days(1));
    }
    case_sync_later($id, false);
    return [$sig, null];
}

/** Nome e CPF conferidos com o documento e o cadastro. [nome, cpf, erro]. */
function sig_check_identity(array $c, array $in): array
{
    $nome = trim(preg_replace('/\s+/u', ' ', (string) ($in['nome'] ?? '')));
    if (sig_norm_name($nome) !== sig_norm_name(sig_expected_name($c))) {
        return [$nome, '', 'Digite o seu nome completo exatamente como está no documento: ' . sig_expected_name($c) . '.'];
    }
    $cpf = only_digits((string) ($in['cpf'] ?? ''));
    $cpfFicha = only_digits((string) fv((int) $c['id'], 'cpf'));
    if (!valid_cpf($cpf) || ($cpfFicha !== '' && $cpf !== $cpfFicha)) {
        return [$nome, $cpf, $cpfFicha !== '' ? 'O CPF não confere com o do cadastro.' : 'Informe um CPF válido.'];
    }
    return [$nome, $cpf, null];
}

/** Cópia da via assinada (o mesmo PDF guardado) para o e-mail do cliente. */
function sig_mail_copy(array $c, array $v, array $sig, string $pdf): void
{
    $to = (string) $sig['signer_email'];
    $label = kit_label($v['kind']);
    $lines = ['Olá, ' . first_name((string) $c['client_name']) . '! Recebemos a sua assinatura eletrônica do documento “' . $label . '” (versão ' . $v['version'] . ').',
        'Assinado em ' . fmt_datetime((string) $sig['signed_at']) . ' (horário de Brasília). Código de verificação: ' . $sig['code'] . '.',
        'A sua via assinada vai em anexo, em PDF. Ela também fica disponível na sua área do cliente.',
        'Para conferir a autenticidade, abra o endereço abaixo ou leia o QR Code impresso na margem do documento: ' . $sig['verify_url']];
    $href = $c['account_id'] ? client_access_link((int) $c['account_id'], (int) $c['id']) : public_link('cliente');
    [$ok, $err] = send_mail($to, 'Documento assinado: ' . $label . ' · ' . $c['protocol'], implode("\n\n", $lines) . "\n\nÁrea do cliente: " . $href,
        mail_html('Documento assinado', $lines, 'Abrir a área do cliente', $href), (int) $c['id'], 'assinatura',
        [[doc_filename($label, (string) $c['protocol'], (int) $v['version']) . '-assinado.pdf', 'application/pdf', $pdf]]);
    add_event((int) $c['id'], 'assinatura', $ok ? 'Via assinada enviada ao cliente por e-mail' : 'A via assinada não foi enviada por e-mail', $ok ? sig_mask_email($to) : $err);
    sig_log((string) $sig['code'], $ok ? 'via_enviada_email' : 'via_nao_enviada_email', ['para' => sig_mask_email($to), 'hash_anexo' => hash('sha256', $pdf)] + ($ok ? [] : ['erro' => $err]));
}
/* ---------- Contratação acompanha as assinaturas ---------- */

/**
 * Documentos que o cliente assina neste atendimento (contrato, procuração e,
 * se houver gratuidade, a declaração), com a situação de cada um: enviado
 * (versão mais recente publicada) e assinado (assinatura eletrônica dessa versão).
 */
function contract_docs(int $caseId): array
{
    $out = [];
    foreach (SIGNABLE_KINDS as $k) {
        if (!kit_applicable($caseId, $k)) {
            continue;
        }
        $v = kit_latest($caseId, $k);
        if ($v && $v['status'] === 'nao_aplicavel') {
            continue;
        }
        $sent = $v && $v['status'] === 'publicado';
        $out[$k] = ['label' => kit_label($k), 'version' => $v, 'sent' => $sent, 'sig' => $sent ? sig_for_version((int) $v['id']) : null];
    }
    return $out;
}

/**
 * Situação da contratação, derivada dos documentos: enviados ao cliente →
 * assinatura pendente; todos assinados eletronicamente → formalizado (com o
 * aceite registrado). Depois de formalizada, não volta atrás. Retorna a nova
 * situação, ou null se nada mudou.
 */
function contract_sync(int $caseId): ?string
{
    $c = find_case_or_null($caseId);
    if (!$c || $c['contract_state'] === 'formalizado' || ($c['initial_status'] ?? '') === 'completed') {
        return null;
    }
    $docs = contract_docs($caseId);
    if (!$docs || !array_filter($docs, fn($d) => $d['sent'])) {
        return null;
    }
    $signed = array_filter($docs, fn($d) => $d['sig']);
    if (count($signed) === count($docs)) {
        $last = max(array_map(fn($d) => (string) $d['sig']['signed_at'], $signed));
        $main = ($docs['honorarios']['sig'] ?? null) ?: reset($signed)['sig'];
        $f = ['contract_state' => 'formalizado', 'contract_file_id' => $main['file_id'], 'contract_signed_at' => substr($last, 0, 10), 'contract_verified_at' => now_str()];
        if ($c['decision'] !== 'aceitou') {
            $f += ['decision' => 'aceitou', 'decision_at' => now_str()];
            add_event($caseId, 'decisao', 'Decisão: ' . DECISIONS['aceitou'], 'Aceite confirmado pela assinatura eletrônica dos documentos.', true, 'sistema');
        }
        bump_case($caseId, $f);
        add_event($caseId, 'contratacao', 'Contratação: ' . CONTRACT_STATES['formalizado'],
            'Todos os documentos foram assinados eletronicamente pelo cliente: ' . implode('; ', array_map(fn($d) => $d['label'] . ' v' . $d['version']['version'] . ' (código ' . $d['sig']['code'] . ')', $signed)) . '.', true, 'sistema');
        set_next($caseId, 'Finalizar o atendimento inicial e iniciar a execução contratada', in_business_days(1));
        return 'formalizado';
    }
    if ($c['contract_state'] !== 'assinatura_pendente') {
        bump_case($caseId, ['contract_state' => 'assinatura_pendente']);
        add_event($caseId, 'contratacao', 'Contratação: ' . CONTRACT_STATES['assinatura_pendente'], 'Documentos enviados ao cliente para assinatura eletrônica.', true, 'sistema');
        return 'assinatura_pendente';
    }
    return null;
}

/* ---------- Retificação pedida pelo cliente ---------- */

function rect_open(int $caseId): array
{
    return all("SELECT r.*, v.kind, v.version FROM doc_rectifications r JOIN case_artifact_versions v ON v.id = r.version_id WHERE r.case_id = ? ORDER BY r.status = 'aberta' DESC, r.id DESC", [$caseId]);
}

/** Registra o pedido do cliente e pede a avaliação da IA depois da resposta. Retorna erro ou null. */
function rect_request(array $c, array $v, string $text): ?string
{
    $text = trim(mb_substr(str_replace("\r\n", "\n", $text), 0, 3000));
    if (mb_strlen($text) < 10) {
        return 'Explique o que está errado ou o que precisa mudar.';
    }
    if (sig_for_version((int) $v['id'])) {
        return 'Este documento já foi assinado. Para mudar algo, fale com Sidney pela conversa.';
    }
    $bucket = 'retificacao:' . $v['id'];
    if (too_many_attempts($bucket, 5, 60)) {
        return 'Muitos pedidos seguidos. Aguarde ou escreva na conversa.';
    }
    record_attempt($bucket);
    $rid = insert('doc_rectifications', ['case_id' => $c['id'], 'version_id' => $v['id'], 'body' => $text, 'ip' => client_ip(), 'created_at' => now_str()]);
    add_event((int) $c['id'], 'assinatura', 'Cliente pediu retificação: ' . kit_label($v['kind']) . ' v' . $v['version'], $text, true, 'cliente');
    set_next((int) $c['id'], 'Ver a retificação pedida no ' . kit_label($v['kind']), in_business_days(1));
    after_response(fn() => rect_ai_review($rid));
    return null;
}

/** Avaliação da IA sobre o pedido de retificação (fica no Kit, para Sidney). */
function rect_ai_review(int $rid): ?string
{
    $r = one('SELECT r.*, v.kind, v.version, v.content FROM doc_rectifications r JOIN case_artifact_versions v ON v.id = r.version_id WHERE r.id = ?', [$rid]);
    if (!$r || !llm_enabled()) {
        return 'A IA está desligada.';
    }
    $res = llm_chat([
        ['role' => 'system', 'content' => 'Você assessora o advogado Sidney Fatuche. O cliente pediu uma retificação num documento que vai assinar. '
            . 'Compare o pedido com o texto do documento e com a ficha do caso. Não altere nada: só avalie para Sidney decidir. Português do Brasil, objetivo. '
            . 'Responda SOMENTE com JSON: {"avaliacao":"procedente|parcial|improcedente|duvida","observacao":"2 a 4 frases para Sidney","trecho":"trecho do documento afetado (curto) ou \"\"","sugestao":"texto sugerido para o trecho, ou \"\""}'],
        ['role' => 'user', 'content' => 'DOCUMENTO (' . kit_label($r['kind']) . ' v' . $r['version'] . "):\n" . mb_substr((string) $r['content'], 0, 40000)
            . "\n\nFICHA:\n" . case_facts_text((int) $r['case_id']) . "\n\nPEDIDO DO CLIENTE:\n" . $r['body']],
    ], (int) $r['case_id'], 'kit:retificacao', 900, 90);
    $j = $res['ok'] ? llm_json($res['content']) : null;
    if (!$j) {
        return 'A IA não respondeu no formato esperado.';
    }
    $note = ['avaliacao' => in_array($j['avaliacao'] ?? '', ['procedente', 'parcial', 'improcedente', 'duvida'], true) ? $j['avaliacao'] : 'duvida',
        'observacao' => mb_substr(trim((string) ($j['observacao'] ?? '')), 0, 2000), 'trecho' => mb_substr(trim((string) ($j['trecho'] ?? '')), 0, 1000),
        'sugestao' => mb_substr(trim((string) ($j['sugestao'] ?? '')), 0, 2000), 'at' => now_str()];
    q('UPDATE doc_rectifications SET ai_note = ? WHERE id = ?', [json_encode($note, JSON_UNESCAPED_UNICODE), $rid]);
    return null;
}

const RECT_AVALIACAO = ['procedente' => 'Procede', 'parcial' => 'Procede em parte', 'improcedente' => 'Não procede', 'duvida' => 'Precisa conferir'];

/** Tipos de documento que o cliente assina (o parecer é só para leitura). */
const SIGNABLE_KINDS = ['honorarios', 'procuracao', 'hipossuficiencia'];

/** Folhas em SVG (a mesma diagramação do PDF). */
function sig_sheets_html(array $L, bool $links = false): string
{
    $out = '';
    foreach ($L['pages'] as $i => $ops) {
        $out .= '<div class="folha" data-pagina="' . ($i + 1) . '"><svg viewBox="0 0 ' . PDOC_W . ' ' . PDOC_H . '" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Página ' . ($i + 1) . ' de ' . count($L['pages']) . '">'
            . pdoc_svg($ops, ['links' => $links]) . '</svg></div>';
    }
    return $out;
}

/** Página com as folhas: o documento como sai no PDF. */
function sig_sheet_page(string $title, string $body, array $opts = []): string
{
    return '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex, nofollow"><title>' . e($title) . '</title><style>' . doc_font_faces() . '</style>'
        . '<link rel="stylesheet" href="' . e(asset('formatador/documento.css')) . '"><link rel="stylesheet" href="' . e(asset('css/folhas.css')) . '"></head>'
        . '<body class="folhas-pagina' . (!empty($opts['noprint']) ? ' -sem-impressao' : '') . '">' . $body
        . implode('', array_map(fn($u) => '<script src="' . e($u) . '"></script>', $opts['scripts'] ?? [])) . '</body></html>';
}

/**
 * Documento para assinar (área do cliente): as folhas como vão sair no PDF, a
 * prévia da faixa lateral e, ao assinar, o selo para arrastar até o lugar.
 */
function sig_sign_page(array $c, array $v, array $opts = []): string
{
    $req = sig_request_open($c, $v);
    $top = $opts['aviso'] ?? '';
    $m = sig_meta($c, $v, null, $req);
    $L = sig_signed_layout($v, $m, null, null, $req);
    if ($req && $req['status'] !== 'cliente') {
        // o escritório ainda assina: o cliente lê e pode pedir retificação
        $faltam = array_map(fn($p) => SIG_PARTY_LABELS[$p['role']] . ($p['role'] === 'testemunha' ? ' ' . $p['ord'] : ''), array_filter(sig_parties((int) $req['id']), fn($p) => !$p['signed_at']));
        return sig_sheet_page(kit_label($v['kind']) . ' · ' . $c['protocol'],
            $top . '<header class="folhas-topo"><strong>' . e(kit_label($v['kind'])) . '</strong><span>versão ' . e(sig_vtxt($v)) . ' · atendimento ' . e($c['protocol']) . '</span></header>'
            . '<main class="folhas">' . sig_sheets_html($L) . '</main>'
            . sig_client_panel($c, $v, 'O escritório está assinando este documento (' . implode(', ', $faltam) . '). Você recebe um aviso quando ele estiver pronto para a sua assinatura; enquanto isso, leia com calma e, se algo estiver errado, peça retificação.', $opts),
            ['noprint' => true, 'scripts' => [asset('js/assinatura.js')]]);
    }
    $label = kit_label($v['kind']);
    $seal = sig_seal_default($c, $v);
    $dados = ['selo' => ['w' => SEAL_W, 'h' => SEAL_H, 'minX' => SEAL_MIN_X, 'folhaW' => PDOC_W, 'folhaH' => PDOC_H, 'tinta' => SEAL_INK] + $seal, 'paginas' => count($L['pages'])];
    $json = json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
    $sealSvg = '<svg viewBox="0 0 ' . SEAL_W . ' ' . SEAL_H . '" xmlns="http://www.w3.org/2000/svg">' . pdoc_svg(sig_seal_ops($m, null)) . '<g data-rubrica></g></svg>';
    return sig_sheet_page($label . ' · ' . $c['protocol'] . ' · v' . sig_vtxt($v),
        $top . '<header class="folhas-topo"><strong>' . e($label) . '</strong><span>versão ' . e(sig_vtxt($v)) . ' · atendimento ' . e($c['protocol']) . ' · ' . count($L['pages']) . ' ' . (count($L['pages']) === 1 ? 'página' : 'páginas') . '</span></header>'
        . '<main class="folhas" data-folhas>' . sig_sheets_html($L) . '</main>'
        . '<template id="selo-modelo">' . $sealSvg . '</template>'
        . '<script type="application/json" id="assin-dados">' . $json . '</script>'
        . sig_client_panel($c, $v, null, $opts),
        ['noprint' => true, 'scripts' => [asset('js/assinatura.js')]]);
}

/** Via assinada na área do cliente: as folhas com o selo e a faixa, e o PDF original para baixar. */
function sig_view_page(array $c, array $v, array $sig, bool $justSigned, array $opts = []): string
{
    $seal = json_decode((string) $sig['seal'], true) ?: [];
    $L = sig_signed_layout($v, $seal['meta'] ?? sig_meta($c, $v), json_decode((string) $sig['strokes'], true), $seal, one('SELECT * FROM sig_requests WHERE version_id = ? AND status = ?', [$v['id'], 'concluido']));
    $base = $opts['pdf'] ?? url('cliente/documento-kit/' . $v['id'] . '/pdf');
    $top = '<header class="folhas-topo -assinado"><strong>' . e(kit_label($v['kind'])) . ' · assinado</strong><span>' . e(fmt_datetime((string) $sig['signed_at'])) . ' · código ' . e($sig['code']) . '</span>'
        . '<nav><a class="assin-btn" href="' . e($base . '?baixar=1') . '">Baixar o PDF assinado</a><a class="assin-btn -sec" href="' . e($base) . '" target="_blank" rel="noopener">Abrir o PDF</a>'
        . '<a class="assin-btn -sec" href="' . e((string) $sig['verify_url']) . '" target="_blank" rel="noopener">Conferir</a></nav></header>';
    $done = $justSigned ? '<div class="assin-feito"><span><strong>Documento assinado.</strong> ' . (isset($opts['pdf']) ? 'Teste concluído: todos assinaram. Baixe o PDF e confira as assinaturas.' : 'Uma cópia em PDF foi para o seu e-mail. Baixe a sua via agora, se quiser.') . '</span>'
        . '<a class="assin-btn" href="' . e($base . '?baixar=1') . '">Baixar o PDF</a></div>' : '';
    return sig_sheet_page(kit_label($v['kind']) . ' · ' . $c['protocol'] . ' · assinado', ($opts['aviso'] ?? '') . $done . $top . '<main class="folhas">' . sig_sheets_html($L, true) . '</main>');
}

/** Barra e janelas: dados e rubrica → posicionar o selo → código e aceite. Retificação à parte. */
function sig_client_panel(array $c, array $v, ?string $waiting = null, array $opts = []): string
{
    $base = $opts['base'] ?? url('cliente/documento-kit/' . $v['id']);
    $email = $opts['email'] ?? (string) $c['client_email'];
    $test = isset($opts['base']);
    $cpf = only_digits((string) fv((int) $c['id'], 'cpf'));
    $rect = one("SELECT * FROM doc_rectifications WHERE version_id = ? AND status = 'aberta' ORDER BY id DESC LIMIT 1", [$v['id']]);
    $tok = csrf_field();
    return '<div class="assin" data-assin data-base="' . e($base) . '">'
        . '<div class="assin-barra" data-barra="inicio"><p>' . ($rect ? '<strong>Retificação enviada em ' . e(fmt_datetime($rect['created_at'])) . '.</strong> Sidney vai revisar' . ($waiting ? '.' : '; você ainda pode assinar se preferir.')
            : ($waiting !== null ? e($waiting) : 'Leia o documento com atenção. Você pode <strong>assinar</strong> ou <strong>pedir retificação</strong>.')) . '</p>'
        . '<div class="assin-acoes">' . ($test ? '' : '<button type="button" class="assin-btn -sec" data-abrir="retificar">Pedir retificação</button>')
        . ($waiting === null ? '<button type="button" class="assin-btn" data-abrir="assinar">Assinar documento</button>' : '') . '</div></div>'

        . '<div class="assin-barra -selo" data-barra="selo" hidden><p><strong>Arraste o selo</strong> até o lugar da sua assinatura — em qualquer página. <span data-selo-onde></span></p>'
        . '<div class="assin-acoes"><button type="button" class="assin-btn -sec" data-voltar>Voltar</button><button type="button" class="assin-btn" data-confirmar-selo>Confirmar a posição</button></div></div>'

        . '<div class="assin-modal" data-janela="assinar" hidden role="dialog" aria-modal="true" aria-labelledby="assin-t"><form class="assin-caixa" data-form="assinar" novalidate>' . $tok
        . '<p class="assin-passo">Passo 1 de 3</p><h2 id="assin-t">Assinar: ' . e(kit_label($v['kind'])) . '</h2>'
        . '<p class="assin-sub">Versão ' . e(sig_vtxt($v)) . ' · atendimento ' . e($c['protocol']) . '. Assinatura eletrônica com confirmação por e-mail.</p>'
        . '<label class="assin-fld"><span>Seu nome completo</span><input name="nome" autocomplete="name" required placeholder="' . e(sig_expected_name($c)) . '"></label>'
        . '<label class="assin-fld"><span>CPF</span><input name="cpf" inputmode="numeric" autocomplete="off" required' . ($cpf ? ' placeholder="•••.•••.' . e(substr($cpf, 6, 3)) . '-' . e(substr($cpf, 9)) . '"' : '') . '></label>'
        . '<div class="assin-fld"><span>Rubrica <small>(desenhe com o dedo ou o mouse)</small></span><canvas class="assin-pad" width="600" height="180" data-pad aria-label="Quadro para desenhar a rubrica"></canvas>'
        . '<button type="button" class="assin-link" data-limpar>Limpar</button></div>'
        . '<p class="assin-erro" data-erro role="alert"></p>'
        . '<div class="assin-acoes"><button type="button" class="assin-btn -sec" data-fechar>Cancelar</button><button type="submit" class="assin-btn">Continuar: posicionar o selo</button></div>'
        . '</form></div>'

        . '<div class="assin-modal" data-janela="confirmar" hidden role="dialog" aria-modal="true" aria-labelledby="conf-t"><form class="assin-caixa" data-form="confirmar" novalidate>' . $tok
        . '<p class="assin-passo">Passo 3 de 3</p><h2 id="conf-t">Confirmar a assinatura</h2>'
        . '<p class="assin-sub" data-selo-resumo></p>'
        . '<div class="assin-fld"><span>Código enviado para ' . e($email ? sig_mask_email($email) : 'o seu e-mail') . '</span>'
        . '<div class="assin-codigo"><input name="codigo" inputmode="numeric" maxlength="6" autocomplete="one-time-code" placeholder="000000"><button type="button" class="assin-btn -sec" data-codigo>Enviar código</button></div></div>'
        . '<label class="assin-ok"><input type="checkbox" name="aceite" value="1"> ' . e(SIGN_CONSENT) . '</label>'
        . '<p class="assin-geo" data-geo>Ao assinar, o navegador pode pedir para usar a sua localização. Ela é guardada como prova da assinatura. A data e a hora são as do servidor no momento da conclusão.</p>'
        . '<p class="assin-erro" data-erro role="alert"></p>'
        . '<div class="assin-acoes"><button type="button" class="assin-btn -sec" data-ajustar>Ajustar o selo</button><button type="submit" class="assin-btn">Assinar agora</button></div>'
        . '</form></div>'

        . '<div class="assin-modal" data-janela="retificar" hidden role="dialog" aria-modal="true" aria-labelledby="ret-t"><form class="assin-caixa" data-form="retificar" novalidate>' . $tok
        . '<h2 id="ret-t">Pedir retificação</h2><p class="assin-sub">Diga o que está errado ou o que precisa mudar. Sidney recebe o seu pedido e revisa o documento.</p>'
        . '<label class="assin-fld"><span>O que precisa ser corrigido?</span><textarea name="texto" rows="6" maxlength="3000" required placeholder="Ex.: meu estado civil é casado; o valor da parcela está diferente"></textarea></label>'
        . '<p class="assin-erro" data-erro role="alert"></p>'
        . '<div class="assin-acoes"><button type="button" class="assin-btn -sec" data-fechar>Cancelar</button><button type="submit" class="assin-btn">Enviar pedido</button></div>'
        . '</form></div></div>';
}
