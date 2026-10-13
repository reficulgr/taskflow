<?php
require_once __DIR__ . '/functions.php';
$user = require_login();
$tasks = select_top_three($user['id']);

// Check for active timer
$activeTimer = null;
$timer = get_active_timer($user['id']);
if ($timer) {
  if ($timer['endTime'] > time()) {
    $activeTimer = $timer;
  } else {
    // Timer expired — auto-complete
    $task = get_task($user['id'], $timer['taskId']);
    if ($task) {
      complete_task($user['id'], $task, null, $timer['startAt'], $timer['endAt']);
    }
    clear_active_timer($user['id']);
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>TaskFlow — Next Up</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <?php render_fonts(); ?>
  <style>
    ::-webkit-scrollbar { width: 8px; }
    ::-webkit-scrollbar-track { background: #1c1c1b; }
    ::-webkit-scrollbar-thumb { background: #575753; border-radius: 4px; }
    @keyframes fade-in-up { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
    .animate-fade-in-up { animation: fade-in-up 0.3s ease-out; }
  </style>
</head>
<body class="bg-slate-900 text-slate-100 font-sans min-h-screen <?= $activeTimer ? 'pb-20' : '' ?>">
<?php render_header('index', $user); ?>
<main>
  <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 lg:gap-8 p-4 md:p-6">
    <div class="lg:col-span-2">
      <h1 class="text-3xl font-bold text-slate-100 mb-6">What's Next?</h1>
      <?php if (!empty($tasks)): ?>
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6">
          <?php foreach ($tasks as $task): ?>
            <?php
              $color = CAT_COLORS[$task['category']] ?? 'bg-gray-500';
              $text = CAT_TEXT[$task['category']] ?? 'text-gray-400';
            ?>
            <div class="bg-slate-800 rounded-lg shadow-xl flex flex-col justify-between border border-slate-700 h-full relative">
              <div class="p-5 flex-grow">
                <div class="flex justify-between items-start mb-2">
                  <div class="flex items-center gap-2">
                    <div class="<?= $color ?> text-white text-xs font-bold px-2.5 py-1 rounded-full"><?= $task['duration'] ?> min</div>
                    <?php if (!empty($task['pinned'])): ?>
                      <span class="text-amber-400" title="Pinned"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 inline"><path stroke-linecap="round" stroke-linejoin="round" d="M16 12v4.5M9.75 8.25 12 21l2.25-12.75M9.75 8.25 6 4.5h12l-3.75 3.75M9.75 8.25h4.5"/></svg></span>
                    <?php endif; ?>
                  </div>
                  <div class="font-semibold text-sm <?= $text ?>"><?= htmlspecialchars($task['category']) ?></div>
                </div>
                <h3 class="text-xl font-bold text-slate-100 my-3"><?= htmlspecialchars($task['name']) ?></h3>
                <p class="text-sm text-slate-400"><?= task_detail($task) ?></p>
                <?php if ($task['note']): ?>
                  <p class="text-sm text-slate-400 mt-2 italic whitespace-pre-wrap border-l-2 border-slate-600 pl-2"><?= htmlspecialchars($task['note']) ?></p>
                <?php endif; ?>
              </div>
              <div class="grid grid-cols-2">
                <form method="POST" action="action.php" class="skip-form relative" data-task="<?= htmlspecialchars($task['name']) ?>">
                  <input type="hidden" name="action" value="skip">
                  <input type="hidden" name="task_id" value="<?= $task['id'] ?>">
                  <input type="hidden" name="skip_duration" class="skip-duration" value="15min">
                  <button type="submit" <?= $activeTimer ? 'disabled' : '' ?> class="w-full bg-slate-700 hover:bg-slate-600 transition-colors p-3 text-slate-300 font-semibold disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center gap-1">
                    <span>Skip</span>
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 skip-arrow"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5"/></svg>
                  </button>
                  <div class="skip-menu hidden absolute bg-slate-700 border border-slate-600 rounded-md shadow-xl z-20 min-w-full left-0 mt-1">
                    <button type="button" data-duration="15min" class="skip-option block w-full text-left px-3 py-2 text-sm text-slate-300 hover:bg-slate-600">15 minutes</button>
                    <button type="button" data-duration="1hour" class="skip-option block w-full text-left px-3 py-2 text-sm text-slate-300 hover:bg-slate-600">1 hour</button>
                    <button type="button" data-duration="today" class="skip-option block w-full text-left px-3 py-2 text-sm text-slate-300 hover:bg-slate-600">Until tomorrow</button>
                    <button type="button" data-duration="tomorrow" class="skip-option block w-full text-left px-3 py-2 text-sm text-slate-300 hover:bg-slate-600">Until day after</button>
                  </div>
                </form>
                <form method="POST" action="action.php">
                  <input type="hidden" name="action" value="start">
                  <input type="hidden" name="task_id" value="<?= $task['id'] ?>">
                  <button type="submit" <?= $activeTimer ? 'disabled' : '' ?> class="flex items-center justify-center gap-2 w-full bg-accent-400 hover:bg-accent-300 transition-colors p-4 font-bold text-slate-950 disabled:bg-accent-700 disabled:text-slate-400 disabled:cursor-not-allowed">Start</button>
                </form>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <div class="flex flex-col items-center justify-center bg-slate-800 rounded-lg p-10 h-64 border border-slate-700">
          <h2 class="text-2xl font-bold text-slate-300">All Clear!</h2>
          <p class="text-slate-400 mt-2">No eligible tasks right now. Maybe add one?</p>
        </div>
      <?php endif; ?>
    </div>
    <div class="lg:col-span-1">
      <?php render_budget_sidebar($user['id']); ?>
    </div>
  </div>
</main>
<?php if ($activeTimer): $remaining = $activeTimer['endTime'] - time(); ?>
  <div id="timer-footer" class="fixed bottom-0 left-0 right-0 bg-slate-800 border-t border-slate-700 shadow-2xl z-50 animate-fade-in-up" data-end="<?= $activeTimer['endTime'] ?>" data-duration="<?= $activeTimer['duration'] ?>">
    <div class="w-full bg-slate-700"><div class="timer-bar h-2 transition-all duration-1000" style="width:0%"></div></div>
    <div class="container mx-auto px-4 sm:px-6 lg:px-8 flex items-center justify-between h-14">
      <p class="font-bold text-white truncate"><?= htmlspecialchars($activeTimer['taskName']) ?></p>
      <div class="flex items-center gap-4 flex-shrink-0">
        <span class="timer-display text-2xl font-bold font-mono text-white"><?= gmdate('i:s', max(0, $remaining)) ?></span>
        <form method="POST" action="action.php">
          <input type="hidden" name="action" value="force_complete">
          <button type="submit" class="flex items-center justify-center bg-emerald-600 hover:bg-emerald-500 transition-colors p-2 rounded-full font-bold text-white">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>
          </button>
        </form>
      </div>
    </div>
  </div>
  <script src="timer.js"></script>
  <script>startTimer(<?= $activeTimer['endTime'] ?>, <?= $activeTimer['duration'] ?>);</script>
<?php endif; ?>
<script>
document.addEventListener('click', (e) => {
  document.querySelectorAll('.skip-form').forEach(f => {
    const menu = f.querySelector('.skip-menu');
    const arrow = f.querySelector('.skip-arrow');
    if (!f.contains(e.target)) {
      menu.classList.add('hidden');
      arrow.style.transform = '';
    }
  });
});
document.querySelectorAll('.skip-form').forEach(form => {
  const btn = form.querySelector('button[type="submit"]');
  const menu = form.querySelector('.skip-menu');
  const arrow = form.querySelector('.skip-arrow');
  const durationInput = form.querySelector('.skip-duration');
  btn.addEventListener('click', (e) => {
    if (btn.disabled) return;
    e.preventDefault();
    const isOpen = !menu.classList.contains('hidden');
    document.querySelectorAll('.skip-menu').forEach(m => m.classList.add('hidden'));
    if (isOpen) { menu.classList.add('hidden'); arrow.style.transform = ''; }
    else { menu.classList.remove('hidden'); arrow.style.transform = 'rotate(180deg)'; }
  });
  form.querySelectorAll('.skip-option').forEach(opt => {
    opt.addEventListener('click', () => {
      durationInput.value = opt.dataset.duration;
      menu.classList.add('hidden');
      arrow.style.transform = '';
      form.submit();
    });
  });
});
</script>
</body>
</html>