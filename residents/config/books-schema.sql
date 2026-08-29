-- Схема сервиса обмена книгами (раздел жителей). Зеркало сервиса инструментов.
-- Накатывается ОДИН раз в ту же БД skazkray_residents:
--   mysql skazkray_residents < config/books-schema.sql
-- Модель P2P: у каждой книги есть владелец-семья (books.family_id); сосед
-- бронирует (book_loans), владелец одобряет/выдаёт и принимает возврат с
-- проверкой состояния. Модерации нет. Обложки — в images с owner_type='book'.

CREATE TABLE books (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    family_id      INT UNSIGNED NOT NULL,                      -- владелец
    title          VARCHAR(250) NOT NULL,
    author         VARCHAR(200) NOT NULL DEFAULT '',
    genre          VARCHAR(80)  NOT NULL DEFAULT '',           -- свободный текст (напр. «Фантастика»)
    description    MEDIUMTEXT   NULL,                          -- аннотация / о чём книга
    condition_note VARCHAR(200) NULL,                          -- состояние («потрёпанная обложка»)
    status         VARCHAR(16)  NOT NULL DEFAULT 'available',  -- available|on_loan|maintenance|hidden
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_book_family FOREIGN KEY (family_id) REFERENCES families(id) ON DELETE CASCADE,
    INDEX idx_books_status (status),
    INDEX idx_books_family (family_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE book_loans (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    book_id          INT UNSIGNED NOT NULL,
    borrower_id      INT UNSIGNED NOT NULL,                    -- семья-читатель
    status           VARCHAR(16)  NOT NULL DEFAULT 'requested',-- requested|on_loan|returned|declined|cancelled
    message          VARCHAR(500) NULL,                        -- сообщение при брони
    due_date         DATE         NULL,                        -- до какого числа нужна
    requested_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    handed_out_at    DATETIME     NULL,
    returned_at      DATETIME     NULL,
    decided_at       DATETIME     NULL,
    return_condition VARCHAR(16)  NULL,                        -- ok|broken (проверка при возврате)
    return_note      VARCHAR(500) NULL,
    CONSTRAINT fk_bloan_book     FOREIGN KEY (book_id)     REFERENCES books(id)    ON DELETE CASCADE,
    CONSTRAINT fk_bloan_borrower FOREIGN KEY (borrower_id) REFERENCES families(id) ON DELETE CASCADE,
    INDEX idx_bloans_book (book_id, status),
    INDEX idx_bloans_borrower (borrower_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
