<?php
// config/conversations.php
declare(strict_types=1);

require_once __DIR__ . '/instagram_channels.php';
require_once __DIR__ . '/r2.php';
require_once __DIR__ . '/accounts.php';

function conv_contacts_table(): string {
  return safe_identifier((string) app_config('database.conversation_contacts_table', 'conversation_contacts'), 'conversation_contacts');
}

function conv_conversations_table(): string {
  return safe_identifier((string) app_config('database.conversations_table', 'conversations'), 'conversations');
}

function conv_messages_table(): string {
  return safe_identifier((string) app_config('database.conversation_messages_table', 'conversation_messages'), 'conversation_messages');
}

function conv_attachments_table(): string {
  return safe_identifier((string) app_config('database.conversation_attachments_table', 'conversation_attachments'), 'conversation_attachments');
}

function conv_webhook_logs_table(): string {
  return safe_identifier((string) app_config('database.webhook_event_logs_table', 'webhook_event_logs'), 'webhook_event_logs');
}

function conv_ensure_schema(PDO $pdo): void {
  global $DB_NAME;
  $dbName = (string) ($DB_NAME ?? '');
  $defaultAccountId = accounts_default_id($pdo);
  $contactsTable = conv_contacts_table();
  $conversationsTable = conv_conversations_table();
  $messagesTable = conv_messages_table();
  $attachmentsTable = conv_attachments_table();
  $logsTable = conv_webhook_logs_table();

  $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS {$contactsTable} (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  account_id INT UNSIGNED NOT NULL DEFAULT {$defaultAccountId},
  external_source VARCHAR(40) NOT NULL,
  external_contact_id VARCHAR(160) NOT NULL,
  display_name VARCHAR(180) NULL,
  username VARCHAR(180) NULL,
  profile_url VARCHAR(255) NULL,
  last_seen_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_external_contact (account_id, external_source, external_contact_id),
  KEY idx_account_id (account_id),
  KEY idx_username (username),
  KEY idx_last_seen_at (last_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

  $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS {$conversationsTable} (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  account_id INT UNSIGNED NOT NULL DEFAULT {$defaultAccountId},
  public_id INT UNSIGNED NULL,
  channel_id INT UNSIGNED NULL,
  contact_id INT UNSIGNED NOT NULL,
  lead_id INT UNSIGNED NULL,
  external_source VARCHAR(40) NOT NULL,
  external_thread_id VARCHAR(180) NOT NULL,
  status VARCHAR(40) NOT NULL DEFAULT 'abierta',
  assigned_to INT UNSIGNED NULL,
  last_message_preview TEXT NULL,
  last_message_at DATETIME NULL,
  unread_count INT UNSIGNED NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_external_thread (account_id, external_source, external_thread_id),
  UNIQUE KEY uniq_account_public_id (account_id, public_id),
  KEY idx_account_id (account_id),
  KEY idx_channel_id (channel_id),
  KEY idx_contact_id (contact_id),
  KEY idx_lead_id (lead_id),
  KEY idx_status (status),
  KEY idx_assigned_to (assigned_to),
  KEY idx_last_message_at (last_message_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

  $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS {$messagesTable} (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  account_id INT UNSIGNED NOT NULL DEFAULT {$defaultAccountId},
  conversation_id INT UNSIGNED NOT NULL,
  external_message_id TEXT NULL,
  external_message_hash CHAR(64) NULL,
  direction VARCHAR(20) NOT NULL,
  sender_external_id VARCHAR(180) NULL,
  message_type VARCHAR(40) NOT NULL DEFAULT 'text',
  message_text TEXT NULL,
  payload_json MEDIUMTEXT NULL,
  sent_by INT UNSIGNED NULL,
  sent_at DATETIME NOT NULL,
  delivery_status VARCHAR(40) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_conversation_message_hash (conversation_id, external_message_hash),
  KEY idx_account_id (account_id),
  KEY idx_conversation_id (conversation_id),
  KEY idx_direction (direction),
  KEY idx_sent_at (sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

  $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS {$attachmentsTable} (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  account_id INT UNSIGNED NOT NULL DEFAULT {$defaultAccountId},
  conversation_id INT UNSIGNED NOT NULL,
  message_id INT UNSIGNED NULL,
  direction VARCHAR(20) NOT NULL,
  media_type VARCHAR(40) NOT NULL DEFAULT 'image',
  mime_type VARCHAR(120) NULL,
  file_size INT UNSIGNED NULL,
  storage_disk VARCHAR(40) NOT NULL DEFAULT 'r2',
  storage_key VARCHAR(500) NOT NULL,
  original_url TEXT NULL,
  filename VARCHAR(180) NULL,
  external_attachment_id VARCHAR(180) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_storage_key (storage_key),
  KEY idx_account_id (account_id),
  KEY idx_conversation_id (conversation_id),
  KEY idx_message_id (message_id),
  KEY idx_media_type (media_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

  $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS {$logsTable} (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  account_id INT UNSIGNED NOT NULL DEFAULT {$defaultAccountId},
  source VARCHAR(40) NOT NULL DEFAULT 'instagram',
  event_type VARCHAR(60) NULL,
  status VARCHAR(40) NOT NULL,
  recipient_id VARCHAR(180) NULL,
  sender_id VARCHAR(180) NULL,
  channel_id INT UNSIGNED NULL,
  channel_username VARCHAR(180) NULL,
  external_message_id TEXT NULL,
  lead_id INT UNSIGNED NULL,
  conversation_id INT UNSIGNED NULL,
  message_preview VARCHAR(255) NULL,
  error_message VARCHAR(255) NULL,
  payload_json MEDIUMTEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_source_created_at (source, created_at),
  KEY idx_account_id (account_id),
  KEY idx_status (status),
  KEY idx_recipient_id (recipient_id),
  KEY idx_sender_id (sender_id),
  KEY idx_channel_id (channel_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

  if ($dbName !== '') {
    foreach ([$contactsTable, $conversationsTable, $messagesTable, $attachmentsTable, $logsTable] as $table) {
      try { accounts_add_account_column($pdo, $dbName, $table, $defaultAccountId); } catch (Throwable $e) { /* no-op */ }
    }
    accounts_rebuild_unique_index($pdo, $dbName, $contactsTable, 'uniq_external_contact', 'account_id, external_source, external_contact_id');
    accounts_rebuild_unique_index($pdo, $dbName, $conversationsTable, 'uniq_external_thread', 'account_id, external_source, external_thread_id');
  }

  try { $pdo->exec("ALTER TABLE {$messagesTable} ADD COLUMN external_message_hash CHAR(64) NULL AFTER external_message_id"); } catch (Throwable $e) { /* no-op */ }
  try { $pdo->exec("ALTER TABLE {$conversationsTable} ADD COLUMN public_id INT UNSIGNED NULL AFTER account_id"); } catch (Throwable $e) { /* no-op */ }
  conv_backfill_public_ids($pdo);
  try { $pdo->exec("ALTER TABLE {$conversationsTable} ADD UNIQUE KEY uniq_account_public_id (account_id, public_id)"); } catch (Throwable $e) { /* no-op */ }
  try { $pdo->exec("UPDATE {$messagesTable} SET external_message_hash=SHA2(external_message_id, 256) WHERE external_message_hash IS NULL AND external_message_id IS NOT NULL AND external_message_id <> ''"); } catch (Throwable $e) { /* no-op */ }
  try { $pdo->exec("ALTER TABLE {$messagesTable} DROP INDEX uniq_conversation_message"); } catch (Throwable $e) { /* no-op */ }
  try { $pdo->exec("ALTER TABLE {$messagesTable} MODIFY external_message_id TEXT NULL"); } catch (Throwable $e) { /* no-op */ }
  try { $pdo->exec("ALTER TABLE {$messagesTable} ADD UNIQUE KEY uniq_conversation_message_hash (conversation_id, external_message_hash)"); } catch (Throwable $e) { /* no-op */ }
  try { $pdo->exec("ALTER TABLE {$logsTable} MODIFY external_message_id TEXT NULL"); } catch (Throwable $e) { /* no-op */ }
  try { $pdo->exec("ALTER TABLE {$attachmentsTable} ADD COLUMN external_attachment_id VARCHAR(180) NULL AFTER filename"); } catch (Throwable $e) { /* no-op */ }
}

function conv_backfill_public_ids(PDO $pdo): void {
  $table = conv_conversations_table();
  try {
    $rows = $pdo->query("SELECT id, account_id FROM {$table} WHERE public_id IS NULL OR public_id=0 ORDER BY account_id ASC, id ASC")->fetchAll();
  } catch (Throwable $e) {
    return;
  }
  if (!$rows) return;
  $maxByAccount = [];
  try {
    $maxRows = $pdo->query("SELECT account_id, MAX(public_id) AS max_public_id FROM {$table} GROUP BY account_id")->fetchAll();
    foreach ($maxRows as $row) $maxByAccount[(int) $row['account_id']] = (int) ($row['max_public_id'] ?? 0);
  } catch (Throwable $e) {
    $maxByAccount = [];
  }
  $upd = $pdo->prepare("UPDATE {$table} SET public_id=? WHERE id=? AND (public_id IS NULL OR public_id=0)");
  foreach ($rows as $row) {
    $accountId = (int) ($row['account_id'] ?? 0);
    $next = ($maxByAccount[$accountId] ?? 0) + 1;
    $maxByAccount[$accountId] = $next;
    try { $upd->execute([$next, (int) $row['id']]); } catch (Throwable $e) { /* no-op */ }
  }
}

function conv_next_public_id(PDO $pdo, int $accountId): int {
  $table = conv_conversations_table();
  try {
    $stmt = $pdo->prepare("SELECT COALESCE(MAX(public_id), 0) + 1 FROM {$table} WHERE account_id=?");
    $stmt->execute([$accountId]);
    return max(1, (int) $stmt->fetchColumn());
  } catch (Throwable $e) {
    return 1;
  }
}

function conv_resolve_public_conversation_id(PDO $pdo, int $accountId, int $publicId): int {
  if ($accountId <= 0 || $publicId <= 0) return 0;
  $table = conv_conversations_table();
  try {
    $stmt = $pdo->prepare("SELECT id FROM {$table} WHERE account_id=? AND public_id=? LIMIT 1");
    $stmt->execute([$accountId, $publicId]);
    return (int) ($stmt->fetchColumn() ?: 0);
  } catch (Throwable $e) {
    return 0;
  }
}

function conv_display_id(array $conversation): int {
  $publicId = (int) ($conversation['public_id'] ?? $conversation['conversation_public_id'] ?? 0);
  return $publicId > 0 ? $publicId : (int) ($conversation['id'] ?? $conversation['conversation_id'] ?? 0);
}

function conv_clean($value, int $max = 180): ?string {
  $value = trim(str_replace("\0", '', (string) $value));
  if ($value === '') return null;
  return mb_substr($value, 0, $max);
}

function conv_instagram_profile_url(?string $username): ?string {
  $username = conv_clean($username, 180);
  if ($username === null) return null;
  $handle = trim($username, "@/ \t\n\r\0\x0B");
  if (!preg_match('/^[a-zA-Z0-9._]{1,30}$/', $handle)) return null;
  return 'https://instagram.com/' . $handle;
}

function conv_upsert_contact(PDO $pdo, array $data): int {
  $table = conv_contacts_table();
  $accountId = (int) (($data['account_id'] ?? current_account_id()) ?: accounts_default_id($pdo));
  $externalSource = conv_clean($data['external_source'] ?? 'instagram', 40) ?? 'instagram';
  $externalContactId = conv_clean($data['external_contact_id'] ?? null, 160);
  if ($externalContactId === null) return 0;
  $displayName = conv_clean($data['display_name'] ?? null, 180);
  $username = conv_clean($data['username'] ?? null, 180);
  $profileUrl = conv_clean($data['profile_url'] ?? conv_instagram_profile_url($username), 255);
  $lastSeenAt = conv_clean($data['last_seen_at'] ?? null, 30);

  $stmt = $pdo->prepare(<<<SQL
INSERT INTO {$table} (
  account_id, external_source, external_contact_id, display_name, username, profile_url, last_seen_at, updated_at
) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
ON DUPLICATE KEY UPDATE
  display_name = COALESCE(VALUES(display_name), display_name),
  username = COALESCE(VALUES(username), username),
  profile_url = COALESCE(VALUES(profile_url), profile_url),
  last_seen_at = COALESCE(VALUES(last_seen_at), last_seen_at),
  updated_at = NOW()
SQL);
  $stmt->execute([$accountId, $externalSource, $externalContactId, $displayName, $username, $profileUrl, $lastSeenAt]);

  $find = $pdo->prepare("SELECT id FROM {$table} WHERE account_id=? AND external_source=? AND external_contact_id=? LIMIT 1");
  $find->execute([$accountId, $externalSource, $externalContactId]);
  return (int) ($find->fetchColumn() ?: 0);
}

function conv_upsert_conversation(PDO $pdo, array $data): int {
  $table = conv_conversations_table();
  $accountId = (int) (($data['account_id'] ?? current_account_id()) ?: accounts_default_id($pdo));
  $externalSource = conv_clean($data['external_source'] ?? 'instagram', 40) ?? 'instagram';
  $externalThreadId = conv_clean($data['external_thread_id'] ?? null, 180);
  $contactId = (int) ($data['contact_id'] ?? 0);
  if ($externalThreadId === null || $contactId <= 0) return 0;
  $find = $pdo->prepare("SELECT id FROM {$table} WHERE account_id=? AND external_source=? AND external_thread_id=? LIMIT 1");
  $find->execute([$accountId, $externalSource, $externalThreadId]);
  $existingId = (int) ($find->fetchColumn() ?: 0);
  $channelId = isset($data['channel_id']) ? (int) $data['channel_id'] : null;
  $leadId = isset($data['lead_id']) ? (int) $data['lead_id'] : null;
  $status = conv_clean($data['status'] ?? 'abierta', 40) ?? 'abierta';
  $preview = conv_clean($data['last_message_preview'] ?? null, 1200);
  $lastMessageAt = conv_clean($data['last_message_at'] ?? null, 30);
  $unreadIncrement = max(0, (int) ($data['unread_increment'] ?? 0));

  if ($existingId > 0) {
    $stmt = $pdo->prepare(<<<SQL
UPDATE {$table}
SET
  channel_id = COALESCE(?, channel_id),
  contact_id = ?,
  lead_id = COALESCE(?, lead_id),
  last_message_preview = COALESCE(?, last_message_preview),
  last_message_at = COALESCE(?, last_message_at),
  unread_count = unread_count + ?,
  updated_at = NOW()
WHERE id=?
SQL);
    $stmt->execute([$channelId, $contactId, $leadId, $preview, $lastMessageAt, $unreadIncrement, $existingId]);
    return $existingId;
  }

  $publicId = conv_next_public_id($pdo, $accountId);
  $stmt = $pdo->prepare(<<<SQL
INSERT INTO {$table} (
  account_id, public_id, channel_id, contact_id, lead_id, external_source, external_thread_id, status,
  last_message_preview, last_message_at, unread_count, updated_at
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
ON DUPLICATE KEY UPDATE
  channel_id = COALESCE(VALUES(channel_id), channel_id),
  contact_id = VALUES(contact_id),
  lead_id = COALESCE(VALUES(lead_id), lead_id),
  last_message_preview = COALESCE(VALUES(last_message_preview), last_message_preview),
  last_message_at = COALESCE(VALUES(last_message_at), last_message_at),
  unread_count = unread_count + VALUES(unread_count),
  updated_at = NOW()
SQL);
  $stmt->execute([$accountId, $publicId, $channelId, $contactId, $leadId, $externalSource, $externalThreadId, $status, $preview, $lastMessageAt, $unreadIncrement]);

  $find = $pdo->prepare("SELECT id FROM {$table} WHERE account_id=? AND external_source=? AND external_thread_id=? LIMIT 1");
  $find->execute([$accountId, $externalSource, $externalThreadId]);
  return (int) ($find->fetchColumn() ?: 0);
}

function conv_account_id_for_conversation(PDO $pdo, int $conversationId): int {
  if ($conversationId <= 0) return accounts_default_id($pdo);
  $table = conv_conversations_table();
  try {
    $stmt = $pdo->prepare("SELECT account_id FROM {$table} WHERE id=? LIMIT 1");
    $stmt->execute([$conversationId]);
    return (int) ($stmt->fetchColumn() ?: accounts_default_id($pdo));
  } catch (Throwable $e) {
    return accounts_default_id($pdo);
  }
}

function conv_add_message(PDO $pdo, array $data): int {
  $table = conv_messages_table();
  $conversationId = (int) ($data['conversation_id'] ?? 0);
  if ($conversationId <= 0) return 0;
  $accountId = (int) ($data['account_id'] ?? conv_account_id_for_conversation($pdo, $conversationId));
  $externalMessageId = conv_clean($data['external_message_id'] ?? null, 2000);
  $externalMessageHash = $externalMessageId !== null ? hash('sha256', $externalMessageId) : null;
  $direction = conv_clean($data['direction'] ?? 'inbound', 20) ?? 'inbound';
  $senderExternalId = conv_clean($data['sender_external_id'] ?? null, 180);
  $messageType = conv_clean($data['message_type'] ?? 'text', 40) ?? 'text';
  $messageText = conv_clean($data['message_text'] ?? null, 5000);
  $payloadJson = isset($data['payload_json']) ? (string) $data['payload_json'] : null;
  $sentBy = isset($data['sent_by']) ? (int) $data['sent_by'] : null;
  $sentAt = conv_clean($data['sent_at'] ?? gmdate('Y-m-d H:i:s'), 30) ?? gmdate('Y-m-d H:i:s');
  $deliveryStatus = conv_clean($data['delivery_status'] ?? null, 40);

  $stmt = $pdo->prepare(<<<SQL
INSERT INTO {$table} (
  account_id, conversation_id, external_message_id, external_message_hash, direction, sender_external_id, message_type,
  message_text, payload_json, sent_by, sent_at, delivery_status
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
ON DUPLICATE KEY UPDATE
  direction = VALUES(direction),
  sender_external_id = VALUES(sender_external_id),
  message_type = VALUES(message_type),
  message_text = VALUES(message_text),
  payload_json = VALUES(payload_json),
  sent_by = VALUES(sent_by),
  sent_at = VALUES(sent_at),
  delivery_status = VALUES(delivery_status)
SQL);
  $stmt->execute([$accountId, $conversationId, $externalMessageId, $externalMessageHash, $direction, $senderExternalId, $messageType, $messageText, $payloadJson, $sentBy, $sentAt, $deliveryStatus]);
  $insertedId = (int) $pdo->lastInsertId();
  if ($insertedId > 0) return $insertedId;
  if ($externalMessageHash === null) return 0;

  $find = $pdo->prepare("SELECT id FROM {$table} WHERE conversation_id=? AND external_message_hash=? LIMIT 1");
  $find->execute([$conversationId, $externalMessageHash]);
  return (int) ($find->fetchColumn() ?: 0);
}

function conv_add_attachment(PDO $pdo, array $data): int {
  $table = conv_attachments_table();
  $conversationId = (int) ($data['conversation_id'] ?? 0);
  $storageKey = conv_clean($data['storage_key'] ?? null, 500);
  if ($conversationId <= 0 || $storageKey === null) return 0;
  $accountId = (int) ($data['account_id'] ?? conv_account_id_for_conversation($pdo, $conversationId));
  $messageId = isset($data['message_id']) ? (int) $data['message_id'] : null;
  $direction = conv_clean($data['direction'] ?? 'inbound', 20) ?? 'inbound';
  $mediaType = conv_clean($data['media_type'] ?? 'image', 40) ?? 'image';
  $mimeType = conv_clean($data['mime_type'] ?? null, 120);
  $fileSize = isset($data['file_size']) ? (int) $data['file_size'] : null;
  $storageDisk = conv_clean($data['storage_disk'] ?? 'r2', 40) ?? 'r2';
  $originalUrl = isset($data['original_url']) ? (string) $data['original_url'] : null;
  $filename = conv_clean($data['filename'] ?? null, 180);
  $externalAttachmentId = conv_clean($data['external_attachment_id'] ?? null, 180);

  $stmt = $pdo->prepare(<<<SQL
INSERT INTO {$table} (
  account_id, conversation_id, message_id, direction, media_type, mime_type, file_size,
  storage_disk, storage_key, original_url, filename, external_attachment_id
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
ON DUPLICATE KEY UPDATE
  message_id = COALESCE(VALUES(message_id), message_id),
  mime_type = COALESCE(VALUES(mime_type), mime_type),
  file_size = COALESCE(VALUES(file_size), file_size),
  original_url = COALESCE(VALUES(original_url), original_url),
  filename = COALESCE(VALUES(filename), filename),
  external_attachment_id = COALESCE(VALUES(external_attachment_id), external_attachment_id)
SQL);
  $stmt->execute([$accountId, $conversationId, $messageId, $direction, $mediaType, $mimeType, $fileSize, $storageDisk, $storageKey, $originalUrl, $filename, $externalAttachmentId]);
  $insertedId = (int) $pdo->lastInsertId();
  if ($insertedId > 0) return $insertedId;
  $find = $pdo->prepare("SELECT id FROM {$table} WHERE storage_key=? LIMIT 1");
  $find->execute([$storageKey]);
  return (int) ($find->fetchColumn() ?: 0);
}

function conv_attachments_for_messages(PDO $pdo, array $messageIds): array {
  $messageIds = array_values(array_unique(array_filter(array_map('intval', $messageIds), static fn($id) => $id > 0)));
  if (!$messageIds) return [];
  $table = conv_attachments_table();
  $placeholders = implode(',', array_fill(0, count($messageIds), '?'));
  $stmt = $pdo->prepare("SELECT * FROM {$table} WHERE message_id IN ({$placeholders}) ORDER BY id ASC");
  $stmt->execute($messageIds);
  $grouped = [];
  foreach ($stmt->fetchAll() as $row) {
    $messageId = (int) ($row['message_id'] ?? 0);
    if ($messageId <= 0) continue;
    $storageKey = (string) ($row['storage_key'] ?? '');
    $mediaUrl = $storageKey !== '' ? r2_presigned_url($storageKey, 3600) : null;
    $grouped[$messageId][] = [
      'id' => (int) ($row['id'] ?? 0),
      'media_type' => (string) ($row['media_type'] ?? 'image'),
      'mime_type' => (string) ($row['mime_type'] ?? ''),
      'file_size' => (int) ($row['file_size'] ?? 0),
      'filename' => (string) ($row['filename'] ?? ''),
      'url' => $mediaUrl ?: ('media.php?id=' . (int) ($row['id'] ?? 0)),
    ];
  }
  return $grouped;
}

function conv_mark_read(PDO $pdo, int $conversationId): void {
  if ($conversationId <= 0) return;
  $table = conv_conversations_table();
  $stmt = $pdo->prepare("UPDATE {$table} SET unread_count=0, updated_at=NOW() WHERE id=?");
  $stmt->execute([$conversationId]);
}

function conv_log_webhook_event(PDO $pdo, array $data): void {
  try {
    conv_ensure_schema($pdo);
    $table = conv_webhook_logs_table();
    $accountId = (int) (($data['account_id'] ?? current_account_id()) ?: accounts_default_id($pdo));
    $stmt = $pdo->prepare(<<<SQL
INSERT INTO {$table} (
  account_id, source, event_type, status, recipient_id, sender_id, channel_id, channel_username,
  external_message_id, lead_id, conversation_id, message_preview, error_message, payload_json
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
SQL);
    $stmt->execute([
      $accountId,
      conv_clean($data['source'] ?? 'instagram', 40) ?? 'instagram',
      conv_clean($data['event_type'] ?? null, 60),
      conv_clean($data['status'] ?? 'received', 40) ?? 'received',
      conv_clean($data['recipient_id'] ?? null, 180),
      conv_clean($data['sender_id'] ?? null, 180),
      isset($data['channel_id']) ? (int) $data['channel_id'] : null,
      conv_clean($data['channel_username'] ?? null, 180),
      conv_clean($data['external_message_id'] ?? null, 2000),
      isset($data['lead_id']) ? (int) $data['lead_id'] : null,
      isset($data['conversation_id']) ? (int) $data['conversation_id'] : null,
      conv_clean($data['message_preview'] ?? null, 255),
      conv_clean($data['error_message'] ?? null, 255),
      isset($data['payload_json']) ? (string) $data['payload_json'] : null,
    ]);
  } catch (Throwable $e) {
    /* Los logs nunca deben romper el webhook. */
  }
}

function conv_graph_post_json(string $path, array $payload, string $accessToken): array {
  $url = rtrim(ig_graph_base(), '/') . '/' . ltrim($path, '/') . '?access_token=' . rawurlencode($accessToken);
  $ch = curl_init();
  curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 25,
  ]);
  $raw = curl_exec($ch);
  $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $error = curl_error($ch);
  curl_close($ch);
  $json = json_decode((string) $raw, true);
  if ($error !== '' || $http < 200 || $http >= 300 || !is_array($json)) {
    return ['ok' => false, 'http' => $http, 'error' => $error !== '' ? $error : ($json['error']['message'] ?? 'Respuesta invalida de Meta'), 'raw' => $raw];
  }
  return ['ok' => true, 'http' => $http, 'data' => $json];
}

function conv_instagram_channel_for_conversation(PDO $pdo, array $conversation): ?array {
  $channelsTable = ig_channels_table();
  $channelId = (int) ($conversation['channel_id'] ?? 0);
  $accountId = (int) (($conversation['account_id'] ?? current_account_id()) ?: accounts_default_id($pdo));

  if ($channelId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM {$channelsTable} WHERE id=? AND is_active=1 AND COALESCE(page_access_token, '')<>'' LIMIT 1");
    $stmt->execute([$channelId]);
    $channel = $stmt->fetch();
    if ($channel) return $channel;
  }

  $stmt = $pdo->prepare(<<<SQL
SELECT *
FROM {$channelsTable}
WHERE account_id=?
  AND is_active=1
  AND COALESCE(page_access_token, '')<>''
ORDER BY updated_at DESC, id DESC
LIMIT 2
SQL);
  $stmt->execute([$accountId]);
  $channels = $stmt->fetchAll() ?: [];
  if (count($channels) === 1) {
    $channel = $channels[0];
    if ($channelId !== (int) ($channel['id'] ?? 0)) {
      $conversationsTable = conv_conversations_table();
      $update = $pdo->prepare("UPDATE {$conversationsTable} SET channel_id=?, updated_at=NOW() WHERE id=?");
      $update->execute([(int) $channel['id'], (int) ($conversation['id'] ?? 0)]);
    }
    return $channel;
  }

  return null;
}

function conv_send_instagram_message(PDO $pdo, array $conversation, string $message): array {
  $message = trim($message);
  if ($message === '') return ['ok' => false, 'error' => 'El mensaje esta vacio.'];

  $channel = conv_instagram_channel_for_conversation($pdo, $conversation);
  if (!$channel) return ['ok' => false, 'error' => 'Canal de Instagram no disponible o existen varios canales activos para esta cuenta.'];

  $token = (string) ($channel['page_access_token'] ?? '');
  $recipientId = (string) ($conversation['contact_external_id'] ?? '');
  if ($token === '' || $recipientId === '') return ['ok' => false, 'error' => 'Faltan credenciales del canal o destinatario.'];

  $payload = [
    'recipient' => ['id' => $recipientId],
    'message' => ['text' => $message],
    'messaging_type' => 'RESPONSE',
  ];

  $targets = array_values(array_filter([
    (string) ($channel['instagram_user_id'] ?? ''),
    (string) ($channel['page_id'] ?? ''),
    'me',
  ], static fn($value) => trim($value) !== ''));

  $lastError = 'No se pudo enviar el mensaje.';
  foreach (array_unique($targets) as $target) {
    $response = conv_graph_post_json($target . '/messages', $payload, $token);
    if (($response['ok'] ?? false) && isset($response['data']) && is_array($response['data'])) {
      return ['ok' => true, 'data' => $response['data'], 'target' => $target];
    }
    $lastError = (string) ($response['error'] ?? $lastError);
  }

  return ['ok' => false, 'error' => $lastError];
}

function conv_send_instagram_attachment(PDO $pdo, array $conversation, string $mediaUrl, string $mediaType): array {
  $mediaUrl = trim($mediaUrl);
  $mediaType = conv_clean($mediaType, 40) ?? 'image';
  if (!in_array($mediaType, ['image', 'audio'], true)) return ['ok' => false, 'error' => 'Tipo de adjunto no permitido.'];
  if ($mediaUrl === '') return ['ok' => false, 'error' => 'El adjunto no esta disponible.'];

  $channel = conv_instagram_channel_for_conversation($pdo, $conversation);
  if (!$channel) return ['ok' => false, 'error' => 'Canal de Instagram no disponible o existen varios canales activos para esta cuenta.'];

  $token = (string) ($channel['page_access_token'] ?? '');
  $recipientId = (string) ($conversation['contact_external_id'] ?? '');
  if ($token === '' || $recipientId === '') return ['ok' => false, 'error' => 'Faltan credenciales del canal o destinatario.'];

  $payload = [
    'recipient' => ['id' => $recipientId],
    'message' => [
      'attachment' => [
        'type' => $mediaType,
        'payload' => [
          'url' => $mediaUrl,
          'is_reusable' => true,
        ],
      ],
    ],
    'messaging_type' => 'RESPONSE',
  ];

  $targets = array_values(array_filter([
    (string) ($channel['instagram_user_id'] ?? ''),
    (string) ($channel['page_id'] ?? ''),
    'me',
  ], static fn($value) => trim($value) !== ''));

  $lastError = 'No se pudo enviar el adjunto.';
  foreach (array_unique($targets) as $target) {
    $response = conv_graph_post_json($target . '/messages', $payload, $token);
    if (($response['ok'] ?? false) && isset($response['data']) && is_array($response['data'])) {
      return ['ok' => true, 'data' => $response['data'], 'target' => $target];
    }
    $lastError = (string) ($response['error'] ?? $lastError);
  }

  return ['ok' => false, 'error' => $lastError];
}

function conv_send_instagram_image(PDO $pdo, array $conversation, string $imageUrl): array {
  return conv_send_instagram_attachment($pdo, $conversation, $imageUrl, 'image');
}

function conv_send_instagram_audio(PDO $pdo, array $conversation, string $audioUrl): array {
  return conv_send_instagram_attachment($pdo, $conversation, $audioUrl, 'audio');
}

function conv_ensure_lead_for_conversation(PDO $pdo, string $leadsTable, int $conversationId): int {
  if ($conversationId <= 0) return 0;
  conv_ensure_schema($pdo);

  $conversationsTable = conv_conversations_table();
  $contactsTable = conv_contacts_table();
  $channelsTable = ig_channels_table();
  $defaultSalesStatus = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) app_config('sales_funnel.default_status', 'nuevo_lead')) ?: 'nuevo_lead';

  $stmt = $pdo->prepare(<<<SQL
SELECT
  c.id,
  c.account_id,
  c.lead_id,
  c.channel_id,
  c.external_source,
  c.external_thread_id,
  c.last_message_preview,
  c.last_message_at,
  c.created_at,
  ct.external_contact_id,
  ct.display_name,
  ct.username,
  ch.page_id,
  ch.instagram_user_id,
  ch.instagram_username,
  ch.page_name
FROM {$conversationsTable} c
JOIN {$contactsTable} ct ON ct.id = c.contact_id
LEFT JOIN {$channelsTable} ch ON ch.id = c.channel_id
WHERE c.id = ?
LIMIT 1
SQL);
  $stmt->execute([$conversationId]);
  $conversation = $stmt->fetch();
  if (!$conversation) return 0;

  $accountId = (int) (($conversation['account_id'] ?? current_account_id()) ?: accounts_default_id($pdo));
  $currentLeadId = (int) ($conversation['lead_id'] ?? 0);
  if ($currentLeadId > 0) return $currentLeadId;

  $senderId = conv_clean($conversation['external_contact_id'] ?? null, 160);
  if ($senderId === null) return 0;

  $threadId = conv_clean($conversation['external_thread_id'] ?? null, 180);
  $recipientId = null;
  if ($threadId !== null && str_contains($threadId, ':')) {
    $parts = explode(':', $threadId, 2);
    $recipientId = conv_clean($parts[0] ?? null, 160);
  }
  $recipientId = $recipientId
    ?? conv_clean($conversation['instagram_user_id'] ?? null, 160)
    ?? conv_clean($conversation['page_id'] ?? null, 160)
    ?? 'unknown';
  $contactKey = $recipientId . ':' . $senderId;

  $existing = $pdo->prepare("SELECT id FROM {$leadsTable} WHERE account_id=? AND external_source=? AND external_contact_id=? LIMIT 1");
  $existing->execute([$accountId, 'instagram', $contactKey]);
  $leadId = (int) ($existing->fetchColumn() ?: 0);

  $displayName = conv_clean($conversation['display_name'] ?? null, 180);
  $username = conv_clean($conversation['username'] ?? null, 180);
  $fullname = $displayName ?: ($username ? '@' . ltrim($username, '@') : 'Lead Instagram #' . substr($senderId, -6));
  $brandInstagram = $username;
  $messageText = conv_clean($conversation['last_message_preview'] ?? 'Conversacion iniciada desde Instagram.', 5000) ?? 'Conversacion iniciada desde Instagram.';
  $messageAt = conv_clean($conversation['last_message_at'] ?? $conversation['created_at'] ?? gmdate('Y-m-d H:i:s'), 30) ?? gmdate('Y-m-d H:i:s');

  if ($leadId <= 0) {
    $insert = $pdo->prepare(<<<SQL
INSERT INTO {$leadsTable} (
  account_id, fullname, phone, email, brand_instagram, business_type, business_type_other, services_needed, main_objective, message,
  source_platform, utm_source, utm_medium, sales_status, status, whatsapp_sent, whatsapp_status,
  external_source, external_contact_id, external_thread_id, first_message_at, last_message_at, last_inbound_message
) VALUES (
  ?, ?, NULL, NULL, ?, ?, NULL, ?, ?, ?,
  'Instagram DM', 'instagram', 'dm', ?, 'pending', 0, 'disabled',
  'instagram', ?, ?, ?, ?, ?
)
SQL);
    $insert->execute([
      $accountId,
      $fullname,
      $brandInstagram,
      (string) app_config('instagram.default_business_type', 'Instagram DM'),
      (string) app_config('instagram.default_service', 'Mensaje directo de Instagram'),
      (string) app_config('instagram.default_objective', 'Conversación iniciada desde Instagram'),
      $messageText,
      $defaultSalesStatus,
      $contactKey,
      $threadId ?: $contactKey,
      $messageAt,
      $messageAt,
      $messageText,
    ]);
    $leadId = (int) $pdo->lastInsertId();
  } else {
    $update = $pdo->prepare(<<<SQL
UPDATE {$leadsTable}
SET
  fullname = CASE WHEN fullname IS NULL OR fullname = '' OR fullname LIKE 'Lead Instagram #%'
    THEN ? ELSE fullname END,
  brand_instagram = COALESCE(brand_instagram, ?),
  external_thread_id = COALESCE(external_thread_id, ?),
  last_message_at = COALESCE(?, last_message_at),
  last_inbound_message = COALESCE(?, last_inbound_message),
  updated_at = NOW()
WHERE id = ?
SQL);
    $update->execute([$fullname, $brandInstagram, $threadId ?: $contactKey, $messageAt, $messageText, $leadId]);
  }

  if ($leadId > 0) {
    $link = $pdo->prepare("UPDATE {$conversationsTable} SET lead_id=?, account_id=?, updated_at=NOW() WHERE id=? AND (lead_id IS NULL OR lead_id=0)");
    $link->execute([$leadId, $accountId, $conversationId]);
  }

  return $leadId;
}

function conv_backfill_from_leads(PDO $pdo, string $leadsTable): int {
  conv_ensure_schema($pdo);
  $channelsTable = ig_channels_table();
  $stmt = $pdo->query(<<<SQL
SELECT id, account_id, fullname, brand_instagram, external_source, external_contact_id, external_thread_id,
       last_external_message_id, last_message_at, last_inbound_message, message, created_at
FROM {$leadsTable}
WHERE external_source='instagram'
  AND external_contact_id IS NOT NULL
ORDER BY id ASC
SQL);
  $leads = $stmt ? $stmt->fetchAll() : [];
  $count = 0;

  foreach ($leads as $lead) {
    $accountId = (int) (($lead['account_id'] ?? current_account_id()) ?: accounts_default_id($pdo));
    $threadId = conv_clean($lead['external_thread_id'] ?? $lead['external_contact_id'] ?? null, 180);
    if ($threadId === null) continue;
    $parts = explode(':', $threadId);
    $senderId = conv_clean(end($parts) ?: ($lead['external_contact_id'] ?? null), 160);
    if ($senderId === null) continue;

    $recipientId = count($parts) > 1 ? conv_clean($parts[0], 160) : null;
    $channel = null;
    if ($recipientId !== null) {
      try { $channel = ig_channel_find_by_recipient($pdo, $channelsTable, $recipientId); } catch (Throwable $e) { $channel = null; }
    }

    $username = conv_clean($lead['brand_instagram'] ?? null, 180);
    if ($username !== null) {
      $username = trim((string) preg_replace('#^https?://(www\.)?instagram\.com/#i', '', $username), "@/ \t\n\r\0\x0B");
      if (!preg_match('/^[a-zA-Z0-9._]{1,30}$/', $username)) $username = null;
    }

    $displayName = conv_clean($lead['fullname'] ?? null, 180);
    if ($displayName !== null && str_starts_with($displayName, 'Lead Instagram #')) $displayName = $username;
    $messageText = conv_clean($lead['last_inbound_message'] ?? $lead['message'] ?? 'Conversacion importada desde lead.', 5000) ?? 'Conversacion importada desde lead.';
    $messageAt = conv_clean($lead['last_message_at'] ?? $lead['created_at'] ?? gmdate('Y-m-d H:i:s'), 30) ?? gmdate('Y-m-d H:i:s');

    $contactId = conv_upsert_contact($pdo, [
      'account_id' => $accountId,
      'external_source' => 'instagram',
      'external_contact_id' => $senderId,
      'display_name' => $displayName,
      'username' => $username,
      'last_seen_at' => $messageAt,
    ]);
    if ($contactId <= 0) continue;

    $conversationId = conv_upsert_conversation($pdo, [
      'account_id' => $accountId,
      'channel_id' => $channel['id'] ?? null,
      'contact_id' => $contactId,
      'lead_id' => (int) $lead['id'],
      'external_source' => 'instagram',
      'external_thread_id' => $threadId,
      'last_message_preview' => $messageText,
      'last_message_at' => $messageAt,
      'unread_increment' => 0,
    ]);
    if ($conversationId <= 0) continue;

    conv_add_message($pdo, [
      'account_id' => $accountId,
      'conversation_id' => $conversationId,
      'external_message_id' => conv_clean($lead['last_external_message_id'] ?? null, 2000),
      'direction' => 'inbound',
      'sender_external_id' => $senderId,
      'message_type' => 'text',
      'message_text' => $messageText,
      'payload_json' => json_encode(['source' => 'lead_backfill', 'lead_id' => (int) $lead['id']], JSON_UNESCAPED_UNICODE),
      'sent_at' => $messageAt,
      'delivery_status' => 'imported',
    ]);
    $count++;
  }

  return $count;
}
