ALTER TABLE users
    ADD COLUMN IF NOT EXISTS whatsapp_phone VARCHAR(20);

CREATE UNIQUE INDEX IF NOT EXISTS users_whatsapp_phone_unique
    ON users (whatsapp_phone)
    WHERE whatsapp_phone IS NOT NULL AND whatsapp_phone <> '';
