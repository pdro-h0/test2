-- The cascading delete from batches must find outbox rows by message; the FK alone has no index.
CREATE INDEX outbox_message_id_idx ON outbox (message_id);
