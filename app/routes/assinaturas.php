<?php
declare(strict_types=1);

/**
 * Painel: assinaturas da equipe (testemunhas e advogado) e os dados de cada
 * integrante (qualificação, rubrica mecânica e certificado digital).
 */

/* ---------- Minha conta: qualificação, rubrica e certificado ---------- */

$router->post('/painel/conta/dados', function () {
    $u = require_user();
    $err = team_save_profile($u, $_POST);
    flash($err ? 'error' : 'ok', $err ?? 'Dados atualizados.');
    redirect('painel/conta#dados');
});

$router->post('/painel/conta/rubrica', function () {
    $u = require_user();
    $s = sig_strokes((string) input('tracos'));
    if (!$s) {
        flash('error', 'Desenhe a rubrica no quadro antes de salvar.');
        redirect('painel/conta#rubrica');
    }
    update('users', (int) $u['id'], ['signature_strokes' => json_encode($s), 'signature_at' => now_str()]);
    audit('rubrica_atualizada');
    flash('ok', 'Rubrica salva. Ela vai nos seus próximos selos de assinatura.');
    redirect('painel/conta#rubrica');
});

$router->post('/painel/conta/certificado', function () {
    $u = require_user();
    $f = $_FILES['certificado'] ?? null;
    $bin = $f && ($f['error'] ?? 1) === UPLOAD_ERR_OK ? (string) file_get_contents($f['tmp_name']) : '';
    $err = team_cert_store($u, $bin, (string) input('senha'));
    flash($err ? 'error' : 'ok', $err ?? 'Certificado cadastrado. A senha não fica guardada: ela é pedida a cada assinatura.');
    redirect('painel/conta#certificado');
});

$router->post('/painel/conta/certificado-a3', function () {
    $u = require_user();
    $f = $_FILES['arquivo'] ?? null;
    $bin = $f && ($f['error'] ?? 1) === UPLOAD_ERR_OK ? (string) file_get_contents($f['tmp_name']) : '';
    $err = team_cert_register_a3($u, $bin);
    flash($err ? 'error' : 'ok', $err ?? 'Certificado A3 cadastrado. Para assinar, você baixa o PDF, assina no token e envia de volta.');
    redirect('painel/conta#certificado');
});

$router->get('/painel/conta/certificado-a3/pdf', function () {
    $u = require_user();
    $pdf = team_cert_a3_pdf($u);
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="cadastro-certificado-a3.pdf"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    exit;
});

$router->post('/painel/conta/certificado/remover', function () {
    team_cert_remove(require_user());
    flash('ok', 'Certificado removido. Você passa a assinar pela aprovação no painel.');
    redirect('painel/conta#certificado');
});

/* ---------- Pedidos de assinatura ---------- */

$router->get('/painel/assinaturas', function () {
    $u = require_user();
    $open = all("SELECT r.*, v.kind, v.version, c.protocol, c.client_name FROM sig_requests r JOIN case_artifact_versions v ON v.id = r.version_id JOIN cases c ON c.id = r.case_id
        WHERE r.status NOT IN ('substituido') AND r.teste = 0 ORDER BY r.status = 'concluido', r.created_at DESC LIMIT 60");
    view('panel/assinaturas', ['meta_title' => 'Assinaturas · Painel', 'u' => $u, 'mine' => sig_my_pending((int) $u['id']), 'open' => $open,
        'users' => all('SELECT * FROM users WHERE active = 1 ORDER BY name'), 'def' => sig_default_users()], 'panel');
});

$router->post('/painel/assinaturas/padrao', function () {
    require_config_editor();
    $w = array_values(array_filter(array_map('intval', [input('testemunha1'), input('testemunha2')])));
    set_setting('sig_testemunhas', json_encode($w));
    set_setting('sig_advogado', (string) (int) input('advogado'));
    flash('ok', 'Assinantes padrão salvos. Valem para os próximos documentos enviados.');
    redirect('painel/assinaturas');
});

/* ---------- Teste da assinatura conjunta ---------- */

$router->get('/painel/assinaturas/teste', function () {
    $u = require_config_editor();
    view('panel/assinaturas_teste', ['meta_title' => 'Testar assinaturas · Painel', 'u' => $u, 'tests' => sig_tests(), 'def' => sig_default_users(),
        'users' => all('SELECT * FROM users WHERE active = 1 ORDER BY name'),
        'cases' => all('SELECT id, protocol, client_name FROM cases WHERE archived_at IS NULL ORDER BY id DESC LIMIT 300')], 'panel');
});

$router->post('/painel/assinaturas/teste', function () {
    $u = require_config_editor();
    [$req, $err, $missing] = sig_test_create((int) input('case_id'), $u, ['testemunhas' => [input('testemunha1'), input('testemunha2')], 'advogado' => input('advogado')]);
    if ($err) {
        flash('error', $err);
        redirect('painel/assinaturas/teste');
    }
    flash('ok', 'Contrato de teste gerado (código ' . $req['code'] . '). Cada pessoa assina na própria tela, em Assinaturas › Para você assinar; por último, você assina como o cliente.'
        . ($missing ? ' Dados que faltam no atendimento (aparecem marcados no texto): ' . implode('; ', $missing) . '.' : ''));
    redirect('painel/assinaturas/teste#teste-' . $req['id']);
});

/** O cliente simulado: a mesma tela de assinatura da área do cliente, com o código no e-mail de quem testa. */
function sig_test_client(string $id): array
{
    $u = require_user();
    $req = sig_test_or_404($id, $u);
    $v = one('SELECT * FROM case_artifact_versions WHERE id = ?', [$req['version_id']]);
    return [$u, $req, $v, find_case((int) $req['case_id'])];
}

function sig_test_banner(array $req, array $u): string
{
    return '<p class="folhas-aviso -teste"><strong>Teste:</strong> você assina como o cliente (simulado). O código de confirmação vai para o seu e-mail (' . e(sig_mask_email((string) $u['email'])) . '). '
        . 'Nada vai para o cliente nem para o atendimento. <a href="' . e(url('painel/assinaturas/teste')) . '#teste-' . (int) $req['id'] . '">Voltar ao teste</a></p>';
}

$router->get('/painel/assinaturas/teste/{id}/cliente', function (string $id) {
    [$u, $req, $v, $c] = sig_test_client($id);
    header('Cache-Control: private, no-store');
    $sig = sig_for_version((int) $v['id']);
    echo $sig ? sig_view_page($c, $v, $sig, input('assinado') === '1', ['pdf' => url('painel/assinaturas/teste/' . $req['id'] . '/pdf'), 'aviso' => sig_test_banner($req, $u)])
        : sig_sign_page($c, $v, ['base' => url('painel/assinaturas/teste/' . $req['id'] . '/cliente'), 'email' => (string) $u['email'], 'aviso' => sig_test_banner($req, $u)]);
    exit;
});

$router->post('/painel/assinaturas/teste/{id}/cliente/dados', function (string $id) {
    [, , $v, $c] = sig_test_client($id);
    [, , $err] = sig_for_version((int) $v['id']) ? [null, null, 'Este documento já foi assinado.'] : sig_check_identity($c, $_POST);
    json_out(['ok' => !$err, 'erro' => $err]);
});

$router->post('/painel/assinaturas/teste/{id}/cliente/codigo', function (string $id) {
    [$u, , $v, $c] = sig_test_client($id);
    $err = sig_for_version((int) $v['id']) ? 'Este documento já foi assinado.' : sig_send_code($c, $v, (string) $u['email']);
    json_out(['ok' => !$err, 'erro' => $err, 'msg' => $err ? null : 'Código enviado para ' . sig_mask_email((string) $u['email']) . ' (o seu e-mail, por ser teste). Ele vale por ' . SIGN_CODE_MINUTES . ' minutos.']);
});

$router->post('/painel/assinaturas/teste/{id}/cliente/assinar', function (string $id) {
    [, $req, $v, $c] = sig_test_client($id);
    [$sig, $err] = sig_sign($c, $v, $_POST);
    json_out(['ok' => (bool) $sig, 'erro' => $err, 'ir' => $sig ? url('painel/assinaturas/teste/' . $req['id'] . '/cliente', ['assinado' => '1']) : null]);
});

$router->post('/painel/assinaturas/teste/{id}/cliente/retificar', function (string $id) {
    sig_test_client($id);
    json_out(['ok' => false, 'erro' => 'No teste não há pedido de retificação.']);
});

/** PDF do teste: a via final, se o cliente simulado já assinou; senão, o PDF em assinatura. */
$router->get('/painel/assinaturas/teste/{id}/pdf', function (string $id) {
    [, $req, $v] = sig_test_client($id);
    if ($sig = sig_for_version((int) $v['id'])) {
        sig_send_original($sig, input('baixar') === '1');
    }
    $bin = $req['signed_path'] ? (string) @file_get_contents(sig_storage_dir() . '/' . basename((string) $req['signed_path'])) : '';
    if ($bin === '' || !hash_equals((string) $req['signed_sha256'], hash('sha256', $bin))) {
        flash('error', $bin === '' ? 'O PDF é gerado na primeira assinatura.' : 'O PDF em assinatura não confere com o registrado.');
        redirect('painel/assinaturas/teste');
    }
    header('Content-Type: application/pdf');
    header('Content-Disposition: ' . (input('baixar') === '1' ? 'attachment' : 'inline') . '; filename="teste-' . $req['code'] . '.pdf"');
    header('Content-Length: ' . strlen($bin));
    header('Cache-Control: private, no-store');
    echo $bin;
    exit;
});

function sig_party_or_404(string $id): array
{
    return one('SELECT * FROM sig_parties WHERE id = ?', [(int) $id]) ?? not_found();
}

/** Troca quem assina (antes de assinar), por quem gerencia o atendimento. */
$router->post('/painel/assinaturas/{id}/pessoa', function (string $id) {
    require_config_editor();
    $p = sig_party_or_404($id);
    $uid = (int) input('user_id');
    $preq = one('SELECT * FROM sig_requests WHERE id = ?', [$p['request_id']]);
    if ($p['signed_at']) {
        flash('error', 'Esta assinatura já foi feita.');
    } elseif ($preq['signed_path'] && $p['role'] === 'testemunha') {
        flash('error', 'O documento já começou a ser assinado com os nomes das testemunhas; para trocar, gere uma nova versão.');
    } elseif ($uid && !one('SELECT 1 FROM users WHERE id = ? AND active = 1', [$uid])) {
        flash('error', 'Usuário não encontrado.');
    } else {
        $other = one('SELECT 1 FROM sig_parties WHERE request_id = ? AND id <> ? AND user_id = ?', [$p['request_id'], $p['id'], $uid]);
        if ($other && $uid) {
            flash('error', 'A mesma pessoa não pode assinar duas vezes no documento.');
        } else {
            q('UPDATE sig_parties SET user_id = ?, seal = NULL WHERE id = ? AND signed_at IS NULL', [$uid ?: null, $p['id']]);
            flash('ok', 'Assinante atualizado.');
        }
    }
    back('painel/assinaturas');
});

/** Tela de assinatura do integrante: o documento, o selo para posicionar e a confirmação. */
$router->get('/painel/assinaturas/{id}', function (string $id) {
    $u = require_user();
    $p = sig_party_or_404($id);
    if ((int) $p['user_id'] !== (int) $u['id'] && !has_role('supervisor') && !is_operator($u)) {
        forbidden(); // o documento só aparece para quem vai assinar e para quem gerencia
    }
    $req = one('SELECT * FROM sig_requests WHERE id = ?', [$p['request_id']]);
    $v = one('SELECT * FROM case_artifact_versions WHERE id = ?', [$req['version_id']]);
    $c = find_case((int) $req['case_id']);
    header('Cache-Control: private, no-store');
    echo sig_party_page($p, $u, $req, $v, $c);
    exit;
});

$router->post('/painel/assinaturas/{id}/assinar', function (string $id) {
    $u = require_user();
    $p = sig_party_or_404($id);
    $err = sig_team_sign($p, $u, $_POST);
    if ($err) {
        flash('error', $err);
        redirect('painel/assinaturas/' . $p['id']);
    }
    audit('assinatura_equipe', (int) one('SELECT case_id FROM sig_requests WHERE id = ?', [$p['request_id']])['case_id'], $p['role']);
    $preq = one('SELECT * FROM sig_requests WHERE id = ?', [$p['request_id']]);
    flash('ok', $p['role'] === 'testemunha' ? 'Assinatura de testemunha registrada.' : ($preq['teste'] ? 'Documento assinado pelo escritório. No teste, falta assinar como o cliente.' : 'Documento assinado. Ele já está com o cliente para assinar.'));
    redirect($preq['teste'] && ((int) $preq['teste_por'] === (int) $u['id']) ? 'painel/assinaturas/teste#teste-' . $preq['id'] : 'painel/assinaturas');
});

/** Certificado A3: baixa o PDF com os selos para assinar no assinador do token. */
$router->post('/painel/assinaturas/{id}/pdf-a3', function (string $id) {
    $u = require_user();
    $p = sig_party_or_404($id);
    [$pdf, $err] = sig_team_prepare_a3($p, $u, $_POST);
    if (!$pdf) {
        flash('error', $err);
        redirect('painel/assinaturas/' . $p['id']);
    }
    $req = one('SELECT * FROM sig_requests WHERE id = ?', [$p['request_id']]);
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="para-assinar-' . $req['code'] . '.pdf"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    exit;
});

$router->post('/painel/assinaturas/{id}/a3', function (string $id) {
    $u = require_user();
    $p = sig_party_or_404($id);
    $f = $_FILES['pdf'] ?? null;
    $bin = $f && ($f['error'] ?? 1) === UPLOAD_ERR_OK ? (string) file_get_contents($f['tmp_name']) : '';
    $err = $bin === '' ? 'Envie o PDF assinado.' : sig_team_upload_a3($p, $u, $bin);
    if ($err) {
        flash('error', $err);
        redirect('painel/assinaturas/' . $p['id']);
    }
    flash('ok', 'Assinatura com o certificado A3 conferida e registrada.');
    redirect('painel/assinaturas');
});

/** Página do integrante: folhas com os selos já postos e o dele para arrastar. */
function sig_party_page(array $p, array $u, array $req, array $v, array $c): string
{
    [$can, $why] = sig_party_check($p, $u);
    $L = sig_doc_layout($v, $req);
    foreach (sig_party_seals($req, (int) $p['id']) as $s) {
        $L['pages'][$s['pagina'] - 1] = array_merge($L['pages'][$s['pagina'] - 1], $s['ops']);
    }
    $pending = json_decode((string) $p['seal'], true) ?: null;
    $pos = $pending && !$p['signed_at'] ? sig_seal_clamp($pending, count($L['pages'])) : sig_seal_place($L, sig_party_block($p), sig_party_rects($req, (int) $p['id']));
    $pr = team_profile($u);
    $cert = team_cert($u);
    $method = team_sign_method($u);
    $meta = ['nome' => $pr['nome_completo'], 'quando' => null, 'fuso' => null, 'codigo' => $req['code'], 'host' => preg_replace('#^https?://#', '', rtrim(public_root(), '/')),
        'rotulo' => sig_seal_label($p['role'], $cert, $method)];
    $sealSvg = '<svg viewBox="0 0 ' . SEAL_W . ' ' . SEAL_H . '" xmlns="http://www.w3.org/2000/svg">' . pdoc_svg(sig_seal_ops($meta, team_signature($u))) . '</svg>';
    $label = kit_label($v['kind']);
    $tok = csrf_field();
    $dados = json_encode(['selo' => ['w' => SEAL_W, 'h' => SEAL_H, 'minX' => SEAL_MIN_X, 'folhaW' => PDOC_W, 'folhaH' => PDOC_H] + $pos, 'paginas' => count($L['pages'])], JSON_HEX_TAG | JSON_HEX_AMP);
    $flash = '';
    foreach (take_flashes() as $f) {
        $flash .= '<p class="folhas-aviso -' . e($f['type'] ?? 'ok') . '">' . e($f['msg'] ?? '') . '</p>';
    }
    $who = SIG_PARTY_LABELS[$p['role']] . ($p['role'] === 'testemunha' ? ' ' . (int) $p['ord'] : '');
    $consent = $p['role'] === 'testemunha' ? SIG_WITNESS_CONSENT : SIG_LAWYER_CONSENT;
    $back = '<a class="assin-btn -sec" href="' . e(url('painel/assinaturas')) . '">Voltar</a>';
    $form = fn(string $action, string $inner, string $extra = '') => '<form method="post" action="' . e(url('painel/assinaturas/' . $p['id'] . '/' . $action)) . '" data-form-selo' . $extra . '>' . $tok . '<input type="hidden" name="selo" data-selo-campo>' . $inner . '</form>';
    $aceite = '<label class="assin-ok"><input type="checkbox" name="aceite" value="1" required> ' . e($consent) . '</label>';
    if (!$can) {
        $bar = '<div class="assin-barra"><p><strong>' . e($who) . ':</strong> ' . e($why) . '</p><div class="assin-acoes">' . $back
            . (str_contains((string) $why, 'Minha conta') ? '<a class="assin-btn" href="' . e(url('painel/conta')) . '">Abrir Minha conta</a>' : '') . '</div></div>';
    } else {
        $head = '<p><strong>Arraste o seu selo</strong> até o lugar da sua assinatura (' . e(mb_strtolower($who)) . '). <span data-selo-onde></span></p>'
            . '<p class="assin-sub">Você assina com: <strong>' . e(TEAM_SIGN_METHODS[$method]) . '</strong>' . ($cert && $method !== 'aprovacao' ? ' · ' . e($cert['titular']) . ' · ' . e($cert['emissor']) : '')
            . ' · <a href="' . e(url('painel/conta#certificado')) . '">mudar em Minha conta</a></p>';
        $body = match ($method) {
            'a1' => $form('assinar', $aceite . '<div class="assin-codigo"><input type="password" name="senha_certificado" autocomplete="off" placeholder="Senha do certificado" required><button class="assin-btn" type="submit">Assinar com o certificado</button></div>', ' class="assin-cert"'),
            'a3' => '<div class="assin-a3 -aberto">' . $form('pdf-a3', $aceite . '<p class="assin-sub">1. Baixe o PDF com o seu selo (na posição da tela). 2. Assine no assinador do token (Assinador SERPRO, Adobe ou outro), sem alterar mais nada. 3. Envie o arquivo assinado.</p><button class="assin-btn -sec" type="submit">Baixar o PDF para assinar no token</button>')
                . '<form method="post" enctype="multipart/form-data" action="' . e(url('painel/assinaturas/' . $p['id'] . '/a3')) . '">' . $tok
                . '<div class="assin-codigo"><input type="file" name="pdf" accept="application/pdf" required><button class="assin-btn" type="submit">Enviar o PDF assinado</button></div></form></div>',
            default => $form('assinar', $aceite . '<div class="assin-codigo"><button class="assin-btn" type="submit">' . ($p['role'] === 'testemunha' ? 'Aprovar e assinar como testemunha' : 'Aprovar e assinar') . '</button></div>', ' class="assin-cert"'),
        };
        $bar = '<div class="assin-barra -equipe">' . $head . $body . '<div class="assin-acoes">' . $back . '</div></div>';
    }
    return sig_sheet_page($label . ' · ' . $c['protocol'] . ' · assinatura do escritório',
        '<header class="folhas-topo"><strong>' . e($label) . ' · ' . e($who) . '</strong><span>' . ($req['teste'] ? '<b class="folhas-teste">TESTE</b> · ' : '') . 'versão ' . e(sig_vtxt($v)) . ' · atendimento ' . e($c['protocol']) . ' · ' . e($c['client_name']) . ' · código ' . e($req['code']) . '</span></header>'
        . $flash . '<main class="folhas">' . sig_sheets_html($L) . '</main>'
        . ($can ? '<template id="selo-modelo">' . $sealSvg . '</template><script type="application/json" id="selo-dados">' . $dados . '</script>' : '')
        . '<div class="assin">' . $bar . '</div>',
        ['scripts' => $can ? [asset('js/equipe.js')] : []]);
}
