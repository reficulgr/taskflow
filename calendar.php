<?php
require_once __DIR__ . '/functions.php';
$user = require_login();

$weekOffset = (int)($_GET['week'] ?? 0);
$monday = strtotime("monday this week", time()) + ($weekOffset * 7 * 86400);
$weekStart = date('Y-m-d', $monday);
$weekEnd = date('Y-m-d', $monday + 6 * 86400);

$completed = get_completed($user['id']);
$collections = get_collections($user['id']);
$allTasks = get_tasks($user['id']);
$flexibleTasks = array_filter($allTasks, fn($t) => $t['type'] === 'Flexible');
$byDay = [];
$untimed = [];
foreach ($completed as $c) {
  $day = $c['startAt'] ? date('Y-m-d', strtotime($c['startAt'])) : date('Y-m-d', strtotime($c['completedAt']));
  if ($day < $weekStart || $day > $weekEnd) continue;
  if ($c['startAt'] && $c['endAt']) {
    $byDay[$day][] = $c;
  } else {
    $untimed[$day][] = $c;
  }
}

$dayNames = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
$days = [];
for ($i = 0; $i < 7; $i++) {
  $days[] = [
    'key' => date('Y-m-d', $monday + $i * 86400),
    'label' => $dayNames[$i] . ' ' . date('M j', $monday + $i * 86400),
    'isToday' => date('Y-m-d') === date('Y-m-d', $monday + $i * 86400),
  ];
}

function timeToMinutesFromDayStart(string $iso): int {
  $ts = strtotime($iso);
  return (int)date('G', $ts) * 60 + (int)date('i', $ts);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>TaskFlow — Calendar</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <?php render_fonts(); ?>
  <style>
    ::-webkit-scrollbar { width: 8px; height: 8px; }
    ::-webkit-scrollbar-track { background: #1c1c1b; }
    ::-webkit-scrollbar-thumb { background: #575753; border-radius: 4px; }
    .cal-grid { display: grid; grid-template-columns: 56px repeat(7, 1fr); }
    .hour-row { height: 60px; }
    .hour-label {
      height: 60px;
      display: flex;
      align-items: flex-start;
      justify-content: flex-end;
      padding-right: 6px;
      padding-top: 2px;
      font-size: 11px;
      font-family: 'IBM Plex Mono', ui-monospace, monospace;
      border-top: 1px solid #1c1c1b;
      color: #7a7a76;
    }
    .day-col {
      border-left: 1px solid #1c1c1b;
      position: relative;
    }
    .day-col .hour-row { border-top: 1px solid #1c1c1b; }
    .time-block {
      position: absolute;
      left: 2px;
      right: 2px;
      border-radius: 4px;
      padding: 2px 6px;
      font-size: 10px;
      overflow: hidden;
      cursor: grab;
      z-index: 10;
      color: white;
      user-select: none;
    }
    .time-block:hover { box-shadow: 0 0 0 2px rgba(255,255,255,0.4); }
    .time-block.dragging { opacity: 0.6; cursor: grabbing; z-index: 20; }
    .resize-handle {
      position: absolute;
      left: 0; right: 0;
      height: 8px;
      cursor: ns-resize;
      z-index: 15;
    }
    .resize-handle.top { top: -2px; }
    .resize-handle.bottom { bottom: -2px; }
    .drop-target { background: rgba(201, 164, 74, 0.08); }
    .now-line {
      position: absolute;
      left: 56px;
      right: 0;
      height: 2px;
      background: #ef4444;
      z-index: 25;
      pointer-events: none;
    }
    .now-line::before {
      content: '';
      position: absolute;
      left: -6px;
      top: -4px;
      width: 10px;
      height: 10px;
      background: #ef4444;
      border-radius: 50%;
    }
    .sticky-header {
      position: sticky;
      top: 0;
      z-index: 50;
      background: #0e0e0d;
    }
    .drag-tooltip {
      position: fixed;
      background: #0e0e0d;
      border: 1px solid #3a3a38;
      border-radius: 4px;
      padding: 4px 8px;
      font-size: 11px;
      font-family: 'IBM Plex Mono', ui-monospace, monospace;
      color: #e2e2e0;
      z-index: 100;
      pointer-events: none;
      display: none;
    }
    #quick-log-modal {
      position: fixed;
      z-index: 200;
      background: #1c1c1b;
      border: 1px solid #3a3a38;
      border-radius: 8px;
      box-shadow: 0 10px 30px rgba(0,0,0,0.5);
      padding: 16px;
      width: 320px;
      display: none;
    }
    #quick-log-modal .ql-time {
      font-family: 'IBM Plex Mono', ui-monospace, monospace;
      font-size: 12px;
      color: #d9b861;
      margin-bottom: 12px;
    }
    #quick-log-overlay {
      position: fixed;
      inset: 0;
      z-index: 150;
      display: none;
    }
  </style>
</head>
<body class="bg-slate-900 text-slate-100 font-sans min-h-screen">
<?php render_header('calendar', $user); ?>
<main class="p-4 md:p-6">
  <div class="flex justify-between items-center bg-slate-900 pb-6 md:sticky md:top-16 md:z-30">
    <div class="flex items-center gap-4">
      <a href="calendar.php?week=<?= $weekOffset - 1 ?>" class="p-2 bg-slate-700 hover:bg-slate-600 rounded-md transition-colors">‹</a>
      <h1 class="text-2xl font-bold text-slate-100"><?= date('M j', $monday) ?> – <?= date('M j, Y', $monday + 6 * 86400) ?></h1>
      <a href="calendar.php?week=<?= $weekOffset + 1 ?>" class="p-2 bg-slate-700 hover:bg-slate-600 rounded-md transition-colors">›</a>
      <?php if ($weekOffset !== 0): ?>
        <a href="calendar.php" class="text-sm text-accent-400 hover:text-accent-300">This Week</a>
      <?php endif; ?>
    </div>
  </div>

  <div class="overflow-x-auto">
    <div class="sticky-header">
      <div class="cal-grid min-w-[800px]">
        <div></div>
        <?php foreach ($days as $d): ?>
          <div class="text-center py-2 font-semibold text-sm <?= $d['isToday'] ? 'text-accent-400' : 'text-slate-300' ?>"><?= htmlspecialchars($d['label']) ?></div>
        <?php endforeach; ?>

        <div></div>
        <?php foreach ($days as $d): ?>
          <div class="border-b border-slate-700 pb-1 min-h-[20px]">
            <?php if (!empty($untimed[$d['key']])): ?>
              <?php foreach ($untimed[$d['key']] as $c):
                $color = CAT_COLORS[$c['category']] ?? 'bg-gray-500';
              ?>
                <div class="untimed-task block text-xs px-2 py-1 mb-1 rounded <?= $color ?> text-white opacity-70 hover:opacity-100 transition-opacity cursor-grab" data-id="<?= $c['id'] ?>" data-duration="<?= $c['duration'] ?>" data-orig-day="<?= $d['key'] ?>" title="Drag to calendar or click to edit"><?= htmlspecialchars($c['taskName']) ?></div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="cal-grid min-w-[800px] relative" id="time-grid">
      <?php
        $todayKey = date('Y-m-d');
        $showNowLine = $todayKey >= $weekStart && $todayKey <= $weekEnd;
        if ($showNowLine):
          $nowMin = (int)date('G') * 60 + (int)date('i');
      ?>
        <div class="now-line" id="now-line" style="top:<?= $nowMin ?>px"></div>
      <?php endif; ?>
      <div>
        <?php for ($h = 0; $h < 24; $h++): ?>
          <div class="hour-label"><?= str_pad($h, 2, '0', STR_PAD_LEFT) ?>:00</div>
        <?php endfor; ?>
      </div>
      <?php foreach ($days as $d): ?>
        <div class="day-col" data-day="<?= $d['key'] ?>">
          <?php for ($h = 0; $h < 24; $h++): ?>
            <div class="hour-row"></div>
          <?php endfor; ?>
          <?php if (!empty($byDay[$d['key']])): ?>
            <?php foreach ($byDay[$d['key']] as $c):
              $color = CAT_COLORS[$c['category']] ?? 'bg-gray-500';
              $startMin = timeToMinutesFromDayStart($c['startAt']);
              $endMin = timeToMinutesFromDayStart($c['endAt']);
              $top = $startMin;
              $height = max(15, $endMin - $startMin);
            ?>
              <div class="time-block <?= $color ?>" data-id="<?= $c['id'] ?>" data-day="<?= $d['key'] ?>" style="top:<?= $top ?>px;height:<?= $height ?>px" title="<?= htmlspecialchars($c['taskName']) ?>">
                <div class="resize-handle top"></div>
                <div class="font-bold truncate"><?= htmlspecialchars($c['taskName']) ?></div>
                <div class="opacity-80 block-times"><?= date('H:i', strtotime($c['startAt'])) ?>–<?= date('H:i', strtotime($c['endAt'])) ?></div>
                <div class="resize-handle bottom"></div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</main>

<div class="drag-tooltip" id="drag-tooltip"></div>

<div id="quick-log-overlay"></div>
<div id="quick-log-modal">
  <div class="flex items-center justify-between mb-2">
    <h3 class="text-lg font-bold text-white">Quick Log</h3>
    <button type="button" id="ql-close" class="text-slate-400 hover:text-white text-xl leading-none">✕</button>
  </div>
  <div class="ql-time" id="ql-time-label"></div>
  <div class="flex gap-2 mb-3 p-1 bg-slate-700/50 rounded-md">
    <button type="button" id="ql-mode-new" class="flex-1 text-center p-1.5 rounded-md text-sm font-medium transition-colors bg-accent-500/90 text-slate-950">New task</button>
    <button type="button" id="ql-mode-existing" class="flex-1 text-center p-1.5 rounded-md text-sm font-medium transition-colors text-slate-300">Existing flexible</button>
  </div>
  <form id="quick-log-form" class="space-y-3" method="POST" action="action.php" novalidate>
    <input type="hidden" name="action" id="ql-action" value="quick_log">
    <div id="ql-new-fields">
      <div>
        <label class="block text-xs font-medium text-slate-300 mb-1">Task Name</label>
        <input type="text" name="name" id="ql-name" required class="w-full p-2 bg-slate-700 border border-slate-600 rounded-md focus:ring-2 focus:ring-accent-400 focus:outline-none text-sm">
      </div>
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="block text-xs font-medium text-slate-300 mb-1">Category</label>
          <select name="category" id="ql-category" class="w-full p-2 bg-slate-700 border border-slate-600 rounded-md focus:ring-2 focus:ring-accent-400 focus:outline-none text-sm">
            <?php foreach (CATEGORIES as $c): ?>
              <option value="<?= $c ?>"><?= $c ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="block text-xs font-medium text-slate-300 mb-1">Duration</label>
          <select name="duration" id="ql-duration" class="w-full p-2 bg-slate-700 border border-slate-600 rounded-md focus:ring-2 focus:ring-accent-400 focus:outline-none text-sm">
            <?php foreach (DURATIONS as $d): ?>
              <option value="<?= $d ?>" <?= $d === 30 ? 'selected' : '' ?>><?= $d ?> min</option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <?php if (!empty($collections)): ?>
      <div>
        <label class="block text-xs font-medium text-slate-300 mb-1">Collection</label>
        <select name="collection_id" id="ql-collection" class="w-full p-2 bg-slate-700 border border-slate-600 rounded-md focus:ring-2 focus:ring-accent-400 focus:outline-none text-sm">
          <option value="">— None —</option>
          <?php
            $byParent = [];
            foreach ($collections as $c) { $byParent[$c['parentId']][] = $c; }
            function render_ql_collection_opts($parentId, $byParent, $depth = 0) {
              foreach ($byParent[$parentId] ?? [] as $c) {
                $prefix = str_repeat('— ', $depth);
                echo "<option value=\"{$c['id']}\">" . htmlspecialchars($prefix . $c['name'] . ' (' . $c['type'] . ')') . "</option>";
                render_ql_collection_opts($c['id'], $byParent, $depth + 1);
              }
            }
            render_ql_collection_opts(null, $byParent);
          ?>
        </select>
      </div>
      <?php endif; ?>
    </div>
    <div id="ql-existing-fields" style="display:none">
      <div>
        <label class="block text-xs font-medium text-slate-300 mb-1">Flexible Task</label>
        <select name="task_id" id="ql-task-id" class="w-full p-2 bg-slate-700 border border-slate-600 rounded-md focus:ring-2 focus:ring-accent-400 focus:outline-none text-sm">
          <option value="">— Select a task —</option>
          <?php foreach ($flexibleTasks as $t): ?>
            <option value="<?= htmlspecialchars($t['id']) ?>"><?= htmlspecialchars($t['name']) ?> (<?= htmlspecialchars($t['category']) ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="block text-xs font-medium text-slate-300 mb-1">Duration</label>
        <select name="duration_existing" id="ql-duration-existing" class="w-full p-2 bg-slate-700 border border-slate-600 rounded-md focus:ring-2 focus:ring-accent-400 focus:outline-none text-sm">
          <?php foreach (DURATIONS as $d): ?>
            <option value="<?= $d ?>" <?= $d === 30 ? 'selected' : '' ?>><?= $d ?> min</option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div>
      <label class="block text-xs font-medium text-slate-300 mb-1">Note</label>
      <textarea name="note" rows="2" class="w-full p-2 bg-slate-700 border border-slate-600 rounded-md focus:ring-2 focus:ring-accent-400 focus:outline-none text-sm"></textarea>
    </div>
    <input type="hidden" name="start_at" id="ql-start-at">
    <input type="hidden" name="end_at" id="ql-end-at">
    <div class="flex justify-end gap-3 pt-2">
      <button type="button" id="ql-cancel" class="px-4 py-2 bg-slate-600 hover:bg-slate-500 rounded-md font-semibold text-sm">Cancel</button>
      <button type="submit" class="px-6 py-2 bg-accent-400 hover:bg-accent-300 text-slate-950 rounded-md font-bold text-sm">Log Task</button>
    </div>
  </form>
</div>

<script>
const PX_PER_MIN = 1;
const SNAP = 5;
const MIN_HEIGHT = 5;
const MAX_MIN = 24 * 60;
let dragData = null;

function snap(v) { return Math.round(v / SNAP) * SNAP; }
function minToTime(min) {
  const h = Math.floor(min / 60);
  const m = min % 60;
  return String(h).padStart(2, '0') + ':' + String(m).padStart(2, '0');
}

function getColFromX(x) {
  const cols = document.querySelectorAll('.day-col');
  const grid = document.getElementById('time-grid');
  const gridRect = grid.getBoundingClientRect();
  const timeColWidth = 56;
  const dayWidth = (gridRect.width - timeColWidth) / 7;
  const relX = x - gridRect.left - timeColWidth;
  if (relX < 0) return null;
  const idx = Math.min(6, Math.max(0, Math.floor(relX / dayWidth)));
  return cols[idx];
}

function updateTooltip(x, y, startMin, endMin) {
  const tip = document.getElementById('drag-tooltip');
  tip.style.display = 'block';
  tip.style.left = (x + 12) + 'px';
  tip.style.top = (y + 12) + 'px';
  tip.textContent = minToTime(startMin) + ' – ' + minToTime(endMin);
}

function hideTooltip() {
  document.getElementById('drag-tooltip').style.display = 'none';
}

// Update now-line every minute
const nowLine = document.getElementById('now-line');
if (nowLine) {
  setInterval(() => {
    const now = new Date();
    const min = now.getHours() * 60 + now.getMinutes();
    nowLine.style.top = min + 'px';
  }, 60000);
}

// On load, scroll so the current real time sits mid-viewport (1px = 1min).
window.addEventListener('DOMContentLoaded', () => {
  const grid = document.getElementById('time-grid');
  if (!grid) return;
  const now = new Date();
  const targetMin = now.getHours() * 60 + now.getMinutes();
  const gridTop = grid.getBoundingClientRect().top + window.scrollY;
  const target = gridTop + targetMin - window.innerHeight / 2;
  window.scrollTo(0, Math.max(0, target));
});

function updateBlockTimes(block) {
  const startMin = snap(parseFloat(block.style.top));
  const endMin = Math.min(MAX_MIN, startMin + snap(parseFloat(block.style.height)));
  block.querySelector('.block-times').textContent = minToTime(startMin) + '–' + minToTime(endMin);
  return { startMin, endMin };
}

// After a successful drag the mouseup is followed by a click on the dropped
// element; without this it would navigate to the edit page and kill the save.
function suppressNextClick() {
  const swallow = (ev) => { ev.preventDefault(); ev.stopPropagation(); disarm(); };
  const disarm = () => {
    document.removeEventListener('click', swallow, true);
    document.removeEventListener('mousedown', disarm, true);
  };
  document.addEventListener('click', swallow, true);
  document.addEventListener('mousedown', disarm, true);
}

document.querySelectorAll('.time-block').forEach(block => {
  block.addEventListener('mousedown', (e) => {
    if (e.button !== 0) return;
    if (e.target.classList.contains('resize-handle')) {
      const isTop = e.target.classList.contains('top');
      dragData = {
        type: 'resize',
        isTop,
        block,
        parentCol: block.parentElement,
        startY: e.clientY,
        startTop: parseFloat(block.style.top),
        startHeight: parseFloat(block.style.height),
        origDay: block.dataset.day,
        moved: false,
      };
    } else {
      const rect = block.getBoundingClientRect();
      dragData = {
        type: 'move',
        block,
        parentCol: block.parentElement,
        startY: e.clientY,
        startX: e.clientX,
        ghostScreenTop: rect.top,
        ghostScreenLeft: rect.left,
        origDay: block.dataset.day,
        moved: false,
      };
    }
    block.classList.add('dragging');
    e.preventDefault();
  });

  block.addEventListener('click', (e) => {
    if (dragData && dragData.moved) {
      e.preventDefault();
      return;
    }
    window.location.href = 'edit_completed.php?id=' + block.dataset.id;
  });
});

document.addEventListener('mousemove', (e) => {
  if (!dragData) return;
  dragData.moved = true;
  const block = dragData.block;
  const dy = e.clientY - dragData.startY;

  if (dragData.type === 'resize') {
    if (dragData.isTop) {
      let newTop = Math.max(0, dragData.startTop + dy);
      let newHeight = dragData.startHeight - dy;
      if (newHeight < MIN_HEIGHT) {
        newHeight = MIN_HEIGHT;
        newTop = dragData.startTop + dragData.startHeight - MIN_HEIGHT;
      }
      if (newTop + newHeight > MAX_MIN) {
        newHeight = MAX_MIN - newTop;
      }
      block.style.top = newTop + 'px';
      block.style.height = newHeight + 'px';
    } else {
      let newHeight = Math.max(MIN_HEIGHT, dragData.startHeight + dy);
      const currentTop = parseFloat(block.style.top);
      if (currentTop + newHeight > MAX_MIN) {
        newHeight = MAX_MIN - currentTop;
      }
      block.style.height = newHeight + 'px';
    }
    const times = updateBlockTimes(block);
    updateTooltip(e.clientX, e.clientY, times.startMin, times.endMin);
  } else if (dragData.type === 'move') {
    const dx = e.clientX - dragData.startX;
    const grid = document.getElementById('time-grid');
    const gridRect = grid.getBoundingClientRect();

    let ghostTop = dragData.ghostScreenTop + dy;
    const maxGhost = gridRect.top + MAX_MIN - parseFloat(block.style.height);
    ghostTop = Math.max(gridRect.top, Math.min(ghostTop, maxGhost));
    // Home position measured each move so the offset stays correct even if the
    // page scrolled mid-drag.
    const homeRect = dragData.parentCol.getBoundingClientRect();
    const homeScreenTop = homeRect.top + block.offsetTop;
    const homeScreenLeft = homeRect.left + block.offsetLeft;
    block.style.transform = `translate(${dragData.ghostScreenLeft + dx - homeScreenLeft}px, ${ghostTop - homeScreenTop}px)`;

    const col = getColFromX(e.clientX);
    document.querySelectorAll('.day-col').forEach(c => c.classList.remove('drop-target'));
    if (col) col.classList.add('drop-target');

    dragData.dropStartMin = snap(ghostTop - gridRect.top);
    const startMin = dragData.dropStartMin;
    const endMin = Math.min(MAX_MIN, startMin + snap(parseFloat(block.style.height)));
    updateTooltip(e.clientX, e.clientY, startMin, endMin);
  }
});

document.addEventListener('mouseup', (e) => {
  if (!dragData) return;
  const drag = dragData;
  const block = drag.block;
  block.classList.remove('dragging');
  document.querySelectorAll('.day-col').forEach(c => c.classList.remove('drop-target'));
  hideTooltip();

  let dayKey = drag.origDay;
  let targetCol = null;
  if (drag.type === 'move') {
    targetCol = getColFromX(e.clientX);
    if (targetCol) dayKey = targetCol.dataset.day;
  }

  let startMin;
  let endMin;
  if (drag.type === 'move' && drag.moved && drag.dropStartMin !== undefined) {
    startMin = Math.max(0, Math.min(MAX_MIN, drag.dropStartMin));
    endMin = Math.min(MAX_MIN, startMin + snap(parseFloat(block.style.height)));
  } else {
    startMin = snap(parseFloat(block.style.top));
    endMin = Math.min(MAX_MIN, startMin + snap(parseFloat(block.style.height)));
  }
  if (endMin <= startMin) endMin = Math.min(MAX_MIN, startMin + SNAP);

  const startDate = dayKey + ' ' + minToTime(startMin) + ':00';
  const endDate = dayKey + ' ' + minToTime(endMin) + ':00';

  block.style.transform = '';
  block.style.top = startMin + 'px';
  block.style.height = (endMin - startMin) + 'px';
  block.querySelector('.block-times').textContent = minToTime(startMin) + '–' + minToTime(endMin);
  block.dataset.day = dayKey;

  const formData = new FormData();
  formData.append('action', 'update_completed_times');
  formData.append('id', block.dataset.id);
  formData.append('start_at', startDate);
  formData.append('end_at', endDate);

  if (drag.type === 'move' && targetCol && targetCol !== drag.parentCol) {
    targetCol.appendChild(block);
  }

  fetch('action.php', { method: 'POST', body: formData })
    .then(r => r.json())
    .then(d => {
      if (!d.ok) throw new Error('save rejected');
      block.style.top = startMin + 'px';
      block.style.height = (endMin - startMin) + 'px';
    })
    .catch(() => {
      window.location.reload();
    });

  dragData = null;
  if (drag.moved) suppressNextClick();
});

// ====== Untimed task dragging ======
document.querySelectorAll('.untimed-task').forEach(el => {
  el.addEventListener('mousedown', (e) => {
    if (e.button !== 0) return;
    dragData = {
      type: 'untimed',
      block: el,
      startY: e.clientY,
      duration: parseInt(el.dataset.duration) || 15,
      origDay: el.dataset.origDay,
      moved: false,
    };
    el.classList.add('dragging');
    e.preventDefault();
  });

  el.addEventListener('click', (e) => {
    if (dragData && dragData.moved) {
      e.preventDefault();
      return;
    }
    window.location.href = 'edit_completed.php?id=' + el.dataset.id;
  });
});

// Untimed drag preview (capture so it runs before any other handlers)
document.addEventListener('mousemove', (e) => {
  if (!dragData || dragData.type !== 'untimed') return;
  dragData.moved = true;
  const col = getColFromX(e.clientX);
  document.querySelectorAll('.day-col').forEach(c => c.classList.remove('drop-target'));
  if (col) col.classList.add('drop-target');

  const grid = document.getElementById('time-grid');
  const gridRect = grid.getBoundingClientRect();
  const relY = e.clientY - gridRect.top;
  let startMin = snap(Math.max(0, Math.min(MAX_MIN - dragData.duration, relY)));
  let endMin = Math.min(MAX_MIN, startMin + dragData.duration);
  updateTooltip(e.clientX, e.clientY, startMin, endMin);
}, true);

// Extend mouseup to handle untimed dropping
document.addEventListener('mouseup', (e) => {
  if (!dragData || dragData.type !== 'untimed') return;
  const el = dragData.block;
  el.classList.remove('dragging');
  document.querySelectorAll('.day-col').forEach(c => c.classList.remove('drop-target'));
  hideTooltip();

  const col = getColFromX(e.clientX);
  if (!col || !dragData.moved) {
    dragData = null;
    return;
  }

  const grid = document.getElementById('time-grid');
  const gridRect = grid.getBoundingClientRect();
  const relY = e.clientY - gridRect.top;
  let startMin = snap(Math.max(0, Math.min(MAX_MIN - dragData.duration, relY)));
  let endMin = Math.min(MAX_MIN, startMin + dragData.duration);
  const dayKey = col.dataset.day;

  const startDate = dayKey + ' ' + minToTime(startMin) + ':00';
  const endDate = dayKey + ' ' + minToTime(endMin) + ':00';

  const formData = new FormData();
  formData.append('action', 'update_completed_times');
  formData.append('id', el.dataset.id);
  formData.append('start_at', startDate);
  formData.append('end_at', endDate);
  fetch('action.php', { method: 'POST', body: formData })
    .then(r => r.json())
    .then(() => { window.location.reload(); });

  suppressNextClick();
  dragData = null;
}, true);

// ====== Right-click quick log ======
const qlModal = document.getElementById('quick-log-modal');
const qlOverlay = document.getElementById('quick-log-overlay');
const qlForm = document.getElementById('quick-log-form');
const qlTimeLabel = document.getElementById('ql-time-label');
const qlStartAt = document.getElementById('ql-start-at');
const qlEndAt = document.getElementById('ql-end-at');
const qlName = document.getElementById('ql-name');
const qlDuration = document.getElementById('ql-duration');
const qlDurationExisting = document.getElementById('ql-duration-existing');
const qlTaskId = document.getElementById('ql-task-id');
const qlCloseBtn = document.getElementById('ql-close');
const qlCancelBtn = document.getElementById('ql-cancel');
const qlModeNew = document.getElementById('ql-mode-new');
const qlModeExisting = document.getElementById('ql-mode-existing');
const qlNewFields = document.getElementById('ql-new-fields');
const qlExistingFields = document.getElementById('ql-existing-fields');
let qlMode = 'new';

function setQlMode(mode) {
  qlMode = mode;
  if (mode === 'new') {
    qlNewFields.style.display = '';
    qlExistingFields.style.display = 'none';
    qlModeNew.classList.add('bg-accent-500/90', 'text-slate-950');
    qlModeNew.classList.remove('text-slate-300');
    qlModeExisting.classList.remove('bg-accent-500/90', 'text-slate-950');
    qlModeExisting.classList.add('text-slate-300');
    qlName.required = true;
    qlTaskId.required = false;
    document.getElementById('ql-action').value = 'quick_log';
    qlName.focus();
  } else {
    qlNewFields.style.display = 'none';
    qlExistingFields.style.display = '';
    qlModeExisting.classList.add('bg-accent-500/90', 'text-slate-950');
    qlModeExisting.classList.remove('text-slate-300');
    qlModeNew.classList.remove('bg-accent-500/90', 'text-slate-950');
    qlModeNew.classList.add('text-slate-300');
    qlName.required = false;
    qlTaskId.required = true;
    document.getElementById('ql-action').value = 'quick_log_existing';
    qlTaskId.focus();
  }
  updateQlEndTime();
}

qlModeNew.addEventListener('click', () => setQlMode('new'));
qlModeExisting.addEventListener('click', () => setQlMode('existing'));

function getActiveDuration() {
  return parseInt((qlMode === 'new' ? qlDuration : qlDurationExisting).value);
}

function updateQlEndTime() {
  if (!qlStartAt.value) return;
  const dayKey = qlStartAt.value.substring(0, 10);
  const startMin = snap(timeToMinutesFromDayStartStr(qlStartAt.value));
  const endMin = Math.min(MAX_MIN, startMin + getActiveDuration());
  qlEndAt.value = dayKey + ' ' + minToTime(endMin) + ':00';
  qlTimeLabel.textContent = dayKey + '  ' + minToTime(startMin) + '–' + minToTime(endMin);
}

function closeQuickLog() {
  qlModal.style.display = 'none';
  qlOverlay.style.display = 'none';
  qlForm.reset();
  setQlMode('new');
}

function openQuickLog(dayKey, startMin) {
  startMin = snap(startMin);
  const endMin = Math.min(MAX_MIN, startMin + getActiveDuration());
  const startDate = dayKey + ' ' + minToTime(startMin) + ':00';
  const endDate = dayKey + ' ' + minToTime(endMin) + ':00';
  qlStartAt.value = startDate;
  qlEndAt.value = endDate;
  qlTimeLabel.textContent = dayKey + '  ' + minToTime(startMin) + '–' + minToTime(endMin);

  // Show first so the real (mode-dependent) height can be measured, then center in the viewport.
  qlModal.style.display = 'block';
  qlOverlay.style.display = 'block';
  const modalRect = qlModal.getBoundingClientRect();
  const left = Math.max(10, Math.round((window.innerWidth - modalRect.width) / 2));
  const top = Math.max(10, Math.round((window.innerHeight - modalRect.height) / 2));
  qlModal.style.left = left + 'px';
  qlModal.style.top = top + 'px';
  qlName.focus();
}

qlDuration.addEventListener('change', updateQlEndTime);
qlDurationExisting.addEventListener('change', updateQlEndTime);

function timeToMinutesFromDayStartStr(iso) {
  const t = new Date(iso);
  return t.getHours() * 60 + t.getMinutes();
}

qlCloseBtn.addEventListener('click', closeQuickLog);
qlCancelBtn.addEventListener('click', closeQuickLog);
qlOverlay.addEventListener('click', closeQuickLog);
document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape' && qlModal.style.display === 'block') closeQuickLog();
});

document.getElementById('time-grid').addEventListener('contextmenu', (e) => {
  if (e.target.closest('.time-block')) return;
  e.preventDefault();
  const col = getColFromX(e.clientX);
  if (!col) return;
  const dayKey = col.dataset.day;
  const grid = document.getElementById('time-grid');
  const gridRect = grid.getBoundingClientRect();
  const relY = e.clientY - gridRect.top;
  const startMin = snap(Math.max(0, Math.min(MAX_MIN - 15, relY)));
  openQuickLog(dayKey, startMin);
});

qlForm.addEventListener('submit', () => {
  if (qlMode === 'existing') {
    document.getElementById('ql-action').value = 'quick_log_existing';
  } else {
    document.getElementById('ql-action').value = 'quick_log';
  }
});
</script>
</body>
</html>