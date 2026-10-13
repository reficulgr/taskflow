<?php
require_once __DIR__ . '/functions.php';
$user = require_login();
$taskId = $_SESSION['pending_completion'] ?? null;
if (!$taskId) { redirect('index.php'); }
$task = get_task($user['id'], $taskId);
if (!$task) { unset($_SESSION['pending_completion']); redirect('index.php'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>TaskFlow — Complete Task</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <?php render_fonts(); ?>
</head>
<body class="bg-slate-900 text-slate-100 font-sans min-h-screen flex items-center justify-center p-4">
  <div class="bg-slate-800 rounded-lg shadow-xl p-6 w-full max-w-md">
    <h2 class="text-xl font-bold text-white mb-2">Complete Task</h2>
    <p class="text-slate-300 mb-4">Add a note for <strong><?= htmlspecialchars($task['name']) ?></strong>:</p>
    <form method="POST" action="action.php" class="space-y-4">
      <input type="hidden" name="action" value="complete_with_note">
      <input type="hidden" name="task_id" value="<?= $task['id'] ?>">
      <div>
        <textarea name="note" rows="3" placeholder="e.g., Read Chapter 5, Watched Blade Runner" autofocus class="w-full p-2 bg-slate-700 border border-slate-600 rounded-md focus:ring-2 focus:ring-accent-400 focus:outline-none"></textarea>
      </div>
      <div class="flex justify-end gap-4">
        <button type="button" onclick="window.location.href='action.php'" formaction="action.php" name="action" value="cancel_completion" class="px-4 py-2 bg-slate-600 hover:bg-slate-500 rounded-md font-semibold">Cancel</button>
        <button type="submit" class="px-6 py-2 bg-accent-400 hover:bg-accent-300 text-slate-950 rounded-md font-bold">Save Completion</button>
      </div>
    </form>
  </div>
</body>
</html>