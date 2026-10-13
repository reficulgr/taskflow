-- Migration: add collection_id to completed_tasks
-- Run once on the live DB to support retroactive collection assignment.

ALTER TABLE completed_tasks ADD COLUMN collection_id INT NULL AFTER note;

ALTER TABLE completed_tasks
  ADD CONSTRAINT fk_completed_collection
  FOREIGN KEY (collection_id) REFERENCES collections(id) ON DELETE SET NULL;

-- Backfill: copy collection_id from still-existing tasks (Recurring/Flexible).
-- OneOff tasks are deleted on completion, so their completions remain NULL
-- and can be manually assigned later via the edit UI or API.
UPDATE completed_tasks c
JOIN tasks t ON c.task_id = t.id AND c.user_id = t.user_id
SET c.collection_id = t.collection_id
WHERE c.collection_id IS NULL AND t.collection_id IS NOT NULL;