<?php
declare(strict_types=1);

/** @var Router $router */

$router->get('/', function () {
    view('site/home', [
        'meta_title' => 'Sidney Fatuche Advocacia · Financiamento de veículos',
        'meta_desc'  => 'Juros altos, parcela que não cabe, notificação ou veículo apreendido. Análise do contrato, negociação e atuação judicial quando cabível, com acompanhamento até o resultado ser conferido.',
        'posts'      => array_slice(posts(), 0, 3),
    ]);
});

$router->get('/atendimento', function () {
    view('site/atendimento', [
        'meta_title' => 'Como funciona o atendimento · Sidney Fatuche Advocacia',
        'meta_desc'  => 'Da primeira conversa à conferência do resultado: quem acompanha seu caso, o que acontece em cada etapa, metas de atendimento e regras de segurança.',
    ]);
});

$router->get('/sobre', function () {
    view('site/sobre', [
        'meta_title' => 'Sidney Fatuche, advogado · OAB/PR 131.033',
        'meta_desc'  => 'Conheça o advogado Sidney Fatuche e a forma de trabalho do escritório em financiamento de veículos.',
    ]);
});

$router->get('/duvidas', function () {
    view('site/duvidas', [
        'meta_title' => 'Dúvidas frequentes · Sidney Fatuche Advocacia',
        'meta_desc'  => 'Respostas diretas sobre revisão de financiamento, busca e apreensão, prazos, custos e acompanhamento do caso.',
    ]);
});

foreach (array_keys(situations()) as $slug) {
    $router->get('/' . $slug, function () use ($slug) {
        $s = situations()[$slug];
        view('site/situacao', [
            'slug'       => $slug,
            's'          => $s,
            'meta_title' => $s['title'] . ' · Sidney Fatuche Advocacia',
            'meta_desc'  => $s['lead'],
        ]);
    });
}

$router->get('/agendar', fn() => redirect('comecar', 301));

$router->get('/blog', function () {
    view('site/blog', [
        'meta_title' => 'Blog · Busca e apreensão e financiamento de veículos',
        'meta_desc'  => 'Estudos sobre financiamento de veículos, busca e apreensão, juros, tarifas e venda do bem, com fontes oficiais.',
        'active_cat' => null,
    ]);
});

$router->get('/blog/categoria/{cat}', function (string $cat) {
    if (!isset(post_categories()[$cat])) {
        not_found();
    }
    view('site/blog', [
        'meta_title' => post_categories()[$cat] . ' · Blog Sidney Fatuche Advocacia',
        'meta_desc'  => 'Estudos da categoria ' . post_categories()[$cat] . '.',
        'active_cat' => $cat,
    ]);
});

$router->get('/blog/{slug}', function (string $slug) {
    $p = post_by_slug($slug) ?? not_found();
    view('site/post', [
        'p'          => $p,
        'meta_title' => $p['title'] . ' · Sidney Fatuche',
        'meta_desc'  => $p['description'],
        'canonical'  => 'blog/' . $p['slug'],
        'og_type'    => 'article',
    ]);
});

/* ---------- Triagem (etapa 1 do workflow) ---------- */

$router->get('/comecar', function () {
    if (intake_workflow() === 'simple_v1') {
        intake_start_page();
    }
    $pre = [];
    $aviso = (string) input('aviso');
    if (isset(NOTICE_OPTIONS[$aviso])) {
        $pre['notice'] = $aviso;
    }
    $prio = (string) input('prioridade');
    if (isset(PRIORITY_OPTIONS[$prio])) {
        $pre['priority'] = $prio;
    }
    $_SESSION['triage_started'] = time();
    view('site/comecar', [
        'meta_title' => 'Conte o que está acontecendo · Sidney Fatuche Advocacia',
        'meta_desc'  => 'Algumas perguntas rápidas para a equipe entender sua situação e verificar se há prazo urgente.',
        'old'        => $pre + ['origin' => (string) input('origem')],
        'errors'     => [],
        'noindex'    => false,
    ]);
});

$router->post('/comecar', function () {
    if (intake_workflow() === 'simple_v1') {
        intake_start_post();
    }
    $a = [
        'notice' => (string) input('notice'),
        'notice_date' => (string) input('notice_date'),
        'bank' => mb_substr((string) input('bank'), 0, 80),
        'contract_status' => (string) input('contract_status'),
        'installment_value' => input_money('installment_value'),
        'overdue' => (string) input('overdue'),
        'overdue_count' => mb_substr((string) input('overdue_count'), 0, 20),
        'has_contract' => (string) input('has_contract'),
        'city' => mb_substr((string) input('city'), 0, 80),
        'uf' => strtoupper(mb_substr((string) input('uf'), 0, 2)),
        'priority' => (string) input('priority'),
        'priority_text' => '',
        'description' => mb_substr((string) input('description'), 0, 3000),
        'budget_installment' => input_money('budget_installment'),
        'other_lawyer' => (string) input('other_lawyer'),
        'contact_pref' => (string) input('contact_pref'),
        'contact_time' => mb_substr((string) input('contact_time'), 0, 40),
        'accessibility' => mb_substr((string) input('accessibility'), 0, 300),
        'name' => mb_substr((string) input('name'), 0, 120),
        'phone' => mb_substr((string) input('phone'), 0, 30),
        'email' => mb_substr((string) input('email'), 0, 120),
        'origin' => (string) input('origin'),
        'summary_confirmed' => input('summary_ok') === '1',
        'summary_fix' => mb_substr((string) input('summary_fix'), 0, 600),
    ];

    $errors = [];
    if (!isset(NOTICE_OPTIONS[$a['notice']])) {
        $errors['notice'] = 'Escolha uma opção. Se não souber, marque “Não sei dizer”.';
    }
    if (!isset(PRIORITY_OPTIONS[$a['priority']])) {
        $errors['priority'] = 'Conte o que você precisa resolver primeiro.';
    }
    if (mb_strlen($a['name']) < 2) {
        $errors['name'] = 'Como podemos te chamar?';
    }
    $digits = only_digits($a['phone']);
    if (strlen($digits) < 10 || strlen($digits) > 13) {
        $errors['phone'] = 'Informe um WhatsApp ou telefone com DDD.';
    }
    if ($a['email'] !== '' && !filter_var($a['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Confira o e-mail ou deixe em branco.';
    }
    if (input('consent') !== '1') {
        $errors['consent'] = 'Precisamos da sua autorização para usar esses dados no atendimento.';
    }
    if ($a['uf'] !== '' && !in_array($a['uf'], UFS, true)) {
        $a['uf'] = '';
    }
    if ($a['notice_date'] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $a['notice_date'])) {
        $a['notice_date'] = '';
    }

    // Proteções simples contra envio automatizado.
    $bot = input('website') !== '' || (time() - (int) ($_SESSION['triage_started'] ?? 0)) < 4;
    if (!$errors && !$bot && too_many_attempts('triage:' . client_ip(), 6, 60)) {
        $errors['form'] = 'Recebemos muitos envios deste aparelho em pouco tempo. Fale com a equipe pelo WhatsApp, por favor.';
    }

    if ($errors) {
        view('site/comecar', [
            'meta_title' => 'Conte o que está acontecendo · Sidney Fatuche Advocacia',
            'meta_desc'  => '',
            'old' => $_POST,
            'errors' => $errors,
        ], 'site', 422);
    }
    if ($bot) {
        redirect('comecar/recebido');
    }
    record_attempt('triage:' . client_ip());

    $a['summary'] = summary_sentence($a);
    if (!$a['summary_confirmed'] && $a['summary_fix'] !== '') {
        $a['description'] = trim($a['description'] . "\n\nAjuste do cliente ao resumo: " . $a['summary_fix']);
    }
    $c = create_case_from_triage($a);
    $_SESSION['triage_case'] = ['id' => (int) $c['id'], 'at' => time()];
    redirect('comecar/recebido');
});

$router->get('/comecar/recebido', function () {
    $t = $_SESSION['triage_case'] ?? null;
    $c = ($t && time() - $t['at'] < 7200) ? one('SELECT * FROM cases WHERE id = ?', [$t['id']]) : null;
    view('site/recebido', [
        'meta_title' => 'Recebemos sua mensagem · Sidney Fatuche Advocacia',
        'meta_desc'  => '',
        'c' => $c,
        'noindex' => true,
    ]);
});

$router->post('/comecar/acesso', function () {
    $t = $_SESSION['triage_case'] ?? null;
    if (!$t || time() - $t['at'] > 7200) {
        flash('error', 'Por segurança, este link expirou. Peça um código de acesso à equipe pelo WhatsApp.');
        redirect('cliente/entrar');
    }
    $pass = (string) ($_POST['password'] ?? '');
    if (mb_strlen($pass) < 8) {
        flash('error', 'Use pelo menos 8 caracteres na senha.');
        redirect('comecar/recebido');
    }
    add_event((int) $t['id'], 'portal', 'Cliente criou acesso à área do cliente', null, false, 'cliente');
    login_portal((int) $t['id'], password_hash($pass, PASSWORD_DEFAULT));
    unset($_SESSION['triage_case']);
    flash('ok', 'Pronto! Esta é a sua área. Guarde o protocolo e a senha para voltar quando quiser.');
    redirect('cliente');
});

/* ---------- Páginas institucionais ---------- */

$router->get('/seguranca', fn() => view('site/seguranca', ['meta_title' => 'Segurança e canais oficiais · Sidney Fatuche Advocacia', 'meta_desc' => 'Como confirmar que uma mensagem é do escritório e como protegemos seus documentos.']));
$router->get('/privacidade', fn() => view('site/privacidade', ['meta_title' => 'Política de privacidade · Sidney Fatuche Advocacia', 'meta_desc' => 'Quais dados coletamos, para quê, por quanto tempo e como exercer seus direitos.']));
$router->get('/termos', fn() => view('site/termos', ['meta_title' => 'Termos de uso · Sidney Fatuche Advocacia', 'meta_desc' => 'Condições de uso do site e da área do cliente.']));

$router->get('/robots.txt', function () {
    header('Content-Type: text/plain; charset=utf-8');
    echo "User-agent: *\nDisallow: /painel\nDisallow: /cliente\nDisallow: /comecar/recebido\nDisallow: /arquivo\nSitemap: " . absolute_url('sitemap.xml') . "\n";
    exit;
});

$router->get('/sitemap.xml', function () {
    header('Content-Type: application/xml; charset=utf-8');
    $urls = ['', 'atendimento', 'sobre', 'duvidas', 'comecar', 'blog', 'seguranca', 'privacidade', 'termos'];
    foreach (array_keys(situations()) as $s) {
        $urls[] = $s;
    }
    foreach (posts() as $p) {
        $urls[] = 'blog/' . $p['slug'];
    }
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
    foreach ($urls as $u) {
        echo '<url><loc>' . e(absolute_url($u)) . '</loc></url>';
    }
    echo '</urlset>';
    exit;
});

/* ---------- Arquivos (somente com autorização no servidor) ---------- */

$router->get('/arquivo/{id}', function (string $id) {
    $f = one('SELECT * FROM files WHERE id = ?', [(int) $id]) ?? not_found();
    $user = current_user();
    $c = one('SELECT * FROM cases WHERE id = ?', [$f['case_id']]);
    if ($user) {
        if (!$c || !can_operate($c, $user)) {
            forbidden();
        }
    } else {
        // Cliente: permissão, carência e visibilidade revalidadas a cada pedido.
        if (!$c || !client_case_authorized($c) || (int) $f['internal'] === 1 || $f['replaced_at']) {
            forbidden();
        }
    }
    $path = config('storage_dir') . '/uploads/' . $f['case_id'] . '/' . $f['stored_name'];
    if (!is_file($path)) {
        not_found();
    }
    if ($user) {
        audit('arquivo_aberto', (int) $f['case_id'], $f['original_name']);
    }
    header('Content-Type: ' . $f['mime'] . ($f['mime'] === 'text/plain' ? '; charset=utf-8' : ''));
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: inline; filename="' . rawurlencode($f['original_name']) . '"');
    header('Cache-Control: private, no-store');
    readfile($path);
    exit;
});
