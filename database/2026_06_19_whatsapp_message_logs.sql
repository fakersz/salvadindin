CREATE TABLE IF NOT EXISTS whatsapp_message_logs (
    message_id VARCHAR(255) PRIMARY KEY,
    wa_id VARCHAR(30) NOT NULL,
    user_id BIGINT NULL REFERENCES users(id) ON DELETE SET NULL,
    transaction_id BIGINT NULL REFERENCES transactions(id) ON DELETE SET NULL,
    status VARCHAR(40) NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_whatsapp_message_logs_wa_id
    ON whatsapp_message_logs (wa_id);
