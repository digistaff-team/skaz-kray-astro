-- Журнал telegram_messages: расширяем context и text под анонсы статей.
-- scripts/announce-post.mjs пишет context = 'post-announce:<slug>' (до ~80 симв.)
-- и полный текст анонса, а исходные VARCHAR(16)/VARCHAR(500) их не вмещали:
-- запись падала с "Data too long for column 'context'", и дедуп не работал.
-- Идемпотентно: повторный MODIFY безопасен.

ALTER TABLE telegram_messages
    MODIFY context VARCHAR(128) NOT NULL,
    MODIFY text    VARCHAR(4096) NULL;
