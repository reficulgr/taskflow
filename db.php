<?php
date_default_timezone_set('Europe/Athens');
$sessionLifetime = 60 * 60 * 24 * 30; // 30 days

// Use a private session save path so other apps' garbage collectors
// on shared hosting can't purge our sessions before their time.
$sessionPath = __DIR__ . '/sessions';
if (!is_dir($sessionPath)) {
    @mkdir($sessionPath, 0700, true);
}
if (!file_exists($sessionPath . '/.htaccess')) {
    @file_put_contents($sessionPath . '/.htaccess', "Require all denied\nDeny from all\n");
}

session_save_path($sessionPath);
ini_set('session.cookie_lifetime', $sessionLifetime);
ini_set('session.gc_maxlifetime', $sessionLifetime);
ini_set('session.gc_probability', 1);
ini_set('session.gc_divisor', 1000);
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.use_strict_mode', 1);
session_start();

// DB credentials live in config.php (gitignored); see config.example.php.
if (is_file(__DIR__ . '/config.php')) {
  require __DIR__ . '/config.php';
} else {
  require __DIR__ . '/config.example.php';
}

function db(): PDO {
  static $pdo = null;
  if ($pdo === null) {
    $pdo = new PDO(
      "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
      DB_USER, DB_PASS,
      [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
  }
  return $pdo;
}

function require_login(): array {
  if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
  }
  return ['id' => $_SESSION['user_id'], 'username' => $_SESSION['username']];
}

function is_logged_in(): bool {
  return isset($_SESSION['user_id']);
}

function redirect(string $page): void {
  header("Location: $page");
  exit;
}