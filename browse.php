<?php
require_once __DIR__ . '/functions.php';
$user = require_login();
$tasks = get_tasks($user['id']);
$search = trim($_GET['search'] ?? '');
$categoryFilter = trim($_GET['category'] ?? '');
$collectionFilter = isset($_GET['collection']) ? (int)$_GET['collection'] : 0;
$allCollections = get_collections($user['id']);
$colById = [];
foreach ($allCollections as $c) { $colById[$c['id']] = $c; }
if ($collectionFilter) {
  $descIds = get_descendant_collection_ids($user['id'], $collectionFilter);
  $tasks = array_filter($tasks, fn($t) => $t['collectionId'] !== null && in_array($t['collectionId'], $descIds));
}
if ($search) {
  $tasks = array_filter($tasks, fn($t) => stripos($t['name'], $search) !== false);
}
if ($categoryFilter) {
  $tasks = array_filter($tasks, fn($t) => $t['category'] === $categoryFilter);
}

$groups = [
  'Recurring' => array_filter($tasks, fn($t) => $t['type'] === 'Recurring'),
  'Flexible' => array_filter($tasks, fn($t) => $t['type'] === 'Flexible'),
  'OneOff' => array_filter($tasks, fn($t) => $t['type'] === 'OneOff'),
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>TaskFlow — Browse</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <?php render_fonts(); ?>
  <style>
    ::-webkit-scrollbar { width: 8px; }
    ::-webkit-scrollbar-track { background: #1c1c1b; }
    ::-webkit-scrollbar-thumb { background: #575753; border-radius: 4px; }
  </style>
</head>
<body class="bg-slate-900 text-slate-100 font-sans min-h-screen">
<?php render_header('browse', $user); ?>
<main class="p-4 md:p-6">
  <h1 class="text-3xl font-bold text-slate-100 mb-6">All Tasks</h1>
  <div class="flex flex-wrap gap-2 mb-4">
    <?php $baseCat = $search ? '?search=' . urlencode($search) . '&' : '?'; ?>
    <a href="browse.php<?= $search ? '?search=' . urlencode($search) : '' ?>" class="px-3 py-1.5 rounded-full text-sm font-medium transition-colors <?= $categoryFilter === '' ? 'bg-accent-500/90 text-slate-950' : 'bg-slate-700 text-slate-300 hover:bg-slate-600' ?>">All</a>
    <?php foreach (CATEGORIES as $c):
      $text = CAT_TEXT[$c] ?? 'text-gray-400'; ?>
      <a href="browse.php?<?= $search ? 'search=' . urlencode($search) . '&' : '' ?>category=<?= urlencode($c) ?>" class="px-3 py-1.5 rounded-full text-sm font-medium transition-colors <?= $categoryFilter === $c ? 'bg-accent-500/90 text-slate-950' : 'bg-slate-700 ' . $text . ' hover:bg-slate-600' ?>"><?= htmlspecialchars($c) ?></a>
    <?php endforeach; ?>
  </div>
  <form method="GET" class="mb-6 flex gap-2">
    <?php if ($categoryFilter): ?><input type="hidden" name="category" value="<?= htmlspecialchars($categoryFilter) ?>"><?php endif; ?>
    <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search by name..." class="flex-1 p-3 bg-slate-700 border border-slate-600 rounded-md focus:ring-2 focus:ring-accent-400 focus:outline-none">
    <?php if (!empty($allCollections)): ?>
      <select name="collection" onchange="this.form.submit()" class="w-48 p-3 bg-slate-700 border border-slate-600 rounded-md focus:ring-2 focus:ring-accent-400 focus:outline-none">
        <option value="0">All collections</option>
        <?php
          $byParent = [];
          foreach ($allCollections as $c) { $byParent[$c['parentId']][] = $c; }
          function render_col_opts($parentId, $byParent, $selected, $depth = 0) {
            foreach ($byParent[$parentId] ?? [] as $c) {
              $prefix = str_repeat('— ', $depth);
              $sel = $selected === $c['id'] ? 'selected' : '';
              echo "<option value=\"{$c['id']}\" $sel>" . htmlspecialchars($prefix . $c['name']) . "</option>";
              render_col_opts($c['id'], $byParent, $selected, $depth + 1);
            }
          }
          render_col_opts(null, $byParent, $collectionFilter);
        ?>
      </select>
    <?php endif; ?>
  </form>
  <div class="space-y-8">
    <?php
    $colors = ['Recurring' => 'text-sky-400', 'Flexible' => 'text-violet-400', 'OneOff' => 'text-amber-400'];
    foreach ($groups as $type => $groupTasks):
      if (empty($groupTasks)) continue;
      $detailFn = function($t) {
        if ($t['type'] === 'Flexible') return 'Always available, de-prioritized when completed.';
        if ($t['type'] === 'OneOff') {
          if ($t['deadline']) {
            $dl = strtotime($t['deadline']);
            $overdue = $dl < time() && !is_today($t['deadline']) ? ' (Overdue)' : '';
            $hasTime = date('H:i', $dl) !== '00:00';
            $fmt = $hasTime ? 'M j, Y g:i A' : 'M j, Y';
            return 'Deadline: ' . date($fmt, $dl) . $overdue;
          }
          return 'No deadline';
        }
        if ($t['type'] === 'Recurring') {
          if (!$t['recurrence']) return 'Recurring (needs update)';
          $r = $t['recurrence'];
          $s = $r['interval'] > 1 ? 's' : '';
          $dayStr = '';
          if (!empty($r['daysOfWeek'])) {
            $names = array_map(fn($d) => ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'][$d], $r['daysOfWeek']);
            $dayStr = implode(', ', $names);
          }
          $detail = '';
          switch ($r['frequency']) {
            case 'Days': $detail = "Recurs every {$r['interval']} day$s"; break;
            case 'Months': $detail = "Recurs every {$r['interval']} month$s"; break;
            case 'Weeks': $detail = "Recurs every {$r['interval']} week$s on $dayStr"; break;
            case 'SpecificDays': $detail = "Recurs on: $dayStr"; break;
          }
          if ($t['lastCompleted'] && is_today($t['lastCompleted'])) return $detail . ' — Completed today.';
          $next = next_availability_date($t);
          if (!$next) return $detail . ' — No upcoming date.';
          if ($next <= date('Y-m-d')) return $detail . ' — Available today.';
          return $detail . ' — Next on: ' . date('D, M j', strtotime($next));
        }
        return '';
      };
    ?>
      <div>
        <h2 class="text-2xl font-semibold <?= $colors[$type] ?> mb-4 pb-2 border-b-2 border-slate-700"><?= $type === 'OneOff' ? 'One-off' : $type ?></h2>
        <div class="bg-slate-800 rounded-lg shadow-lg overflow-hidden border border-slate-700">
          <ul class="divide-y divide-slate-700">
            <?php foreach ($groupTasks as $t):
              $color = CAT_COLORS[$t['category']] ?? 'bg-gray-500';
              $text = CAT_TEXT[$t['category']] ?? 'text-gray-400';
            ?>
              <li class="p-4 flex items-center justify-between hover:bg-slate-700/50 transition-colors">
                <div class="flex items-start gap-4 flex-1 min-w-0">
                  <span class="<?= $color ?> w-3 h-3 rounded-full flex-shrink-0 mt-1.5"></span>
                  <div class="min-w-0">
                    <p class="font-bold text-slate-100">
                      <?= htmlspecialchars($t['name']) ?>
                      <?php if (!empty($t['pinned'])): ?>
                        <span class="inline-block ml-2 text-amber-400 align-middle" title="Pinned"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 inline"><path stroke-linecap="round" stroke-linejoin="round" d="M16 12v4.5M9.75 8.25 12 21l2.25-12.75M9.75 8.25 6 4.5h12l-3.75 3.75M9.75 8.25h4.5"/></svg></span>
                      <?php endif; ?>
                      <?php if (is_snoozed($t)): $until = strtotime($t['lastSkipped']); ?>
                        <span class="inline-block ml-2 px-2 py-0.5 text-xs font-semibold bg-slate-600 text-slate-200 rounded-full align-middle">Snoozed until <?= date('M j, H:i', $until) ?></span>
                      <?php endif; ?>
                    </p>
                    <p class="text-sm <?= $text ?>"><?= htmlspecialchars($t['category']) ?> - <?= $t['duration'] ?> min</p>
                    <?php if ($t['collectionId'] && isset($colById[$t['collectionId']])):
                      $path = collection_path($user['id'], $t['collectionId']);
                      $pathStr = implode(' › ', array_map(fn($c) => $c['name'], $path));
                    ?>
                      <p class="text-xs text-slate-500 mt-0.5"><?= htmlspecialchars($pathStr) ?></p>
                    <?php endif; ?>
                    <p class="text-sm text-slate-400"><?= $detailFn($t) ?></p>
                    <?php if ($t['note']): ?><p class="text-sm text-slate-400 italic mt-1">Note: <?= htmlspecialchars($t['note']) ?></p><?php endif; ?>
                  </div>
                </div>
                <div class="flex items-center gap-1 flex-shrink-0 ml-4">
                  <?php if (is_snoozed($t)): ?>
                    <form method="POST" action="action.php">
                      <input type="hidden" name="action" value="unsnooze">
                      <input type="hidden" name="task_id" value="<?= $t['id'] ?>">
                      <input type="hidden" name="from" value="browse.php">
                      <button type="submit" class="p-2 rounded-full text-slate-400 hover:bg-amber-500 hover:text-white transition-colors" title="Unsnooze">Wake</button>
                    </form>
                  <?php endif; ?>
                  <a href="edit_task.php?id=<?= $t['id'] ?>" class="p-2 rounded-full text-slate-400 hover:bg-accent-500 hover:text-slate-950 transition-colors">Edit</a>
                  <form method="POST" action="action.php" onsubmit="return confirm('Delete this task?')">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="task_id" value="<?= $t['id'] ?>">
                    <button type="submit" class="p-2 rounded-full text-slate-400 hover:bg-rose-500 hover:text-white transition-colors">Del</button>
                  </form>
                  <form method="POST" action="action.php">
                    <input type="hidden" name="action" value="complete">
                    <input type="hidden" name="task_id" value="<?= $t['id'] ?>">
                    <button type="submit" class="p-2 rounded-full text-slate-400 hover:bg-emerald-500 hover:text-white transition-colors">Done</button>
                  </form>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>
      </div>
    <?php endforeach; ?>
    <?php if (empty($tasks)): ?>
      <div class="text-center p-8 bg-slate-800 rounded-lg border border-slate-700">
        <p class="text-slate-400">No tasks available. Add one to get started!</p>
      </div>
    <?php endif; ?>
  </div>
</main>
</body>
</html>