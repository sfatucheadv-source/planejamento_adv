<?php
declare(strict_types=1);

/**
 * Assinaturas da equipe antes do cliente.
 *
 * Ordem: as testemunhas (usuários da equipe) aprovam no painel, sem e-mail nem
 * código — a sessão do usuário é a autenticação; depois o advogado assina com
 * o certificado digital (A1 no servidor, com a senha a cada assinatura, ou A3
 * fora do sistema, devolvendo o PDF assinado); por fim o cliente, pelo processo
 * da área do cliente. Cada selo leva a rubrica mecânica guardada no perfil do
 * usuário (desenhada uma vez, trocada quando ele quiser).
 *
 * O PDF do advogado é gerado com os selos das testemunhas e o dele; a assinatura
 * do certificado cobre esse arquivo inteiro. O selo e a faixa do cliente entram
 * depois, como acréscimo (atualização incremental), sem invalidar a assinatura.
 */

const TEAM_PROFILE_FIELDS = [
    'nome_completo' => 'Nome completo',
    'nacionalidade' => 'Nacionalidade',
    'estado_civil' => 'Estado civil',
    'uniao_estavel' => 'União estável',
    'profissao' => 'Profissão',
    'cpf' => 'CPF',
    'rg' => 'RG (número e órgão emissor)',
    'oab' => 'OAB (se advogado)',
    'email' => 'E-mail',
    'telefone' => 'Telefone pessoal',
    'cep' => 'CEP',
    'logradouro' => 'Endereço (rua)',
    'numero' => 'Número',
    'complemento' => 'Complemento',
    'bairro' => 'Bairro',
    'cidade' => 'Cidade',
    'uf' => 'UF',
];
/** Mínimo para assinar (art. 319, II, do CPC: qualificação). */
const TEAM_PROFILE_REQUIRED = ['nome_completo', 'nacionalidade', 'estado_civil', 'profissao', 'cpf', 'email', 'telefone', 'cep', 'logradouro', 'numero', 'bairro', 'cidade', 'uf'];
const TEAM_ESTADOS_CIVIS = ['solteiro(a)', 'casado(a)', 'divorciado(a)', 'separado(a) judicialmente', 'viúvo(a)'];

const SIG_PARTY_LABELS = ['testemunha' => 'Testemunha', 'advogado' => 'Advogado (pelo escritório)'];
const SIG_REQUEST_STATES = ['testemunhas' => 'Aguardando as testemunhas', 'advogado' => 'Aguardando o advogado', 'cliente' => 'Com o cliente para assinar',
    'concluido' => 'Assinado por todos', 'substituido' => 'Substituído por nova versão'];

/* ---------- Perfil da equipe ---------- */

function team_profile(array $u): array
{
    $p = json_decode((string) ($u['profile'] ?? ''), true) ?: [];
    return $p + ['nome_completo' => $u['name'], 'email' => $u['email'], 'telefone' => (string) ($u['phone'] ?? '')];
}

function team_profile_missing(array $u): array
{
    $p = team_profile($u);
    $out = [];
    foreach (TEAM_PROFILE_REQUIRED as $k) {
        if (trim((string) ($p[$k] ?? '')) === '') {
            $out[] = TEAM_PROFILE_FIELDS[$k];
        }
    }
    if (($p['cpf'] ?? '') !== '' && !valid_cpf(only_digits((string) $p['cpf']))) {
        $out[] = 'CPF válido';
    }
    return $out;
}

/** Qualificação por extenso (para a conferência e as evidências). */
function team_qualification(array $u): string
{
    $p = team_profile($u);
    $end = trim(implode(', ', array_filter([trim(($p['logradouro'] ?? '') . ', ' . ($p['numero'] ?? ''), ', '), $p['complemento'] ?? '', $p['bairro'] ?? '',
        trim(($p['cidade'] ?? '') . '/' . ($p['uf'] ?? ''), '/'), ($p['cep'] ?? '') !== '' ? 'CEP ' . $p['cep'] : ''])));
    return implode(', ', array_filter([$p['nome_completo'] ?? '', $p['nacionalidade'] ?? '', $p['estado_civil'] ?? '',
        ($p['uniao_estavel'] ?? '') === 'sim' ? 'em união estável' : '', $p['profissao'] ?? '', ($p['oab'] ?? '') !== '' ? 'OAB ' . $p['oab'] : '',
        ($p['cpf'] ?? '') !== '' ? 'CPF ' . sig_fmt_cpf((string) $p['cpf']) : '', ($p['rg'] ?? '') !== '' ? 'RG ' . $p['rg'] : '',
        $end !== '' ? 'residente em ' . $end : '', ($p['email'] ?? '') !== '' ? 'e-mail ' . $p['email'] : '', ($p['telefone'] ?? '') !== '' ? 'telefone ' . $p['telefone'] : '']));
}

function team_save_profile(array $u, array $in): ?string
{
    $p = [];
    foreach (TEAM_PROFILE_FIELDS as $k => $l) {
        $p[$k] = trim(mb_substr((string) ($in[$k] ?? ''), 0, 200));
    }
    $p['uf'] = mb_strtoupper(mb_substr($p['uf'], 0, 2));
    $p['uniao_estavel'] = ($in['uniao_estavel'] ?? '') === 'sim' ? 'sim' : 'nao';
    if ($p['cpf'] !== '') {
        if (!valid_cpf(only_digits($p['cpf']))) {
            return 'CPF inválido.';
        }
        $p['cpf'] = only_digits($p['cpf']);
    }
    if ($p['email'] !== '' && !filter_var($p['email'], FILTER_VALIDATE_EMAIL)) {
        return 'E-mail inválido.';
    }
    if ($p['nome_completo'] === '') {
        return 'Informe o nome completo.';
    }
    update('users', (int) $u['id'], ['profile' => json_encode($p, JSON_UNESCAPED_UNICODE)]);
    audit('perfil_atualizado', null, 'qualificação');
    return null;
}

/** Rubrica mecânica guardada (traços), ou null. */
function team_signature(array $u): ?array
{
    $s = json_decode((string) ($u['signature_strokes'] ?? ''), true);
    return is_array($s) ? sig_strokes($s) : null;
}

/* ---------- Forma de assinar: certificado A1, certificado A3 (token) ou senha ---------- */

const TEAM_SIGN_METHODS = ['senha' => 'Senha do sistema', 'a1' => 'Certificado A1 (arquivo)', 'a3' => 'Certificado A3 (token ou cartão)'];

function team_cert_dir(): string
{
    $dir = config('storage_dir') . '/certificados';
    is_dir($dir) || mkdir($dir, 0700, true);
    return $dir;
}

/** Certificado cadastrado (dados, tipo a1/a3) ou null. */
function team_cert(array $u): ?array
{
    $c = json_decode((string) ($u['cert_info'] ?? ''), true);
    if (!is_array($c)) {
        return null;
    }
    $c['tipo'] ??= $u['cert_path'] ? 'a1' : 'a3';
    return $c;
}

/** Como a pessoa assina: com o certificado cadastrado ou, sem ele, com a senha do sistema. */
function team_sign_method(array $u): string
{
    $c = team_cert($u);
    if ($c && ($c['tipo'] === 'a3' || ($c['tipo'] === 'a1' && $u['cert_path'] && is_file(team_cert_dir() . '/' . basename((string) $u['cert_path']))))) {
        return $c['tipo'];
    }
    return 'senha';
}

/** Confere o certificado lido (validade e CPF do cadastro). Retorna erro ou null. */
function team_cert_check(array $u, array $info): ?string
{
    if ($info['valido_ate'] && $info['valido_ate'] < now_str()) {
        return 'Este certificado venceu em ' . fmt_datetime($info['valido_ate']) . '.';
    }
    $cpf = only_digits((string) (team_profile($u)['cpf'] ?? ''));
    if ($cpf !== '' && $info['cpf'] && $info['cpf'] !== $cpf) {
        return 'O CPF do certificado (' . sig_fmt_cpf($info['cpf']) . ') não é o do seu cadastro.';
    }
    return null;
}

function team_cert_drop_file(array $u): void
{
    if ($u['cert_path']) {
        $p = team_cert_dir() . '/' . basename((string) $u['cert_path']);
        @chmod($p, 0600);
        @unlink($p);
    }
}

/**
 * A1: guarda o arquivo (.pfx/.p12), que já é protegido pela senha. A senha é
 * pedida aqui só para conferir o arquivo e ler os dados; não é guardada.
 */
function team_cert_store(array $u, string $pfx, string $password): ?string
{
    if ($pfx === '' || strlen($pfx) > 50000) {
        return 'Envie o arquivo do certificado A1 (.pfx ou .p12).';
    }
    [$c, $err] = pdfx_open_pfx($pfx, $password);
    if (!$c) {
        return $err;
    }
    $info = pdfx_cert_info($c['cert']);
    if ($err = team_cert_check($u, $info)) {
        return $err;
    }
    $file = 'u' . (int) $u['id'] . '-' . bin2hex(random_bytes(6)) . '.pfx';
    $path = team_cert_dir() . '/' . $file;
    file_put_contents($path, $pfx);
    @chmod($path, 0400);
    team_cert_drop_file($u);
    update('users', (int) $u['id'], ['cert_path' => $file, 'cert_info' => json_encode(['tipo' => 'a1'] + $info, JSON_UNESCAPED_UNICODE), 'cert_at' => now_str()]);
    audit('certificado_cadastrado', null, 'A1 · ' . $info['titular'] . ' · ' . $info['emissor']);
    return null;
}

/**
 * A3 (token ou cartão): a chave nunca sai do token, então o sistema guarda só a
 * parte pública. Aceita o certificado exportado (.cer, .crt ou .pem) ou o PDF
 * de cadastro assinado no token — de onde o certificado é lido.
 */
function team_cert_register_a3(array $u, string $bin): ?string
{
    $bin = trim($bin) === '' ? '' : $bin;
    if ($bin === '' || strlen($bin) > 5000000) {
        return 'Envie o certificado exportado do token (.cer) ou o PDF de cadastro assinado com ele.';
    }
    $pem = null;
    if (str_starts_with($bin, '%PDF')) {
        $sigs = pdfx_signatures($bin);
        $last = end($sigs);
        if (!$last || !$last['integra'] || empty($last['pem'])) {
            return 'Não encontrei uma assinatura digital válida no PDF. Assine o PDF de cadastro com o token e envie de novo.';
        }
        $pem = $last['pem'];
    } elseif (str_contains($bin, '-----BEGIN CERTIFICATE-----')) {
        preg_match('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s', $bin, $m);
        $pem = $m[0] ?? null;
    } else {
        $pem = "-----BEGIN CERTIFICATE-----\n" . chunk_split(base64_encode($bin), 64, "\n") . "-----END CERTIFICATE-----\n";
    }
    if (!$pem || !openssl_x509_parse($pem)) {
        return 'Arquivo de certificado não reconhecido.';
    }
    $info = pdfx_cert_info($pem);
    if (!$info['cpf']) {
        return 'Este certificado não traz CPF (e-CPF). Use o certificado pessoal do token.';
    }
    if ($err = team_cert_check($u, $info)) {
        return $err;
    }
    team_cert_drop_file($u);
    update('users', (int) $u['id'], ['cert_path' => null, 'cert_info' => json_encode(['tipo' => 'a3'] + $info, JSON_UNESCAPED_UNICODE), 'cert_at' => now_str()]);
    audit('certificado_cadastrado', null, 'A3 · ' . $info['titular'] . ' · ' . $info['emissor']);
    return null;
}

/** PDF de uma página para cadastrar o A3: a pessoa assina com o token e envia de volta. */
function team_cert_a3_pdf(array $u): string
{
    $p = team_profile($u);
    $lines = ['Cadastro de certificado digital A3', 'Sistema de atendimento · ' . (office('name') ?: 'Sidney Fatuche Advocacia'), '',
        'Usuário: ' . $p['nome_completo'] . ' (' . $u['email'] . ')', 'Gerado em ' . fmt_datetime(now_str()) . ' (horário de Brasília)', '',
        'Assine este arquivo com o seu token (Assinador SERPRO, Adobe ou outro) e envie de volta em Minha conta.',
        'O sistema lê do arquivo apenas o certificado público; a chave continua no token.'];
    $ops = [];
    $y = 40.0;
    foreach ($lines as $i => $t) {
        $enc = pdoc_enc($t);
        $key = $i === 0 ? 'poppinsb' : 'poppins';
        $pt = $i === 0 ? 14.0 : 10.0;
        $ops[] = ['t', 30.0, $y, $key, $pt, PDOC_TINTA, $enc, pdoc_width($enc, $key, $pt), false];
        $y += $i === 0 ? 10 : 6;
    }
    return pdoc_pdf(['pages' => [$ops], 'sigs' => []], ['titulo' => 'Cadastro de certificado A3 · ' . $p['nome_completo']]);
}

function team_cert_remove(array $u): void
{
    team_cert_drop_file($u);
    update('users', (int) $u['id'], ['cert_path' => null, 'cert_info' => null, 'cert_at' => null]);
    audit('certificado_removido');
}

/* ---------- Papéis nos blocos de assinatura do documento ---------- */

/** Quem assina cada bloco: cliente, testemunha (linha "Nome:") ou o escritório. */
function sig_block_role(string $first, array $c): string
{
    $t = sig_norm_name(sig_plain($first));
    $client = sig_norm_name(sig_expected_name($c));
    if ($client !== '' && str_contains($t, $client)) {
        return 'cliente';
    }
    if (preg_match('/^nome\s*:?\s*$/u', $t)) {
        return 'testemunha';
    }
    $office = sig_norm_name((string) (office('name') ?: ''));
    if (str_contains($t, 'advocacia') || str_contains($t, 'fatuche') || ($office !== '' && str_contains($t, $office))) {
        return 'escritorio';
    }
    return 'outro';
}

/**
 * Blocos com o papel de cada assinatura; as linhas das testemunhas ganham o
 * nome e o CPF de quem foi escolhido (na ordem em que aparecem).
 */
function sig_tag_blocks(array $blocks, array $c, array $witnesses = []): array
{
    $i = 0;
    $afterLabel = false; // blocos depois do rótulo "Testemunhas" são de testemunha, mesmo já com nome
    foreach ($blocks as &$b) {
        if ($b['type'] === 'rotulo') {
            $afterLabel = str_contains(sig_norm_name(sig_plain($b['text'])), 'testemunh');
            continue;
        }
        if ($b['type'] !== 'assinatura') {
            $afterLabel = false;
            continue;
        }
        $role = sig_block_role($b['lines'][0], $c);
        if ($afterLabel && $role !== 'cliente') {
            $role = 'testemunha';
        }
        if ($role === 'testemunha') {
            $i++;
            $b['papel'] = 'testemunha' . $i;
            if (!empty($witnesses[$i]['name'])) {
                $b['lines'] = [$witnesses[$i]['name'], 'CPF ' . sig_fmt_cpf((string) $witnesses[$i]['cpf']), 'Testemunha'];
            }
        } else {
            $b['papel'] = $role;
        }
    }
    return $blocks;
}

/** Testemunhas do pedido (ordem => [name, cpf]), com os dados do cadastro de cada uma. */
function sig_request_witnesses(?array $req): array
{
    if (!$req) {
        return [];
    }
    $out = [];
    foreach (all("SELECT * FROM sig_parties WHERE request_id = ? AND role = 'testemunha' ORDER BY ord", [$req['id']]) as $p) {
        if ($p['signed_at']) {
            $out[(int) $p['ord']] = ['name' => $p['name'], 'cpf' => $p['cpf']];
        } elseif ($p['user_id'] && ($u = one('SELECT * FROM users WHERE id = ?', [$p['user_id']]))) {
            $pr = team_profile($u);
            $out[(int) $p['ord']] = ['name' => $pr['nome_completo'], 'cpf' => (string) ($pr['cpf'] ?? '')];
        }
    }
    return $out;
}

/* ---------- Pedido de assinatura ---------- */

function sig_request(int $versionId): ?array
{
    return one("SELECT * FROM sig_requests WHERE version_id = ? AND status <> 'substituido'", [$versionId]);
}

function sig_parties(int $requestId): array
{
    return all("SELECT * FROM sig_parties WHERE request_id = ? ORDER BY role = 'advogado', ord", [$requestId]);
}

function sig_default_users(): array
{
    $w = array_values(array_filter(array_map('intval', (array) (json_decode((string) setting('sig_testemunhas', '[]'), true) ?: []))));
    return ['testemunhas' => $w, 'advogado' => (int) setting('sig_advogado', 0) ?: (operator_id() ?? 0)];
}

/**
 * Abre (ou devolve) o pedido de assinaturas da equipe para a versão publicada.
 * Só existe quando o documento tem testemunhas ou o escritório como parte;
 * documentos só do cliente (procuração, declaração) seguem direto para ele.
 */
function sig_request_open(array $c, array $v): ?array
{
    if ($v['status'] !== 'publicado' || !in_array($v['kind'], SIGNABLE_KINDS, true)) {
        return null;
    }
    if ($req = sig_request((int) $v['id'])) {
        return $req;
    }
    if (sig_for_version((int) $v['id'])) {
        return null; // assinado antes deste fluxo existir
    }
    $roles = array_count_values(array_filter(array_map(fn($b) => $b['papel'] ?? null, sig_tag_blocks(doc_blocks((string) $v['content']), $c))));
    $witnesses = count(array_filter(array_keys($roles), fn($r) => str_starts_with((string) $r, 'testemunha')));
    $office = isset($roles['escritorio']);
    if (!$witnesses && !$office) {
        return null;
    }
    // versão anterior do mesmo documento: o pedido antigo é substituído
    foreach (all("SELECT r.id, r.code FROM sig_requests r JOIN case_artifact_versions v ON v.id = r.version_id WHERE r.case_id = ? AND v.kind = ? AND r.status NOT IN ('concluido','substituido')", [$c['id'], $v['kind']]) as $old) {
        q("UPDATE sig_requests SET status = 'substituido', updated_at = ? WHERE id = ?", [now_str(), $old['id']]);
        sig_log((string) $old['code'], 'pedido_substituido', ['nova_versao' => (int) $v['version']]);
    }
    do {
        $code = strtoupper(implode('-', str_split(substr(bin2hex(random_bytes(6)), 0, 12), 4)));
    } while (one('SELECT 1 FROM sig_requests WHERE code = ?', [$code]) || one('SELECT 1 FROM doc_signatures WHERE code = ?', [$code]) || one('SELECT 1 FROM sig_log WHERE code = ?', [$code]));
    $def = sig_default_users();
    $rid = insert('sig_requests', ['case_id' => $c['id'], 'version_id' => $v['id'], 'code' => $code, 'status' => $witnesses ? 'testemunhas' : 'advogado', 'created_at' => now_str(), 'updated_at' => now_str()]);
    for ($i = 1; $i <= $witnesses; $i++) {
        $uid = $def['testemunhas'][$i - 1] ?? null;
        insert('sig_parties', ['request_id' => $rid, 'role' => 'testemunha', 'ord' => $i, 'user_id' => $uid && one('SELECT 1 FROM users WHERE id = ? AND active = 1', [$uid]) ? $uid : null]);
    }
    if ($office) {
        insert('sig_parties', ['request_id' => $rid, 'role' => 'advogado', 'ord' => 1, 'user_id' => $def['advogado'] ?: null]);
    }
    sig_log($code, 'pedido_criado', ['documento' => kit_label($v['kind']), 'versao' => (int) $v['version'], 'testemunhas' => $witnesses, 'advogado' => $office]);
    add_event((int) $c['id'], 'assinatura', 'Assinaturas do escritório pedidas: ' . kit_label($v['kind']) . ' v' . $v['version'],
        ($witnesses ? $witnesses . ' testemunha(s)' : '') . ($office ? ($witnesses ? ' e ' : '') . 'o advogado' : '') . ' assinam antes do cliente.');
    return one('SELECT * FROM sig_requests WHERE id = ?', [$rid]);
}

/** Passa o pedido para a etapa seguinte quando a atual termina. */
function sig_request_advance(int $rid): void
{
    $req = one('SELECT * FROM sig_requests WHERE id = ?', [$rid]);
    if (!$req || in_array($req['status'], ['concluido', 'substituido', 'cliente'], true)) {
        return;
    }
    $ps = sig_parties($rid);
    $pendW = array_filter($ps, fn($p) => $p['role'] === 'testemunha' && !$p['signed_at']);
    $pendA = array_filter($ps, fn($p) => $p['role'] === 'advogado' && !$p['signed_at']);
    $st = $pendW ? 'testemunhas' : ($pendA ? 'advogado' : 'cliente');
    if ($st === $req['status']) {
        return;
    }
    q('UPDATE sig_requests SET status = ?, updated_at = ? WHERE id = ?', [$st, now_str(), $rid]);
    $v = one('SELECT * FROM case_artifact_versions WHERE id = ?', [$req['version_id']]);
    $label = kit_label($v['kind']);
    if ($st === 'advogado') {
        $adv = reset($pendA);
        set_next((int) $req['case_id'], 'Assinar pelo escritório: ' . $label, in_business_days(1));
        add_event((int) $req['case_id'], 'assinatura', 'Testemunhas assinaram: ' . $label, 'Falta a assinatura do advogado pelo escritório.');
    }
    if ($st === 'cliente') {
        $c = find_case((int) $req['case_id']);
        add_event((int) $req['case_id'], 'assinatura', 'Pronto para o cliente assinar: ' . $label, 'O escritório assinou (testemunhas e advogado). O documento está na área do cliente.', true);
        chat_post((int) $req['case_id'], 'operator', 'O documento “' . $label . '” já foi assinado pelo escritório e está pronto para a sua assinatura em “Reunião e proposta”.');
        sig_notify_client($c, $v);
        set_next((int) $req['case_id'], 'Aguardar a assinatura do cliente: ' . $label, in_business_days(2));
    }
}

function sig_notify_client(array $c, array $v): void
{
    $email = (string) $c['client_email'];
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return;
    }
    $label = kit_label($v['kind']);
    $href = $c['account_id'] ? client_access_link((int) $c['account_id'], (int) $c['id']) : public_link('cliente');
    $lines = ['Olá, ' . first_name((string) $c['client_name']) . '! O documento “' . $label . '” (atendimento ' . $c['protocol'] . ') já foi assinado pelo escritório e está pronto para a sua assinatura.',
        'Abra a sua área do cliente, leia o documento e assine. Se algo estiver errado, você pode pedir retificação.'];
    send_mail($email, 'Documento pronto para assinar: ' . $label, implode("\n\n", $lines) . "\n\n" . $href, mail_html('Documento pronto para assinar', $lines, 'Abrir a área do cliente', $href), (int) $c['id'], 'assinatura');
}

/* ---------- Selos da equipe ---------- */

/** Dados do selo de um integrante já assinado (como ficou registrado). */
function sig_party_meta(array $p): array
{
    return (json_decode((string) $p['seal'], true) ?: [])['meta'] ?? [];
}

/** Selos já assinados do pedido (operações na página), com o link do registro. */
function sig_party_seals(?array $req, ?int $exceptParty = null): array
{
    if (!$req) {
        return [];
    }
    $out = [];
    foreach (sig_parties((int) $req['id']) as $p) {
        if (!$p['signed_at'] || (int) $p['id'] === $exceptParty) {
            continue;
        }
        $seal = json_decode((string) $p['seal'], true) ?: [];
        $ops = pdoc_move(sig_seal_ops($seal['meta'] ?? [], json_decode((string) $p['strokes'], true)), (float) $seal['x'], (float) $seal['y']);
        $ops[] = ['link', (float) $seal['x'], (float) $seal['y'], SEAL_W, SEAL_H, public_link('verificar/' . $req['code'])];
        $out[] = ['pagina' => (int) $seal['pagina'], 'ops' => $ops, 'rect' => [(float) $seal['x'], (float) $seal['y'], SEAL_W, SEAL_H]];
    }
    return $out;
}

/** Retângulos (por página) dos selos já postos, para os seguintes não os cobrirem. */
function sig_party_rects(?array $req, ?int $exceptParty = null): array
{
    $out = [];
    foreach (sig_party_seals($req, $exceptParty) as $s) {
        $out[$s['pagina']][] = $s['rect'];
    }
    return $out;
}

/** Texto do rótulo do selo conforme o papel e a forma de assinar. */
function sig_seal_label(string $role, ?array $cert = null, string $method = 'senha'): string
{
    $digital = in_array($method, ['a1', 'a3', 'certificado_a1', 'certificado_a3', 'certificado_externo'], true);
    $cert_label = !empty($cert['icp_brasil']) ? 'CERTIFICADO ICP-BRASIL' : 'CERTIFICADO DIGITAL';
    return match ($role) {
        'testemunha' => 'TESTEMUNHA · ' . ($digital ? $cert_label : 'ASSINADO ELETRONICAMENTE'),
        'advogado' => $digital ? 'ASSINADO COM ' . $cert_label : 'ASSINADO ELETRONICAMENTE',
        default => 'ASSINADO ELETRONICAMENTE',
    };
}

/** Papel do bloco onde o selo do integrante vai por padrão. */
function sig_party_block(array $p): string
{
    return $p['role'] === 'testemunha' ? 'testemunha' . (int) $p['ord'] : 'escritorio';
}

/** Integrante da equipe pode assinar agora? [ok, motivo]. */
function sig_party_check(array $p, array $u): array
{
    $req = one('SELECT * FROM sig_requests WHERE id = ?', [$p['request_id']]);
    if (!$req || in_array($req['status'], ['substituido'], true)) {
        return [false, 'Este pedido foi substituído por uma nova versão do documento.'];
    }
    if ($p['signed_at']) {
        return [false, 'Assinatura já registrada.'];
    }
    if ((int) $p['user_id'] !== (int) $u['id']) {
        return [false, 'Esta assinatura foi pedida a outra pessoa da equipe.'];
    }
    if ($p['role'] === 'advogado' && $req['status'] !== 'advogado') {
        return [false, 'O advogado assina depois das testemunhas.'];
    }
    foreach (sig_parties((int) $req['id']) as $o) {
        if (!$o['user_id']) {
            return [false, 'Falta escolher quem assina como ' . mb_strtolower(SIG_PARTY_LABELS[$o['role']]) . ($o['role'] === 'testemunha' ? ' ' . $o['ord'] : '') . ' (em Assinaturas).'];
        }
    }
    if ($miss = team_profile_missing($u)) {
        return [false, 'Complete os seus dados em Minha conta: ' . implode(', ', $miss) . '.'];
    }
    if (!team_signature($u)) {
        return [false, 'Desenhe a sua rubrica em Minha conta (ela é pedida uma vez e reaproveitada).'];
    }
    return [true, null];
}

/** Evidências comuns da assinatura de um integrante. */
function sig_party_evidence(array $u, array $v, array $seal, string $consent, array $extra = []): array
{
    $now = now();
    return ['usuario' => ['id' => (int) $u['id'], 'login' => $u['email'], 'qualificacao' => team_qualification($u)],
        'autenticacao' => ['meio' => 'sessão do usuário da equipe no painel', 'sessao' => hash('sha256', session_id())],
        'documento' => ['tipo' => $v['kind'], 'versao' => (int) $v['version'], 'hash_sha256_texto' => sig_doc_hash($v)],
        'momento' => ['brasilia' => $now->format('Y-m-d H:i:s'), 'utc' => $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'), 'fuso' => $now->format('P')],
        'rede' => ['ip' => client_ip()], 'aparelho' => ['navegador' => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 400)],
        'selo' => $seal + ['largura_mm' => SEAL_W, 'altura_mm' => SEAL_H], 'aceite' => $consent] + $extra;
}

const SIG_WITNESS_CONSENT = 'Assino como testemunha o documento acima, na versão exibida, e reconheço esta assinatura, feita com o meu usuário do sistema, como válida (MP 2.200-2/2001, art. 10, § 2º).';
const SIG_LAWYER_CONSENT = 'Assino o documento acima, na versão exibida, em nome do escritório, e reconheço esta assinatura como válida (MP 2.200-2/2001, art. 10).';

/** Posição recebida da tela (ou a sugerida), já dentro da folha. */
function sig_party_position(array $p, array $c, array $v, ?array $req, array $in): array
{
    $L = sig_doc_layout($v, $req);
    $pos = is_string($in['selo'] ?? null) ? json_decode((string) $in['selo'], true) : null;
    return is_array($pos) && is_numeric($pos['pagina'] ?? null) && is_numeric($pos['x'] ?? null) && is_numeric($pos['y'] ?? null)
        ? sig_seal_clamp($pos, count($L['pages'])) + ['visualizacao' => array_intersect_key($pos, array_flip(['largura_folha_px', 'zoom', 'dpr', 'orientacao', 'tela']))]
        : sig_seal_place($L, sig_party_block($p), sig_party_rects($req, (int) $p['id']));
}

/**
 * PDF em assinatura (a versão mais recente, conferida pelo hash). Na primeira
 * assinatura da equipe, gera o documento (com os nomes das testemunhas nas
 * linhas delas) e o guarda: a partir daí, cada selo e cada assinatura digital
 * entram como acréscimo ao fim do arquivo. Retorna [pdf, sha, erro].
 */
function sig_team_current(array $req): array
{
    $dir = sig_storage_dir();
    if ($req['signed_path']) {
        $bin = (string) @file_get_contents($dir . '/' . basename((string) $req['signed_path']));
        if ($bin === '' || !hash_equals((string) $req['signed_sha256'], hash('sha256', $bin))) {
            sig_log((string) $req['code'], 'divergencia_arquivo', ['arquivo' => 'PDF em assinatura ausente ou alterado']);
            return [null, null, 'O PDF em assinatura não confere com o registrado. Fale com Sidney.'];
        }
        return [$bin, $req['signed_sha256'], null];
    }
    $v = one('SELECT * FROM case_artifact_versions WHERE id = ?', [$req['version_id']]);
    $c = find_case((int) $req['case_id']);
    $L = sig_doc_layout($v, $req);
    // selos já registrados antes deste formato (no conteúdo da página)
    foreach (sig_party_seals($req) as $s) {
        $L['pages'][$s['pagina'] - 1] = array_merge($L['pages'][$s['pagina'] - 1], $s['ops']);
    }
    $pdf = pdoc_pdf($L, ['titulo' => kit_label($v['kind']) . ' · ' . $c['protocol'] . ' · v' . $v['version'], 'assunto' => 'Documento em assinatura · código ' . $req['code'],
        'palavras' => 'verificação ' . $req['code'], 'autor' => office('lawyer') ?: 'Sidney Fatuche', 'fontes' => ['poppins', 'poppinsb', 'poppinssb']]);
    $file = $req['code'] . '-0.pdf';
    file_put_contents($dir . '/' . $file, $pdf);
    @chmod($dir . '/' . $file, 0440);
    $sha = hash('sha256', $pdf);
    q('UPDATE sig_requests SET base_path = ?, base_sha256 = ?, signed_path = ?, signed_sha256 = ?, updated_at = ? WHERE id = ? AND signed_path IS NULL', [$file, $sha, $file, $sha, now_str(), $req['id']]);
    sig_log((string) $req['code'], 'pdf_gerado', ['hash_pdf' => $sha, 'bytes' => strlen($pdf), 'paginas' => count($L['pages'])]);
    $req = one('SELECT * FROM sig_requests WHERE id = ?', [$req['id']]);
    return [$pdf, $req['signed_sha256'], $req['signed_sha256'] === $sha ? null : 'Outra pessoa começou a assinar agora. Tente de novo.'];
}

/** Selo do integrante como carimbo (acréscimo) no PDF atual. [pdf, selo, dados do selo]. */
function sig_team_stamp(string $pdf, array $p, array $u, array $req, array $in, string $method, ?array $cert): array
{
    $v = one('SELECT * FROM case_artifact_versions WHERE id = ?', [$req['version_id']]);
    $c = find_case((int) $req['case_id']);
    $seal = sig_party_position($p, $c, $v, $req, $in);
    [$quando, $fuso] = sig_when(now());
    $meta = ['nome' => team_profile($u)['nome_completo'], 'quando' => $quando, 'fuso' => $fuso, 'codigo' => $req['code'], 'host' => preg_replace('#^https?://#', '', rtrim(public_root(), '/')),
        'rotulo' => sig_seal_label($p['role'], $cert, $method)];
    $rect = [$seal['x'], $seal['y'], SEAL_W, SEAL_H];
    $who = SIG_PARTY_LABELS[$p['role']] . ($p['role'] === 'testemunha' ? ' ' . $p['ord'] : '');
    $out = pdfx_add_annots($pdf, [$seal['pagina'] - 1 => [
        ['draw' => pdoc_move(sig_seal_ops($meta, team_signature($u)), $seal['x'], $seal['y']), 'rect' => $rect, 'name' => 'Assinatura', 'title' => $who . ': ' . $meta['nome']],
        ['link' => public_link('verificar/' . $req['code']), 'rect' => $rect],
    ]], ['poppins', 'poppinsb', 'poppinssb']);
    return [$out, $seal, $meta];
}

/**
 * Assina pela forma cadastrada: senha do sistema ou certificado A1 (senha do
 * certificado). O certificado A3 é em dois passos (sig_team_prepare_a3 e
 * sig_team_upload_a3). Retorna erro ou null.
 */
function sig_team_sign(array $p, array $u, array $in): ?string
{
    [$ok, $why] = sig_party_check($p, $u);
    if (!$ok) {
        return $why;
    }
    if (($in['aceite'] ?? '') !== '1') {
        return 'Marque a declaração de assinatura.';
    }
    $method = team_sign_method($u);
    if ($method === 'a3') {
        return 'Seu certificado é A3 (token): baixe o PDF, assine no token e envie de volta.';
    }
    $cert = null;
    $info = null;
    if ($method === 'a1') {
        [$cert, $err] = pdfx_open_pfx((string) file_get_contents(team_cert_dir() . '/' . basename((string) $u['cert_path'])), (string) ($in['senha_certificado'] ?? ''));
        if (!$cert) {
            return $err;
        }
        $info = pdfx_cert_info($cert['cert']);
        if ($err = team_cert_check($u, $info)) {
            return $err;
        }
    } elseif (!password_verify((string) ($in['senha'] ?? ''), (string) $u['pass_hash'])) {
        return 'Senha incorreta. Use a mesma senha de entrada no sistema.';
    }
    $req = one('SELECT * FROM sig_requests WHERE id = ?', [$p['request_id']]);
    [$pdf, $prev, $err] = sig_team_current($req);
    if (!$pdf) {
        return $err;
    }
    [$out, $seal, $meta] = sig_team_stamp($pdf, $p, $u, $req, $in, $method, $info);
    if ($cert) {
        $v = one('SELECT * FROM case_artifact_versions WHERE id = ?', [$req['version_id']]);
        [$out, $err] = pdfx_sign($out, $cert, $seal['pagina'] - 1, [$seal['x'], $seal['y'], SEAL_W, SEAL_H],
            ['nome' => $info['titular'] ?: $meta['nome'], 'motivo' => SIG_PARTY_LABELS[$p['role']] . ' · ' . kit_label($v['kind']) . ' v' . $v['version'], 'local' => 'Brasil',
                'contato' => (string) (team_profile($u)['email'] ?? ''), 'campo' => SIG_PARTY_LABELS[$p['role']] . ' ' . $p['ord'] . ' · ' . $req['code']]);
        if (!$out) {
            return $err;
        }
    }
    return sig_team_finish($p, $u, $req, $prev, $out, $seal, $meta, $cert ? 'certificado_a1' : 'senha', $info);
}

/** A3: gera o PDF com o selo para assinar no token (guarda de qual versão partiu). [pdf, erro]. */
function sig_team_prepare_a3(array $p, array $u, array $in): array
{
    [$ok, $why] = sig_party_check($p, $u);
    if (!$ok) {
        return [null, $why];
    }
    $req = one('SELECT * FROM sig_requests WHERE id = ?', [$p['request_id']]);
    [$pdf, $prev, $err] = sig_team_current($req);
    if (!$pdf) {
        return [null, $err];
    }
    [$out, $seal, $meta] = sig_team_stamp($pdf, $p, $u, $req, $in, 'a3', team_cert($u));
    file_put_contents(sig_storage_dir() . '/' . $req['code'] . '-a3-' . (int) $p['id'] . '.pdf', $out);
    q('UPDATE sig_parties SET seal = ? WHERE id = ? AND signed_at IS NULL', [json_encode($seal + ['meta' => $meta, 'parte_de' => $prev], JSON_UNESCAPED_UNICODE), $p['id']]);
    return [$out, null];
}

/**
 * A3: recebe o PDF assinado no token. Confere que é o arquivo baixado (os bytes
 * dele ficam intactos no começo), que a assinatura é íntegra e que o CPF do
 * certificado é o do cadastro. Retorna erro ou null.
 */
function sig_team_upload_a3(array $p, array $u, string $uploaded): ?string
{
    [$ok, $why] = sig_party_check($p, $u);
    if (!$ok) {
        return $why;
    }
    $pending = json_decode((string) $p['seal'], true) ?: [];
    $req = one('SELECT * FROM sig_requests WHERE id = ?', [$p['request_id']]);
    $path = sig_storage_dir() . '/' . $req['code'] . '-a3-' . (int) $p['id'] . '.pdf';
    if (!is_file($path) || empty($pending['meta'])) {
        return 'Baixe primeiro o PDF para assinar com o token.';
    }
    $base = (string) file_get_contents($path);
    if (!str_starts_with($uploaded, $base) || strlen($uploaded) <= strlen($base)) {
        return 'O arquivo enviado não é o PDF baixado para assinatura (ou não recebeu a assinatura).';
    }
    if (($pending['parte_de'] ?? '') !== $req['signed_sha256']) {
        return 'Outra pessoa assinou enquanto isso. Baixe o PDF de novo e assine a versão atual.';
    }
    $sigs = pdfx_signatures($uploaded);
    $last = end($sigs);
    if (!$last || !$last['integra'] || $last['cobre_bytes'] < strlen($base)) {
        return 'Não encontrei uma assinatura digital válida no arquivo.';
    }
    $info = $last['certificado'] ?? [];
    $cpf = only_digits((string) team_profile($u)['cpf']);
    if (empty($info['cpf']) || $info['cpf'] !== $cpf) {
        return 'O certificado usado não é o do seu CPF.';
    }
    $seal = array_diff_key($pending, ['meta' => 1, 'parte_de' => 1]);
    return sig_team_finish($p, $u, $req, (string) $pending['parte_de'], $uploaded, $seal, $pending['meta'], 'certificado_a3', $info);
}

/** Grava a nova versão do PDF (se ninguém assinou ao mesmo tempo) e registra a assinatura. */
function sig_team_finish(array $p, array $u, array $req, string $prev, string $pdf, array $seal, array $meta, string $method, ?array $info): ?string
{
    if ($info) {
        $check = pdfx_signatures($pdf);
        $last = end($check);
        if (!$last || !$last['integra']) {
            return 'A assinatura digital não confere com o documento.';
        }
    }
    $n = 1 + (int) scalar('SELECT COUNT(*) FROM sig_parties WHERE request_id = ? AND signed_at IS NOT NULL', [$req['id']]);
    $file = $req['code'] . '-' . $n . '.pdf';
    $path = sig_storage_dir() . '/' . $file;
    @chmod($path, 0600);
    file_put_contents($path, $pdf);
    @chmod($path, 0440);
    $sha = hash('sha256', $pdf);
    $st = db()->prepare('UPDATE sig_requests SET signed_path = ?, signed_sha256 = ?, updated_at = ? WHERE id = ? AND signed_sha256 = ?');
    $st->execute([$file, $sha, now_str(), $req['id'], $prev]);
    if ($st->rowCount() !== 1) {
        return 'Outra pessoa assinou ao mesmo tempo. Abra a página de novo e assine a versão atual.';
    }
    $v = one('SELECT * FROM case_artifact_versions WHERE id = ?', [$req['version_id']]);
    $pr = team_profile($u);
    $meio = ['senha' => 'senha do usuário confirmada no momento da assinatura', 'certificado_a1' => 'certificado digital A1', 'certificado_a3' => 'certificado digital A3 (token), assinado fora do sistema'][$method];
    $ev = sig_party_evidence($u, $v, $seal, $p['role'] === 'testemunha' ? SIG_WITNESS_CONSENT : SIG_LAWYER_CONSENT,
        ['meio' => $meio, 'certificado' => $info, 'pdf_anterior_sha256' => $prev, 'pdf_assinado_sha256' => $sha]);
    q('UPDATE sig_parties SET name = ?, cpf = ?, seal = ?, strokes = ?, method = ?, cert_info = ?, evidence = ?, ip = ?, user_agent = ?, signed_at = ? WHERE id = ? AND signed_at IS NULL',
        [$pr['nome_completo'], only_digits((string) $pr['cpf']), json_encode($seal + ['meta' => $meta], JSON_UNESCAPED_UNICODE), json_encode(team_signature($u)), $method,
            $info ? json_encode($info, JSON_UNESCAPED_UNICODE) : null, json_encode($ev, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), client_ip(), mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 400), now_str(), $p['id']]);
    sig_log((string) $req['code'], $p['role'] === 'testemunha' ? 'testemunha_assinou' : 'advogado_assinou', ['ordem' => (int) $p['ord'], 'nome' => $pr['nome_completo'], 'cpf' => sig_fmt_cpf((string) $pr['cpf']),
        'usuario' => (int) $u['id'], 'meio' => $method, 'certificado' => $info ? ['titular' => $info['titular'], 'cpf' => $info['cpf'] ? sig_fmt_cpf($info['cpf']) : null, 'emissor' => $info['emissor'], 'serie' => $info['serie'], 'sha256' => $info['sha256']] : null,
        'hash_pdf' => $sha, 'selo' => $seal]);
    add_event((int) $req['case_id'], 'assinatura', ($p['role'] === 'testemunha' ? 'Testemunha assinou: ' : 'Advogado assinou: ') . $pr['nome_completo'],
        kit_label($v['kind']) . ' v' . $v['version'] . ' · ' . $meio . ' · selo na página ' . $seal['pagina'] . '.');
    @unlink(sig_storage_dir() . '/' . $req['code'] . '-a3-' . (int) $p['id'] . '.pdf');
    sig_request_advance((int) $req['id']);
    return null;
}

/** Assinaturas que esperam o usuário (as que ele já pode fazer). */
function sig_my_pending(int $uid): array
{
    return all("SELECT p.*, r.code, r.status AS req_status, r.case_id, v.kind, v.version, c.protocol, c.client_name FROM sig_parties p
        JOIN sig_requests r ON r.id = p.request_id JOIN case_artifact_versions v ON v.id = r.version_id JOIN cases c ON c.id = r.case_id
        WHERE p.user_id = ? AND p.signed_at IS NULL AND ((p.role = 'testemunha' AND r.status = 'testemunhas') OR (p.role = 'advogado' AND r.status = 'advogado'))
        ORDER BY r.created_at", [$uid]);
}

/** Assinantes para a faixa e a conferência: equipe (assinados) e o cliente. */
function sig_signers_list(?array $req, ?array $clientSig, array $c): array
{
    $out = [];
    if ($clientSig || !$req) {
        $out[] = ['nome' => $clientSig['signer_name'] ?? sig_expected_name($c), 'papel' => 'cliente', 'quando' => $clientSig ? sig_when(dt($clientSig['signed_at']))[0] : null];
    }
    if ($req) {
        foreach (sig_parties((int) $req['id']) as $p) {
            if ($p['signed_at']) {
                $out[] = ['nome' => $p['name'], 'papel' => $p['role'], 'quando' => sig_when(dt($p['signed_at']))[0], 'metodo' => $p['method']];
            }
        }
        if (!$clientSig) {
            array_unshift($out, ['nome' => sig_expected_name($c), 'papel' => 'cliente', 'quando' => null]);
        }
    }
    return $out;
}
