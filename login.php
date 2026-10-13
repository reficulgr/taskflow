<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

if (is_logged_in()) { redirect('index.php'); }

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $username = trim($_POST['username'] ?? '');
  $password = $_POST['password'] ?? '';
  $action = $_POST['action'] ?? 'login';

  if ($username === '' || $password === '') {
    $error = 'Username and password are required.';
  } elseif ($action === 'register') {
    $stmt = db()->prepare('SELECT id FROM users WHERE username = ?');
    $stmt->execute([$username]);
    if ($stmt->fetch()) {
      $error = 'Username already taken.';
    } else {
      $stmt = db()->prepare('INSERT INTO users (username, password_hash) VALUES (?, ?)');
      $stmt->execute([$username, password_hash($password, PASSWORD_BCRYPT)]);
      $_SESSION['user_id'] = (int)db()->lastInsertId();
      $_SESSION['username'] = $username;
      redirect('index.php');
    }
  } else {
    $stmt = db()->prepare('SELECT id, password_hash FROM users WHERE username = ?');
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    if (!$user || !password_verify($password, $user['password_hash'])) {
      $error = 'Invalid credentials.';
    } else {
      $_SESSION['user_id'] = (int)$user['id'];
      $_SESSION['username'] = $username;
      redirect('index.php');
    }
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>TaskFlow — Sign In</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <?php render_fonts(); ?>
</head>
<body class="bg-slate-900 text-slate-100 font-sans min-h-screen flex items-center justify-center p-4">
  <div class="bg-slate-800 rounded-lg shadow-xl p-8 w-full max-w-sm">
    <h1 class="text-3xl font-bold text-white mb-2 text-center font-condensed">TaskFlow</h1>
    <p class="text-slate-400 text-sm text-center mb-6">Sign in or create an account</p>
    <?php if ($error): ?>
      <div class="bg-red-500/20 border border-red-500/30 text-red-400 text-sm rounded-lg p-3 mb-4"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <form method="POST" class="space-y-4">
      <div>
        <label class="block text-sm font-medium text-slate-300 mb-1">Username</label>
        <input type="text" name="username" required class="w-full p-2 bg-slate-700 border border-slate-600 rounded-md focus:ring-2 focus:ring-accent-400 focus:outline-none">
      </div>
      <div>
        <label class="block text-sm font-medium text-slate-300 mb-1">Password</label>
        <input type="password" name="password" required class="w-full p-2 bg-slate-700 border border-slate-600 rounded-md focus:ring-2 focus:ring-accent-400 focus:outline-none">
      </div>
      <button type="submit" name="action" value="login" class="w-full py-2 bg-accent-400 hover:bg-accent-300 text-slate-950 rounded-md font-bold transition">Sign In</button>
      <button type="submit" name="action" value="register" class="w-full py-2 bg-slate-700 hover:bg-slate-600 rounded-md font-semibold text-slate-300 transition">Create Account</button>
    </form>
  </div>
</body>
</html>