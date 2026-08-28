-- Схема раздела «Попечительский совет».
-- Накатывается ОДИН раз в ту же БД, что и раздел жителей:
--   mysql skazkray_residents < config/council-schema.sql
-- Аккаунты совета живут в отдельной таблице council_members — её UNIQUE email
-- независим от families.email, поэтому один и тот же email может быть и семьёй,
-- и членом совета одновременно (разные таблицы, разные ключи сессии).
-- Троттлинг входа переиспользует существующую таблицу login_attempts (ключи council:*).

CREATE TABLE council_members (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email         VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    name          VARCHAR(160) NOT NULL,
    status        VARCHAR(16)  NOT NULL DEFAULT 'active',   -- active|blocked
    role          VARCHAR(16)  NOT NULL DEFAULT 'member',   -- member|admin
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE council_tasks (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title        VARCHAR(300) NOT NULL,
    description  MEDIUMTEXT   NULL,
    author       VARCHAR(160) NOT NULL DEFAULT '',          -- кто сформулировал
    assignee     VARCHAR(160) NOT NULL DEFAULT '',          -- кто взялся
    priority     VARCHAR(16)  NOT NULL DEFAULT 'средняя',   -- низкая|средняя|высокая
    status       VARCHAR(16)  NOT NULL DEFAULT 'новая',     -- новая|в работе|выполнена
    progress     INT          NOT NULL DEFAULT 0,           -- 0..100
    spent        DECIMAL(12,2) NOT NULL DEFAULT 0,          -- затрачено, руб
    contacts     MEDIUMTEXT   NULL,                         -- контакты специалистов
    links        MEDIUMTEXT   NULL,                         -- ссылки на товары
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME     NULL,
    INDEX idx_council_tasks_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE council_subtasks (
    id       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    task_id  INT UNSIGNED NOT NULL,
    title    VARCHAR(300) NOT NULL,
    done     TINYINT(1)   NOT NULL DEFAULT 0,
    position INT          NOT NULL DEFAULT 0,
    CONSTRAINT fk_council_subtask_task FOREIGN KEY (task_id) REFERENCES council_tasks(id) ON DELETE CASCADE,
    INDEX idx_council_subtask_task (task_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE council_password_resets (
    token      CHAR(64)     PRIMARY KEY,
    member_id  INT UNSIGNED NOT NULL,
    expires_at DATETIME     NOT NULL,
    CONSTRAINT fk_council_reset_member FOREIGN KEY (member_id) REFERENCES council_members(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
