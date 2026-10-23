-- Fingerprint of the normalized batch payload, kept after html/text are purged.
ALTER TABLE batches ADD COLUMN payload_hash text
    CHECK (payload_hash IS NULL OR payload_hash ~ '^[0-9a-f]{64}$');
