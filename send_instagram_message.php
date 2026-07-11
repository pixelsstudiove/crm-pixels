<?php
// send_instagram_message.php
declare(strict_types=1);
require_once __DIR__ . '/auth/require_auth.php';
require_once __DIR__ . '/config/conversations.php';
require_permission('send_messages');

conv_ensure_schema($pdo);

$conversationsTable = conv_conversations_table();
$contactsTable = conv_contacts_table();

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
$imageUpload = $_FILES['image'] ?? null;
$hasImage = is_array($imageUpload) && (int) ($imageUpload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
if ($message === '' && !$hasImage) send_redirect($conversationId, 'Escribe un mensaje o adjunta una imagen antes de enviar.');
if (mb_strlen($message) > 1000) send_redirect($conversationId, 'El mensaje supera el limite permitido.');

$stmt = $pdo->prepare(<<<SQL
SELECT
  c.*,
  ct.external_contact_id AS contact_external_id,
  ct.display_name,
  ct.username
FROM {$conversationsTable} c
JOIN {$contactsTable} ct ON ct.id = c.contact_id
WHERE c.id = ?
LIMIT 1
SQL);
$stmt->execute([$conversationId]);
$conversation = $stmt->fetch();
if (!$conversation) send_redirect($conversationId, 'No se encontro la conversacion.');

$now = gmdate('Y-m-d H:i:s');
$responseMessages = [];
$lastPreview = $message;
$pendingImage = null;

if ($hasImage) {
  if (!r2_is_configured()) send_redirect($conversationId, 'R2 no esta configurado para enviar imagenes.');
  $errorCode = (int) ($imageUpload['error'] ?? UPLOAD_ERR_OK);
  if ($errorCode !== UPLOAD_ERR_OK) send_redirect($conversationId, 'No se pudo recibir la imagen adjunta.');
  $tmp = (string) ($imageUpload['tmp_name'] ?? '');
  if ($tmp === '' || !is_uploaded_file($tmp)) send_redirect($conversationId, 'Imagen adjunta invalida.');
  $bytes = file_get_contents($tmp);
  if (!is_string($bytes) || $bytes === '') send_redirect($conversationId, 'La imagen esta vacia.');
  $maxBytes = max(1024, (int) app_config('media.max_upload_bytes', 8388608));
  if (strlen($bytes) > $maxBytes) send_redirect($conversationId, 'La imagen supera el tamaño permitido.');
  $finfo = new finfo(FILEINFO_MIME_TYPE);
  $mime = (string) ($finfo->buffer($bytes) ?: '');
  $allowedMimes = (array) app_config('media.allowed_image_mimes', []);
  if (!in_array($mime, $allowedMimes, true)) send_redirect($conversationId, 'Solo se permiten imagenes JPG, PNG, GIF o WEBP.');

  $key = r2_random_key('instagram/outbound/' . $conversationId, $mime);
  $upload = r2_upload_bytes($key, $bytes, $mime);
  if (!($upload['ok'] ?? false)) send_redirect($conversationId, 'No se pudo subir la imagen a R2.');
  $signedUrl = r2_presigned_url($key, 3600);
  if (!$signedUrl) send_redirect($conversationId, 'No se pudo preparar la imagen para Meta.');

  $pendingImage = [
    'bytes' => $bytes,
    'mime' => $mime,
    'key' => $key,
    'signed_url' => $signedUrl,
    'filename' => (string) ($imageUpload['name'] ?? ('imagen.' . r2_extension_from_mime($mime))),
  ];
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
    'time' => date('d/m/Y H:i', strtotime($now)),
    'sent_by_username' => (string) ($_SESSION['username'] ?? ''),
    'delivery_status' => 'sent',
  ];
}

if ($hasImage) {
  if (!is_array($pendingImage)) send_redirect($conversationId, 'No se pudo preparar la imagen.');
  $result = conv_send_instagram_image($pdo, $conversation, (string) $pendingImage['signed_url']);
  if (!($result['ok'] ?? false)) {
    send_redirect($conversationId, 'Meta no pudo enviar la imagen: ' . (string) ($result['error'] ?? 'Error desconocido.'));
  }

  $data = is_array($result['data'] ?? null) ? $result['data'] : [];
  $externalMessageId = (string) ($data['message_id'] ?? $data['id'] ?? '');
  $externalMessageId = $externalMessageId !== '' ? $externalMessageId : null;
  $imageText = $message !== '' ? 'Imagen enviada' : 'Imagen enviada';
  $imageMessageId = conv_add_message($pdo, [
    'conversation_id' => $conversationId,
    'external_message_id' => $externalMessageId,
    'direction' => 'outbound',
    'sender_external_id' => (string) ($result['target'] ?? ''),
    'message_type' => 'image',
    'message_text' => $imageText,
    'payload_json' => json_encode($data, JSON_UNESCAPED_UNICODE),
    'sent_by' => (int) ($_SESSION['user_id'] ?? 0),
    'sent_at' => $now,
    'delivery_status' => 'sent',
  ]);
  $attachmentId = conv_add_attachment($pdo, [
    'conversation_id' => $conversationId,
    'message_id' => $imageMessageId,
    'direction' => 'outbound',
    'media_type' => 'image',
    'mime_type' => (string) $pendingImage['mime'],
    'file_size' => strlen((string) $pendingImage['bytes']),
    'storage_disk' => 'r2',
    'storage_key' => (string) $pendingImage['key'],
    'filename' => (string) $pendingImage['filename'],
  ]);
  $responseMessages[] = [
    'id' => $imageMessageId,
    'direction' => 'outbound',
    'text' => $imageText,
    'attachments' => [[
      'id' => $attachmentId,
      'media_type' => 'image',
      'mime_type' => (string) $pendingImage['mime'],
      'file_size' => strlen((string) $pendingImage['bytes']),
      'filename' => (string) $pendingImage['filename'],
      'url' => 'media.php?id=' . $attachmentId,
    ]],
    'time' => date('d/m/Y H:i', strtotime($now)),
    'sent_by_username' => (string) ($_SESSION['username'] ?? ''),
    'delivery_status' => 'sent',
  ];
  $lastPreview = 'Imagen enviada';
}

conv_upsert_conversation($pdo, [
  'channel_id' => $conversation['channel_id'] ?? null,
  'contact_id' => (int) $conversation['contact_id'],
  'lead_id' => $conversation['lead_id'] ?? null,
  'external_source' => 'instagram',
  'external_thread_id' => (string) $conversation['external_thread_id'],
  'last_message_preview' => $lastPreview !== '' ? $lastPreview : 'Imagen enviada',
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
