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

    foreach ($migrations as $v => $sql) {
        if ($v > $version) {
            $pdo->beginTransaction();
            $pdo->exec($sql);
            if ($v === 2) {
                migrate_portal_accounts($pdo);
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
