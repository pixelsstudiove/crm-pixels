<?php
// lead_status_history.php
declare(strict_types=1);

require_once __DIR__ . '/auth/require_auth.php';
require_once __DIR__ . '/config/lead_status_history.php';

header('Content-Type: application/json; charset=utf-8');

if (!can('view_dashboard') && !can('view_conversations')) {
  http_response_code(403);
  echo json_encode(['ok' => false, 'error' => 'No tienes permiso para ver el historial.'], JSON_UNESCAPED_UNICODE);
  exit;
}

$leadId = max(0, (int) ($_GET['lead_id'] ?? 0));
if ($leadId <= 0) {
  http_response_code(422);
  echo json_encode(['ok' => false, 'error' => 'Lead invalido.'], JSON_UNESCAPED_UNICODE);
  exit;
}

try {
  $rows = lead_status_history_rows($pdo, $leadId);
  $items = array_map(static function(array $row): array {
    $previous = (string) ($row['previous_status'] ?? '');
    $next = (string) ($row['new_status'] ?? '');
    return [
      'id' => (int) ($row['id'] ?? 0),
      'previous_status' => $previous,
      'previous_label' => $previous !== '' ? lead_status_label($previous) : 'Sin status previo',
      'new_status' => $next,
      'new_label' => lead_status_label($next),
      'reason' => (string) ($row['change_reason'] ?? ''),
      'username' => (string) ($row['username'] ?? 'Sistema'),
      'created_at' => app_datetime($row['created_at'] ?? ''),
    ];
  }, $rows);

  echo json_encode(['ok' => true, 'items' => $items], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'No se pudo cargar el historial.'], JSON_UNESCAPED_UNICODE);
}
