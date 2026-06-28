ALTER TABLE financial_attachments
    ADD COLUMN IF NOT EXISTS extracted_text TEXT;
