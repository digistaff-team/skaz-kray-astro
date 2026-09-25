CREATE TABLE families (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT NOT NULL UNIQUE,
    telegram_id INTEGER UNIQUE,
    telegram_username TEXT,
    max_user_id INTEGER UNIQUE,
    password_hash TEXT NOT NULL,
    name TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending',
    role TEXT NOT NULL DEFAULT 'resident',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    approved_at TEXT
);
CREATE TABLE diary_entries (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    family_id INTEGER NOT NULL,
    title TEXT NOT NULL,
    body TEXT NOT NULL,
    is_public INTEGER NOT NULL DEFAULT 0,
    visibility TEXT NOT NULL DEFAULT 'residents',
    status TEXT NOT NULL DEFAULT 'pending',
    reject_reason TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    published_at TEXT
);
CREATE TABLE products (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    family_id INTEGER NOT NULL,
    title TEXT NOT NULL,
    description TEXT NOT NULL,
    price TEXT,
    unit TEXT,
    contact TEXT NOT NULL,
    visibility TEXT NOT NULL DEFAULT 'public',
    status TEXT NOT NULL DEFAULT 'pending',
    reject_reason TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    published_at TEXT
);
CREATE TABLE images (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    owner_type TEXT NOT NULL,
    owner_id INTEGER NOT NULL,
    path TEXT NOT NULL,
    sort INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE password_resets (
    token TEXT PRIMARY KEY,
    family_id INTEGER NOT NULL,
    expires_at TEXT NOT NULL
);
CREATE TABLE login_attempts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT NOT NULL,
    ip TEXT NOT NULL,
    attempted_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE council_members (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT NOT NULL UNIQUE,
    telegram_id INTEGER UNIQUE,
    max_user_id INTEGER UNIQUE,
    password_hash TEXT NOT NULL,
    name TEXT NOT NULL,
    surname TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT 'active',
    role TEXT NOT NULL DEFAULT 'member',
    is_duty_chair INTEGER NOT NULL DEFAULT 0,
    claim_code_hash TEXT,
    claim_code_expires TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE council_tasks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    description TEXT,
    author TEXT NOT NULL DEFAULT '',
    assignee TEXT NOT NULL DEFAULT '',
    priority TEXT NOT NULL DEFAULT 'средняя',
    status TEXT NOT NULL DEFAULT 'новая',
    progress INTEGER NOT NULL DEFAULT 0,
    spent REAL NOT NULL DEFAULT 0,
    expense_category_id INTEGER,
    expense_timing TEXT NOT NULL DEFAULT 'post',
    expense_status TEXT NOT NULL DEFAULT 'none',
    expense_msg_chat_id TEXT,
    expense_msg_id INTEGER,
    contacts TEXT,
    links TEXT,
    due_date TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at TEXT
);
CREATE TABLE council_subtasks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    task_id INTEGER NOT NULL,
    title TEXT NOT NULL,
    done INTEGER NOT NULL DEFAULT 0,
    position INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE council_password_resets (
    token TEXT PRIMARY KEY,
    member_id INTEGER NOT NULL,
    expires_at TEXT NOT NULL
);
CREATE TABLE tools (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    family_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    category TEXT NOT NULL DEFAULT '',
    description TEXT,
    condition_note TEXT,
    terms TEXT,
    status TEXT NOT NULL DEFAULT 'available',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE tool_loans (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tool_id INTEGER NOT NULL,
    borrower_id INTEGER NOT NULL,
    status TEXT NOT NULL DEFAULT 'requested',
    message TEXT,
    due_date TEXT,
    requested_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    handed_out_at TEXT,
    returned_at TEXT,
    decided_at TEXT,
    return_condition TEXT,
    return_note TEXT
);
CREATE TABLE books (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    family_id INTEGER NOT NULL,
    title TEXT NOT NULL,
    author TEXT NOT NULL DEFAULT '',
    genre TEXT NOT NULL DEFAULT '',
    description TEXT,
    condition_note TEXT,
    status TEXT NOT NULL DEFAULT 'available',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE book_loans (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    book_id INTEGER NOT NULL,
    borrower_id INTEGER NOT NULL,
    status TEXT NOT NULL DEFAULT 'requested',
    message TEXT,
    due_date TEXT,
    requested_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    handed_out_at TEXT,
    returned_at TEXT,
    decided_at TEXT,
    return_condition TEXT,
    return_note TEXT
);
CREATE TABLE trips (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    driver_id INTEGER NOT NULL,
    origin TEXT NOT NULL,
    destination TEXT NOT NULL,
    trip_date TEXT NOT NULL,
    trip_time TEXT,
    seats_total INTEGER NOT NULL DEFAULT 1,
    seats_free INTEGER NOT NULL DEFAULT 1,
    note TEXT,
    status TEXT NOT NULL DEFAULT 'active',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE trip_bookings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    trip_id INTEGER NOT NULL,
    passenger_id INTEGER NOT NULL,
    seats INTEGER NOT NULL DEFAULT 1,
    status TEXT NOT NULL DEFAULT 'requested',
    message TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    decided_at TEXT
);
CREATE TABLE council_ledger_categories (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    kind TEXT NOT NULL,                 -- income | expense
    name TEXT NOT NULL,
    position INTEGER NOT NULL DEFAULT 0,
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE council_ledger_entries (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    kind TEXT NOT NULL,                 -- income | expense (дублирует kind статьи)
    category_id INTEGER NOT NULL,
    amount REAL NOT NULL DEFAULT 0,
    entry_date TEXT NOT NULL,           -- YYYY-MM-DD
    note TEXT NOT NULL DEFAULT '',
    author TEXT NOT NULL DEFAULT '',
    source_task_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE app_sections (
    section_key TEXT PRIMARY KEY,
    enabled INTEGER NOT NULL DEFAULT 1,
    updated_at TEXT
);
CREATE TABLE households (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    glade TEXT NOT NULL DEFAULT '',
    plot TEXT NOT NULL DEFAULT '',
    estate_name TEXT NOT NULL DEFAULT '',
    status_raw TEXT NOT NULL DEFAULT '',
    joined_text TEXT NOT NULL DEFAULT '',
    family_id INTEGER,
    sort INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE residents (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    household_id INTEGER NOT NULL,
    full_name TEXT NOT NULL,
    birth_raw TEXT NOT NULL DEFAULT '',
    birth_date TEXT,
    phone TEXT NOT NULL DEFAULT '',
    vk TEXT NOT NULL DEFAULT '',
    skills TEXT,
    community_role TEXT,
    moved_text TEXT NOT NULL DEFAULT '',
    residence TEXT NOT NULL DEFAULT '',
    car TEXT NOT NULL DEFAULT '',
    hometown TEXT NOT NULL DEFAULT '',
    email TEXT NOT NULL DEFAULT '',
    questionnaire TEXT NOT NULL DEFAULT '',
    comment TEXT,
    updated_text TEXT NOT NULL DEFAULT '',
    sort INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE household_cars (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    household_id INTEGER NOT NULL,
    title TEXT NOT NULL,
    plate TEXT NOT NULL DEFAULT '',
    note TEXT NOT NULL DEFAULT '',
    sort INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE household_pets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    household_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    kind TEXT NOT NULL DEFAULT '',
    note TEXT NOT NULL DEFAULT '',
    sort INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE household_owners (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    household_id INTEGER NOT NULL,
    family_id INTEGER NOT NULL UNIQUE,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE household_join_requests (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    household_id INTEGER NOT NULL,
    family_id INTEGER NOT NULL UNIQUE,
    surname TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Закупки (совместные оптовые) — зеркало config/purchases-schema.sql
CREATE TABLE purchases (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organizer_id INTEGER NOT NULL,
    title TEXT NOT NULL,
    category TEXT,
    unit TEXT NOT NULL DEFAULT 'шт',
    price_per_unit REAL,
    target_qty REAL,
    deadline TEXT,
    supplier TEXT,
    pickup TEXT,
    note TEXT,
    status TEXT NOT NULL DEFAULT 'collecting',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE purchase_orders (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    purchase_id INTEGER NOT NULL,
    family_id INTEGER NOT NULL,
    qty REAL NOT NULL,
    note TEXT,
    paid_at TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (purchase_id, family_id)
);

CREATE TABLE water_level_history (
    measured_at TEXT NOT NULL PRIMARY KEY,
    level_cm REAL NOT NULL,
    change_24h REAL NOT NULL DEFAULT 0
);

CREATE TABLE water_alert_state (
    id INTEGER NOT NULL PRIMARY KEY,
    status TEXT NOT NULL,
    level_cm REAL NOT NULL,
    notified_at TEXT
);

-- Собрание и повестка совета — зеркало config/council-meeting-*.sql, council-duty-ack.sql,
-- council-agenda-schema.sql, council-agenda-carried.sql
CREATE TABLE council_meeting (
    id INTEGER PRIMARY KEY,
    meeting_date TEXT NOT NULL DEFAULT '',
    starts_at TEXT,
    ends_at TEXT,
    notified_for TEXT,
    rotation_index INTEGER NOT NULL DEFAULT 0,
    duty_ack_at TEXT,
    place TEXT NOT NULL DEFAULT '',
    duty_chair TEXT NOT NULL DEFAULT '',
    duty_secretary TEXT NOT NULL DEFAULT '',
    agenda TEXT,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE council_agenda_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    author TEXT NOT NULL DEFAULT '',
    discussed INTEGER NOT NULL DEFAULT 0,
    carried_over INTEGER NOT NULL DEFAULT 0,
    sort INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
