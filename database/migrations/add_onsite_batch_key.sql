ALTER TABLE requests
    ADD COLUMN IF NOT EXISTS onsite_batch_key VARCHAR(32) NULL AFTER created_by;

CREATE INDEX IF NOT EXISTS idx_requests_onsite_batch_key ON requests (onsite_batch_key);
