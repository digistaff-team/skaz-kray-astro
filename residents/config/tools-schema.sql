-- Схема сервиса шеринга инструментов (раздел жителей).
-- Накатывается ОДИН раз в ту же БД skazkray_residents:
--   mysql skazkray_residents < config/tools-schema.sql
-- Модель P2P: у каждого инструмента есть владелец-семья (tools.family_id);
-- сосед оставляет заявку (tool_loans), владелец одобряет/выдаёт и принимает
-- возврат с проверкой состояния. Модерации нет — инструмент сразу в каталоге.
-- Фото инструментов — в существующей таблице images с owner_type='tool'.

CREATE TABLE tools (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    family_id      INT UNSIGNED NOT NULL,                      -- владелец
    name           VARCHAR(200) NOT NULL,
    category       VARCHAR(80)  NOT NULL DEFAULT '',           -- свободный текст (напр. «Электроинструмент»)
    description    MEDIUMTEXT   NULL,
    condition_note VARCHAR(200) NULL,                          -- состояние («рабочее, есть люфт»)
    terms          VARCHAR(200) NULL,                          -- условия/залог (свободный текст, опц.)
    status         VARCHAR(16)  NOT NULL DEFAULT 'available',  -- available|on_loan|maintenance|hidden
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_tool_family FOREIGN KEY (family_id) REFERENCES families(id) ON DELETE CASCADE,
    INDEX idx_tools_status (status),
    INDEX idx_tools_family (family_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE tool_loans (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tool_id          INT UNSIGNED NOT NULL,
    borrower_id      INT UNSIGNED NOT NULL,                    -- семья-заёмщик
    status           VARCHAR(16)  NOT NULL DEFAULT 'requested',-- requested|on_loan|returned|declined|cancelled
    message          VARCHAR(500) NULL,                        -- сообщение при запросе
    due_date         DATE         NULL,                        -- до какого числа нужен
    requested_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    handed_out_at    DATETIME     NULL,                        -- когда фактически выдан
    returned_at      DATETIME     NULL,                        -- когда вернули
    decided_at       DATETIME     NULL,                        -- когда владелец одобрил/отклонил
    return_condition VARCHAR(16)  NULL,                        -- ok|broken (проверка при возврате)
    return_note      VARCHAR(500) NULL,
    CONSTRAINT fk_loan_tool     FOREIGN KEY (tool_id)     REFERENCES tools(id)    ON DELETE CASCADE,
    CONSTRAINT fk_loan_borrower FOREIGN KEY (borrower_id) REFERENCES families(id) ON DELETE CASCADE,
    INDEX idx_loans_tool (tool_id, status),
    INDEX idx_loans_borrower (borrower_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
