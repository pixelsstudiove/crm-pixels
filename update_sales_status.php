<?php
// update_sales_status.php
// Actualiza el status comercial de un lead desde el dashboard.
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
$salesStatus = isset($_POST['sales_status']) ? trim((string) $_POST['sales_status']) : '';
$allowedStatuses = array_keys((array) app_config('sales_funnel.statuses', []));

if ($allowedStatuses === []) {
  $allowedStatuses = ['nuevo_lead', 'contactado', 'diagnostico_agendado'];
}

if ($id <= 0 || !in_array($salesStatus, $allowedStatuses, true)) {
  http_response_code(422);
  echo json_encode(['ok' => false, 'error' => 'Datos inválidos'], JSON_UNESCAPED_UNICODE);
  exit;
}

try {
  $chk = $pdo->prepare('SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?');
  $chk->execute([$DB_NAME, $TABLE_LEADS, 'sales_status']);
  if (!$chk->fetch()) {
    $pdo->exec("ALTER TABLE {$TABLE_LEADS} ADD COLUMN sales_status VARCHAR(50) NOT NULL DEFAULT 'nuevo_lead' AFTER referrer");
  }
  $chk->execute([$DB_NAME, $TABLE_LEADS, 'updated_at']);
  if (!$chk->fetch()) {
    $pdo->exec("ALTER TABLE {$TABLE_LEADS} ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP");
  }

  $idx = $pdo->prepare('SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND INDEX_NAME=?');
  $idx->execute([$DB_NAME, $TABLE_LEADS, 'idx_sales_status']);
  if (!$idx->fetch()) {
    try { $pdo->exec("ALTER TABLE {$TABLE_LEADS} ADD KEY idx_sales_status (sales_status)"); } catch (Throwable $e) { /* índice existente */ }
  }

  $upd = $pdo->prepare("UPDATE {$TABLE_LEADS} SET sales_status=?, updated_at=NOW() WHERE id=?");
  $upd->execute([$salesStatus, $id]);
  $stamp = $pdo->prepare("SELECT updated_at FROM {$TABLE_LEADS} WHERE id=?");
  $stamp->execute([$id]);
  $updatedAt = (string) ($stamp->fetchColumn() ?: '');

  echo json_encode([
    'ok' => true,
    'sales_status' => $salesStatus,
    'label' => (string) app_config('sales_funnel.statuses.' . $salesStatus, $salesStatus),
    'updated_at' => $updatedAt,
  ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'No se pudo actualizar el status comercial'], JSON_UNESCAPED_UNICODE);
}
