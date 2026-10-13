-- Run this once on the live MySQL DB (e.g. via GreenGeeks phpMyAdmin).
-- Adds API token support to the users table. The raw token is NEVER stored;
-- only its sha256 hash is, so a DB leak does not expose valid tokens.

ALTER TABLE users
  ADD COLUMN api_token_hash VARCHAR(64) NULL,
  ADD COLUMN api_token_set_at TIMESTAMP NULL;

-- Optional: a small admin helper to look up whether a user has a token.
-- Not required for operation.
-- SELECT id, username, api_token_set_at FROM users;