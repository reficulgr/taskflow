<?php
require_once __DIR__ . '/functions.php';
$user = require_login();
$collections = get_collections($user['id']);
$editCol = null;
if (isset($_GET['edit'])) {
  $editCol = get_collection($user['id'], (int)$_GET['edit']);
  if (!$editCol) redirect('collections.php');
}

$tasks = get_tasks($user['id']);
$completed = get_completed($user['id']);
$taskCounts = [];
foreach ($tasks as $t) { if ($t['collectionId'] !== null) $taskCounts[$t['collectionId']] = ($taskCounts[$t['collectionId']] ?? 0) + 1; }
$completedCounts = [];
foreach ($completed as $c) { if ($c['collectionId'] !== null) $completedCounts[$c['collectionId']] = ($completedCounts[$c['collectionId']] ?? 0) + 1; }

// ====== Selected collection view ======
$viewId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$viewCol = null;
if ($viewId) {
  $viewCol = get_collection($user['id'], $viewId);
  if (!$viewCol) $viewId = 0;
}
$subtreeIds = $viewCol ? get_descendant_collection_ids($user['id'], $viewCol['id']) : [];

// ====== Time filtering (same semantics as report.php) ======
$range = trim($_GET['range'] ?? 'all');
if (!in_array($range, ['all', 'today', '7d', '30d', 'month', 'year', 'custom'], true)) $range = 'all';
$hbFrom = trim($_GET['from'] ?? '');
$hbTo = trim($_GET['to'] ?? '');
if ($range === 'custom') {
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $hbFrom)) $hbFrom = '';
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $hbTo)) $hbTo = '';
  if ($hbFrom === '' && $hbTo === '') $range = 'all';
} else {
  $hbFrom = ''; $hbTo = '';
}
$rangeFrom = null; $rangeTo = null;
switch ($range) {
  case 'today': $rangeFrom = date('Y-m-d'); break;
  case '7d':    $rangeFrom = date('Y-m-d', strtotime('-6 days')); break;
  case '30d':   $rangeFrom = date('Y-m-d', strtotime('-29 days')); break;
  case 'month': $rangeFrom = date('Y-m-01'); break;
  case 'year':  $rangeFrom = date('Y-01-01'); break;
  case 'custom':
    $rangeFrom = $hbFrom !== '' ? $hbFrom : null;
    $rangeTo = $hbTo !== '' ? $hbTo : null;
    break;
}
$rangeLabel = 'All time';
if ($range === 'today') $rangeLabel = 'Today';
elseif ($range === '7d') $rangeLabel = 'Last 7 days';
elseif ($range === '30d') $rangeLabel = 'Last 30 days';
elseif ($range === 'month') $rangeLabel = 'This month';
elseif ($range === 'year') $rangeLabel = 'This year';
elseif ($range === 'custom') {
  $parts = [];
  if ($rangeFrom) $parts[] = 'from ' . date('M j, Y', strtotime($rangeFrom));
  if ($rangeTo) $parts[] = 'to ' . date('M j, Y', strtotime($rangeTo));
  $rangeLabel = ucfirst(implode(' ', $parts));
}

// Committed time: actual elapsed minutes preferred (mirrors budget accounting).
$minutesOf = function (array $c): int {
  if (!empty($c['startAt']) && !empty($c['endAt'])) {
    $diff = (int)round((strtotime($c['endAt']) - strtotime($c['startAt'])) / 60);
    if ($diff > 0) return $diff;
  }
  return (int)$c['duration'];
};

$today = date('Y-m-d');
$subtreeCompleted = $viewCol ? array_values(array_filter($completed, fn($c) => $c['collectionId'] !== null && in_array($c['collectionId'], $subtreeIds, true))) : [];
$viewCompleted = array_values(array_filter($subtreeCompleted, function ($c) use ($rangeFrom, $rangeTo) {
  $d = date('Y-m-d', strtotime($c['completedAt']));
  if ($rangeFrom !== null && $d < $rangeFrom) return false;
  if ($rangeTo !== null && $d > $rangeTo) return false;
  return true;
}));

// ====== Heartbeat window + buckets (day buckets; weekly for long windows) ======
$winStart = $today; $winEnd = $today;
if ($viewCol) {
  switch ($range) {
    case 'today': break;
    case '7d':  $winStart = date('Y-m-d', strtotime('-6 days')); break;
    case '30d': $winStart = date('Y-m-d', strtotime('-29 days')); break;
    case 'month': $winStart = date('Y-m-01'); break;
    case 'year': $winStart = date('Y-01-01'); break;
    case 'custom':
      if ($rangeFrom !== null) $winStart = $rangeFrom;
      if ($rangeTo !== null) $winEnd = $rangeTo;
      if ($winEnd > $today) $winEnd = $today;
      if ($winStart > $winEnd) $winStart = $winEnd;
      break;
    case 'all':
      foreach ($subtreeCompleted as $c) {
        $d = date('Y-m-d', strtotime($c['completedAt']));
        if ($d < $winStart) $winStart = $d;
      }
      break;
  }
}
$spanDays = $viewCol ? (intdiv(strtotime($winEnd) - strtotime($winStart), 86400) + 1) : 0;
$granularity = $spanDays > 120 ? 'week' : 'day';

function week_start_of(string $d): string {
  $t = strtotime($d);
  return date('Y-m-d', $t - ((int)date('w', $t)) * 86400);
}
function bucket_key_of(string $d, string $gran): string {
  return $gran === 'week' ? week_start_of($d) : $d;
}

$buckets = [];
if ($viewCol && $spanDays > 0) {
  if ($granularity === 'week') {
    $cur = week_start_of($winStart);
    while ($cur <= $winEnd) {
      $buckets[$cur] = ['start' => $cur, 'total' => 0, 'cats' => []];
      $cur = date('Y-m-d', strtotime($cur . ' +7 days'));
    }
  } else {
    $cur = $winStart;
    while ($cur <= $winEnd) {
      $buckets[$cur] = ['start' => $cur, 'total' => 0, 'cats' => []];
      $cur = date('Y-m-d', strtotime($cur . ' +1 day'));
    }
  }
  foreach ($viewCompleted as $c) {
    $k = bucket_key_of(date('Y-m-d', strtotime($c['completedAt'])), $granularity);
    if (!isset($buckets[$k])) continue;
    $m = $minutesOf($c);
    $buckets[$k]['total'] += $m;
    $buckets[$k]['cats'][$c['category']] = ($buckets[$k]['cats'][$c['category']] ?? 0) + $m;
  }
}
$bucketList = array_values($buckets);
$maxBucket = 0;
foreach ($bucketList as $b) { if ($b['total'] > $maxBucket) $maxBucket = $b['total']; }

$statTotal = 0; $activeBuckets = 0;
foreach ($bucketList as $b) { $statTotal += $b['total']; if ($b['total'] > 0) $activeBuckets++; }
$statAvg = $activeBuckets > 0 ? (int)round($statTotal / $activeBuckets) : 0;
$lastDate = null;
foreach ($viewCompleted as $c) {
  $d = date('Y-m-d', strtotime($c['completedAt']));
  if ($lastDate === null || $d > $lastDate) $lastDate = $d;
}
$activeUnit = $granularity === 'week' ? 'weeks' : 'days';

// ====== Line-graph scale, x labels, per-task pebbles ======
$yMax = $maxBucket;
$yTicks = []; $yTop = 0;
if ($yMax > 0) {
  $niceSteps = [15, 30, 45, 60, 90, 120, 180, 240, 300, 360, 480, 600, 720, 900, 1200, 1440];
  $step = 0;
  foreach ($niceSteps as $opt) { if ($opt >= $yMax / 4) { $step = $opt; break; } }
  if ($step === 0) $step = 1440 * (int)ceil($yMax / 4 / 1440);
  $yTop = (int)ceil($yMax / $step) * $step;
  for ($v = $step; $v <= $yTop; $v += $step) $yTicks[] = $v;
}
$svgPts = ''; $svgArea = '';
if ($bucketList) {
  $n = count($bucketList);
  $pts = [];
  if ($n === 1) {
    $y = $yTop > 0 ? 400 - $bucketList[0]['total'] / $yTop * 400 : 400;
    $pts = ['0,' . round($y, 2), '1000,' . round($y, 2)];
  } else {
    foreach ($bucketList as $i => $b) {
      $x = $i / ($n - 1) * 1000;
      $y = $yTop > 0 ? 400 - $b['total'] / $yTop * 400 : 400;
      $pts[] = round($x, 2) . ',' . round($y, 2);
    }
  }
  $svgPts = implode(' ', $pts);
  $svgArea = '0,400 ' . $svgPts . ' 1000,400';
}
$xLabels = [];
if ($bucketList) {
  $n = count($bucketList);
  for ($k = 0; $k < 5; $k++) {
    $i = (int)round($k * ($n - 1) / 4);
    if (!isset($xLabels[$i])) $xLabels[$i] = date('M j', strtotime($bucketList[$i]['start']));
  }
}

// Task rollup for the pebble list
$taskAgg = [];
foreach ($viewCompleted as $c) {
  $k = $c['taskId'] !== null ? 't' . $c['taskId'] : 'c' . $c['id'];
  if (!isset($taskAgg[$k])) $taskAgg[$k] = ['name' => $c['taskName'], 'category' => $c['category'], 'minutes' => 0];
  $taskAgg[$k]['minutes'] += $minutesOf($c);
}
usort($taskAgg, fn($a, $b) => $b['minutes'] <=> $a['minutes']);
$pebbleInc = get_pebble_increment($user['id']);

$byParent = [];
foreach ($collections as $c) { $byParent[$c['parentId']][] = $c; }
function render_tree($parentId, $byParent, $depth = 0) {
  global $taskCounts, $completedCounts, $viewId;
  $children = $byParent[$parentId] ?? [];
  foreach ($children as $c):
    $typeColor = $c['type'] === 'Initiative' ? 'text-sky-400' : 'text-violet-400';
    $typeBg = $c['type'] === 'Initiative' ? 'bg-sky-600' : 'bg-violet-600';
  ?>
    <div class="collection-row group<?= $c['id'] === $viewId ? ' active-row' : '' ?>" draggable="true" data-id="<?= $c['id'] ?>">
      <div class="flex items-center justify-between p-3 rounded-md hover:bg-slate-700/50 transition-colors" style="margin-left: <?= $depth * 24 ?>px">
        <div class="flex items-center gap-3 min-w-0">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 text-slate-500 cursor-grab flex-shrink-0 drag-handle">
            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 9h16.5m-16.5 6.75h16.5"/>
          </svg>
          <span class="<?= $typeBg ?> text-white text-xs font-bold px-2 py-0.5 rounded-full flex-shrink-0"><?= $c['type'] === 'Initiative' ? 'I' : 'P' ?></span>
          <div class="min-w-0">
            <div class="flex items-center gap-2">
              <p class="font-bold text-slate-100 truncate"><?= htmlspecialchars($c['name']) ?></p>
              <?php
                $tc = $taskCounts[$c['id']] ?? 0;
                $cc = $completedCounts[$c['id']] ?? 0;
                if ($tc || $cc):
              ?>
                <span class="flex items-center gap-1.5 text-xs text-slate-400 flex-shrink-0">
                  <?php if ($tc): ?><span class="bg-slate-600 px-1.5 rounded-full"><?= $tc ?> active</span><?php endif; ?>
                  <?php if ($cc): ?><span class="bg-emerald-700 px-1.5 rounded-full"><?= $cc ?> done</span><?php endif; ?>
                </span>
              <?php endif; ?>
            </div>
            <?php if ($c['description']): ?><p class="text-xs text-slate-400 truncate"><?= htmlspecialchars($c['description']) ?></p><?php endif; ?>
          </div>
        </div>
        <div class="flex items-center gap-1 flex-shrink-0 opacity-0 group-hover:opacity-100 transition-opacity">
          <a href="collections.php?edit=<?= $c['id'] ?>" class="p-1.5 rounded-full text-slate-400 hover:bg-accent-500 hover:text-slate-950 transition-colors" title="Edit">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Z"/></svg>
          </a>
          <form method="POST" action="action.php" onsubmit="return confirm('Delete this collection? Its sub-collections will be deleted and tasks will be unassigned.')">
            <input type="hidden" name="action" value="delete_collection">
            <input type="hidden" name="id" value="<?= $c['id'] ?>">
            <button type="submit" class="p-1.5 rounded-full text-slate-400 hover:bg-rose-500 hover:text-white transition-colors" title="Delete">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0"/></svg>
            </button>
          </form>
        </div>
      </div>
    </div>
    <?php render_tree($c['id'], $byParent, $depth + 1);
  endforeach;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>TaskFlow — Collections</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <?php render_fonts(); ?>
  <style>
    .collection-row.dragging { opacity: 0.4; }
    .collection-row.drag-over > div { background: rgba(201, 164, 74, 0.2); outline: 2px dashed #c9a44a; outline-offset: -2px; }
    .collection-row { cursor: pointer; }
    .collection-row.active-row > div { background: rgba(201, 164, 74, 0.12); box-shadow: inset 0 0 0 1px rgba(201, 164, 74, 0.45); }
    .drop-zone { border: 2px dashed #575753; transition: all 0.2s; }
    .drop-zone.drag-over { border-color: #c9a44a; background: rgba(201, 164, 74, 0.1); }
  </style>
</head>
<body class="bg-slate-900 text-slate-100 font-sans min-h-screen">
<?php render_header('collections', $user); ?>
<main class="flex flex-col md:flex-row md:items-start">
  <aside class="w-full md:w-96 flex-shrink-0 md:sticky md:top-16 md:flex md:flex-col md:h-[calc(100vh-4rem)] md:border-r md:border-slate-700">

    <div class="p-4 md:p-6 pb-4 flex-shrink-0">
      <h2 class="text-xl font-semibold text-slate-300 mb-4"><?= $editCol ? 'Edit' : 'Add New' ?></h2>
      <form method="POST" action="action.php" class="space-y-4 bg-slate-800 p-4 rounded-lg border border-slate-700">
        <input type="hidden" name="action" value="save_collection">
        <input type="hidden" name="collection_id" value="<?= $editCol['id'] ?? '' ?>">
        <div>
          <label class="block text-sm font-medium text-slate-300 mb-1">Type</label>
          <div class="flex gap-2" id="type-toggle">
            <label class="col-type-btn flex-1 text-center p-2 rounded-md cursor-pointer transition-colors border <?= (!$editCol || $editCol['type'] === 'Initiative') ? 'bg-sky-600 text-white border-sky-600' : 'bg-slate-700 text-slate-300 border-slate-600' ?>" data-type="Initiative">
              <input type="radio" name="type" value="Initiative" <?= (!$editCol || $editCol['type'] === 'Initiative') ? 'checked' : '' ?> class="hidden">Initiative
            </label>
            <label class="col-type-btn flex-1 text-center p-2 rounded-md cursor-pointer transition-colors border <?= ($editCol && $editCol['type'] === 'Project') ? 'bg-violet-600 text-white border-violet-600' : 'bg-slate-700 text-slate-300 border-slate-600' ?>" data-type="Project">
              <input type="radio" name="type" value="Project" <?= ($editCol && $editCol['type'] === 'Project') ? 'checked' : '' ?> class="hidden">Project
            </label>
          </div>
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-300 mb-1">Name</label>
          <input type="text" name="name" required value="<?= htmlspecialchars($editCol['name'] ?? '') ?>" class="w-full p-2 bg-slate-700 border border-slate-600 rounded-md focus:ring-2 focus:ring-accent-400 focus:outline-none">
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-300 mb-1">Description (Optional)</label>
          <textarea name="description" rows="2" class="w-full p-2 bg-slate-700 border border-slate-600 rounded-md focus:ring-2 focus:ring-accent-400 focus:outline-none"><?= htmlspecialchars($editCol['description'] ?? '') ?></textarea>
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-300 mb-1">Parent (Optional)</label>
          <select name="parent_id" class="w-full p-2 bg-slate-700 border border-slate-600 rounded-md focus:ring-2 focus:ring-accent-400 focus:outline-none">
            <option value="">— Top level —</option>
            <?php
              function render_parent_opts($parentId, $byParent, $excludeId, $selected, $depth = 0) {
                foreach ($byParent[$parentId] ?? [] as $c) {
                  if ($excludeId && $c['id'] === $excludeId) continue;
                  $prefix = str_repeat('— ', $depth);
                  $sel = $selected === $c['id'] ? 'selected' : '';
                  echo "<option value=\"{$c['id']}\" $sel>" . htmlspecialchars($prefix . $c['name'] . ' (' . $c['type'] . ')') . "</option>";
                  render_parent_opts($c['id'], $byParent, $excludeId, $selected, $depth + 1);
                }
              }
              render_parent_opts(null, $byParent, $editCol['id'] ?? 0, $editCol['parentId'] ?? null);
            ?>
          </select>
        </div>
        <div class="flex justify-end gap-4 pt-2">
          <?php if ($editCol): ?><a href="collections.php" class="px-4 py-2 bg-slate-600 hover:bg-slate-500 rounded-md font-semibold">Cancel</a><?php endif; ?>
          <button type="submit" class="px-6 py-2 bg-accent-400 hover:bg-accent-300 text-slate-950 rounded-md font-bold"><?= $editCol ? 'Save Changes' : 'Add' ?></button>
        </div>
      </form>
    </div>

    <div class="p-4 md:p-6 pt-4 md:flex-1 md:min-h-0 md:overflow-y-auto">
      <h2 class="text-xl font-semibold text-slate-300 mb-4">Tree</h2>
      <?php if (empty($collections)): ?>
        <div class="text-center p-8 bg-slate-800 rounded-lg border border-slate-700">
          <p class="text-slate-400">No collections yet. Create an initiative to get started.</p>
        </div>
      <?php else: ?>
        <div id="tree" class="space-y-1">
          <?php render_tree(null, $byParent); ?>
        </div>
        <div id="root-drop" class="drop-zone mt-3 p-3 text-center text-sm text-slate-500 rounded-md" data-id="">
          Drop here to move to top level
        </div>
      <?php endif; ?>
      <p class="text-xs text-slate-500 mt-3">Drag any row onto another to re-parent it. Drop on the zone above to make it top-level.</p>
    </div>

  </aside>

  <section class="flex-1 min-w-0 p-4 md:p-6">
    <?php if (!$viewCol): ?>
      <h1 class="text-3xl font-bold text-slate-100">Collections</h1>
      <div class="mt-8 max-w-md p-10 text-center bg-slate-800 rounded-lg border border-dashed border-slate-700">
        <p class="text-slate-400 font-semibold">Pick a collection from the sidebar</p>
        <p class="text-xs text-slate-500 mt-2">Click any Initiative or Project to open its heartbeat — the daily pulse of committed time.</p>
      </div>
    <?php else: ?>
      <div class="mb-4">
        <div class="flex items-center gap-2 mb-1">
          <span class="<?= $viewCol['type'] === 'Initiative' ? 'bg-sky-600' : 'bg-violet-600' ?> text-white text-xs font-bold px-2 py-0.5 rounded-full flex-shrink-0"><?= $viewCol['type'] ?></span>
          <h1 class="text-3xl font-bold text-slate-100 truncate"><?= htmlspecialchars($viewCol['name']) ?></h1>
        </div>
        <?php if ($viewCol['description']): ?><p class="text-sm text-slate-400 mt-1"><?= htmlspecialchars($viewCol['description']) ?></p><?php endif; ?>
      </div>

      <div class="bg-slate-800 rounded-lg border border-slate-700 p-4 mb-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <p class="text-sm text-slate-300">Showing: <span class="text-accent-400 font-semibold"><?= htmlspecialchars($rangeLabel) ?></span></p>
          <div class="flex flex-wrap gap-1.5">
            <?php
              $presets = ['all' => 'All', 'today' => 'Today', '7d' => '7d', '30d' => '30d', 'month' => 'Month', 'year' => 'Year'];
              foreach ($presets as $key => $label):
                $qs = $key === 'all' ? ['id' => $viewCol['id']] : ['id' => $viewCol['id'], 'range' => $key];
                $active = $range === $key ? 'bg-accent-500/90 text-slate-950' : 'bg-slate-700 text-slate-300 hover:bg-slate-600'; ?>
                <a href="collections.php?<?= http_build_query($qs) ?>" class="px-2.5 py-1 rounded-full text-xs font-medium transition-colors <?= $active ?>"><?= $label ?></a>
            <?php endforeach; ?>
          </div>
        </div>
        <form method="GET" action="collections.php" class="flex flex-wrap items-end gap-2 border-t border-slate-700 pt-3 mt-3">
          <input type="hidden" name="id" value="<?= $viewCol['id'] ?>">
          <input type="hidden" name="range" value="custom">
          <input type="date" name="from" value="<?= htmlspecialchars($hbFrom) ?>" class="p-2 bg-slate-700 border border-slate-600 rounded-md text-sm text-slate-200 focus:ring-2 focus:ring-accent-400 focus:outline-none">
          <input type="date" name="to" value="<?= htmlspecialchars($hbTo) ?>" class="p-2 bg-slate-700 border border-slate-600 rounded-md text-sm text-slate-200 focus:ring-2 focus:ring-accent-400 focus:outline-none">
          <button type="submit" class="bg-accent-400 hover:bg-accent-300 text-slate-950 text-sm font-bold px-4 py-2 rounded transition-colors">Apply</button>
          <?php if ($range !== 'all'): ?><a href="collections.php?id=<?= $viewCol['id'] ?>" class="text-xs text-slate-400 hover:text-white py-2 px-2">Clear filter</a><?php endif; ?>
        </form>
      </div>

      <div class="bg-slate-800 rounded-lg border border-slate-700 p-4">
        <div class="flex items-center justify-between mb-3">
          <h2 class="text-lg font-bold text-slate-200">Heartbeat</h2>
          <span class="text-xs text-slate-500"><?= $granularity === 'week' ? 'weekly buckets' : 'per day' ?> · <?= htmlspecialchars($rangeLabel) ?></span>
        </div>
        <div class="flex">
          <div class="relative flex-shrink-0" style="width: 4rem; height: 11rem;">
            <?php foreach ($yTicks as $v): ?>
              <span class="absolute right-2 text-[10px] text-slate-500 font-mono -translate-y-1/2" style="top: <?= round((1 - $v / $yTop) * 100, 2) ?>%"><?= format_minutes($v) ?></span>
            <?php endforeach; ?>
            <span class="absolute right-2 bottom-0 text-[10px] text-slate-500 font-mono">0</span>
          </div>
          <div class="flex-1 min-w-0">
            <div class="relative" style="height: 11rem;">
              <svg class="absolute inset-0 w-full h-full" viewBox="0 0 1000 400" preserveAspectRatio="none">
                <?php foreach ($yTicks as $v): ?>
                  <line x1="0" y1="<?= round(400 - $v / $yTop * 400, 2) ?>" x2="1000" y2="<?= round(400 - $v / $yTop * 400, 2) ?>" stroke="#282828" stroke-width="1" vector-effect="non-scaling-stroke" />
                <?php endforeach; ?>
                <line x1="0" y1="399" x2="1000" y2="399" stroke="#3a3a38" stroke-width="1" vector-effect="non-scaling-stroke" />
                <?php if ($svgArea): ?><polygon points="<?= $svgArea ?>" fill="rgba(201, 164, 74, 0.10)" /><?php endif; ?>
                <?php if ($svgPts): ?><polyline points="<?= $svgPts ?>" fill="none" stroke="#d9b861" stroke-width="2" vector-effect="non-scaling-stroke" /><?php endif; ?>
              </svg>
            </div>
            <div class="flex justify-between text-[10px] text-slate-500 font-mono mt-1.5">
              <?php foreach ($xLabels as $d): ?><span><?= $d ?></span><?php endforeach; ?>
            </div>
          </div>
        </div>
        <?php if ($maxBucket === 0): ?>
          <p class="text-xs text-slate-500 text-center mt-3">Flatline — nothing logged in <?= htmlspecialchars($rangeLabel) ?>.</p>
        <?php endif; ?>
      </div>

      <div class="bg-slate-800 rounded-lg border border-slate-700 p-4 mt-4">
        <div class="flex items-center justify-between mb-2">
          <h2 class="text-lg font-bold text-slate-200">Committed tasks</h2>
          <span class="text-xs text-slate-500"><?= count($taskAgg) ?> tasks · <?= htmlspecialchars($rangeLabel) ?></span>
        </div>
        <?php if (empty($taskAgg)): ?>
          <p class="text-sm text-slate-500">Nothing logged in <?= htmlspecialchars($rangeLabel) ?>.</p>
        <?php else: ?>
          <div class="divide-y divide-slate-700/60">
            <?php foreach ($taskAgg as $t):
              $cls = CAT_COLORS[$t['category']] ?? 'bg-gray-500';
              $txt = CAT_TEXT[$t['category']] ?? 'text-gray-400';
              $full = intdiv($t['minutes'], $pebbleInc);
              $rem = $t['minutes'] % $pebbleInc; ?>
              <div class="py-2.5">
                <div class="flex items-center justify-between gap-2">
                  <div class="min-w-0 flex items-center gap-2">
                    <span class="<?= $cls ?> w-2 h-2 rounded-full flex-shrink-0"></span>
                    <p class="text-sm font-semibold <?= $txt ?> truncate"><?= htmlspecialchars($t['name']) ?></p>
                  </div>
                  <span class="text-xs font-mono text-slate-400 flex-shrink-0"><?= format_minutes($t['minutes']) ?></span>
                </div>
                <div class="flex flex-wrap items-center gap-[3px] mt-1.5 ml-4">
                  <?php for ($pi = 0; $pi < $full; $pi++): ?>
                    <span class="<?= $cls ?> w-2 h-2 rounded-full" title="<?= htmlspecialchars($t['category']) ?> — <?= (int)$pebbleInc ?> min"></span>
                  <?php endfor; ?>
                  <?php if ($rem > 0): $pw = max(3, (int)round(8 * $rem / $pebbleInc)); ?>
                    <span class="<?= $cls ?> rounded-full opacity-60" style="width: <?= $pw ?>px; height: 8px" title="<?= htmlspecialchars($t['category']) ?> — <?= $rem ?> min (partial)"></span>
                  <?php endif; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mt-4">
        <div class="bg-slate-800 rounded-lg border border-slate-700 p-3">
          <p class="text-xs text-slate-500">Total logged</p>
          <p class="text-xl font-bold text-slate-100 font-mono"><?= format_minutes($statTotal) ?></p>
        </div>
        <div class="bg-slate-800 rounded-lg border border-slate-700 p-3">
          <p class="text-xs text-slate-500">Active <?= ucfirst($activeUnit) ?></p>
          <p class="text-xl font-bold text-slate-100 font-mono"><?= $activeBuckets ?></p>
        </div>
        <div class="bg-slate-800 rounded-lg border border-slate-700 p-3">
          <p class="text-xs text-slate-500">Avg per active</p>
          <p class="text-xl font-bold text-slate-100 font-mono"><?= format_minutes($statAvg) ?></p>
        </div>
        <div class="bg-slate-800 rounded-lg border border-slate-700 p-3">
          <p class="text-xs text-slate-500">Last activity</p>
          <p class="text-xl font-bold text-slate-100 font-mono"><?= $lastDate ? date('M j, Y', strtotime($lastDate)) : '—' ?></p>
        </div>
      </div>
    <?php endif; ?>
  </section>
</main>
<script>
const rows = document.querySelectorAll('.collection-row');
let dragId = null;
let justDragged = 0;

rows.forEach(row => {
  row.addEventListener('dragstart', (e) => {
    dragId = row.dataset.id;
    row.classList.add('dragging');
    e.dataTransfer.effectAllowed = 'move';
  });
  row.addEventListener('dragend', () => {
    row.classList.remove('dragging');
    document.querySelectorAll('.drag-over').forEach(el => el.classList.remove('drag-over'));
    dragId = null;
    justDragged = Date.now();
  });
  row.addEventListener('dragover', (e) => {
    e.preventDefault();
    if (dragId && dragId !== row.dataset.id) {
      row.classList.add('drag-over');
    }
  });
  row.addEventListener('dragleave', () => {
    row.classList.remove('drag-over');
  });
  row.addEventListener('drop', (e) => {
    e.preventDefault();
    row.classList.remove('drag-over');
    if (dragId && dragId !== row.dataset.id) {
      reparent(dragId, row.dataset.id);
    }
  });
});

rows.forEach(row => {
  row.addEventListener('click', (e) => {
    if (e.target.closest('a, button, input, textarea, select, label')) return;
    if (Date.now() - justDragged < 400) return;
    window.location.href = 'collections.php?id=' + row.dataset.id;
  });
});

const rootDrop = document.getElementById('root-drop');
if (rootDrop) {
  rootDrop.addEventListener('dragover', (e) => {
    e.preventDefault();
    rootDrop.classList.add('drag-over');
  });
  rootDrop.addEventListener('dragleave', () => {
    rootDrop.classList.remove('drag-over');
  });
  rootDrop.addEventListener('drop', (e) => {
    e.preventDefault();
    rootDrop.classList.remove('drag-over');
    if (dragId) {
      reparent(dragId, '');
    }
  });
}

function reparent(id, parentId) {
  const formData = new FormData();
  formData.append('action', 'set_collection_parent');
  formData.append('id', id);
  formData.append('parent_id', parentId);
  fetch('action.php', { method: 'POST', body: formData })
    .then(() => window.location.reload());
}

document.querySelectorAll('.col-type-btn').forEach(btn => {
  btn.addEventListener('click', (e) => {
    e.preventDefault();
    document.querySelectorAll('.col-type-btn').forEach(b => {
      const radio = b.querySelector('input');
      radio.checked = false;
      b.classList.remove('bg-sky-600', 'text-white', 'border-sky-600', 'bg-violet-600', 'border-violet-600');
      b.classList.add('bg-slate-700', 'text-slate-300', 'border-slate-600');
    });
    const radio = btn.querySelector('input');
    radio.checked = true;
    btn.classList.remove('bg-slate-700', 'text-slate-300', 'border-slate-600');
    if (btn.dataset.type === 'Initiative') {
      btn.classList.add('bg-sky-600', 'text-white', 'border-sky-600');
    } else {
      btn.classList.add('bg-violet-600', 'text-white', 'border-violet-600');
    }
  });
});
</script>
</body>
</html>