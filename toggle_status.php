<?php
// toggle_status.php
declare(strict_types=1);
require_once __DIR__ . '/auth/require_auth.php';
header('Content-Type: application/json; charset=utf-8');

$csrf = $_POST['csrf'] ?? '';
if (!$csrf || !isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], (string) $csrf)) {
  http_response_code(403);
  echo json_encode(['ok' => false, 'error' => 'CSRF inválido'], JSON_UNESCAPED_UNICODE); exit;
}

$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
$status = isset($_POST['status']) ? trim((string) $_POST['status']) : 'pending';
$allowed = ['pending', 'completed'];

if ($id <= 0 || !in_array($status, $allowed, true)) {
  http_response_code(422);
  echo json_encode(['ok' => false, 'error' => 'Datos inválidos'], JSON_UNESCAPED_UNICODE); exit;
}

try {
  $chk = $pdo->prepare('SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?');
  $chk->execute([$DB_NAME, $TABLE_LEADS, 'status']);
  if (!$chk->fetch()) {
    $pdo->exec("ALTER TABLE {$TABLE_LEADS} ADD COLUMN status VARCHAR(32) NOT NULL DEFAULT 'pending'");
  }
} catch (Throwable $e) { /* ignore */ }

try {
  $upd = $pdo->prepare("UPDATE {$TABLE_LEADS} SET status=? WHERE id=?");
  $upd->execute([$status, $id]);
  echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'No se pudo actualizar'], JSON_UNESCAPED_UNICODE);
}
