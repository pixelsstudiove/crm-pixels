<?php
// account_update.php
declare(strict_types=1);
require_once __DIR__ . '/auth/require_auth.php';
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok' => false, 'error' => 'Método no permitido'], JSON_UNESCAPED_UNICODE); exit;
}

$csrf = $_POST['csrf'] ?? '';
if (!$csrf || !isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], (string) $csrf)) {
  http_response_code(403);
  echo json_encode(['ok' => false, 'error' => 'CSRF inválido'], JSON_UNESCAPED_UNICODE); exit;
}

$currentpass = (string) ($_POST['currentpass'] ?? '');
$newpass = (string) ($_POST['newpass'] ?? '');
$confirm = (string) ($_POST['confirm'] ?? '');
if (strlen($currentpass) < 8) {
  http_response_code(422);
  echo json_encode(['ok' => false, 'error' => 'Ingresa tu contraseña actual.'], JSON_UNESCAPED_UNICODE); exit;
}
if (strlen($newpass) < 8) {
  http_response_code(422);
  echo json_encode(['ok' => false, 'error' => 'La contraseña debe tener al menos 8 caracteres.'], JSON_UNESCAPED_UNICODE); exit;
}
if ($newpass !== $confirm) {
  http_response_code(422);
  echo json_encode(['ok' => false, 'error' => 'Las contraseñas no coinciden.'], JSON_UNESCAPED_UNICODE); exit;
}

try {
  $currentStmt = $pdo->prepare("SELECT password_hash FROM {$TABLE_USERS} WHERE id=? LIMIT 1");
  $currentStmt->execute([(int) $_SESSION['user_id']]);
  $currentRow = $currentStmt->fetch();
  if (!$currentRow || !password_verify($currentpass, (string) $currentRow['password_hash'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'La contraseña actual no es correcta.'], JSON_UNESCAPED_UNICODE); exit;
  }

  $hash = password_hash($newpass, PASSWORD_DEFAULT);
  $stmt = $pdo->prepare("UPDATE {$TABLE_USERS} SET password_hash=? WHERE id=?");
  $stmt->execute([$hash, (int) $_SESSION['user_id']]);
  echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'No se pudo actualizar la contraseña.'], JSON_UNESCAPED_UNICODE);
}
