<?php
// TaskFlow JSON API entry point.
// Auth: Bearer token (sha256-hashed in the DB). See api_auth.php.
// Routing: uses PATH_INFO, e.g. /api.php/tasks/123/complete
// Fallback: query string ?p=/tasks/123/complete  (for hosts where PATH_INFO is off)
// Body: JSON for POST/PUT, form-encoded also accepted.

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/api_auth.php';

mb_internal_encoding('UTF-8');

// CORS: allow simple same-origin. If you want to call from a browser on another
// origin later, set Access-Control-Allow-Origin explicitly there.
header('Cache-Control: no-store');

// ---- Resolve request path ----
$path = $_SERVER['PATH_INFO'] ?? '';
if ($path === '' && isset($_GET['p'])) $path = $_GET['p'];
$path = '/' . trim($path ?? '', '/');
if ($path === '/') $path = '/';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Health probe — no auth required, so external monitors/curl can check liveness.
if ($path === '/ping') {
  api_send(['ok' => true, 'service' => 'taskflow-api', 'time' => date('c')]);
}

// Everything else requires a valid API user.
$user = api_require_user();

// ---- Parse request body ----
function api_body(): array {
  static $b = null;
  if ($b === null) {
    $raw = file_get_contents('php://input') ?: '';
    $ctype = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($ctype, 'application/json') !== false || ($raw !== '' && in_array($raw[0], ['{', '['], true))) {
      $decoded = json_decode($raw, true);
      $b = is_array($decoded) ? $decoded : [];
    } else {
      $b = $_POST;
    }
  }
  return $b;
}

// Match a path like /tasks/{id}/complete and return the captured id, or null.
function match_route(string $pattern, string $path, array &$params = []): bool {
  $pp = explode('/', trim($pattern, '/'));
  $pa = explode('/', trim($path, '/'));
  if (count($pp) !== count($pa)) return false;
  $params = [];
  for ($i = 0; $i < count($pp); $i++) {
    if ($pp[$i] === '{id}') {
      $params['id'] = $pa[$i];
    } elseif ($pp[$i] === '{int}') {
      if (!ctype_digit($pa[$i])) return false;
      $params['int'] = (int)$pa[$i];
    } elseif ($pp[$i] !== $pa[$i]) {
      return false;
    }
  }
  return true;
}

// ---- Enriched budget view (used by GET /budgets) ----
function api_budgets_view(int $userId): array {
  $budgets = get_budgets($userId);
  $transfers = get_daily_transfers($userId);
  $out = [];
  foreach ($budgets as $b) {
    $cat = $b['category'];
    $consumed = consumed_today($userId, $cat);
    $effective = effective_budget($userId, $cat, $budgets, $transfers);
    $out[] = [
      'category' => $cat,
      'weekdayBudget' => $b['weekdayBudget'],
      'weekendBudget' => $b['weekendBudget'],
      'effective' => $effective,
      'consumed' => $consumed,
      'remaining' => max(0, $effective - $consumed),
    ];
  }
  return ['budgets' => $out, 'transfers' => $transfers, 'isWeekday' => is_weekday()];
}

// ---- Enriched completed entry view (used by GET /history) ----
function api_completed_view(array $r, array $collectionNames = [], array $skillData = null): array {
  $colId = $r['collection_id'] !== null ? (int)$r['collection_id'] : null;
  $skills = null;
  if (is_array($skillData) && isset($skillData['stamps'])) {
    $skills = $skillData['stamps'][(int)$r['id']] ?? [];
  }
  return [
    'id' => (int)$r['id'], 'taskId' => $r['task_id'], 'taskName' => $r['task_name'],
    'category' => $r['category'], 'duration' => (int)$r['duration'],
    'completedAt' => $r['completed_at'], 'startAt' => $r['start_at'] ?: null,
    'endAt' => $r['end_at'] ?: null, 'note' => $r['note'] ?: null,
    'collectionId' => $colId,
    'collectionName' => ($colId !== null && isset($collectionNames[$colId])) ? $collectionNames[$colId] : null,
    'skills' => $skills,
  ];
}

function api_skill_stamps(int $userId): array {
  // [completedId => [{id, name}, ...]]
  if (!skills_table_exists()) return [];
  $stmt = db()->prepare('SELECT cts.completed_id, s.id, s.name FROM completed_task_skills cts JOIN skills s ON s.id = cts.skill_id WHERE cts.user_id = ? ORDER BY s.name');
  $stmt->execute([$userId]);
  $out = [];
  foreach ($stmt->fetchAll() as $r) $out[(int)$r['completed_id']][] = ['id' => (int)$r['id'], 'name' => $r['name']];
  return $out;
}

function api_history(int $userId, ?string $date = null, ?int $collectionId = null): array {
  $collectionNames = [];
  foreach (get_collections($userId) as $col) { $collectionNames[$col['id']] = $col['name']; }
  $skillData = ['stamps' => api_skill_stamps($userId)];
  if ($collectionId !== null) {
    if ($date) {
      $stmt = db()->prepare('SELECT * FROM completed_tasks WHERE user_id = ? AND DATE(completed_at) = ? AND collection_id = ? ORDER BY completed_at DESC');
      $stmt->execute([$userId, $date, $collectionId]);
    } else {
      $stmt = db()->prepare('SELECT * FROM completed_tasks WHERE user_id = ? AND collection_id = ? ORDER BY completed_at DESC LIMIT 200');
      $stmt->execute([$userId, $collectionId]);
    }
  } else {
    if ($date) {
      $stmt = db()->prepare('SELECT * FROM completed_tasks WHERE user_id = ? AND DATE(completed_at) = ? ORDER BY completed_at DESC');
      $stmt->execute([$userId, $date]);
    } else {
      $stmt = db()->prepare('SELECT * FROM completed_tasks WHERE user_id = ? ORDER BY completed_at DESC LIMIT 200');
      $stmt->execute([$userId]);
    }
  }
  return array_map(fn($r) => api_completed_view($r, $collectionNames, $skillData), $stmt->fetchAll());
}

// ---- Enriched task view (adds eligibility/next-availability info) ----
function api_task_view(int $userId, array $task): array {
  $budgets = get_budgets($userId);
  $transfers = get_daily_transfers($userId);
  $consumed = consumed_today($userId, $task['category']);
  $effective = effective_budget($userId, $task['category'], $budgets, $transfers);
  $eligible = is_recurring_eligible($task);
  $snoozed = is_snoozed($task);
  $next = next_availability_date($task);
  $detail = task_detail($task);
  $skillIds = null;
  if (skills_table_exists()) {
    $skillIds = array_column(get_task_skills($userId, $task['id']), 'id');
  }
  return [
    'id' => $task['id'], 'name' => $task['name'], 'category' => $task['category'],
    'duration' => $task['duration'], 'type' => $task['type'],
    'recurrence' => $task['recurrence'], 'deadline' => $task['deadline'],
    'lastCompleted' => $task['lastCompleted'], 'lastSkipped' => $task['lastSkipped'],
    'pinned' => $task['pinned'], 'collectionId' => $task['collectionId'],
    'note' => $task['note'], 'createdAt' => $task['createdAt'],
    'detail' => $detail,
    'eligible' => $eligible,
    'snoozed' => $snoozed,
    'nextAvailableDate' => $next,
    'budget' => ['effective' => $effective, 'consumed' => $consumed, 'remaining' => max(0, $effective - $consumed)],
    'skillIds' => $skillIds,
  ];
}

// ---- Skill payload helpers ----
function api_parse_skill_ids($v): ?array {
  if (!is_array($v)) return null;
  $out = [];
  foreach ($v as $sid) {
    $i = (int)$sid;
    if ($i > 0) $out[] = $i;
  }
  return $out;
}

function api_skills_view(int $userId, ?string $range = null): array {
  $skills = get_skills($userId);
  if (empty($skills)) return ['skills' => [], 'note' => 'skills tables not migrated yet'];
  $completed = get_completed($userId);
  $rangeFrom = null; $rangeTo = null;
  switch ($range) {
    case 'today': $rangeFrom = date('Y-m-d'); break;
    case '7d':    $rangeFrom = date('Y-m-d', strtotime('-6 days')); break;
    case '30d':   $rangeFrom = date('Y-m-d', strtotime('-29 days')); break;
    case 'month': $rangeFrom = date('Y-m-01'); break;
    case 'year':  $rangeFrom = date('Y-01-01'); break;
  }
  if ($rangeFrom !== null || $rangeTo !== null) {
    $completed = array_values(array_filter($completed, function($c) use ($rangeFrom, $rangeTo) {
      $d = date('Y-m-d', strtotime($c['completedAt']));
      if ($rangeFrom !== null && $d < $rangeFrom) return false;
      if ($rangeTo !== null && $d > $rangeTo) return false;
      return true;
    }));
  }
  $minutes = skill_minutes($userId, $completed);
  $taskCounts = [];
  $stmt = db()->prepare('SELECT skill_id, COUNT(*) AS n FROM task_skills WHERE user_id = ? GROUP BY skill_id');
  $stmt->execute([$userId]);
  foreach ($stmt->fetchAll() as $r) $taskCounts[(int)$r['skill_id']] = (int)$r['n'];
  $stampCounts = [];
  $stmt = db()->prepare('SELECT skill_id, COUNT(*) AS n FROM completed_task_skills WHERE user_id = ? GROUP BY skill_id');
  $stmt->execute([$userId]);
  foreach ($stmt->fetchAll() as $r) $stampCounts[(int)$r['skill_id']] = (int)$r['n'];
  $out = [];
  foreach ($skills as $s) {
    $out[] = [
      'id' => $s['id'], 'name' => $s['name'], 'parentIds' => $s['parentIds'],
      'archived' => $s['archived'],
      'minutes' => $minutes[$s['id']] ?? 0,
      'taskCount' => $taskCounts[$s['id']] ?? 0,
      'completionCount' => $stampCounts[$s['id']] ?? 0,
    ];
  }
  return ['skills' => $out, 'range' => $range ?: 'all'];
}

// ---- Validate a task payload (create/edit) ----
function api_validate_task(array $d): array {
  $errors = [];
  $name = trim($d['name'] ?? '');
  if ($name === '') $errors['name'] = 'name is required';
  if (!in_array($d['category'] ?? '', CATEGORIES, true)) $errors['category'] = 'invalid category';
  $duration = (int)($d['duration'] ?? 0);
  if ($duration <= 0) $errors['duration'] = 'duration must be > 0';
  $type = $d['type'] ?? '';
  if (!in_array($type, ['Flexible', 'Recurring', 'OneOff'], true)) $errors['type'] = 'invalid type';
  if ($type === 'Recurring') {
    $rec = $d['recurrence'] ?? null;
    if (!is_array($rec) || !in_array($rec['frequency'] ?? '', ['Days', 'Weeks', 'Months', 'SpecificDays'], true)) {
      $errors['recurrence'] = 'recurrence.frequency is required and must be Days|Weeks|Months|SpecificDays';
    } elseif ((int)($rec['interval'] ?? 0) <= 0) {
      $errors['recurrence'] = 'recurrence.interval must be >= 1';
    }
  }
  return $errors;
}

// ============================================================
//  ROUTING
// ============================================================
$params = [];
try {

  // ---------- READ ----------

  if ($path === '/' && $method === 'GET') {
    api_send(['ok' => true, 'service' => 'taskflow-api', 'user' => $user['username'], 'endpoints' => [
      'GET /next', 'GET /tasks', 'GET /tasks/{id}', 'POST /tasks', 'DELETE /tasks/{id}',
      'POST /tasks/{id}/complete', 'POST /tasks/{id}/skip', 'POST /tasks/{id}/unsnooze',
      'GET /history', 'PUT /history/{int}', 'DELETE /history/{int}', 'GET /budgets', 'POST /budgets/transfer', 'DELETE /budgets/transfer/{int}',
      'GET /collections', 'POST /collections', 'DELETE /collections/{int}',
      'GET /skills', 'POST /skills', 'DELETE /skills/{int}',
      'GET /timer', 'POST /timer/start/{id}', 'POST /timer/complete', 'POST /timer/cancel',
    ]]);
  }

  if ($path === '/next' && $method === 'GET') {
    $top = select_top_three($user['id']);
    api_send(array_map(fn($t) => api_task_view($user['id'], $t), $top));
  }

  if ($path === '/tasks' && $method === 'GET') {
    $all = get_tasks($user['id']);
    $cat = $_GET['category'] ?? null;
    $type = $_GET['type'] ?? null;
    $col = isset($_GET['collectionId']) ? (int)$_GET['collectionId'] : null;
    $filtered = array_values(array_filter($all, function($t) use ($cat, $type, $col) {
      if ($cat !== null && $t['category'] !== $cat) return false;
      if ($type !== null && $t['type'] !== $type) return false;
      if ($col !== null && $t['collectionId'] !== $col) return false;
      return true;
    }));
    api_send(array_map(fn($t) => api_task_view($user['id'], $t), $filtered));
  }

  if (match_route('/tasks/{id}', $path, $params) && $method === 'GET') {
    $task = get_task($user['id'], $params['id']);
    if (!$task) api_send(['error' => 'Task not found'], 404);
    api_send(api_task_view($user['id'], $task));
  }

  if ($path === '/history' && $method === 'GET') {
    $date = isset($_GET['date']) ? $_GET['date'] : null;
    $collectionId = isset($_GET['collectionId']) ? (int)$_GET['collectionId'] : null;
    api_send(api_history($user['id'], $date, $collectionId));
  }

  if (match_route('/history/{int}', $path, $params) && $method === 'PUT') {
    $id = $params['int'];
    $entry = get_completed_by_id($user['id'], $id);
    if (!$entry) api_send(['error' => 'Completion not found'], 404);
    $d = api_body();
    $startAt = null;
    if (!empty($d['startAt'])) $startAt = date('c', strtotime($d['startAt']));
    elseif (array_key_exists('startAt', $d)) $startAt = null;
    else $startAt = $entry['startAt'];
    $endAt = null;
    if (!empty($d['endAt'])) $endAt = date('c', strtotime($d['endAt']));
    elseif (array_key_exists('endAt', $d)) $endAt = null;
    else $endAt = $entry['endAt'];
    $data = [
      'category' => $d['category'] ?? $entry['category'],
      'duration' => (int)($d['duration'] ?? $entry['duration']),
      'startAt' => $startAt,
      'endAt' => $endAt,
      'note' => isset($d['note']) ? (trim($d['note']) ?: null) : $entry['note'],
      'collectionId' => array_key_exists('collectionId', $d) ? ($d['collectionId'] !== null ? (int)$d['collectionId'] : null) : $entry['collectionId'],
    ];
    update_completed($user['id'], $id, $data);
    if (skills_table_exists()) {
      $skillIds = api_parse_skill_ids($d['skillIds'] ?? null);
      if ($skillIds !== null) set_completed_skills($user['id'], $id, $skillIds);
    }
    $updated = get_completed_by_id($user['id'], $id);
    $collectionNames = [];
    foreach (get_collections($user['id']) as $col) { $collectionNames[$col['id']] = $col['name']; }
    $skillData = ['stamps' => api_skill_stamps($user['id'])];
    api_send(api_completed_view(['id' => $updated['id'], 'task_id' => $updated['taskId'], 'task_name' => $updated['taskName'], 'category' => $updated['category'], 'duration' => $updated['duration'], 'completed_at' => $updated['completedAt'], 'start_at' => $updated['startAt'], 'end_at' => $updated['endAt'], 'note' => $updated['note'], 'collection_id' => $updated['collectionId']], $collectionNames, $skillData));
  }

  if (match_route('/history/{int}', $path, $params) && $method === 'DELETE') {
    $id = $params['int'];
    $entry = get_completed_by_id($user['id'], $id);
    if (!$entry) api_send(['error' => 'Completion not found'], 404);
    $stmt = db()->prepare('DELETE FROM completed_tasks WHERE id = ? AND user_id = ?');
    $stmt->execute([$id, $user['id']]);
    api_send(['ok' => true, 'deleted' => $stmt->rowCount()]);
  }

  if ($path === '/budgets' && $method === 'GET') {
    api_send(api_budgets_view($user['id']));
  }

  if ($path === '/collections' && $method === 'GET') {
    $cols = get_collections($user['id']);
    // attach task counts
    $tasks = get_tasks($user['id']);
    $counts = [];
    foreach ($tasks as $t) { if ($t['collectionId'] !== null) $counts[$t['collectionId']] = ($counts[$t['collectionId']] ?? 0) + 1; }
    foreach ($cols as &$c) { $c['taskCount'] = $counts[$c['id']] ?? 0; }
    api_send($cols);
  }

  if ($path === '/skills' && $method === 'GET') {
    $range = $_GET['range'] ?? null;
    api_send(api_skills_view($user['id'], is_string($range) ? $range : null));
  }

  if ($path === '/timer' && $method === 'GET') {
    $timer = get_active_timer($user['id']);
    api_send($timer);
  }

  // ---------- WRITE: tasks ----------

  if ($path === '/tasks' && $method === 'POST') {
    $d = api_body();
    $errors = api_validate_task($d);
    if ($errors) api_send(['error' => 'Validation failed', 'fields' => $errors], 422);

    $id = $d['id'] ?? '';
    if ($id === '') {
      $id = 't_' . bin2hex(random_bytes(12));
    }
    $recurrence = $d['recurrence'] ?? null;
    $taskData = [
      'id' => $id,
      'name' => trim($d['name']),
      'category' => $d['category'],
      'duration' => (int)$d['duration'],
      'type' => $d['type'],
      'recurrence' => $recurrence,
      'deadline' => $d['deadline'] ?? null,
      'lastCompleted' => $d['lastCompleted'] ?? null,
      'lastSkipped' => $d['lastSkipped'] ?? null,
      'pinned' => !empty($d['pinned']),
      'collectionId' => isset($d['collectionId']) ? (int)$d['collectionId'] : null,
      'note' => $d['note'] ?? null,
      'createdAt' => $d['createdAt'] ?? date('c'),
    ];
    save_task($user['id'], $taskData);
    if (skills_table_exists()) {
      $skillIds = api_parse_skill_ids($d['skillIds'] ?? null);
      if ($skillIds !== null) set_task_skills($user['id'], $id, $skillIds);
    }
    $task = get_task($user['id'], $id);
    api_send(api_task_view($user['id'], $task), 201);
  }

  if (match_route('/tasks/{id}', $path, $params) && $method === 'PUT') {
    $existing = get_task($user['id'], $params['id']);
    if (!$existing) api_send(['error' => 'Task not found'], 404);
    $d = api_body();
    $errors = api_validate_task($d);
    if ($errors) api_send(['error' => 'Validation failed', 'fields' => $errors], 422);
    $recurrence = $d['recurrence'] ?? null;
    $taskData = [
      'id' => $params['id'],
      'name' => trim($d['name']),
      'category' => $d['category'],
      'duration' => (int)$d['duration'],
      'type' => $d['type'],
      'recurrence' => $recurrence,
      'deadline' => $d['deadline'] ?? null,
      'lastCompleted' => $d['lastCompleted'] ?? $existing['lastCompleted'],
      'lastSkipped' => $d['lastSkipped'] ?? $existing['lastSkipped'],
      'pinned' => !empty($d['pinned']),
      'collectionId' => isset($d['collectionId']) ? (int)$d['collectionId'] : null,
      'note' => $d['note'] ?? null,
      'createdAt' => $existing['createdAt'],
    ];
    save_task($user['id'], $taskData);
    if (skills_table_exists()) {
      $skillIds = api_parse_skill_ids($d['skillIds'] ?? null);
      if ($skillIds !== null) set_task_skills($user['id'], $params['id'], $skillIds);
    }
    $task = get_task($user['id'], $params['id']);
    api_send(api_task_view($user['id'], $task));
  }

  if (match_route('/tasks/{id}', $path, $params) && $method === 'DELETE') {
    $stmt = db()->prepare('DELETE FROM tasks WHERE id = ? AND user_id = ?');
    $stmt->execute([$params['id'], $user['id']]);
    api_send(['ok' => true, 'deleted' => $stmt->rowCount()]);
  }

  if (match_route('/tasks/{id}/complete', $path, $params) && $method === 'POST') {
    $task = get_task($user['id'], $params['id']);
    if (!$task) api_send(['error' => 'Task not found'], 404);
    $d = api_body();
    $note = isset($d['note']) ? (trim($d['note']) ?: null) : null;
    $startAt = $d['startAt'] ?? null;
    $endAt = $d['endAt'] ?? null;
    // Per-completion skill override (defaults to the task's tags).
    $skillOverride = skills_table_exists() ? api_parse_skill_ids($d['skillIds'] ?? null) : null;
    if ($skillOverride !== null) $task['skillIds'] = $skillOverride;
    complete_task($user['id'], $task, $note, $startAt, $endAt);
    api_send(['ok' => true, 'completedTaskId' => $params['id'], 'note' => $note, 'skillIds' => $task['skillIds'] ?? null]);
  }

  if (match_route('/tasks/{id}/skip', $path, $params) && $method === 'POST') {
    $task = get_task($user['id'], $params['id']);
    if (!$task) api_send(['error' => 'Task not found'], 404);
    $d = api_body();
    $until = $d['until'] ?? null;
    if ($until) {
      skip_task($user['id'], $task['id'], date('c', strtotime($until)));
    } else {
      $duration = $d['duration'] ?? '15min';
      skip_task($user['id'], $task['id'], skip_until($duration));
    }
    api_send(['ok' => true, 'skippedTaskId' => $params['id']]);
  }

  if (match_route('/tasks/{id}/unsnooze', $path, $params) && $method === 'POST') {
    unsnooze_task($user['id'], $params['id']);
    api_send(['ok' => true, 'unsnoozedTaskId' => $params['id']]);
  }

  // ---------- WRITE: budgets / transfers ----------

  if ($path === '/budgets/transfer' && $method === 'POST') {
    $d = api_body();
    $from = $d['fromCategory'] ?? ($d['from'] ?? '');
    $to = $d['toCategory'] ?? ($d['to'] ?? '');
    $minutes = (int)($d['minutes'] ?? 0);
    if (!in_array($from, CATEGORIES, true) || !in_array($to, CATEGORIES, true)) {
      api_send(['error' => 'Invalid category'], 422);
    }
    if ($from === $to || $minutes <= 0) api_send(['error' => 'from != to and minutes > 0 required'], 422);
    $before = get_daily_transfers($user['id']);
    add_daily_transfer($user['id'], $from, $to, $minutes);
    $after = get_daily_transfers($user['id']);
    $created = count($after) > count($before) ? end($after) : null;
    api_send(['ok' => true, 'transfer' => $created, 'budgets' => api_budgets_view($user['id'])], 201);
  }

  if (match_route('/budgets/transfer/{int}', $path, $params) && $method === 'DELETE') {
    delete_daily_transfer($user['id'], $params['int']);
    api_send(['ok' => true]);
  }

  // ---------- WRITE: collections ----------

  if ($path === '/collections' && $method === 'POST') {
    $d = api_body();
    $name = trim($d['name'] ?? '');
    if ($name === '') api_send(['error' => 'name is required'], 422);
    $id = !empty($d['id']) ? (int)$d['id'] : null;
    $newId = save_collection($user['id'], [
      'id' => $id,
      'type' => $d['type'] ?? 'Project',
      'name' => $name,
      'description' => isset($d['description']) ? (trim($d['description']) ?: null) : null,
      'parentId' => !empty($d['parentId']) ? (int)$d['parentId'] : null,
    ]);
    $col = get_collection($user['id'], $newId);
    api_send($col, $id ? 200 : 201);
  }

  if (match_route('/collections/{int}', $path, $params) && $method === 'DELETE') {
    delete_collection($user['id'], $params['int']);
    api_send(['ok' => true]);
  }

  // ---------- WRITE: skills ----------

  if ($path === '/skills' && $method === 'POST') {
    $d = api_body();
    $name = trim($d['name'] ?? '');
    if ($name === '') api_send(['error' => 'name is required'], 422);
    $id = !empty($d['id']) ? (int)$d['id'] : null;
    if (is_array($d['parentIds'] ?? null)) {
      $parentIds = array_map('intval', array_filter($d['parentIds'], fn($p) => (int)$p > 0));
    } elseif (array_key_exists('parentIds', $d)) {
      $parentIds = []; // parentIds: null => top level
    } elseif (array_key_exists('parentId', $d)) {
      $parentIds = $d['parentId'] !== null && $d['parentId'] !== '' ? [(int)$d['parentId']] : [];
    } else {
      $parentIds = null; // leave parents untouched
    }
    if ($parentIds !== null && $id !== null) {
      foreach ($parentIds as $pid) {
        // Cycle: the skill being edited is an ancestor of the proposed parent.
        if (is_skill_ancestor_of($user['id'], $id, $pid)) {
          api_send(['error' => 'parentId would create a cycle', 'offendingParentId' => $pid], 422);
        }
      }
    }
    $newId = save_skill($user['id'], [
      'id' => $id,
      'name' => $name,
      'parentIds' => $parentIds,
      'archived' => !empty($d['archived']),
    ]);
    $skill = get_skill($user['id'], $newId);
    api_send($skill, $id ? 200 : 201);
  }

  if (match_route('/skills/{int}', $path, $params) && $method === 'DELETE') {
    $refs = skill_references($user['id'], $params['int']);
    if (!empty($refs)) {
      api_send(['error' => 'Skill is referenced and cannot be deleted', 'references' => $refs, 'hint' => 'Archive it instead, or clear its tags first.'], 409);
    }
    $ok = delete_skill($user['id'], $params['int']);
    api_send(['ok' => $ok], $ok ? 200 : 404);
  }

  // ---------- WRITE: timer ----------

  if (match_route('/timer/start/{id}', $path, $params) && $method === 'POST') {
    $task = get_task($user['id'], $params['id']);
    if (!$task) api_send(['error' => 'Task not found'], 404);
    start_active_timer($user['id'], $task);
    api_send(get_active_timer($user['id']), 201);
  }

  if ($path === '/timer/complete' && $method === 'POST') {
    $timer = get_active_timer($user['id']);
    if (!$timer) api_send(['error' => 'No active timer'], 404);
    $task = get_task($user['id'], $timer['taskId']);
    if (!$task) { clear_active_timer($user['id']); api_send(['error' => 'Timer task missing'], 404); }
    $d = api_body();
    $endNow = date('c');
    $note = isset($d['note']) ? (trim($d['note']) ?: null) : null;
    complete_task($user['id'], $task, $note, $timer['startAt'], $endNow);
    clear_active_timer($user['id']);
    api_send(['ok' => true, 'completedTaskId' => $task['id'], 'note' => $note]);
  }

  if ($path === '/timer/cancel' && $method === 'POST') {
    clear_active_timer($user['id']);
    api_send(['ok' => true]);
  }

  // ---------- 404 ----------
  api_send(['error' => 'Not found', 'path' => $path, 'method' => $method], 404);

} catch (Throwable $e) {
  api_send(['error' => 'Server error', 'message' => $e->getMessage()], 500);
}