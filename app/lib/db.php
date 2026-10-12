<?php
declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $dir = config('storage_dir');
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }
    $file = getenv('FATUCHE_DB') ?: $dir . '/fatuche.sqlite';
    $pdo = new PDO('sqlite:' . $file, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    migrate($pdo);
    return $pdo;
}

function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function one(string $sql, array $params = []): ?array
{
    $r = q($sql, $params)->fetch();
    return $r === false ? null : $r;
}

function all(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

function scalar(string $sql, array $params = []): mixed
{
    $v = q($sql, $params)->fetchColumn();
    return $v === false ? null : $v;
}

function insert(string $table, array $row): int
{
    $cols = array_keys($row);
    $sql = sprintf(
        'INSERT INTO %s (%s) VALUES (%s)',
        $table,
        implode(',', $cols),
        implode(',', array_map(fn($c) => ':' . $c, $cols))
    );
    q($sql, $row);
    return (int) db()->lastInsertId();
}

function update(string $table, int $id, array $row): void
{
    if (!$row) {
        return;
    }
    $set = implode(',', array_map(fn($c) => "$c = :$c", array_keys($row)));
    $row['__id'] = $id;
    q("UPDATE $table SET $set WHERE id = :__id", $row);
}

function migrate(PDO $pdo): void
{
    $version = (int) $pdo->query('PRAGMA user_version')->fetchColumn();
    $migrations = [
        1 => <<<SQL
CREATE TABLE users (
    id INTEGER PRIMARY KEY,
    name TEXT NOT NULL,
    email TEXT NOT NULL UNIQUE,
    phone TEXT,
    pass_hash TEXT NOT NULL,
    roles TEXT NOT NULL DEFAULT 'gestor',
    active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL
);

CREATE TABLE cases (
    id INTEGER PRIMARY KEY,
    protocol TEXT NOT NULL UNIQUE,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    source TEXT NOT NULL DEFAULT 'site',
    origin_page TEXT,
    client_name TEXT NOT NULL,
    client_phone TEXT,
    client_email TEXT,
    client_city TEXT,
    client_uf TEXT,
    contact_pref TEXT,
    contact_time TEXT,
    accessibility TEXT,
    notice TEXT,
    notice_date TEXT,
    bank TEXT,
    contract_status TEXT,
    installment_value REAL,
    overdue TEXT,
    overdue_count TEXT,
    has_contract TEXT,
    other_lawyer TEXT,
    priority TEXT,
    priority_text TEXT,
    budget_installment REAL,
    summary_sentence TEXT,
    summary_confirmed INTEGER NOT NULL DEFAULT 0,
    description TEXT,
    consent_at TEXT,
    stage TEXT NOT NULL DEFAULT 'acolhimento',
    stage_since TEXT NOT NULL,
    urgent INTEGER NOT NULL DEFAULT 0,
    urgency_class TEXT,
    urgency_assessed_at TEXT,
    objective TEXT,
    objective_measure TEXT,
    gestor_id INTEGER REFERENCES users(id),
    advogado_id INTEGER REFERENCES users(id),
    substituto_id INTEGER REFERENCES users(id),
    next_action TEXT,
    next_action_due TEXT,
    next_action_owner_id INTEGER REFERENCES users(id),
    client_next_action TEXT,
    next_client_update TEXT,
    last_client_update TEXT,
    first_human_contact_at TEXT,
    docs_sufficient_at TEXT,
    status TEXT NOT NULL DEFAULT 'ativo',
    outcome TEXT,
    closed_at TEXT,
    portal_pass_hash TEXT,
    portal_code_hash TEXT,
    portal_code_expires TEXT,
    data TEXT NOT NULL DEFAULT '{}'
);
CREATE INDEX idx_cases_stage ON cases(stage, status);

CREATE TABLE case_checks (
    id INTEGER PRIMARY KEY,
    case_id INTEGER NOT NULL REFERENCES cases(id) ON DELETE CASCADE,
    stage TEXT NOT NULL,
    item TEXT NOT NULL,
    done INTEGER NOT NULL DEFAULT 0,
    note TEXT,
    done_by INTEGER REFERENCES users(id),
    done_at TEXT,
    UNIQUE(case_id, stage, item)
);

CREATE TABLE documents (
    id INTEGER PRIMARY KEY,
    case_id INTEGER NOT NULL REFERENCES cases(id) ON DELETE CASCADE,
    kind TEXT NOT NULL,
    label TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'falta',
    note TEXT,
    essential INTEGER NOT NULL DEFAULT 1,
    requested_at TEXT NOT NULL,
    received_at TEXT,
    reviewed_at TEXT,
    reviewed_by INTEGER REFERENCES users(id)
);

CREATE TABLE files (
    id INTEGER PRIMARY KEY,
    case_id INTEGER NOT NULL REFERENCES cases(id) ON DELETE CASCADE,
    document_id INTEGER REFERENCES documents(id) ON DELETE SET NULL,
    original_name TEXT NOT NULL,
    stored_name TEXT NOT NULL,
    mime TEXT NOT NULL,
    size INTEGER NOT NULL,
    uploaded_by_client INTEGER NOT NULL DEFAULT 0,
    uploaded_by INTEGER REFERENCES users(id),
    uploaded_at TEXT NOT NULL,
    reviewed_at TEXT
);

CREATE TABLE tasks (
    id INTEGER PRIMARY KEY,
    case_id INTEGER REFERENCES cases(id) ON DELETE CASCADE,
    title TEXT NOT NULL,
    owner_id INTEGER REFERENCES users(id),
    due_at TEXT,
    depends_on TEXT NOT NULL DEFAULT 'equipe',
    kind TEXT,
    status TEXT NOT NULL DEFAULT 'aberta',
    evidence TEXT,
    created_by INTEGER REFERENCES users(id),
    created_at TEXT NOT NULL,
    done_at TEXT
);
CREATE INDEX idx_tasks_open ON tasks(status, due_at);

CREATE TABLE events (
    id INTEGER PRIMARY KEY,
    case_id INTEGER NOT NULL REFERENCES cases(id) ON DELETE CASCADE,
    at TEXT NOT NULL,
    actor TEXT NOT NULL,
    user_id INTEGER REFERENCES users(id),
    type TEXT NOT NULL,
    title TEXT NOT NULL,
    body TEXT,
    visible_to_client INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX idx_events_case ON events(case_id, at);

CREATE TABLE messages (
    id INTEGER PRIMARY KEY,
    case_id INTEGER NOT NULL REFERENCES cases(id) ON DELETE CASCADE,
    template TEXT,
    channel TEXT NOT NULL,
    body TEXT NOT NULL,
    facts_checked INTEGER NOT NULL DEFAULT 0,
    sent_by INTEGER REFERENCES users(id),
    sent_at TEXT NOT NULL
);

CREATE TABLE decisions (
    id INTEGER PRIMARY KEY,
    case_id INTEGER NOT NULL REFERENCES cases(id) ON DELETE CASCADE,
    kind TEXT NOT NULL,
    question TEXT NOT NULL,
    context TEXT,
    options TEXT NOT NULL DEFAULT '[]',
    answer TEXT,
    reason TEXT,
    answered_via TEXT,
    deadline TEXT,
    requested_by INTEGER REFERENCES users(id),
    requested_at TEXT NOT NULL,
    answered_at TEXT,
    recorded_by INTEGER REFERENCES users(id)
);

CREATE TABLE offers (
    id INTEGER PRIMARY KEY,
    case_id INTEGER NOT NULL REFERENCES cases(id) ON DELETE CASCADE,
    round INTEGER NOT NULL DEFAULT 1,
    origin TEXT NOT NULL DEFAULT 'credor',
    creditor TEXT,
    ref_balance REAL,
    ref_date TEXT,
    down_payment REAL,
    installments INTEGER,
    installment_value REAL,
    total REAL,
    charges TEXT,
    effects TEXT,
    valid_until TEXT,
    protocol TEXT,
    status TEXT NOT NULL DEFAULT 'recebida',
    communicated_at TEXT,
    notes TEXT,
    created_by INTEGER REFERENCES users(id),
    created_at TEXT NOT NULL
);

CREATE TABLE schedule (
    id INTEGER PRIMARY KEY,
    case_id INTEGER NOT NULL REFERENCES cases(id) ON DELETE CASCADE,
    description TEXT NOT NULL,
    due_date TEXT NOT NULL,
    amount REAL,
    status TEXT NOT NULL DEFAULT 'pendente',
    confirmed_at TEXT,
    proof TEXT
);

CREATE TABLE deadlines (
    id INTEGER PRIMARY KEY,
    case_id INTEGER NOT NULL REFERENCES cases(id) ON DELETE CASCADE,
    description TEXT NOT NULL,
    trigger_event TEXT,
    due_at TEXT NOT NULL,
    owner_id INTEGER REFERENCES users(id),
    substitute_id INTEGER REFERENCES users(id),
    status TEXT NOT NULL DEFAULT 'aberto',
    done_at TEXT,
    note TEXT
);

CREATE TABLE exceptions (
    id INTEGER PRIMARY KEY,
    case_id INTEGER NOT NULL REFERENCES cases(id) ON DELETE CASCADE,
    kind TEXT NOT NULL,
    note TEXT,
    opened_by INTEGER REFERENCES users(id),
    opened_at TEXT NOT NULL,
    resolved_at TEXT,
    resolution TEXT
);

CREATE TABLE feedback (
    id INTEGER PRIMARY KEY,
    case_id INTEGER NOT NULL REFERENCES cases(id) ON DELETE CASCADE,
    kind TEXT NOT NULL,
    ease INTEGER,
    simplify TEXT,
    answers TEXT NOT NULL DEFAULT '{}',
    created_at TEXT NOT NULL
);

CREATE TABLE audit (
    id INTEGER PRIMARY KEY,
    at TEXT NOT NULL,
    user_id INTEGER,
    case_id INTEGER,
    action TEXT NOT NULL,
    detail TEXT
);

CREATE TABLE login_attempts (
    id INTEGER PRIMARY KEY,
    bucket TEXT NOT NULL,
    at TEXT NOT NULL
);
CREATE INDEX idx_login_attempts ON login_attempts(bucket, at);
SQL,
    ];

    // Versão 2 — reforma do atendimento inicial (operador único, coleta por chatbot,
    // reunião e kit). Somente acréscimos: nenhuma tabela ou coluna antiga é removida.
    $migrations[2] = <<<SQL
CREATE TABLE IF NOT EXISTS settings (
    key TEXT PRIMARY KEY,
    value TEXT,
    updated_at TEXT
);
CREATE TABLE client_accounts (
    id INTEGER PRIMARY KEY,
    name TEXT,
    phone TEXT,
    email TEXT,
    pass_hash TEXT,
    created_at TEXT NOT NULL,
    last_login_at TEXT
);
ALTER TABLE cases ADD COLUMN workflow_version TEXT NOT NULL DEFAULT 'legacy';
ALTER TABLE cases ADD COLUMN initial_status TEXT;
ALTER TABLE cases ADD COLUMN operator_id INTEGER REFERENCES users(id);
ALTER TABLE cases ADD COLUMN account_id INTEGER REFERENCES client_accounts(id);
ALTER TABLE cases ADD COLUMN previous_case_id INTEGER REFERENCES cases(id);
ALTER TABLE cases ADD COLUMN duplicate_of INTEGER REFERENCES cases(id);
ALTER TABLE cases ADD COLUMN row_version INTEGER NOT NULL DEFAULT 1;
ALTER TABLE cases ADD COLUMN chat_mode TEXT NOT NULL DEFAULT 'bot';
ALTER TABLE cases ADD COLUMN intake_edit TEXT;
ALTER TABLE cases ADD COLUMN intake_done_at TEXT;
ALTER TABLE cases ADD COLUMN urgency_signal TEXT;
ALTER TABLE cases ADD COLUMN urgency_reason TEXT;
ALTER TABLE cases ADD COLUMN urgency_date TEXT;
ALTER TABLE cases ADD COLUMN urgency_reviewed_at TEXT;
ALTER TABLE cases ADD COLUMN urgency_opinion TEXT;
ALTER TABLE cases ADD COLUMN review_flag TEXT;
ALTER TABLE cases ADD COLUMN sufficiency_at TEXT;
ALTER TABLE cases ADD COLUMN sufficiency_note TEXT;
ALTER TABLE cases ADD COLUMN urgent_scope TEXT;
ALTER TABLE cases ADD COLUMN preparation_at TEXT;
ALTER TABLE cases ADD COLUMN decision TEXT NOT NULL DEFAULT 'pendente';
ALTER TABLE cases ADD COLUMN decision_at TEXT;
ALTER TABLE cases ADD COLUMN decision_note TEXT;
ALTER TABLE cases ADD COLUMN contract_state TEXT NOT NULL DEFAULT 'nao_preparado';
ALTER TABLE cases ADD COLUMN contract_signed_at TEXT;
ALTER TABLE cases ADD COLUMN contract_file_id INTEGER;
ALTER TABLE cases ADD COLUMN contract_verified_at TEXT;
ALTER TABLE cases ADD COLUMN initial_completed_at TEXT;
ALTER TABLE cases ADD COLUMN closure_reason TEXT;
ALTER TABLE cases ADD COLUMN closure_communicated_at TEXT;
ALTER TABLE cases ADD COLUMN closure_channel TEXT;
ALTER TABLE cases ADD COLUMN access_expires_at TEXT;
ALTER TABLE cases ADD COLUMN return_requested_at TEXT;
ALTER TABLE cases ADD COLUMN activity_at TEXT;
ALTER TABLE cases ADD COLUMN activity_seen_at TEXT;
ALTER TABLE cases ADD COLUMN execution_started_at TEXT;
ALTER TABLE cases ADD COLUMN migrated_from_stage TEXT;
CREATE INDEX idx_cases_initial ON cases(workflow_version, initial_status);
CREATE INDEX idx_cases_account ON cases(account_id);

ALTER TABLE files ADD COLUMN client_key TEXT;
ALTER TABLE files ADD COLUMN kind TEXT;
ALTER TABLE files ADD COLUMN internal INTEGER NOT NULL DEFAULT 0;
ALTER TABLE files ADD COLUMN replaced_at TEXT;
CREATE UNIQUE INDEX idx_files_key ON files(case_id, client_key) WHERE client_key IS NOT NULL;
ALTER TABLE documents ADD COLUMN reject_reason TEXT;
ALTER TABLE documents ADD COLUMN origin TEXT;

CREATE TABLE case_fields (
    id INTEGER PRIMARY KEY,
    case_id INTEGER NOT NULL REFERENCES cases(id) ON DELETE CASCADE,
    field TEXT NOT NULL,
    value TEXT,
    state TEXT NOT NULL DEFAULT 'informado',
    source TEXT NOT NULL DEFAULT 'cliente',
    document_id INTEGER REFERENCES documents(id) ON DELETE SET NULL,
    updated_at TEXT NOT NULL,
    updated_by INTEGER REFERENCES users(id),
    UNIQUE(case_id, field)
);

CREATE TABLE case_pending_items (
    id INTEGER PRIMARY KEY,
    case_id INTEGER NOT NULL REFERENCES cases(id) ON DELETE CASCADE,
    type TEXT NOT NULL,
    ref TEXT,
    description TEXT NOT NULL,
    criticality TEXT NOT NULL DEFAULT 'pontual',
    status TEXT NOT NULL DEFAULT 'aberta',
    note TEXT,
    created_at TEXT NOT NULL,
    created_by INTEGER REFERENCES users(id),
    resolved_at TEXT
);
CREATE INDEX idx_pending_case ON case_pending_items(case_id, status);

CREATE TABLE chat_messages (
    id INTEGER PRIMARY KEY,
    case_id INTEGER NOT NULL REFERENCES cases(id) ON DELETE CASCADE,
    author TEXT NOT NULL,
    user_id INTEGER REFERENCES users(id),
    body TEXT NOT NULL,
    question TEXT,
    client_key TEXT,
    created_at TEXT NOT NULL
);
CREATE INDEX idx_chat_case ON chat_messages(case_id, id);
CREATE UNIQUE INDEX idx_chat_key ON chat_messages(case_id, client_key) WHERE client_key IS NOT NULL;

CREATE TABLE case_meetings (
    id INTEGER PRIMARY KEY,
    case_id INTEGER NOT NULL REFERENCES cases(id) ON DELETE CASCADE,
    status TEXT NOT NULL DEFAULT 'confirmada',
    channel TEXT NOT NULL,
    starts_at TEXT NOT NULL,
    duration_min INTEGER NOT NULL DEFAULT 30,
    url TEXT,
    phone TEXT,
    note TEXT,
    confirmed_at TEXT NOT NULL,
    confirmed_by INTEGER REFERENCES users(id),
    held_at TEXT,
    effective_channel TEXT,
    summary TEXT,
    corrections TEXT,
    guidance TEXT,
    risks TEXT,
    pending_left TEXT,
    proposal_version_id INTEGER,
    reaction TEXT,
    no_show_reason TEXT,
    replaced_by INTEGER REFERENCES case_meetings(id),
    created_at TEXT NOT NULL
);
CREATE INDEX idx_meetings_case ON case_meetings(case_id, status);

CREATE TABLE doc_templates (
    key TEXT PRIMARY KEY,
    title TEXT NOT NULL,
    body TEXT NOT NULL,
    version INTEGER NOT NULL DEFAULT 1,
    approved_at TEXT,
    approved_by INTEGER REFERENCES users(id),
    updated_at TEXT NOT NULL
);

CREATE TABLE case_artifact_versions (
    id INTEGER PRIMARY KEY,
    case_id INTEGER NOT NULL REFERENCES cases(id) ON DELETE CASCADE,
    kind TEXT NOT NULL,
    version INTEGER NOT NULL,
    template_version INTEGER,
    data_hash TEXT,
    data_snapshot TEXT,
    content TEXT,
    missing TEXT NOT NULL DEFAULT '[]',
    status TEXT NOT NULL DEFAULT 'minuta',
    created_at TEXT NOT NULL,
    created_by INTEGER REFERENCES users(id),
    reviewed_at TEXT,
    published_at TEXT,
    signed_file_id INTEGER REFERENCES files(id),
    UNIQUE(case_id, kind, version)
);
SQL;

    // Versão 3 — registro das chamadas à IA (OpenRouter): custo e erros visíveis a Sidney.
    $migrations[3] = <<<SQL
CREATE TABLE llm_log (
    id INTEGER PRIMARY KEY,
    case_id INTEGER REFERENCES cases(id) ON DELETE CASCADE,
    kind TEXT NOT NULL,
    model TEXT,
    ok INTEGER NOT NULL DEFAULT 0,
    error TEXT,
    prompt_tokens INTEGER,
    completion_tokens INTEGER,
    cost REAL,
    created_at TEXT NOT NULL
);
CREATE INDEX idx_llm_log_case ON llm_log(case_id, created_at);
SQL;

    // Versão 4 — variáveis personalizadas dos modelos de documentos.
    $migrations[4] = <<<SQL
CREATE TABLE template_vars (
    id INTEGER PRIMARY KEY,
    key TEXT NOT NULL UNIQUE,
    label TEXT NOT NULL,
    scope TEXT NOT NULL CHECK (scope IN ('escritorio','atendimento')),
    value TEXT,
    help TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
SQL;

    // Versão 5 — documentos no padrão do escritório (modelos com marcação e
    // timbrado). Os dados vêm de PHP: migrate_document_models().
    $migrations[5] = 'SELECT 1;';

    // Versão 6 — propostas: condições negociadas por atendimento (honorários,
    // êxito, valores à parte, repasses, pagamento e parcelamento).
    $migrations[6] = <<<SQL
CREATE TABLE proposals (
    id INTEGER PRIMARY KEY,
    case_id INTEGER NOT NULL UNIQUE REFERENCES cases(id) ON DELETE CASCADE,
    data TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    updated_by INTEGER REFERENCES users(id)
);
SQL;

    // Versão 7 — cobranças no Asaas geradas a partir do contrato assinado.
    $migrations[7] = <<<SQL
ALTER TABLE cases ADD COLUMN asaas_customer_id TEXT;
CREATE TABLE asaas_charges (
    id INTEGER PRIMARY KEY,
    case_id INTEGER NOT NULL REFERENCES cases(id) ON DELETE CASCADE,
    ref TEXT NOT NULL,
    label TEXT NOT NULL,
    env TEXT NOT NULL,
    asaas_id TEXT NOT NULL,
    installment_id TEXT,
    customer_id TEXT,
    billing_type TEXT NOT NULL,
    value_cents INTEGER NOT NULL,
    installments INTEGER NOT NULL DEFAULT 1,
    due_date TEXT NOT NULL,
    status TEXT NOT NULL,
    invoice_url TEXT,
    paid_at TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE INDEX idx_asaas_case ON asaas_charges(case_id);
CREATE INDEX idx_asaas_id ON asaas_charges(asaas_id);
SQL;

    // Versão 8 — conversas do cliente: o que ele já viu, avisos por e-mail e link de acesso.
    $migrations[8] = <<<SQL
ALTER TABLE cases ADD COLUMN client_seen_msg_id INTEGER NOT NULL DEFAULT 0;
ALTER TABLE cases ADD COLUMN client_seen_at TEXT;
ALTER TABLE cases ADD COLUMN client_notified_at TEXT;
ALTER TABLE client_accounts ADD COLUMN notify_email INTEGER NOT NULL DEFAULT 1;
CREATE TABLE client_access_links (
    id INTEGER PRIMARY KEY,
    account_id INTEGER NOT NULL REFERENCES client_accounts(id) ON DELETE CASCADE,
    case_id INTEGER REFERENCES cases(id) ON DELETE CASCADE,
    token_hash TEXT NOT NULL UNIQUE,
    expires_at TEXT NOT NULL,
    created_at TEXT NOT NULL,
    used_at TEXT
);
CREATE TABLE mail_log (
    id INTEGER PRIMARY KEY,
    case_id INTEGER REFERENCES cases(id) ON DELETE SET NULL,
    to_addr TEXT NOT NULL,
    subject TEXT NOT NULL,
    kind TEXT NOT NULL,
    ok INTEGER NOT NULL,
    error TEXT,
    created_at TEXT NOT NULL
);
UPDATE cases SET client_seen_msg_id = COALESCE((SELECT MAX(id) FROM chat_messages m WHERE m.case_id = cases.id), 0);
UPDATE cases SET client_seen_at = updated_at;
SQL;

    // Versão 9 — painel de negociação: guia de preço (interno) e avaliações da IA.
    $migrations[9] = <<<SQL
CREATE TABLE proposal_guides (
    case_id INTEGER PRIMARY KEY REFERENCES cases(id) ON DELETE CASCADE,
    data TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    updated_by INTEGER REFERENCES users(id)
);
CREATE TABLE proposal_evaluations (
    id INTEGER PRIMARY KEY,
    case_id INTEGER NOT NULL REFERENCES cases(id) ON DELETE CASCADE,
    user_id INTEGER REFERENCES users(id),
    input TEXT NOT NULL,
    result TEXT NOT NULL,
    model TEXT,
    created_at TEXT NOT NULL
);
CREATE INDEX idx_prop_eval_case ON proposal_evaluations(case_id, id);
SQL;

    // Versão 10 — análise do cliente no painel de negociação; validade da proposta sai dos modelos.
    $migrations[10] = <<<SQL
ALTER TABLE proposal_evaluations ADD COLUMN kind TEXT NOT NULL DEFAULT 'preco';
SQL;

    // Versão 11 — base zerada de atendimentos (pedido de Sidney): ver migrate_clear_cases().
    $migrations[11] = <<<SQL
CREATE INDEX IF NOT EXISTS idx_cases_created ON cases(created_at);
SQL;

    // Versão 12 — gestor e administrador arquivam atendimentos (somem das listas do escritório).
    $migrations[12] = <<<SQL
CREATE INDEX IF NOT EXISTS idx_cases_account_id ON cases(account_id, id);
SQL;

    // Versão 13 — assistente Sid-LEX mais objetivo: saudação, nome e tom padrão novos.
    $migrations[13] = <<<SQL
CREATE INDEX IF NOT EXISTS idx_chat_case_author ON chat_messages(case_id, author, id);
SQL;

    foreach ($migrations as $v => $sql) {
        if ($v > $version) {
            if ($v === 11) {
                migrate_backup_before_clear($pdo); // VACUUM INTO não roda dentro de transação
            }
            $pdo->beginTransaction();
            $pdo->exec($sql);
            if ($v === 2) {
                migrate_portal_accounts($pdo);
            }
            if ($v === 5) {
                migrate_document_models($pdo);
            }
            if ($v === 6) {
                migrate_document_models_v6($pdo);
            }
            if ($v === 10) {
                migrate_remove_validade($pdo);
            }
            if ($v === 12) {
                $cols = array_column($pdo->query('PRAGMA table_info(cases)')->fetchAll(), 'name');
                if (!in_array('archived_at', $cols, true)) {
                    $pdo->exec('ALTER TABLE cases ADD COLUMN archived_at TEXT');
                    $pdo->exec('ALTER TABLE cases ADD COLUMN archived_by INTEGER REFERENCES users(id)');
                }
            }
            if ($v === 13) {
                migrate_agent_v3($pdo);
                // identidade e comprovante já são pedidos um a um: o pedido conjunto do titular sai
                $pdo->exec("UPDATE documents SET status = 'dispensado' WHERE kind = 'titular' AND status IN ('falta', 'ajuda') AND id NOT IN (SELECT document_id FROM files WHERE document_id IS NOT NULL)");
            }
            if ($v === 11) {
                migrate_clear_cases($pdo);
                migrate_agent_defaults($pdo);
            }
            $pdo->exec('PRAGMA user_version = ' . $v);
            $pdo->commit();
        }
    }
}

/**
 * Migração 2: cada senha de área do cliente que existia por caso vira uma conta,
 * ligada apenas ao próprio caso. Nada é mesclado por telefone, nome ou CPF.
 */
function migrate_portal_accounts(PDO $pdo): void
{
    $rows = $pdo->query('SELECT id, client_name, client_phone, client_email, portal_pass_hash, created_at FROM cases WHERE portal_pass_hash IS NOT NULL')->fetchAll();
    $ins = $pdo->prepare('INSERT INTO client_accounts (name, phone, email, pass_hash, created_at) VALUES (?,?,?,?,?)');
    $link = $pdo->prepare('UPDATE cases SET account_id = ? WHERE id = ?');
    foreach ($rows as $r) {
        $ins->execute([$r['client_name'], $r['client_phone'], $r['client_email'], $r['portal_pass_hash'], $r['created_at']]);
        $link->execute([(int) $pdo->lastInsertId(), $r['id']]);
    }
}

function audit(string $action, ?int $caseId = null, ?string $detail = null): void
{
    insert('audit', [
        'at'      => now_str(),
        'user_id' => current_user()['id'] ?? null,
        'case_id' => $caseId,
        'action'  => $action,
        'detail'  => $detail,
    ]);
}

/**
 * Migração 5: procuração, declaração de hipossuficiência e contrato passam aos
 * modelos do escritório (nova versão, que Sidney revisa e aprova). Proposta e
 * parecer só mudam se ainda estiverem no texto inicial. Dados do escritório que
 * constam dos modelos entram como padrão quando ainda não foram preenchidos.
 */
function migrate_document_models(PDO $pdo): void
{
    $now = (new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d H:i:s');
    $titles = ['proposta' => 'Proposta de atendimento', 'honorarios' => 'Contrato de honorários', 'procuracao' => 'Procuração',
        'hipossuficiencia' => 'Declaração de hipossuficiência', 'parecer' => 'Parecer de cálculos'];
    $oldDefaults = ['proposta' => '8f34469664e63b347cbca418619faad7512ff864', 'parecer' => '1438b7d40d2a59b79dd3713e041e71f2692fa539'];
    foreach ($titles as $key => $title) {
        $body = rtrim((string) file_get_contents(APP_DIR . '/inicial/modelos/' . $key . '.txt')) . "\n";
        $st = $pdo->prepare('SELECT body FROM doc_templates WHERE key = ?');
        $st->execute([$key]);
        $cur = $st->fetchColumn();
        if ($cur === false) {
            continue; // instalação nova: seed_templates() cria com o texto novo
        }
        if (isset($oldDefaults[$key]) && sha1((string) $cur) !== $oldDefaults[$key]) {
            continue; // Sidney já editou: o texto dele fica
        }
        if ($cur !== $body) {
            $pdo->prepare('UPDATE doc_templates SET body = ?, title = ?, version = version + 1, approved_at = NULL, approved_by = NULL, updated_at = ? WHERE key = ?')
                ->execute([$body, $title, $now, $key]);
        }
    }
    $defaults = [
        'office_email' => 'sidney@fatuche.adv.br',
        'office_phone' => '(41) 99906-2936',
        'office_address' => 'Rua Francisco de Paula Lobo Araújo, 372, Cidade Jardim, São José dos Pinhais/PR, CEP 83035-330',
        'office_city' => 'Curitiba',
        'office_pix_type' => 'email',
        'office_pix_key' => 'sidney@fatuche.adv.br',
        'default_procuracao_poderes' => 'Para representar os interesses {{flex:do/da}} outorgante em juízo ou fora dele, em qualquer Repartição Pública, Delegacia de Polícia, Unidade do Ministério Público, Foro, Instância ou Tribunal, com todos os poderes da cláusula “ad judicia et extra”, além dos **PODERES ESPECÍFICOS** do artigo 105 do Código de Processo Civil, para receber citação, confessar, reconhecer a procedência do pedido, transigir, desistir, renunciar ao direito sobre o qual se funda a ação, receber valores, dar quitação, firmar compromisso ou acordo, levantar ou receber RPV e ALVARÁS, pedir a justiça gratuita, assinar declaração de hipossuficiência econômica e ainda substabelecer, no todo ou em parte, em conformidade com a norma do art. 105 da Lei 13.105/2015',
        'default_procuracao_finalidade' => 'tutela de seus interesses relativos ao contrato de financiamento de veículo n.º {{contrato.numero}}, celebrado com {{contrato.banco}}.',
    ];
    $ins = $pdo->prepare("INSERT INTO settings (key, value, updated_at) VALUES (?,?,?) ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at WHERE settings.value IS NULL OR settings.value = ''");
    foreach ($defaults as $k => $v) {
        $ins->execute([$k, $v, $now]);
    }
}

/**
 * Migração 6: contrato, procuração e proposta passam a ter cláusulas
 * condicionais ligadas ao módulo Propostas. Só troca o modelo que ainda está
 * no texto da migração 5 (o que Sidney editou fica como está).
 */
function migrate_document_models_v6(PDO $pdo): void
{
    $now = (new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d H:i:s');
    $previous = ['honorarios' => '5c9d8082b6a048577a069b0399abe9067dedaf69', 'procuracao' => '0fffa2cff89d1f23b887d1662b444fa4b6fec0ff', 'proposta' => 'c36e6d4540b8859473a66b954b6ea19e17bc1e7e'];
    foreach ($previous as $key => $hash) {
        $st = $pdo->prepare('SELECT body FROM doc_templates WHERE key = ?');
        $st->execute([$key]);
        $cur = $st->fetchColumn();
        if ($cur === false || sha1((string) $cur) !== $hash) {
            continue;
        }
        $body = rtrim((string) file_get_contents(APP_DIR . '/inicial/modelos/' . $key . '.txt')) . "\n";
        $pdo->prepare('UPDATE doc_templates SET body = ?, version = version + 1, approved_at = NULL, approved_by = NULL, updated_at = ? WHERE key = ?')->execute([$body, $now, $key]);
    }
}

/**
 * Migração 10: a validade da proposta não aparece mais para o cliente. Tira o
 * parágrafo do contrato e a linha da proposta, mesmo nos modelos já aprovados
 * (a aprovação é mantida: só sai um trecho que não tem mais valor a mostrar).
 */
function migrate_remove_validade(PDO $pdo): void
{
    $now = (new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d H:i:s');
    $cuts = [
        'honorarios' => "/\\*\\*Parágrafo #\\.\\*\\* A validade da proposta comercial[^\\n]*\\n\\n\\{\\{proposta\\.validade[^}]*\\}\\}\\n\\n[^\\n]*\\n\\n/u",
        'proposta' => "/@dados\\nValidade desta proposta:[^\\n]*\\n\\n?/u",
    ];
    foreach ($cuts as $key => $re) {
        $st = $pdo->prepare('SELECT body FROM doc_templates WHERE key = ?');
        $st->execute([$key]);
        $cur = $st->fetchColumn();
        if ($cur === false) {
            continue;
        }
        $body = preg_replace($re, '', str_replace("\r\n", "\n", (string) $cur));
        if ($body !== null && $body !== $cur) {
            $pdo->prepare('UPDATE doc_templates SET body = ?, version = version + 1, updated_at = ? WHERE key = ?')->execute([$body, $now, $key]);
        }
    }
}

/** Pasta da cópia de segurança feita antes de zerar os atendimentos. */
function migrate_backup_dir(): string
{
    return config('storage_dir') . '/backup-antes-de-zerar-atendimentos';
}

/** Cópia íntegra do banco (e, depois, dos arquivos enviados) antes de apagar. */
function migrate_backup_before_clear(PDO $pdo): void
{
    if (!(int) $pdo->query('SELECT COUNT(*) FROM cases')->fetchColumn()) {
        return;
    }
    $dir = migrate_backup_dir();
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }
    $file = $dir . '/fatuche-' . date('Ymd-His') . '.sqlite';
    $pdo->exec('VACUUM INTO ' . $pdo->quote($file));
}

/**
 * Migração 11: apaga todos os atendimentos e o que pertence a eles (conversas,
 * ficha, documentos, propostas, cobranças, contas de clientes, avisos e
 * registros). Usuários do painel, configurações, modelos e variáveis ficam.
 * Os arquivos enviados vão para a pasta da cópia de segurança, não são apagados.
 */
function migrate_clear_cases(PDO $pdo): void
{
    $had = (int) $pdo->query('SELECT COUNT(*) FROM cases')->fetchColumn();
    if (!$had) {
        return;
    }
    $pdo->exec('UPDATE cases SET previous_case_id = NULL, duplicate_of = NULL');
    foreach (['case_artifact_versions', 'asaas_charges', 'proposal_evaluations', 'proposal_guides', 'proposals', 'client_access_links', 'mail_log', 'llm_log',
        'chat_messages', 'case_meetings', 'case_pending_items', 'case_fields', 'files', 'documents', 'tasks', 'events', 'messages', 'decisions', 'offers',
        'schedule', 'deadlines', 'exceptions', 'feedback', 'case_checks'] as $t) {
        if ($pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = " . $pdo->quote($t))->fetchColumn()) {
            $pdo->exec('DELETE FROM ' . $t);
        }
    }
    $pdo->exec('DELETE FROM cases');
    $pdo->exec('DELETE FROM client_accounts');
    $pdo->exec('DELETE FROM audit WHERE case_id IS NOT NULL');
    $pdo->exec("DELETE FROM login_attempts");
    $pdo->prepare('INSERT INTO audit (at, user_id, case_id, action, detail) VALUES (?, NULL, NULL, ?, ?)')
        ->execute([date('Y-m-d H:i:s'), 'atendimentos_zerados', $had . ' atendimento(s) apagado(s); cópia em ' . basename(migrate_backup_dir())]);
    $up = config('storage_dir') . '/uploads';
    if (is_dir($up)) {
        $dest = migrate_backup_dir() . '/uploads-' . date('Ymd-His');
        if (!is_dir(dirname($dest))) {
            mkdir(dirname($dest), 0700, true);
        }
        @rename($up, $dest);
    }
}

/** Quem não tinha mudado o tom do assistente passa a usar o novo padrão (ativo e resolutivo). */
function migrate_agent_defaults(PDO $pdo): void
{
    $st = $pdo->prepare("DELETE FROM settings WHERE key = 'agent_tone' AND replace(value, char(13), '') = ?");
    $st->execute([AGENT_TONE_PREVIOUS]);
}

/** Quem não tinha mudado o tom, a saudação ou o nome do assistente passa a usar os novos padrões (Sid-LEX objetivo). */
function migrate_agent_v3(PDO $pdo): void
{
    $del = $pdo->prepare("DELETE FROM settings WHERE key = ? AND replace(value, char(13), '') = ?");
    foreach ([AGENT_TONE_PREVIOUS, AGENT_TONE_V2] as $old) {
        $del->execute(['agent_tone', $old]);
    }
    foreach (AGENT_GREETINGS_PREVIOUS as $old) {
        $del->execute(['agent_greeting', $old]);
    }
    $del->execute(['agent_name', AGENT_NAME_PREVIOUS]);
}
