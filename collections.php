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

$byParent = [];
foreach ($collections as $c) { $byParent[$c['parentId']][] = $c; }
function render_tree($parentId, $byParent, $depth = 0) {
  $children = $byParent[$parentId] ?? [];
  foreach ($children as $c):
    $typeColor = $c['type'] === 'Initiative' ? 'text-sky-400' : 'text-violet-400';
    $typeBg = $c['type'] === 'Initiative' ? 'bg-sky-600' : 'bg-violet-600';
  ?>
    <div class="collection-row group" draggable="true" data-id="<?= $c['id'] ?>">
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
    <h1 class="text-3xl font-bold text-slate-100">Collections</h1>
  </section>
</main>
<script>
const rows = document.querySelectorAll('.collection-row');
let dragId = null;

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