<?php
// inbox_updates.php
declare(strict_types=1);
require_once __DIR__ . '/auth/require_auth.php';
require_once __DIR__ . '/config/conversations.php';
require_permission('view_conversations');

if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');

conv_ensure_schema($pdo);

$contactsTable = conv_contacts_table();
$conversationsTable = conv_conversations_table();
$messagesTable = conv_messages_table();
$channelsTable = ig_channels_table();

$statusOptions = [
  'abierta' => 'Abierta',
  'pendiente' => 'Pendiente',
  'seguimiento' => 'En seguimiento',
  'cerrada' => 'Cerrada',
  'spam' => 'Spam / no califica',
];

function updates_contact_name(array $conversation): string {
  $name = trim((string) ($conversation['display_name'] ?? ''));
  if ($name !== '') return $name;
  $username = trim((string) ($conversation['username'] ?? ''));
  if ($username !== '') return '@' . ltrim($username, '@');
  return 'Contacto de Instagram';
}

function updates_short($value, int $max = 92): string {
  $value = trim((string) $value);
  if ($value === '') return '—';
  return mb_strlen($value) > $max ? mb_substr($value, 0, max(1, $max - 1)) . '…' : $value;
}

function updates_time($value): string {
  $value = trim((string) $value);
  if ($value === '') return 'Sin fecha';
  $time = strtotime($value);
  return $time ? date('d/m/Y H:i', $time) : $value;
}

try {
  $filterStatus = trim((string) ($_GET['status'] ?? ''));
  if ($filterStatus !== '' && !array_key_exists($filterStatus, $statusOptions)) $filterStatus = '';
  $q = trim((string) ($_GET['q'] ?? ''));
  $selectedId = max(0, (int) ($_GET['id'] ?? 0));
  $afterId = max(0, (int) ($_GET['after_id'] ?? 0));

  $where = [];
  $params = [];
  if ($filterStatus !== '') {
    $where[] = 'c.status = :status';
    $params[':status'] = $filterStatus;
  }
  if ($q !== '') {
    $where[] = '(ct.display_name LIKE :q OR ct.username LIKE :q OR c.last_message_preview LIKE :q OR ch.page_name LIKE :q OR ch.instagram_username LIKE :q)';
    $params[':q'] = '%' . $q . '%';
  }
  $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

  $listSql = <<<SQL
SELECT
  c.*,
  ct.external_contact_id AS contact_external_id,
  ct.display_name,
  ct.username,
  ct.profile_url,
  ch.page_name,
  ch.instagram_username AS channel_username,
  l.fullname AS lead_fullname
FROM {$conversationsTable} c
JOIN {$contactsTable} ct ON ct.id = c.contact_id
LEFT JOIN {$channelsTable} ch ON ch.id = c.channel_id
LEFT JOIN {$TABLE_LEADS} l ON l.id = c.lead_id
{$whereSql}
ORDER BY COALESCE(c.last_message_at, c.created_at) DESC
LIMIT 80
SQL;
  $stmt = $pdo->prepare($listSql);
  foreach ($params as $key => $value) $stmt->bindValue($key, $value);
  $stmt->execute();
  $rows = $stmt->fetchAll();

  $conversations = [];
  foreach ($rows as $row) {
    $conversations[] = [
      'id' => (int) $row['id'],
      'name' => updates_contact_name($row),
      'time' => updates_time($row['last_message_at'] ?? $row['created_at'] ?? ''),
      'status' => (string) ($row['status'] ?? ''),
      'status_label' => (string) ($statusOptions[(string) ($row['status'] ?? '')] ?? 'Abierta'),
      'preview' => updates_short($row['last_message_preview'] ?? '', 92),
      'unread_count' => (int) ($row['unread_count'] ?? 0),
    ];
  }

  $messages = [];
  $selected = null;
  if ($selectedId > 0) {
    $detailSql = <<<SQL
SELECT
  c.*,
  ct.external_contact_id AS contact_external_id,
  ct.display_name,
  ct.username,
  ct.profile_url,
  ch.page_name,
  ch.instagram_username AS channel_username,
  l.fullname AS lead_fullname,
  l.sales_status AS lead_sales_status
FROM {$conversationsTable} c
JOIN {$contactsTable} ct ON ct.id = c.contact_id
LEFT JOIN {$channelsTable} ch ON ch.id = c.channel_id
LEFT JOIN {$TABLE_LEADS} l ON l.id = c.lead_id
WHERE c.id = ?
LIMIT 1
SQL;
    $detailStmt = $pdo->prepare($detailSql);
    $detailStmt->execute([$selectedId]);
    $selected = $detailStmt->fetch() ?: null;
    if ($selected) {
      conv_mark_read($pdo, (int) $selected['id']);
      $msgStmt = $pdo->prepare("SELECT m.*, u.username AS sent_by_username FROM {$messagesTable} m LEFT JOIN {$TABLE_USERS} u ON u.id = m.sent_by WHERE m.conversation_id=? AND m.id>? ORDER BY m.sent_at ASC, m.id ASC");
      $msgStmt->execute([(int) $selected['id'], $afterId]);
      foreach ($msgStmt->fetchAll() as $message) {
        $direction = (string) ($message['direction'] ?? 'inbound');
        $messages[] = [
          'id' => (int) $message['id'],
          'direction' => $direction === 'outbound' ? 'outbound' : 'inbound',
          'text' => (string) ($message['message_text'] ?: 'Mensaje sin texto'),
          'time' => updates_time($message['sent_at'] ?? ''),
          'sent_by_username' => (string) ($message['sent_by_username'] ?? ''),
          'delivery_status' => (string) ($message['delivery_status'] ?? ''),
        ];
      }
    }
  }

  echo json_encode([
    'ok' => true,
    'conversation_id' => $selectedId,
    'conversations' => $conversations,
    'messages' => $messages,
    'server_time' => gmdate('c'),
  ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'No se pudieron cargar actualizaciones.'], JSON_UNESCAPED_UNICODE);
}
