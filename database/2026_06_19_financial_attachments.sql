CREATE TABLE IF NOT EXISTS financial_attachments (
    id BIGSERIAL PRIMARY KEY,
    user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    movement_id BIGINT NULL REFERENCES transactions(id) ON DELETE SET NULL,
    original_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    mime_type VARCHAR(120) NOT NULL,
    file_size BIGINT NOT NULL,
    extracted_text TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT financial_attachments_file_size_check CHECK (file_size > 0)
);

CREATE INDEX IF NOT EXISTS idx_financial_attachments_user_id
    ON financial_attachments (user_id);

CREATE INDEX IF NOT EXISTS idx_financial_attachments_movement_id
    ON financial_attachments (movement_id);

CREATE INDEX IF NOT EXISTS idx_financial_attachments_user_movement
    ON financial_attachments (user_id, movement_id);
