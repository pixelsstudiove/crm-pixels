<?php
// send_instagram_message.php
declare(strict_types=1);
require_once __DIR__ . '/auth/require_auth.php';
require_once __DIR__ . '/config/conversations.php';
require_once __DIR__ . '/config/lead_status_history.php';
require_permission('send_messages');

conv_ensure_schema($pdo);

$conversationsTable = conv_conversations_table();
$contactsTable = conv_contacts_table();
$messagesTable = conv_messages_table();
$currentAccountId = (int) (current_account_id() ?: accounts_default_id($pdo));

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

function send_redirect(int $conversationId, string $notice, ?string $routeId = null): void {
  $routeId = $routeId !== null && $routeId !== '' ? $routeId : (string) $conversationId;
  if (send_wants_json()) send_json(['ok' => false, 'error' => $notice, 'conversation_id' => $routeId], 400);
  header('Location: ' . account_url('inbox.php', ['id' => $routeId, 'notice' => $notice]));
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

function send_conversation_provider(array $conversation): string {
  $source = strtolower(trim((string) ($conversation['external_source'] ?? 'instagram')));
  return $source === 'messenger' ? 'messenger' : 'instagram';
}

function send_conversation_has_outbound(PDO $pdo, int $conversationId): bool {
  if ($conversationId <= 0) return false;
  $messagesTable = conv_messages_table();
  $stmt = $pdo->prepare("SELECT 1 FROM {$messagesTable} WHERE conversation_id=? AND direction='outbound' AND delivery_status='sent' LIMIT 1");
  $stmt->execute([$conversationId]);
  return (bool) $stmt->fetchColumn();
}

function send_auto_contact_lead_after_reply(PDO $pdo, string $leadsTable, int $leadId, int $accountId, bool $hadOutboundBefore, string $sentAt): ?array {
  if ($leadId <= 0 || $accountId <= 0 || $hadOutboundBefore) return null;

  $defaultStatus = (string) app_config('sales_funnel.default_status', 'nuevo_lead');
  $targetStatus = 'en_conversacion';
  $allowedStatuses = array_keys((array) app_config('sales_funnel.statuses', []));
  if (!in_array($targetStatus, $allowedStatuses, true)) return null;

  $stmt = $pdo->prepare("SELECT sales_status FROM {$leadsTable} WHERE id=? AND account_id=? LIMIT 1");
  $stmt->execute([$leadId, $accountId]);
  $previousStatus = (string) ($stmt->fetchColumn() ?: '');
  if ($previousStatus !== $defaultStatus) return null;

  $update = $pdo->prepare("UPDATE {$leadsTable} SET sales_status=?, updated_at=NOW() WHERE id=? AND account_id=? AND sales_status=?");
  $update->execute([$targetStatus, $leadId, $accountId, $previousStatus]);
  if ($update->rowCount() < 1) return null;

  $operator = trim((string) ($_SESSION['username'] ?? 'usuario'));
  $when = app_datetime($sentAt, 'd/m/Y H:i', $sentAt);
  $reason = "Conversacion respondida por {$operator} el {$when}. Status actualizado automaticamente de " . lead_status_label($previousStatus) . ' a ' . lead_status_label($targetStatus) . '.';
  lead_status_history_record($pdo, $leadId, $previousStatus, $targetStatus, $reason, $accountId);

  return [
    'previous_status' => $previousStatus,
    'sales_status' => $targetStatus,
    'label' => lead_status_label($targetStatus),
    'reason' => $reason,
  ];
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

$conversationRouteId = trim((string) ($_POST['conversation_id'] ?? ''));
$requestAccountId = accounts_request_account_id($pdo);
$postAccountId = max(0, (int) ($_POST['account_id'] ?? 0));
$lookupAccountId = $requestAccountId > 0 ? $requestAccountId : $postAccountId;
$conversationId = $lookupAccountId > 0
  ? conv_resolve_conversation_route_id($pdo, $lookupAccountId, $conversationRouteId)
  : 0;
$csrf = (string) ($_POST['csrf'] ?? '');
if ($conversationId <= 0) send_redirect(0, 'Conversacion invalida.', $conversationRouteId);
if (!$csrf || !isset($_SESSION['csrf']) || !hash_equals((string) $_SESSION['csrf'], $csrf)) {
  send_redirect($conversationId, 'Sesion invalida. Recarga la pagina.', $conversationRouteId);
}

$message = trim((string) ($_POST['message'] ?? ''));
$mediaUpload = $_FILES['media'] ?? ($_FILES['image'] ?? null);
$mediaUploads = send_normalize_uploads($mediaUpload);
$hasMedia = count($mediaUploads) > 0;
if ($message === '' && !$hasMedia) send_redirect($conversationId, 'Escribe un mensaje o adjunta una imagen/audio antes de enviar.', $conversationRouteId);
if (mb_strlen($message) > 1000) send_redirect($conversationId, 'El mensaje supera el limite permitido.', $conversationRouteId);

$conversationSql = <<<SQL
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
  %s
LIMIT 1
SQL;
$accountSql = is_super_admin() ? '' : 'AND c.account_id = ?';
$stmt = $pdo->prepare(sprintf($conversationSql, $accountSql));
$stmt->execute(is_super_admin() ? [$conversationId] : [$conversationId, $currentAccountId]);
$conversation = $stmt->fetch();
if (!$conversation) send_redirect($conversationId, 'No se encontro la conversacion.', $conversationRouteId);

$conversationAccountId = (int) (($conversation['account_id'] ?? $currentAccountId) ?: accounts_default_id($pdo));
$conversationProvider = send_conversation_provider($conversation);
$leadId = (int) ($conversation['lead_id'] ?? 0);
if ($leadId <= 0 && isset($TABLE_LEADS)) {
  $leadId = conv_ensure_lead_for_conversation($pdo, $TABLE_LEADS, $conversationId);
  if ($leadId > 0) $conversation['lead_id'] = $leadId;
}
$hadOutboundBefore = send_conversation_has_outbound($pdo, $conversationId);
$autoContactResult = null;

$replyWindow = meta_reply_window_info($conversation['last_inbound_at'] ?? '');
if (!($replyWindow['can_reply'] ?? false)) {
  send_redirect($conversationId, 'Chat vencido. No se puede responder desde el CRM hasta recibir un nuevo mensaje del cliente.', $conversationRouteId);
}

$now = gmdate('Y-m-d H:i:s');
$responseMessages = [];
$lastPreview = $message;
$pendingMedia = [];

if ($hasMedia) {
  if (!r2_is_configured()) send_redirect($conversationId, 'R2 no esta configurado para enviar adjuntos.', $conversationRouteId);
  if (count($mediaUploads) > 5) send_redirect($conversationId, 'Puedes adjuntar un maximo de 5 fotos por envio.', $conversationRouteId);
  $maxBytes = max(1024, (int) app_config('media.max_upload_bytes', 8388608));
  $finfo = new finfo(FILEINFO_MIME_TYPE);
  foreach ($mediaUploads as $mediaUpload) {
    $errorCode = (int) ($mediaUpload['error'] ?? UPLOAD_ERR_OK);
    if ($errorCode !== UPLOAD_ERR_OK) send_redirect($conversationId, 'No se pudo recibir uno de los adjuntos.', $conversationRouteId);
    $tmp = (string) ($mediaUpload['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) send_redirect($conversationId, 'Adjunto invalido.', $conversationRouteId);
    $bytes = file_get_contents($tmp);
    if (!is_string($bytes) || $bytes === '') send_redirect($conversationId, 'El adjunto esta vacio.', $conversationRouteId);
    if (strlen($bytes) > $maxBytes) send_redirect($conversationId, 'Uno de los adjuntos supera el tamaño permitido.', $conversationRouteId);
    $mime = (string) ($finfo->buffer($bytes) ?: '');
    $mediaType = send_media_type_from_mime($mime);
    if ($mediaType === null) send_redirect($conversationId, 'Solo se permiten imagenes JPG, PNG, GIF, WEBP o audios MP3, M4A, AAC, OGG, WAV, WEBM.', $conversationRouteId);
    if (count($mediaUploads) > 1 && $mediaType !== 'image') send_redirect($conversationId, 'Solo puedes adjuntar varias fotos juntas. Los audios se envian uno por uno.', $conversationRouteId);
    if ($mediaType === 'audio' && $mime === 'video/mp4') $mime = 'audio/mp4';
    if ($mediaType === 'audio' && $mime === 'video/webm') $mime = 'audio/webm';
    if ($mediaType === 'audio' && $mime === 'application/ogg') $mime = 'audio/ogg';

    $key = r2_random_key($conversationProvider . '/outbound/' . $mediaType . '/' . $conversationId, $mime);
    $upload = r2_upload_bytes($key, $bytes, $mime);
    if (!($upload['ok'] ?? false)) send_redirect($conversationId, 'No se pudo subir uno de los adjuntos a R2.', $conversationRouteId);
    $signedUrl = r2_presigned_url($key, 3600);
    if (!$signedUrl) send_redirect($conversationId, 'No se pudo preparar uno de los adjuntos para Meta.', $conversationRouteId);

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
    send_redirect($conversationId, 'Meta no pudo enviar el mensaje: ' . (string) ($result['error'] ?? 'Error desconocido.'), $conversationRouteId);
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
  $autoContactResult = $autoContactResult ?: send_auto_contact_lead_after_reply($pdo, $TABLE_LEADS, $leadId, $conversationAccountId, $hadOutboundBefore, $now);
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
  if (!$pendingMedia) send_redirect($conversationId, 'No se pudo preparar el adjunto.', $conversationRouteId);
  foreach ($pendingMedia as $pendingMediaItem) {
    $mediaType = (string) ($pendingMediaItem['type'] ?? 'image');
    $result = conv_send_instagram_attachment($pdo, $conversation, (string) $pendingMediaItem['signed_url'], $mediaType);
    if (!($result['ok'] ?? false)) {
      send_redirect($conversationId, 'Meta no pudo enviar el ' . send_media_label($mediaType) . ': ' . (string) ($result['error'] ?? 'Error desconocido.'), $conversationRouteId);
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
    $autoContactResult = $autoContactResult ?: send_auto_contact_lead_after_reply($pdo, $TABLE_LEADS, $leadId, $conversationAccountId, $hadOutboundBefore, $now);
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
  'account_id' => (int) (($conversation['account_id'] ?? $currentAccountId) ?: accounts_default_id($pdo)),
  'channel_id' => $conversation['channel_id'] ?? null,
  'contact_id' => (int) $conversation['contact_id'],
  'lead_id' => $conversation['lead_id'] ?? null,
  'external_source' => $conversationProvider,
  'external_thread_id' => (string) $conversation['external_thread_id'],
  'last_message_preview' => $lastPreview !== '' ? $lastPreview : 'Adjunto enviado',
  'last_message_at' => $now,
  'unread_increment' => 0,
]);

if (send_wants_json()) {
  send_json([
    'ok' => true,
    'notice' => 'Mensaje enviado.',
    'conversation_id' => $conversationRouteId,
    'message' => $responseMessages[0] ?? null,
    'messages' => $responseMessages,
    'auto_status' => $autoContactResult,
  ]);
}

send_redirect($conversationId, 'Mensaje enviado.', $conversationRouteId);
