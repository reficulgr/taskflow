<?php
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/api_auth.php';
$user = require_login();

$generatedToken = null;
$hasToken = false;
$tokenSetAt = null;

// Check current token state
$stmt = db()->prepare('SELECT api_token_hash, api_token_set_at FROM users WHERE id = ?');
$stmt->execute([$user['id']]);
$row = $stmt->fetch();
if ($row) {
  $hasToken = !empty($row['api_token_hash']);
  $tokenSetAt = $row['api_token_set_at'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate') {
  $token = api_generate_token();
  $hash = api_token_hash($token);
  $stmt = db()->prepare('UPDATE users SET api_token_hash = ?, api_token_set_at = NOW() WHERE id = ?');
  $stmt->execute([$hash, $user['id']]);
  $generatedToken = $token;
  $hasToken = true;
  $tokenSetAt = date('c');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>TaskFlow — API Token</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <?php render_fonts(); ?>
</head>
<body class="bg-slate-900 text-slate-100 font-sans min-h-screen">
<?php render_header('settings', $user); ?>
<main class="p-4 md:p-6 max-w-2xl mx-auto">
  <h1 class="text-3xl font-bold text-slate-100 mb-2">API Token</h1>
  <p class="text-slate-400 mb-6">
    Use this token to let external tools (scripts, the opencode MCP server) talk to your TaskFlow on your behalf.
    Treat it like a password — anyone with it can act as you.
    The token is stored in the database only as a hash; you see the raw value <strong>once</strong> when you generate it.
  </p>

  <?php if ($generatedToken): ?>
    <div class="bg-emerald-900/40 border border-emerald-500/40 rounded-lg p-6 mb-6">
      <h2 class="text-xl font-bold text-emerald-300 mb-3">Your new API token — copy it now</h2>
      <p class="text-emerald-200 text-sm mb-3">You will not be shown this again. Store it somewhere safe (e.g. your password manager).</p>
      <div class="flex items-stretch gap-2">
        <input id="tokenBox" type="text" readonly value="<?= htmlspecialchars($generatedToken) ?>"
          class="flex-1 p-3 bg-slate-950 border border-slate-700 rounded-md font-mono text-sm text-emerald-200 break-all">
        <button type="button" onclick="(function(){var e=document.getElementById('tokenBox');e.select();document.execCommand('copy');})()"
          class="px-4 bg-accent-400 hover:bg-accent-300 text-slate-950 rounded-md font-bold transition">Copy</button>
      </div>
    </div>
  <?php endif; ?>

  <div class="bg-slate-800 rounded-lg shadow-lg border border-slate-700 p-6 mb-6">
    <h2 class="text-2xl font-bold text-slate-200 mb-4">Current token</h2>
    <?php if ($hasToken): ?>
      <p class="text-slate-300 mb-1">A token is set<?= $tokenSetAt ? ' <span class="text-slate-400 text-sm">(' . htmlspecialchars($tokenSetAt) . ')</span>' : '' ?>.</p>
      <p class="text-slate-400 text-sm mb-4">For security the raw value is not retrievable. Generating a new one will revoke the old token immediately.</p>
    <?php else: ?>
      <p class="text-slate-300 mb-4">No API token set yet.</p>
    <?php endif; ?>
    <form method="POST" onsubmit="return confirm('Generate a new token? This revokes any existing token immediately.');">
      <input type="hidden" name="action" value="generate">
      <button type="submit" class="px-5 py-2 bg-accent-400 hover:bg-accent-300 text-slate-950 rounded-md font-bold transition">
        <?= $hasToken ? 'Generate new token (revokes old)' : 'Generate token' ?>
      </button>
    </form>
  </div>

  <div class="bg-slate-800 rounded-lg shadow-lg border border-slate-700 p-6">
    <h2 class="text-2xl font-bold text-slate-200 mb-3">How to use it</h2>
    <p class="text-slate-300 text-sm mb-2">Send it as a Bearer token in the <code class="text-accent-300">Authorization</code> header:</p>
    <pre class="bg-slate-950 border border-slate-700 rounded-md p-3 text-xs text-slate-300 overflow-x-auto">curl -H "Authorization: Bearer YOUR_TOKEN" \
  https://symbolonbranding.co.uk/taskflow/api.php/next</pre>
    <p class="text-slate-400 text-xs mt-3">API base URL: <code class="text-accent-300">https://symbolonbranding.co.uk/taskflow/api.php</code></p>
  </div>

  <p class="mt-6"><a href="settings.php" class="text-accent-400 hover:text-accent-300">← Back to Settings</a></p>
</main>
</body>
</html>