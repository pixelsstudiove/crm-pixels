<?php
// whatsapp_webhook.php
// Recibe eventos de WhatsApp Cloud API y crea/actualiza conversaciones en el CRM.
declare(strict_types=1);

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/instagram_channels.php';
require_once __DIR__ . '/config/conversations.php';

function wa_json(array $payload, int $status = 200): void {
  if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
  http_response_code($status);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}

function wa_clean($value, int $max = 180): ?string {
  $value = trim(str_replace("\0", '', (string) $value));
  if ($value === '') return null;
  return mb_substr($value, 0, $max);
}

function wa_message_time($timestamp): string {
  $raw = (int) $timestamp;
  if ($raw <= 0) $raw = time();
  return gmdate('Y-m-d H:i:s', $raw);
}

function wa_validate_signature(string $rawBody): bool {
  $secret = trim((string) app_config('whatsapp_cloud.app_secret', ''));
  if ($secret === '') return true;
  $signature = (string) ($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '');
  if (!str_starts_with($signature, 'sha256=')) return false;
  $expected = 'sha256=' . hash_hmac('sha256', $rawBody, $secret);
  return hash_equals($expected, $signature);
}

function wa_contact_for(array $value, string $from): array {
  foreach (($value['contacts'] ?? []) as $contact) {
    if (!is_array($contact)) continue;
    if ((string) ($contact['wa_id'] ?? '') === $from) return $contact;
  }
  return [];
}

function wa_message_text(array $message): string {
  $type = strtolower(trim((string) ($message['type'] ?? 'text')));
  if ($type === 'text') return wa_clean($message['text']['body'] ?? '', 5000) ?? '';
  if ($type === 'button') return wa_clean($message['button']['text'] ?? $message['button']['payload'] ?? '', 5000) ?? '';
  if ($type === 'interactive') {
    $reply = $message['interactive']['button_reply']['title'] ?? $message['interactive']['list_reply']['title'] ?? null;
    return wa_clean($reply, 5000) ?? '';
  }
  if (in_array($type, ['image', 'audio', 'video', 'document', 'sticker'], true)) {
    $caption = wa_clean($message[$type]['caption'] ?? '', 5000);
    return $caption ?: 'Adjunto recibido: ' . $type;
  }
  return 'Se ha recibido un mensaje no soportado en esta plataforma, accede a este mensaje directamente desde la app oficial.';
}

function wa_message_media_id(array $message): ?string {
  $type = strtolower(trim((string) ($message['type'] ?? '')));
  if (!in_array($type, ['image', 'audio', 'video', 'document', 'sticker'], true)) return null;
  return wa_clean($message[$type]['id'] ?? null, 180);
}

function wa_message_media_type(array $message): ?string {
  $type = strtolower(trim((string) ($message['type'] ?? '')));
  if ($type === 'image') return 'image';
  if ($type === 'audio') return 'audio';
  return null;
}

function wa_download_media_bytes(string $mediaId, string $token): array {
  $meta = ig_graph_request('GET', $mediaId, ['access_token' => $token]);
  if (!($meta['ok'] ?? false)) return ['ok' => false, 'error' => (string) ($meta['error'] ?? 'Meta no devolvió el adjunto.')];
  $data = is_array($meta['data'] ?? null) ? $meta['data'] : [];
  $url = trim((string) ($data['url'] ?? ''));
  if ($url === '') return ['ok' => false, 'error' => 'Meta no devolvió la URL del adjunto.'];

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 45,
    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
  ]);
  $bytes = curl_exec($ch);
  $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $error = curl_error($ch);
  $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
  curl_close($ch);
  if ($error !== '' || $http < 200 || $http >= 300 || !is_string($bytes) || $bytes === '') {
    return ['ok' => false, 'error' => $error !== '' ? $error : 'No se pudo descargar el adjunto de Meta.'];
  }
  $mime = wa_clean($data['mime_type'] ?? strtok($contentType, ';') ?: 'application/octet-stream', 120) ?? 'application/octet-stream';
  return [
    'ok' => true,
    'bytes' => $bytes,
    'mime' => $mime,
    'url' => $url,
    'size' => (int) ($data['file_size'] ?? strlen($bytes)),
  ];
}

function wa_store_message_media(PDO $pdo, array $channel, int $conversationId, int $messageId, array $message): void {
  $mediaId = wa_message_media_id($message);
  $mediaType = wa_message_media_type($message);
  if ($mediaId === null || $mediaType === null || $messageId <= 0 || !r2_is_configured()) return;

  $token = trim((string) ($channel['page_access_token'] ?? ''));
  if ($token === '') return;

  $download = wa_download_media_bytes($mediaId, $token);
  if (!($download['ok'] ?? false)) return;

  $mime = (string) ($download['mime'] ?? '');
  $allowed = $mediaType === 'image'
    ? (array) app_config('media.allowed_image_mimes', [])
    : (array) app_config('media.allowed_audio_mimes', []);
  if (!in_array($mime, $allowed, true)) return;

  $key = r2_random_key('whatsapp/inbound/' . $mediaType . '/' . $conversationId, $mime);
  $upload = r2_upload_bytes($key, (string) $download['bytes'], $mime);
  if (!($upload['ok'] ?? false)) return;

  conv_add_attachment($pdo, [
    'conversation_id' => $conversationId,
    'message_id' => $messageId,
    'direction' => 'inbound',
    'media_type' => $mediaType,
    'mime_type' => $mime,
    'file_size' => (int) ($download['size'] ?? strlen((string) $download['bytes'])),
    'storage_key' => $key,
    'original_url' => (string) ($download['url'] ?? ''),
    'filename' => $mediaId . '.' . r2_extension_from_mime($mime),
    'external_attachment_id' => $mediaId,
  ]);
}

function wa_log(PDO $pdo, array $data): void {
  try { conv_log_webhook_event($pdo, $data); } catch (Throwable $e) { /* no-op */ }
}

function wa_message_already_exists(PDO $pdo, int $conversationId, ?string $messageId): bool {
  if ($conversationId <= 0 || $messageId === null || $messageId === '') return false;
  try {
    $table = conv_messages_table();
    $hash = hash('sha256', $messageId);
    $stmt = $pdo->prepare("SELECT id FROM {$table} WHERE conversation_id=? AND external_message_hash=? LIMIT 1");
    $stmt->execute([$conversationId, $hash]);
    return (int) ($stmt->fetchColumn() ?: 0) > 0;
  } catch (Throwable $e) {
    return false;
  }
}

function wa_update_message_status(PDO $pdo, ?string $messageId, ?string $status): void {
  $messageId = wa_clean($messageId, 2000);
  $status = wa_clean($status, 40);
  if ($messageId === null || $status === null) return;
  try {
    $table = conv_messages_table();
    $hash = hash('sha256', $messageId);
    $stmt = $pdo->prepare("UPDATE {$table} SET delivery_status=? WHERE external_message_hash=?");
    $stmt->execute([$status, $hash]);
  } catch (Throwable $e) {
    /* no-op */
  }
}

function wa_process_status(PDO $pdo, string $channelsTable, array $value, array $status): void {
  $phoneNumberId = wa_clean($value['metadata']['phone_number_id'] ?? null, 120);
  $displayPhone = wa_clean($value['metadata']['display_phone_number'] ?? null, 40);
  $recipientId = wa_clean($status['recipient_id'] ?? null, 160);
  $messageId = wa_clean($status['id'] ?? null, 2000);
  $deliveryStatus = wa_clean($status['status'] ?? null, 40);
  $eventAt = wa_message_time($status['timestamp'] ?? null);

  wa_update_message_status($pdo, $messageId, $deliveryStatus);

  if ($phoneNumberId === null) {
    wa_log($pdo, [
      'source' => 'whatsapp',
      'status' => 'ignored',
      'event_type' => 'message_status',
      'recipient_id' => $phoneNumberId,
      'sender_id' => $recipientId,
      'external_message_id' => $messageId,
      'message_preview' => $deliveryStatus,
      'error_message' => 'Status de WhatsApp sin phone_number_id.',
      'payload_json' => json_encode(['value' => $value, 'status' => $status], JSON_UNESCAPED_UNICODE),
    ]);
    return;
  }

  $channel = ig_channel_find_by_recipient($pdo, $channelsTable, $phoneNumberId, 'whatsapp');
  if (!$channel) {
    wa_log($pdo, [
      'source' => 'whatsapp',
      'status' => 'ignored',
      'event_type' => 'message_status',
      'recipient_id' => $phoneNumberId,
      'sender_id' => $recipientId,
      'external_message_id' => $messageId,
      'message_preview' => $deliveryStatus,
      'error_message' => 'No existe canal activo de WhatsApp para el phone_number_id.',
      'payload_json' => json_encode(['value' => $value, 'status' => $status], JSON_UNESCAPED_UNICODE),
    ]);
    return;
  }

  $accountId = (int) (($channel['account_id'] ?? current_account_id()) ?: accounts_default_id($pdo));
  try {
    $stmt = $pdo->prepare("UPDATE {$channelsTable} SET last_event_at=?, updated_at=NOW() WHERE id=?");
    $stmt->execute([$eventAt, (int) ($channel['id'] ?? 0)]);
  } catch (Throwable $e) {
    /* no-op */
  }

  wa_log($pdo, [
    'account_id' => $accountId,
    'source' => 'whatsapp',
    'status' => 'processed',
    'event_type' => 'message_status',
    'recipient_id' => $phoneNumberId,
    'sender_id' => $recipientId,
    'channel_id' => (int) ($channel['id'] ?? 0),
    'channel_username' => (string) (($channel['whatsapp_display_phone_number'] ?? '') ?: ($displayPhone ?: ($channel['instagram_username'] ?? ''))),
    'external_message_id' => $messageId,
    'message_preview' => $deliveryStatus,
    'payload_json' => json_encode(['value' => $value, 'status' => $status], JSON_UNESCAPED_UNICODE),
  ]);
}

function wa_process_message(PDO $pdo, string $channelsTable, string $leadsTable, array $value, array $message): void {
  $phoneNumberId = wa_clean($value['metadata']['phone_number_id'] ?? null, 120);
  $displayPhone = wa_clean($value['metadata']['display_phone_number'] ?? null, 40);
  $from = wa_clean($message['from'] ?? null, 160);
  $messageId = wa_clean($message['id'] ?? null, 2000);
  $messageType = wa_clean($message['type'] ?? 'text', 40) ?? 'text';
  $messageAt = wa_message_time($message['timestamp'] ?? null);
  $messageText = wa_message_text($message);

  if ($phoneNumberId === null || $from === null) {
    wa_log($pdo, [
      'source' => 'whatsapp',
      'status' => 'ignored',
      'event_type' => 'invalid_payload',
      'recipient_id' => $phoneNumberId,
      'sender_id' => $from,
      'external_message_id' => $messageId,
      'message_preview' => $messageText,
      'error_message' => 'Payload de WhatsApp sin phone_number_id o remitente.',
      'payload_json' => json_encode($message, JSON_UNESCAPED_UNICODE),
    ]);
    return;
  }

  $channel = ig_channel_find_by_recipient($pdo, $channelsTable, $phoneNumberId, 'whatsapp');
  if (!$channel) {
    wa_log($pdo, [
      'source' => 'whatsapp',
      'status' => 'ignored',
      'event_type' => 'inactive_or_unknown_channel',
      'recipient_id' => $phoneNumberId,
      'sender_id' => $from,
      'external_message_id' => $messageId,
      'message_preview' => $messageText,
      'error_message' => 'No existe canal activo de WhatsApp para el phone_number_id.',
      'payload_json' => json_encode(['value' => $value, 'message' => $message], JSON_UNESCAPED_UNICODE),
    ]);
    return;
  }

  $accountId = (int) (($channel['account_id'] ?? current_account_id()) ?: accounts_default_id($pdo));
  $contact = wa_contact_for($value, $from);
  $profileName = wa_clean($contact['profile']['name'] ?? null, 180);
  $displayName = $profileName ?: '+' . preg_replace('/\D+/', '', $from);
  $threadId = $phoneNumberId . ':' . $from;

  $contactId = conv_upsert_contact($pdo, [
    'account_id' => $accountId,
    'external_source' => 'whatsapp',
    'external_contact_id' => $from,
    'display_name' => $displayName,
    'username' => '+' . preg_replace('/\D+/', '', $from),
    'profile_url' => 'https://wa.me/' . preg_replace('/\D+/', '', $from),
    'last_seen_at' => $messageAt,
  ]);
  if ($contactId <= 0) return;

  $conversationId = conv_upsert_conversation($pdo, [
    'account_id' => $accountId,
    'channel_id' => (int) ($channel['id'] ?? 0),
    'contact_id' => $contactId,
    'external_source' => 'whatsapp',
    'external_thread_id' => $threadId,
    'last_message_preview' => $messageText,
    'last_message_at' => $messageAt,
    'unread_increment' => 0,
  ]);
  if ($conversationId <= 0) return;

  $isDuplicateMessage = wa_message_already_exists($pdo, $conversationId, $messageId);

  $leadId = conv_ensure_lead_for_conversation($pdo, $leadsTable, $conversationId);
  if ($leadId > 0) {
    conv_upsert_conversation($pdo, [
      'account_id' => $accountId,
      'channel_id' => (int) ($channel['id'] ?? 0),
      'contact_id' => $contactId,
      'lead_id' => $leadId,
      'external_source' => 'whatsapp',
      'external_thread_id' => $threadId,
      'unread_increment' => 0,
    ]);
  }

  $internalMessageId = conv_add_message($pdo, [
    'account_id' => $accountId,
    'conversation_id' => $conversationId,
    'external_message_id' => $messageId,
    'direction' => 'inbound',
    'sender_external_id' => $from,
    'message_type' => $messageType,
    'message_text' => $messageText,
    'payload_json' => json_encode(['source' => 'whatsapp_cloud', 'value' => $value, 'message' => $message], JSON_UNESCAPED_UNICODE),
    'sent_at' => $messageAt,
    'delivery_status' => 'received',
  ]);
  wa_store_message_media($pdo, $channel, $conversationId, $internalMessageId, $message);

  if (!$isDuplicateMessage) {
    conv_upsert_conversation($pdo, [
      'account_id' => $accountId,
      'channel_id' => (int) ($channel['id'] ?? 0),
      'contact_id' => $contactId,
      'lead_id' => $leadId ?: null,
      'external_source' => 'whatsapp',
      'external_thread_id' => $threadId,
      'last_message_preview' => $messageText,
      'last_message_at' => $messageAt,
      'unread_increment' => 1,
    ]);
  }

  try {
    $stmt = $pdo->prepare("UPDATE {$channelsTable} SET last_event_at=?, updated_at=NOW() WHERE id=?");
    $stmt->execute([$messageAt, (int) ($channel['id'] ?? 0)]);
  } catch (Throwable $e) {
    /* no-op */
  }

  wa_log($pdo, [
    'account_id' => $accountId,
    'source' => 'whatsapp',
    'status' => 'processed',
    'event_type' => $messageType,
    'recipient_id' => $phoneNumberId,
    'sender_id' => $from,
    'channel_id' => (int) ($channel['id'] ?? 0),
    'channel_username' => (string) (($channel['whatsapp_display_phone_number'] ?? '') ?: ($displayPhone ?: ($channel['instagram_username'] ?? ''))),
    'external_message_id' => $messageId,
    'lead_id' => $leadId ?: null,
    'conversation_id' => $conversationId,
    'message_preview' => $messageText,
    'payload_json' => json_encode(['value' => $value, 'message' => $message], JSON_UNESCAPED_UNICODE),
  ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  $mode = (string) ($_GET['hub_mode'] ?? $_GET['hub.mode'] ?? '');
  $token = (string) ($_GET['hub_verify_token'] ?? $_GET['hub.verify_token'] ?? '');
  $challenge = (string) ($_GET['hub_challenge'] ?? $_GET['hub.challenge'] ?? '');
  $expected = trim((string) app_config('whatsapp_cloud.webhook_verify_token', ''));
  if ($mode === 'subscribe' && $expected !== '' && hash_equals($expected, $token)) {
    if (!headers_sent()) header('Content-Type: text/plain; charset=utf-8');
    echo $challenge;
    exit;
  }
  http_response_code(403);
  echo 'Token invalido';
  exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  wa_json(['ok' => false, 'error' => 'Metodo no permitido.'], 405);
}

$raw = file_get_contents('php://input') ?: '';
conv_ensure_schema($pdo);
$channelsTable = ig_channels_table();
ig_channels_ensure_schema($pdo, $channelsTable);

if (!wa_validate_signature($raw)) {
  wa_log($pdo, [
    'source' => 'whatsapp',
    'status' => 'ignored',
    'event_type' => 'invalid_signature',
    'error_message' => 'Firma invalida. Revisa WHATSAPP_CLOUD_APP_SECRET / FACEBOOK_APP_SECRET.',
    'payload_json' => json_encode(['raw' => mb_substr($raw, 0, 2000)], JSON_UNESCAPED_UNICODE),
  ]);
  wa_json(['ok' => false, 'error' => 'Firma invalida.'], 403);
}

$payload = json_decode($raw, true);
if (!is_array($payload)) wa_json(['ok' => false, 'error' => 'JSON invalido.'], 400);

foreach (($payload['entry'] ?? []) as $entry) {
  if (!is_array($entry)) continue;
  foreach (($entry['changes'] ?? []) as $change) {
    if (!is_array($change)) continue;
    $value = is_array($change['value'] ?? null) ? $change['value'] : [];
    foreach (($value['messages'] ?? []) as $message) {
      if (is_array($message)) wa_process_message($pdo, $channelsTable, $TABLE_LEADS, $value, $message);
    }
    foreach (($value['statuses'] ?? []) as $status) {
      if (!is_array($status)) continue;
      wa_process_status($pdo, $channelsTable, $value, $status);
    }
  }
}

wa_json(['ok' => true]);
