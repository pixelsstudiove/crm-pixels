<?php
// config/db.php
declare(strict_types=1);

require_once __DIR__ . '/app.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
  $sessionDir = __DIR__ . '/../storage/sessions';
  if (!is_dir($sessionDir)) {
    @mkdir($sessionDir, 0770, true);
  }
  if (is_dir($sessionDir) && is_writable($sessionDir)) {
    session_save_path($sessionDir);
  }

  ini_set('session.gc_maxlifetime', '14400');
  ini_set('session.cookie_httponly', '1');
  ini_set('session.use_strict_mode', '1');
  $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
  if ($isHttps) {
    ini_set('session.cookie_secure', '1');
  }

  session_name((string) app_config('session.name', 'pixels_lead_capture_sess'));
  session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
  ]);
  session_start();
}

$env_is_local = in_array($_SERVER['SERVER_NAME'] ?? '', ['localhost', '127.0.0.1', '::1'], true);

// Configura estos datos según tu hosting o usa variables de entorno.
$DB_HOST = (string) env_value('DB_HOST', 'localhost');
$DB_PORT = (string) env_value('DB_PORT', $env_is_local ? '8889' : '3306');
$DB_NAME = (string) env_value('DB_NAME', 'pixelstudio');
$DB_USER = (string) env_value('DB_USER', $env_is_local ? 'root' : '');
$DB_PASS = (string) env_value('DB_PASS', $env_is_local ? 'hola123' : '');
$DB_CHARSET = 'utf8mb4';

$TABLE_LEADS = safe_identifier((string) app_config('database.leads_table', 'leads'), 'leads');
$TABLE_USERS = safe_identifier((string) app_config('database.users_table', 'users'), 'users');

$DSN = "mysql:host={$DB_HOST};port={$DB_PORT};dbname={$DB_NAME};charset={$DB_CHARSET}";

try {
  $pdo = new PDO($DSN, $DB_USER, $DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_PERSISTENT => false,
  ]);
  $pdo->exec("SET time_zone = '+00:00'");
} catch (Throwable $e) {
  if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
  http_response_code(500);
  echo json_encode([
    'ok' => false,
    'errors' => ['Error de conexión a la base de datos.'],
    'detail' => $env_is_local ? $e->getMessage() : 'Contacte al administrador.',
  ], JSON_UNESCAPED_UNICODE);
  exit;
}
