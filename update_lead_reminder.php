<?php
// update_lead_reminder.php
// Actualiza el recordatorio de próxima acción de un lead desde el dashboard.
declare(strict_types=1);

require_once __DIR__ . '/auth/require_auth.php';
header('Content-Type: application/json; charset=utf-8');

if (!can('edit_leads')) {
  http_response_code(403);
  echo json_encode(['ok' => false, 'error' => 'No tienes permiso para editar leads'], JSON_UNESCAPED_UNICODE);
  exit;
}

$csrf = $_POST['csrf'] ?? '';
if (!$csrf || !isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], (string) $csrf)) {
  http_response_code(403);
  echo json_encode(['ok' => false, 'error' => 'CSRF inválido'], JSON_UNESCAPED_UNICODE);
  exit;
}

$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
$reminderAtRaw = trim((string) ($_POST['reminder_at'] ?? ''));
$reminderNote = trim((string) ($_POST['reminder_note'] ?? ''));

if ($id <= 0 || mb_strlen($reminderNote) > 255) {
  http_response_code(422);
  echo json_encode(['ok' => false, 'error' => 'Datos inválidos'], JSON_UNESCAPED_UNICODE);
  exit;
}

$reminderAt = null;
if ($reminderAtRaw !== '') {
  $dt = DateTime::createFromFormat('Y-m-d\TH:i', $reminderAtRaw);
  $errors = DateTime::getLastErrors();
  if (!$dt || ($errors && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0))) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Fecha de recordatorio inválida'], JSON_UNESCAPED_UNICODE);
    exit;
  }
  $reminderAt = $dt->format('Y-m-d H:i:00');
}

try {
  $chk = $pdo->prepare('SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?');
  $chk->execute([$DB_NAME, $TABLE_LEADS, 'reminder_at']);
  if (!$chk->fetch()) {
    $pdo->exec("ALTER TABLE {$TABLE_LEADS} ADD COLUMN reminder_at DATETIME NULL AFTER notes");
  }
  $chk->execute([$DB_NAME, $TABLE_LEADS, 'reminder_note']);
  if (!$chk->fetch()) {
    $pdo->exec("ALTER TABLE {$TABLE_LEADS} ADD COLUMN reminder_note VARCHAR(255) NULL AFTER reminder_at");
  }
  $chk->execute([$DB_NAME, $TABLE_LEADS, 'updated_at']);
  if (!$chk->fetch()) {
    $pdo->exec("ALTER TABLE {$TABLE_LEADS} ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP");
  }

  $idx = $pdo->prepare('SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND INDEX_NAME=?');
  $idx->execute([$DB_NAME, $TABLE_LEADS, 'idx_reminder_at']);
  if (!$idx->fetch()) {
    try { $pdo->exec("ALTER TABLE {$TABLE_LEADS} ADD KEY idx_reminder_at (reminder_at)"); } catch (Throwable $e) { /* índice existente */ }
  }

  $currentAccountId = (int) (current_account_id() ?: accounts_default_id($pdo));
  if (is_super_admin()) {
    $upd = $pdo->prepare("UPDATE {$TABLE_LEADS} SET reminder_at=?, reminder_note=?, updated_at=NOW() WHERE id=?");
    $upd->execute([$reminderAt, $reminderNote !== '' ? $reminderNote : null, $id]);

    $stamp = $pdo->prepare("SELECT reminder_at, reminder_note, updated_at FROM {$TABLE_LEADS} WHERE id=?");
    $stamp->execute([$id]);
  } else {
    $upd = $pdo->prepare("UPDATE {$TABLE_LEADS} SET reminder_at=?, reminder_note=?, updated_at=NOW() WHERE id=? AND account_id=?");
    $upd->execute([$reminderAt, $reminderNote !== '' ? $reminderNote : null, $id, $currentAccountId]);

    $stamp = $pdo->prepare("SELECT reminder_at, reminder_note, updated_at FROM {$TABLE_LEADS} WHERE id=? AND account_id=?");
    $stamp->execute([$id, $currentAccountId]);
  }
  $row = $stamp->fetch() ?: [];

  echo json_encode([
    'ok' => true,
    'reminder_at' => (string) ($row['reminder_at'] ?? ''),
    'reminder_note' => (string) ($row['reminder_note'] ?? ''),
    'updated_at' => (string) ($row['updated_at'] ?? ''),
  ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'No se pudo actualizar el recordatorio'], JSON_UNESCAPED_UNICODE);
}
