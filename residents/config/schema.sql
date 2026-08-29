-- База создаётся отдельно: CREATE DATABASE skazkray_residents CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE families (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email         VARCHAR(255) NOT NULL UNIQUE,
    telegram_id   BIGINT       NULL UNIQUE,               -- привязка к Telegram (авто-логин через Mini App)
    password_hash VARCHAR(255) NOT NULL,
    name          VARCHAR(160) NOT NULL,
    status        VARCHAR(16)  NOT NULL DEFAULT 'pending',   -- pending|active|blocked
    role          VARCHAR(16)  NOT NULL DEFAULT 'resident',  -- resident|editor
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    approved_at   DATETIME     NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE diary_entries (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    family_id     INT UNSIGNED NOT NULL,
    title         VARCHAR(200) NOT NULL,
    body          MEDIUMTEXT   NOT NULL,
    is_public     TINYINT(1)   NOT NULL DEFAULT 0,           -- показывать на внешнем сайте (/dnevniki-pomestiy/)
    status        VARCHAR(16)  NOT NULL DEFAULT 'pending',   -- pending|published|rejected
    reject_reason VARCHAR(500) NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    published_at  DATETIME     NULL,
    CONSTRAINT fk_diary_family FOREIGN KEY (family_id) REFERENCES families(id) ON DELETE CASCADE,
    INDEX idx_diary_status_pub (status, published_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE products (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    family_id     INT UNSIGNED NOT NULL,
    title         VARCHAR(200) NOT NULL,
    description   MEDIUMTEXT   NOT NULL,
    price         VARCHAR(80)  NULL,                          -- свободный текст; NULL = по договорённости
    contact       VARCHAR(200) NOT NULL,
    status        VARCHAR(16)  NOT NULL DEFAULT 'pending',
    reject_reason VARCHAR(500) NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    published_at  DATETIME     NULL,
    CONSTRAINT fk_product_family FOREIGN KEY (family_id) REFERENCES families(id) ON DELETE CASCADE,
    INDEX idx_product_status_pub (status, published_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE images (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    owner_type VARCHAR(16)  NOT NULL,                          -- entry|product
    owner_id   INT UNSIGNED NOT NULL,
    path       VARCHAR(255) NOT NULL,
    sort       INT          NOT NULL DEFAULT 0,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_images_owner (owner_type, owner_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE password_resets (
    token      CHAR(64)     PRIMARY KEY,
    family_id  INT UNSIGNED NOT NULL,
    expires_at DATETIME     NOT NULL,
    CONSTRAINT fk_reset_family FOREIGN KEY (family_id) REFERENCES families(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE login_attempts (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email        VARCHAR(255) NOT NULL,
    ip           VARCHAR(45)  NOT NULL,
    attempted_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_attempts_email (email, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
