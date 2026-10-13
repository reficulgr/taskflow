-- Migration: add the skill tree (for the Skills / archetype feature).
-- Run once on the live DB via phpMyAdmin.

CREATE TABLE skills (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  name VARCHAR(255) NOT NULL,
  parent_id INT NULL,
  archived TINYINT NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (parent_id) REFERENCES skills(id) ON DELETE CASCADE
);

-- A task's default skill tags (copied to completions when the task is completed).
CREATE TABLE task_skills (
  task_id VARCHAR(64) NOT NULL,
  user_id INT NOT NULL,
  skill_id INT NOT NULL,
  PRIMARY KEY (task_id, skill_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
  FOREIGN KEY (skill_id) REFERENCES skills(id) ON DELETE CASCADE
);

-- The frozen per-completion skill stamps. These are the source of truth for
-- the skill reports; tree re-orgs re-interpret them at read time.
CREATE TABLE completed_task_skills (
  completed_id INT NOT NULL,
  user_id INT NOT NULL,
  skill_id INT NOT NULL,
  PRIMARY KEY (completed_id, skill_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (completed_id) REFERENCES completed_tasks(id) ON DELETE CASCADE,
  FOREIGN KEY (skill_id) REFERENCES skills(id) ON DELETE CASCADE
);

-- Seed the four archetype roots for every existing user (editable later).
-- Archetype colors are handled in PHP; only nodes live here.
INSERT INTO skills (user_id, name, parent_id)
SELECT id, 'Magician', NULL FROM users
UNION ALL SELECT id, 'Lover', NULL FROM users
UNION ALL SELECT id, 'Warrior', NULL FROM users
UNION ALL SELECT id, 'King', NULL FROM users;