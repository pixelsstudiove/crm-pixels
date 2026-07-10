<?php
// instagram_webhook.php
// Recibe eventos de Instagram Messaging API y crea/actualiza leads en el CRM.
declare(strict_types=1);

require_once __DIR__ . '/config/db.php';

function ig_json(array $payload, int $status = 200): void {
  if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
  http_response_code($status);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}

function ig_column_exists(PDO $pdo, string $dbName, string $table, string $column): bool {
  $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?");
  $stmt->execute([$dbName, $table, $column]);
  return (int) $stmt->fetchColumn() > 0;
}

function ig_index_exists(PDO $pdo, string $dbName, string $table, string $index): bool {
  $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?");
  $stmt->execute([$dbName, $table, $index]);
  return (int) $stmt->fetchColumn() > 0;
}

function ig_clean($value, int $max = 160): ?string {
  $value = trim(str_replace("\0", '', (string) $value));
  if ($value === '') return null;
  return mb_substr($value, 0, $max);
}

function ig_message_time($timestamp): string {
  $raw = (int) $timestamp;
  if ($raw > 9999999999) $raw = (int) floor($raw / 1000);
  if ($raw <= 0) $raw = time();
  return gmdate('Y-m-d H:i:s', $raw);
}

function ig_validate_signature(string $rawBody): bool {
  $secret = (string) app_config('instagram.app_secret', '');
  if ($secret === '') return true;
  $signature = (string) ($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '');
  if (!str_starts_with($signature, 'sha256=')) return false;
  $expected = 'sha256=' . hash_hmac('sha256', $rawBody, $secret);
  return hash_equals($expected, $signature);
}

function ig_ensure_leads_schema(PDO $pdo, string $dbName, string $table): void {
  $defaultSalesStatus = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) app_config('sales_funnel.default_status', 'nuevo_lead')) ?: 'nuevo_lead';
  $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS {$table} (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  fullname VARCHAR(120) NOT NULL,
  phone VARCHAR(64) NULL,
  email VARCHAR(150) NULL,
  brand_instagram VARCHAR(120) NULL,
  business_type VARCHAR(80) NULL,
  business_type_other VARCHAR(120) NULL,
  services_needed TEXT NULL,
  main_objective VARCHAR(120) NULL,
  message TEXT NULL,
  source_platform VARCHAR(80) NULL,
  utm_source VARCHAR(80) NULL,
  utm_medium VARCHAR(80) NULL,
  utm_campaign VARCHAR(120) NULL,
  utm_content VARCHAR(160) NULL,
  utm_term VARCHAR(160) NULL,
  ad_name VARCHAR(180) NULL,
  ad_id VARCHAR(120) NULL,
  gclid VARCHAR(180) NULL,
  fbclid VARCHAR(180) NULL,
  landing_url TEXT NULL,
  referrer TEXT NULL,
  sales_status VARCHAR(50) NOT NULL DEFAULT '{$defaultSalesStatus}',
  notes TEXT NULL,
  reminder_at DATETIME NULL,
  reminder_note VARCHAR(255) NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'pending',
  ip VARCHAR(64) NULL,
  user_agent VARCHAR(255) NULL,
  whatsapp_sent TINYINT(1) NOT NULL DEFAULT 0,
  whatsapp_status VARCHAR(32) NULL,
  external_source VARCHAR(40) NULL,
  external_contact_id VARCHAR(120) NULL,
  external_thread_id VARCHAR(120) NULL,
  last_external_message_id VARCHAR(120) NULL,
  first_message_at DATETIME NULL,
  last_message_at DATETIME NULL,
  last_inbound_message TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_phone (phone),
  UNIQUE KEY uniq_external_contact (external_source, external_contact_id),
  KEY idx_source_platform (source_platform),
  KEY idx_sales_status (sales_status),
  KEY idx_created_at (created_at),
  KEY idx_last_message_at (last_message_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

  $columns = [
    'phone' => "ALTER TABLE {$table} ADD COLUMN phone VARCHAR(64) NULL AFTER fullname",
    'email' => "ALTER TABLE {$table} ADD COLUMN email VARCHAR(150) NULL AFTER phone",
    'brand_instagram' => "ALTER TABLE {$table} ADD COLUMN brand_instagram VARCHAR(120) NULL AFTER email",
    'business_type' => "ALTER TABLE {$table} ADD COLUMN business_type VARCHAR(80) NULL AFTER brand_instagram",
    'business_type_other' => "ALTER TABLE {$table} ADD COLUMN business_type_other VARCHAR(120) NULL AFTER business_type",
    'services_needed' => "ALTER TABLE {$table} ADD COLUMN services_needed TEXT NULL AFTER business_type_other",
    'main_objective' => "ALTER TABLE {$table} ADD COLUMN main_objective VARCHAR(120) NULL AFTER services_needed",
    'message' => "ALTER TABLE {$table} ADD COLUMN message TEXT NULL AFTER main_objective",
    'source_platform' => "ALTER TABLE {$table} ADD COLUMN source_platform VARCHAR(80) NULL AFTER message",
    'utm_source' => "ALTER TABLE {$table} ADD COLUMN utm_source VARCHAR(80) NULL AFTER source_platform",
    'utm_medium' => "ALTER TABLE {$table} ADD COLUMN utm_medium VARCHAR(80) NULL AFTER utm_source",
    'utm_campaign' => "ALTER TABLE {$table} ADD COLUMN utm_campaign VARCHAR(120) NULL AFTER utm_medium",
    'utm_content' => "ALTER TABLE {$table} ADD COLUMN utm_content VARCHAR(160) NULL AFTER utm_campaign",
    'utm_term' => "ALTER TABLE {$table} ADD COLUMN utm_term VARCHAR(160) NULL AFTER utm_content",
    'ad_name' => "ALTER TABLE {$table} ADD COLUMN ad_name VARCHAR(180) NULL AFTER utm_term",
    'ad_id' => "ALTER TABLE {$table} ADD COLUMN ad_id VARCHAR(120) NULL AFTER ad_name",
    'gclid' => "ALTER TABLE {$table} ADD COLUMN gclid VARCHAR(180) NULL AFTER ad_id",
    'fbclid' => "ALTER TABLE {$table} ADD COLUMN fbclid VARCHAR(180) NULL AFTER gclid",
    'landing_url' => "ALTER TABLE {$table} ADD COLUMN landing_url TEXT NULL AFTER fbclid",
    'referrer' => "ALTER TABLE {$table} ADD COLUMN referrer TEXT NULL AFTER landing_url",
    'sales_status' => "ALTER TABLE {$table} ADD COLUMN sales_status VARCHAR(50) NOT NULL DEFAULT '{$defaultSalesStatus}' AFTER referrer",
    'notes' => "ALTER TABLE {$table} ADD COLUMN notes TEXT NULL AFTER sales_status",
    'reminder_at' => "ALTER TABLE {$table} ADD COLUMN reminder_at DATETIME NULL AFTER notes",
    'reminder_note' => "ALTER TABLE {$table} ADD COLUMN reminder_note VARCHAR(255) NULL AFTER reminder_at",
    'status' => "ALTER TABLE {$table} ADD COLUMN status VARCHAR(32) NOT NULL DEFAULT 'pending'",
    'ip' => "ALTER TABLE {$table} ADD COLUMN ip VARCHAR(64) NULL",
    'user_agent' => "ALTER TABLE {$table} ADD COLUMN user_agent VARCHAR(255) NULL",
    'whatsapp_sent' => "ALTER TABLE {$table} ADD COLUMN whatsapp_sent TINYINT(1) NOT NULL DEFAULT 0",
    'whatsapp_status' => "ALTER TABLE {$table} ADD COLUMN whatsapp_status VARCHAR(32) NULL",
    'external_source' => "ALTER TABLE {$table} ADD COLUMN external_source VARCHAR(40) NULL AFTER whatsapp_status",
    'external_contact_id' => "ALTER TABLE {$table} ADD COLUMN external_contact_id VARCHAR(120) NULL AFTER external_source",
    'external_thread_id' => "ALTER TABLE {$table} ADD COLUMN external_thread_id VARCHAR(120) NULL AFTER external_contact_id",
    'last_external_message_id' => "ALTER TABLE {$table} ADD COLUMN last_external_message_id VARCHAR(120) NULL AFTER external_thread_id",
    'first_message_at' => "ALTER TABLE {$table} ADD COLUMN first_message_at DATETIME NULL AFTER last_external_message_id",
    'last_message_at' => "ALTER TABLE {$table} ADD COLUMN last_message_at DATETIME NULL AFTER first_message_at",
    'last_inbound_message' => "ALTER TABLE {$table} ADD COLUMN last_inbound_message TEXT NULL AFTER last_message_at",
    'updated_at' => "ALTER TABLE {$table} ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP",
  ];
  foreach ($columns as $column => $sql) {
    if (!ig_column_exists($pdo, $dbName, $table, $column)) $pdo->exec($sql);
  }

  foreach ([
    'phone' => 'VARCHAR(64) NULL',
    'email' => 'VARCHAR(150) NULL',
    'brand_instagram' => 'VARCHAR(120) NULL',
    'business_type' => 'VARCHAR(80) NULL',
    'services_needed' => 'TEXT NULL',
    'main_objective' => 'VARCHAR(120) NULL',
  ] as $column => $definition) {
    if (ig_column_exists($pdo, $dbName, $table, $column)) {
      try { $pdo->exec("ALTER TABLE {$table} MODIFY {$column} {$definition}"); } catch (Throwable $e) { /* no-op */ }
    }
  }

  $indexes = [
    'uniq_external_contact' => "ALTER TABLE {$table} ADD UNIQUE KEY uniq_external_contact (external_source, external_contact_id)",
    'idx_last_message_at' => "ALTER TABLE {$table} ADD KEY idx_last_message_at (last_message_at)",
  ];
  foreach ($indexes as $index => $sql) {
    if (!ig_index_exists($pdo, $dbName, $table, $index)) {
      try { $pdo->exec($sql); } catch (Throwable $e) { /* índice existente */ }
    }
  }
}

function ig_event_text(array $event): string {
  $text = ig_clean($event['message']['text'] ?? null, 1200);
  if ($text !== null) return $text;
  $postback = ig_clean($event['postback']['title'] ?? null, 1200);
  if ($postback !== null) return 'Postback: ' . $postback;
  $attachments = $event['message']['attachments'] ?? [];
  if (is_array($attachments) && $attachments) {
    $types = [];
    foreach ($attachments as $attachment) {
      $type = ig_clean($attachment['type'] ?? 'adjunto', 60) ?? 'adjunto';
      $types[] = $type;
    }
    return 'Adjunto recibido: ' . implode(', ', array_unique($types));
  }
  return 'Mensaje recibido desde Instagram.';
}

function ig_referral_data(array $event): array {
  $referral = $event['referral'] ?? ($event['message']['referral'] ?? []);
  $referral = is_array($referral) ? $referral : [];
  $ads = $referral['ads_context_data'] ?? [];
  $ads = is_array($ads) ? $ads : [];
  return [
    'campaign' => ig_clean($referral['ref'] ?? $referral['source'] ?? null, 120),
    'ad_name' => ig_clean($ads['ad_title'] ?? $ads['source'] ?? null, 180),
    'ad_id' => ig_clean($referral['ad_id'] ?? $ads['ad_id'] ?? null, 120),
    'content' => ig_clean($ads['post_id'] ?? $ads['photo_url'] ?? $ads['video_url'] ?? null, 160),
  ];
}

function ig_upsert_lead(PDO $pdo, string $table, array $event): int {
  $senderId = ig_clean($event['sender']['id'] ?? null, 120);
  if ($senderId === null) return 0;

  $recipientId = ig_clean($event['recipient']['id'] ?? null, 120);
  $messageId = ig_clean($event['message']['mid'] ?? $event['postback']['mid'] ?? null, 120);
  $messageText = ig_event_text($event);
  $messageAt = ig_message_time($event['timestamp'] ?? null);
  $threadId = $recipientId !== null ? $recipientId . ':' . $senderId : $senderId;
  $ref = ig_referral_data($event);

  $existing = $pdo->prepare("SELECT id FROM {$table} WHERE external_source=? AND external_contact_id=? LIMIT 1");
  $existing->execute(['instagram', $senderId]);
  $leadId = (int) ($existing->fetchColumn() ?: 0);

  if ($leadId > 0) {
    $update = $pdo->prepare(<<<SQL
UPDATE {$table}
SET
  external_thread_id = ?,
  last_external_message_id = ?,
  last_message_at = ?,
  last_inbound_message = ?,
  message = COALESCE(message, ?),
  utm_campaign = COALESCE(utm_campaign, ?),
  ad_name = COALESCE(ad_name, ?),
  ad_id = COALESCE(ad_id, ?),
  utm_content = COALESCE(utm_content, ?),
  updated_at = NOW()
WHERE id = ?
SQL);
    $update->execute([$threadId, $messageId, $messageAt, $messageText, $messageText, $ref['campaign'], $ref['ad_name'], $ref['ad_id'], $ref['content'], $leadId]);
    return $leadId;
  }

  $defaultSalesStatus = (string) app_config('sales_funnel.default_status', 'nuevo_lead');
  $fullname = 'Lead Instagram #' . substr($senderId, -6);
  $brandInstagram = 'Instagram ID ' . $senderId;

  $insert = $pdo->prepare(<<<SQL
INSERT INTO {$table} (
  fullname, phone, email, brand_instagram, business_type, business_type_other, services_needed, main_objective, message,
  source_platform, utm_source, utm_medium, utm_campaign, utm_content, ad_name, ad_id,
  sales_status, status, whatsapp_sent, whatsapp_status, external_source, external_contact_id, external_thread_id,
  last_external_message_id, first_message_at, last_message_at, last_inbound_message
) VALUES (
  ?, NULL, NULL, ?, ?, NULL, ?, ?, ?,
  'Instagram DM', 'instagram', 'dm', ?, ?, ?, ?,
  ?, 'pending', 0, 'disabled', 'instagram', ?, ?,
  ?, ?, ?, ?
)
SQL);
  $insert->execute([
    $fullname,
    $brandInstagram,
    (string) app_config('instagram.default_business_type', 'Instagram DM'),
    (string) app_config('instagram.default_service', 'Mensaje directo de Instagram'),
    (string) app_config('instagram.default_objective', 'Conversación iniciada desde Instagram'),
    $messageText,
    $ref['campaign'],
    $ref['content'],
    $ref['ad_name'],
    $ref['ad_id'],
    $defaultSalesStatus,
    $senderId,
    $threadId,
    $messageId,
    $messageAt,
    $messageAt,
    $messageText,
  ]);

  return (int) $pdo->lastInsertId();
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  $mode = (string) ($_GET['hub_mode'] ?? $_GET['hub.mode'] ?? '');
  $token = (string) ($_GET['hub_verify_token'] ?? $_GET['hub.verify_token'] ?? '');
  $challenge = (string) ($_GET['hub_challenge'] ?? $_GET['hub.challenge'] ?? '');
  $expected = (string) app_config('instagram.webhook_verify_token', '');
  if ($mode === 'subscribe' && $expected !== '' && hash_equals($expected, $token)) {
    if (!headers_sent()) header('Content-Type: text/plain; charset=utf-8');
    echo $challenge;
    exit;
  }
  http_response_code(403);
  echo 'Webhook de Instagram no verificado.';
  exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  ig_json(['ok' => false, 'error' => 'Método no permitido'], 405);
}

$raw = (string) file_get_contents('php://input');
if (!ig_validate_signature($raw)) {
  ig_json(['ok' => false, 'error' => 'Firma inválida'], 403);
}

$payload = json_decode($raw, true);
if (!is_array($payload)) {
  ig_json(['ok' => false, 'error' => 'JSON inválido'], 400);
}

try {
  ig_ensure_leads_schema($pdo, $DB_NAME, $TABLE_LEADS);
  $createdOrUpdated = [];
  foreach (($payload['entry'] ?? []) as $entry) {
    if (!is_array($entry)) continue;
    foreach (($entry['messaging'] ?? []) as $event) {
      if (!is_array($event)) continue;
      $leadId = ig_upsert_lead($pdo, $TABLE_LEADS, $event);
      if ($leadId > 0) $createdOrUpdated[] = $leadId;
    }
  }

  ig_json(['ok' => true, 'lead_ids' => array_values(array_unique($createdOrUpdated))]);
} catch (Throwable $e) {
  ig_json(['ok' => false, 'error' => 'No se pudo procesar el webhook'], 500);
}
