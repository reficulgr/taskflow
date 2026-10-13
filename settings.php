<?php
require_once __DIR__ . '/functions.php';
$user = require_login();
$budgets = get_budgets($user['id']);
$pebbleIncrement = get_pebble_increment($user['id']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (($_POST['form'] ?? '') === 'budgets') {
    foreach (CATEGORIES as $cat) {
      $wd = (int)($_POST[$cat . '_weekday'] ?? 0);
      $we = (int)($_POST[$cat . '_weekend'] ?? 0);
      $stmt = db()->prepare('INSERT INTO budgets (user_id, category, weekday_budget, weekend_budget) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE weekday_budget=VALUES(weekday_budget), weekend_budget=VALUES(weekend_budget)');
      $stmt->execute([$user['id'], $cat, $wd, $we]);
    }
    $budgets = get_budgets($user['id']);
  }
  if (($_POST['form'] ?? '') === 'report') {
    $inc = (int)($_POST['pebble_increment'] ?? 30);
    save_pebble_increment($user['id'], $inc);
    $pebbleIncrement = get_pebble_increment($user['id']);
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>TaskFlow — Settings</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <?php render_fonts(); ?>
  <style>
    ::-webkit-scrollbar { width: 8px; }
    ::-webkit-scrollbar-track { background: #1c1c1b; }
    ::-webkit-scrollbar-thumb { background: #575753; border-radius: 4px; }
  </style>
</head>
<body class="bg-slate-900 text-slate-100 font-sans min-h-screen">
<?php render_header('settings', $user); ?>
<main class="p-4 md:p-6 max-w-4xl mx-auto">
  <h1 class="text-3xl font-bold text-slate-100 mb-6">Settings</h1>

  <div class="bg-slate-800 rounded-lg shadow-lg border border-slate-700 p-6 mb-8">
    <h2 class="text-2xl font-bold text-slate-200 mb-4">Account</h2>
    <div class="flex items-center justify-between">
      <span class="text-slate-300">Signed in as <strong><?= htmlspecialchars($user['username']) ?></strong></span>
      <form method="POST" action="action.php">
        <input type="hidden" name="action" value="logout">
        <button type="submit" class="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-slate-300 rounded-md font-semibold transition">Sign Out</button>
      </form>
    </div>
  </div>

  <div class="bg-slate-800 rounded-lg shadow-lg border border-slate-700 p-6 mb-8">
    <h2 class="text-2xl font-bold text-slate-200 mb-4">API</h2>
    <div class="flex items-center justify-between">
      <span class="text-slate-300">Let external tools (scripts, the opencode MCP server) act on your behalf via a token.</span>
      <a href="api_token.php" class="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-slate-300 rounded-md font-semibold transition">Manage API Token</a>
    </div>
  </div>

  <div class="bg-slate-800 rounded-lg shadow-lg border border-slate-700 p-6 mb-8">
    <h2 class="text-2xl font-bold text-slate-200 mb-4">Report</h2>
    <form method="POST" class="flex items-center justify-between gap-4 flex-wrap">
      <input type="hidden" name="form" value="report">
      <label for="pebble_increment" class="text-slate-300">Pebble size (minutes of completed time per pebble)</label>
      <div class="flex items-center gap-3">
        <select name="pebble_increment" id="pebble_increment" class="p-2 bg-slate-700 border border-slate-600 rounded-md text-slate-100 focus:ring-2 focus:ring-accent-400 focus:outline-none">
          <?php foreach (PEBBLE_INCREMENTS as $inc): ?>
            <option value="<?= $inc ?>" <?= $pebbleIncrement === $inc ? 'selected' : '' ?>><?= $inc ?> min</option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="px-6 py-2 bg-accent-400 hover:bg-accent-300 text-slate-950 rounded-md font-bold">Save</button>
      </div>
    </form>
  </div>

  <div class="bg-slate-800 rounded-lg shadow-lg border border-slate-700 p-6 mb-8">
    <h2 class="text-2xl font-bold text-slate-200 mb-4">Time Budgets</h2>
    <form method="POST" class="space-y-6">
      <input type="hidden" name="form" value="budgets">
      <div class="grid grid-cols-3 gap-4 font-bold text-slate-400 border-b border-slate-600 pb-2">
        <div>Category</div>
        <div class="text-center">Weekday (min)</div>
        <div class="text-center">Weekend (min)</div>
      </div>
      <?php foreach ($budgets as $b): $text = CAT_TEXT[$b['category']] ?? 'text-gray-400'; ?>
        <div class="grid grid-cols-3 gap-4 items-center">
          <div class="font-semibold <?= $text ?>"><?= $b['category'] ?></div>
          <input type="number" min="0" step="15" name="<?= htmlspecialchars($b['category']) ?>_weekday" value="<?= $b['weekdayBudget'] ?>" class="w-full p-2 bg-slate-700 border border-slate-600 rounded-md focus:ring-2 focus:ring-accent-400 focus:outline-none text-right font-mono">
          <input type="number" min="0" step="15" name="<?= htmlspecialchars($b['category']) ?>_weekend" value="<?= $b['weekendBudget'] ?>" class="w-full p-2 bg-slate-700 border border-slate-600 rounded-md focus:ring-2 focus:ring-accent-400 focus:outline-none text-right font-mono">
        </div>
      <?php endforeach; ?>
      <button type="submit" class="px-6 py-2 bg-accent-400 hover:bg-accent-300 text-slate-950 rounded-md font-bold">Save Budgets</button>
    </form>
  </div>
</main>
</body>
</html>