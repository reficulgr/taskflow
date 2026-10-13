<?php
require_once __DIR__ . '/db.php';

const CATEGORIES = ['Revenue Today', 'Revenue Tomorrow', 'Exploration', 'Entertainment', 'Social-Household'];
const CAT_COLORS = [
  'Revenue Today' => 'bg-red-500', 'Revenue Tomorrow' => 'bg-sky-500',
  'Exploration' => 'bg-emerald-500', 'Entertainment' => 'bg-violet-500', 'Social-Household' => 'bg-amber-500',
];
const CAT_TEXT = [
  'Revenue Today' => 'text-red-400', 'Revenue Tomorrow' => 'text-sky-400',
  'Exploration' => 'text-emerald-400', 'Entertainment' => 'text-violet-400', 'Social-Household' => 'text-amber-400',
];
const DEFAULT_BUDGETS = [
  ['Revenue Today', 240, 0], ['Revenue Tomorrow', 120, 0],
  ['Exploration', 240, 360], ['Entertainment', 240, 360], ['Social-Household', 120, 240],
];
const DURATIONS = [15, 30, 45, 60, 75, 90, 120, 180];

function is_weekday(): bool { $d = (int)date('N'); return $d >= 1 && $d <= 5; }

function is_today(string $dateStr): bool {
  return date('Y-m-d', strtotime($dateStr)) === date('Y-m-d');
}

function add_days(string $dateStr, int $n): string {
  return date('Y-m-d', strtotime("$dateStr +$n days"));
}

function format_minutes(int $mins): string {
  $h = intdiv($mins, 60);
  $m = $mins % 60;
  if ($h > 0 && $m > 0) return "{$h}h {$m}m";
  if ($h > 0) return "{$h}h";
  return "{$m}m";
}

// ====== Data loaders ======
function get_tasks(int $userId): array {
  $stmt = db()->prepare('SELECT * FROM tasks WHERE user_id = ? ORDER BY created_at DESC');
  $stmt->execute([$userId]);
  return array_map('normalize_task', $stmt->fetchAll());
}

function get_task(int $userId, string $taskId): ?array {
  $stmt = db()->prepare('SELECT * FROM tasks WHERE id = ? AND user_id = ?');
  $stmt->execute([$taskId, $userId]);
  $row = $stmt->fetch();
  return $row ? normalize_task($row) : null;
}

function normalize_task(array $row): array {
  $recurrence = null;
  if ($row['recurrence_frequency']) {
    $recurrence = ['frequency' => $row['recurrence_frequency'], 'interval' => (int)$row['recurrence_interval']];
    if ($row['recurrence_days_of_week']) {
      $recurrence['daysOfWeek'] = array_map('intval', explode(',', $row['recurrence_days_of_week']));
    }
  }
  return [
    'id' => $row['id'], 'name' => $row['name'], 'category' => $row['category'],
    'duration' => (int)$row['duration'], 'type' => $row['type'],
    'recurrence' => $recurrence,
    'deadline' => $row['deadline'] ?: null,
    'lastCompleted' => $row['last_completed'] ?: null,
    'lastSkipped' => $row['last_skipped'] ?: null,
    'pinned' => (int)$row['pinned'] === 1,
    'collectionId' => $row['collection_id'] !== null ? (int)$row['collection_id'] : null,
    'note' => $row['note'] ?: null,
    'createdAt' => $row['created_at'],
  ];
}

function get_completed(int $userId): array {
  $stmt = db()->prepare('SELECT * FROM completed_tasks WHERE user_id = ? ORDER BY completed_at DESC');
  $stmt->execute([$userId]);
  $rows = $stmt->fetchAll();
  return array_map(function($r) {
    return [
      'id' => (int)$r['id'], 'taskId' => $r['task_id'], 'taskName' => $r['task_name'],
      'category' => $r['category'], 'duration' => (int)$r['duration'],
      'completedAt' => $r['completed_at'], 'startAt' => $r['start_at'] ?: null, 'endAt' => $r['end_at'] ?: null,
      'note' => $r['note'] ?: null,
      'collectionId' => $r['collection_id'] !== null ? (int)$r['collection_id'] : null,
    ];
  }, $rows);
}

function get_budgets(int $userId): array {
  $stmt = db()->prepare('SELECT * FROM budgets WHERE user_id = ?');
  $stmt->execute([$userId]);
  $rows = $stmt->fetchAll();
  if (empty($rows)) {
    $stmt = db()->prepare('INSERT INTO budgets (user_id, category, weekday_budget, weekend_budget) VALUES (?, ?, ?, ?)');
    foreach (DEFAULT_BUDGETS as $b) { $stmt->execute([$userId, $b[0], $b[1], $b[2]]); }
    return get_budgets($userId);
  }
  return array_map(function($r) {
    return ['category' => $r['category'], 'weekdayBudget' => (int)$r['weekday_budget'], 'weekendBudget' => (int)$r['weekend_budget']];
  }, $rows);
}

const PEBBLE_INCREMENTS = [15, 30, 45, 60, 90, 120, 180];

function get_pebble_increment(int $userId): int {
  try {
    $stmt = db()->prepare('SELECT pebble_increment FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $inc = (int)$stmt->fetchColumn();
    return $inc > 0 ? $inc : 30;
  } catch (Throwable $e) {
    return 30;
  }
}

function save_pebble_increment(int $userId, int $increment): void {
  if (!in_array($increment, PEBBLE_INCREMENTS, true)) return;
  try {
    $stmt = db()->prepare('UPDATE users SET pebble_increment = ? WHERE id = ?');
    $stmt->execute([$increment, $userId]);
  } catch (Throwable $e) {
    // Column missing (migration not run yet) — add it and retry once.
    try {
      db()->exec('ALTER TABLE users ADD COLUMN pebble_increment INT NOT NULL DEFAULT 30');
      $stmt = db()->prepare('UPDATE users SET pebble_increment = ? WHERE id = ?');
      $stmt->execute([$increment, $userId]);
    } catch (Throwable $e2) {
      // Cannot alter — keep default 30.
    }
  }
}

// ====== Budget tracking ======
// Actual elapsed minutes for one completion row: endAt - startAt when both
// exist and form a positive span, else the stored (planned) duration.
function completion_actual_minutes(array $row): int {
  if (!empty($row['start_at']) && !empty($row['end_at'])) {
    $diff = (int)round((strtotime($row['end_at']) - strtotime($row['start_at'])) / 60);
    if ($diff > 0) return $diff;
  }
  return (int)$row['duration'];
}

// Budget consumption tracks ACTUAL elapsed time (calendar blocks), not planned
// durations, so overruns are charged to the category where they happened.
function consumed_today(int $userId, string $category): int {
  $stmt = db()->prepare("SELECT duration, start_at, end_at FROM completed_tasks WHERE user_id = ? AND category = ? AND DATE(completed_at) = CURDATE()");
  $stmt->execute([$userId, $category]);
  $total = 0;
  foreach ($stmt->fetchAll() as $row) $total += completion_actual_minutes($row);
  return $total;
}

function get_daily_transfers(int $userId): array {
  $stmt = db()->prepare('SELECT * FROM daily_transfers WHERE user_id = ? AND date = CURDATE() ORDER BY id ASC');
  $stmt->execute([$userId]);
  return array_map(function($r) {
    return [
      'id' => (int)$r['id'],
      'fromCategory' => $r['from_category'],
      'toCategory' => $r['to_category'],
      'minutes' => (int)$r['minutes'],
    ];
  }, $stmt->fetchAll());
}

function add_daily_transfer(int $userId, string $from, string $to, int $minutes): void {
  if ($from === $to || $minutes <= 0) return;
  $budgets = get_budgets($userId);
  $transfers = get_daily_transfers($userId);
  $effectiveFrom = effective_budget($userId, $from, $budgets, $transfers);
  $consumedFrom = consumed_today($userId, $from);
  $availableFrom = max(0, $effectiveFrom - $consumedFrom);
  if ($minutes > $availableFrom) return;
  $stmt = db()->prepare('INSERT INTO daily_transfers (user_id, date, from_category, to_category, minutes) VALUES (?, CURDATE(), ?, ?, ?)');
  $stmt->execute([$userId, $from, $to, $minutes]);
}

function delete_daily_transfer(int $userId, int $id): void {
  $stmt = db()->prepare('DELETE FROM daily_transfers WHERE id = ? AND user_id = ?');
  $stmt->execute([$id, $userId]);
}

function get_completed_by_id(int $userId, int $id): ?array {
  $stmt = db()->prepare('SELECT * FROM completed_tasks WHERE id = ? AND user_id = ?');
  $stmt->execute([$id, $userId]);
  $r = $stmt->fetch();
  if (!$r) return null;
  return [
    'id' => (int)$r['id'], 'taskId' => $r['task_id'], 'taskName' => $r['task_name'],
    'category' => $r['category'], 'duration' => (int)$r['duration'],
    'completedAt' => $r['completed_at'], 'startAt' => $r['start_at'] ?: null, 'endAt' => $r['end_at'] ?: null,
    'note' => $r['note'] ?: null,
    'collectionId' => $r['collection_id'] !== null ? (int)$r['collection_id'] : null,
  ];
}

function update_completed(int $userId, int $id, array $data): void {
  $stmt = db()->prepare('UPDATE completed_tasks SET category=?, duration=?, start_at=?, end_at=?, note=?, collection_id=? WHERE id=? AND user_id=?');
  $stmt->execute([
    $data['category'], (int)$data['duration'],
    $data['startAt'] ?? null, $data['endAt'] ?? null, $data['note'] ?? null,
    $data['collectionId'] ?? null,
    $id, $userId,
  ]);
}

function update_completed_times(int $userId, int $id, ?string $startAt, ?string $endAt): void {
  $completedAt = $endAt ? date('c', strtotime($endAt)) : ($startAt ? date('c', strtotime($startAt)) : date('c'));
  $stmt = db()->prepare('UPDATE completed_tasks SET start_at=?, end_at=?, completed_at=? WHERE id=? AND user_id=?');
  $stmt->execute([$startAt, $endAt, $completedAt, $id, $userId]);
}

function effective_budget(int $userId, string $category, array $budgets, array $transfers): int {
  $weekday = is_weekday();
  $base = 0;
  foreach ($budgets as $b) { if ($b['category'] === $category) { $base = $weekday ? $b['weekdayBudget'] : $b['weekendBudget']; break; } }
  $delta = 0;
  foreach ($transfers as $t) {
    if ($t['toCategory'] === $category) $delta += $t['minutes'];
    if ($t['fromCategory'] === $category) $delta -= $t['minutes'];
  }
  return max(0, $base + $delta);
}

// ====== Recurrence logic ======
function next_availability_date(array $task): ?string {
  if ($task['type'] !== 'Recurring' || !$task['recurrence']) return null;
  $base = $task['lastCompleted'] ? date('Y-m-d', strtotime($task['lastCompleted'])) : date('Y-m-d', strtotime($task['createdAt']));
  $r = $task['recurrence'];
  switch ($r['frequency']) {
    case 'Days': return add_days($base, $r['interval']);
    case 'Months': return date('Y-m-d', strtotime("$base +{$r['interval']} months"));
    case 'SpecificDays':
      if (empty($r['daysOfWeek'])) return null;
      for ($i = 1; $i <= 7; $i++) {
        $d = add_days($base, $i);
        if (in_array((int)date('w', strtotime($d)), $r['daysOfWeek'])) return $d;
      }
      return null;
    case 'Weeks':
      if (empty($r['daysOfWeek'])) return null;
      $start = date('Y-m-d', strtotime("last Sunday", strtotime($base)));
      $nextStart = add_days($start, $r['interval'] * 7);
      for ($i = 0; $i < 7; $i++) {
        $d = add_days($nextStart, $i);
        if (in_array((int)date('w', strtotime($d)), $r['daysOfWeek']) && $d > $base) return $d;
      }
      return null;
    default: return null;
  }
}

function is_recurring_eligible(array $task): bool {
  if ($task['type'] !== 'Recurring' || !$task['recurrence']) return true;
  if ($task['lastCompleted'] && is_today($task['lastCompleted'])) return false;
  $next = next_availability_date($task);
  if (!$next) return false;
  return date('Y-m-d') >= $next;
}

// ====== Priority engine ======
function select_top_three(int $userId): array {
  $tasks = get_tasks($userId);
  $completed = get_completed($userId);
  $budgets = get_budgets($userId);
  $transfers = get_daily_transfers($userId);
  $weekday = is_weekday();

  $eligible = array_filter($tasks, function($task) use ($budgets, $transfers, $weekday, $userId, $completed) {
    if (is_snoozed($task)) return false;
    $b = null;
    foreach ($budgets as $budget) { if ($budget['category'] === $task['category']) { $b = $budget; break; } }
    if (!$b) return false;
    $budget = effective_budget($userId, $task['category'], $budgets, $transfers);
    $consumed = consumed_today($userId, $task['category']);
    if ($consumed + $task['duration'] > $budget) return false;
    if ($task['type'] === 'Recurring') return is_recurring_eligible($task);
    return true;
  });

  $scored = array_map(function($task) use ($completed) {
    $score = 0;
    $now = time();
    if ($task['type'] === 'OneOff' && $task['deadline']) {
      $dl = strtotime($task['deadline']);
      if ($dl > $now) {
        $daysAway = ceil(($dl - $now) / 86400);
        $score += 50 / max(1, $daysAway);
        if ($daysAway <= 1) {
          $hoursAway = ($dl - $now) / 3600;
          $score += max(0, 50 - $hoursAway * 2);
        }
      } else {
        $score += 100;
      }
    }
    if ($task['type'] === 'Recurring' && $task['lastCompleted']) {
      $score += floor(($now - strtotime($task['lastCompleted'])) / 86400) * 5;
    } elseif ($task['type'] === 'Recurring') {
      $score += floor(($now - strtotime($task['createdAt'])) / 86400);
    }
    $pri = ['Revenue Today' => 100, 'Revenue Tomorrow' => 70, 'Exploration' => 40, 'Social-Household' => 20, 'Entertainment' => 10];
    $score += $pri[$task['category']] ?? 0;
    if ($task['type'] === 'Flexible') {
      $score += 30;
      foreach ($completed as $c) { if ($c['taskId'] === $task['id'] && is_today($c['completedAt'])) { $score -= 500; break; } }
    }
    $score += mt_rand(0, 500) / 100;
    return ['task' => $task, 'score' => $score, 'pinned' => $task['pinned']];
  }, $eligible);

  usort($scored, function($a, $b) {
    if ($a['pinned'] !== $b['pinned']) return $b['pinned'] <=> $a['pinned'];
    return $b['score'] <=> $a['score'];
  });
  return array_map(fn($x) => $x['task'], array_slice($scored, 0, 3));
}

// ====== Rendering helpers ======
function theme_config_script(): string {
  return '<script>tailwind.config = { theme: { extend: { fontFamily: { sans: ["\'IBM Plex Sans\'", "system-ui", "sans-serif"], mono: ["\'IBM Plex Mono\'", "ui-monospace", "monospace"], condensed: ["\'IBM Plex Sans Condensed\'", "\'IBM Plex Sans\'", "sans-serif"] }, colors: { accent: { 200: \'#f3e2b7\', 300: \'#e6cc8c\', 400: \'#d9b861\', 500: \'#c9a44a\', 600: \'#a9883c\', 700: \'#84682f\', 800: \'#5c4922\' }, slate: { 50: \'#f8f8f7\', 100: \'#f1f1f0\', 200: \'#e2e2e0\', 300: \'#c6c6c2\', 400: \'#9a9a96\', 500: \'#7a7a76\', 600: \'#575753\', 700: \'#282828\', 800: \'#191918\', 900: \'#121211\', 950: \'#0e0e0d\' } } } } }</script>';
}

function render_fonts(): void {
  echo '<link rel="preconnect" href="https://fonts.googleapis.com">' . "\n"
    . '  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n"
    . '  <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500&family=IBM+Plex+Sans:ital,wght@0,400;0,500;0,600;0,700;1,400&family=IBM+Plex+Sans+Condensed:wght@600;700&display=swap" rel="stylesheet">' . "\n"
    . '  ' . theme_config_script() . "\n"
    . '  <style>h1,h2,h3,h4,h5,h6{font-family:\'IBM Plex Sans Condensed\',\'IBM Plex Sans\',sans-serif}</style>';
}

function task_detail(array $task): string {
  switch ($task['type']) {
    case 'Flexible': return 'Flexible Task';
    case 'Recurring':
      if (!$task['recurrence']) return 'Recurring Task';
      $r = $task['recurrence'];
      $s = $r['interval'] > 1 ? 's' : '';
      $units = ['Days' => 'day', 'Weeks' => 'week', 'Months' => 'month'];
      $unit = $units[$r['frequency']] ?? '';
      if ($r['frequency'] === 'SpecificDays') {
        $days = array_map(fn($d) => ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'][$d], $r['daysOfWeek'] ?? []);
        return 'Recurs on: ' . implode(', ', $days);
      }
      return "Recurs every {$r['interval']} $unit$s";
    case 'OneOff':
      if ($task['deadline']) {
        $dl = strtotime($task['deadline']);
        $overdue = $dl < time() ? ' (Overdue)' : '';
        $hasTime = date('H:i', $dl) !== '00:00';
        $fmt = $hasTime ? 'M j, Y g:i A' : 'M j, Y';
        return 'Deadline: ' . date($fmt, $dl) . $overdue;
      }
      return 'One-off Task';
    default: return '';
  }
}

// ====== Skip / snooze ======
function is_snoozed(array $task): bool {
  return $task['lastSkipped'] !== null && strtotime($task['lastSkipped']) > time();
}

function skip_task(int $userId, string $taskId, string $until): void {
  $stmt = db()->prepare('UPDATE tasks SET last_skipped = ? WHERE id = ? AND user_id = ?');
  $stmt->execute([$until, $taskId, $userId]);
}

function unsnooze_task(int $userId, string $taskId): void {
  $stmt = db()->prepare('UPDATE tasks SET last_skipped = NULL WHERE id = ? AND user_id = ?');
  $stmt->execute([$taskId, $userId]);
}

function skip_until(string $duration): string {
  switch ($duration) {
    case '15min':  return date('c', time() + 15 * 60);
    case '1hour':  return date('c', time() + 60 * 60);
    case 'today':  return date('c', strtotime('tomorrow') - 1);
    case 'tomorrow': return date('c', strtotime('tomorrow') + 86399);
    default: return date('c', time() + 15 * 60);
  }
}

// ====== Active timer (DB-backed) ======
function get_active_timer(int $userId): ?array {
  $stmt = db()->prepare('SELECT * FROM active_timers WHERE user_id = ?');
  $stmt->execute([$userId]);
  $row = $stmt->fetch();
  if (!$row) return null;
  $task = get_task($userId, $row['task_id']);
  if (!$task) {
    clear_active_timer($userId);
    return null;
  }
  return [
    'taskId' => $row['task_id'],
    'taskName' => $task['name'],
    'taskType' => $task['type'],
    'startAt' => $row['start_at'],
    'endAt' => $row['end_at'],
    'endTime' => strtotime($row['end_at']),
    'duration' => $task['duration'],
  ];
}

function start_active_timer(int $userId, array $task): void {
  clear_active_timer($userId);
  $startAt = date('c');
  $endAt = date('c', time() + $task['duration'] * 60);
  $stmt = db()->prepare('INSERT INTO active_timers (user_id, task_id, start_at, end_at) VALUES (?, ?, ?, ?)');
  $stmt->execute([$userId, $task['id'], $startAt, $endAt]);
}

function clear_active_timer(int $userId): void {
  $stmt = db()->prepare('DELETE FROM active_timers WHERE user_id = ?');
  $stmt->execute([$userId]);
}

// ====== Collections ======
function get_collections(int $userId): array {
  $stmt = db()->prepare('SELECT * FROM collections WHERE user_id = ? ORDER BY name ASC');
  $stmt->execute([$userId]);
  return array_map(function($r) {
    return [
      'id' => (int)$r['id'],
      'type' => $r['type'],
      'name' => $r['name'],
      'description' => $r['description'] ?: null,
      'parentId' => $r['parent_id'] !== null ? (int)$r['parent_id'] : null,
      'createdAt' => $r['created_at'],
    ];
  }, $stmt->fetchAll());
}

function get_collection(int $userId, int $id): ?array {
  $stmt = db()->prepare('SELECT * FROM collections WHERE id = ? AND user_id = ?');
  $stmt->execute([$id, $userId]);
  $row = $stmt->fetch();
  if (!$row) return null;
  return [
    'id' => (int)$row['id'],
    'type' => $row['type'],
    'name' => $row['name'],
    'description' => $row['description'] ?: null,
    'parentId' => $row['parent_id'] !== null ? (int)$row['parent_id'] : null,
    'createdAt' => $row['created_at'],
  ];
}

function save_collection(int $userId, array $data): int {
  if (!empty($data['id'])) {
    $stmt = db()->prepare('UPDATE collections SET type=?, name=?, description=?, parent_id=? WHERE id=? AND user_id=?');
    $stmt->execute([$data['type'], $data['name'], $data['description'] ?? null, $data['parentId'] ?? null, $data['id'], $userId]);
    return (int)$data['id'];
  }
  $stmt = db()->prepare('INSERT INTO collections (user_id, type, name, description, parent_id) VALUES (?, ?, ?, ?, ?)');
  $stmt->execute([$userId, $data['type'], $data['name'], $data['description'] ?? null, $data['parentId'] ?? null]);
  return (int)db()->lastInsertId();
}

function delete_collection(int $userId, int $id): void {
  $stmt = db()->prepare('DELETE FROM collections WHERE id = ? AND user_id = ?');
  $stmt->execute([$id, $userId]);
}

function set_collection_parent(int $userId, int $id, ?int $parentId): void {
  if ($id === $parentId) return;
  if ($parentId !== null && is_descendant_of($userId, $parentId, $id)) return;
  $stmt = db()->prepare('UPDATE collections SET parent_id = ? WHERE id = ? AND user_id = ?');
  $stmt->execute([$parentId, $id, $userId]);
}

function is_descendant_of(int $userId, int $candidateId, int $ancestorId): bool {
  $stmt = db()->prepare('SELECT parent_id FROM collections WHERE id = ? AND user_id = ?');
  $cur = $candidateId;
  while ($cur !== null) {
    if ($cur === $ancestorId) return true;
    $stmt->execute([$cur, $userId]);
    $row = $stmt->fetch();
    $cur = $row && $row['parent_id'] !== null ? (int)$row['parent_id'] : null;
  }
  return false;
}

function get_descendant_collection_ids(int $userId, int $rootId): array {
  $all = get_collections($userId);
  $byParent = [];
  foreach ($all as $c) { $byParent[$c['parentId']][] = $c['id']; }
  $result = [$rootId];
  $stack = [$rootId];
  while ($stack) {
    $cur = array_pop($stack);
    foreach ($byParent[$cur] ?? [] as $childId) {
      $result[] = $childId;
      $stack[] = $childId;
    }
  }
  return $result;
}

function collection_path(int $userId, int $id): array {
  $path = [];
  $cur = $id;
  while ($cur !== null) {
    $c = get_collection($userId, $cur);
    if (!$c) break;
    array_unshift($path, $c);
    $cur = $c['parentId'];
  }
  return $path;
}

// ====== Skills ======
const ARCHETYPE_SKILLS = ['Magician', 'Lover', 'Warrior', 'King'];
const SKILL_ROOT_COLORS = [
  'Magician' => '#8b5cf6', 'Lover' => '#f43f5e', 'Warrior' => '#f97316', 'King' => '#eab308',
];

function skills_table_exists(): bool {
  try {
    db()->query("SELECT 1 FROM skills LIMIT 1");
    return true;
  } catch (Throwable $e) {
    return false;
  }
}

function skill_parents_table_exists(): bool {
  try {
    db()->query("SELECT 1 FROM skill_parents LIMIT 1");
    return true;
  } catch (Throwable $e) {
    return false;
  }
}

function seed_skills(int $userId): void {
  if (!skills_table_exists()) return;
  $stmt = db()->prepare('SELECT COUNT(*) FROM skills WHERE user_id = ?');
  $stmt->execute([$userId]);
  if ((int)$stmt->fetchColumn() > 0) return;
  $stmt = db()->prepare('INSERT INTO skills (user_id, name, parent_id) VALUES (?, ?, NULL)');
  foreach (ARCHETYPE_SKILLS as $name) $stmt->execute([$userId, $name]);
}

// Returns the skill list; each skill carries parentIds[] (multiple parents allowed).
// Falls back to the legacy parent_id column when the junction table isn't migrated yet.
function get_skills(int $userId): array {
  seed_skills($userId);
  if (!skills_table_exists()) return [];
  $stmt = db()->prepare('SELECT * FROM skills WHERE user_id = ? ORDER BY name ASC');
  $stmt->execute([$userId]);
  $rows = $stmt->fetchAll();
  $parents = [];
  if (skill_parents_table_exists()) {
    $stmt = db()->prepare('SELECT sp.skill_id, sp.parent_id FROM skill_parents sp JOIN skills s ON s.id = sp.skill_id WHERE s.user_id = ?');
    $stmt->execute([$userId]);
    foreach ($stmt->fetchAll() as $r) $parents[(int)$r['skill_id']][] = (int)$r['parent_id'];
  }
  return array_map(function($r) use ($parents) {
    $pid = $r['parent_id'] !== null ? (int)$r['parent_id'] : null;
    $parentIds = $parents[(int)$r['id']] ?? [];
    if ($pid !== null && !in_array($pid, $parentIds, true)) $parentIds[] = $pid;
    return [
      'id' => (int)$r['id'],
      'name' => $r['name'],
      'parentIds' => $parentIds,
      'archived' => (int)$r['archived'] === 1,
      'createdAt' => $r['created_at'],
    ];
  }, $rows);
}

function get_skill(int $userId, int $id): ?array {
  seed_skills($userId);
  $stmt = db()->prepare('SELECT * FROM skills WHERE id = ? AND user_id = ?');
  $stmt->execute([$id, $userId]);
  $r = $stmt->fetch();
  if (!$r) return null;
  $parentIds = [];
  if (skill_parents_table_exists()) {
    $stmt = db()->prepare('SELECT parent_id FROM skill_parents WHERE skill_id = ?');
    $stmt->execute([$id]);
    foreach ($stmt->fetchAll() as $p) $parentIds[] = (int)$p['parent_id'];
  }
  if ($r['parent_id'] !== null && !in_array((int)$r['parent_id'], $parentIds, true)) $parentIds[] = (int)$r['parent_id'];
  return [
    'id' => (int)$r['id'],
    'name' => $r['name'],
    'parentIds' => $parentIds,
    'archived' => (int)$r['archived'] === 1,
    'createdAt' => $r['created_at'],
  ];
}

// Creates/updates a skill. parentIds: full replace of the parent set.
// Legacy single-parent data accepted via parentId for backward compatibility.
function save_skill(int $userId, array $data): int {
  seed_skills($userId);
  if (!empty($data['id'])) {
    $stmt = db()->prepare('UPDATE skills SET name=?, archived=? WHERE id=? AND user_id=?');
    $stmt->execute([
      $data['name'],
      !empty($data['archived']) ? 1 : 0,
      $data['id'], $userId,
    ]);
    $id = (int)$data['id'];
  } else {
    $stmt = db()->prepare('INSERT INTO skills (user_id, name, archived) VALUES (?, ?, ?)');
    $stmt->execute([$userId, $data['name'], !empty($data['archived']) ? 1 : 0]);
    $id = (int)db()->lastInsertId();
  }
  if (skill_parents_table_exists()) {
    if (array_key_exists('parentIds', $data)) {
      $pids = is_array($data['parentIds']) ? $data['parentIds'] : [];
      set_skill_parents($userId, $id, $pids);
    } elseif (array_key_exists('parentId', $data)) {
      set_skill_parents($userId, $id, $data['parentId'] === null ? [] : [$data['parentId']]);
    }
  } elseif (!empty($data['parentId']) || (array_key_exists('parentIds', $data) && !empty($data['parentIds']))) {
    // Pre-junction-table fallback: single parent only.
    $pid = $data['parentIds'][0] ?? $data['parentId'] ?? null;
    if ($pid !== null && !is_skill_ancestor_of($userId, $id, (int)$pid)) {
      $stmt = db()->prepare('UPDATE skills SET parent_id = ? WHERE id = ? AND user_id = ?');
      $stmt->execute([(int)$pid, $id, $userId]);
    }
  }
  return $id;
}

// Replace a skill's full parent set. Cycle-safe: a parent that would make the
// skill its own ancestor (i.e. the skill is an ancestor OF that parent) is skipped.
function set_skill_parents(int $userId, int $id, array $parentIds): void {
  if (!skill_parents_table_exists()) return;
  $clean = [];
  foreach (array_unique(array_map('intval', $parentIds)) as $pid) {
    if ($pid === $id) continue;
    if (!get_skill($userId, $pid)) continue;
    if (is_skill_ancestor_of($userId, $id, $pid)) continue;
    $clean[] = $pid;
  }
  $stmt = db()->prepare('DELETE FROM skill_parents WHERE skill_id = ?');
  $stmt->execute([$id]);
  $stmt = db()->prepare('INSERT IGNORE INTO skill_parents (skill_id, parent_id) VALUES (?, ?)');
  foreach ($clean as $pid) $stmt->execute([$id, $pid]);
}

// Add one extra parent without touching the others.
function add_skill_parent(int $userId, int $id, int $parentId): void {
  if ($id === $parentId) return;
  if (!get_skill($userId, $parentId)) return;
  if (is_skill_ancestor_of($userId, $id, $parentId)) return;
  if (!skill_parents_table_exists()) return;
  $stmt = db()->prepare('INSERT IGNORE INTO skill_parents (skill_id, parent_id) VALUES (?, ?)');
  $stmt->execute([$id, $parentId]);
}

// True if $candidate is reachable upward from $start (i.e. $start is a descendant of $candidate).
function is_skill_ancestor_of(int $userId, int $candidateId, int $startId): bool {
  $skills = get_skills($userId);
  $byId = [];
  foreach ($skills as $s) $byId[$s['id']] = $s;
  $stack = [$startId];
  $seen = [];
  while ($stack) {
    $cur = array_pop($stack);
    if (isset($seen[$cur])) continue;
    $seen[$cur] = true;
    if ($cur === $candidateId) return true;
    foreach (($byId[$cur]['parentIds'] ?? []) as $p) if (isset($byId[$p])) $stack[] = $p;
  }
  return false;
}

// Back-compat wrapper: the old single-parent reparent (now replaces the parent set).
function set_skill_parent(int $userId, int $id, ?int $parentId): void {
  if (skill_parents_table_exists()) {
    set_skill_parents($userId, $id, $parentId === null ? [] : [$parentId]);
  } else {
    if ($id === $parentId) return;
    if ($parentId !== null && is_skill_ancestor_of($userId, $id, $parentId)) return;
    $stmt = db()->prepare('UPDATE skills SET parent_id = ? WHERE id = ? AND user_id = ?');
    $stmt->execute([$parentId, $id, $userId]);
  }
}

// Returns ['taskCount' => n, 'completionCount' => m] blocking a delete, or [] if deletable.
function skill_references(int $userId, int $id): array {
  $refs = [];
  $stmt = db()->prepare('SELECT COUNT(*) FROM task_skills WHERE user_id = ? AND skill_id = ?');
  $stmt->execute([$userId, $id]);
  $tc = (int)$stmt->fetchColumn();
  $stmt = db()->prepare('SELECT COUNT(*) FROM completed_task_skills WHERE user_id = ? AND skill_id = ?');
  $stmt->execute([$userId, $id]);
  $cc = (int)$stmt->fetchColumn();
  if ($tc > 0 || $cc > 0) $refs = ['taskCount' => $tc, 'completionCount' => $cc];
  return $refs;
}

function delete_skill(int $userId, int $id): bool {
  seed_skills($userId);
  if (!empty(skill_references($userId, $id))) return false;
  // With the junction table, children detach automatically (their parent rows cascade).
  // Pre-junction: orphan children to top level first so the FK doesn't block.
  if (!skill_parents_table_exists()) {
    $stmt = db()->prepare('UPDATE skills SET parent_id = NULL WHERE parent_id = ? AND user_id = ?');
    $stmt->execute([$id, $userId]);
  }
  $stmt = db()->prepare('DELETE FROM skills WHERE id = ? AND user_id = ?');
  $stmt->execute([$id, $userId]);
  return true;
}

function get_task_skills(int $userId, string $taskId): array {
  if (!skills_table_exists()) return [];
  $stmt = db()->prepare('SELECT s.id, s.name FROM task_skills ts JOIN skills s ON s.id = ts.skill_id WHERE ts.user_id = ? AND ts.task_id = ? ORDER BY s.name');
  $stmt->execute([$userId, $taskId]);
  return array_map(function($r) { return ['id' => (int)$r['id'], 'name' => $r['name']]; }, $stmt->fetchAll());
}

function set_task_skills(int $userId, string $taskId, array $skillIds): void {
  if (!skills_table_exists()) return;
  $stmt = db()->prepare('DELETE FROM task_skills WHERE user_id = ? AND task_id = ?');
  $stmt->execute([$userId, $taskId]);
  $stmt = db()->prepare('INSERT IGNORE INTO task_skills (task_id, user_id, skill_id) VALUES (?, ?, ?)');
  foreach (array_unique(array_map('intval', $skillIds)) as $sid) {
    if (get_skill($userId, $sid)) $stmt->execute([$taskId, $userId, $sid]);
  }
}

function get_completed_skills(int $userId, int $completedId): array {
  if (!skills_table_exists()) return [];
  $stmt = db()->prepare('SELECT s.id, s.name FROM completed_task_skills cts JOIN skills s ON s.id = cts.skill_id WHERE cts.user_id = ? AND cts.completed_id = ? ORDER BY s.name');
  $stmt->execute([$userId, $completedId]);
  return array_map(function($r) { return ['id' => (int)$r['id'], 'name' => $r['name']]; }, $stmt->fetchAll());
}

function set_completed_skills(int $userId, int $completedId, array $skillIds): void {
  if (!skills_table_exists()) return;
  $stmt = db()->prepare('DELETE FROM completed_task_skills WHERE user_id = ? AND completed_id = ?');
  $stmt->execute([$userId, $completedId]);
  $stmt = db()->prepare('INSERT IGNORE INTO completed_task_skills (completed_id, user_id, skill_id) VALUES (?, ?, ?)');
  foreach (array_unique(array_map('intval', $skillIds)) as $sid) {
    if (get_skill($userId, $sid)) $stmt->execute([$completedId, $userId, $sid]);
  }
}

// Roll-up over the DAG: minutes propagate from each stamped skill to every ancestor
// reachable by any path — each ancestor counted ONCE per stamp (dedupe).
// Computed from the frozen stamps x the CURRENT tree. Re-orgs re-interpret
// history on the next read. $completed: pre-filtered completions.
function skill_minutes(int $userId, array $completed): array {
  if (!skills_table_exists() || empty($completed)) return [];
  $skills = get_skills($userId);
  $byId = [];
  foreach ($skills as $s) $byId[$s['id']] = $s;

  $ids = array_column($skills, 'id');
  $stamps = [];
  if ($ids) {
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare("SELECT completed_id, skill_id FROM completed_task_skills WHERE user_id = ? AND skill_id IN ($in)");
    $stmt->execute(array_merge([$userId], $ids));
    foreach ($stmt->fetchAll() as $r) $stamps[(int)$r['completed_id']][] = (int)$r['skill_id'];
  }

  // Pre-compute the ancestor closure (deduped) for every skill node.
  $ancestorsOf = [];
  foreach ($skills as $s) {
    $set = [];
    $stack = $s['parentIds'];
    while ($stack) {
      $cur = array_pop($stack);
      if (isset($set[$cur])) continue;
      $set[$cur] = true;
      foreach (($byId[$cur]['parentIds'] ?? []) as $p) $stack[] = $p;
    }
    $ancestorsOf[$s['id']] = array_keys($set);
  }

  $minutes = [];
  foreach ($completed as $c) {
    $sidList = $stamps[(int)$c['id']] ?? [];
    // Union of all ancestors of all stamps on this completion (deduped per stamp-set).
    $all = [];
    foreach ($sidList as $sid) {
      if (isset($byId[$sid])) $all[$sid] = true;
      foreach ($ancestorsOf[$sid] ?? [] as $a) $all[$a] = true;
    }
    foreach (array_keys($all) as $node) $minutes[$node] = ($minutes[$node] ?? 0) + $c['duration'];
  }
  return $minutes;
}

// ====== Task actions ======
function complete_task(int $userId, array $task, ?string $note = null, ?string $startAt = null, ?string $endAt = null): void {
  $completedAt = $endAt ?: ($startAt ?: date('c'));
  $stmt = db()->prepare('INSERT INTO completed_tasks (user_id, task_id, task_name, category, duration, completed_at, start_at, end_at, note, collection_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
  $stmt->execute([$userId, $task['id'], $task['name'], $task['category'], $task['duration'], $completedAt, $startAt, $endAt, $note, $task['collectionId'] ?? null]);
  $completedId = (int)db()->lastInsertId();

  // Stamp the completion with the task's default skill tags (frozen snapshot),
  // unless the caller provided an explicit per-completion override.
  if (array_key_exists('skillIds', $task)) {
    if (!empty($task['skillIds'])) set_completed_skills($userId, $completedId, $task['skillIds']);
  } elseif (skills_table_exists()) {
    $defaults = array_column(get_task_skills($userId, $task['id']), 'id');
    if (!empty($defaults)) set_completed_skills($userId, $completedId, $defaults);
  }

  if ($task['type'] === 'OneOff') {
    $stmt = db()->prepare('DELETE FROM tasks WHERE id = ? AND user_id = ?');
    $stmt->execute([$task['id'], $userId]);
  } elseif ($task['type'] === 'Recurring') {
    $stmt = db()->prepare('UPDATE tasks SET last_completed = ?, last_skipped = NULL WHERE id = ? AND user_id = ?');
    $stmt->execute([$completedAt, $task['id'], $userId]);
  } else {
    $stmt = db()->prepare('UPDATE tasks SET last_skipped = NULL WHERE id = ? AND user_id = ?');
    $stmt->execute([$task['id'], $userId]);
  }
}

function save_task(int $userId, array $data): void {
  $recurrence = $data['recurrence'] ?? null;
  $stmt = db()->prepare('INSERT INTO tasks (id, user_id, name, category, duration, type, recurrence_frequency, recurrence_interval, recurrence_days_of_week, deadline, last_completed, last_skipped, pinned, collection_id, note, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE name=VALUES(name), category=VALUES(category), duration=VALUES(duration), type=VALUES(type), recurrence_frequency=VALUES(recurrence_frequency), recurrence_interval=VALUES(recurrence_interval), recurrence_days_of_week=VALUES(recurrence_days_of_week), deadline=VALUES(deadline), last_completed=VALUES(last_completed), last_skipped=VALUES(last_skipped), pinned=VALUES(pinned), collection_id=VALUES(collection_id), note=VALUES(note)');
  $stmt->execute([
    $data['id'], $userId, $data['name'], $data['category'], (int)$data['duration'], $data['type'],
    $recurrence['frequency'] ?? null,
    $recurrence ? (int)($recurrence['interval'] ?? 1) : null,
    $recurrence && !empty($recurrence['daysOfWeek']) ? implode(',', $recurrence['daysOfWeek']) : null,
    $data['deadline'] ?? null, $data['lastCompleted'] ?? null, $data['lastSkipped'] ?? null, !empty($data['pinned']) ? 1 : 0, $data['collectionId'] ?? null, $data['note'] ?? null, $data['createdAt'],
  ]);
}

function render_header(string $current, array $user): void {
  $views = [
    'index' => 'Next Up', 'browse' => 'Browse', 'collections' => 'Collections', 'calendar' => 'Calendar', 'report' => 'Report', 'skills' => 'Skills', 'settings' => 'Settings',
  ];
  echo '<header class="bg-slate-800/80 backdrop-blur-sm shadow-md sticky top-0 z-40 border-b border-slate-700">
    <nav class="container mx-auto px-4 sm:px-6 lg:px-8">
      <div class="flex items-center justify-between h-16">
        <div class="flex items-center gap-3">
          <a href="index.php" class="text-white font-bold text-xl font-condensed"><span class="text-white">•</span> TaskFlow</a>
          <span class="text-xs text-slate-400 font-mono">' . htmlspecialchars($user['username']) . '</span>
        </div>
        <div class="flex items-center gap-2 sm:gap-4">
          <div class="hidden md:flex items-center gap-2">';
  foreach ($views as $id => $label) {
    $active = $current === $id ? 'bg-accent-500/90 text-slate-950' : 'text-slate-300 hover:bg-slate-700 hover:text-white';
    echo "<a href=\"$id.php\" class=\"px-3 py-2 rounded-md text-sm font-medium transition-colors $active\">$label</a>";
  }
  echo '</div>
    <a href="edit_task.php" class="flex items-center gap-2 bg-accent-400 hover:bg-accent-300 text-slate-950 font-bold py-2 px-4 rounded-md transition-colors shadow">Add Task</a>
  </div></div>';
  echo '<div class="md:hidden flex items-center justify-around py-2 border-t border-slate-700">';
  foreach ($views as $id => $label) {
    $active = $current === $id ? 'bg-accent-500/90 text-slate-950' : 'text-slate-300 hover:bg-slate-700';
    echo "<a href=\"$id.php\" class=\"px-3 py-2 rounded-md text-xs font-medium transition-colors $active\">$label</a>";
  }
  echo '</div></nav></header>';
}

function render_budget_sidebar(int $userId): void {
  $budgets = get_budgets($userId);
  $transfers = get_daily_transfers($userId);
  echo '<div class="bg-slate-800 p-4 rounded-lg shadow-lg">
    <h2 class="text-lg font-bold text-slate-200 mb-4">Daily Budgets</h2>
    <div class="space-y-4">';
  foreach ($budgets as $b) {
    $consumed = consumed_today($userId, $b['category']);
    $total = effective_budget($userId, $b['category'], $budgets, $transfers);
    $pct = $total > 0 ? min(($consumed / $total) * 100, 100) : 0;
    $color = CAT_COLORS[$b['category']] ?? 'bg-gray-500';
    $text = CAT_TEXT[$b['category']] ?? 'text-gray-400';
    echo "<div>
      <div class=\"flex justify-between items-center mb-1\">
        <span class=\"text-sm font-semibold $text\">{$b['category']}</span>
        <span class=\"text-xs text-slate-400 font-mono\">$consumed / $total min</span>
      </div>
      <div class=\"w-full bg-slate-700 rounded-full h-2.5\">
        <div class=\"$color h-2.5 rounded-full transition-all duration-500\" style=\"width:{$pct}%\"></div>
      </div>
    </div>";
  }
  echo '</div>';
  if (!empty($transfers)) {
    echo '<div class="mt-4 pt-4 border-t border-slate-700">
      <h3 class="text-sm font-semibold text-slate-300 mb-2">Today\'s Transfers</h3>
      <div class="space-y-1">';
    foreach ($transfers as $t) {
      $fromText = CAT_TEXT[$t['fromCategory']] ?? 'text-gray-400';
      $toText = CAT_TEXT[$t['toCategory']] ?? 'text-gray-400';
      echo "<div class=\"flex items-center justify-between text-xs\">
        <span><span class=\"$fromText\">" . htmlspecialchars($t['fromCategory']) . "</span> → <span class=\"$toText\">" . htmlspecialchars($t['toCategory']) . "</span> <span class=\"text-slate-400\">+{$t['minutes']} min</span></span>
        <form method=\"POST\" action=\"action.php\" class=\"inline\">
          <input type=\"hidden\" name=\"action\" value=\"delete_transfer\">
          <input type=\"hidden\" name=\"transfer_id\" value=\"{$t['id']}\">
          <button type=\"submit\" class=\"text-slate-500 hover:text-rose-400\">✕</button>
        </form>
      </div>";
    }
    echo '</div></div>';
  }
  echo '<form method="POST" action="action.php" class="mt-4 pt-4 border-t border-slate-700 space-y-2">
    <h3 class="text-sm font-semibold text-slate-300">Transfer Minutes</h3>
    <div class="grid grid-cols-2 gap-2">
      <select name="from_category" class="p-1.5 bg-slate-700 border border-slate-600 rounded text-xs text-slate-300">';
  foreach (CATEGORIES as $c) echo "<option value=\"$c\">$c</option>";
  echo '</select>
      <select name="to_category" class="p-1.5 bg-slate-700 border border-slate-600 rounded text-xs text-slate-300">';
  foreach (CATEGORIES as $c) echo "<option value=\"$c\">$c</option>";
  echo '</select>
    </div>
    <div class="flex gap-2">
      <input type="number" name="minutes" min="5" step="5" value="60" class="w-20 p-1.5 bg-slate-700 border border-slate-600 rounded text-xs text-slate-300">
      <input type="hidden" name="action" value="transfer_budget">
      <button type="submit" class="flex-1 bg-accent-400 hover:bg-accent-300 text-slate-950 text-xs font-bold py-1.5 rounded transition-colors">Transfer</button>
    </div>
  </form>';
  echo '</div>';
}