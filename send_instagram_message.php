<?php
// send_instagram_message.php
declare(strict_types=1);
require_once __DIR__ . '/auth/require_auth.php';
require_once __DIR__ . '/config/conversations.php';
require_permission('send_messages');

conv_ensure_schema($pdo);

$conversationsTable = conv_conversations_table();
$contactsTable = conv_contacts_table();
$messagesTable = conv_messages_table();

function send_wants_json(): bool {
  $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
  $requested = (string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '');
  return str_contains($accept, 'application/json') || strtolower($requested) === 'fetch';
}

function send_json(array $payload, int $status = 200): void {
  if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
  http_response_code($status);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}

function send_redirect(int $conversationId, string $notice): void {
  if (send_wants_json()) send_json(['ok' => false, 'error' => $notice, 'conversation_id' => $conversationId], 400);
  header('Location: inbox.php?id=' . $conversationId . '&notice=' . rawurlencode($notice));
  exit;
}

function send_media_type_from_mime(string $mime): ?string {
  if (in_array($mime, (array) app_config('media.allowed_image_mimes', []), true)) return 'image';
  if (in_array($mime, (array) app_config('media.allowed_audio_mimes', []), true)) return 'audio';
  return null;
}

function send_media_label(string $type): string {
  return $type === 'audio' ? 'audio' : 'imagen';
}

function send_public_media_text(string $type): string {
  return $type === 'audio' ? 'Audio enviado' : 'Imagen enviada';
}

function send_normalize_uploads($upload): array {
  if (!is_array($upload)) return [];
  $errors = $upload['error'] ?? UPLOAD_ERR_NO_FILE;
  if (is_array($errors)) {
    $files = [];
    foreach ($errors as $index => $error) {
      if ((int) $error === UPLOAD_ERR_NO_FILE) continue;
      $files[] = [
        'name' => (string) (($upload['name'][$index] ?? '') ?: ''),
        'type' => (string) (($upload['type'][$index] ?? '') ?: ''),
        'tmp_name' => (string) (($upload['tmp_name'][$index] ?? '') ?: ''),
        'error' => (int) $error,
        'size' => (int) (($upload['size'][$index] ?? 0) ?: 0),
      ];
    }
    return $files;
  }
  if ((int) $errors === UPLOAD_ERR_NO_FILE) return [];
  return [[
    'name' => (string) (($upload['name'] ?? '') ?: ''),
    'type' => (string) (($upload['type'] ?? '') ?: ''),
    'tmp_name' => (string) (($upload['tmp_name'] ?? '') ?: ''),
    'error' => (int) $errors,
    'size' => (int) (($upload['size'] ?? 0) ?: 0),
  ]];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  if (send_wants_json()) send_json(['ok' => false, 'error' => 'Metodo no permitido.'], 405);
  http_response_code(405);
  echo 'Metodo no permitido.';
  exit;
}

$conversationId = (int) ($_POST['conversation_id'] ?? 0);
$csrf = (string) ($_POST['csrf'] ?? '');
if ($conversationId <= 0) send_redirect(0, 'Conversacion invalida.');
if (!$csrf || !isset($_SESSION['csrf']) || !hash_equals((string) $_SESSION['csrf'], $csrf)) {
  send_redirect($conversationId, 'Sesion invalida. Recarga la pagina.');
}

$message = trim((string) ($_POST['message'] ?? ''));
$mediaUpload = $_FILES['media'] ?? ($_FILES['image'] ?? null);
$mediaUploads = send_normalize_uploads($mediaUpload);
$hasMedia = count($mediaUploads) > 0;
if ($message === '' && !$hasMedia) send_redirect($conversationId, 'Escribe un mensaje o adjunta una imagen/audio antes de enviar.');
if (mb_strlen($message) > 1000) send_redirect($conversationId, 'El mensaje supera el limite permitido.');

$stmt = $pdo->prepare(<<<SQL
SELECT
  c.*,
  ct.external_contact_id AS contact_external_id,
  ct.display_name,
  ct.username,
  (
    SELECT MAX(im.sent_at)
    FROM {$messagesTable} im
    WHERE im.conversation_id = c.id AND im.direction = 'inbound'
  ) AS last_inbound_at
FROM {$conversationsTable} c
JOIN {$contactsTable} ct ON ct.id = c.contact_id
WHERE c.id = ?
LIMIT 1
SQL);
$stmt->execute([$conversationId]);
$conversation = $stmt->fetch();
if (!$conversation) send_redirect($conversationId, 'No se encontro la conversacion.');

$replyWindow = meta_reply_window_info($conversation['last_inbound_at'] ?? '');
if (!($replyWindow['can_reply'] ?? false)) {
  send_redirect($conversationId, 'Chat vencido. No se puede responder desde el CRM hasta recibir un nuevo mensaje del cliente.');
}

$now = gmdate('Y-m-d H:i:s');
$responseMessages = [];
$lastPreview = $message;
$pendingMedia = [];

if ($hasMedia) {
  if (!r2_is_configured()) send_redirect($conversationId, 'R2 no esta configurado para enviar adjuntos.');
  if (count($mediaUploads) > 5) send_redirect($conversationId, 'Puedes adjuntar un maximo de 5 fotos por envio.');
  $maxBytes = max(1024, (int) app_config('media.max_upload_bytes', 8388608));
  $finfo = new finfo(FILEINFO_MIME_TYPE);
  foreach ($mediaUploads as $mediaUpload) {
    $errorCode = (int) ($mediaUpload['error'] ?? UPLOAD_ERR_OK);
    if ($errorCode !== UPLOAD_ERR_OK) send_redirect($conversationId, 'No se pudo recibir uno de los adjuntos.');
    $tmp = (string) ($mediaUpload['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) send_redirect($conversationId, 'Adjunto invalido.');
    $bytes = file_get_contents($tmp);
    if (!is_string($bytes) || $bytes === '') send_redirect($conversationId, 'El adjunto esta vacio.');
    if (strlen($bytes) > $maxBytes) send_redirect($conversationId, 'Uno de los adjuntos supera el tamaño permitido.');
    $mime = (string) ($finfo->buffer($bytes) ?: '');
    $mediaType = send_media_type_from_mime($mime);
    if ($mediaType === null) send_redirect($conversationId, 'Solo se permiten imagenes JPG, PNG, GIF, WEBP o audios MP3, M4A, AAC, OGG, WAV, WEBM.');
    if (count($mediaUploads) > 1 && $mediaType !== 'image') send_redirect($conversationId, 'Solo puedes adjuntar varias fotos juntas. Los audios se envian uno por uno.');
    if ($mediaType === 'audio' && $mime === 'video/mp4') $mime = 'audio/mp4';
    if ($mediaType === 'audio' && $mime === 'video/webm') $mime = 'audio/webm';
    if ($mediaType === 'audio' && $mime === 'application/ogg') $mime = 'audio/ogg';

    $key = r2_random_key('instagram/outbound/' . $mediaType . '/' . $conversationId, $mime);
    $upload = r2_upload_bytes($key, $bytes, $mime);
    if (!($upload['ok'] ?? false)) send_redirect($conversationId, 'No se pudo subir uno de los adjuntos a R2.');
    $signedUrl = r2_presigned_url($key, 3600);
    if (!$signedUrl) send_redirect($conversationId, 'No se pudo preparar uno de los adjuntos para Meta.');

    $pendingMedia[] = [
      'bytes' => $bytes,
      'mime' => $mime,
      'type' => $mediaType,
      'key' => $key,
      'signed_url' => $signedUrl,
      'filename' => (string) (($mediaUpload['name'] ?? '') ?: (send_media_label($mediaType) . '.' . r2_extension_from_mime($mime))),
    ];
  }
}

if ($message !== '') {
  $result = conv_send_instagram_message($pdo, $conversation, $message);
  if (!($result['ok'] ?? false)) {
    send_redirect($conversationId, 'Meta no pudo enviar el mensaje: ' . (string) ($result['error'] ?? 'Error desconocido.'));
  }

  $data = is_array($result['data'] ?? null) ? $result['data'] : [];
  $externalMessageId = (string) ($data['message_id'] ?? $data['id'] ?? '');
  $externalMessageId = $externalMessageId !== '' ? $externalMessageId : null;
  $messageId = conv_add_message($pdo, [
    'conversation_id' => $conversationId,
    'external_message_id' => $externalMessageId,
    'direction' => 'outbound',
    'sender_external_id' => (string) ($result['target'] ?? ''),
    'message_type' => 'text',
    'message_text' => $message,
    'payload_json' => json_encode($data, JSON_UNESCAPED_UNICODE),
    'sent_by' => (int) ($_SESSION['user_id'] ?? 0),
    'sent_at' => $now,
    'delivery_status' => 'sent',
  ]);
  $responseMessages[] = [
    'id' => $messageId,
    'direction' => 'outbound',
    'text' => $message,
    'attachments' => [],
    'time' => app_datetime($now),
    'sent_by_username' => (string) ($_SESSION['username'] ?? ''),
    'delivery_status' => 'sent',
  ];
}

if ($hasMedia) {
  if (!$pendingMedia) send_redirect($conversationId, 'No se pudo preparar el adjunto.');
  foreach ($pendingMedia as $pendingMediaItem) {
    $mediaType = (string) ($pendingMediaItem['type'] ?? 'image');
    $result = conv_send_instagram_attachment($pdo, $conversation, (string) $pendingMediaItem['signed_url'], $mediaType);
    if (!($result['ok'] ?? false)) {
      send_redirect($conversationId, 'Meta no pudo enviar el ' . send_media_label($mediaType) . ': ' . (string) ($result['error'] ?? 'Error desconocido.'));
    }

    $data = is_array($result['data'] ?? null) ? $result['data'] : [];
    $externalMessageId = (string) ($data['message_id'] ?? $data['id'] ?? '');
    $externalMessageId = $externalMessageId !== '' ? $externalMessageId : null;
    $mediaText = send_public_media_text($mediaType);
    $mediaMessageId = conv_add_message($pdo, [
      'conversation_id' => $conversationId,
      'external_message_id' => $externalMessageId,
      'direction' => 'outbound',
      'sender_external_id' => (string) ($result['target'] ?? ''),
      'message_type' => $mediaType,
      'message_text' => $mediaText,
      'payload_json' => json_encode($data, JSON_UNESCAPED_UNICODE),
      'sent_by' => (int) ($_SESSION['user_id'] ?? 0),
      'sent_at' => $now,
      'delivery_status' => 'sent',
    ]);
    $attachmentId = conv_add_attachment($pdo, [
      'conversation_id' => $conversationId,
      'message_id' => $mediaMessageId,
      'direction' => 'outbound',
      'media_type' => $mediaType,
      'mime_type' => (string) $pendingMediaItem['mime'],
      'file_size' => strlen((string) $pendingMediaItem['bytes']),
      'storage_disk' => 'r2',
      'storage_key' => (string) $pendingMediaItem['key'],
      'filename' => (string) $pendingMediaItem['filename'],
    ]);
    $responseMessages[] = [
      'id' => $mediaMessageId,
      'direction' => 'outbound',
      'text' => $mediaText,
      'attachments' => [[
        'id' => $attachmentId,
        'media_type' => $mediaType,
        'mime_type' => (string) $pendingMediaItem['mime'],
        'file_size' => strlen((string) $pendingMediaItem['bytes']),
        'filename' => (string) $pendingMediaItem['filename'],
        'url' => 'media.php?id=' . $attachmentId,
      ]],
      'time' => app_datetime($now),
      'sent_by_username' => (string) ($_SESSION['username'] ?? ''),
      'delivery_status' => 'sent',
    ];
    $lastPreview = $mediaText;
  }
  if (count($pendingMedia) > 1) $lastPreview = count($pendingMedia) . ' imagenes enviadas';
}

conv_upsert_conversation($pdo, [
  'channel_id' => $conversation['channel_id'] ?? null,
  'contact_id' => (int) $conversation['contact_id'],
  'lead_id' => $conversation['lead_id'] ?? null,
  'external_source' => 'instagram',
  'external_thread_id' => (string) $conversation['external_thread_id'],
  'last_message_preview' => $lastPreview !== '' ? $lastPreview : 'Adjunto enviado',
  'last_message_at' => $now,
  'unread_increment' => 0,
]);

if (send_wants_json()) {
  send_json([
    'ok' => true,
    'notice' => 'Mensaje enviado.',
    'conversation_id' => $conversationId,
    'message' => $responseMessages[0] ?? null,
    'messages' => $responseMessages,
  ]);
}

send_redirect($conversationId, 'Mensaje enviado.');
