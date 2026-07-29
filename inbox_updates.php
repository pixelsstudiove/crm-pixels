<?php
// inbox_updates.php
declare(strict_types=1);
require_once __DIR__ . '/auth/require_auth.php';
require_once __DIR__ . '/config/conversations.php';
require_once __DIR__ . '/config/lead_status_history.php';
require_permission('view_conversations');

if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');

conv_ensure_schema($pdo);

$contactsTable = conv_contacts_table();
$conversationsTable = conv_conversations_table();
$messagesTable = conv_messages_table();
$channelsTable = ig_channels_table();
$accountsTable = accounts_table();
$currentAccountId = (int) (current_account_id() ?: accounts_default_id($pdo));
if (isset($TABLE_LEADS)) {
  lead_status_normalize_legacy_statuses($pdo, $TABLE_LEADS);
  lead_status_auto_mark_no_response($pdo, $TABLE_LEADS, is_super_admin() ? null : $currentAccountId);
}

$statusOptions = [
  'abierta' => 'Abierta',
  'pendiente' => 'Pendiente',
  'seguimiento' => 'En seguimiento',
  'cerrada' => 'Cerrada',
  'spam' => 'Spam / no califica',
];
$salesStatusOptions = (array) app_config('sales_funnel.statuses', []);
if ($salesStatusOptions === []) {
  $salesStatusOptions = [
    'nuevo_lead' => 'Nuevo lead',
    'en_conversacion' => 'En conversacion',
    'propuesta_enviada' => 'Propuesta enviada',
    'no_responde' => 'No responde',
    'cliente_ganado' => 'Cliente ganado',
    'cliente_perdido' => 'Cliente perdido',
    'no_califica' => 'No califica',
  ];
}

function updates_contact_name(array $conversation): string {
  $name = trim((string) ($conversation['display_name'] ?? ''));
  if ($name !== '') return $name;
  $username = trim((string) ($conversation['username'] ?? ''));
  if ($username !== '') return '@' . ltrim($username, '@');
  return match (conv_conversation_provider($conversation)) {
    'messenger' => 'Contacto de Messenger',
    'whatsapp' => 'Contacto de WhatsApp',
    default => 'Contacto de Instagram',
  };
}

function updates_avatar_url(array $conversation): string {
  $avatarUrl = trim((string) ($conversation['avatar_url'] ?? ''));
  if ($avatarUrl !== '') return $avatarUrl;
  $profileUrl = trim((string) ($conversation['profile_url'] ?? ''));
  if (updates_channel_icon_source($conversation) === 'messenger' && str_starts_with($profileUrl, 'http')) return $profileUrl;
  return '';
}

function updates_avatar_initials(array $conversation): string {
  $name = trim(str_replace('@', '', updates_contact_name($conversation)));
  if ($name === '') return 'C';
  $parts = preg_split('/\s+/', $name) ?: [];
  $initials = '';
  foreach ($parts as $part) {
    $part = trim((string) $part);
    if ($part === '') continue;
    $initials .= mb_substr($part, 0, 1);
    if (mb_strlen($initials) >= 2) break;
  }
  return mb_strtoupper($initials !== '' ? $initials : mb_substr($name, 0, 1));
}

function updates_short($value, int $max = 92): string {
  $value = updates_normalize_message_text($value);
  if ($value === '') return '—';
  return mb_strlen($value) > $max ? mb_substr($value, 0, max(1, $max - 1)) . '…' : $value;
}

function updates_unsupported_message_text(): string {
  return 'Se ha recibido un mensaje no soportado en esta plataforma, accede a este mensaje directamente desde la app oficial.';
}

function updates_normalize_message_text($value): string {
  $text = trim((string) $value);
  $legacyUnsupported = [
    'Mensaje recibido desde Instagram DM.',
    'Mensaje recibido desde Facebook Messenger.',
    'Mensaje recibido desde WhatsApp.',
    'Adjunto recibido: unsupported_type',
  ];
  return in_array($text, $legacyUnsupported, true) ? updates_unsupported_message_text() : $text;
}

function updates_time($value): string {
  return app_datetime($value);
}

function updates_channel_icon_source(array $conversation): string {
  $source = strtolower(trim((string) ($conversation['external_source'] ?? 'instagram')));
  if ($source === 'messenger') return 'messenger';
  if ($source === 'whatsapp') return 'whatsapp';
  return 'instagram';
}

function updates_channel_icon_path(array $conversation): string {
  $source = updates_channel_icon_source($conversation);
  if ($source === 'messenger') return 'images/icon_messenger.png';
  if ($source === 'whatsapp') {
    return is_file(__DIR__ . '/images/icon_whatsapp.png') ? 'images/icon_whatsapp.png' : 'images/icon_whatwsapp.png';
  }
  return 'images/icon_instagram.png';
}

function updates_channel_icon_label(array $conversation): string {
  $source = updates_channel_icon_source($conversation);
  if ($source === 'messenger') return 'Messenger';
  if ($source === 'whatsapp') return 'WhatsApp';
  return 'Instagram';
}

try {
  $filterStatus = trim((string) ($_GET['status'] ?? ''));
  if ($filterStatus !== '' && !array_key_exists($filterStatus, $statusOptions)) $filterStatus = '';
  $filterSalesStatus = trim((string) ($_GET['sales_status'] ?? ''));
  if ($filterSalesStatus !== '' && !array_key_exists($filterSalesStatus, $salesStatusOptions)) $filterSalesStatus = '';
  $q = trim((string) ($_GET['q'] ?? ''));
  $requestSlug = accounts_request_slug();
  $requestAccount = accounts_request_account($pdo);
  if ($requestSlug !== '' && !$requestAccount) {
    echo json_encode(['ok' => false, 'error' => 'Cuenta no encontrada.'], JSON_UNESCAPED_UNICODE);
    exit;
  }
  $requestAccountId = $requestAccount ? (int) ($requestAccount['id'] ?? 0) : 0;
  $selectedRouteId = trim((string) ($_GET['id'] ?? ''));
  $selectedId = 0;
  $filterChannelId = max(0, (int) ($_GET['channel_id'] ?? 0));
  $selectedAccountId = max(0, (int) ($_GET['selected_account_id'] ?? 0));
  $filterAccountId = 0;
  if (is_super_admin()) {
    $accountIds = [];
    try {
      $accountStmt = $pdo->query("SELECT id FROM " . accounts_table());
      $accountIds = $accountStmt ? array_map('intval', $accountStmt->fetchAll(PDO::FETCH_COLUMN)) : [];
    } catch (Throwable $e) {
      $accountIds = [];
    }
    $filterAccountId = $requestAccountId > 0 ? $requestAccountId : max(0, (int) ($_GET['account_id'] ?? 0));
    if ($filterAccountId > 0 && !in_array($filterAccountId, $accountIds, true)) $filterAccountId = 0;
  }
  $publicLookupAccountId = $requestAccountId > 0 ? $requestAccountId : ($selectedAccountId > 0 ? $selectedAccountId : $filterAccountId);
  if ($selectedRouteId !== '' && $publicLookupAccountId > 0) {
    $selectedId = conv_resolve_conversation_route_id($pdo, $publicLookupAccountId, $selectedRouteId);
  }

  $where = [];
  $params = [];
  if (!is_super_admin()) {
    $where[] = 'c.account_id = :account_id';
    $params[':account_id'] = $currentAccountId;
  } elseif ($filterAccountId > 0) {
    $where[] = 'c.account_id = :account_id';
    $params[':account_id'] = $filterAccountId;
  }
  if ($filterChannelId > 0) {
    $where[] = 'c.channel_id = :channel_id';
    $params[':channel_id'] = $filterChannelId;
  }
  if ($filterStatus !== '') {
    $where[] = 'c.status = :status';
    $params[':status'] = $filterStatus;
  }
  if ($filterSalesStatus !== '') {
    $where[] = 'COALESCE(l.sales_status, :default_sales_status) = :sales_status';
    $params[':default_sales_status'] = (string) app_config('sales_funnel.default_status', 'nuevo_lead');
    $params[':sales_status'] = $filterSalesStatus;
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
  ct.avatar_url,
  ch.page_name,
  ch.instagram_username AS channel_username,
  a.slug AS account_slug,
  l.fullname AS lead_fullname,
  l.sales_status AS lead_sales_status,
  (
    SELECT MAX(im.sent_at)
    FROM {$messagesTable} im
    WHERE im.conversation_id = c.id AND im.direction = 'inbound'
  ) AS last_inbound_at
FROM {$conversationsTable} c
JOIN {$contactsTable} ct ON ct.id = c.contact_id
LEFT JOIN {$channelsTable} ch ON ch.id = c.channel_id
LEFT JOIN {$accountsTable} a ON a.id = c.account_id
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
      'id' => conv_route_id($row),
      'account_id' => (int) ($row['account_id'] ?? 0),
      'account_slug' => (string) ($row['account_slug'] ?? ''),
      'name' => updates_contact_name($row),
      'time' => updates_time($row['last_message_at'] ?? $row['created_at'] ?? ''),
      'status' => (string) ($row['status'] ?? ''),
      'status_label' => (string) ($statusOptions[(string) ($row['status'] ?? '')] ?? 'Abierta'),
      'preview' => updates_short($row['last_message_preview'] ?? '', 92),
      'unread_count' => (int) ($row['unread_count'] ?? 0),
      'reply_window' => meta_reply_window_info($row['last_inbound_at'] ?? ''),
      'channel_icon' => updates_channel_icon_path($row),
      'channel_label' => updates_channel_icon_label($row),
      'avatar_url' => updates_avatar_url($row),
      'avatar_initials' => updates_avatar_initials($row),
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
  ct.avatar_url,
  ch.page_name,
  ch.instagram_username AS channel_username,
  a.slug AS account_slug,
  l.fullname AS lead_fullname,
  l.sales_status AS lead_sales_status,
  (
    SELECT MAX(im.sent_at)
    FROM {$messagesTable} im
    WHERE im.conversation_id = c.id AND im.direction = 'inbound'
  ) AS last_inbound_at
FROM {$conversationsTable} c
JOIN {$contactsTable} ct ON ct.id = c.contact_id
LEFT JOIN {$channelsTable} ch ON ch.id = c.channel_id
LEFT JOIN {$accountsTable} a ON a.id = c.account_id
LEFT JOIN {$TABLE_LEADS} l ON l.id = c.lead_id
WHERE c.id = ?
  %s
LIMIT 1
SQL;
    $accountDetailSql = '';
    if (!is_super_admin()) $accountDetailSql = 'AND c.account_id = ?';
    elseif ($publicLookupAccountId > 0) $accountDetailSql = 'AND c.account_id = ?';
    $detailStmt = $pdo->prepare(sprintf($detailSql, $accountDetailSql));
    $detailParams = [$selectedId];
    if (!is_super_admin()) $detailParams[] = $currentAccountId;
    elseif ($publicLookupAccountId > 0) $detailParams[] = $publicLookupAccountId;
    $detailStmt->execute($detailParams);
    $selected = $detailStmt->fetch() ?: null;
    if ($selected && empty($selected['lead_id'])) {
      conv_ensure_lead_for_conversation($pdo, $TABLE_LEADS, (int) $selected['id']);
      $detailStmt->execute($detailParams);
      $selected = $detailStmt->fetch() ?: null;
    }
    if ($selected) {
      $selectedRouteId = conv_route_id($selected);
      conv_mark_read($pdo, (int) $selected['id']);
      $msgStmt = $pdo->prepare("SELECT * FROM (SELECT m.*, u.username AS sent_by_username FROM {$messagesTable} m LEFT JOIN {$TABLE_USERS} u ON u.id = m.sent_by WHERE m.conversation_id=? ORDER BY m.sent_at DESC, m.id DESC LIMIT 120) recent_messages ORDER BY sent_at ASC, id ASC");
      $msgStmt->execute([(int) $selected['id']]);
      $messageRows = $msgStmt->fetchAll();
      $attachmentsByMessage = conv_attachments_for_messages($pdo, array_map(static fn($message) => (int) ($message['id'] ?? 0), $messageRows));
      foreach ($messageRows as $message) {
        $direction = (string) ($message['direction'] ?? 'inbound');
        $messageId = (int) $message['id'];
        $messages[] = [
          'id' => $messageId,
          'direction' => $direction === 'outbound' ? 'outbound' : ($direction === 'system' ? 'system' : 'inbound'),
          'text' => updates_normalize_message_text((string) ($message['message_text'] ?: 'Mensaje sin texto')),
          'attachments' => $attachmentsByMessage[$messageId] ?? [],
          'time' => updates_time($message['sent_at'] ?? ''),
          'sent_by_username' => (string) ($message['sent_by_username'] ?? ''),
          'delivery_status' => (string) ($message['delivery_status'] ?? ''),
        ];
      }
    }
  }

  echo json_encode([
    'ok' => true,
    'conversation_id' => $selectedRouteId,
    'selected_account_id' => $selected ? (int) ($selected['account_id'] ?? 0) : 0,
    'conversations' => $conversations,
    'messages' => $messages,
    'reply_window' => $selected ? meta_reply_window_info($selected['last_inbound_at'] ?? '') : null,
    'server_time' => app_datetime(gmdate('Y-m-d H:i:s'), 'c'),
  ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'No se pudieron cargar actualizaciones.'], JSON_UNESCAPED_UNICODE);
}
