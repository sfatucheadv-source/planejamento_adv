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

$router->post('/painel/conta/certificado/remover', function () {
    team_cert_remove(require_user());
    flash('ok', 'Certificado removido do sistema.');
    redirect('painel/conta#certificado');
});

/* ---------- Pedidos de assinatura ---------- */

$router->get('/painel/assinaturas', function () {
    $u = require_user();
    $open = all("SELECT r.*, v.kind, v.version, c.protocol, c.client_name FROM sig_requests r JOIN case_artifact_versions v ON v.id = r.version_id JOIN cases c ON c.id = r.case_id
        WHERE r.status NOT IN ('substituido') ORDER BY r.status = 'concluido', r.created_at DESC LIMIT 60");
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

function sig_party_or_404(string $id): array
{
    return one('SELECT * FROM sig_parties WHERE id = ?', [(int) $id]) ?? not_found();
}

/** Troca quem assina (antes de assinar), por quem gerencia o atendimento. */
$router->post('/painel/assinaturas/{id}/pessoa', function (string $id) {
    require_config_editor();
    $p = sig_party_or_404($id);
    $uid = (int) input('user_id');
    if ($p['signed_at']) {
        flash('error', 'Esta assinatura já foi feita.');
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
    $err = $p['role'] === 'testemunha' ? sig_witness_sign($p, $u, $_POST) : sig_lawyer_sign_a1($p, $u, $_POST);
    if ($err) {
        flash('error', $err);
        redirect('painel/assinaturas/' . $p['id']);
    }
    audit('assinatura_equipe', (int) one('SELECT case_id FROM sig_requests WHERE id = ?', [$p['request_id']])['case_id'], $p['role']);
    flash('ok', $p['role'] === 'testemunha' ? 'Assinatura de testemunha registrada.' : 'Documento assinado com o certificado digital. Ele já está com o cliente para assinar.');
    redirect('painel/assinaturas');
});

/** Certificado A3: baixa o PDF com os selos para assinar no assinador do token. */
$router->post('/painel/assinaturas/{id}/pdf-a3', function (string $id) {
    $u = require_user();
    $p = sig_party_or_404($id);
    if ($p['role'] !== 'advogado') {
        forbidden();
    }
    [$pdf, $err] = sig_lawyer_prepare_a3($p, $u, $_POST);
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
    $err = $bin === '' ? 'Envie o PDF assinado.' : sig_lawyer_upload($p, $u, $bin);
    if ($err) {
        flash('error', $err);
        redirect('painel/assinaturas/' . $p['id']);
    }
    flash('ok', 'Assinatura com o certificado A3 conferida e registrada. O documento já está com o cliente.');
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
    $cert = json_decode((string) ($u['cert_info'] ?? ''), true) ?: null;
    $meta = ['nome' => $pr['nome_completo'], 'quando' => null, 'fuso' => null, 'codigo' => $req['code'], 'host' => preg_replace('#^https?://#', '', rtrim(public_root(), '/')),
        'rotulo' => sig_seal_label($p['role'], $cert)];
    $sealSvg = '<svg viewBox="0 0 ' . SEAL_W . ' ' . SEAL_H . '" xmlns="http://www.w3.org/2000/svg">' . pdoc_svg(sig_seal_ops($meta, team_signature($u))) . '</svg>';
    $label = kit_label($v['kind']);
    $tok = csrf_field();
    $dados = json_encode(['selo' => ['w' => SEAL_W, 'h' => SEAL_H, 'minX' => SEAL_MIN_X, 'folhaW' => PDOC_W, 'folhaH' => PDOC_H] + $pos, 'paginas' => count($L['pages'])], JSON_HEX_TAG | JSON_HEX_AMP);
    $flash = '';
    foreach (take_flashes() as $f) {
        $flash .= '<p class="folhas-aviso -' . e($f['type'] ?? 'ok') . '">' . e($f['msg'] ?? '') . '</p>';
    }
    $who = SIG_PARTY_LABELS[$p['role']] . ($p['role'] === 'testemunha' ? ' ' . (int) $p['ord'] : '');
    if (!$can) {
        $bar = '<div class="assin-barra"><p><strong>' . e($who) . ':</strong> ' . e($why) . '</p><div class="assin-acoes"><a class="assin-btn -sec" href="' . e(url('painel/assinaturas')) . '">Voltar</a>'
            . (str_contains((string) $why, 'Minha conta') ? '<a class="assin-btn" href="' . e(url('painel/conta')) . '">Abrir Minha conta</a>' : '') . '</div></div>';
    } elseif ($p['role'] === 'testemunha') {
        $bar = '<form class="assin-barra -equipe" method="post" action="' . e(url('painel/assinaturas/' . $p['id'] . '/assinar')) . '" data-form-selo>' . $tok
            . '<input type="hidden" name="selo" data-selo-campo><p><strong>Arraste o seu selo</strong> até o lugar da sua assinatura. <span data-selo-onde></span></p>'
            . '<label class="assin-ok"><input type="checkbox" name="aceite" value="1" required> ' . e(SIG_WITNESS_CONSENT) . '</label>'
            . '<div class="assin-acoes"><a class="assin-btn -sec" href="' . e(url('painel/assinaturas')) . '">Voltar</a><button class="assin-btn" type="submit">Aprovar e assinar como testemunha</button></div></form>';
    } else {
        $hasA1 = $u['cert_path'] && $cert;
        $bar = '<div class="assin-barra -equipe"><p><strong>Arraste o seu selo</strong> até o lugar da assinatura do escritório. <span data-selo-onde></span></p>'
            . ($hasA1 ? '<form class="assin-cert" method="post" action="' . e(url('painel/assinaturas/' . $p['id'] . '/assinar')) . '" data-form-selo>' . $tok . '<input type="hidden" name="selo" data-selo-campo>'
                . '<p class="assin-sub">Certificado: <strong>' . e($cert['titular']) . '</strong> · ' . e($cert['emissor']) . ' · válido até ' . e(fmt_date((string) $cert['valido_ate'])) . '</p>'
                . '<label class="assin-ok"><input type="checkbox" name="aceite" value="1" required> ' . e(SIG_LAWYER_CONSENT) . '</label>'
                . '<div class="assin-codigo"><input type="password" name="senha_certificado" autocomplete="off" placeholder="Senha do certificado" required><button class="assin-btn" type="submit">Assinar com o certificado</button></div></form>'
                : '<p class="assin-sub">Sem certificado A1 cadastrado. <a href="' . e(url('painel/conta#certificado')) . '">Cadastrar em Minha conta</a> ou use o token (A3) abaixo.</p>')
            . '<details class="assin-a3"><summary>Assinar com certificado A3 (token ou cartão)</summary>'
            . '<form method="post" action="' . e(url('painel/assinaturas/' . $p['id'] . '/pdf-a3')) . '" data-form-selo>' . $tok . '<input type="hidden" name="selo" data-selo-campo>'
            . '<p class="assin-sub">1. Baixe o PDF com os selos (a posição do seu selo fica a que está na tela). 2. Assine no assinador do token (ex.: Assinador SERPRO ou Adobe), sem alterar mais nada. 3. Envie o arquivo assinado.</p>'
            . '<button class="assin-btn -sec" type="submit">Baixar o PDF para assinar</button></form>'
            . '<form method="post" enctype="multipart/form-data" action="' . e(url('painel/assinaturas/' . $p['id'] . '/a3')) . '">' . $tok
            . '<div class="assin-codigo"><input type="file" name="pdf" accept="application/pdf" required><button class="assin-btn" type="submit">Enviar o PDF assinado</button></div></form></details>'
            . '<div class="assin-acoes"><a class="assin-btn -sec" href="' . e(url('painel/assinaturas')) . '">Voltar</a></div></div>';
    }
    return sig_sheet_page($label . ' · ' . $c['protocol'] . ' · assinatura do escritório',
        '<header class="folhas-topo"><strong>' . e($label) . ' · ' . e($who) . '</strong><span>versão ' . (int) $v['version'] . ' · atendimento ' . e($c['protocol']) . ' · ' . e($c['client_name']) . ' · código ' . e($req['code']) . '</span></header>'
        . $flash . '<main class="folhas">' . sig_sheets_html($L) . '</main>'
        . ($can ? '<template id="selo-modelo">' . $sealSvg . '</template><script type="application/json" id="selo-dados">' . $dados . '</script>' : '')
        . '<div class="assin">' . $bar . '</div>',
        ['scripts' => $can ? [asset('js/equipe.js')] : []]);
}
