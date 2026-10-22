-- One row per client. The fixed window lives in window_start; hits resets on a new second.
CREATE TABLE rate_limits (
    client_id bigint PRIMARY KEY REFERENCES clients (id) ON DELETE CASCADE,
    window_start timestamptz NOT NULL,
    hits integer NOT NULL CHECK (hits > 0)
);
