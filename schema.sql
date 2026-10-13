DROP TABLE IF EXISTS daily_transfers;
DROP TABLE IF EXISTS active_timers;
DROP TABLE IF EXISTS budgets;
DROP TABLE IF EXISTS completed_task_skills;
DROP TABLE IF EXISTS task_skills;
DROP TABLE IF EXISTS skill_parents;
DROP TABLE IF EXISTS skills;
DROP TABLE IF EXISTS completed_tasks;
DROP TABLE IF EXISTS tasks;
DROP TABLE IF EXISTS collections;
DROP TABLE IF EXISTS users;

CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(100) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE collections (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  type VARCHAR(20) NOT NULL,
  name VARCHAR(255) NOT NULL,
  description TEXT,
  parent_id INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (parent_id) REFERENCES collections(id) ON DELETE CASCADE
);

CREATE TABLE tasks (
  id VARCHAR(64) PRIMARY KEY,
  user_id INT NOT NULL,
  name VARCHAR(255) NOT NULL,
  category VARCHAR(50) NOT NULL,
  duration INT NOT NULL,
  type VARCHAR(20) NOT NULL,
  recurrence_frequency VARCHAR(20),
  recurrence_interval INT,
  recurrence_days_of_week VARCHAR(50),
  deadline VARCHAR(50),
  last_completed VARCHAR(50),
  last_skipped VARCHAR(50),
  pinned TINYINT NOT NULL DEFAULT 0,
  collection_id INT NULL,
  note TEXT,
  created_at VARCHAR(50) NOT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (collection_id) REFERENCES collections(id) ON DELETE SET NULL
);

CREATE TABLE completed_tasks (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  task_id VARCHAR(64) NOT NULL,
  task_name VARCHAR(255) NOT NULL,
  category VARCHAR(50) NOT NULL,
  duration INT NOT NULL,
  completed_at VARCHAR(50) NOT NULL,
  start_at VARCHAR(50) NULL,
  end_at VARCHAR(50) NULL,
  note TEXT,
  collection_id INT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (collection_id) REFERENCES collections(id) ON DELETE SET NULL
);

CREATE TABLE active_timers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL UNIQUE,
  task_id VARCHAR(64) NOT NULL,
  start_at VARCHAR(50) NOT NULL,
  end_at VARCHAR(50) NOT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE budgets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  category VARCHAR(50) NOT NULL,
  weekday_budget INT NOT NULL DEFAULT 0,
  weekend_budget INT NOT NULL DEFAULT 0,
  UNIQUE KEY (user_id, category),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE daily_transfers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  date DATE NOT NULL,
  from_category VARCHAR(50) NOT NULL,
  to_category VARCHAR(50) NOT NULL,
  minutes INT NOT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

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

CREATE TABLE skill_parents (
  skill_id INT NOT NULL,
  parent_id INT NOT NULL,
  PRIMARY KEY (skill_id, parent_id),
  FOREIGN KEY (skill_id) REFERENCES skills(id) ON DELETE CASCADE,
  FOREIGN KEY (parent_id) REFERENCES skills(id) ON DELETE CASCADE
);

CREATE TABLE task_skills (
  task_id VARCHAR(64) NOT NULL,
  user_id INT NOT NULL,
  skill_id INT NOT NULL,
  PRIMARY KEY (task_id, skill_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
  FOREIGN KEY (skill_id) REFERENCES skills(id) ON DELETE CASCADE
);

CREATE TABLE completed_task_skills (
  completed_id INT NOT NULL,
  user_id INT NOT NULL,
  skill_id INT NOT NULL,
  PRIMARY KEY (completed_id, skill_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (completed_id) REFERENCES completed_tasks(id) ON DELETE CASCADE,
  FOREIGN KEY (skill_id) REFERENCES skills(id) ON DELETE CASCADE
);