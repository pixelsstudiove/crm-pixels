<?php
// instagram_webhook.php
// Recibe eventos de Instagram Messaging API y crea/actualiza leads en el CRM.
declare(strict_types=1);

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/instagram_channels.php';
require_once __DIR__ . '/config/conversations.php';
require_once __DIR__ . '/config/ad_attribution.php';
require_once __DIR__ . '/config/lead_status_history.php';

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
  campaign_id VARCHAR(120) NULL,
  campaign_name VARCHAR(180) NULL,
  campaign_ref_id INT UNSIGNED NULL,
  utm_content VARCHAR(160) NULL,
  utm_term VARCHAR(160) NULL,
  adset_id VARCHAR(120) NULL,
  adset_name VARCHAR(180) NULL,
  adset_ref_id INT UNSIGNED NULL,
  ad_name VARCHAR(180) NULL,
  ad_id VARCHAR(120) NULL,
  ad_ref_id INT UNSIGNED NULL,
  ad_referral_source VARCHAR(80) NULL,
  ad_referral_type VARCHAR(80) NULL,
  ad_referral_payload TEXT NULL,
  ad_enrichment_error VARCHAR(255) NULL,
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
    'campaign_id' => "ALTER TABLE {$table} ADD COLUMN campaign_id VARCHAR(120) NULL AFTER utm_campaign",
    'campaign_name' => "ALTER TABLE {$table} ADD COLUMN campaign_name VARCHAR(180) NULL AFTER campaign_id",
    'campaign_ref_id' => "ALTER TABLE {$table} ADD COLUMN campaign_ref_id INT UNSIGNED NULL AFTER campaign_name",
    'utm_content' => "ALTER TABLE {$table} ADD COLUMN utm_content VARCHAR(160) NULL AFTER utm_campaign",
    'utm_term' => "ALTER TABLE {$table} ADD COLUMN utm_term VARCHAR(160) NULL AFTER utm_content",
    'adset_id' => "ALTER TABLE {$table} ADD COLUMN adset_id VARCHAR(120) NULL AFTER utm_term",
    'adset_name' => "ALTER TABLE {$table} ADD COLUMN adset_name VARCHAR(180) NULL AFTER adset_id",
    'adset_ref_id' => "ALTER TABLE {$table} ADD COLUMN adset_ref_id INT UNSIGNED NULL AFTER adset_name",
    'ad_name' => "ALTER TABLE {$table} ADD COLUMN ad_name VARCHAR(180) NULL AFTER utm_term",
    'ad_id' => "ALTER TABLE {$table} ADD COLUMN ad_id VARCHAR(120) NULL AFTER ad_name",
    'ad_ref_id' => "ALTER TABLE {$table} ADD COLUMN ad_ref_id INT UNSIGNED NULL AFTER ad_id",
    'ad_referral_source' => "ALTER TABLE {$table} ADD COLUMN ad_referral_source VARCHAR(80) NULL AFTER ad_id",
    'ad_referral_type' => "ALTER TABLE {$table} ADD COLUMN ad_referral_type VARCHAR(80) NULL AFTER ad_referral_source",
    'ad_referral_payload' => "ALTER TABLE {$table} ADD COLUMN ad_referral_payload TEXT NULL AFTER ad_referral_type",
    'ad_enrichment_error' => "ALTER TABLE {$table} ADD COLUMN ad_enrichment_error VARCHAR(255) NULL AFTER ad_referral_payload",
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
    'idx_campaign_name' => "ALTER TABLE {$table} ADD KEY idx_campaign_name (campaign_name)",
    'idx_campaign_ref_id' => "ALTER TABLE {$table} ADD KEY idx_campaign_ref_id (campaign_ref_id)",
    'idx_adset_name' => "ALTER TABLE {$table} ADD KEY idx_adset_name (adset_name)",
    'idx_adset_ref_id' => "ALTER TABLE {$table} ADD KEY idx_adset_ref_id (adset_ref_id)",
    'idx_ad_id' => "ALTER TABLE {$table} ADD KEY idx_ad_id (ad_id)",
    'idx_ad_ref_id' => "ALTER TABLE {$table} ADD KEY idx_ad_ref_id (ad_ref_id)",
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
  if (ig_story_reply_data($event)) return 'Respuesta a story recibida.';
  $attachments = $event['message']['attachments'] ?? [];
  if (is_array($attachments) && $attachments) {
    $types = [];
    $supportedTypes = [];
    foreach ($attachments as $attachment) {
      $type = ig_clean($attachment['type'] ?? 'adjunto', 60) ?? 'adjunto';
      $types[] = $type;
      $payload = is_array($attachment['payload'] ?? null) ? $attachment['payload'] : [];
      $hasDownloadUrl = trim((string) ($payload['url'] ?? '')) !== '';
      if (in_array($type, ['image', 'audio', 'video'], true) && $hasDownloadUrl) $supportedTypes[] = $type;
    }
    if ($supportedTypes) return 'Adjunto recibido: ' . implode(', ', array_unique($supportedTypes));
  }
  return ig_unsupported_message_text();
}

function ig_unsupported_message_text(): string {
  return 'Se ha recibido un mensaje no soportado en esta plataforma, accede a este mensaje directamente desde la app oficial.';
}

function ig_is_unsupported_message_text(string $text): bool {
  return trim($text) === ig_unsupported_message_text();
}

function ig_referral_data(array $event): array {
  $referral = $event['referral'] ?? ($event['message']['referral'] ?? []);
  $referral = is_array($referral) ? $referral : [];
  $ads = $referral['ads_context_data'] ?? [];
  $ads = is_array($ads) ? $ads : [];
  $source = ig_clean($referral['source'] ?? null, 80);
  $refParam = ig_clean($referral['ref'] ?? null, 180);
  $sourceIsAds = ads_is_generic_meta_source($source);
  $refIsAds = ads_is_generic_meta_source($refParam);
  $campaignId = ig_clean($ads['campaign_id'] ?? $ads['campaign']['id'] ?? $referral['campaign_id'] ?? $referral['campaign']['id'] ?? null, 120);
  $campaignName = ig_clean($ads['campaign_name'] ?? $ads['campaign']['name'] ?? $referral['campaign_name'] ?? $referral['campaign']['name'] ?? null, 180);
  if (ads_is_generic_meta_source($campaignName)) $campaignName = null;
  $adsetId = ig_clean($ads['adset_id'] ?? $ads['ad_set_id'] ?? $ads['adset']['id'] ?? $ads['ad_set']['id'] ?? $referral['adset_id'] ?? $referral['ad_set_id'] ?? $referral['adset']['id'] ?? null, 120);
  $adsetName = ig_clean($ads['adset_name'] ?? $ads['ad_set_name'] ?? $ads['adset']['name'] ?? $ads['ad_set']['name'] ?? $referral['adset_name'] ?? $referral['ad_set_name'] ?? null, 180);
  $adId = ig_clean($referral['ad_id'] ?? $ads['ad_id'] ?? $ads['ad']['id'] ?? $referral['ad']['id'] ?? null, 120);
  $adName = ig_clean($ads['ad_title'] ?? $ads['ad_name'] ?? $ads['ad']['name'] ?? null, 180);
  $campaignFallback = $campaignName ?: ((!$sourceIsAds && !$refIsAds) ? ($refParam ?: $source) : null);
  if (ads_is_generic_meta_source($campaignFallback)) $campaignFallback = null;
  $payloadJson = ($referral || $ads)
    ? ig_clean(json_encode(['referral' => $referral, 'ads_context_data' => $ads], JSON_UNESCAPED_UNICODE), 5000)
    : null;
  return [
    'campaign' => ig_clean($campaignFallback, 120),
    'campaign_id' => $campaignId,
    'campaign_name' => $campaignName,
    'adset_id' => $adsetId,
    'adset_name' => $adsetName,
    'ad_name' => $adName,
    'ad_id' => $adId,
    'content' => ig_clean($ads['post_id'] ?? $ads['photo_url'] ?? $ads['video_url'] ?? null, 160),
    'referral_source' => $source,
    'referral_type' => ig_clean($referral['type'] ?? $ads['type'] ?? null, 80),
    'payload_json' => $payloadJson,
    'enrichment_error' => null,
    'campaign_was_generic' => $sourceIsAds || $refIsAds,
  ];
}

function ig_ad_referral_message_text(array $ref): ?string {
  $campaignName = ig_clean($ref['campaign_name'] ?? $ref['campaign'] ?? null, 180);
  $adsetName = ig_clean($ref['adset_name'] ?? null, 180);
  $adName = ig_clean($ref['ad_name'] ?? null, 180);
  $source = ig_clean($ref['referral_source'] ?? null, 80);
  $adId = ig_clean($ref['ad_id'] ?? null, 120);
  $adsetId = ig_clean($ref['adset_id'] ?? null, 120);
  $campaignId = ig_clean($ref['campaign_id'] ?? null, 120);
  $hasReferralContext = $campaignName !== null || $adsetName !== null || $adName !== null || $adId !== null || $adsetId !== null || $campaignId !== null || $source !== null || !empty($ref['payload_json']);
  if (!$hasReferralContext) return null;

  $lines = [];
  $lines[] = $adName !== null ? 'Respuesta al anuncio: ' . $adName : 'Respuesta recibida desde un anuncio.';
  if ($campaignName !== null && !ads_is_generic_meta_source($campaignName)) $lines[] = 'Campaña: ' . $campaignName;
  if ($adsetName !== null) $lines[] = 'Conjunto: ' . $adsetName;
  if ($adName === null && $adId !== null) $lines[] = 'Anuncio ID: ' . $adId;
  if ($campaignName === null && $source !== null && !ads_is_generic_meta_source($source)) $lines[] = 'Referencia: ' . $source;

  return implode("\n", array_values(array_unique($lines)));
}

function ig_story_reply_data(array $event): ?array {
  $story = $event['message']['reply_to']['story'] ?? $event['reply_to']['story'] ?? null;
  if (!is_array($story)) return null;

  $url = trim(str_replace("\0", '', (string) ($story['url'] ?? $story['media_url'] ?? '')));
  $storyId = ig_clean($story['id'] ?? $story['story_id'] ?? null, 180);
  if ($url === '' && $storyId === null) return null;

  $rawType = strtolower(trim((string) ($story['media_type'] ?? $story['type'] ?? $story['attachment_type'] ?? '')));
  $type = 'story';
  if (str_contains($rawType, 'video')) $type = 'video';
  elseif (str_contains($rawType, 'image') || str_contains($rawType, 'photo')) $type = 'image';

  return [
    'id' => $storyId,
    'url' => $url,
    'type' => $type,
    'raw_type' => $rawType !== '' ? $rawType : null,
  ];
}

function ig_story_reply_context_text(array $story): string {
  return ($story['url'] ?? '') !== ''
    ? 'Story respondida por el lead.'
    : 'El lead respondió una story. Abre Instagram para ver el contenido original.';
}

function ig_ad_attribution_token(?array $channel): ?string {
  $userToken = ig_clean($channel['user_access_token'] ?? null, 2000);
  if ($userToken !== null) return $userToken;
  $configuredToken = ig_clean(app_config('instagram.ads_access_token', ''), 2000);
  if ($configuredToken !== null) return $configuredToken;
  return ig_clean($channel['page_access_token'] ?? null, 2000);
}

function ig_enrich_ad_attribution(?array $channel, array $ref): array {
  $adId = ig_clean($ref['ad_id'] ?? null, 120);
  $adsetId = ig_clean($ref['adset_id'] ?? null, 120);
  $campaignId = ig_clean($ref['campaign_id'] ?? null, 120);
  $token = ig_ad_attribution_token($channel);
  $needsEnrichment = $adId !== null || $adsetId !== null || $campaignId !== null || !empty($ref['campaign_was_generic']);
  if (!$needsEnrichment) return $ref;
  if ($token === null) {
    $ref['enrichment_error'] = 'No se pudo consultar Meta Ads: el canal no tiene token activo.';
    return $ref;
  }

  if ($adId !== null) {
    $response = ig_graph_request_base(ig_graph_base(), 'GET', $adId, [
      'fields' => 'id,name,adset{id,name},campaign{id,name}',
      'access_token' => $token,
    ]);
    if (($response['ok'] ?? false) && isset($response['data']) && is_array($response['data'])) {
      $ad = $response['data'];
      $adset = is_array($ad['adset'] ?? null) ? $ad['adset'] : [];
      $campaign = is_array($ad['campaign'] ?? null) ? $ad['campaign'] : [];
      $ref['ad_id'] = ig_clean($ad['id'] ?? $ref['ad_id'] ?? null, 120);
      $ref['ad_name'] = ig_clean($ad['name'] ?? $ref['ad_name'] ?? null, 180);
      $ref['adset_id'] = ig_clean($adset['id'] ?? $ref['adset_id'] ?? null, 120);
      $ref['adset_name'] = ig_clean($adset['name'] ?? $ref['adset_name'] ?? null, 180);
      $ref['campaign_id'] = ig_clean($campaign['id'] ?? $ref['campaign_id'] ?? null, 120);
      $ref['campaign_name'] = ig_clean($campaign['name'] ?? $ref['campaign_name'] ?? null, 180);
      if (($ref['campaign'] ?? null) === null && ($ref['campaign_name'] ?? null) !== null) $ref['campaign'] = ig_clean($ref['campaign_name'], 120);
      return $ref;
    }
    $ref['enrichment_error'] = ig_clean($response['error'] ?? 'Meta no devolvió datos del anuncio.', 255);
  }

  $adsetId = ig_clean($ref['adset_id'] ?? null, 120);
  if ($adsetId !== null && ($ref['campaign_name'] ?? null) === null) {
    $response = ig_graph_request_base(ig_graph_base(), 'GET', $adsetId, [
      'fields' => 'id,name,campaign{id,name}',
      'access_token' => $token,
    ]);
    if (($response['ok'] ?? false) && isset($response['data']) && is_array($response['data'])) {
      $adset = $response['data'];
      $campaign = is_array($adset['campaign'] ?? null) ? $adset['campaign'] : [];
      $ref['adset_id'] = ig_clean($adset['id'] ?? $ref['adset_id'] ?? null, 120);
      $ref['adset_name'] = ig_clean($adset['name'] ?? $ref['adset_name'] ?? null, 180);
      $ref['campaign_id'] = ig_clean($campaign['id'] ?? $ref['campaign_id'] ?? null, 120);
      $ref['campaign_name'] = ig_clean($campaign['name'] ?? $ref['campaign_name'] ?? null, 180);
    } elseif (($ref['enrichment_error'] ?? null) === null) {
      $ref['enrichment_error'] = ig_clean($response['error'] ?? 'Meta no devolvió datos del conjunto de anuncios.', 255);
    }
  }

  $campaignId = ig_clean($ref['campaign_id'] ?? null, 120);
  if ($campaignId !== null && ($ref['campaign_name'] ?? null) === null) {
    $response = ig_graph_request_base(ig_graph_base(), 'GET', $campaignId, [
      'fields' => 'id,name',
      'access_token' => $token,
    ]);
    if (($response['ok'] ?? false) && isset($response['data']) && is_array($response['data'])) {
      $campaign = $response['data'];
      $ref['campaign_id'] = ig_clean($campaign['id'] ?? $ref['campaign_id'] ?? null, 120);
      $ref['campaign_name'] = ig_clean($campaign['name'] ?? $ref['campaign_name'] ?? null, 180);
    } elseif (($ref['enrichment_error'] ?? null) === null) {
      $ref['enrichment_error'] = ig_clean($response['error'] ?? 'Meta no devolvió datos de la campaña.', 255);
    }
  }

  if (($ref['campaign'] ?? null) === null && ($ref['campaign_name'] ?? null) !== null) $ref['campaign'] = ig_clean($ref['campaign_name'], 120);
  if (($ref['campaign_name'] ?? null) === null && ($ref['campaign_id'] ?? null) === null && !empty($ref['campaign_was_generic']) && ($ref['enrichment_error'] ?? null) === null) {
    $ref['enrichment_error'] = 'Meta indicó que el mensaje viene de Ads, pero no envió campaign_id ni ad_id para resolver el nombre.';
  }
  return $ref;
}

function ig_clear_generic_campaign_values(PDO $pdo, string $table, int $leadId): void {
  if ($leadId <= 0) return;
  $campaignsTable = ads_campaigns_table();
  try {
    $stmt = $pdo->prepare(<<<SQL
UPDATE {$table} l
LEFT JOIN {$campaignsTable} ac ON ac.id = l.campaign_ref_id
SET
  l.utm_campaign = CASE
    WHEN UPPER(TRIM(COALESCE(l.utm_campaign, ''))) IN ('ADS', 'AD') THEN NULL
    ELSE l.utm_campaign
  END,
  l.campaign_name = CASE
    WHEN UPPER(TRIM(COALESCE(l.campaign_name, ''))) IN ('ADS', 'AD') THEN NULL
    ELSE l.campaign_name
  END,
  l.campaign_ref_id = CASE
    WHEN UPPER(TRIM(COALESCE(ac.campaign_name, ''))) IN ('ADS', 'AD') THEN NULL
    ELSE l.campaign_ref_id
  END
WHERE l.id = ?
SQL);
    $stmt->execute([$leadId]);
  } catch (Throwable $e) {
    /* La limpieza no debe bloquear el procesamiento del webhook. */
  }
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
    'Accept: image/avif,image/webp,image/apng,image/svg+xml,image/*,audio/*,video/*,*/*;q=0.8',
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
    'video' => (array) app_config('media.allowed_video_mimes', []),
    default => (array) app_config('media.allowed_image_mimes', []),
  };
}

function ig_media_label(string $type): string {
  if ($type === 'audio') return 'audio';
  if ($type === 'video') return 'video';
  return 'imagen';
}

function ig_media_type_from_mime(string $mime, string $fallback): string {
  $mime = strtolower(trim($mime));
  if (str_starts_with($mime, 'image/')) return 'image';
  if (str_starts_with($mime, 'audio/')) return 'audio';
  if (str_starts_with($mime, 'video/')) return 'video';
  return in_array($fallback, ['image', 'audio', 'video'], true) ? $fallback : 'image';
}

function ig_store_message_attachments(PDO $pdo, int $conversationId, int $messageId, array $event, ?array $channel, string $provider = 'instagram', string $direction = 'inbound'): array {
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
    $type = in_array($type, ['image', 'audio', 'video'], true) ? $type : 'story';
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
    $type = ig_media_type_from_mime($mime, $type);
    $label = ig_media_label($type);
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
      'direction' => $direction === 'outbound' ? 'outbound' : 'inbound',
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

function ig_store_story_reply_context(PDO $pdo, int $conversationId, string $senderId, ?string $messageId, string $messageAt, array $event, ?array $channel, string $provider = 'instagram'): array {
  $story = ig_story_reply_data($event);
  if (!$story || $conversationId <= 0) {
    return ['message_id' => 0, 'message_inserted' => false, 'attachments_total' => 0, 'attachments_stored' => 0, 'attachment_errors' => []];
  }

  $messagesTable = conv_messages_table();
  $contextSeed = $messageId ?: hash('sha256', $senderId . '|' . $messageAt . '|' . json_encode($story, JSON_UNESCAPED_UNICODE));
  $externalId = 'story-context:' . $contextSeed;
  $hash = hash('sha256', $externalId);
  $existsStmt = $pdo->prepare("SELECT id FROM {$messagesTable} WHERE conversation_id=? AND external_message_hash=? LIMIT 1");
  $existsStmt->execute([$conversationId, $hash]);
  $alreadyExists = (bool) $existsStmt->fetchColumn();

  $contextEvent = $event;
  $contextEvent['_crm_story_reply_context'] = true;
  $contextEvent['_crm_story_reply'] = $story;
  $inserted = conv_add_message($pdo, [
    'conversation_id' => $conversationId,
    'external_message_id' => $externalId,
    'direction' => 'inbound',
    'sender_external_id' => $senderId,
    'message_type' => 'story_context',
    'message_text' => ig_story_reply_context_text($story),
    'payload_json' => json_encode($contextEvent, JSON_UNESCAPED_UNICODE),
    'sent_at' => $messageAt,
    'delivery_status' => 'received',
  ]);

  $attachmentResult = ['total' => 0, 'stored' => 0, 'errors' => []];
  if ($inserted > 0 && !$alreadyExists && trim((string) ($story['url'] ?? '')) !== '') {
    $attachmentResult = ig_store_message_attachments($pdo, $conversationId, $inserted, [
      'message' => [
        'attachments' => [
          [
            'type' => (string) ($story['type'] ?? 'story'),
            'payload' => [
              'url' => (string) ($story['url'] ?? ''),
              'attachment_id' => $story['id'] ?? null,
            ],
          ],
        ],
      ],
    ], $channel, $provider, 'inbound');
  }

  return [
    'message_id' => $inserted,
    'message_inserted' => $inserted > 0 && !$alreadyExists,
    'attachments_total' => (int) ($attachmentResult['total'] ?? 0),
    'attachments_stored' => (int) ($attachmentResult['stored'] ?? 0),
    'attachment_errors' => (array) ($attachmentResult['errors'] ?? []),
  ];
}

function ig_sync_conversation(PDO $pdo, ?array $channel, string $senderId, string $threadId, ?string $messageId, string $messageText, string $messageAt, ?string $profileName, ?string $profileUsername, int $leadId, array $event, string $provider = 'instagram', string $direction = 'inbound', bool $storeMessage = true): array {
  try {
    conv_ensure_schema($pdo);
    $direction = $direction === 'outbound' ? 'outbound' : 'inbound';
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
      'last_message_preview' => $storeMessage ? $messageText : null,
      'last_message_at' => $storeMessage ? $messageAt : null,
      'unread_increment' => 0,
    ]);
    if ($conversationId <= 0) return ['conversation_id' => 0, 'message_id' => 0, 'message_inserted' => false, 'error' => 'No se pudo guardar la conversacion.'];
    if (!$storeMessage) {
      return [
        'conversation_id' => $conversationId,
        'message_id' => 0,
        'message_inserted' => false,
        'attachments_total' => 0,
        'attachments_stored' => 0,
        'attachment_errors' => [],
        'had_outbound_before' => false,
      ];
    }

    $hasReferralContext = !empty($event['_crm_message_from_referral']) || isset($event['referral']) || isset($event['message']['referral']);
    $messageType = $hasReferralContext ? 'referral' : (isset($event['message']['text']) ? 'text' : (isset($event['postback']) ? 'postback' : 'attachment'));
    $messagesTable = conv_messages_table();
    $messageAlreadyExists = false;
    $hadOutboundBefore = false;
    if ($messageId !== null) {
      $existsStmt = $pdo->prepare("SELECT id FROM {$messagesTable} WHERE conversation_id=? AND external_message_hash=? LIMIT 1");
      $existsStmt->execute([$conversationId, hash('sha256', $messageId)]);
      $messageAlreadyExists = (bool) $existsStmt->fetchColumn();
    }
    if ($direction === 'outbound') {
      $outboundStmt = $pdo->prepare("SELECT 1 FROM {$messagesTable} WHERE conversation_id=? AND direction='outbound' LIMIT 1");
      $outboundStmt->execute([$conversationId]);
      $hadOutboundBefore = (bool) $outboundStmt->fetchColumn();
    }
    $storyContextResult = ['message_id' => 0, 'message_inserted' => false, 'attachments_total' => 0, 'attachments_stored' => 0, 'attachment_errors' => []];
    if ($direction === 'inbound' && !$messageAlreadyExists && ig_story_reply_data($event)) {
      $storyContextResult = ig_store_story_reply_context($pdo, $conversationId, $senderId, $messageId, $messageAt, $event, $channel, $provider);
    }
    $inserted = conv_add_message($pdo, [
      'conversation_id' => $conversationId,
      'external_message_id' => $messageId,
      'direction' => $direction,
      'sender_external_id' => $senderId,
      'message_type' => $messageType,
      'message_text' => $messageText,
      'payload_json' => json_encode($event, JSON_UNESCAPED_UNICODE),
      'sent_at' => $messageAt,
      'delivery_status' => $direction === 'outbound' ? 'sent' : 'received',
    ]);

    $attachmentResult = ['total' => 0, 'stored' => 0, 'errors' => []];
    if ($inserted > 0 && !$messageAlreadyExists) {
      $attachmentResult = ig_store_message_attachments($pdo, $conversationId, $inserted, $event, $channel, $provider, $direction);
      conv_upsert_conversation($pdo, [
        'account_id' => $accountId,
        'channel_id' => $channel['id'] ?? null,
        'contact_id' => $contactId,
        'lead_id' => $leadId > 0 ? $leadId : null,
        'external_source' => $provider,
        'external_thread_id' => $threadId,
        'last_message_preview' => $messageText,
        'last_message_at' => $messageAt,
        'unread_increment' => $direction === 'inbound' ? 1 : 0,
      ]);
    }
    return [
      'conversation_id' => $conversationId,
      'message_id' => $inserted,
      'message_inserted' => $inserted > 0 && !$messageAlreadyExists,
      'attachments_total' => (int) ($attachmentResult['total'] ?? 0),
      'attachments_stored' => (int) ($attachmentResult['stored'] ?? 0),
      'story_context_message_id' => (int) ($storyContextResult['message_id'] ?? 0),
      'story_context_inserted' => (bool) ($storyContextResult['message_inserted'] ?? false),
      'story_context_attachments_total' => (int) ($storyContextResult['attachments_total'] ?? 0),
      'story_context_attachments_stored' => (int) ($storyContextResult['attachments_stored'] ?? 0),
      'attachment_errors' => array_merge((array) ($storyContextResult['attachment_errors'] ?? []), (array) ($attachmentResult['errors'] ?? [])),
      'had_outbound_before' => $hadOutboundBefore,
      'error' => $inserted > 0 ? null : 'No se pudo guardar el mensaje en conversation_messages.',
    ];
  } catch (Throwable $e) {
    /* El webhook no debe fallar si el historial conversacional no pudo escribirse. */
    return ['conversation_id' => 0, 'message_id' => 0, 'message_inserted' => false, 'error' => $e->getMessage()];
  }
}

function ig_recent_outbound_duplicate_for_thread(PDO $pdo, int $accountId, string $provider, string $threadId, ?string $customerId, string $messageText, string $messageAt): bool {
  $text = trim($messageText);
  if ($accountId <= 0 || $threadId === '' || $text === '') return false;
  $conversationsTable = conv_conversations_table();
  $messagesTable = conv_messages_table();
  try {
    $stmt = $pdo->prepare(<<<SQL
SELECT 1
FROM {$messagesTable} m
JOIN {$conversationsTable} c ON c.id = m.conversation_id
WHERE c.account_id = ?
  AND c.external_source = ?
  AND c.external_thread_id = ?
  AND m.direction = 'outbound'
  AND m.message_text = ?
  AND (? IS NULL OR m.sender_external_id IS NULL OR m.sender_external_id = '' OR m.sender_external_id = ?)
  AND ABS(TIMESTAMPDIFF(SECOND, m.sent_at, ?)) <= 90
LIMIT 1
SQL);
    $stmt->execute([$accountId, $provider, $threadId, $text, $customerId, $customerId, $messageAt]);
    return (bool) $stmt->fetchColumn();
  } catch (Throwable $e) {
    return false;
  }
}

function ig_auto_mark_lead_after_official_reply(PDO $pdo, string $leadsTable, int $leadId, int $accountId, ?array $channel, bool $hadOutboundBefore, string $sentAt): void {
  if ($leadId <= 0 || $accountId <= 0 || $hadOutboundBefore) return;
  $defaultStatus = (string) app_config('sales_funnel.default_status', 'nuevo_lead');
  $targetStatus = 'en_conversacion';
  $allowedStatuses = array_keys((array) app_config('sales_funnel.statuses', []));
  if (!in_array($targetStatus, $allowedStatuses, true)) return;

  $stmt = $pdo->prepare("SELECT sales_status FROM {$leadsTable} WHERE id=? AND account_id=? LIMIT 1");
  $stmt->execute([$leadId, $accountId]);
  $previousStatus = (string) ($stmt->fetchColumn() ?: '');
  if ($previousStatus !== $defaultStatus) return;

  $update = $pdo->prepare("UPDATE {$leadsTable} SET sales_status=?, updated_at=NOW() WHERE id=? AND account_id=? AND sales_status=?");
  $update->execute([$targetStatus, $leadId, $accountId, $previousStatus]);
  if ($update->rowCount() < 1) return;

  $channelName = ig_clean($channel['instagram_username'] ?? $channel['page_name'] ?? 'canal oficial', 120) ?? 'canal oficial';
  $when = function_exists('app_datetime') ? app_datetime($sentAt, 'd/m/Y H:i', $sentAt) : $sentAt;
  $reason = 'Conversacion respondida desde ' . $channelName . ' el ' . $when . '. Status actualizado automaticamente de ' . lead_status_label($previousStatus) . ' a ' . lead_status_label($targetStatus) . '.';
  lead_status_history_record($pdo, $leadId, $previousStatus, $targetStatus, $reason, $accountId);
}

function ig_upsert_lead(PDO $pdo, string $table, string $channelsTable, array $event, string $provider = 'instagram'): int {
  $senderId = ig_clean($event['sender']['id'] ?? null, 120);
  if ($senderId === null) return 0;

  $recipientId = ig_clean($event['recipient']['id'] ?? null, 120);
  $messageId = ig_clean($event['message']['mid'] ?? $event['postback']['mid'] ?? null, 2000);
  $hasVisibleMessage = isset($event['message']) || isset($event['postback']);
  $hasReferralOnly = !$hasVisibleMessage && isset($event['referral']) && is_array($event['referral']);
  $messageText = $hasVisibleMessage ? ig_event_text($event, $provider) : 'Referencia de anuncio recibida desde Meta.';
  $hasStoryReply = ig_story_reply_data($event) !== null;
  $isOfficialEcho = !empty($event['message']['is_echo']);
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

  $direction = 'inbound';
  $businessId = $recipientId;
  $customerId = $senderId;
  $channel = null;
  if ($isOfficialEcho) {
    $channel = ig_channel_find_by_recipient($pdo, $channelsTable, $senderId, $provider);
    $direction = 'outbound';
    $businessId = $senderId;
    $customerId = $recipientId;
  } else {
    $channel = ig_channel_find_by_recipient($pdo, $channelsTable, $recipientId, $provider);
    if (!$channel) {
      $possibleEchoChannel = ig_channel_find_by_recipient($pdo, $channelsTable, $senderId, $provider);
      if ($possibleEchoChannel) {
        $channel = $possibleEchoChannel;
        $direction = 'outbound';
        $businessId = $senderId;
        $customerId = $recipientId;
      }
    }
  }
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
  $threadId = $businessId . ':' . $customerId;
  $looksLikeEchoDuplicate = $direction === 'inbound'
    && $hasVisibleMessage
    && ig_recent_outbound_duplicate_for_thread($pdo, $accountId, $provider, $threadId, $customerId, $messageText, $messageAt);
  $ref = ig_referral_data($event);
  $ref = ig_enrich_ad_attribution($channel ?: null, $ref);
  try {
    $ref = array_merge($ref, ads_upsert_from_ref($pdo, $accountId, $ref, 'meta', $messageAt, $table));
  } catch (Throwable $e) {
    $ref['campaign_ref_id'] = null;
    $ref['adset_ref_id'] = null;
    $ref['ad_ref_id'] = null;
  }
  $adReferralText = ig_ad_referral_message_text($ref);
  $messageFromAdReferral = $adReferralText !== null && (!$hasVisibleMessage || ig_is_unsupported_message_text($messageText));
  if ($messageFromAdReferral) {
    $messageText = $adReferralText;
    $event['_crm_message_from_referral'] = true;
  }
  $profile = ig_contact_profile($channel ?: null, $customerId, $provider);
  $profileName = $profile['name'] ?? null;
  $profileUsername = $profile['username'] ?? null;
  if (!empty($profile['profile_url'])) $event['_profile_url'] = $profile['profile_url'];
  if (!empty($profile['avatar_url'])) $event['_avatar_url'] = $profile['avatar_url'];
  $profileDisplayName = $profileName ?: $profileUsername;

  $contactKey = $businessId . ':' . $customerId;

  $existing = $pdo->prepare("SELECT id FROM {$table} WHERE account_id=? AND external_source=? AND external_contact_id=? LIMIT 1");
  $existing->execute([$accountId, $provider, $contactKey]);
  $leadId = (int) ($existing->fetchColumn() ?: 0);
  $shouldStoreMessage = ($hasVisibleMessage || $messageFromAdReferral) && !$looksLikeEchoDuplicate;
  $shouldUpdateLeadActivity = ($hasVisibleMessage || $messageFromAdReferral) && !$looksLikeEchoDuplicate;
  $shouldUpdateLeadMessage = $direction === 'inbound' && ($hasVisibleMessage || $messageFromAdReferral) && !$looksLikeEchoDuplicate;

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
  last_external_message_id = CASE WHEN ? = 1 THEN ? ELSE last_external_message_id END,
  last_message_at = CASE WHEN ? = 1 THEN ? ELSE last_message_at END,
  last_inbound_message = CASE WHEN ? = 1 THEN ? ELSE last_inbound_message END,
  message = CASE WHEN ? = 1 THEN COALESCE(message, ?) ELSE message END,
  utm_campaign = CASE WHEN ? IS NOT NULL THEN ? ELSE utm_campaign END,
  campaign_id = CASE WHEN ? IS NOT NULL THEN ? ELSE campaign_id END,
  campaign_name = CASE WHEN ? IS NOT NULL THEN ? ELSE campaign_name END,
  campaign_ref_id = CASE WHEN ? IS NOT NULL THEN ? ELSE campaign_ref_id END,
  adset_id = CASE WHEN ? IS NOT NULL THEN ? ELSE adset_id END,
  adset_name = CASE WHEN ? IS NOT NULL THEN ? ELSE adset_name END,
  adset_ref_id = CASE WHEN ? IS NOT NULL THEN ? ELSE adset_ref_id END,
  ad_name = CASE WHEN ? IS NOT NULL THEN ? ELSE ad_name END,
  ad_id = CASE WHEN ? IS NOT NULL THEN ? ELSE ad_id END,
  ad_ref_id = CASE WHEN ? IS NOT NULL THEN ? ELSE ad_ref_id END,
  utm_content = CASE WHEN ? IS NOT NULL THEN ? ELSE utm_content END,
  ad_referral_source = CASE WHEN ? IS NOT NULL THEN ? ELSE ad_referral_source END,
  ad_referral_type = CASE WHEN ? IS NOT NULL THEN ? ELSE ad_referral_type END,
  ad_referral_payload = CASE WHEN ? IS NOT NULL THEN ? ELSE ad_referral_payload END,
  ad_enrichment_error = CASE WHEN ? IS NOT NULL THEN ? ELSE ad_enrichment_error END,
  updated_at = NOW()
WHERE id = ?
SQL);
    $update->execute([
      $profileDisplayName, $profileDisplayName, $profileUsername, $profileUsername, $threadId,
      $shouldUpdateLeadActivity ? 1 : 0, $messageId,
      $shouldUpdateLeadActivity ? 1 : 0, $messageAt,
      $shouldUpdateLeadMessage ? 1 : 0, $messageText, $shouldUpdateLeadMessage ? 1 : 0, $messageText,
      $ref['campaign'], $ref['campaign'],
      $ref['campaign_id'], $ref['campaign_id'],
      $ref['campaign_name'], $ref['campaign_name'],
      $ref['campaign_ref_id'], $ref['campaign_ref_id'],
      $ref['adset_id'], $ref['adset_id'],
      $ref['adset_name'], $ref['adset_name'],
      $ref['adset_ref_id'], $ref['adset_ref_id'],
      $ref['ad_name'], $ref['ad_name'],
      $ref['ad_id'], $ref['ad_id'],
      $ref['ad_ref_id'], $ref['ad_ref_id'],
      $ref['content'], $ref['content'],
      $ref['referral_source'], $ref['referral_source'],
      $ref['referral_type'], $ref['referral_type'],
      $ref['payload_json'], $ref['payload_json'],
      $ref['enrichment_error'], $ref['enrichment_error'],
      $leadId,
    ]);
    ig_clear_generic_campaign_values($pdo, $table, $leadId);
    if ($channel) {
      try {
        $stamp = $pdo->prepare("UPDATE {$channelsTable} SET last_event_at=?, updated_at=NOW() WHERE id=?");
        $stamp->execute([$messageAt, (int) $channel['id']]);
      } catch (Throwable $e) { /* no-op */ }
    }
    $sync = ig_sync_conversation($pdo, $channel ?: null, $customerId, $threadId, $messageId, $messageText, $messageAt, $profileName, $profileUsername, $leadId, $event, $provider, $direction, $shouldStoreMessage);
    $conversationId = (int) ($sync['conversation_id'] ?? 0);
    $conversationMessageId = (int) ($sync['message_id'] ?? 0);
    $messageInserted = (bool) ($sync['message_inserted'] ?? false);
    if ($direction === 'outbound' && $messageInserted) {
      ig_auto_mark_lead_after_official_reply($pdo, $table, $leadId, $accountId, $channel ?: null, (bool) ($sync['had_outbound_before'] ?? false), $messageAt);
    }
    $attachmentErrors = (array) ($sync['attachment_errors'] ?? []);
    $attachmentError = $attachmentErrors ? implode(' | ', array_slice(array_map('strval', $attachmentErrors), 0, 3)) : null;
    $attributionError = ig_clean($ref['enrichment_error'] ?? null, 255);
    conv_log_webhook_event($pdo, [
      'source' => $provider,
      'status' => $looksLikeEchoDuplicate ? 'duplicate' : ($hasReferralOnly ? 'processed' : ($conversationMessageId > 0 ? ($messageInserted ? 'processed' : 'duplicate') : 'lead_only')),
      'account_id' => $accountId,
      'event_type' => $looksLikeEchoDuplicate ? 'message_echo_duplicate' : (($hasReferralOnly || $messageFromAdReferral) ? 'referral' : ($direction === 'outbound' ? 'message_echo' : ($hasStoryReply ? 'story_reply' : (isset($event['message']['text']) ? 'message' : (isset($event['postback']) ? 'postback' : 'attachment'))))),
      'recipient_id' => $businessId,
      'sender_id' => $customerId,
      'channel_id' => (int) $channel['id'],
      'channel_username' => $provider === 'messenger' ? ($channel['page_name'] ?? null) : ($channel['instagram_username'] ?? $channel['page_name'] ?? null),
      'external_message_id' => $messageId,
      'lead_id' => $leadId,
      'conversation_id' => $conversationId > 0 ? $conversationId : null,
      'message_preview' => $messageText,
      'error_message' => $looksLikeEchoDuplicate ? 'Eco saliente duplicado ignorado.' : ($hasReferralOnly ? $attributionError : ($conversationMessageId > 0 ? ($attachmentError ?: $attributionError) : (string) ($sync['error'] ?? 'Lead actualizado, pero no se pudo sincronizar el mensaje.'))),
      'payload_json' => json_encode($event, JSON_UNESCAPED_UNICODE),
    ]);
    return $leadId;
  }

  $defaultSalesStatus = (string) app_config('sales_funnel.default_status', 'nuevo_lead');
  $fullname = $profileDisplayName ?: 'Lead ' . ig_provider_label($provider) . ' #' . substr($customerId, -6);
  $brandInstagram = $provider === 'instagram' ? $profileUsername : null;

  $insert = $pdo->prepare(<<<SQL
INSERT INTO {$table} (
  account_id, fullname, phone, email, brand_instagram, business_type, business_type_other, services_needed, main_objective, message,
  source_platform, utm_source, utm_medium, utm_campaign, campaign_id, campaign_name, utm_content,
  campaign_ref_id, adset_id, adset_name, adset_ref_id, ad_name, ad_id, ad_ref_id, ad_referral_source, ad_referral_type, ad_referral_payload, ad_enrichment_error,
  sales_status, status, whatsapp_sent, whatsapp_status, external_source, external_contact_id, external_thread_id,
  last_external_message_id, first_message_at, last_message_at, last_inbound_message
) VALUES (
  ?, ?, NULL, NULL, ?, ?, NULL, ?, ?, ?,
  ?, ?, ?, ?, ?, ?, ?,
  ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
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
    $shouldUpdateLeadMessage ? $messageText : null,
    ig_provider_label($provider),
    ig_provider_source($provider),
    ig_provider_medium($provider),
    $ref['campaign'],
    $ref['campaign_id'],
    $ref['campaign_name'],
    $ref['content'],
    $ref['campaign_ref_id'],
    $ref['adset_id'],
    $ref['adset_name'],
    $ref['adset_ref_id'],
    $ref['ad_name'],
    $ref['ad_id'],
    $ref['ad_ref_id'],
    $ref['referral_source'],
    $ref['referral_type'],
    $ref['payload_json'],
    $ref['enrichment_error'],
    $defaultSalesStatus,
    $provider,
    $contactKey,
    $threadId,
    $messageId,
    $messageAt,
    $messageAt,
    $shouldUpdateLeadMessage ? $messageText : null,
  ]);

  if ($channel) {
    try {
      $stamp = $pdo->prepare("UPDATE {$channelsTable} SET last_event_at=?, updated_at=NOW() WHERE id=?");
      $stamp->execute([$messageAt, (int) $channel['id']]);
    } catch (Throwable $e) { /* no-op */ }
  }

  $leadId = (int) $pdo->lastInsertId();
  ig_clear_generic_campaign_values($pdo, $table, $leadId);
  $sync = ig_sync_conversation($pdo, $channel ?: null, $customerId, $threadId, $messageId, $messageText, $messageAt, $profileName, $profileUsername, $leadId, $event, $provider, $direction, $shouldStoreMessage);
  $conversationId = (int) ($sync['conversation_id'] ?? 0);
  $conversationMessageId = (int) ($sync['message_id'] ?? 0);
  $messageInserted = (bool) ($sync['message_inserted'] ?? false);
  if ($direction === 'outbound' && $messageInserted) {
    ig_auto_mark_lead_after_official_reply($pdo, $table, $leadId, $accountId, $channel ?: null, (bool) ($sync['had_outbound_before'] ?? false), $messageAt);
  }
  $attachmentErrors = (array) ($sync['attachment_errors'] ?? []);
  $attachmentError = $attachmentErrors ? implode(' | ', array_slice(array_map('strval', $attachmentErrors), 0, 3)) : null;
  $attributionError = ig_clean($ref['enrichment_error'] ?? null, 255);
  conv_log_webhook_event($pdo, [
    'source' => $provider,
    'status' => $looksLikeEchoDuplicate ? 'duplicate' : ($hasReferralOnly ? 'processed' : ($conversationMessageId > 0 ? ($messageInserted ? 'processed' : 'duplicate') : 'lead_only')),
    'account_id' => $accountId,
    'event_type' => $looksLikeEchoDuplicate ? 'message_echo_duplicate' : (($hasReferralOnly || $messageFromAdReferral) ? 'referral' : ($direction === 'outbound' ? 'message_echo' : ($hasStoryReply ? 'story_reply' : (isset($event['message']['text']) ? 'message' : (isset($event['postback']) ? 'postback' : 'attachment'))))),
    'recipient_id' => $businessId,
    'sender_id' => $customerId,
    'channel_id' => (int) $channel['id'],
    'channel_username' => $provider === 'messenger' ? ($channel['page_name'] ?? null) : ($channel['instagram_username'] ?? $channel['page_name'] ?? null),
    'external_message_id' => $messageId,
    'lead_id' => $leadId,
    'conversation_id' => $conversationId > 0 ? $conversationId : null,
    'message_preview' => $messageText,
    'error_message' => $looksLikeEchoDuplicate ? 'Eco saliente duplicado ignorado.' : ($hasReferralOnly ? $attributionError : ($conversationMessageId > 0 ? ($attachmentError ?: $attributionError) : (string) ($sync['error'] ?? 'Lead creado, pero no se pudo sincronizar el mensaje.'))),
    'payload_json' => json_encode($event, JSON_UNESCAPED_UNICODE),
  ]);
  return $leadId;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  $mode = (string) ($_GET['hub_mode'] ?? $_GET['hub.mode'] ?? '');
  $token = (string) ($_GET['hub_verify_token'] ?? $_GET['hub.verify_token'] ?? '');
  $challenge = (string) ($_GET['hub_challenge'] ?? $_GET['hub.challenge'] ?? '');
  $expected = (string) app_config('instagram.webhook_verify_token', '');
  $whatsappExpected = (string) app_config('whatsapp_cloud.webhook_verify_token', '');
  $validTokens = array_values(array_unique(array_filter([$expected, $whatsappExpected], static fn($value) => trim((string) $value) !== '')));
  $isValidToken = false;
  foreach ($validTokens as $validToken) {
    if (hash_equals((string) $validToken, $token)) {
      $isValidToken = true;
      break;
    }
  }
  if ($mode === 'subscribe' && $isValidToken) {
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

if (strtolower(trim((string) ($payload['object'] ?? ''))) === 'whatsapp_business_account') {
  if (!defined('WHATSAPP_WEBHOOK_RAW_BODY')) define('WHATSAPP_WEBHOOK_RAW_BODY', $raw);
  require __DIR__ . '/whatsapp_webhook.php';
  exit;
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
      $hasProcessableEvent = isset($event['message'])
        || isset($event['postback'])
        || (isset($event['referral']) && is_array($event['referral']));
      if (!$hasProcessableEvent) continue;
      $leadId = ig_upsert_lead($pdo, $TABLE_LEADS, $channelsTable, $event, $provider);
      if ($leadId > 0) $createdOrUpdated[] = $leadId;
      else $ignoredEvents++;
    }
  }

  ig_json(['ok' => true, 'lead_ids' => array_values(array_unique($createdOrUpdated)), 'ignored_events' => $ignoredEvents]);
} catch (Throwable $e) {
  ig_json(['ok' => false, 'error' => 'No se pudo procesar el webhook'], 500);
}
