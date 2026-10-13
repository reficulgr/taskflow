-- Migration: add pebble_increment to users
-- Run once on the live DB to support the report pebble time increment setting.

ALTER TABLE users
  ADD COLUMN pebble_increment INT NOT NULL DEFAULT 30;