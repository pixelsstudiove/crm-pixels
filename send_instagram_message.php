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
if ($message === '') send_redirect($conversationId, 'Escribe un mensaje antes de enviar.');
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

$result = conv_send_instagram_message($pdo, $conversation, $message);
if (!($result['ok'] ?? false)) {
  send_redirect($conversationId, 'Meta no pudo enviar el mensaje: ' . (string) ($result['error'] ?? 'Error desconocido.'));
}

$data = is_array($result['data'] ?? null) ? $result['data'] : [];
$externalMessageId = (string) ($data['message_id'] ?? $data['id'] ?? '');
$externalMessageId = $externalMessageId !== '' ? $externalMessageId : null;
$now = gmdate('Y-m-d H:i:s');

conv_add_message($pdo, [
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
$messageId = (int) $pdo->lastInsertId();

conv_upsert_conversation($pdo, [
  'channel_id' => $conversation['channel_id'] ?? null,
  'contact_id' => (int) $conversation['contact_id'],
  'lead_id' => $conversation['lead_id'] ?? null,
  'external_source' => 'instagram',
  'external_thread_id' => (string) $conversation['external_thread_id'],
  'last_message_preview' => $message,
  'last_message_at' => $now,
  'unread_increment' => 0,
]);

if (send_wants_json()) {
  send_json([
    'ok' => true,
    'notice' => 'Mensaje enviado.',
    'conversation_id' => $conversationId,
    'message' => [
      'id' => $messageId,
      'direction' => 'outbound',
      'text' => $message,
      'time' => date('d/m/Y H:i', strtotime($now)),
      'sent_by_username' => (string) ($_SESSION['username'] ?? ''),
      'delivery_status' => 'sent',
    ],
  ]);
}

send_redirect($conversationId, 'Mensaje enviado.');
