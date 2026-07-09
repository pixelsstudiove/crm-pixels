<?php
// update_lead_notes.php
// Actualiza las anotaciones internas de un lead desde el dashboard.
declare(strict_types=1);

require_once __DIR__ . '/auth/require_auth.php';
header('Content-Type: application/json; charset=utf-8');

$csrf = $_POST['csrf'] ?? '';
if (!$csrf || !isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], (string) $csrf)) {
  http_response_code(403);
  echo json_encode(['ok' => false, 'error' => 'CSRF inválido'], JSON_UNESCAPED_UNICODE);
  exit;
}

$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
$notes = trim((string) ($_POST['notes'] ?? ''));

if ($id <= 0 || mb_strlen($notes) > 2000) {
  http_response_code(422);
  echo json_encode(['ok' => false, 'error' => 'Datos inválidos'], JSON_UNESCAPED_UNICODE);
  exit;
}

try {
  $chk = $pdo->prepare('SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?');
  $chk->execute([$DB_NAME, $TABLE_LEADS, 'notes']);
  if (!$chk->fetch()) {
    $pdo->exec("ALTER TABLE {$TABLE_LEADS} ADD COLUMN notes TEXT NULL AFTER sales_status");
  }
  $chk->execute([$DB_NAME, $TABLE_LEADS, 'updated_at']);
  if (!$chk->fetch()) {
    $pdo->exec("ALTER TABLE {$TABLE_LEADS} ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP");
  }

  $upd = $pdo->prepare("UPDATE {$TABLE_LEADS} SET notes=?, updated_at=NOW() WHERE id=?");
  $upd->execute([$notes !== '' ? $notes : null, $id]);
  $stamp = $pdo->prepare("SELECT updated_at FROM {$TABLE_LEADS} WHERE id=?");
  $stamp->execute([$id]);
  $updatedAt = (string) ($stamp->fetchColumn() ?: '');

  echo json_encode(['ok' => true, 'updated_at' => $updatedAt], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'No se pudieron actualizar las anotaciones'], JSON_UNESCAPED_UNICODE);
}
