<?php
require_once __DIR__ . '/functions.php';
$user = require_login();
$skills = get_skills($user['id']);

// Time filter (same grammar as report.php)
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

$completed = get_completed($user['id']);
$completed = array_values(array_filter($completed, function($c) use ($rangeFrom, $rangeTo) {
  $d = date('Y-m-d', strtotime($c['completedAt']));
  if ($rangeFrom !== null && $d < $rangeFrom) return false;
  if ($rangeTo !== null && $d > $rangeTo) return false;
  return true;
}));
$skillMinutes = skill_minutes($user['id'], $completed);

// Max minutes across the tree for node saturation
$maxMinutes = 0;
foreach ($skillMinutes as $m) $maxMinutes = max($maxMinutes, $m);

// Roots in a stable order: the four archetypes first, then any custom roots by name
$roots = [];
$archetypeOrder = array_flip(ARCHETYPE_SKILLS);
usort($skills, function($a, $b) use ($archetypeOrder) {
  $aRoot = empty($a['parentIds']);
  $bRoot = empty($b['parentIds']);
  if ($aRoot && $bRoot) {
    $ao = isset($archetypeOrder[$a['name']]) ? $archetypeOrder[$a['name']] : 99;
    $bo = isset($archetypeOrder[$b['name']]) ? $archetypeOrder[$b['name']] : 99;
    if ($ao !== $bo) return $ao <=> $bo;
    return strcmp($a['name'], $b['name']);
  }
  if ($aRoot) return 1;
  if ($bRoot) return -1;
  return strcmp($a['name'], $b['name']);
});
foreach ($skills as $s) if (empty($s['parentIds'])) $roots[] = $s;

// Multi-parent index: children listed under EACH parent.
// $childrenOf[parentId] = [skills that have parentId among their parents]
$childrenOf = [];
foreach ($skills as $s) {
  foreach ($s['parentIds'] as $pid) { $childrenOf[$pid][] = $s; }
}
foreach ($childrenOf as &$list) usort($list, fn($a, $b) => strcmp($a['name'], $b['name']));
unset($list);

// Reference counts for delete hints
$taskCounts = []; $stampCounts = [];
if (!empty($skills)) {
  $stmt = db()->prepare('SELECT skill_id, COUNT(*) AS n FROM task_skills WHERE user_id = ? GROUP BY skill_id');
  $stmt->execute([$user['id']]);
  foreach ($stmt->fetchAll() as $r) $taskCounts[(int)$r['skill_id']] = (int)$r['n'];
  $stmt = db()->prepare('SELECT skill_id, COUNT(*) AS n FROM completed_task_skills WHERE user_id = ? GROUP BY skill_id');
  $stmt->execute([$user['id']]);
  foreach ($stmt->fetchAll() as $r) $stampCounts[(int)$r['skill_id']] = (int)$r['n'];
}

function node_saturation(int $mins, int $max): float {
  if ($max <= 0) return 0;
  return min(1, sqrt($mins / $max));
}

// Renders a node card (root or child). $renderPath guards against cycles.
function render_skill_node(array $node, bool $isRoot, array $childrenOf, array $minutes, array $taskCounts, array $stampCounts, int $maxMinutes, string $color, array $renderPath) {
  $id = $node['id'];
  if (in_array($id, $renderPath, true)) return; // cycle guard
  $path = array_merge($renderPath, [$id]);
  $kids = $childrenOf[$id] ?? [];
  $mins = $minutes[$id] ?? 0;
  $sat = node_saturation($mins, $maxMinutes);
  $refCount = ($taskCounts[$id] ?? 0) + ($stampCounts[$id] ?? 0);
  $multiParent = count($node['parentIds']) > 1;
  $name = htmlspecialchars($node['name']);
  $isArchetype = $isRoot && isset(SKILL_ROOT_COLORS[$node['name']]);
  $borderSat = 0.2 + 0.6 * $sat;
  $rootClass = $isRoot
    ? ($node['archived'] ? 'opacity-50 border-slate-700 bg-slate-800/60' : 'border-slate-600 bg-slate-800')
    : ($node['archived'] ? 'opacity-50' : '');
?>
  <?php if ($isRoot): ?>
    <div class="space-y-3" data-root-id="<?= $id ?>">
      <div class="skill-node drop-target" draggable="true" data-id="<?= $id ?>" data-name="<?= $name ?>" data-archived="<?= $node['archived'] ? '1' : '0' ?>" data-referenced="<?= $refCount > 0 ? '1' : '0' ?>">
        <div class="rounded-xl p-4 border-2 transition-colors <?= $rootClass ?>" style="box-shadow: 0 0 <?= (20 + 25 * $sat) ?>px -8px <?= $color ?>">
          <div class="flex items-center justify-between gap-2">
            <div class="flex items-center gap-2.5 min-w-0">
              <span class="w-4 h-4 rounded-full flex-shrink-0" style="background: <?= $color ?>; opacity: <?= 0.25 + 0.75 * $sat ?>"></span>
              <span class="skill-name font-bold text-lg truncate" style="color: <?= $isArchetype ? '#f1f1f0' : '#c6c6c2' ?>"><?= $name ?></span>
              <?= $node['archived'] ? '<span class="text-[10px] bg-slate-700 text-slate-400 px-1.5 rounded-full flex-shrink-0">archived</span>' : '' ?>
            </div>
            <div class="flex items-center gap-0.5 flex-shrink-0 skill-actions">
              <button type="button" class="node-add w-7 h-7 rounded-full bg-slate-700 hover:bg-accent-600 text-slate-300 hover:text-slate-950 text-sm font-bold transition-colors" title="Add child skill">+</button>
              <button type="button" class="node-rename w-7 h-7 rounded-full bg-slate-700 hover:bg-accent-600 text-slate-300 hover:text-slate-950 text-xs transition-colors" title="Rename">✎</button>
              <form method="POST" action="action.php" class="inline">
                <input type="hidden" name="action" value="save_skill">
                <input type="hidden" name="skill_id" value="<?= $id ?>">
                <input type="hidden" name="name" value="<?= $name ?>">
                <input type="hidden" name="archived" value="<?= $node['archived'] ? '' : '1' ?>">
                <button type="submit" class="w-7 h-7 rounded-full bg-slate-700 hover:bg-amber-600 text-slate-300 hover:text-white text-xs transition-colors" title="<?= $node['archived'] ? 'Unarchive' : 'Archive' ?>"><?= $node['archived'] ? '↑' : '⌛' ?></button>
              </form>
              <?php if ($refCount === 0): ?>
                <form method="POST" action="action.php" class="inline" onsubmit="return confirm('Delete this skill?')">
                  <input type="hidden" name="action" value="delete_skill">
                  <input type="hidden" name="id" value="<?= $id ?>">
                  <button type="submit" class="w-7 h-7 rounded-full bg-slate-700 hover:bg-rose-600 text-slate-300 hover:text-white text-xs transition-colors" title="Delete">✕</button>
                </form>
              <?php else: ?>
                <button type="button" class="w-7 h-7 rounded-full bg-slate-700 text-slate-600 text-xs cursor-not-allowed" title="Referenced (<?= $taskCounts[$id] ?? 0 ?> tasks, <?= $stampCounts[$id] ?? 0 ?> completions) — archive instead">✕</button>
              <?php endif; ?>
            </div>
          </div>
          <?php if ($mins > 0): ?>
            <p class="text-xs text-slate-400 font-mono mt-2"><?= format_minutes($mins) ?><?= $isArchetype ? '' : ' · ' . ($stampCounts[$id] ?? 0) . ' sessions' ?></p>
          <?php else: ?>
            <p class="text-xs text-slate-600 font-mono mt-2">—</p>
          <?php endif; ?>
          <div class="mt-2 h-1.5 rounded-full bg-slate-700/60 overflow-hidden">
            <div class="h-full rounded-full transition-all duration-500" style="width: <?= (int)($sat * 100) ?>%; background: <?= $color ?>"></div>
          </div>
          <div class="node-input-slot"></div>
        </div>
      </div>
      <?php if (!empty($kids)): ?>
        <div class="connector h-4"></div>
        <?php foreach ($kids as $kid): render_skill_node($kid, false, $childrenOf, $minutes, $taskCounts, $stampCounts, $maxMinutes, $color, $path); endforeach; ?>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="skill-node drop-target <?= $rootClass ?>" draggable="true" data-id="<?= $id ?>" data-name="<?= $name ?>" data-archived="<?= $node['archived'] ? '1' : '0' ?>" data-referenced="<?= $refCount > 0 ? '1' : '0' ?>" data-multi-parent="<?= $multiParent ? '1' : '0' ?>">
      <div class="rounded-lg p-2.5 border-2 border-slate-700 bg-slate-800/80 hover:bg-slate-800 transition-colors" style="border-color: rgba(71,85,105,<?= $borderSat ?>);">
        <div class="flex items-center justify-between gap-2">
          <div class="flex items-center gap-2 min-w-0">
            <span class="w-2.5 h-2.5 rounded-full flex-shrink-0" style="background: <?= $color ?>; opacity: <?= 0.2 + 0.8 * $sat ?>"></span>
            <span class="skill-name text-sm font-semibold truncate <?= $node['archived'] ? 'line-through text-slate-500' : 'text-slate-200' ?>"><?= $name ?></span>
            <?= $multiParent ? '<span class="text-[9px] bg-indigo-900 text-indigo-300 px-1 rounded-full flex-shrink-0" title="Multiple parents">ⁿ</span>' : '' ?>
            <?= $node['archived'] ? '<span class="text-[9px] bg-slate-700 text-slate-400 px-1 rounded-full flex-shrink-0">arch</span>' : '' ?>
          </div>
          <div class="flex items-center gap-0.5 flex-shrink-0 skill-actions">
            <button type="button" class="node-add w-6 h-6 rounded-full bg-slate-700 hover:bg-accent-600 text-slate-400 hover:text-slate-950 text-xs font-bold transition-colors" title="Add child skill">+</button>
            <button type="button" class="node-rename w-6 h-6 rounded-full bg-slate-700 hover:bg-accent-600 text-slate-400 hover:text-slate-950 text-[10px] transition-colors" title="Rename">✎</button>
            <form method="POST" action="action.php" class="inline">
              <input type="hidden" name="action" value="save_skill">
              <input type="hidden" name="skill_id" value="<?= $id ?>">
              <input type="hidden" name="name" value="<?= $name ?>">
              <input type="hidden" name="archived" value="<?= $node['archived'] ? '' : '1' ?>">
              <button type="submit" class="w-6 h-6 rounded-full bg-slate-700 hover:bg-amber-600 text-slate-400 hover:text-white text-[10px] transition-colors" title="<?= $node['archived'] ? 'Unarchive' : 'Archive' ?>"><?= $node['archived'] ? '↑' : '⌛' ?></button>
            </form>
            <?php if ($refCount === 0): ?>
              <form method="POST" action="action.php" class="inline" onsubmit="return confirm('Delete this skill?')">
                <input type="hidden" name="action" value="delete_skill">
                <input type="hidden" name="id" value="<?= $id ?>">
                <button type="submit" class="w-6 h-6 rounded-full bg-slate-700 hover:bg-rose-600 text-slate-400 hover:text-white text-[10px] transition-colors" title="Delete">✕</button>
              </form>
            <?php else: ?>
              <button type="button" class="w-6 h-6 rounded-full bg-slate-700 text-slate-600 text-[10px] cursor-not-allowed" title="Referenced (<?= $taskCounts[$id] ?? 0 ?> tasks, <?= $stampCounts[$id] ?? 0 ?> completions) — archive instead">✕</button>
            <?php endif; ?>
          </div>
        </div>
        <?php if ($mins > 0): ?>
          <p class="text-[10px] text-slate-500 font-mono mt-1 ml-[18px]"><?= format_minutes($mins) ?> · <?= $stampCounts[$id] ?? 0 ?> sessions</p>
        <?php endif; ?>
        <div class="node-input-slot"></div>
      </div>
    </div>
    <?php if (!empty($kids)): ?>
      <div class="connector h-3 ml-[calc(13px)]"></div>
      <div class="space-y-2 pl-3 border-l-2 border-slate-700/60 ml-[6px]">
        <?php foreach ($kids as $kid): render_skill_node($kid, false, $childrenOf, $minutes, $taskCounts, $stampCounts, $maxMinutes, $color, $path); endforeach; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>
<?php }

// Renders the edit form: multi-parent checkbox picker over the whole tree.
function render_parent_checkboxes(array $all, ?array $editSkill) {
  // Simple indented list; every skill is checkable as a parent.
  $byParent = [];
  foreach ($all as $s) {
    foreach ($s['parentIds'] as $p) { $byParent[$p][] = $s; }
  }
  $sel = $editSkill ? $editSkill['parentIds'] : [];
  $walk = function($parentId, $depth) use (&$walk, $byParent, $all, $sel, $editSkill) {
    foreach ($byParent[$parentId] ?? [] as $s) {
      if ($editSkill && $s['id'] === $editSkill['id']) continue; // can't be its own parent
      $checked = in_array($s['id'], $sel, true) ? 'checked' : '';
      $prefix = str_repeat('— ', $depth);
      echo '<label class="flex items-center gap-1.5 text-xs text-slate-300"><input type="checkbox" name="parent_ids[]" value="' . $s['id'] . '" ' . $checked . ' class="w-3.5 h-3.5 rounded bg-slate-700 border-slate-600 text-accent-400 focus:ring-accent-400">' . htmlspecialchars($prefix . $s['name']) . '</label>';
      $walk($s['id'], $depth + 1);
    }
  };
  // Root-level skills first (those with no parents), then everything reachable.
  echo '<div class="space-y-1 max-h-48 overflow-y-auto p-2 bg-slate-900/60 rounded border border-slate-700">';
  $walk(null, 0);
  echo '</div>';
  echo '<p class="text-[10px] text-slate-500">Tick any number of parents (multi-parent skills appear under each). Leave all unticked for a top-level skill.</p>';
}
$editSkill = null;
if (isset($_GET['edit'])) {
  $editSkill = get_skill($user['id'], (int)$_GET['edit']);
  if (!$editSkill) redirect('skills.php');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>TaskFlow — Skills</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <?php render_fonts(); ?>
  <style>
    ::-webkit-scrollbar { width: 8px; }
    ::-webkit-scrollbar-track { background: #1c1c1b; }
    ::-webkit-scrollbar-thumb { background: #575753; border-radius: 4px; }
    .skill-node { position: relative; transition: transform .15s ease, box-shadow .15s ease; }
    .skill-node:hover { transform: translateY(-1px); }
    .skill-node.dragging { opacity: .35; }
    .drop-target.drag-over { outline: 2px dashed #c9a44a; outline-offset: 2px; }
    .node-input { outline: none; }
    .connector { width: 2px; background: linear-gradient(to bottom, #575753, #3a3a38); margin: 0 auto; }
    .skill-actions { opacity: 0; }
    .skill-node:hover .skill-actions { opacity: 1; }
  </style>
</head>
<body class="bg-slate-900 text-slate-100 font-sans min-h-screen">
<?php render_header('skills', $user); ?>
<main class="p-4 md:p-6 max-w-7xl mx-auto">
  <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
    <h1 class="text-3xl font-bold text-slate-100">Skill Tree</h1>
    <div class="flex flex-wrap gap-1.5">
      <?php
        $presets = ['all' => 'All', 'today' => 'Today', '7d' => '7d', '30d' => '30d', 'month' => 'Month', 'year' => 'Year'];
        foreach ($presets as $key => $label):
          $active = $range === $key ? 'bg-accent-500/90 text-slate-950' : 'bg-slate-700 text-slate-300 hover:bg-slate-600'; ?>
          <a href="skills.php?range=<?= $key ?>" class="px-2.5 py-1 rounded-full text-xs font-medium transition-colors <?= $active ?>"><?= $label ?></a>
        <?php endforeach; ?>
    </div>
  </div>
  <p class="text-xs text-slate-500 mb-6">Showing: <span class="text-accent-400 font-semibold"><?= htmlspecialchars($rangeLabel) ?></span> · node colour deepens with time invested · ⁿ marks multi-parent skills</p>

  <?php if (empty($skills)): ?>
    <div class="text-center p-8 bg-slate-800 rounded-lg border border-slate-700 text-slate-400">
      Run the skills migration to use this page.
    </div>
  <?php else: ?>

  <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4">
    <?php
    foreach ($roots as $root):
      $rootColor = SKILL_ROOT_COLORS[$root['name']] ?? '#7a7a76';
      render_skill_node($root, true, $childrenOf, $skillMinutes, $taskCounts, $stampCounts, $maxMinutes, $rootColor, []);
    endforeach;
    ?>
  </div>

  <p class="text-xs text-slate-500 mt-6">Drag a node onto another to <strong>replace</strong> its parents with that node · drop on the page background to make it top-level · use the full editor (a node's ✎ → Full Edit) for multi-parenting.</p>

  <?php endif; ?>
</main>
<script>
(function () {
  let dragId = null;

  document.querySelectorAll('.skill-node').forEach(node => {
    node.addEventListener('dragstart', e => {
      dragId = node.dataset.id;
      node.classList.add('dragging');
      e.dataTransfer.effectAllowed = 'move';
      e.dataTransfer.setData('text/plain', node.dataset.id);
    });
    node.addEventListener('dragend', () => {
      node.classList.remove('dragging');
      document.querySelectorAll('.drag-over').forEach(el => el.classList.remove('drag-over'));
      dragId = null;
    });
    node.addEventListener('dragover', e => {
      e.preventDefault();
      if (dragId && dragId !== node.dataset.id) node.classList.add('drag-over');
    });
    node.addEventListener('dragleave', () => node.classList.remove('drag-over'));
    node.addEventListener('drop', e => {
      e.preventDefault();
      node.classList.remove('drag-over');
      if (dragId && dragId !== node.dataset.id) {
        reparent(dragId, node.dataset.id); // drag = replace parents
      }
    });
  });

  function reparent(id, parentId) {
    const fd = new FormData();
    fd.append('action', 'set_skill_parent');
    fd.append('id', id);
    fd.append('parent_id', parentId);
    fetch('action.php', { method: 'POST', body: fd }).then(() => window.location.reload());
  }

  // Inline add-child: + button inserts a form under the node.
  document.querySelectorAll('.node-add').forEach(btn => {
    btn.addEventListener('click', e => {
      e.preventDefault();
      const node = btn.closest('.skill-node');
      const slot = node.querySelector('.node-input-slot');
      if (slot.querySelector('form')) { slot.innerHTML = ''; return; }
      const parentId = node.dataset.id;
      const f = document.createElement('form');
      f.method = 'POST';
      f.action = 'action.php';
      f.className = 'mt-2 flex gap-1.5';
      f.innerHTML =
        '<input type="hidden" name="action" value="save_skill">' +
        '<input type="hidden" name="parent_ids[]" value="' + parentId + '">' +
        '<input type="text" name="name" required autofocus placeholder="New skill..." class="node-input flex-1 p-1.5 bg-slate-900 border border-accent-500 rounded text-xs text-slate-100">' +
        '<button type="submit" class="px-2 bg-accent-400 hover:bg-accent-300 text-slate-950 text-xs font-bold rounded transition-colors">Add</button>';
      slot.innerHTML = '';
      slot.appendChild(f);
      f.querySelector('input[name=name]').focus();
    });
  });

  // Inline rename: pencil opens a quick form; also offers Full Edit for parents.
  document.querySelectorAll('.node-rename').forEach(btn => {
    btn.addEventListener('click', e => {
      e.preventDefault();
      const node = btn.closest('.skill-node');
      const slot = node.querySelector('.node-input-slot');
      if (slot.querySelector('form')) { slot.innerHTML = ''; return; }
      const id = node.dataset.id;
      const isArch = node.dataset.archived === '1';
      const isMulti = node.dataset.multiParent === '1';
      const f = document.createElement('form');
      f.method = 'POST';
      f.action = 'action.php';
      f.className = 'mt-2 space-y-1.5';
      f.innerHTML =
        '<input type="hidden" name="action" value="save_skill">' +
        '<input type="hidden" name="skill_id" value="' + id + '">' +
        (isArch ? '<input type="hidden" name="archived" value="1">' : '') +
        '<div class="flex gap-1.5">' +
        '<input type="text" name="name" required class="node-input flex-1 p-1.5 bg-slate-900 border border-accent-500 rounded text-xs text-slate-100" value="">' +
        '<button type="submit" class="px-2 bg-accent-400 hover:bg-accent-300 text-slate-950 text-xs font-bold rounded transition-colors">Save</button>' +
        '</div>' +
        (isMulti
          ? '<p class="text-[10px] text-indigo-300">This skill has multiple parents — renaming here keeps them. Use Full Edit to change parents.</p>'
          : '');
      // No parent_ids field => save_skill leaves parents untouched (partial edit semantics).
      slot.innerHTML = '';
      slot.appendChild(f);
      const inp = f.querySelector('input[name=name]');
      inp.value = node.dataset.name;
      inp.focus();
      inp.select();
    });
  });

  // Escape cancels any open inline form.
  document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
      document.querySelectorAll('.node-input-slot form').forEach(f => f.remove());
    }
  });
})();
</script>
</body>
</html>