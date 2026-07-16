<?php
// instagram_webhook.php
// Recibe eventos de Instagram Messaging API y crea/actualiza leads en el CRM.
declare(strict_types=1);

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/instagram_channels.php';
require_once __DIR__ . '/config/conversations.php';

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
  $secrets = array_values(array_unique(array_filter([
    (string) app_config('instagram.app_secret', ''),
    (string) app_config('instagram.facebook_app_secret', ''),
  ], static fn($secret) => trim($secret) !== '')));
  if (!$secrets) return true;
  $signature = (string) ($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '');
  if (!str_starts_with($signature, 'sha256=')) return false;
  foreach ($secrets as $secret) {
    $expected = 'sha256=' . hash_hmac('sha256', $rawBody, $secret);
    if (hash_equals($expected, $signature)) return true;
  }
  return false;
}

function ig_provider_from_payload(array $payload): string {
  $object = strtolower(trim((string) ($payload['object'] ?? '')));
  return $object === 'page' ? 'messenger' : 'instagram';
}

function ig_provider_label(string $provider): string {
  return $provider === 'messenger' ? 'Facebook Messenger' : 'Instagram DM';
}

function ig_provider_source(string $provider): string {
  return $provider === 'messenger' ? 'facebook' : 'instagram';
}

function ig_provider_medium(string $provider): string {
  return $provider === 'messenger' ? 'messenger' : 'dm';
}

function ig_provider_default_business_type(string $provider): string {
  return $provider === 'messenger' ? 'Messenger' : (string) app_config('instagram.default_business_type', 'Instagram DM');
}

function ig_provider_default_service(string $provider): string {
  return $provider === 'messenger' ? 'Mensaje directo de Facebook Messenger' : (string) app_config('instagram.default_service', 'Mensaje directo de Instagram');
}

function ig_provider_default_objective(string $provider): string {
  return $provider === 'messenger' ? 'Conversación iniciada desde Facebook Messenger' : (string) app_config('instagram.default_objective', 'Conversación iniciada desde Instagram');
}

function ig_log_invalid_signature(PDO $pdo, string $rawBody): void {
  try {
    $payload = json_decode($rawBody, true);
    if (!is_array($payload)) $payload = [];
    conv_ensure_schema($pdo);
    $provider = ig_provider_from_payload($payload);
    $firstEvent = [];
    foreach (($payload['entry'] ?? []) as $entry) {
      if (!is_array($entry)) continue;
      foreach (($entry['messaging'] ?? []) as $event) {
        if (is_array($event)) {
          $firstEvent = $event;
          break 2;
        }
      }
    }
    conv_log_webhook_event($pdo, [
      'source' => $provider,
      'status' => 'ignored',
      'event_type' => 'invalid_signature',
      'recipient_id' => ig_clean($firstEvent['recipient']['id'] ?? null, 120),
      'sender_id' => ig_clean($firstEvent['sender']['id'] ?? null, 120),
      'external_message_id' => ig_clean($firstEvent['message']['mid'] ?? $firstEvent['postback']['mid'] ?? null, 2000),
      'message_preview' => ig_event_text($firstEvent, $provider),
      'error_message' => 'Firma invalida. Revisa FACEBOOK_APP_SECRET / INSTAGRAM_APP_SECRET segun la app que envia el webhook.',
      'payload_json' => json_encode($payload ?: ['raw' => mb_substr($rawBody, 0, 2000)], JSON_UNESCAPED_UNICODE),
    ]);
  } catch (Throwable $e) {
    /* No bloquear la respuesta del webhook por diagnostico. */
  }
}

function ig_ensure_leads_schema(PDO $pdo, string $dbName, string $table): void {
  $defaultAccountId = accounts_default_id($pdo);
  $defaultSalesStatus = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) app_config('sales_funnel.default_status', 'nuevo_lead')) ?: 'nuevo_lead';
  $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS {$table} (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  account_id INT UNSIGNED NOT NULL DEFAULT {$defaultAccountId},
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
  last_external_message_id TEXT NULL,
  first_message_at DATETIME NULL,
  last_message_at DATETIME NULL,
  last_inbound_message TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_phone (phone),
  UNIQUE KEY uniq_external_contact (account_id, external_source, external_contact_id),
  KEY idx_account_id (account_id),
  KEY idx_source_platform (source_platform),
  KEY idx_sales_status (sales_status),
  KEY idx_created_at (created_at),
  KEY idx_last_message_at (last_message_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

  try { accounts_add_account_column($pdo, $dbName, $table, $defaultAccountId); } catch (Throwable $e) { /* no-op */ }
  accounts_rebuild_unique_index($pdo, $dbName, $table, 'uniq_external_contact', 'account_id, external_source, external_contact_id');

  $columns = [
    'account_id' => "ALTER TABLE {$table} ADD COLUMN account_id INT UNSIGNED NOT NULL DEFAULT {$defaultAccountId} AFTER id",
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
    'last_external_message_id' => "ALTER TABLE {$table} ADD COLUMN last_external_message_id TEXT NULL AFTER external_thread_id",
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
    'uniq_external_contact' => "ALTER TABLE {$table} ADD UNIQUE KEY uniq_external_contact (account_id, external_source, external_contact_id)",
    'idx_account_id' => "ALTER TABLE {$table} ADD KEY idx_account_id (account_id)",
    'idx_last_message_at' => "ALTER TABLE {$table} ADD KEY idx_last_message_at (last_message_at)",
  ];
  foreach ($indexes as $index => $sql) {
    if (!ig_index_exists($pdo, $dbName, $table, $index)) {
      try { $pdo->exec($sql); } catch (Throwable $e) { /* índice existente */ }
    }
  }

  if (ig_column_exists($pdo, $dbName, $table, 'last_external_message_id')) {
    try { $pdo->exec("ALTER TABLE {$table} MODIFY last_external_message_id TEXT NULL"); } catch (Throwable $e) { /* no-op */ }
  }
}

function ig_ensure_channel_schema_safe(PDO $pdo): string {
  $table = ig_channels_table();
  try { ig_channels_ensure_schema($pdo, $table); } catch (Throwable $e) { /* no-op */ }
  return $table;
}

function ig_event_text(array $event, string $provider = 'instagram'): string {
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
  return 'Mensaje recibido desde ' . ig_provider_label($provider) . '.';
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

function ig_profile_avatar_url(array $data): ?string {
  foreach (['profile_pic', 'profile_picture_url', 'picture'] as $key) {
    $value = $data[$key] ?? null;
    if (is_array($value)) {
      $value = $value['data']['url'] ?? $value['url'] ?? null;
    }
    $url = ig_clean($value, 2000);
    if ($url !== null && preg_match('#^https?://#i', $url)) return $url;
  }
  return null;
}

function ig_profile_payload(array $data, string $provider = 'instagram'): array {
  $name = ig_clean($data['name'] ?? null, 120);
  if ($provider === 'messenger' && $name === null) {
    $name = ig_clean(trim((string) (($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? ''))), 120);
  }
  return [
    'name' => $name,
    'username' => $provider === 'messenger' ? null : ig_clean($data['username'] ?? null, 120),
    'profile_url' => null,
    'avatar_url' => ig_profile_avatar_url($data),
  ];
}

function ig_contact_profile(?array $channel, ?string $senderId, string $provider = 'instagram'): array {
  $senderId = ig_clean($senderId, 120);
  $token = ig_clean($channel['page_access_token'] ?? null, 2000);
  if ($senderId === null || $token === null) return [];

  if ($provider === 'messenger') {
    $response = ig_graph_request_base(ig_graph_base(), 'GET', $senderId, [
      'fields' => 'name,first_name,last_name,profile_pic',
      'access_token' => $token,
    ]);
    if (!($response['ok'] ?? false) || !isset($response['data']) || !is_array($response['data'])) return [];
    return ig_profile_payload($response['data'], $provider);
  }

  $isDirectLogin = (string) ($channel['connection_type'] ?? 'facebook') === 'instagram_login';
  $bases = $isDirectLogin
    ? [ig_instagram_graph_base(), ig_graph_base()]
    : [ig_graph_base(), ig_instagram_graph_base()];
  $fieldSets = [
    'name,username,profile_pic',
    'name,username,profile_picture_url',
    'username,profile_pic',
    'username,profile_picture_url',
    'name,username',
  ];
  $fallbackProfile = null;

  foreach (array_values(array_unique($bases)) as $baseUrl) {
    foreach ($fieldSets as $fields) {
      $candidate = ig_graph_request_base($baseUrl, 'GET', $senderId, [
        'fields' => $fields,
        'access_token' => $token,
      ]);
      if (!($candidate['ok'] ?? false) || !isset($candidate['data']) || !is_array($candidate['data'])) continue;

      $profile = ig_profile_payload($candidate['data'], $provider);
      if (!empty($profile['avatar_url'])) return $profile;
      if ($fallbackProfile === null && ($profile['name'] !== null || $profile['username'] !== null)) {
        $fallbackProfile = $profile;
      }
    }
  }

  return $fallbackProfile ?? [];
}

function ig_download_media(string $url, ?string $accessToken): array {
  $headers = [
    'Accept: image/avif,image/webp,image/apng,image/svg+xml,image/*,audio/*,*/*;q=0.8',
    'User-Agent: PixelsCRM/1.0',
  ];
  if ($accessToken) $headers[] = 'Authorization: Bearer ' . $accessToken;
  $ch = curl_init();
  curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS => 3,
    CURLOPT_TIMEOUT => 35,
  ]);
  $bytes = curl_exec($ch);
  $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
  $error = curl_error($ch);
  curl_close($ch);
  if ($error !== '' || $http < 200 || $http >= 300 || !is_string($bytes) || $bytes === '') {
    return [
      'ok' => false,
      'http' => $http,
      'mime' => trim(explode(';', $contentType)[0] ?? ''),
      'error' => $error !== '' ? $error : 'No se pudo descargar el adjunto de Meta.',
    ];
  }
  return ['ok' => true, 'http' => $http, 'bytes' => $bytes, 'mime' => trim(explode(';', $contentType)[0] ?? '')];
}

function ig_download_media_with_fallback(string $url, ?string $accessToken): array {
  $attempts = [null];
  if ($accessToken) $attempts[] = $accessToken;
  $lastDownload = ['ok' => false, 'error' => 'No se pudo descargar el adjunto de Meta.'];

  foreach ($attempts as $token) {
    $download = ig_download_media($url, $token);
    $lastDownload = $download;
    if (!($download['ok'] ?? false)) continue;

    $bytes = (string) ($download['bytes'] ?? '');
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $detectedMime = $bytes !== '' ? (string) ($finfo->buffer($bytes) ?: '') : '';
    $reportedMime = trim((string) ($download['mime'] ?? ''));
    $mime = $detectedMime !== '' ? $detectedMime : $reportedMime;
    $download['detected_mime'] = $mime;

    if (!in_array(strtolower($mime), ['text/html', 'text/plain', 'application/json'], true)) {
      return $download;
    }
    $lastDownload = [
      'ok' => false,
      'http' => $download['http'] ?? null,
      'mime' => $download['mime'] ?? null,
      'detected_mime' => $mime,
      'error' => 'Meta devolvió ' . $mime . ' en lugar de un adjunto multimedia.',
    ];
  }

  return $lastDownload;
}

function ig_media_allowed_mimes(string $type): array {
  return match ($type) {
    'audio' => (array) app_config('media.allowed_audio_mimes', []),
    default => (array) app_config('media.allowed_image_mimes', []),
  };
}

function ig_media_label(string $type): string {
  return $type === 'audio' ? 'audio' : 'imagen';
}

function ig_store_message_attachments(PDO $pdo, int $conversationId, int $messageId, array $event, ?array $channel, string $provider = 'instagram'): array {
  $result = ['total' => 0, 'stored' => 0, 'errors' => []];
  if ($conversationId <= 0 || $messageId <= 0) {
    $result['errors'][] = 'Conversación o mensaje inválido para adjuntos.';
    return $result;
  }
  $attachments = $event['message']['attachments'] ?? [];
  if (!is_array($attachments) || !$attachments) return $result;
  if (!r2_is_configured()) {
    $result['total'] = count($attachments);
    $result['errors'][] = 'R2 no está configurado en el servidor.';
    return $result;
  }

  $maxBytes = max(1024, (int) app_config('media.max_upload_bytes', 8388608));
  $token = ig_clean($channel['page_access_token'] ?? null, 2000);

  foreach ($attachments as $attachment) {
    if (!is_array($attachment)) continue;
    $result['total']++;
    $type = ig_clean($attachment['type'] ?? 'image', 40) ?? 'image';
    if (!in_array($type, ['image', 'audio'], true)) continue;
    $label = ig_media_label($type);
    $payload = $attachment['payload'] ?? [];
    $payload = is_array($payload) ? $payload : [];
    $url = trim(str_replace("\0", '', (string) ($payload['url'] ?? '')));
    if ($url === '') {
      $result['errors'][] = 'Adjunto de ' . $label . ' sin payload.url.';
      continue;
    }

    $download = ig_download_media_with_fallback($url, $token);
    if (!($download['ok'] ?? false)) {
      $result['errors'][] = 'No se pudo descargar ' . $label . ' desde Meta: ' . (string) ($download['error'] ?? 'error desconocido');
      continue;
    }
    $bytes = (string) ($download['bytes'] ?? '');
    if ($bytes === '') {
      $result['errors'][] = 'Meta devolvió un adjunto vacío.';
      continue;
    }
    if (strlen($bytes) > $maxBytes) {
      $result['errors'][] = 'Adjunto supera el tamaño permitido.';
      continue;
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string) ($download['detected_mime'] ?? ($finfo->buffer($bytes) ?: ($download['mime'] ?? '')));
    $allowedMimes = ig_media_allowed_mimes($type);
    if (!in_array($mime, $allowedMimes, true)) {
      $result['errors'][] = 'MIME no permitido: ' . $mime;
      continue;
    }
    if ($type === 'audio' && $mime === 'video/mp4') $mime = 'audio/mp4';
    if ($type === 'audio' && $mime === 'video/webm') $mime = 'audio/webm';
    if ($type === 'audio' && $mime === 'application/ogg') $mime = 'audio/ogg';

    $key = r2_random_key($provider . '/inbound/' . $type . '/' . $conversationId, $mime);
    $upload = r2_upload_bytes($key, $bytes, $mime);
    if (!($upload['ok'] ?? false)) {
      $result['errors'][] = 'R2 no aceptó el adjunto: ' . (string) ($upload['error'] ?? 'error desconocido');
      continue;
    }

    $attachmentId = conv_add_attachment($pdo, [
      'conversation_id' => $conversationId,
      'message_id' => $messageId,
      'direction' => 'inbound',
      'media_type' => $type,
      'mime_type' => $mime,
      'file_size' => strlen($bytes),
      'storage_disk' => 'r2',
      'storage_key' => $key,
      'original_url' => $url,
      'filename' => basename(parse_url($url, PHP_URL_PATH) ?: ($provider . '-' . $type . '.' . r2_extension_from_mime($mime))),
      'external_attachment_id' => ig_clean($payload['attachment_id'] ?? null, 180),
    ]);
    if ($attachmentId > 0) $result['stored']++;
    else $result['errors'][] = 'El adjunto subió a R2, pero no se guardó en la base de datos.';
  }
  return $result;
}

function ig_sync_conversation(PDO $pdo, ?array $channel, string $senderId, string $threadId, ?string $messageId, string $messageText, string $messageAt, ?string $profileName, ?string $profileUsername, int $leadId, array $event, string $provider = 'instagram'): array {
  try {
    conv_ensure_schema($pdo);
    $accountId = (int) (($channel['account_id'] ?? current_account_id()) ?: accounts_default_id($pdo));
    $contactId = conv_upsert_contact($pdo, [
      'account_id' => $accountId,
      'external_source' => $provider,
      'external_contact_id' => $senderId,
      'display_name' => $profileName ?: $profileUsername,
      'username' => $profileUsername,
      'profile_url' => ig_clean($event['_profile_url'] ?? null, 255),
      'avatar_url' => ig_clean($event['_avatar_url'] ?? null, 2000),
      'last_seen_at' => $messageAt,
    ]);
    if ($contactId <= 0) return ['conversation_id' => 0, 'message_id' => 0, 'message_inserted' => false, 'error' => 'No se pudo guardar el contacto.'];

    $conversationId = conv_upsert_conversation($pdo, [
      'account_id' => $accountId,
      'channel_id' => $channel['id'] ?? null,
      'contact_id' => $contactId,
      'lead_id' => $leadId > 0 ? $leadId : null,
      'external_source' => $provider,
      'external_thread_id' => $threadId,
      'last_message_preview' => $messageText,
      'last_message_at' => $messageAt,
      'unread_increment' => 0,
    ]);
    if ($conversationId <= 0) return ['conversation_id' => 0, 'message_id' => 0, 'message_inserted' => false, 'error' => 'No se pudo guardar la conversacion.'];

    $messageType = isset($event['message']['text']) ? 'text' : (isset($event['postback']) ? 'postback' : 'attachment');
    $messagesTable = conv_messages_table();
    $messageAlreadyExists = false;
    if ($messageId !== null) {
      $existsStmt = $pdo->prepare("SELECT id FROM {$messagesTable} WHERE conversation_id=? AND external_message_hash=? LIMIT 1");
      $existsStmt->execute([$conversationId, hash('sha256', $messageId)]);
      $messageAlreadyExists = (bool) $existsStmt->fetchColumn();
    }
    $inserted = conv_add_message($pdo, [
      'conversation_id' => $conversationId,
      'external_message_id' => $messageId,
      'direction' => 'inbound',
      'sender_external_id' => $senderId,
      'message_type' => $messageType,
      'message_text' => $messageText,
      'payload_json' => json_encode($event, JSON_UNESCAPED_UNICODE),
      'sent_at' => $messageAt,
      'delivery_status' => 'received',
    ]);

    $attachmentResult = ['total' => 0, 'stored' => 0, 'errors' => []];
    if ($inserted > 0) {
      $attachmentResult = ig_store_message_attachments($pdo, $conversationId, $inserted, $event, $channel, $provider);
      conv_upsert_conversation($pdo, [
        'account_id' => $accountId,
        'channel_id' => $channel['id'] ?? null,
        'contact_id' => $contactId,
        'lead_id' => $leadId > 0 ? $leadId : null,
        'external_source' => $provider,
        'external_thread_id' => $threadId,
        'last_message_preview' => $messageText,
        'last_message_at' => $messageAt,
        'unread_increment' => 1,
      ]);
    }
    return [
      'conversation_id' => $conversationId,
      'message_id' => $inserted,
      'message_inserted' => $inserted > 0 && !$messageAlreadyExists,
      'attachments_total' => (int) ($attachmentResult['total'] ?? 0),
      'attachments_stored' => (int) ($attachmentResult['stored'] ?? 0),
      'attachment_errors' => (array) ($attachmentResult['errors'] ?? []),
      'error' => $inserted > 0 ? null : 'No se pudo guardar el mensaje en conversation_messages.',
    ];
  } catch (Throwable $e) {
    /* El webhook no debe fallar si el historial conversacional no pudo escribirse. */
    return ['conversation_id' => 0, 'message_id' => 0, 'message_inserted' => false, 'error' => $e->getMessage()];
  }
}

function ig_upsert_lead(PDO $pdo, string $table, string $channelsTable, array $event, string $provider = 'instagram'): int {
  $senderId = ig_clean($event['sender']['id'] ?? null, 120);
  if ($senderId === null) return 0;

  $recipientId = ig_clean($event['recipient']['id'] ?? null, 120);
  $messageId = ig_clean($event['message']['mid'] ?? $event['postback']['mid'] ?? null, 2000);
  $messageText = ig_event_text($event, $provider);
  if ($recipientId === null) {
    conv_log_webhook_event($pdo, [
      'source' => $provider,
      'status' => 'ignored',
      'event_type' => 'missing_recipient',
      'sender_id' => $senderId,
      'external_message_id' => $messageId,
      'message_preview' => $messageText,
      'error_message' => 'Evento sin recipient.id.',
      'payload_json' => json_encode($event, JSON_UNESCAPED_UNICODE),
    ]);
    return 0;
  }

  $channel = ig_channel_find_by_recipient($pdo, $channelsTable, $recipientId, $provider);
  if (!$channel) {
    conv_log_webhook_event($pdo, [
      'source' => $provider,
      'status' => 'ignored',
      'event_type' => 'inactive_or_unknown_channel',
      'recipient_id' => $recipientId,
      'sender_id' => $senderId,
      'external_message_id' => $messageId,
      'message_preview' => $messageText,
      'error_message' => 'No existe canal activo para el recipient.',
      'payload_json' => json_encode($event, JSON_UNESCAPED_UNICODE),
    ]);
    return 0;
  }
  $accountId = (int) (($channel['account_id'] ?? current_account_id()) ?: accounts_default_id($pdo));

  $messageAt = ig_message_time($event['timestamp'] ?? null);
  $threadId = $recipientId !== null ? $recipientId . ':' . $senderId : $senderId;
  $ref = ig_referral_data($event);
  $profile = ig_contact_profile($channel ?: null, $senderId, $provider);
  $profileName = $profile['name'] ?? null;
  $profileUsername = $profile['username'] ?? null;
  if (!empty($profile['profile_url'])) $event['_profile_url'] = $profile['profile_url'];
  if (!empty($profile['avatar_url'])) $event['_avatar_url'] = $profile['avatar_url'];
  $profileDisplayName = $profileName ?: $profileUsername;

  $contactKey = $recipientId !== null ? $recipientId . ':' . $senderId : $senderId;

  $existing = $pdo->prepare("SELECT id FROM {$table} WHERE account_id=? AND external_source=? AND external_contact_id=? LIMIT 1");
  $existing->execute([$accountId, $provider, $contactKey]);
  $leadId = (int) ($existing->fetchColumn() ?: 0);

  if ($leadId > 0) {
    $update = $pdo->prepare(<<<SQL
UPDATE {$table}
SET
  fullname = CASE
    WHEN ? IS NOT NULL AND (fullname = '' OR fullname LIKE 'Lead Instagram #%' OR fullname LIKE 'Lead Facebook Messenger #%') THEN ?
    ELSE fullname
  END,
  brand_instagram = CASE WHEN ? IS NOT NULL THEN ? ELSE brand_instagram END,
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
    $update->execute([$profileDisplayName, $profileDisplayName, $profileUsername, $profileUsername, $threadId, $messageId, $messageAt, $messageText, $messageText, $ref['campaign'], $ref['ad_name'], $ref['ad_id'], $ref['content'], $leadId]);
    if ($channel) {
      try {
        $stamp = $pdo->prepare("UPDATE {$channelsTable} SET last_event_at=?, updated_at=NOW() WHERE id=?");
        $stamp->execute([$messageAt, (int) $channel['id']]);
      } catch (Throwable $e) { /* no-op */ }
    }
    $sync = ig_sync_conversation($pdo, $channel ?: null, $senderId, $threadId, $messageId, $messageText, $messageAt, $profileName, $profileUsername, $leadId, $event, $provider);
    $conversationId = (int) ($sync['conversation_id'] ?? 0);
    $conversationMessageId = (int) ($sync['message_id'] ?? 0);
    $messageInserted = (bool) ($sync['message_inserted'] ?? false);
    $attachmentErrors = (array) ($sync['attachment_errors'] ?? []);
    $attachmentError = $attachmentErrors ? implode(' | ', array_slice(array_map('strval', $attachmentErrors), 0, 3)) : null;
    conv_log_webhook_event($pdo, [
      'source' => $provider,
      'status' => $conversationMessageId > 0 ? ($messageInserted ? 'processed' : 'duplicate') : 'lead_only',
      'account_id' => $accountId,
      'event_type' => isset($event['message']['text']) ? 'message' : (isset($event['postback']) ? 'postback' : 'attachment'),
      'recipient_id' => $recipientId,
      'sender_id' => $senderId,
      'channel_id' => (int) $channel['id'],
      'channel_username' => $provider === 'messenger' ? ($channel['page_name'] ?? null) : ($channel['instagram_username'] ?? $channel['page_name'] ?? null),
      'external_message_id' => $messageId,
      'lead_id' => $leadId,
      'conversation_id' => $conversationId > 0 ? $conversationId : null,
      'message_preview' => $messageText,
      'error_message' => $conversationMessageId > 0 ? $attachmentError : (string) ($sync['error'] ?? 'Lead actualizado, pero no se pudo sincronizar el mensaje.'),
      'payload_json' => json_encode($event, JSON_UNESCAPED_UNICODE),
    ]);
    return $leadId;
  }

  $defaultSalesStatus = (string) app_config('sales_funnel.default_status', 'nuevo_lead');
  $fullname = $profileDisplayName ?: 'Lead ' . ig_provider_label($provider) . ' #' . substr($senderId, -6);
  $brandInstagram = $provider === 'instagram' ? $profileUsername : null;

  $insert = $pdo->prepare(<<<SQL
INSERT INTO {$table} (
  account_id, fullname, phone, email, brand_instagram, business_type, business_type_other, services_needed, main_objective, message,
  source_platform, utm_source, utm_medium, utm_campaign, utm_content, ad_name, ad_id,
  sales_status, status, whatsapp_sent, whatsapp_status, external_source, external_contact_id, external_thread_id,
  last_external_message_id, first_message_at, last_message_at, last_inbound_message
) VALUES (
  ?, ?, NULL, NULL, ?, ?, NULL, ?, ?, ?,
  ?, ?, ?, ?, ?, ?, ?,
  ?, 'pending', 0, 'disabled', ?, ?, ?,
  ?, ?, ?, ?
)
SQL);
  $insert->execute([
    $accountId,
    $fullname,
    $brandInstagram,
    ig_provider_default_business_type($provider),
    ig_provider_default_service($provider),
    ig_provider_default_objective($provider),
    $messageText,
    ig_provider_label($provider),
    ig_provider_source($provider),
    ig_provider_medium($provider),
    $ref['campaign'],
    $ref['content'],
    $ref['ad_name'],
    $ref['ad_id'],
    $defaultSalesStatus,
    $provider,
    $contactKey,
    $threadId,
    $messageId,
    $messageAt,
    $messageAt,
    $messageText,
  ]);

  if ($channel) {
    try {
      $stamp = $pdo->prepare("UPDATE {$channelsTable} SET last_event_at=?, updated_at=NOW() WHERE id=?");
      $stamp->execute([$messageAt, (int) $channel['id']]);
    } catch (Throwable $e) { /* no-op */ }
  }

  $leadId = (int) $pdo->lastInsertId();
  $sync = ig_sync_conversation($pdo, $channel ?: null, $senderId, $threadId, $messageId, $messageText, $messageAt, $profileName, $profileUsername, $leadId, $event, $provider);
  $conversationId = (int) ($sync['conversation_id'] ?? 0);
  $conversationMessageId = (int) ($sync['message_id'] ?? 0);
  $messageInserted = (bool) ($sync['message_inserted'] ?? false);
  $attachmentErrors = (array) ($sync['attachment_errors'] ?? []);
  $attachmentError = $attachmentErrors ? implode(' | ', array_slice(array_map('strval', $attachmentErrors), 0, 3)) : null;
  conv_log_webhook_event($pdo, [
    'source' => $provider,
    'status' => $conversationMessageId > 0 ? ($messageInserted ? 'processed' : 'duplicate') : 'lead_only',
    'account_id' => $accountId,
    'event_type' => isset($event['message']['text']) ? 'message' : (isset($event['postback']) ? 'postback' : 'attachment'),
    'recipient_id' => $recipientId,
    'sender_id' => $senderId,
    'channel_id' => (int) $channel['id'],
    'channel_username' => $provider === 'messenger' ? ($channel['page_name'] ?? null) : ($channel['instagram_username'] ?? $channel['page_name'] ?? null),
    'external_message_id' => $messageId,
    'lead_id' => $leadId,
    'conversation_id' => $conversationId > 0 ? $conversationId : null,
    'message_preview' => $messageText,
    'error_message' => $conversationMessageId > 0 ? $attachmentError : (string) ($sync['error'] ?? 'Lead creado, pero no se pudo sincronizar el mensaje.'),
    'payload_json' => json_encode($event, JSON_UNESCAPED_UNICODE),
  ]);
  return $leadId;
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
  echo 'Webhook de Meta no verificado.';
  exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  ig_json(['ok' => false, 'error' => 'Método no permitido'], 405);
}

$raw = (string) file_get_contents('php://input');
if (!ig_validate_signature($raw)) {
  ig_log_invalid_signature($pdo, $raw);
  ig_json(['ok' => false, 'error' => 'Firma inválida'], 403);
}

$payload = json_decode($raw, true);
if (!is_array($payload)) {
  ig_json(['ok' => false, 'error' => 'JSON inválido'], 400);
}

try {
  ig_ensure_leads_schema($pdo, $DB_NAME, $TABLE_LEADS);
  $channelsTable = ig_ensure_channel_schema_safe($pdo);
  $provider = ig_provider_from_payload($payload);
  $createdOrUpdated = [];
  $ignoredEvents = 0;
  foreach (($payload['entry'] ?? []) as $entry) {
    if (!is_array($entry)) continue;
    foreach (($entry['messaging'] ?? []) as $event) {
      if (!is_array($event)) continue;
      if (!empty($event['message']['is_echo'])) continue;
      if (!isset($event['message']) && !isset($event['postback'])) continue;
      $leadId = ig_upsert_lead($pdo, $TABLE_LEADS, $channelsTable, $event, $provider);
      if ($leadId > 0) $createdOrUpdated[] = $leadId;
      else $ignoredEvents++;
    }
  }

  ig_json(['ok' => true, 'lead_ids' => array_values(array_unique($createdOrUpdated)), 'ignored_events' => $ignoredEvents]);
} catch (Throwable $e) {
  ig_json(['ok' => false, 'error' => 'No se pudo procesar el webhook'], 500);
}
