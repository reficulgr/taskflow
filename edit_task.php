<?php
require_once __DIR__ . '/functions.php';
$user = require_login();

$editTask = null;
if (isset($_GET['id'])) {
  $editTask = get_task($user['id'], $_GET['id']);
  if (!$editTask) redirect('browse.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $id = $_POST['id'] ?: uniqid('', true);
  $type = $_POST['type'] ?? 'OneOff';
  $recurrence = null;
  if ($type === 'Recurring') {
    $freq = $_POST['rec_freq'] ?? 'Days';
    $interval = $freq === 'SpecificDays' ? 1 : max(1, (int)($_POST['rec_interval'] ?? 1));
    $days = [];
    if ($freq === 'Weeks' || $freq === 'SpecificDays') {
      foreach (($_POST['rec_days'] ?? []) as $d) $days[] = (int)$d;
      sort($days);
    }
    $recurrence = ['frequency' => $freq, 'interval' => $interval, 'daysOfWeek' => $days ?: null];
  }
  $deadlineDate = null;
  $deadlineTime = null;
  if ($type === 'OneOff' && !empty($_POST['deadline_date'])) {
    $deadlineDate = $_POST['deadline_date'];
    if (!empty($_POST['deadline_time'])) $deadlineTime = $_POST['deadline_time'];
    $deadline = date('c', strtotime("$deadlineDate " . ($deadlineTime ?: '00:00')));
  } else {
    $deadline = null;
  }
  $data = [
    'id' => $id,
    'name' => trim($_POST['name'] ?? ''),
    'category' => $_POST['category'] ?? 'Revenue Today',
    'duration' => (int)($_POST['duration'] ?? 15),
    'type' => $type,
    'recurrence' => $recurrence,
    'deadline' => $deadline,
    'lastCompleted' => $editTask['lastCompleted'] ?? null,
    'lastSkipped' => $editTask['lastSkipped'] ?? null,
    'pinned' => !empty($_POST['pinned']),
    'collectionId' => !empty($_POST['collection_id']) ? (int)$_POST['collection_id'] : null,
    'note' => trim($_POST['note'] ?? '') ?: null,
    'createdAt' => $editTask['createdAt'] ?? date('c'),
  ];
  if ($data['name'] === '') { redirect('edit_task.php'); }
  save_task($user['id'], $data);
  if (skills_table_exists()) {
    $skillIds = array_map('intval', $_POST['skill_ids'] ?? []);
    set_task_skills($user['id'], $id, $skillIds);
  }
  redirect('calendar.php');
}

$r = $editTask['recurrence'] ?? ['frequency' => 'Days', 'interval' => 1, 'daysOfWeek' => []];
$taskSkillIds = $editTask ? array_column(get_task_skills($user['id'], $editTask['id']), 'id') : [];
$allSkills = skills_table_exists() ? get_skills($user['id']) : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>TaskFlow — <?= $editTask ? 'Edit Task' : 'Add Task' ?></title>
  <script src="https://cdn.tailwindcss.com"></script>
  <?php render_fonts(); ?>
</head>
<body class="bg-slate-900 text-slate-100 font-sans min-h-screen flex items-center justify-center p-4">
  <div class="bg-slate-800 rounded-lg shadow-xl p-6 w-full max-w-lg max-h-[90vh] overflow-y-auto">
    <div class="flex items-center justify-between mb-6">
      <h2 class="text-2xl font-bold text-white"><?= $editTask ? 'Edit Task' : 'Add New Task' ?></h2>
      <a href="browse.php" class="text-slate-400 hover:text-white text-xl">✕</a>
    </div>
    <?php if ($editTask): ?>
    <form method="POST" action="action.php" id="delete-task-form" onsubmit="return confirm('Delete this task?')">
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="task_id" value="<?= htmlspecialchars($editTask['id']) ?>">
    </form>
    <?php endif; ?>
    <form method="POST" class="space-y-4">
      <input type="hidden" name="id" value="<?= htmlspecialchars($editTask['id'] ?? '') ?>">
      <div>
        <label class="block text-sm font-medium text-slate-300 mb-1">Task Name</label>
        <input type="text" name="name" required value="<?= htmlspecialchars($editTask['name'] ?? '') ?>" class="w-full p-2 bg-slate-700 border border-slate-600 rounded-md focus:ring-2 focus:ring-accent-400 focus:outline-none">
      </div>
      <div>
        <label class="block text-sm font-medium text-slate-300 mb-1">Note (Optional)</label>
        <textarea name="note" rows="3" placeholder="Optional notes..." class="w-full p-2 bg-slate-700 border border-slate-600 rounded-md focus:ring-2 focus:ring-accent-400 focus:outline-none"><?= htmlspecialchars($editTask['note'] ?? '') ?></textarea>
      </div>
      <div class="grid grid-cols-2 gap-4">
        <div>
          <label class="block text-sm font-medium text-slate-300 mb-1">Category</label>
          <select name="category" class="w-full p-2 bg-slate-700 border border-slate-600 rounded-md focus:ring-2 focus:ring-accent-400 focus:outline-none">
            <?php foreach (CATEGORIES as $c): ?>
              <option value="<?= $c ?>" <?= ($editTask['category'] ?? '') === $c ? 'selected' : '' ?>><?= $c ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-300 mb-1">Duration</label>
          <select name="duration" class="w-full p-2 bg-slate-700 border border-slate-600 rounded-md focus:ring-2 focus:ring-accent-400 focus:outline-none">
            <?php foreach (DURATIONS as $d): ?>
              <option value="<?= $d ?>" <?= ($editTask['duration'] ?? 15) === $d ? 'selected' : '' ?>><?= $d ?> min</option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div>
        <label class="block text-sm font-medium text-slate-300 mb-1">Type</label>
        <div class="flex gap-2 p-1 bg-slate-700/50 rounded-md">
          <label class="flex-1 text-center p-2 rounded-md cursor-pointer transition-colors type-btn <?= ($editTask['type'] ?? 'OneOff') === 'OneOff' ? 'bg-accent-500/90 text-slate-950' : 'text-slate-300' ?>">
            <input type="radio" name="type" value="OneOff" <?= ($editTask['type'] ?? 'OneOff') === 'OneOff' ? 'checked' : '' ?> class="hidden">One-off
          </label>
          <label class="flex-1 text-center p-2 rounded-md cursor-pointer transition-colors type-btn <?= ($editTask['type'] ?? '') === 'Recurring' ? 'bg-accent-500/90 text-slate-950' : 'text-slate-300' ?>">
            <input type="radio" name="type" value="Recurring" <?= ($editTask['type'] ?? '') === 'Recurring' ? 'checked' : '' ?> class="hidden">Recurring
          </label>
          <label class="flex-1 text-center p-2 rounded-md cursor-pointer transition-colors type-btn <?= ($editTask['type'] ?? '') === 'Flexible' ? 'bg-accent-500/90 text-slate-950' : 'text-slate-300' ?>">
            <input type="radio" name="type" value="Flexible" <?= ($editTask['type'] ?? '') === 'Flexible' ? 'checked' : '' ?> class="hidden">Flexible
          </label>
        </div>
      </div>
      <div id="deadline-field" style="display:<?= ($editTask['type'] ?? 'OneOff') === 'OneOff' ? 'block' : 'none' ?>">
        <label class="block text-sm font-medium text-slate-300 mb-1">Deadline (Optional)</label>
        <div class="flex gap-2">
          <input type="date" name="deadline_date" value="<?= $editTask['deadline'] ? date('Y-m-d', strtotime($editTask['deadline'])) : '' ?>" class="flex-1 p-2 bg-slate-700 border border-slate-600 rounded-md focus:ring-2 focus:ring-accent-400 focus:outline-none">
          <input type="time" name="deadline_time" value="<?= $editTask['deadline'] && date('H:i', strtotime($editTask['deadline'])) !== '00:00' ? date('H:i', strtotime($editTask['deadline'])) : '' ?>" class="w-32 p-2 bg-slate-700 border border-slate-600 rounded-md focus:ring-2 focus:ring-accent-400 focus:outline-none" placeholder="Time?">
        </div>
      </div>
      <div id="recurrence-field" style="display:<?= ($editTask['type'] ?? '') === 'Recurring' ? 'block' : 'none' ?>" class="p-4 bg-slate-900/50 rounded-lg space-y-4">
        <h3 class="font-semibold text-slate-200">Recurrence Rules</h3>
        <div class="flex items-center gap-2">
          <input type="number" name="rec_interval" min="1" value="<?= $r['interval'] ?>" class="w-20 p-2 bg-slate-700 border border-slate-600 rounded-md focus:ring-2 focus:ring-accent-400 focus:outline-none">
          <select name="rec_freq" id="rec-freq" class="w-full p-2 bg-slate-700 border border-slate-600 rounded-md focus:ring-2 focus:ring-accent-400 focus:outline-none">
            <option value="Days" <?= $r['frequency'] === 'Days' ? 'selected' : '' ?>>Days</option>
            <option value="Weeks" <?= $r['frequency'] === 'Weeks' ? 'selected' : '' ?>>Weeks</option>
            <option value="Months" <?= $r['frequency'] === 'Months' ? 'selected' : '' ?>>Months</option>
            <option value="SpecificDays" <?= $r['frequency'] === 'SpecificDays' ? 'selected' : '' ?>>Specific Days</option>
          </select>
        </div>
        <div id="days-field" style="display:<?= in_array($r['frequency'], ['Weeks', 'SpecificDays']) ? 'block' : 'none' ?>">
          <label class="block text-sm font-medium text-slate-300 mb-1">On these days</label>
          <div class="flex justify-between gap-1 bg-slate-700 p-1 rounded-md">
            <?php
            $dayNames = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
            $selectedDays = $r['daysOfWeek'] ?? [];
            foreach ($dayNames as $i => $name):
              $checked = in_array($i, $selectedDays) ? 'bg-accent-500/90 text-slate-950 font-bold' : 'bg-slate-600 hover:bg-slate-500 text-slate-300';
            ?>
              <label class="flex-1 p-2 text-sm rounded transition-colors cursor-pointer text-center <?= $checked ?>">
                <input type="checkbox" name="rec_days[]" value="<?= $i ?>" <?= in_array($i, $selectedDays) ? 'checked' : '' ?> class="hidden day-cb"><?= $name ?>
              </label>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
      <div class="flex items-center gap-2">
        <label class="flex items-center gap-2 cursor-pointer">
          <input type="checkbox" name="pinned" value="1" <?= !empty($editTask['pinned']) ? 'checked' : '' ?> class="w-4 h-4 rounded bg-slate-700 border-slate-600 text-accent-400 focus:ring-accent-400">
          <span class="text-sm font-medium text-slate-300">Pin to top</span>
        </label>
      </div>
      <?php
        $collections = get_collections($user['id']);
        $byParent = [];
        foreach ($collections as $c) { $byParent[$c['parentId']][] = $c; }
        function render_collection_options($parentId, $byParent, $selectedId, $depth = 0) {
          $children = $byParent[$parentId] ?? [];
          foreach ($children as $c) {
            $prefix = str_repeat('— ', $depth);
            $sel = $selectedId === $c['id'] ? 'selected' : '';
            echo "<option value=\"{$c['id']}\" $sel>" . htmlspecialchars($prefix . $c['name'] . ' (' . $c['type'] . ')') . "</option>";
            render_collection_options($c['id'], $byParent, $selectedId, $depth + 1);
          }
        }
      ?>
      <?php if (!empty($collections)): ?>
      <div>
        <label class="block text-sm font-medium text-slate-300 mb-1">Belongs to (Optional)</label>
        <select name="collection_id" class="w-full p-2 bg-slate-700 border border-slate-600 rounded-md focus:ring-2 focus:ring-accent-400 focus:outline-none">
          <option value="">— None —</option>
          <?php render_collection_options(null, $byParent, $editTask['collectionId'] ?? null); ?>
        </select>
      </div>
      <?php endif; ?>
      <?php if (!empty($allSkills)): ?>
      <?php
        $skillsJson = json_encode(array_map(fn($s) => [
          'id' => (int)$s['id'],
          'name' => $s['name'],
          'parentIds' => array_map('intval', $s['parentIds']),
          'archived' => (bool)$s['archived'],
        ], $allSkills), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $selectedJson = json_encode(array_values(array_map('intval', $taskSkillIds)));
      ?>
      <div>
        <label class="block text-sm font-medium text-slate-300 mb-1">Skills Practised (Optional)</label>
        <div id="skill-picker" data-skills="<?= htmlspecialchars($skillsJson, ENT_QUOTES) ?>" data-selected="<?= htmlspecialchars($selectedJson, ENT_QUOTES) ?>">
          <div id="sp-tray" class="flex flex-wrap items-center gap-1.5 mb-2 min-h-[30px] p-1.5 bg-slate-900/60 rounded-md border border-slate-700"></div>
          <div class="overflow-x-auto">
            <div class="grid grid-cols-3 min-w-[340px] border border-slate-700 rounded-md overflow-hidden bg-slate-900/70">
              <div id="sp-prev" class="h-44 overflow-y-auto border-r border-slate-700"></div>
              <div id="sp-cur" class="h-44 overflow-y-auto border-r border-slate-700"></div>
              <div id="sp-next" class="h-44 overflow-y-auto"></div>
            </div>
          </div>
        </div>
      </div>
      <?php endif; ?>
      <div class="flex justify-end gap-4 pt-4">
        <?php if ($editTask): ?>
          <button type="submit" form="delete-task-form" class="mr-auto px-4 py-2 bg-rose-900/60 hover:bg-rose-700 text-rose-200 hover:text-white rounded-md font-semibold transition-colors">Delete</button>
        <?php endif; ?>
        <a href="browse.php" class="px-4 py-2 bg-slate-600 hover:bg-slate-500 rounded-md font-semibold">Cancel</a>
        <button type="submit" class="px-6 py-2 bg-accent-400 hover:bg-accent-300 text-slate-950 rounded-md font-bold"><?= $editTask ? 'Save Changes' : 'Add Task' ?></button>
      </div>
    </form>
  </div>
  <script>
  (function () {
    const picker = document.getElementById('skill-picker');
    if (!picker) return;
    const skills = JSON.parse(picker.dataset.skills);
    const tray = document.getElementById('sp-tray');
    const prevPane = document.getElementById('sp-prev');
    const curPane = document.getElementById('sp-cur');
    const nextPane = document.getElementById('sp-next');

    const byId = {};
    skills.forEach(s => { byId[s.id] = s; });
    const childrenOf = {};
    skills.forEach(s => {
      const plist = s.parentIds.length ? s.parentIds : [null];
      plist.forEach(p => { (childrenOf[p] = childrenOf[p] || []).push(s); });
    });
    Object.values(childrenOf).forEach(list => list.sort((a, b) => a.name.localeCompare(b.name)));
    const roots = childrenOf[null] || [];

    const selected = new Set(JSON.parse(picker.dataset.selected));
    let path = [];      // ids of ancestors entered; cur pane renders path[path.length]
    let focusId = null; // id whose children preview in the next pane

    function childrenAt(level) {
      if (level === 0) return roots;
      return childrenOf[path[level - 1]] || [];
    }

    function fmtEl(s) {
      const on = selected.has(s.id);
      const el = document.createElement('div');
      el.className = 'sp-row flex items-center justify-between gap-1 px-2 py-1.5 text-xs cursor-pointer transition-colors ' +
        (on ? 'bg-accent-500/20 text-accent-300' : 'text-slate-300 hover:bg-slate-800');
      el.dataset.id = s.id;
      el.title = s.name;
      el.innerHTML =
        '<span class="truncate ' + (s.archived ? 'line-through text-slate-500' : '') + '">' +
        (on ? '<span class="text-accent-400 mr-1">✓</span>' : '') +
        escapeHtml(s.name) + '</span>' +
        '<span class="flex items-center gap-0.5 flex-shrink-0">' +
        ((childrenOf[s.id] || []).length ? '<span class="text-slate-500 text-[10px]">▸</span>' : '') +
        '<button type="button" class="sp-add ' + (on ? 'text-accent-400' : 'text-slate-500 hover:text-accent-400') +
        (s.archived && !on ? ' cursor-not-allowed' : '') + ' w-4 h-4 leading-none text-sm font-bold" title="' +
        (on ? 'Remove tag' : (s.archived ? 'Archived — cannot tag' : 'Tag skill')) + '">' + (on ? '✓' : '+') + '</button>' +
        '</span>';
      return el;
    }

    function paneHeader(label, muted) {
      const el = document.createElement('div');
      el.className = 'px-2 py-1 text-[10px] border-b border-slate-800 truncate ' + (muted ? 'uppercase tracking-wide text-slate-600' : 'text-slate-400');
      el.textContent = label;
      return el;
    }

    function ghost(text) {
      const el = document.createElement('div');
      el.className = 'px-2 py-1.5 text-[10px] text-slate-600 italic';
      el.textContent = text;
      return el;
    }

    function escapeHtml(str) {
      return String(str).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    }

    function renderPane(pane, level) {
      pane.innerHTML = '';
      pane.appendChild(paneHeader(level === 0 ? 'Top level' : byId[path[level - 1]].name, level === 0));
      const rows = childrenAt(level);
      if (!rows.length) { pane.appendChild(ghost('No children')); return; }
      rows.forEach(s => {
        const el = fmtEl(s);
        if (level < path.length && path[level] === s.id) el.classList.add('bg-slate-800', 'text-accent-300');
        pane.appendChild(el);
      });
    }

    function renderPreview() {
      nextPane.innerHTML = '';
      if (focusId && (childrenOf[focusId] || []).length) {
        nextPane.appendChild(paneHeader(byId[focusId].name, false));
        childrenOf[focusId].forEach(s => nextPane.appendChild(fmtEl(s)));
      } else {
        nextPane.appendChild(ghost('Click a row with ▸ to see its children'));
      }
    }

    function makeChip(s) {
      const chip = document.createElement('span');
      chip.className = 'inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-accent-500/90 text-slate-950 border border-accent-500';
      chip.innerHTML = '<input type="checkbox" name="skill_ids[]" value="' + s.id + '" checked class="hidden">' +
        escapeHtml(s.name) +
        '<button type="button" class="sp-remove opacity-60 hover:opacity-100 leading-none" title="Remove">✕</button>';
      if (s.archived) chip.classList.add('opacity-50');
      return chip;
    }

    function renderTray() {
      tray.innerHTML = '';
      if (!selected.size) {
        tray.appendChild(ghost('No skills tagged — click + on a row'));
        return;
      }
      [...selected].sort((a, b) => (byId[a]?.name || '').localeCompare(byId[b]?.name || '')).forEach(id => {
        const s = byId[id];
        if (!s) { selected.delete(id); return; }
        const chip = makeChip(s);
        chip.querySelector('.sp-remove').addEventListener('click', () => { selected.delete(id); render(); });
        tray.appendChild(chip);
      });
    }

    function render() {
      const f = focusId ? byId[focusId] : null;
      const depth = f ? path.indexOf(f.id) : Math.max(0, path.length - 1);
      prevPane.innerHTML = '';
      if (depth > 0) renderPane(prevPane, depth - 1);
      else prevPane.appendChild(ghost('—'));
      renderPane(curPane, depth);
      renderPreview();
      renderTray();
    }

    function navigate(newPath, newFocus) {
      path = newPath;
      focusId = newFocus;
      render();
    }

    function toggleTag(id, s) {
      if (s.archived && !selected.has(id)) return;
      if (selected.has(id)) selected.delete(id); else selected.add(id);
      render();
    }

    // One movement rule: clicking any row focuses it — path becomes the
    // ancestor chain of the clicked row *through the pane's own level*
    // (cur pane = the focus's level, next pane = one deeper, prev pane = one
    // higher). Highlight = the focus; right pane = its children.
    // + always just toggles the tag.
    function bind(pane, levelOffset) {
      pane.addEventListener('click', e => {
        const add = e.target.closest('.sp-add');
        const row = e.target.closest('.sp-row');
        if (!row) return;
        const id = parseInt(row.dataset.id, 10);
        if (add) { toggleTag(id, byId[id]); return; }
        const f = focusId ? byId[focusId] : null;
        const curLevel = f ? path.indexOf(f.id) : Math.max(0, path.length - 1);
        const targetLevel = curLevel + levelOffset;
        if (targetLevel < 0) return;
        path = path.slice(0, targetLevel).concat([id]);
        focusId = id;
        render();
      });
    }

    bind(prevPane, -1);
    bind(curPane, 0);
    bind(nextPane, +1);

    render();
  })();
  </script>
  <script>
    const typeBtns = document.querySelectorAll('.type-btn input');
    const deadlineField = document.getElementById('deadline-field');
    const recurrenceField = document.getElementById('recurrence-field');
    const recFreq = document.getElementById('rec-freq');
    const daysField = document.getElementById('days-field');

    function updateTypeUI(value) {
      deadlineField.style.display = value === 'OneOff' ? 'block' : 'none';
      recurrenceField.style.display = value === 'Recurring' ? 'block' : 'none';
      document.querySelectorAll('.type-btn').forEach(btn => {
        const isActive = btn.querySelector('input').value === value;
        btn.classList.toggle('bg-accent-500/90', isActive);
        btn.classList.toggle('text-slate-950', isActive);
        btn.classList.toggle('text-slate-300', !isActive);
      });
    }

    typeBtns.forEach(input => {
      input.onchange = () => updateTypeUI(input.value);
    });
    recFreq.onchange = () => {
      daysField.style.display = (recFreq.value === 'Weeks' || recFreq.value === 'SpecificDays') ? 'block' : 'none';
      const intervalInput = recurrenceField.querySelector('input[name="rec_interval"]');
      intervalInput.parentElement.style.display = recFreq.value === 'SpecificDays' ? 'none' : 'flex';
    };
    document.querySelectorAll('.day-cb').forEach(cb => {
      cb.onchange = () => {
        cb.parentElement.classList.toggle('bg-accent-500/90', cb.checked);
        cb.parentElement.classList.toggle('text-slate-950', cb.checked);
        cb.parentElement.classList.toggle('font-bold', cb.checked);
        cb.parentElement.classList.toggle('bg-slate-600', !cb.checked);
      };
    });
  </script>
</body>
</html>