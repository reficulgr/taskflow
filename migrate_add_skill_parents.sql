-- Migration: multi-parent skills (skill tree becomes a DAG).
-- Run once on the live DB via phpMyAdmin.
-- A skill can now have multiple parents; roll-up dedupes ancestors per stamp.

CREATE TABLE skill_parents (
  skill_id INT NOT NULL,
  parent_id INT NOT NULL,
  PRIMARY KEY (skill_id, parent_id),
  FOREIGN KEY (skill_id) REFERENCES skills(id) ON DELETE CASCADE,
  FOREIGN KEY (parent_id) REFERENCES skills(id) ON DELETE CASCADE
);

-- Backfill: existing single parents become the first row in the junction table.
INSERT IGNORE INTO skill_parents (skill_id, parent_id)
SELECT id, parent_id FROM skills WHERE parent_id IS NOT NULL;

-- The parent_id column stays (read-only fallback) but is no longer authoritative.
-- NULL it so nothing accidentally re-uses it:
UPDATE skills SET parent_id = NULL WHERE parent_id IS NOT NULL;