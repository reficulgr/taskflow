<?php
require_once __DIR__ . '/functions.php';
$user = require_login();
$collections = get_collections($user['id']);
$completed = get_completed($user['id']);
$hasAny = !empty($completed);
$increment = get_pebble_increment($user['id']);

// ====== Time filtering ======
$range = trim($_GET['range'] ?? 'all');
if (!in_array($range, ['all', 'today', '7d', '30d', 'month', 'year', 'custom'], true)) $range = 'all';
$from = trim($_GET['from'] ?? '');
$to = trim($_GET['to'] ?? '');
if ($range === 'custom') {
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = '';
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) $to = '';
  if ($from === '' && $to === '') $range = 'all';
} else {
  $from = ''; $to = '';
}

$rangeFrom = null; $rangeTo = null;
switch ($range) {
  case 'today': $rangeFrom = date('Y-m-d'); break;
  case '7d':    $rangeFrom = date('Y-m-d', strtotime('-6 days')); break;
  case '30d':   $rangeFrom = date('Y-m-d', strtotime('-29 days')); break;
  case 'month': $rangeFrom = date('Y-m-01'); break;
  case 'year':  $rangeFrom = date('Y-01-01'); break;
  case 'custom':
    $rangeFrom = $from !== '' ? $from : null;
    $rangeTo = $to !== '' ? $to : null;
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

// ====== View tab: pebbles | skills ======
$view = trim($_GET['view'] ?? 'pebbles');
if (!in_array($view, ['pebbles', 'skills'], true)) $view = 'pebbles';

// Pebbles view becomes a viewport-filling app shell on desktop (lg+):
// the treemap flexes to absorb the exact remaining height instead of a
// hardcoded calc() guess.
$fit = ($view === 'pebbles' && $hasAny);

// Builds a URL preserving the current view + range state.
function report_url(string $view, string $range, string $from, string $to): string {
  $qs = ['view' => $view === 'pebbles' ? null : $view];
  if ($range !== 'all' && $range !== 'custom') $qs['range'] = $range;
  if ($range === 'custom') { $qs['range'] = 'custom'; if ($from) $qs['from'] = $from; if ($to) $qs['to'] = $to; }
  $qs = array_filter($qs, fn($v) => $v !== null);
  return 'report.php' . ($qs ? '?' . http_build_query($qs) : '');
}

$allCompleted = $completed;
$completed = array_values(array_filter($completed, function($c) use ($rangeFrom, $rangeTo) {
  $d = date('Y-m-d', strtotime($c['completedAt']));
  if ($rangeFrom !== null && $d < $rangeFrom) return false;
  if ($rangeTo !== null && $d > $rangeTo) return false;
  return true;
}));

// ====== Previous equal-length window (skill delta markers) ======
$prevFrom = null; $prevTo = null;
if ($rangeFrom !== null && $rangeTo !== null) {
  $lenDays = intdiv(strtotime($rangeTo) - strtotime($rangeFrom), 86400) + 1;
  $prevTo = date('Y-m-d', strtotime($rangeFrom) - 86400);
  $prevFrom = date('Y-m-d', strtotime($prevTo . ' -' . ($lenDays - 1) . ' days'));
} elseif ($rangeFrom !== null && $range !== 'custom') {
  switch ($range) {
    case 'today': $prevFrom = $prevTo = date('Y-m-d', strtotime('-1 day')); break;
    case '7d':  $prevTo = date('Y-m-d', strtotime($rangeFrom) - 86400); $prevFrom = date('Y-m-d', strtotime($prevTo . ' -6 days')); break;
    case '30d': $prevTo = date('Y-m-d', strtotime($rangeFrom) - 86400); $prevFrom = date('Y-m-d', strtotime($prevTo . ' -29 days')); break;
    case 'month': $prevFrom = date('Y-m-01', strtotime($rangeFrom . ' -1 month')); $prevTo = date('Y-m-t', strtotime($prevFrom)); break;
    case 'year':  $prevFrom = date('Y-01-01', strtotime($rangeFrom . ' -1 year')); $prevTo = date('Y-12-31', strtotime($prevFrom)); break;
  }
}
$prevMinutes = [];
if ($prevFrom !== null) {
  $prevCompleted = array_values(array_filter($allCompleted, function($c) use ($prevFrom, $prevTo) {
    $d = date('Y-m-d', strtotime($c['completedAt']));
    return $d >= $prevFrom && $d <= $prevTo;
  }));
  $prevMinutes = skill_minutes($user['id'], $prevCompleted);
}

// ====== Fixed hour rings for the skill polygons (each ring = double the last; top ≈ 2h/day) ======
$rings = null;
switch ($range) {
  case 'today': $rings = [30, 60, 120, 240]; break;
  case '7d':    $rings = [120, 240, 480, 960]; break;
  case '30d':
  case 'month': $rings = [480, 960, 1920, 3840]; break;
  case 'year':  $rings = [6000, 12000, 24000, 48000]; break;
  case 'custom':
    if ($rangeFrom !== null && $rangeTo !== null) {
      $days = intdiv(strtotime($rangeTo) - strtotime($rangeFrom), 86400) + 1;
    } elseif ($rangeFrom !== null) {
      $days = intdiv(time() - strtotime($rangeFrom), 86400) + 1;
    } else {
      $days = 7;
    }
    $top = (int)max(240, round(120 * $days / 15) * 15);
    $rings = [
      (int)max(15, round($top / 8 / 15) * 15),
      (int)max(15, round($top / 4 / 15) * 15),
      (int)max(15, round($top / 2 / 15) * 15),
      $top,
    ];
    break;
}

// ====== Grouping: initiative (root collection) -> project -> category -> minutes ======
$colById = []; $childrenOf = [];
foreach ($collections as $c) { $colById[$c['id']] = $c; $childrenOf[$c['parentId']][] = $c['id']; }

$rootOf = [];
foreach ($collections as $c) {
  $cur = $c; $guard = 0;
  while ($cur['parentId'] !== null && isset($colById[$cur['parentId']]) && $guard++ < 100) $cur = $colById[$cur['parentId']];
  $rootOf[$c['id']] = $cur['id'];
}

$subgroupOf = [];
foreach ($collections as $c) {
  $root = $rootOf[$c['id']];
  if ($c['id'] === $root) { $subgroupOf[$c['id']] = null; continue; }
  $cur = $c; $guard = 0;
  while ($cur['parentId'] !== $root && isset($colById[$cur['parentId']]) && $guard++ < 100) $cur = $colById[$cur['parentId']];
  $subgroupOf[$c['id']] = $cur['id'];
}

// [rootId | 'misc'][subgroupId | null][category] => minutes
$groupMinutes = [];
foreach ($completed as $c) {
  if ($c['collectionId'] === null || !isset($colById[$c['collectionId']])) { $g = 'misc'; $sg = null; }
  else { $g = $rootOf[$c['collectionId']]; $sg = $subgroupOf[$c['collectionId']]; }
  $groupMinutes[$g][$sg][$c['category']] = ($groupMinutes[$g][$sg][$c['category']] ?? 0) + $c['duration'];
}

// ====== Nested groups for the treemap ======
$groups = [];
foreach ($collections as $c) {
  if ($c['parentId'] !== null || empty($groupMinutes[$c['id']])) continue;
  $sgs = $groupMinutes[$c['id']];
  $self = $sgs[null] ?? [];
  $children = []; $childTotal = 0;
  foreach ($childrenOf[$c['id']] ?? [] as $kidId) {
    if (empty($sgs[$kidId])) continue;
    $minutes = $sgs[$kidId];
    $t = array_sum($minutes);
    $childTotal += $t;
    $children[] = ['id' => 'col-' . $kidId, 'name' => $colById[$kidId]['name'], 'type' => $colById[$kidId]['type'], 'total' => $t, 'minutes' => $minutes];
  }
  usort($children, fn($a, $b) => $b['total'] <=> $a['total']);
  $groups[] = [
    'id' => 'col-' . $c['id'], 'name' => $c['name'], 'type' => $c['type'],
    'self' => $self, 'selfTotal' => array_sum($self),
    'total' => array_sum($self) + $childTotal, 'children' => $children,
  ];
}
if (!empty($groupMinutes['misc'])) {
  $m = $groupMinutes['misc'][null] ?? [];
  $groups[] = ['id' => 'misc', 'name' => 'Misc', 'type' => 'Misc', 'self' => $m, 'selfTotal' => array_sum($m), 'total' => array_sum($m), 'children' => []];
}

$totalAll = 0; $fullPebbles = 0; $partialPebbles = 0;
foreach ($groupMinutes as $sgs) foreach ($sgs as $cats) foreach ($cats as $mins) {
  $totalAll += $mins; $fullPebbles += intdiv($mins, $increment);
  if ($mins % $increment > 0) $partialPebbles++;
}

$palette = [
  'Revenue Today' => ['bg' => '#ef4444', 'fg' => '#f87171'],
  'Revenue Tomorrow' => ['bg' => '#0ea5e9', 'fg' => '#38bdf8'],
  'Exploration' => ['bg' => '#10b981', 'fg' => '#34d399'],
  'Entertainment' => ['bg' => '#8b5cf6', 'fg' => '#a78bfa'],
  'Social-Household' => ['bg' => '#f59e0b', 'fg' => '#fbbf24'],
];
$reportData = ['groups' => $groups, 'categories' => array_values(CATEGORIES), 'palette' => $palette, 'increment' => $increment];

// ====== Skills spider (shares the page's time filter) ======
$skillsData = null;
if (skills_table_exists()) {
  $skillsList = get_skills($user['id']);
  if (!empty($skillsList)) {
    $skillsData = [
      'skills' => $skillsList,
      'minutes' => skill_minutes($user['id'], $completed),
      'prevMinutes' => $prevFrom !== null ? $prevMinutes : null,
      'rings' => $rings,
      'rootColors' => SKILL_ROOT_COLORS,
    ];
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>TaskFlow — Report</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <?php render_fonts(); ?>
  <style>
    ::-webkit-scrollbar { width: 8px; }
    ::-webkit-scrollbar-track { background: #1c1c1b; }
    ::-webkit-scrollbar-thumb { background: #575753; border-radius: 4px; }
    #treemap { position: relative; border: 1px solid #3a3a38; border-radius: 12px; background: #0e0e0d; overflow: hidden; }
    .cell { position: absolute; box-sizing: border-box; border: 1px solid #3a3a38; border-radius: 10px; background: rgba(25,25,24,.9); overflow: hidden;
      transition: left .45s cubic-bezier(.22,1,.36,1), top .45s cubic-bezier(.22,1,.36,1), width .45s cubic-bezier(.22,1,.36,1), height .45s cubic-bezier(.22,1,.36,1); }
    .cell.group { background: rgba(25,25,24,.35); }
    .cell.zoomable { cursor: pointer; }
    .cell.zoomable:hover { border-color: #c9a44a; box-shadow: inset 0 0 0 1px #c9a44a; }
    .cell-head { position: absolute; top: 0; left: 0; right: 0; display: flex; align-items: center; gap: 5px; padding: 2px 7px; pointer-events: none; }
    .badge { color: #fff; font-size: 9px; font-weight: 700; border-radius: 9999px; padding: 1px 5px; flex-shrink: 0; }
    .cname { font-size: 11px; font-weight: 700; color: #e2e2e0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .cell.group > .cell-head .cname { color: #c6c6c2; }
    .ctotal { margin-left: auto; font-size: 10px; color: #9a9a96; font-family: 'IBM Plex Mono', ui-monospace, SFMono-Regular, monospace; flex-shrink: 0; }
    .cell-body { position: absolute; left: 5px; right: 5px; bottom: 5px; display: flex; flex-direction: column; }
    .peb-wrap { flex: 1; display: flex; flex-wrap: wrap; align-items: center; justify-content: center; align-content: center; gap: 2px; min-height: 0; }
    .peb { border-radius: 9999px; flex-shrink: 0; }
    .peb-part { opacity: .6; }
    .peb-foot { text-align: center; font-size: 10px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; line-height: 14px; }
    #crumbs .crumb { cursor: pointer; padding: 2px 6px; border-radius: 6px; color: #9a9a96; }
    #crumbs .crumb:hover { color: #fff; background: #282828; }
    #crumbs .crumb.cur { color: #d9b861; font-weight: 700; }
    #crumbs .sep { color: #575753; }
    #treemap { height: calc(100vh - 280px); min-height: 460px; }
    /* Desktop app shell: pin the viewport, let the treemap absorb remaining height. */
    @media (min-width: 1024px) {
      body.app-fit { height: 100vh; overflow: hidden; display: flex; flex-direction: column; }
      body.app-fit > main { flex: 1; min-height: 0; display: flex; flex-direction: column; overflow: hidden; }
      body.app-fit .fit-row { flex: 1; min-height: 0; display: flex; flex-direction: row; }
      body.app-fit .fit-col { flex: 1 1 auto; min-height: 0; display: flex; flex-direction: column; min-width: 0; }
      body.app-fit #treemap { flex: 1 1 auto; min-height: 280px; height: auto; }
      body.app-fit .fit-empty { display: flex; }
    }
  </style>
</head>
<body class="<?= $fit ? 'app-fit' : '' ?> bg-slate-900 text-slate-100 font-sans min-h-screen">
<?php render_header('report', $user); ?>
<main class="p-4 md:p-6">
  <h1 class="text-3xl font-bold text-slate-100 mb-6">Progress Report</h1>
  <?php if (empty($completed) && $range === 'all' && !$hasAny): ?>
    <div class="text-center p-8 bg-slate-800 rounded-lg border border-slate-700">
      <p class="text-slate-400">No tasks completed yet. Go finish something!</p>
    </div>
  <?php else: ?>
    <div class="flex flex-col lg:flex-row gap-4 <?= $fit ? 'fit-row' : '' ?>">
    <div class="flex-1 min-w-0 <?= $fit ? 'fit-col' : '' ?>">
    <div class="bg-slate-800 rounded-lg border border-slate-700 p-4 mb-3 flex flex-wrap items-center justify-between gap-3">
      <p class="text-sm text-slate-300">
        <span class="font-bold text-slate-100"><?= format_minutes($totalAll) ?></span> logged
        <?php if ($view === 'pebbles'): ?>
          · <span class="font-bold text-slate-100"><?= count($groups) ?></span> groups
          · <span class="font-bold text-slate-100"><?= $fullPebbles ?></span> pebbles<?= $partialPebbles > 0 ? " (+{$partialPebbles} partial)" : '' ?>
        <?php endif; ?>
        <?php if ($range !== 'all'): ?><span class="text-accent-400 font-semibold"> · <?= htmlspecialchars($rangeLabel) ?></span><?php endif; ?>
      </p>
      <div class="flex flex-wrap gap-x-3 gap-y-1">
        <?php foreach (CATEGORIES as $c):
          $text = CAT_TEXT[$c] ?? 'text-gray-400'; ?>
          <span class="flex items-center gap-1.5 text-xs">
            <span class="<?= CAT_COLORS[$c] ?> w-3 h-3 rounded-full inline-block"></span>
            <span class="<?= $text ?>"><?= htmlspecialchars($c) ?></span>
          </span>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="flex items-center gap-1.5 mb-3 border-b border-slate-700 pb-2">
      <?php
        $tabs = ['pebbles' => 'Pebbles', 'skills' => 'Skills'];
        foreach ($tabs as $key => $label):
          $active = $view === $key ? 'bg-accent-500/90 text-slate-950' : 'bg-slate-700 text-slate-300 hover:bg-slate-600'; ?>
          <a href="<?= report_url($key, $range, $from, $to) ?>" class="px-4 py-1.5 rounded-full text-sm font-bold transition-colors <?= $active ?>"><?= $label ?></a>
        <?php endforeach; ?>
    </div>

    <?php if ($view === 'pebbles'): ?>
    <div id="crumbs" class="flex items-center flex-wrap gap-1 text-sm mb-2"></div>
    <?php if (empty($completed)): ?>
      <div id="treemap" class="text-center p-8 bg-slate-800 rounded-lg border border-slate-700 fit-empty" style="align-items:center; justify-content:center;">
        <p class="text-slate-400">Nothing completed in <?= htmlspecialchars($rangeLabel) ?>.</p>
      </div>
    <?php else: ?>
      <div id="treemap"></div>
      <p class="text-xs text-slate-500 mt-2">Click a tile to zoom in · Esc or the breadcrumb to zoom out. Each pebble = <?= (int)$increment ?> minutes of completed time, coloured by category; smaller translucent pebbles are leftovers.</p>
    <?php endif; ?>
    <?php else: ?>
    <div class="bg-slate-800 rounded-lg border border-slate-700 p-4">
      <div class="flex items-center justify-between gap-3 mb-2">
        <h2 class="text-xl font-bold text-slate-200">Skill Balance</h2>
        <a href="skills.php<?= $range !== 'all' ? '?range=' . urlencode($range) . ($range === 'custom' ? '&from=' . urlencode($from) . '&to=' . urlencode($to) : '') : '' ?>" class="text-xs text-accent-400 hover:underline">Manage skills →</a>
      </div>
      <div id="skills-spider-holder"></div>
    </div>
    <?php endif; ?>
    </div>
    <aside class="w-full lg:w-64 flex-shrink-0">
      <div class="bg-slate-800 rounded-lg border border-slate-700 p-4 lg:sticky lg:top-20">
        <h2 class="text-lg font-bold text-slate-200 mb-3">Time Filter</h2>
        <p class="text-xs text-slate-500 mb-3">Showing: <span class="text-accent-400 font-semibold"><?= htmlspecialchars($rangeLabel) ?></span></p>
        <div class="flex flex-wrap gap-1.5 mb-4">
          <?php
            $presets = [
              'all' => 'All', 'today' => 'Today', '7d' => '7d', '30d' => '30d',
              'month' => 'Month', 'year' => 'Year',
            ];
            foreach ($presets as $key => $label):
              $qs = array_filter(['view' => $view === 'pebbles' ? null : $view, 'range' => $key === 'all' ? null : $key]);
              $active = $range === $key ? 'bg-accent-500/90 text-slate-950' : 'bg-slate-700 text-slate-300 hover:bg-slate-600'; ?>
              <a href="report.php<?= $qs ? '?' . http_build_query($qs) : '' ?>" class="px-2.5 py-1 rounded-full text-xs font-medium transition-colors <?= $active ?>"><?= $label ?></a>
            <?php endforeach; ?>
        </div>
        <form method="GET" action="report.php" class="border-t border-slate-700 pt-3 space-y-2">
          <?php if ($view !== 'pebbles'): ?><input type="hidden" name="view" value="<?= $view ?>"><?php endif; ?>
          <input type="hidden" name="range" value="custom">
          <label class="block text-xs font-medium text-slate-400">Custom range</label>
          <input type="date" name="from" value="<?= htmlspecialchars($from) ?>" class="w-full p-2 bg-slate-700 border border-slate-600 rounded-md text-sm text-slate-200 focus:ring-2 focus:ring-accent-400 focus:outline-none">
          <input type="date" name="to" value="<?= htmlspecialchars($to) ?>" class="w-full p-2 bg-slate-700 border border-slate-600 rounded-md text-sm text-slate-200 focus:ring-2 focus:ring-accent-400 focus:outline-none">
          <button type="submit" class="w-full bg-accent-400 hover:bg-accent-300 text-slate-950 text-sm font-bold py-1.5 rounded transition-colors">Apply</button>
          <?php if ($range !== 'all'): ?>
            <a href="report.php<?= $view !== 'pebbles' ? '?view=' . urlencode($view) : '' ?>" class="block text-center text-xs text-slate-400 hover:text-white py-1">Clear filter</a>
          <?php endif; ?>
        </form>
        <div class="border-t border-slate-700 pt-3 mt-3">
          <p class="text-xs text-slate-500">Pebble size: <span class="text-slate-300 font-semibold"><?= (int)$increment ?> min</span> <a href="settings.php" class="text-accent-400 hover:underline">(change)</a></p>
        </div>
      </div>
    </aside>
    </div>
  <?php endif; ?>
</main>
<script>const REPORT = <?= json_encode($reportData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
<script>
(function () {
  const vp = document.getElementById('treemap');
  if (typeof REPORT === 'undefined' || !REPORT.groups.length || !vp) return;
  let zoom = null;
  let resizeT = null;

  const groupById = new Map(), childById = new Map(), parentOf = new Map(), nodeIndex = new Map();
  REPORT.groups.forEach(g => {
    groupById.set(g.id, g); nodeIndex.set(g.id, g); parentOf.set(g.id, null);
    g.children.forEach(ch => { childById.set(ch.id, ch); nodeIndex.set(ch.id, ch); parentOf.set(ch.id, g.id); });
  });
  const crumbs = document.getElementById('crumbs');
  const els = new Map();

  function esc(s) { return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
  function fmt(m) { const h = Math.floor(m / 60), r = m % 60; if (h > 0 && r > 0) return h + 'h ' + r + 'm'; if (h > 0) return h + 'h'; return r + 'm'; }

  // Squarified treemap (Bruls et al.): fill the shorter side with rows chosen to minimise worst aspect ratio.
  function squarify(items, x, y, w, h) {
    const out = [];
    if (!items.length || w <= 0 || h <= 0) return out;
    const total = items.reduce((a, b) => a + b.value, 0);
    if (total <= 0) return out;
    const scale = (w * h) / total;
    let sorted = items.slice().sort((a, b) => b.value - a.value);
    let cx = x, cy = y, cw = w, ch = h;
    let guard = 0;
    while (sorted.length && guard++ < 1000) {
      if (cw < 2 || ch < 2) break;
      const vertical = cw >= ch;
      const side = vertical ? ch : cw;
      const row = [sorted[0]];
      let i = 1;
      const thicknessOf = r => Math.min(r.reduce((a, b) => a + b.value, 0) * scale / side, vertical ? cw : ch);
      const worstOf = r => {
        const th = Math.max(r.reduce((a, b) => a + b.value, 0) * scale / side, 0.0001);
        let worst = 0;
        for (const it of r) {
          const len = it.value * scale / th;
          worst = Math.max(worst, Math.max(len / th, th / len));
        }
        return worst;
      };
      while (i < sorted.length && worstOf(row.concat([sorted[i]])) <= worstOf(row)) row.push(sorted[i++]);
      sorted = sorted.slice(row.length);
      const th = Math.max(thicknessOf(row), 0.0001);
      if (vertical) {
        let ry = cy;
        for (const it of row) {
          const ih = it.value * scale / th;
          out.push({ id: it.id, x: cx, y: ry, w: th, h: ih });
          ry += ih;
        }
        cx += th; cw -= th;
      } else {
        let rx = cx;
        for (const it of row) {
          const iw = it.value * scale / th;
          out.push({ id: it.id, x: rx, y: cy, w: iw, h: th });
          rx += iw;
        }
        cy += th; ch -= th;
      }
    }
    return out;
  }

  function addNode(cells, node, rect, isRoot, z) {
    const hasKids = Array.isArray(node.children) && node.children.length > 0;
    const headH = isRoot ? 30 : (hasKids ? 20 : 16);
    cells.push({ key: node.id, node, rect, kind: hasKids ? 'group' : 'leaf', headH, z,
      minutes: hasKids ? null : (node.minutes || node.self || {}) });
    if (!hasKids) return;
    const ix = rect.x + 2, iy = rect.y + headH + 2, iw = rect.w - 4, ih = rect.h - headH - 4;
    const entries = node.children.map(c => ({ id: c.id, value: c.total }));
    if (node.selfTotal > 0) entries.push({ id: node.id + '/general', value: node.selfTotal });
    for (const r of squarify(entries, ix, iy, iw, ih)) {
      if (r.id.endsWith('/general')) {
        cells.push({ key: r.id, node: { id: r.id, name: 'General', type: 'General', total: node.selfTotal },
          rect: r, kind: 'leaf', headH: 16, z: z + 1, minutes: node.self, pseudo: true });
      } else {
        const ch = childById.get(r.id);
        cells.push({ key: ch.id, node: ch, rect: r, kind: 'leaf', headH: 16, z: z + 1, minutes: ch.minutes });
      }
    }
  }

  function computeCells() {
    const W = vp.clientWidth, H = vp.clientHeight;
    const cells = [];
    if (zoom === null) {
      const rects = squarify(REPORT.groups.map(g => ({ id: g.id, value: g.total })), 0, 0, W, H);
      for (const r of rects) addNode(cells, groupById.get(r.id), r, false, 1);
    } else {
      const g = groupById.get(zoom) || childById.get(zoom);
      if (g) addNode(cells, g, { x: 0, y: 0, w: W, h: H }, true, 1);
    }
    return cells;
  }

  function badgeFor(type) {
    if (type === 'Initiative') return ['I', '#0284c7'];
    if (type === 'Project') return ['P', '#7c3aed'];
    if (type === 'Misc') return ['\u2022', '#7a7a76'];
    return ['G', '#575753'];
  }

  function buildCell(c) {
    const el = document.createElement('div');
    el.className = 'cell' + (c.kind === 'group' ? ' group' : '');
    el.dataset.key = c.key;
    const n = c.node;
    const badge = badgeFor(n.type);
    const head = document.createElement('div');
    head.className = 'cell-head';
    head.innerHTML = '<span class="badge" style="background:' + badge[1] + '">' + badge[0] + '</span>' +
      '<span class="cname">' + esc(n.name) + '</span><span class="ctotal">' + fmt(n.total) + '</span>';
    el.appendChild(head);
    if (c.kind === 'leaf') {
      const body = document.createElement('div');
      body.className = 'cell-body';
      body.style.top = c.headH + 'px';
      el.appendChild(body);
    }
    return el;
  }

  function renderPebbles(body, c) {
    const bw = c.rect.w - 12, bh = c.rect.h - c.headH - 12;
    const minutes = c.minutes || {};
    const inc = REPORT.increment || 30;
    const pebs = [];
    for (const cat of REPORT.categories) {
      const m = minutes[cat] || 0;
      const full = Math.floor(m / inc);
      for (let i = 0; i < full; i++) pebs.push({ cat, full: true });
      const rem = m % inc;
      if (rem > 0) pebs.push({ cat, full: false, rem });
    }
    if (!pebs.length || bw < 10 || bh < 10) {
      if (body.dataset.sig !== 'empty') { body.innerHTML = ''; body.dataset.sig = 'empty'; }
      return;
    }
    const gap = 2;
    let d = Math.sqrt((bw * bh) / pebs.length) - gap;
    d = Math.max(3, Math.min(12, d));
    const sig = [Math.round(d), pebs.length, Math.round(bw), Math.round(bh)].join('|');
    if (body.dataset.sig === sig) return;
    body.dataset.sig = sig;
    const pal = REPORT.palette;
    let html = '<div class="peb-wrap">';
    for (const p of pebs) {
      const col = (pal[p.cat] || {}).bg || '#7a7a76';
      if (p.full) {
        html += '<span class="peb" style="width:' + d + 'px;height:' + d + 'px;background:' + col + '" title="' + esc(p.cat) + ' \u2014 ' + inc + ' min"></span>';
      } else {
        const pw = Math.max(3, Math.round(d * p.rem / inc));
        html += '<span class="peb peb-part" style="width:' + pw + 'px;height:' + d + 'px;background:' + col + '" title="' + esc(p.cat) + ' \u2014 ' + p.rem + ' min (partial)"></span>';
      }
    }
    html += '</div>';
    if (bh >= 44) {
      const chips = [];
      for (const cat of REPORT.categories) {
        const m = minutes[cat] || 0;
        if (m > 0) chips.push('<span style="color:' + ((pal[cat] || {}).fg || '#9a9a96') + '">' + esc(cat) + ' ' + fmt(m) + '</span>');
      }
      html += '<div class="peb-foot">' + chips.join('<span style="color:#575753"> \u00b7 </span>') + '</div>';
    }
    body.innerHTML = html;
  }

  function styleCell(el, c) {
    const r = c.rect;
    el.style.left = r.x + 'px'; el.style.top = r.y + 'px';
    el.style.width = r.w + 'px'; el.style.height = r.h + 'px';
    el.style.zIndex = c.z;
    const zoomable = !c.pseudo && c.key !== zoom;
    el.classList.toggle('zoomable', zoomable);
    el.title = zoomable ? 'Zoom into ' + c.node.name : c.node.name;
    if (c.kind === 'leaf') {
      const body = el.querySelector('.cell-body');
      if (body && body.style.top !== c.headH + 'px') body.style.top = c.headH + 'px';
      if (body) renderPebbles(body, c);
    }
  }

  function render() {
    const cells = computeCells();
    const seen = new Set();
    for (const c of cells) {
      seen.add(c.key);
      let el = els.get(c.key);
      if (!el) { el = buildCell(c); els.set(c.key, el); vp.appendChild(el); }
      styleCell(el, c);
    }
    for (const [k, el] of els) if (!seen.has(k)) { els.delete(k); el.remove(); }
  }

  function renderCrumbs() {
    const parts = ['<span class="crumb' + (zoom === null ? ' cur' : '') + '" data-z="">All</span>'];
    if (zoom !== null) {
      const chain = [];
      let cur = zoom;
      let guard = 0;
      while (cur && guard++ < 50) { chain.unshift(cur); cur = parentOf.get(cur) || null; }
      chain.forEach((id, i) => {
        const n = nodeIndex.get(id);
        if (!n) return;
        parts.push('<span class="sep">\u203a</span><span class="crumb' + (i === chain.length - 1 ? ' cur' : '') + '" data-z="' + esc(id) + '">' + esc(n.name) + '</span>');
      });
    }
    crumbs.innerHTML = parts.join('');
  }

  function setZoom(id) {
    zoom = id;
    render();
    renderCrumbs();
  }

  vp.addEventListener('click', e => {
    const el = e.target.closest('.cell');
    if (!el) return;
    const key = el.dataset.key;
    if (key === zoom || key.endsWith('/general')) return;
    if (!(groupById.has(key) || childById.has(key))) return;
    setZoom(key);
  });

  crumbs.addEventListener('click', e => {
    const crumb = e.target.closest('.crumb');
    if (!crumb) return;
    setZoom(crumb.dataset.z || null);
  });

  document.addEventListener('keydown', e => {
    if (e.key === 'Escape' && zoom !== null) setZoom(parentOf.get(zoom) || null);
  });

  window.addEventListener('resize', () => {
    clearTimeout(resizeT);
    resizeT = setTimeout(render, 150);
  });

  render();
  renderCrumbs();
})();
</script>
<script src="skills_spider.js?v=8"></script>
<?php if ($skillsData !== null): ?>
<script>
(function () {
  var holder = document.getElementById('skills-spider-holder');
  if (!holder) return;
  render_skill_spider('skills-spider-holder', <?= json_encode($skillsData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);
})();
</script>
<?php else: ?>
<script>
(function () {
  var holder = document.getElementById('skills-spider-holder');
  if (!holder) return;
  holder.innerHTML = '<p class="text-slate-400 text-sm">Run the skills migration, then <a href="skills.php" class="text-accent-400 hover:underline">set up your skill tree</a>.</p>';
})();
</script>
<?php endif; ?>
</body>
</html>