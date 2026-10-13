<?php
declare(strict_types=1);

/**
 * Assinatura eletrônica dos documentos do kit pelo cliente, sem certificado
 * digital: identificação pela conta da área do cliente, confirmação do nome e
 * do CPF, código de uso único enviado ao e-mail do cadastro, rubrica desenhada
 * na tela e o aceite expresso. Ficam registrados o IP, o navegador, a data e
 * hora do servidor, o fuso e, se o cliente permitir, a localização do aparelho,
 * além do hash SHA-256 do texto assinado. A via assinada leva uma folha com
 * todas essas evidências e um código para conferência pública (/verificar).
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

/** Envia o código de uso único ao e-mail do cadastro. Retorna erro ou null. */
function sig_send_code(array $c, array $v): ?string
{
    $email = (string) $c['client_email'];
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
    $lines = ['Seu código para assinar o documento “' . $label . '” (versão ' . $v['version'] . ', atendimento ' . $c['protocol'] . ') é: ' . $code,
        'Ele vale por ' . SIGN_CODE_MINUTES . ' minutos e só pode ser usado uma vez. Se você não pediu este código, ignore este e-mail e avise Sidney.'];
    [$ok, $err] = send_mail($email, 'Código para assinar: ' . $code, implode("\n\n", $lines), mail_html('Código para assinar o documento', $lines), (int) $c['id'], 'assinatura');
    if (!$ok) {
        unset($_SESSION['sig_code'][$v['id']]);
        return 'Não foi possível enviar o código agora: ' . $err;
    }
    add_event((int) $c['id'], 'assinatura', 'Código de assinatura enviado', $label . ' v' . $v['version'] . ' · ' . sig_mask_email($email), false, 'cliente');
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

/**
 * Assina a versão publicada. $in: nome, cpf, codigo, aceite, rubrica (PNG),
 * geo_* e dados do aparelho. Retorna [assinatura, erro].
 */
function sig_sign(array $c, array $v, array $in): array
{
    $id = (int) $c['id'];
    if ($v['status'] !== 'publicado') {
        return [null, 'Este documento não está disponível para assinatura.'];
    }
    if (sig_for_version((int) $v['id'])) {
        return [null, 'Este documento já foi assinado.'];
    }
    if (($in['aceite'] ?? '') !== '1') {
        return [null, 'Marque que leu e concorda com o documento.'];
    }
    $nome = trim(preg_replace('/\s+/u', ' ', (string) ($in['nome'] ?? '')));
    if (sig_norm_name($nome) !== sig_norm_name(sig_expected_name($c))) {
        return [null, 'Digite o seu nome completo exatamente como está no documento: ' . sig_expected_name($c) . '.'];
    }
    $cpf = only_digits((string) ($in['cpf'] ?? ''));
    $cpfFicha = only_digits((string) fv($id, 'cpf'));
    if (!valid_cpf($cpf) || ($cpfFicha !== '' && $cpf !== $cpfFicha)) {
        return [null, $cpfFicha !== '' ? 'O CPF não confere com o do cadastro.' : 'Informe um CPF válido.'];
    }
    $png = (string) ($in['rubrica'] ?? '');
    if (!sig_image_ok($png)) {
        return [null, 'Faça a sua rubrica no quadro de assinatura.'];
    }
    [$otp, $err] = sig_check_code($v, (string) ($in['codigo'] ?? ''));
    if (!$otp) {
        return [null, $err];
    }
    if ($cpfFicha === '') {
        set_field($id, 'cpf', $cpf, 'informado', 'cliente');
    }
    $now = now();
    $utc = $now->setTimezone(new DateTimeZone('UTC'));
    $geo = sig_geo($in);
    $code = strtoupper(implode('-', str_split(substr(bin2hex(random_bytes(6)), 0, 12), 4)));
    $ua = mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 400);
    $evidence = [
        'documento' => ['tipo' => $v['kind'], 'titulo' => kit_label($v['kind']), 'versao' => (int) $v['version'], 'publicado_em' => $v['published_at'], 'hash_sha256' => sig_doc_hash($v)],
        'assinante' => ['nome' => sig_expected_name($c), 'nome_digitado' => $nome, 'cpf' => $cpf, 'conta' => portal_account_id(), 'email' => $otp['to']],
        'email_confirmado' => ['para' => $otp['to'], 'codigo_enviado_em' => $otp['sent_at'], 'confirmado_em' => $now->format('Y-m-d H:i:s')],
        'momento' => ['brasilia' => $now->format('Y-m-d H:i:s'), 'utc' => $utc->format('Y-m-d\TH:i:s\Z')],
        'rede' => ['ip' => client_ip(), 'encaminhado_por' => mb_substr((string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''), 0, 200) ?: null],
        'aparelho' => ['navegador' => $ua, 'idioma' => mb_substr((string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''), 0, 100),
            'fuso' => mb_substr((string) ($in['fuso'] ?? ''), 0, 60), 'tela' => mb_substr((string) ($in['tela'] ?? ''), 0, 40),
            'plataforma' => mb_substr((string) ($in['plataforma'] ?? ''), 0, 60)],
        'localizacao' => $geo,
        'sessao' => hash('sha256', session_id()),
        'aceite' => SIGN_CONSENT,
    ];
    $sid = insert('doc_signatures', [
        'case_id' => $id, 'version_id' => $v['id'], 'code' => $code, 'signer_name' => sig_expected_name($c), 'signer_cpf' => $cpf, 'signer_email' => $otp['to'],
        'doc_hash' => sig_doc_hash($v), 'ip' => client_ip(), 'user_agent' => $ua, 'geo' => json_encode($geo),
        'evidence' => json_encode($evidence, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'signature_image' => $png, 'signed_at' => $now->format('Y-m-d H:i:s'),
    ]);
    $sig = one('SELECT * FROM doc_signatures WHERE id = ?', [$sid]);
    // Via assinada (documento + folha de evidências), guardada nos arquivos do atendimento.
    $html = sig_signed_page($c, $v, $sig, false);
    $dir = config('storage_dir') . '/uploads/' . $id;
    is_dir($dir) || mkdir($dir, 0700, true);
    $stored = bin2hex(random_bytes(16)) . '.html';
    file_put_contents($dir . '/' . $stored, $html);
    $name = doc_filename(kit_label($v['kind']), (string) $c['protocol'], (int) $v['version']) . ' - assinado.html';
    $fid = insert('files', ['case_id' => $id, 'original_name' => $name, 'stored_name' => $stored, 'mime' => 'text/html', 'size' => strlen($html),
        'uploaded_by_client' => 1, 'uploaded_at' => now_str(), 'kind' => 'assinado', 'internal' => 0]);
    q('UPDATE doc_signatures SET file_id = ?, file_hash = ? WHERE id = ?', [$fid, hash('sha256', $html), $sid]);
    q('UPDATE case_artifact_versions SET signed_file_id = ? WHERE id = ?', [$fid, $v['id']]);
    add_event($id, 'assinatura', 'Cliente assinou eletronicamente: ' . kit_label($v['kind']) . ' v' . $v['version'],
        'Código ' . $code . ' · IP ' . client_ip() . ' · localização: ' . sig_geo_text($geo) . '. Confira e registre a contratação.', true, 'cliente');
    set_next($id, 'Conferir a assinatura eletrônica (' . kit_label($v['kind']) . ') e registrar a contratação', in_business_days(1));
    $sig = one('SELECT * FROM doc_signatures WHERE id = ?', [$sid]);
    sig_mail_copy($c, $v, $sig, $html);
    case_sync_later($id, false);
    return [$sig, null];
}

/** Folha de evidências, em marcação de documento. */
function sig_certificate_markup(array $c, array $v, array $sig): string
{
    $ev = json_decode((string) $sig['evidence'], true) ?: [];
    $s = dt($sig['signed_at']);
    $rows = [
        'Documento' => kit_label($v['kind']) . ' · versão ' . $v['version'] . ' · atendimento ' . $c['protocol'],
        'Código de verificação' => $sig['code'],
        'Conferência' => public_link('verificar/' . $sig['code']),
        'Hash SHA-256 do texto assinado' => $sig['doc_hash'],
        'Assinante' => $sig['signer_name'],
        'CPF' => sig_fmt_cpf((string) $sig['signer_cpf']),
        'E-mail confirmado' => sig_mask_email((string) $sig['signer_email']) . ' (código enviado em ' . fmt_datetime((string) ($ev['email_confirmado']['codigo_enviado_em'] ?? '')) . ' e confirmado na assinatura)',
        'Data e hora' => $s->format('d/m/Y \à\s H:i:s') . ' (Brasília) · ' . ($ev['momento']['utc'] ?? ''),
        'IP' => $sig['ip'],
        'Navegador' => $ev['aparelho']['navegador'] ?? '',
        'Fuso e tela do aparelho' => trim(($ev['aparelho']['fuso'] ?? '') . ' · ' . ($ev['aparelho']['tela'] ?? ''), ' ·'),
        'Localização' => sig_geo_text($ev['localizacao'] ?? []),
    ];
    $dados = '';
    foreach ($rows as $k => $val) {
        $dados .= $k . ': ' . sig_plain((string) $val) . "\n";
    }
    return "@quebra\n## Registro de assinatura eletrônica\n\n@dados\n" . trim($dados)
        . "\n\n@observacao Aceite registrado: “" . SIGN_CONSENT . "”"
        . "\n\nA autoria foi confirmada pela conta do assinante na área do cliente, pelo nome e CPF digitados e pelo código de uso único enviado ao e-mail do cadastro. A integridade do texto pode ser conferida pelo hash acima e pelo código de verificação no endereço indicado. Rubrica desenhada pelo assinante:";
}

/** Página da via assinada: documento, folha de evidências e rubrica. */
function sig_signed_page(array $c, array $v, array $sig, bool $withPrint, bool $justSigned = false): string
{
    $blocks = doc_blocks((string) $v['content'] . "\n\n" . sig_certificate_markup($c, $v, $sig));
    $img = '<p class="doc-centro assin-rubrica"><img src="' . e((string) $sig['signature_image']) . '" alt="Rubrica de ' . e($sig['signer_name']) . '"></p>'
        . '<p class="doc-centro assin-rubrica__nome">' . e($sig['signer_name']) . ' · ' . e(fmt_datetime((string) $sig['signed_at'])) . ' · ' . e($sig['code']) . '</p>';
    return doc_page(kit_label($v['kind']) . ' · ' . $c['protocol'] . ' · v' . $v['version'] . ' · assinado', $blocks,
        ['Imprimir / salvar em PDF' => 'print'], null, ['append' => $img, 'base' => $withPrint ? null : rtrim(public_root(), '/') . '/',
            'after' => $justSigned ? '<div class="assin-feito"><span><strong>Documento assinado.</strong> Uma cópia foi para o seu e-mail. Salve a sua via em PDF agora.</span><button class="assin-btn" type="button" data-imprimir>Salvar em PDF</button></div>' : '']);
}

/** Cópia da via assinada para o e-mail do cliente (anexo .html, que abre e imprime em PDF). */
function sig_mail_copy(array $c, array $v, array $sig, string $html): void
{
    $to = (string) $sig['signer_email'];
    $label = kit_label($v['kind']);
    $lines = ['Olá, ' . first_name((string) $c['client_name']) . '! Recebemos a sua assinatura eletrônica do documento “' . $label . '” (versão ' . $v['version'] . ').',
        'Assinado em ' . fmt_datetime((string) $sig['signed_at']) . ' (horário de Brasília). Código de verificação: ' . $sig['code'] . '.',
        'A sua via assinada vai em anexo: abra o arquivo no navegador e use “Imprimir / salvar em PDF”. Ela também fica disponível na sua área do cliente.',
        'Para conferir a autenticidade: ' . public_link('verificar/' . $sig['code'])];
    $href = $c['account_id'] ? client_access_link((int) $c['account_id'], (int) $c['id']) : public_link('cliente');
    [$ok, $err] = send_mail($to, 'Documento assinado: ' . $label . ' · ' . $c['protocol'], implode("\n\n", $lines) . "\n\nÁrea do cliente: " . $href,
        mail_html('Documento assinado', $lines, 'Abrir a área do cliente', $href), (int) $c['id'], 'assinatura',
        [[doc_filename($label, (string) $c['protocol'], (int) $v['version']) . ' - assinado.html', 'text/html; charset=UTF-8', $html]]);
    add_event((int) $c['id'], 'assinatura', $ok ? 'Via assinada enviada ao cliente por e-mail' : 'A via assinada não foi enviada por e-mail', $ok ? sig_mask_email($to) : $err);
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

/** Barra e janelas de assinatura e retificação na página do documento (área do cliente). */
function sig_client_panel(array $c, array $v): string
{
    $base = url('cliente/documento-kit/' . $v['id']);
    $email = (string) $c['client_email'];
    $cpf = only_digits((string) fv((int) $c['id'], 'cpf'));
    $rect = one("SELECT * FROM doc_rectifications WHERE version_id = ? AND status = 'aberta' ORDER BY id DESC LIMIT 1", [$v['id']]);
    $tok = csrf_field();
    return '<div class="assin" data-assin data-base="' . e($base) . '">'
        . '<div class="assin-barra"><p>' . ($rect ? '<strong>Retificação enviada em ' . e(fmt_datetime($rect['created_at'])) . '.</strong> Sidney vai revisar; você ainda pode assinar se preferir.'
            : 'Leia o documento com atenção. Você pode <strong>assinar</strong> ou <strong>pedir retificação</strong>.') . '</p>'
        . '<div class="assin-acoes"><button type="button" class="assin-btn -sec" data-abrir="retificar">Pedir retificação</button>'
        . '<button type="button" class="assin-btn" data-abrir="assinar">Assinar documento</button></div></div>'

        . '<div class="assin-modal" data-janela="assinar" hidden role="dialog" aria-modal="true" aria-labelledby="assin-t"><form class="assin-caixa" data-form="assinar" novalidate>' . $tok
        . '<h2 id="assin-t">Assinar: ' . e(kit_label($v['kind'])) . '</h2>'
        . '<p class="assin-sub">Versão ' . (int) $v['version'] . ' · atendimento ' . e($c['protocol']) . '. Assinatura eletrônica com confirmação por e-mail.</p>'
        . '<label class="assin-fld"><span>Seu nome completo</span><input name="nome" autocomplete="name" required placeholder="' . e(sig_expected_name($c)) . '"></label>'
        . '<label class="assin-fld"><span>CPF</span><input name="cpf" inputmode="numeric" autocomplete="off" required' . ($cpf ? ' placeholder="•••.•••.' . e(substr($cpf, 6, 3)) . '-' . e(substr($cpf, 9)) . '"' : '') . '></label>'
        . '<div class="assin-fld"><span>Rubrica <small>(desenhe com o dedo ou o mouse)</small></span><canvas class="assin-pad" width="600" height="180" data-pad aria-label="Quadro para desenhar a rubrica"></canvas>'
        . '<button type="button" class="assin-link" data-limpar>Limpar</button></div>'
        . '<div class="assin-fld"><span>Código enviado para ' . e($email ? sig_mask_email($email) : 'o seu e-mail') . '</span>'
        . '<div class="assin-codigo"><input name="codigo" inputmode="numeric" maxlength="6" autocomplete="one-time-code" placeholder="000000"><button type="button" class="assin-btn -sec" data-codigo>Enviar código</button></div></div>'
        . '<label class="assin-ok"><input type="checkbox" name="aceite" value="1"> ' . e(SIGN_CONSENT) . '</label>'
        . '<p class="assin-geo" data-geo>Ao assinar, o navegador pode pedir para usar a sua localização. Ela é guardada como prova da assinatura.</p>'
        . '<p class="assin-erro" data-erro role="alert"></p>'
        . '<div class="assin-acoes"><button type="button" class="assin-btn -sec" data-fechar>Cancelar</button><button type="submit" class="assin-btn">Assinar agora</button></div>'
        . '</form></div>'

        . '<div class="assin-modal" data-janela="retificar" hidden role="dialog" aria-modal="true" aria-labelledby="ret-t"><form class="assin-caixa" data-form="retificar" novalidate>' . $tok
        . '<h2 id="ret-t">Pedir retificação</h2><p class="assin-sub">Diga o que está errado ou o que precisa mudar. Sidney recebe o seu pedido e revisa o documento.</p>'
        . '<label class="assin-fld"><span>O que precisa ser corrigido?</span><textarea name="texto" rows="6" maxlength="3000" required placeholder="Ex.: meu estado civil é casado; o valor da parcela está diferente"></textarea></label>'
        . '<p class="assin-erro" data-erro role="alert"></p>'
        . '<div class="assin-acoes"><button type="button" class="assin-btn -sec" data-fechar>Cancelar</button><button type="submit" class="assin-btn">Enviar pedido</button></div>'
        . '</form></div></div>';
}
