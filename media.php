<?php
// media.php
declare(strict_types=1);

require_once __DIR__ . '/auth/require_auth.php';
require_once __DIR__ . '/config/conversations.php';
require_permission('view_conversations');

conv_ensure_schema($pdo);

$id = max(0, (int) ($_GET['id'] ?? 0));
if ($id <= 0) {
  http_response_code(404);
  echo 'Archivo no encontrado.';
  exit;
}

$table = conv_attachments_table();
if (is_super_admin()) {
  $stmt = $pdo->prepare("SELECT * FROM {$table} WHERE id=? LIMIT 1");
  $stmt->execute([$id]);
} else {
  $stmt = $pdo->prepare("SELECT * FROM {$table} WHERE id=? AND account_id=? LIMIT 1");
  $stmt->execute([$id, (int) (current_account_id() ?: accounts_default_id($pdo))]);
}
$attachment = $stmt->fetch();
if (!$attachment) {
  http_response_code(404);
  echo 'Archivo no encontrado.';
  exit;
}

$key = (string) ($attachment['storage_key'] ?? '');
$url = $key !== '' ? r2_presigned_url($key, 300) : null;
if (!$url) {
  http_response_code(503);
  echo 'Archivo no disponible.';
  exit;
}

header('Location: ' . $url, true, 302);
exit;
