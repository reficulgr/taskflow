<?php
// API authentication: Bearer token (sha256-hashed in the DB).
// Used by api.php instead of the browser-session require_login().

require_once __DIR__ . '/db.php';

function api_token_hash(string $token): string {
  return hash('sha256', $token);
}

function api_generate_token(): string {
  // 32 random bytes -> 64 hex chars. Return raw hex (this is the token the
  // user copies once). We store only its sha256 hash in the DB.
  return bin2hex(random_bytes(32));
}

function api_bearer_token(): ?string {
  $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
  if ($header === '' && function_exists('getallheaders')) {
    $all = getallheaders();
    foreach ($all as $k => $v) {
      if (strcasecmp($k, 'Authorization') === 0) { $header = $v; break; }
    }
  }
  if (preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
    return trim($m[1]);
  }
  // Query-string fallback (handy for quick GET tests in a browser).
  if (!empty($_GET['token'])) return trim($_GET['token']);
  return null;
}

function api_authenticate(): ?array {
  $token = api_bearer_token();
  if (!$token) return null;
  $hash = api_token_hash($token);
  $stmt = db()->prepare('SELECT id, username FROM users WHERE api_token_hash = ?');
  $stmt->execute([$hash]);
  $row = $stmt->fetch();
  if (!$row) return null;
  return ['id' => (int)$row['id'], 'username' => $row['username']];
}

function api_require_user(): array {
  $user = api_authenticate();
  if (!$user) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized: missing or invalid API token']);
    exit;
  }
  return $user;
}

function api_send(mixed $data, int $code = 200): void {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  exit;
}