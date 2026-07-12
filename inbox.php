<?php
// inbox.php
declare(strict_types=1);
require_once __DIR__ . '/auth/require_auth.php';
require_once __DIR__ . '/config/conversations.php';
require_once __DIR__ . '/config/lead_status_history.php';
require_permission('view_conversations');

conv_ensure_schema($pdo);

$contactsTable = conv_contacts_table();
$conversationsTable = conv_conversations_table();
$messagesTable = conv_messages_table();
$channelsTable = ig_channels_table();
$accountsTable = accounts_table();
$currentAccountId = (int) (current_account_id() ?: accounts_default_id($pdo));

$canSendMessages = can('send_messages');
$canManageConversations = can('manage_conversations') || can('send_messages');
$canEditLeads = can('edit_leads');
$canManageUsers = can('manage_users');
$canManageIntegrations = can('manage_integrations');
$canViewDashboard = can('view_dashboard');
$currentRoleLabel = role_label(current_user_role());
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
    'contactado' => 'Contactado',
    'en_conversacion' => 'En conversación',
    'interesado' => 'Interesado',
    'propuesta_enviada' => 'Propuesta enviada',
    'en_seguimiento' => 'En seguimiento',
    'cliente_ganado' => 'Cliente ganado',
    'cliente_perdido' => 'Cliente perdido',
    'no_responde' => 'No responde',
  ];
}

$errors = [];
$notice = trim((string) ($_GET['notice'] ?? ''));

function inbox_wants_json(): bool {
  $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
  $requestedWith = (string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '');
  return stripos($accept, 'application/json') !== false || strtolower($requestedWith) === 'fetch';
}

function inbox_json_error(string $message, int $status = 422): void {
  http_response_code($status);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
  exit;
}

$requestSlug = accounts_request_slug();
$requestAccount = accounts_request_account($pdo);
if ($requestSlug !== '' && !$requestAccount) {
  http_response_code(404);
  exit('Cuenta no encontrada.');
}
$requestAccountId = $requestAccount ? (int) ($requestAccount['id'] ?? 0) : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $csrf = (string) ($_POST['csrf'] ?? '');
  if (!$csrf || !isset($_SESSION['csrf']) || !hash_equals((string) $_SESSION['csrf'], $csrf)) {
    if (inbox_wants_json()) inbox_json_error('CSRF invalido. Recarga la pagina.', 403);
    $errors[] = 'CSRF invalido. Recarga la pagina.';
  } else {
    $action = (string) ($_POST['action'] ?? '');
    $conversationRouteId = (int) ($_POST['conversation_id'] ?? 0);
    $postAccountId = max(0, (int) ($_POST['account_id'] ?? 0));
    $postLookupAccountId = $requestAccountId > 0 ? $requestAccountId : $postAccountId;
    $conversationId = $postLookupAccountId > 0
      ? conv_resolve_public_conversation_id($pdo, $postLookupAccountId, $conversationRouteId)
      : $conversationRouteId;
    if ($action === 'update_status' && $canManageConversations && $conversationId > 0) {
      $status = (string) ($_POST['status'] ?? '');
      if (array_key_exists($status, $statusOptions)) {
        if (is_super_admin()) {
          $stmt = $pdo->prepare("UPDATE {$conversationsTable} SET status=?, updated_at=NOW() WHERE id=?");
          $stmt->execute([$status, $conversationId]);
        } else {
          $stmt = $pdo->prepare("UPDATE {$conversationsTable} SET status=?, updated_at=NOW() WHERE id=? AND account_id=?");
          $stmt->execute([$status, $conversationId, $currentAccountId]);
        }
        if (inbox_wants_json()) {
          header('Content-Type: application/json; charset=utf-8');
          echo json_encode(['ok' => true, 'status' => $status, 'label' => (string) $statusOptions[$status], 'notice' => 'Estado actualizado.'], JSON_UNESCAPED_UNICODE);
          exit;
        }
        header('Location: ' . account_url('inbox.php', ['id' => $conversationRouteId, 'notice' => 'Estado actualizado.']));
        exit;
      }
      if (inbox_wants_json()) inbox_json_error('Selecciona un estado valido.');
      $errors[] = 'Selecciona un estado valido.';
    } elseif ($action === 'update_sales_status' && $canEditLeads && $conversationId > 0) {
      $leadId = (int) ($_POST['lead_id'] ?? 0);
      $salesStatus = (string) ($_POST['sales_status'] ?? '');
      $changeReason = trim((string) ($_POST['change_reason'] ?? ''));
      if ($leadId <= 0 || !array_key_exists($salesStatus, $salesStatusOptions)) {
        if (inbox_wants_json()) inbox_json_error('Selecciona un status comercial valido.');
        $errors[] = 'Selecciona un status comercial valido.';
      } elseif (mb_strlen($changeReason) < 4) {
        if (inbox_wants_json()) inbox_json_error('Indica el motivo del cambio.');
        $errors[] = 'Indica el motivo del cambio.';
      } else {
        if (is_super_admin()) {
          $current = $pdo->prepare("SELECT sales_status FROM {$TABLE_LEADS} WHERE id=? LIMIT 1");
          $current->execute([$leadId]);
        } else {
          $current = $pdo->prepare("SELECT sales_status FROM {$TABLE_LEADS} WHERE id=? AND account_id=? LIMIT 1");
          $current->execute([$leadId, $currentAccountId]);
        }
        $previousStatus = (string) ($current->fetchColumn() ?: '');
        if ($previousStatus === '') {
          if (inbox_wants_json()) inbox_json_error('Lead no encontrado para esta cuenta.', 404);
          $errors[] = 'Lead no encontrado para esta cuenta.';
        } else {
          if (is_super_admin()) {
            $stmt = $pdo->prepare("UPDATE {$TABLE_LEADS} SET sales_status=?, updated_at=NOW() WHERE id=?");
            $stmt->execute([$salesStatus, $leadId]);
          } else {
            $stmt = $pdo->prepare("UPDATE {$TABLE_LEADS} SET sales_status=?, updated_at=NOW() WHERE id=? AND account_id=?");
            $stmt->execute([$salesStatus, $leadId, $currentAccountId]);
          }
          if ($previousStatus !== $salesStatus) {
            lead_status_history_record($pdo, $leadId, $previousStatus !== '' ? $previousStatus : null, $salesStatus, $changeReason);
          }
          if (inbox_wants_json()) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => true, 'sales_status' => $salesStatus, 'label' => (string) $salesStatusOptions[$salesStatus], 'notice' => 'Status comercial actualizado.'], JSON_UNESCAPED_UNICODE);
            exit;
          }
          header('Location: ' . account_url('inbox.php', ['id' => $conversationRouteId, 'notice' => 'Status comercial actualizado.']));
          exit;
        }
      }
    } elseif (inbox_wants_json()) {
      inbox_json_error('No tienes permiso o la accion no es valida.', 403);
    }
  }
}

$filterStatus = trim((string) ($_GET['status'] ?? ''));
if ($filterStatus !== '' && !array_key_exists($filterStatus, $statusOptions)) $filterStatus = '';
$q = trim((string) ($_GET['q'] ?? ''));
$selectedRouteId = max(0, (int) ($_GET['id'] ?? 0));
$selectedId = $selectedRouteId;
$accountOptions = [];
$filterAccountId = 0;
if (is_super_admin()) {
  try {
    $accountStmt = $pdo->query("SELECT id, name, slug FROM " . accounts_table() . " ORDER BY name ASC");
    $accountOptions = $accountStmt ? $accountStmt->fetchAll() : [];
  } catch (Throwable $e) {
    $accountOptions = [];
  }
  $accountIds = array_map(static fn($row) => (int) ($row['id'] ?? 0), $accountOptions);
  $filterAccountId = $requestAccountId > 0 ? $requestAccountId : max(0, (int) ($_GET['account_id'] ?? 0));
  if ($filterAccountId > 0 && !in_array($filterAccountId, $accountIds, true)) $filterAccountId = 0;
}
$publicLookupAccountId = $requestAccountId > 0 ? $requestAccountId : $filterAccountId;
if ($selectedRouteId > 0 && $publicLookupAccountId > 0) {
  $selectedId = conv_resolve_public_conversation_id($pdo, $publicLookupAccountId, $selectedRouteId);
}

$channelOptions = [];
try {
  $channelSql = <<<SQL
SELECT DISTINCT ch.id, ch.page_name, ch.page_id, ch.instagram_username
FROM {$conversationsTable} c
JOIN {$channelsTable} ch ON ch.id = c.channel_id
%s
ORDER BY COALESCE(ch.instagram_username, ch.page_name, ch.page_id) ASC
SQL;
  if (is_super_admin() && $filterAccountId > 0) {
    $channelStmt = $pdo->prepare(sprintf($channelSql, 'WHERE c.account_id = ?'));
    $channelStmt->execute([$filterAccountId]);
  } elseif (is_super_admin()) {
    $channelStmt = $pdo->query(sprintf($channelSql, ''));
  } else {
    $channelStmt = $pdo->prepare(sprintf($channelSql, 'WHERE c.account_id = ?'));
    $channelStmt->execute([$currentAccountId]);
  }
  $channelOptions = $channelStmt ? $channelStmt->fetchAll() : [];
} catch (Throwable $e) {
  $channelOptions = [];
}
$channelIds = array_map(static fn($row) => (int) ($row['id'] ?? 0), $channelOptions);
$filterChannelId = max(0, (int) ($_GET['channel_id'] ?? 0));
if ($filterChannelId > 0 && !in_array($filterChannelId, $channelIds, true)) $filterChannelId = 0;

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
  ch.page_name,
  ch.instagram_username AS channel_username,
  a.slug AS account_slug,
  l.fullname AS lead_fullname,
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
$conversations = $stmt->fetchAll();

if ($selectedId <= 0 && $conversations) {
  $selectedId = (int) $conversations[0]['id'];
  $selectedRouteId = conv_display_id($conversations[0]);
}

$selected = null;
if ($selectedId > 0) {
  $detailSql = <<<SQL
SELECT
  c.*,
  ct.external_contact_id AS contact_external_id,
  ct.display_name,
  ct.username,
  ct.profile_url,
  ch.page_name,
  ch.instagram_username AS channel_username,
  a.slug AS account_slug,
  l.fullname AS lead_fullname,
  l.sales_status AS lead_sales_status,
  l.notes AS lead_notes,
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
  elseif ($filterAccountId > 0) $accountDetailSql = 'AND c.account_id = ?';
  $detailStmt = $pdo->prepare(sprintf($detailSql, $accountDetailSql));
  $detailParams = [$selectedId];
  if (!is_super_admin()) $detailParams[] = $currentAccountId;
  elseif ($filterAccountId > 0) $detailParams[] = $filterAccountId;
  $detailStmt->execute($detailParams);
  $selected = $detailStmt->fetch() ?: null;
  if ($selected && empty($selected['lead_id'])) {
    conv_ensure_lead_for_conversation($pdo, $TABLE_LEADS, (int) $selected['id']);
    $detailStmt->execute($detailParams);
    $selected = $detailStmt->fetch() ?: null;
  }
  if ($selected) conv_mark_read($pdo, (int) $selected['id']);
}

$messages = [];
if ($selected) {
  $selectedRouteId = conv_display_id($selected);
  $msgStmt = $pdo->prepare("SELECT m.*, u.username AS sent_by_username FROM {$messagesTable} m LEFT JOIN {$TABLE_USERS} u ON u.id = m.sent_by WHERE m.conversation_id=? ORDER BY m.sent_at ASC, m.id ASC");
  $msgStmt->execute([(int) $selected['id']]);
  $messages = $msgStmt->fetchAll();
}
$selectedAccountId = $selected ? (int) ($selected['account_id'] ?? 0) : 0;
$attachmentsByMessage = conv_attachments_for_messages($pdo, array_map(static fn($message) => (int) ($message['id'] ?? 0), $messages));
$lastMessageId = 0;
foreach ($messages as $message) $lastMessageId = max($lastMessageId, (int) ($message['id'] ?? 0));
$replyWindow = $selected ? meta_reply_window_info($selected['last_inbound_at'] ?? '') : null;
$replyChannel = $selected ? conv_instagram_channel_for_conversation($pdo, $selected) : null;
$canReplyFromCrm = $canSendMessages && ($replyWindow['can_reply'] ?? true) && (bool) $replyChannel;
$channelUnavailableMessage = 'No hay un canal de Instagram activo disponible para esta conversación. Revisa Canales o reconecta Instagram antes de responder.';

function inbox_contact_name(array $conversation): string {
  $name = trim((string) ($conversation['display_name'] ?? ''));
  if ($name !== '') return $name;
  $username = trim((string) ($conversation['username'] ?? ''));
  if ($username !== '') return '@' . ltrim($username, '@');
  return 'Contacto de Instagram';
}

function inbox_short($value, int $max = 70): string {
  $value = trim((string) $value);
  if ($value === '') return '—';
  return mb_strlen($value) > $max ? mb_substr($value, 0, max(1, $max - 1)) . '…' : $value;
}

function inbox_time($value): string {
  return app_datetime($value);
}

function inbox_channel_label(array $channel): string {
  $username = trim((string) ($channel['instagram_username'] ?? ''));
  if ($username !== '') return '@' . ltrim($username, '@');
  $pageName = trim((string) ($channel['page_name'] ?? ''));
  if ($pageName !== '') return $pageName;
  return trim((string) ($channel['page_id'] ?? 'Canal de Instagram'));
}

function inbox_has_displayable_attachment(array $attachments): bool {
  foreach ($attachments as $attachment) {
    if (in_array(($attachment['media_type'] ?? ''), ['image', 'audio'], true) && !empty($attachment['url'])) return true;
  }
  return false;
}

function inbox_visible_message_text($value, array $attachments): string {
  $text = trim((string) $value);
  $hasAttachment = inbox_has_displayable_attachment($attachments);
  $attachmentOnlyLabels = ['Adjunto recibido: image', 'Adjunto recibido: audio', 'Imagen enviada', 'Audio enviado', 'Imagen', 'Audio'];
  if ($hasAttachment && in_array($text, $attachmentOnlyLabels, true)) return '';
  if ($text !== '') return $text;
  return $hasAttachment ? '' : 'Mensaje sin texto';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover" />
  <title>Inbox conversacional - Pixels Studio</title>
  <link rel="stylesheet" href="css/app.css?v=<?= (int) @filemtime(__DIR__ . '/css/app.css') ?>">
  <meta name="csrf" content="<?= h($_SESSION['csrf'] ?? '') ?>">
  <style>
    :root { --container-w:min(98vw, 1440px); --inbox-line:#d6ecf8; --inbox-soft:#eef9ff; --inbox-ink:#071120; --inbox-muted:#5d6d86; }
    .inbox-card { height:calc(100vh - (var(--dashboard-pad) * 2)); min-height:0; display:flex; }
    .inbox-card > .panel { width:100%; height:100%; display:flex; flex-direction:column; min-height:0; overflow:hidden; }
    .inbox-header { flex:0 0 auto; display:flex; justify-content:space-between; align-items:flex-start; gap:16px; flex-wrap:wrap; margin-bottom:14px; }
    .inbox-actions { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
    .inbox-link, .inbox-btn { appearance:none; display:inline-flex; align-items:center; justify-content:center; min-height:40px; padding:0 14px; border:1px solid var(--line); border-radius:10px; background:var(--surface-soft); color:#007ea8; font:inherit; font-weight:850; text-decoration:none; cursor:pointer; }
    .inbox-link.primary, .inbox-btn.primary { background:#071120; border-color:#071120; color:#eafaff; }
    .inbox-link:hover, .inbox-btn:hover { background:#dff6ff; border-color:#8bdfff; }
    .account-switch { display:inline-flex; align-items:center; height:40px; }
    .account-switch select { appearance:none; min-width:190px; height:40px; padding:0 34px 0 14px; border-radius:10px; border:1px solid var(--line); background:var(--surface-soft); color:#007ea8; font:inherit; font-size:.95rem; font-weight:850; cursor:pointer; }
    .account-switch select:hover, .account-switch select:focus { background:#dff6ff; border-color:#8bdfff; outline:none; }
    .menu-dropdown { position:relative; }
    .menu-trigger { appearance:none; display:inline-flex; align-items:center; justify-content:center; gap:8px; height:40px; padding:0 14px; border-radius:10px; border:1px solid var(--line); background:var(--surface-soft); color:#007ea8; font:inherit; font-size:.95rem; font-weight:850; cursor:pointer; user-select:none; transition:background .2s ease, border-color .2s ease, transform .06s ease; }
    .menu-trigger::after { content:"⌄"; color:#007ea8; font-size:.95rem; line-height:1; transform:translateY(-1px); }
    .menu-dropdown.is-open .menu-trigger, .menu-trigger:hover { background:#dff6ff; border-color:#8bdfff; }
    .menu-trigger:active { transform:translateY(1px); }
    .menu-panel { position:absolute; top:calc(100% + 8px); right:0; z-index:30; min-width:220px; padding:8px; border:1px solid var(--line); border-radius:14px; background:#fff; box-shadow:0 16px 36px rgba(0, 76, 110, .18); display:none; }
    .menu-dropdown.is-open .menu-panel { display:block; }
    .menu-item { width:100%; min-height:40px; display:flex; align-items:center; justify-content:flex-start; gap:8px; padding:0 10px; border:0; border-radius:10px; background:transparent; color:var(--brand-ink); font:inherit; font-weight:800; text-align:left; text-decoration:none; cursor:pointer; }
    .menu-item:hover { background:#eefaff; color:#007ea8; }
    .menu-meta { display:block; padding:6px 10px 9px; color:var(--brand-muted); font-size:.78rem; font-weight:850; border-bottom:1px solid rgba(0,68,99,.10); margin-bottom:6px; }
    .menu-form { margin:0; }
    #inboxNoticeArea { flex:0 0 auto; }
    .inbox-layout { flex:1 1 auto; display:grid; grid-template-columns:minmax(280px, 360px) minmax(0, 1fr) minmax(260px, 320px); gap:12px; min-height:0; overflow:hidden; }
    .inbox-panel { border:1px solid rgba(0,212,255,.16); border-radius:16px; background:#fff; overflow:hidden; box-shadow:0 8px 22px rgba(0, 76, 110, .06); }
    .inbox-layout > .inbox-panel { min-height:0; max-height:100%; }
    .inbox-layout > .inbox-panel:first-child, .inbox-layout > .inbox-panel:nth-child(2) { display:flex; flex-direction:column; }
    .conversation-filters { flex:0 0 auto; display:grid; grid-template-columns:1fr; gap:8px; padding:12px; border-bottom:1px solid var(--inbox-line); background:#fbfdff; }
    .conversation-filters input { width:100%; min-height:40px; border:1px solid var(--line); border-radius:10px; padding:0 10px; font:inherit; color:var(--inbox-ink); background:#fff; }
    .conversation-filters select, .status-form select { appearance:none; width:100%; height:var(--field-h); padding:0 15px; outline:none; border:1px solid var(--line); border-radius:var(--radius-sm); color:var(--brand-ink); background:var(--field-bg); box-shadow:inset 0 1px 0 rgba(51,10,12,.02); font:inherit; transition:border-color .18s, box-shadow .18s; cursor:pointer; }
    .conversation-filters select:focus, .status-form select:focus { border-color:var(--focus); box-shadow:0 0 0 3px rgba(0,212,255,.16); }
    .conversation-list { flex:1 1 auto; min-height:0; overflow:auto; }
    .conversation-item { display:block; padding:13px 14px; border-bottom:1px solid rgba(0,68,99,.10); color:inherit; text-decoration:none; background:#fff; }
    .conversation-item:hover, .conversation-item.is-active { background:#effaff; }
    .conversation-row { display:flex; justify-content:space-between; gap:8px; align-items:flex-start; }
    .conversation-name { font-weight:900; color:var(--inbox-ink); }
    .conversation-time { color:var(--inbox-muted); font-size:.78rem; white-space:nowrap; }
    .conversation-preview { color:var(--inbox-muted); margin-top:5px; font-size:.9rem; line-height:1.35; }
    .badge { display:inline-flex; align-items:center; min-height:24px; padding:0 8px; border-radius:999px; font-size:.74rem; font-weight:900; background:#eef9f0; color:#217a43; border:1px solid #a8e0ba; }
    .badge.unread { background:#071120; color:#eafaff; border-color:#071120; }
    .window-pill { display:inline-flex; align-items:center; min-height:24px; padding:0 8px; border-radius:999px; font-size:.72rem; font-weight:900; border:1px solid #bfe2c5; background:#eef9f0; color:#217a43; }
    .window-pill.warning { border-color:#f1d589; background:#fff8df; color:#8a5b00; }
    .window-pill.expired, .window-pill.unknown { border-color:#f1c2c6; background:#fff1f2; color:#85232a; }
    .chat-header { flex:0 0 auto; padding:16px; border-bottom:1px solid var(--inbox-line); display:flex; justify-content:space-between; gap:12px; align-items:flex-start; background:#fbfdff; }
    .chat-header h2 { margin:0; font-size:1.15rem; color:var(--inbox-ink); }
    .chat-header p { margin:4px 0 0; color:var(--inbox-muted); }
    .message-list { flex:1 1 auto; min-height:0; overflow:auto; padding:18px; background:linear-gradient(180deg,#f8fdff,#eef8ff); display:flex; flex-direction:column; gap:10px; }
    .reply-window-alert { margin:12px 16px 0; padding:12px 14px; border-radius:12px; border:1px solid #bfe2c5; background:#eef9f0; color:#184f2b; font-size:.9rem; line-height:1.35; font-weight:750; }
    .reply-window-alert[hidden] { display:none; }
    .reply-window-alert strong { display:block; margin-bottom:3px; color:inherit; }
    .reply-window-alert.warning { border-color:#f1d589; background:#fff8df; color:#8a5b00; }
    .reply-window-alert.expired, .reply-window-alert.unknown { border-color:#f1c2c6; background:#fff1f2; color:#85232a; }
    .message { max-width:min(78%, 620px); border:1px solid var(--inbox-line); border-radius:14px; padding:10px 12px; background:#fff; color:var(--inbox-ink); box-shadow:0 4px 14px rgba(0, 76, 110, .05); }
    .message.outbound { align-self:flex-end; background:#071120; border-color:#071120; color:#eafaff; }
    .message.inbound { align-self:flex-start; }
    .message.is-pending { opacity:.78; }
    .message.is-failed { background:#fff3f3; border-color:#f4a6a6; color:#7e1e1e; }
    .message-text { white-space:pre-wrap; overflow-wrap:anywhere; line-height:1.45; }
    .message-attachments { display:grid; gap:8px; margin-bottom:8px; }
    .message-image { display:block; max-width:min(280px, 100%); max-height:320px; border-radius:12px; border:1px solid rgba(0,68,99,.12); object-fit:cover; background:#fff; }
    .message-audio { display:block; width:min(320px, 100%); max-width:100%; }
    .message-meta { margin-top:6px; font-size:.72rem; opacity:.72; }
    .message-meta.error { color:#b83232; opacity:1; font-weight:900; }
    .reply-box { flex:0 0 auto; padding:10px 12px 8px; border-top:1px solid var(--inbox-line); background:#fff; }
    .reply-box.is-disabled { opacity:.72; background:#f6f9fc; }
    .reply-box.is-disabled textarea { background:#f1f5f9; color:#64748b; cursor:not-allowed; }
    .reply-box.is-disabled .icon-tool, .reply-box.is-disabled .emoji-btn, .reply-box.is-disabled .composer-submit { filter:grayscale(.25); cursor:not-allowed; }
    .reply-box.is-disabled .composer-file { pointer-events:none; }
    .composer-main { display:grid; grid-template-columns:minmax(0, 1fr) 44px 112px; gap:10px; align-items:stretch; }
    .composer-input { position:relative; }
    .reply-box textarea { width:100%; height:104px; min-height:104px; resize:vertical; border:1px solid var(--line); border-radius:12px; padding:10px 12px; font:inherit; outline:none; }
    .reply-box textarea:focus { border-color:var(--brand-primary); box-shadow:0 0 0 3px rgba(0,212,255,.16); }
    .reply-box.is-recording textarea { display:none; }
    .recording-surface { position:relative; height:104px; border:1px solid var(--line); border-radius:12px; overflow:hidden; background:linear-gradient(180deg,#f8fdff,#eef9ff); }
    .recording-surface[hidden] { display:none; }
    .recording-canvas { position:absolute; inset:0; width:100%; height:100%; }
    .recording-center { position:absolute; inset:0; display:grid; place-items:center; gap:10px; align-content:center; padding:16px; background:linear-gradient(90deg,rgba(248,253,255,.86),rgba(248,253,255,.48),rgba(248,253,255,.86)); }
    .recording-time { color:#a82b2b; font-weight:950; letter-spacing:.02em; }
    .recording-actions { display:flex; gap:10px; flex-wrap:wrap; justify-content:center; }
    .recording-action { min-height:38px; padding:0 14px; border-radius:10px; border:1px solid var(--line); font-weight:950; cursor:pointer; }
    .recording-action.send { background:#071120; border-color:#071120; color:#eafaff; }
    .recording-action.cancel { background:#fff; color:#a82b2b; border-color:#f4a6a6; }
    .composer-submit { width:100%; height:100%; min-height:104px; border-radius:12px; }
    .composer-quick-actions { display:grid; grid-template-rows:repeat(3, 1fr); gap:5px; min-height:104px; }
    .composer-tools { position:relative; display:flex; gap:8px; align-items:center; justify-content:space-between; margin-top:6px; flex-wrap:wrap; }
    .composer-left { display:flex; align-items:center; gap:10px; flex-wrap:wrap; color:var(--inbox-muted); font-size:.86rem; font-weight:750; }
    .composer-file { display:inline-flex; align-items:center; justify-content:center; }
    .composer-file input { position:absolute; width:1px; height:1px; opacity:0; pointer-events:none; }
    .icon-tool { display:inline-flex; align-items:center; justify-content:center; width:100%; height:100%; min-height:0; padding:0; border:1px solid var(--line); border-radius:10px; background:var(--surface-soft); color:#007ea8; font-size:1.05rem; font-weight:900; cursor:pointer; }
    .icon-tool:hover { background:#dff6ff; border-color:#8bdfff; }
    .file-name { max-width:220px; color:var(--inbox-muted); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .audio-recorder { display:inline-flex; align-items:center; gap:8px; flex-wrap:wrap; }
    .record-btn { background:#fff6f6; color:#a82b2b; }
    .record-btn:hover { background:#ffe7e7; border-color:#f4a6a6; }
    .record-btn.is-recording { background:#a82b2b; border-color:#a82b2b; color:#fff; }
    .record-status { color:var(--inbox-muted); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:220px; }
    .record-preview { width:min(260px, 100%); height:34px; }
    .record-preview[hidden] { display:none; }
    .enter-toggle { display:inline-flex; align-items:center; gap:7px; cursor:pointer; user-select:none; }
    .enter-toggle input { width:16px; height:16px; accent-color:#007ea8; }
    .emoji-wrap { position:relative; }
    .emoji-btn { width:100%; height:100%; min-height:0; padding:0; border-radius:10px; border:1px solid var(--line); background:var(--surface-soft); color:#007ea8; font-size:1.05rem; font-weight:900; cursor:pointer; }
    .emoji-btn:hover { background:#dff6ff; border-color:#8bdfff; }
    .emoji-panel { position:absolute; right:0; bottom:calc(100% + 8px); width:232px; display:none; grid-template-columns:repeat(6, 1fr); gap:6px; padding:10px; border:1px solid var(--line); border-radius:14px; background:#fff; box-shadow:0 14px 36px rgba(0, 76, 110, .18); z-index:5; }
    .emoji-panel.is-open { display:grid; }
    .emoji-option { width:30px; height:30px; border:1px solid transparent; border-radius:8px; background:#fff; cursor:pointer; font-size:1.05rem; }
    .emoji-option:hover { background:#eefaff; border-color:#8bdfff; }
    .reply-actions { display:flex; justify-content:space-between; gap:10px; align-items:center; margin-top:3px; flex-wrap:wrap; color:var(--inbox-muted); font-size:.88rem; }
    .reply-box.is-sending textarea, .reply-box.is-sending button { opacity:.7; pointer-events:none; }
    .live-status { color:var(--inbox-muted); font-size:.82rem; }
    .side-panel { padding:16px; display:grid; align-content:start; gap:14px; overflow:auto; }
    .side-panel h2 { margin:0; font-size:1.05rem; color:var(--inbox-ink); }
    .info-row { display:grid; gap:3px; color:var(--inbox-muted); font-size:.9rem; }
    .info-row strong { color:var(--inbox-ink); }
    .status-form { display:grid; gap:8px; }
    .status-save-hint { color:var(--inbox-muted); font-size:.78rem; font-weight:750; }
    .side-notes-field { display:grid; gap:7px; }
    .side-notes-field span { color:var(--inbox-ink); font-size:.9rem; font-weight:850; }
    .side-notes-input { width:100%; min-height:92px; resize:vertical; padding:10px 12px; border:1px solid var(--line); border-radius:12px; color:var(--brand-ink); background:#fff; outline:none; font:inherit; line-height:1.35; }
    .side-notes-input:focus { border-color:var(--brand-primary); box-shadow:0 0 0 3px rgba(0,212,255,.16); }
    .side-notes-input.is-saving { opacity:.65; cursor:progress; }
    .side-window-alert { display:grid; gap:4px; padding:12px; border-radius:12px; border:1px solid #f1d589; background:#fff8df; color:#8a5b00; font-size:.88rem; line-height:1.35; font-weight:750; }
    .side-window-alert[hidden] { display:none; }
    .side-window-alert strong { color:inherit; }
    .empty-state { display:grid; place-items:center; min-height:260px; text-align:center; color:var(--inbox-muted); padding:24px; }
    .message-list > .empty-state { flex:1; min-height:0; }
    .conversation-list > .empty-state { min-height:0; }
    .notice { margin-bottom:14px; }
    .modal-backdrop { position:fixed; inset:0; background:rgba(0,8,18,.72); display:none; align-items:center; justify-content:center; z-index:9999; }
    .modal-backdrop.is-open { display:flex; }
    .modal { width:min(92vw, 520px); border-radius:22px; border:1px solid rgba(255,255,255,.32); background:rgba(7,17,32,.96); backdrop-filter:blur(18px) saturate(120%); box-shadow:0 20px 60px rgba(0,0,0,.45); padding:24px; color:#eafaff; }
    .modal h2 { margin:0 0 10px; font-size:1.4rem; color:#fff; }
    .modal .subtitle { color:rgba(234,250,255,.86); }
    .modal .actions { display:flex; gap:10px; justify-content:flex-end; margin-top:14px; }
    .modal .field-label { color:#eafaff; }
    .modal input, .modal textarea { width:100%; background:rgba(255,255,255,.08); border:1px solid rgba(255,255,255,.22); border-radius:12px; color:#fff; padding:10px 12px; font:inherit; outline:none; }
    .modal textarea { min-height:110px; resize:vertical; line-height:1.4; }
    .modal input:focus, .modal textarea:focus { border-color:#8bdfff; box-shadow:0 0 0 3px rgba(0,212,255,.14); }
    .history-list { display:grid; gap:10px; margin-top:14px; max-height:360px; overflow:auto; }
    .history-item { border:1px solid rgba(255,255,255,.18); border-radius:14px; padding:12px; background:rgba(255,255,255,.06); }
    .history-item strong { display:block; color:#fff; margin-bottom:5px; }
    .history-item p { margin:0; color:rgba(234,250,255,.88); line-height:1.4; }
    .history-meta { margin-top:7px; color:rgba(234,250,255,.66); font-size:.82rem; font-weight:750; }
    .btn-secondary { appearance:none; border:1px solid rgba(255,255,255,.35); background:transparent; color:#eafaff; padding:10px 14px; border-radius:12px; cursor:pointer; }
    .btn-secondary:hover { background:rgba(255,255,255,.08); }
    @media (max-width: 1100px) { .inbox-layout { grid-template-columns:minmax(260px, 340px) minmax(0, 1fr); grid-template-rows:minmax(0, 1fr) auto; overflow:auto; } .side-panel { grid-column:1 / -1; max-height:none; } }
    @media (max-width: 760px) { .inbox-card { height:auto; min-height:calc(100vh - (var(--dashboard-pad) * 2)); } .inbox-card > .panel { height:auto; overflow:visible; } .inbox-layout { flex:0 0 auto; grid-template-columns:1fr; overflow:visible; } .conversation-list { flex:0 0 auto; max-height:300px; } .message-list { min-height:320px; max-height:52vh; padding:12px; } .message { max-width:92%; } .composer-main { grid-template-columns:minmax(0, 1fr) 44px; } .composer-submit { grid-column:1 / -1; min-height:46px; } }
    @media (max-width: 700px) { .inbox-actions { width:100%; } .menu-dropdown { flex:1; } .menu-trigger { width:100%; } .menu-panel { left:0; right:auto; width:min(92vw, 280px); } }
  </style>
</head>
<body class="dashboard-page">
  <main class="dashboard-shell">
    <section class="form-card dashboard-card inbox-card">
      <div class="panel">
        <header class="inbox-header">
          <div>
            <p class="eyebrow"><?= h(app_config('brand.name', 'Pixels Studio')) ?></p>
            <h1 class="title">Inbox conversacional</h1>
            <p class="subtitle">Gestiona conversaciones de Instagram y su avance comercial desde el CRM.</p>
          </div>
          <div class="inbox-actions">
            <?php if (is_super_admin()): ?>
              <label class="account-switch" aria-label="Cambiar cuenta">
                <select onchange="if (this.value) window.location.href = this.value">
                  <option value="<?= h(account_url('inbox.php', ['q' => $q, 'channel_id' => $filterChannelId > 0 ? $filterChannelId : null, 'status' => $filterStatus], '')) ?>" <?= $filterAccountId <= 0 ? 'selected' : '' ?>>Todas las cuentas</option>
                  <?php foreach ($accountOptions as $account): ?>
                    <?php $accountSlug = trim((string) ($account['slug'] ?? '')); ?>
                    <option value="<?= h(account_url('inbox.php', ['q' => $q, 'status' => $filterStatus], $accountSlug !== '' ? $accountSlug : null)) ?>" <?= $filterAccountId === (int) $account['id'] ? 'selected' : '' ?>><?= h((string) $account['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
            <?php endif; ?>
            <?php if ($canViewDashboard || $canManageIntegrations): ?>
              <div class="menu-dropdown" data-menu>
                <button class="menu-trigger" type="button" data-menu-trigger aria-expanded="false">Cambiar vista</button>
                <div class="menu-panel" role="menu">
                  <?php if ($canViewDashboard): ?><a class="menu-item" href="<?= h(account_url('dashboard.php')) ?>">Embudo comercial</a><?php endif; ?>
                  <?php if ($canManageIntegrations): ?><a class="menu-item" href="<?= h(account_url('channels.php')) ?>">Ver canales</a><?php endif; ?>
                  <?php if ($canManageIntegrations): ?><a class="menu-item" href="<?= h(account_url('webhook_logs.php')) ?>">Ver eventos</a><?php endif; ?>
                </div>
              </div>
            <?php endif; ?>
            <div class="menu-dropdown" data-menu>
              <button class="menu-trigger" type="button" data-menu-trigger aria-expanded="false"><?= h((string) ($_SESSION['username'] ?? 'Usuario')) ?></button>
              <div class="menu-panel" role="menu">
                <span class="menu-meta"><?= h($currentRoleLabel) ?></span>
                <button type="button" class="menu-item" data-modal-open="profileModal">Seguridad</button>
                <?php if (can('manage_accounts')): ?><a class="menu-item" href="/accounts.php">Gestión de cuentas</a><?php endif; ?>
                <?php if ($canManageUsers): ?><a class="menu-item" href="/users.php">Gestión de usuarios</a><?php endif; ?>
                <form class="menu-form" action="/logout.php" method="post">
                  <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf'] ?? '') ?>">
                  <button class="menu-item" type="submit">Cerrar sesión</button>
                </form>
              </div>
            </div>
          </div>
        </header>

        <div id="inboxNoticeArea">
          <?php if ($notice !== ''): ?><div class="form-alert alert-info notice"><?= h($notice) ?></div><?php endif; ?>
          <?php foreach ($errors as $error): ?><div class="form-alert alert-error notice"><?= h($error) ?></div><?php endforeach; ?>
        </div>

        <div class="inbox-layout">
          <aside class="inbox-panel" aria-label="Conversaciones">
            <form class="conversation-filters" method="get" action="inbox.php">
              <input type="text" name="q" value="<?= h($q) ?>" placeholder="Buscar conversación">
              <select name="channel_id" onchange="this.form.submit()" aria-label="Filtrar por canal">
                <option value="">Todos los canales</option>
                <?php foreach ($channelOptions as $channel): ?>
                  <option value="<?= (int) $channel['id'] ?>" <?= $filterChannelId === (int) $channel['id'] ? 'selected' : '' ?>><?= h(inbox_channel_label($channel)) ?></option>
                <?php endforeach; ?>
              </select>
              <select name="status" onchange="this.form.submit()" aria-label="Filtrar por estado">
                <option value="">Todos los estados</option>
                <?php foreach ($statusOptions as $value => $label): ?>
                  <option value="<?= h($value) ?>" <?= $filterStatus === (string) $value ? 'selected' : '' ?>><?= h($label) ?></option>
                <?php endforeach; ?>
              </select>
            </form>
            <div class="conversation-list" id="conversationList" data-selected-id="<?= (int) $selectedRouteId ?>">
              <?php if ($conversations): foreach ($conversations as $conversation): ?>
                <?php $isActive = $selected && (int) $selected['id'] === (int) $conversation['id']; ?>
                <?php $conversationSlug = trim((string) ($conversation['account_slug'] ?? $requestSlug)); ?>
                <a class="conversation-item <?= $isActive ? 'is-active' : '' ?>" href="<?= h(account_url('inbox.php', ['id' => conv_display_id($conversation), 'channel_id' => $filterChannelId > 0 ? $filterChannelId : null, 'status' => $filterStatus, 'q' => $q], $conversationSlug !== '' ? $conversationSlug : null)) ?>">
                  <div class="conversation-row">
                    <span class="conversation-name"><?= h(inbox_contact_name($conversation)) ?></span>
                    <span class="conversation-time"><?= h(inbox_time($conversation['last_message_at'] ?? $conversation['created_at'] ?? '')) ?></span>
                  </div>
                  <div class="conversation-row" style="margin-top:6px">
                    <span class="badge"><?= h($statusOptions[(string) ($conversation['status'] ?? '')] ?? 'Abierta') ?></span>
                    <?php $conversationWindow = meta_reply_window_info($conversation['last_inbound_at'] ?? ''); ?>
                    <?php if (($conversationWindow['status'] ?? '') !== 'active'): ?><span class="window-pill <?= h((string) ($conversationWindow['status'] ?? '')) ?>"><?= h((string) ($conversationWindow['label'] ?? 'Chat')) ?></span><?php endif; ?>
                    <?php if ((int) ($conversation['unread_count'] ?? 0) > 0): ?><span class="badge unread"><?= (int) $conversation['unread_count'] ?></span><?php endif; ?>
                  </div>
                  <div class="conversation-preview"><?= h(inbox_short($conversation['last_message_preview'] ?? '', 92)) ?></div>
                </a>
              <?php endforeach; else: ?>
                <div class="empty-state">Aun no hay conversaciones. Llegaran aqui cuando entre un nuevo DM de Instagram.</div>
              <?php endif; ?>
            </div>
          </aside>

          <section class="inbox-panel" aria-label="Chat">
            <?php if ($selected): ?>
              <?php
                $funnelSearch = trim((string) ($selected['username'] ?? ''));
                $funnelSearch = $funnelSearch !== '' ? ltrim($funnelSearch, '@') : inbox_contact_name($selected);
              ?>
              <header class="chat-header">
                <div>
                  <h2><?= h(inbox_contact_name($selected)) ?></h2>
                  <p><?= h((string) ($selected['channel_username'] ?: $selected['page_name'] ?: 'Instagram')) ?> · <span id="conversationStatusLabel"><?= h($statusOptions[(string) ($selected['status'] ?? '')] ?? 'Abierta') ?></span></p>
                </div>
                <a class="inbox-link" href="<?= h(account_url('dashboard.php', ['q' => $funnelSearch, 'account_id' => $filterAccountId > 0 && $requestSlug === '' ? $filterAccountId : null], $requestSlug !== '' ? $requestSlug : null)) ?>">Ver en embudo</a>
              </header>
              <?php $showChatWindowAlert = in_array((string) ($replyWindow['status'] ?? ''), ['expired', 'unknown'], true); ?>
              <?php if ($replyWindow): ?>
                <div class="reply-window-alert <?= h((string) ($replyWindow['status'] ?? 'unknown')) ?>" id="replyWindowAlert" <?= $showChatWindowAlert ? '' : 'hidden' ?>>
                  <strong><?= h((string) ($replyWindow['label'] ?? 'Chat vencido')) ?></strong>
                  <span><?= h((string) ($replyWindow['detail'] ?? '')) ?></span>
                </div>
              <?php endif; ?>
              <?php if ($canSendMessages && ($replyWindow['can_reply'] ?? true) && !$replyChannel): ?>
                <div class="reply-window-alert expired">
                  <strong>Canal no disponible</strong>
                  <span><?= h($channelUnavailableMessage) ?></span>
                </div>
              <?php endif; ?>

              <div class="message-list" id="messageList" data-last-id="<?= (int) $lastMessageId ?>">
                <?php if ($messages): foreach ($messages as $message): ?>
                  <?php $direction = (string) ($message['direction'] ?? 'inbound'); ?>
                  <article class="message <?= $direction === 'outbound' ? 'outbound' : 'inbound' ?>" data-message-id="<?= (int) $message['id'] ?>">
                    <?php $messageAttachments = $attachmentsByMessage[(int) $message['id']] ?? []; ?>
                    <?php $visibleText = inbox_visible_message_text($message['message_text'] ?? '', $messageAttachments); ?>
                    <?php if ($messageAttachments): ?>
                      <div class="message-attachments">
                        <?php foreach ($messageAttachments as $attachment): ?>
                          <?php if (($attachment['media_type'] ?? '') === 'image'): ?>
                            <a href="<?= h($attachment['url']) ?>" target="_blank" rel="noopener">
                              <img class="message-image" src="<?= h($attachment['url']) ?>" alt="<?= h((string) ($attachment['filename'] ?: 'Imagen adjunta')) ?>">
                            </a>
                          <?php elseif (($attachment['media_type'] ?? '') === 'audio'): ?>
                            <audio class="message-audio" controls preload="none" src="<?= h($attachment['url']) ?>"></audio>
                          <?php endif; ?>
                        <?php endforeach; ?>
                      </div>
                    <?php endif; ?>
                    <?php if ($visibleText !== ''): ?><div class="message-text"><?= h($visibleText) ?></div><?php endif; ?>
                    <div class="message-meta">
                      <?= $direction === 'outbound' ? 'Enviado' : 'Recibido' ?> · <?= h(inbox_time($message['sent_at'] ?? '')) ?>
                      <?php if ($direction === 'outbound' && !empty($message['sent_by_username'])): ?> · <?= h($message['sent_by_username']) ?><?php endif; ?>
                    </div>
                  </article>
                <?php endforeach; else: ?>
                  <div class="empty-state">Esta conversacion aun no tiene mensajes guardados.</div>
                <?php endif; ?>
              </div>

              <form class="reply-box <?= $canReplyFromCrm ? '' : 'is-disabled' ?>" id="replyForm" method="post" action="<?= h(account_url('send_instagram_message.php')) ?>" enctype="multipart/form-data">
                <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf'] ?? '') ?>">
                <input type="hidden" name="conversation_id" value="<?= (int) $selectedRouteId ?>">
                <?php if ($requestSlug === '' && ($filterAccountId > 0 || $selectedAccountId > 0)): ?><input type="hidden" name="account_id" value="<?= (int) ($filterAccountId > 0 ? $filterAccountId : $selectedAccountId) ?>"><?php endif; ?>
                <div class="composer-main">
                  <div class="composer-input">
                    <textarea name="message" maxlength="1000" placeholder="Escribe una respuesta para Instagram" <?= $canReplyFromCrm ? '' : 'disabled' ?>></textarea>
                    <div class="recording-surface" id="audioRecordingSurface" hidden>
                      <canvas class="recording-canvas" id="audioWaveCanvas" width="900" height="180" aria-hidden="true"></canvas>
                      <div class="recording-center">
                        <div class="recording-time" id="audioRecordingTime">Grabando 0s</div>
                        <div class="recording-actions">
                          <button class="recording-action send" type="button" id="audioQuickSendButton">Enviar audio</button>
                          <button class="recording-action cancel" type="button" id="audioCancelButton">Cancelar</button>
                        </div>
                      </div>
                    </div>
                  </div>
                  <div class="composer-quick-actions" aria-label="Acciones rápidas del mensaje">
                    <div class="emoji-wrap">
                      <button class="emoji-btn" type="button" id="emojiToggle" aria-label="Insertar emoji" aria-expanded="false" <?= $canReplyFromCrm ? '' : 'disabled' ?>>☺</button>
                      <div class="emoji-panel" id="emojiPanel" aria-label="Emojis rápidos">
                        <?php foreach (['😀','😁','😂','😊','😍','😎','🙌','👍','🙏','🔥','✨','✅','👀','💬','📌','📍','💰','🚀'] as $emoji): ?>
                          <button class="emoji-option" type="button" data-emoji="<?= h($emoji) ?>"><?= h($emoji) ?></button>
                        <?php endforeach; ?>
                      </div>
                    </div>
                    <button class="icon-tool record-btn" type="button" id="audioRecordButton" title="Grabar audio" aria-label="Grabar audio" <?= $canReplyFromCrm ? '' : 'disabled' ?>>🎙</button>
                    <label class="composer-file" title="Adjuntar imagen o audio" aria-label="Adjuntar imagen o audio">
                      <span class="icon-tool">📎</span>
                      <input type="file" name="media[]" id="mediaInput" accept="image/jpeg,image/png,image/gif,image/webp,audio/mpeg,audio/mp3,audio/mp4,audio/m4a,audio/x-m4a,audio/aac,audio/ogg,audio/wav,audio/x-wav,audio/webm,audio/3gpp" multiple <?= $canReplyFromCrm ? '' : 'disabled' ?>>
                    </label>
                  </div>
                  <button class="inbox-btn primary composer-submit" type="submit" <?= $canReplyFromCrm ? '' : 'disabled' ?>>Enviar</button>
                </div>
                <div class="composer-tools">
                  <div class="composer-left">
                    <span class="file-name" id="mediaFileName">Sin adjunto</span>
                    <span class="record-status" id="audioRecordStatus">Sin audio grabado</span>
                    <audio class="record-preview" id="audioRecordPreview" controls preload="metadata" hidden></audio>
                    <label class="enter-toggle">
                      <input type="checkbox" id="sendWithEnter" checked>
                      <span>Enviar con Intro</span>
                    </label>
                    <span>Shift + Intro crea salto de línea</span>
                  </div>
                </div>
                <div class="reply-actions">
                  <span class="live-status" id="liveStatus">Actualizando automaticamente.</span>
                </div>
              </form>
            <?php else: ?>
              <div class="empty-state">Selecciona una conversacion para ver el historial.</div>
            <?php endif; ?>
          </section>

          <aside class="inbox-panel side-panel" aria-label="Ficha de conversacion">
            <?php if ($selected): ?>
              <h2>Ficha conversacional</h2>
              <?php if ($replyWindow): ?>
                <div class="side-window-alert" id="sideReplyWindowAlert" <?= (string) ($replyWindow['status'] ?? '') === 'warning' ? '' : 'hidden' ?>>
                  <strong><?= h((string) ($replyWindow['label'] ?? 'Chat por vencer')) ?></strong>
                  <span><?= h((string) ($replyWindow['detail'] ?? '')) ?></span>
                </div>
              <?php endif; ?>
              <div class="info-row"><span>Contacto</span><strong><?= h(inbox_contact_name($selected)) ?></strong></div>
              <div class="info-row"><span>Instagram</span><strong><?= !empty($selected['username']) ? '<a href="' . h((string) ($selected['profile_url'] ?: ('https://instagram.com/' . ltrim((string) $selected['username'], '@')))) . '" target="_blank" rel="noopener">@' . h((string) $selected['username']) . '</a>' : '—' ?></strong></div>
              <div class="info-row"><span>Canal</span><strong><?= h((string) ($selected['channel_username'] ?: $selected['page_name'] ?: 'Instagram')) ?></strong></div>
              <div class="info-row"><span>Ultimo mensaje</span><strong><?= h(inbox_time($selected['last_message_at'] ?? '')) ?></strong></div>

              <?php if (!empty($selected['lead_id'])): ?>
              <label class="side-notes-field">
                <span>Anotaciones</span>
                <textarea class="side-notes-input" data-lead-notes data-lead-id="<?= (int) $selected['lead_id'] ?>" maxlength="2000" rows="4" placeholder="Agregar anotación..." <?= $canEditLeads ? '' : 'disabled' ?>><?= h((string) ($selected['lead_notes'] ?? '')) ?></textarea>
              </label>
              <form class="status-form" method="post" action="<?= h(account_url('inbox.php', ['id' => $selectedRouteId, 'account_id' => $filterAccountId > 0 && $requestSlug === '' ? $filterAccountId : null])) ?>" data-auto-status-form data-status-target="salesStatusLabel">
                <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf'] ?? '') ?>">
                <input type="hidden" name="action" value="update_sales_status">
                <input type="hidden" name="conversation_id" value="<?= (int) $selectedRouteId ?>">
                <?php if ($requestSlug === '' && ($filterAccountId > 0 || $selectedAccountId > 0)): ?><input type="hidden" name="account_id" value="<?= (int) ($filterAccountId > 0 ? $filterAccountId : $selectedAccountId) ?>"><?php endif; ?>
                <input type="hidden" name="lead_id" value="<?= (int) $selected['lead_id'] ?>">
                <label class="field">
                  <span class="field-label">Status comercial</span>
                  <select name="sales_status" <?= $canEditLeads ? '' : 'disabled' ?>>
                    <?php foreach ($salesStatusOptions as $value => $label): ?>
                      <option value="<?= h($value) ?>" <?= (string) ($selected['lead_sales_status'] ?? '') === (string) $value ? 'selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                  </select>
                </label>
              </form>
              <button class="inbox-link" type="button" data-history-open data-lead-id="<?= (int) $selected['lead_id'] ?>">Ver historial</button>
              <?php endif; ?>

              <form class="status-form" method="post" action="<?= h(account_url('inbox.php', ['id' => $selectedRouteId, 'account_id' => $filterAccountId > 0 && $requestSlug === '' ? $filterAccountId : null])) ?>" data-auto-status-form data-status-target="conversationStatusLabel">
                <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf'] ?? '') ?>">
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="conversation_id" value="<?= (int) $selectedRouteId ?>">
                <?php if ($requestSlug === '' && ($filterAccountId > 0 || $selectedAccountId > 0)): ?><input type="hidden" name="account_id" value="<?= (int) ($filterAccountId > 0 ? $filterAccountId : $selectedAccountId) ?>"><?php endif; ?>
                <label class="field">
                  <span class="field-label">Estado conversacional</span>
                  <select name="status" <?= $canManageConversations ? '' : 'disabled' ?>>
                    <?php foreach ($statusOptions as $value => $label): ?>
                      <option value="<?= h($value) ?>" <?= (string) ($selected['status'] ?? '') === (string) $value ? 'selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                  </select>
                </label>
              </form>
            <?php else: ?>
              <h2>Ficha conversacional</h2>
              <p class="subtitle">Cuando selecciones una conversacion veras aqui el contacto, canal, estado y lead asociado.</p>
            <?php endif; ?>
          </aside>
        </div>
      </div>
    </section>
  </main>
  <div id="profileModal" class="modal-backdrop" data-modal>
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="profileTitle">
      <h2 id="profileTitle">Actualizar contraseña</h2>
      <p class="subtitle">Usuario: <strong><?= h((string) ($_SESSION['username'] ?? 'Usuario')) ?></strong></p>
      <form id="profileForm" class="form" action="account_update.php" method="post" novalidate>
        <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf'] ?? '') ?>">
        <label class="field"><span class="field-label">Contraseña actual</span><input type="password" name="currentpass" minlength="8" required autocomplete="current-password"></label>
        <label class="field"><span class="field-label">Nueva contraseña</span><input type="password" name="newpass" minlength="8" required autocomplete="new-password"></label>
        <label class="field"><span class="field-label">Confirmar contraseña</span><input type="password" name="confirm" minlength="8" required autocomplete="new-password"></label>
        <div id="profileAlert" class="form-alert" aria-live="polite"></div>
        <div class="actions">
          <button type="button" class="btn-secondary" data-modal-close>Cancelar</button>
          <button type="submit" class="btn-secondary">Guardar</button>
        </div>
      </form>
    </div>
  </div>
  <script src="js/dashboard.js?v=<?= (int) @filemtime(__DIR__ . '/js/dashboard.js') ?>" defer></script>
  <script>
    const inboxState = {
      conversationId: <?= (int) $selectedRouteId ?>,
      accountId: <?= (int) $filterAccountId ?>,
      selectedAccountId: <?= (int) $selectedAccountId ?>,
      channelId: <?= (int) $filterChannelId ?>,
      q: <?= json_encode($q, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
      status: <?= json_encode($filterStatus, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
      lastMessageId: <?= (int) $lastMessageId ?>,
      csrf: <?= json_encode((string) ($_SESSION['csrf'] ?? ''), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
      canReply: <?= $canReplyFromCrm ? 'true' : 'false' ?>,
      channelAvailable: <?= $replyChannel ? 'true' : 'false' ?>,
      polling: false
    };

    const conversationList = document.getElementById('conversationList');
    const messageList = document.getElementById('messageList');
    const replyForm = document.getElementById('replyForm');
    const liveStatus = document.getElementById('liveStatus');
    const noticeArea = document.getElementById('inboxNoticeArea');
    const sendWithEnter = document.getElementById('sendWithEnter');
    const emojiToggle = document.getElementById('emojiToggle');
    const emojiPanel = document.getElementById('emojiPanel');
    const audioRecordButton = document.getElementById('audioRecordButton');
    const audioRecordStatus = document.getElementById('audioRecordStatus');
    const audioRecordPreview = document.getElementById('audioRecordPreview');
    const audioRecordingSurface = document.getElementById('audioRecordingSurface');
    const audioWaveCanvas = document.getElementById('audioWaveCanvas');
    const audioRecordingTime = document.getElementById('audioRecordingTime');
    const audioQuickSendButton = document.getElementById('audioQuickSendButton');
    const audioCancelButton = document.getElementById('audioCancelButton');
    const replyWindowAlert = document.getElementById('replyWindowAlert');
    const sideReplyWindowAlert = document.getElementById('sideReplyWindowAlert');

    function setComposerEnabled(canReply) {
      inboxState.canReply = Boolean(canReply) && Boolean(inboxState.channelAvailable);
      if (!replyForm) return;
      replyForm.classList.toggle('is-disabled', !inboxState.canReply);
      replyForm.querySelectorAll('textarea[name="message"], #mediaInput, #audioRecordButton, #emojiToggle, button[type="submit"]').forEach(el => {
        el.disabled = !inboxState.canReply;
      });
      if (!inboxState.canReply && emojiPanel && emojiToggle) {
        emojiPanel.classList.remove('is-open');
        emojiToggle.setAttribute('aria-expanded', 'false');
      }
    }

    function updateReplyWindow(windowInfo) {
      if (!windowInfo) return;
      setComposerEnabled(Boolean(windowInfo.can_reply));
      const status = String(windowInfo.status || 'unknown');
      if (replyWindowAlert) {
        const showChatAlert = ['expired', 'unknown'].includes(status);
        replyWindowAlert.hidden = !showChatAlert;
        replyWindowAlert.className = `reply-window-alert ${escapeHtml(status)}`;
        replyWindowAlert.innerHTML = `
          <strong>${escapeHtml(windowInfo.label || 'Chat vencido')}</strong>
          <span>${escapeHtml(windowInfo.detail || '')}</span>
        `;
      }
      if (sideReplyWindowAlert) {
        sideReplyWindowAlert.hidden = status !== 'warning';
        sideReplyWindowAlert.innerHTML = `
          <strong>${escapeHtml(windowInfo.label || 'Chat por vencer')}</strong>
          <span>${escapeHtml(windowInfo.detail || '')}</span>
        `;
      }
    }

    function escapeHtml(value) {
      return String(value ?? '').replace(/[&<>"']/g, char => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
      }[char]));
    }

    function showNotice(message, type = 'info') {
      if (!noticeArea || !message) return;
      noticeArea.innerHTML = `<div class="form-alert ${type === 'error' ? 'alert-error' : 'alert-info'} notice">${escapeHtml(message)}</div>`;
      window.setTimeout(() => { if (noticeArea) noticeArea.innerHTML = ''; }, 4200);
    }

    function ensureLeadWorkflowModal() {
      let modal = document.getElementById('leadWorkflowModal');
      if (modal) return modal;
      modal = document.createElement('div');
      modal.id = 'leadWorkflowModal';
      modal.className = 'modal-backdrop';
      modal.setAttribute('data-modal', '');
      modal.innerHTML = `
        <div class="modal" role="dialog" aria-modal="true" aria-labelledby="leadWorkflowTitle">
          <h2 id="leadWorkflowTitle"></h2>
          <p class="subtitle" id="leadWorkflowSubtitle"></p>
          <div id="leadWorkflowBody"></div>
        </div>
      `;
      modal.addEventListener('click', event => {
        if (event.target === modal) modal.classList.remove('is-open');
      });
      document.body.appendChild(modal);
      return modal;
    }

    function requestStatusChangeReason(previousLabel, nextLabel) {
      return new Promise(resolve => {
        const modal = ensureLeadWorkflowModal();
        const title = modal.querySelector('#leadWorkflowTitle');
        const subtitle = modal.querySelector('#leadWorkflowSubtitle');
        const body = modal.querySelector('#leadWorkflowBody');
        title.textContent = 'Motivo del cambio';
        subtitle.textContent = `${previousLabel || 'Status actual'} → ${nextLabel || 'Nuevo status'}`;
        body.innerHTML = `
          <label class="field">
            <span class="field-label">Motivo del cambio</span>
            <textarea id="statusChangeReason" maxlength="1000" placeholder="Ej: Cliente solicitó presupuesto, se validó interés o no respondió al seguimiento."></textarea>
          </label>
          <div class="form-alert alert-error" id="statusChangeError" style="display:none"></div>
          <div class="actions">
            <button type="button" class="btn-secondary" data-reason-cancel>Cancelar</button>
            <button type="button" class="btn-secondary" data-reason-save>Guardar cambio</button>
          </div>
        `;
        const textarea = body.querySelector('#statusChangeReason');
        const error = body.querySelector('#statusChangeError');
        const finish = value => {
          modal.classList.remove('is-open');
          resolve(value);
        };
        body.querySelector('[data-reason-cancel]').addEventListener('click', () => finish(null), { once: true });
        body.querySelector('[data-reason-save]').addEventListener('click', () => {
          const reason = textarea.value.trim();
          if (reason.length < 4) {
            error.textContent = 'Indica el motivo del cambio.';
            error.style.display = 'block';
            textarea.focus();
            return;
          }
          finish(reason);
        });
        modal.classList.add('is-open');
        window.setTimeout(() => textarea.focus(), 30);
      });
    }

    async function openLeadHistory(leadId) {
      const modal = ensureLeadWorkflowModal();
      const title = modal.querySelector('#leadWorkflowTitle');
      const subtitle = modal.querySelector('#leadWorkflowSubtitle');
      const body = modal.querySelector('#leadWorkflowBody');
      title.textContent = 'Historial de cambios';
      subtitle.textContent = 'Bitácora comercial del lead.';
      body.innerHTML = '<p class="subtitle">Cargando historial...</p>';
      modal.classList.add('is-open');

      try {
        const response = await fetch(`lead_status_history.php?lead_id=${encodeURIComponent(leadId)}`, {
          headers: { 'Accept': 'application/json', 'X-Requested-With': 'fetch' },
          cache: 'no-store'
        });
        const data = await response.json().catch(() => ({ ok: false }));
        if (!response.ok || !data.ok) throw new Error(data.error || 'No se pudo cargar el historial.');
        const items = Array.isArray(data.items) ? data.items : [];
        body.innerHTML = items.length ? `
          <div class="history-list">
            ${items.map(item => `
              <article class="history-item">
                <strong>${escapeHtml(item.previous_label)} → ${escapeHtml(item.new_label)}</strong>
                <p>${escapeHtml(item.reason)}</p>
                <div class="history-meta">${escapeHtml(item.username || 'Sistema')} · ${escapeHtml(item.created_at || '')}</div>
              </article>
            `).join('')}
          </div>
        ` : '<p class="subtitle">Este lead aún no tiene cambios de status registrados.</p>';
      } catch (error) {
        body.innerHTML = `<div class="form-alert alert-error" style="display:block">${escapeHtml(error.message || 'No se pudo cargar el historial.')}</div>`;
      }
    }

    function attachmentMarkup(attachments) {
      if (!Array.isArray(attachments) || !attachments.length) return '';
      const items = attachments.map(attachment => {
        if (!attachment.url) return '';
        if (attachment.media_type === 'image') {
          const alt = attachment.filename || 'Imagen adjunta';
          return `
            <a href="${escapeHtml(attachment.url)}" target="_blank" rel="noopener">
              <img class="message-image" src="${escapeHtml(attachment.url)}" alt="${escapeHtml(alt)}">
            </a>
          `;
        }
        if (attachment.media_type === 'audio') {
          return `<audio class="message-audio" controls preload="none" src="${escapeHtml(attachment.url)}"></audio>`;
        }
        return '';
      }).join('');
      return items ? `<div class="message-attachments">${items}</div>` : '';
    }

    function hasDisplayableAttachment(attachments) {
      return Array.isArray(attachments) && attachments.some(attachment => attachment && ['image', 'audio'].includes(attachment.media_type) && attachment.url);
    }

    function visibleMessageText(message) {
      const text = String(message?.text ?? '').trim();
      const attachmentOnlyLabels = ['Adjunto recibido: image', 'Adjunto recibido: audio', 'Imagen enviada', 'Audio enviado', 'Imagen', 'Audio'];
      if (hasDisplayableAttachment(message?.attachments) && attachmentOnlyLabels.includes(text)) return '';
      if (text) return text;
      return hasDisplayableAttachment(message?.attachments) ? '' : 'Mensaje sin texto';
    }

    function insertAtCursor(textarea, value) {
      if (!textarea || !value) return;
      const start = textarea.selectionStart ?? textarea.value.length;
      const end = textarea.selectionEnd ?? textarea.value.length;
      textarea.value = textarea.value.slice(0, start) + value + textarea.value.slice(end);
      const next = start + value.length;
      textarea.focus();
      textarea.setSelectionRange(next, next);
    }

    function isNearBottom(el) {
      if (!el) return false;
      return el.scrollHeight - el.scrollTop - el.clientHeight < 120;
    }

    function scrollMessagesToBottom() {
      if (messageList) messageList.scrollTop = messageList.scrollHeight;
    }

    function scrollMessagesToBottomSoon() {
      scrollMessagesToBottom();
      window.requestAnimationFrame(scrollMessagesToBottom);
      window.setTimeout(scrollMessagesToBottom, 80);
      window.setTimeout(scrollMessagesToBottom, 350);
    }

    function bindMessageMediaScroll(container) {
      if (!container) return;
      container.querySelectorAll('img, audio, video').forEach(media => {
        media.addEventListener('load', scrollMessagesToBottomSoon, { once: true });
        media.addEventListener('loadedmetadata', scrollMessagesToBottomSoon, { once: true });
      });
    }

    function setRecordButtonState(isRecording) {
      if (!audioRecordButton) return;
      audioRecordButton.textContent = isRecording ? '■' : '🎙';
      audioRecordButton.title = isRecording ? 'Detener audio' : 'Grabar audio';
      audioRecordButton.setAttribute('aria-label', audioRecordButton.title);
      audioRecordButton.classList.toggle('is-recording', isRecording);
    }

    function bestAudioMimeType() {
      if (!window.MediaRecorder || !MediaRecorder.isTypeSupported) return '';
      const preferred = ['audio/mp4', 'audio/webm;codecs=opus', 'audio/webm', 'audio/ogg;codecs=opus', 'audio/ogg'];
      return preferred.find(type => MediaRecorder.isTypeSupported(type)) || '';
    }

    function audioExtensionFromMime(type) {
      const mime = String(type || '').split(';')[0].toLowerCase();
      if (mime === 'audio/mp4' || mime === 'audio/m4a' || mime === 'audio/x-m4a') return 'm4a';
      if (mime === 'audio/ogg') return 'ogg';
      if (mime === 'audio/wav' || mime === 'audio/x-wav') return 'wav';
      if (mime === 'audio/mpeg' || mime === 'audio/mp3') return 'mp3';
      return 'webm';
    }

    function conversationHref(id, slug = '') {
      const params = new URLSearchParams();
      params.set('id', String(id));
      if (inboxState.channelId) params.set('channel_id', String(inboxState.channelId));
      if (inboxState.status) params.set('status', inboxState.status);
      if (inboxState.q) params.set('q', inboxState.q);
      const cleanSlug = String(slug || '').trim();
      return `${cleanSlug ? `/${encodeURIComponent(cleanSlug)}/` : ''}inbox.php?${params.toString()}`;
    }

    function renderConversations(items) {
      if (!conversationList || !Array.isArray(items)) return;
      if (!items.length) {
        conversationList.innerHTML = '<div class="empty-state">Aun no hay conversaciones. Llegaran aqui cuando entre un nuevo DM de Instagram.</div>';
        return;
      }
      conversationList.innerHTML = items.map(item => {
        const active = Number(item.id) === Number(inboxState.conversationId);
        const unread = active ? 0 : Number(item.unread_count || 0);
        return `
          <a class="conversation-item ${active ? 'is-active' : ''}" href="${escapeHtml(conversationHref(item.id, item.account_slug || ''))}">
            <div class="conversation-row">
              <span class="conversation-name">${escapeHtml(item.name)}</span>
              <span class="conversation-time">${escapeHtml(item.time)}</span>
            </div>
            <div class="conversation-row" style="margin-top:6px">
              <span class="badge">${escapeHtml(item.status_label)}</span>
              ${item.reply_window && item.reply_window.status !== 'active' ? `<span class="window-pill ${escapeHtml(item.reply_window.status || 'unknown')}">${escapeHtml(item.reply_window.label || 'Chat')}</span>` : ''}
              ${unread > 0 ? `<span class="badge unread">${unread}</span>` : ''}
            </div>
            <div class="conversation-preview">${escapeHtml(item.preview)}</div>
          </a>
        `;
      }).join('');
    }

    function appendMessage(message) {
      if (!messageList || !message || !message.id) return;
      if (messageList.querySelector(`[data-message-id="${Number(message.id)}"]`)) return;
      const emptyState = messageList.querySelector('.empty-state');
      if (emptyState) emptyState.remove();
      const direction = message.direction === 'outbound' ? 'outbound' : 'inbound';
      const metaLabel = direction === 'outbound' ? 'Enviado' : 'Recibido';
      const sentBy = direction === 'outbound' && message.sent_by_username ? ` · ${escapeHtml(message.sent_by_username)}` : '';
      const article = document.createElement('article');
      article.className = `message ${direction}`;
      article.dataset.messageId = String(message.id);
      const visibleText = visibleMessageText(message);
      article.innerHTML = `
        ${attachmentMarkup(message.attachments)}
        ${visibleText ? `<div class="message-text">${escapeHtml(visibleText)}</div>` : ''}
        <div class="message-meta">${metaLabel} · ${escapeHtml(message.time)}${sentBy}</div>
      `;
      messageList.appendChild(article);
      bindMessageMediaScroll(article);
      inboxState.lastMessageId = Math.max(inboxState.lastMessageId, Number(message.id));
      messageList.dataset.lastId = String(inboxState.lastMessageId);
    }

    function appendOptimisticMessage(message) {
      if (!messageList || !message || !message.id) return null;
      const emptyState = messageList.querySelector('.empty-state');
      if (emptyState) emptyState.remove();
      const article = document.createElement('article');
      article.className = 'message outbound is-pending';
      article.dataset.messageId = String(message.id);
      article.dataset.optimistic = '1';
      const visibleText = visibleMessageText(message);
      article.innerHTML = `
        ${attachmentMarkup(message.attachments)}
        ${visibleText ? `<div class="message-text">${escapeHtml(visibleText)}</div>` : ''}
        <div class="message-meta">Enviando...</div>
      `;
      messageList.appendChild(article);
      bindMessageMediaScroll(article);
      scrollMessagesToBottomSoon();
      return article;
    }

    function messageNodeById(id) {
      if (!messageList) return null;
      return Array.from(messageList.querySelectorAll('[data-message-id]')).find(node => node.dataset.messageId === String(id)) || null;
    }

    function replaceOptimisticMessage(tempId, message) {
      if (!messageList || !tempId || !message || !message.id) return false;
      const tempNode = messageNodeById(tempId);
      if (!tempNode) {
        appendMessage(message);
        return false;
      }
      const existingRealNode = messageNodeById(Number(message.id));
      if (existingRealNode) {
        tempNode.remove();
        inboxState.lastMessageId = Math.max(inboxState.lastMessageId, Number(message.id || 0));
        messageList.dataset.lastId = String(inboxState.lastMessageId);
        return true;
      }
      const wrapper = document.createElement('div');
      wrapper.innerHTML = messageMarkup(message).trim();
      const realNode = wrapper.firstElementChild;
      if (!realNode) return false;
      tempNode.replaceWith(realNode);
      inboxState.lastMessageId = Math.max(inboxState.lastMessageId, Number(message.id || 0));
      messageList.dataset.lastId = String(inboxState.lastMessageId);
      return true;
    }

    function markOptimisticFailed(tempIds, errorMessage) {
      if (!messageList) return;
      tempIds.forEach(tempId => {
        const node = messageNodeById(tempId);
        if (!node) return;
        node.classList.remove('is-pending');
        node.classList.add('is-failed');
        const meta = node.querySelector('.message-meta');
        if (meta) {
          meta.classList.add('error');
          meta.textContent = errorMessage || 'Error al enviar';
        }
      });
    }

    function messageMarkup(message) {
      const direction = message.direction === 'outbound' ? 'outbound' : 'inbound';
      const metaLabel = direction === 'outbound' ? 'Enviado' : 'Recibido';
      const sentBy = direction === 'outbound' && message.sent_by_username ? ` · ${escapeHtml(message.sent_by_username)}` : '';
      const visibleText = visibleMessageText(message);
      return `
        <article class="message ${direction}" data-message-id="${Number(message.id)}">
          ${attachmentMarkup(message.attachments)}
          ${visibleText ? `<div class="message-text">${escapeHtml(visibleText)}</div>` : ''}
          <div class="message-meta">${metaLabel} · ${escapeHtml(message.time)}${sentBy}</div>
        </article>
      `;
    }

    function renderMessages(messages) {
      if (!messageList || !Array.isArray(messages)) return;
      if (!messages.length) {
        messageList.innerHTML = '<div class="empty-state">Esta conversacion aun no tiene mensajes guardados.</div>';
        inboxState.lastMessageId = 0;
        messageList.dataset.lastId = '0';
        return;
      }
      messageList.innerHTML = messages.map(messageMarkup).join('');
      inboxState.lastMessageId = messages.reduce((max, message) => Math.max(max, Number(message.id || 0)), 0);
      messageList.dataset.lastId = String(inboxState.lastMessageId);
    }

    function hasActiveMediaPlayback() {
      if (!messageList) return false;
      return Array.from(messageList.querySelectorAll('audio, video')).some(media => {
        return !media.paused && !media.ended && media.currentTime > 0;
      });
    }

    function syncMessages(messages) {
      if (!messageList || !Array.isArray(messages)) return;
      if (!messages.length) {
        if (!messageList.querySelector('.empty-state')) renderMessages(messages);
        return;
      }

      const incomingIds = messages.map(message => Number(message.id || 0)).filter(Boolean);
      const currentIds = Array.from(messageList.querySelectorAll('[data-message-id]')).map(node => Number(node.dataset.messageId || 0)).filter(Boolean);
      const hasSameCount = incomingIds.length === currentIds.length;
      const hasSameOrder = hasSameCount && incomingIds.every((id, index) => id === currentIds[index]);
      const lastIncomingId = incomingIds.reduce((max, id) => Math.max(max, id), 0);

      if (hasSameOrder) {
        inboxState.lastMessageId = Math.max(inboxState.lastMessageId, lastIncomingId);
        messageList.dataset.lastId = String(inboxState.lastMessageId);
        return;
      }

      const onlyNewAtEnd = currentIds.length > 0
        && incomingIds.length >= currentIds.length
        && currentIds.every((id, index) => id === incomingIds[index]);

      if (onlyNewAtEnd) {
        messages.slice(currentIds.length).forEach(appendMessage);
        return;
      }

      if (hasActiveMediaPlayback()) return;
      renderMessages(messages);
    }

    async function pollInbox(force = false) {
      if (inboxState.polling && !force) return;
      inboxState.polling = true;
      try {
        const params = new URLSearchParams();
        if (inboxState.conversationId) params.set('id', String(inboxState.conversationId));
        if (inboxState.selectedAccountId) params.set('selected_account_id', String(inboxState.selectedAccountId));
        if (inboxState.accountId) params.set('account_id', String(inboxState.accountId));
        if (inboxState.channelId) params.set('channel_id', String(inboxState.channelId));
        if (inboxState.q) params.set('q', inboxState.q);
        if (inboxState.status) params.set('status', inboxState.status);
        const response = await fetch(`inbox_updates.php?${params.toString()}`, {
          headers: { 'Accept': 'application/json' },
          cache: 'no-store'
        });
        const data = await response.json();
        if (!data.ok) throw new Error(data.error || 'No se pudieron cargar actualizaciones.');
        renderConversations(data.conversations || []);
        updateReplyWindow(data.reply_window || null);
        const shouldStick = isNearBottom(messageList);
        syncMessages(data.messages || []);
        if (shouldStick) scrollMessagesToBottom();
        if (liveStatus) liveStatus.textContent = 'Actualizado automaticamente.';
      } catch (error) {
        if (liveStatus) liveStatus.textContent = 'Reintentando actualizacion...';
      } finally {
        inboxState.polling = false;
      }
    }

    if (replyForm) {
      const textarea = replyForm.querySelector('textarea[name="message"]');
      const mediaInput = document.getElementById('mediaInput');
      const mediaFileName = document.getElementById('mediaFileName');
      let mediaRecorder = null;
      let recordingStream = null;
      let recordingChunks = [];
      let recordedAudioBlob = null;
      let recordedAudioUrl = '';
      let recordingTimer = null;
      let recordingStartedAt = 0;
      let audioContext = null;
      let audioAnalyser = null;
      let audioWaveAnimation = 0;
      let sendAfterRecordingStops = false;
      let recordingCancelled = false;

      function clearRecordedAudio() {
        recordedAudioBlob = null;
        if (recordedAudioUrl) URL.revokeObjectURL(recordedAudioUrl);
        recordedAudioUrl = '';
        if (audioRecordPreview) {
          audioRecordPreview.removeAttribute('src');
          audioRecordPreview.hidden = true;
        }
        if (audioRecordStatus) audioRecordStatus.textContent = 'Sin audio grabado';
      }

      function setRecordingSurface(active) {
        replyForm.classList.toggle('is-recording', Boolean(active));
        if (audioRecordingSurface) audioRecordingSurface.hidden = !active;
      }

      function stopRecordingTracks() {
        if (!recordingStream) return;
        recordingStream.getTracks().forEach(track => track.stop());
        recordingStream = null;
      }

      function stopRecordingTimer() {
        if (recordingTimer) window.clearInterval(recordingTimer);
        recordingTimer = null;
      }

      function stopAudioWave() {
        if (audioWaveAnimation) window.cancelAnimationFrame(audioWaveAnimation);
        audioWaveAnimation = 0;
        audioAnalyser = null;
        if (audioContext) {
          audioContext.close().catch(() => {});
          audioContext = null;
        }
      }

      function drawIdleWave() {
        if (!audioWaveCanvas) return;
        const ctx = audioWaveCanvas.getContext('2d');
        if (!ctx) return;
        const { width, height } = audioWaveCanvas;
        ctx.clearRect(0, 0, width, height);
        ctx.fillStyle = 'rgba(0,126,168,.14)';
        const bars = 42;
        const gap = 6;
        const barWidth = Math.max(4, (width - gap * (bars - 1)) / bars);
        for (let i = 0; i < bars; i++) {
          const ratio = Math.sin(i * .55) * .5 + .5;
          const barHeight = 18 + ratio * 56;
          const x = i * (barWidth + gap);
          const y = (height - barHeight) / 2;
          ctx.fillRect(x, y, barWidth, barHeight);
        }
      }

      function startAudioWave(stream) {
        if (!audioWaveCanvas || !window.AudioContext && !window.webkitAudioContext) return;
        const AudioContextCtor = window.AudioContext || window.webkitAudioContext;
        audioContext = new AudioContextCtor();
        audioAnalyser = audioContext.createAnalyser();
        audioAnalyser.fftSize = 256;
        const source = audioContext.createMediaStreamSource(stream);
        source.connect(audioAnalyser);
        const data = new Uint8Array(audioAnalyser.frequencyBinCount);
        const ctx = audioWaveCanvas.getContext('2d');
        if (!ctx) return;

        const draw = () => {
          if (!audioAnalyser) return;
          audioAnalyser.getByteFrequencyData(data);
          const { width, height } = audioWaveCanvas;
          ctx.clearRect(0, 0, width, height);
          const bars = 48;
          const gap = 5;
          const barWidth = Math.max(4, (width - gap * (bars - 1)) / bars);
          for (let i = 0; i < bars; i++) {
            const value = data[Math.floor(i * data.length / bars)] || 0;
            const normalized = Math.max(.08, value / 255);
            const barHeight = normalized * (height * .72);
            const x = i * (barWidth + gap);
            const y = (height - barHeight) / 2;
            const gradient = ctx.createLinearGradient(0, y, 0, y + barHeight);
            gradient.addColorStop(0, 'rgba(0,212,255,.95)');
            gradient.addColorStop(1, 'rgba(7,17,32,.82)');
            ctx.fillStyle = gradient;
            ctx.fillRect(x, y, barWidth, barHeight);
          }
          audioWaveAnimation = window.requestAnimationFrame(draw);
        };
        draw();
      }

      function updateRecordingStatus() {
        if (!audioRecordStatus || !recordingStartedAt) return;
        const elapsed = Math.max(0, Math.floor((Date.now() - recordingStartedAt) / 1000));
        audioRecordStatus.textContent = `Grabando ${elapsed}s`;
        if (audioRecordingTime) audioRecordingTime.textContent = `Grabando ${elapsed}s`;
      }

      async function startAudioRecording() {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia || !window.MediaRecorder) {
          showNotice('Tu navegador no permite grabar audio desde esta pantalla.', 'error');
          return;
        }
        clearRecordedAudio();
        if (mediaInput) mediaInput.value = '';
        if (mediaFileName) mediaFileName.textContent = 'Sin adjunto';
        sendAfterRecordingStops = false;
        recordingCancelled = false;
        try {
          recordingStream = await navigator.mediaDevices.getUserMedia({ audio: true });
          recordingChunks = [];
          const mimeType = bestAudioMimeType();
          mediaRecorder = mimeType ? new MediaRecorder(recordingStream, { mimeType }) : new MediaRecorder(recordingStream);
          mediaRecorder.addEventListener('dataavailable', event => {
            if (event.data && event.data.size > 0) recordingChunks.push(event.data);
          });
          mediaRecorder.addEventListener('stop', () => {
            stopRecordingTimer();
            stopRecordingTracks();
            if (recordingCancelled) {
              recordingCancelled = false;
              recordingChunks = [];
              setRecordingSurface(false);
              return;
            }
            const type = mediaRecorder && mediaRecorder.mimeType ? mediaRecorder.mimeType : (mimeType || 'audio/webm');
            recordedAudioBlob = recordingChunks.length ? new Blob(recordingChunks, { type }) : null;
            recordingChunks = [];
            if (!recordedAudioBlob || recordedAudioBlob.size <= 0) {
              clearRecordedAudio();
              setRecordingSurface(false);
              showNotice('No se pudo guardar la grabacion.', 'error');
              return;
            }
            recordedAudioUrl = URL.createObjectURL(recordedAudioBlob);
            if (audioRecordPreview) {
              audioRecordPreview.src = recordedAudioUrl;
              audioRecordPreview.hidden = false;
            }
            if (audioRecordStatus) audioRecordStatus.textContent = 'Audio listo para enviar';
            setRecordingSurface(false);
            drawIdleWave();
            if (sendAfterRecordingStops) {
              sendAfterRecordingStops = false;
              if (typeof replyForm.requestSubmit === 'function') replyForm.requestSubmit();
              else replyForm.dispatchEvent(new Event('submit', { cancelable: true }));
            }
          });
          setRecordingSurface(true);
          drawIdleWave();
          startAudioWave(recordingStream);
          mediaRecorder.start();
          recordingStartedAt = Date.now();
          updateRecordingStatus();
          recordingTimer = window.setInterval(updateRecordingStatus, 500);
          setRecordButtonState(true);
        } catch (error) {
          stopRecordingTimer();
          stopAudioWave();
          stopRecordingTracks();
          setRecordingSurface(false);
          setRecordButtonState(false);
          showNotice('No se pudo acceder al microfono.', 'error');
        }
      }

      function stopAudioRecording() {
        if (mediaRecorder && mediaRecorder.state === 'recording') mediaRecorder.stop();
        stopAudioWave();
        setRecordButtonState(false);
        recordingStartedAt = 0;
      }

      function cancelAudioRecording() {
        sendAfterRecordingStops = false;
        recordingCancelled = true;
        recordingChunks = [];
        if (mediaRecorder && mediaRecorder.state === 'recording') {
          mediaRecorder.stop();
        }
        stopRecordingTimer();
        stopAudioWave();
        stopRecordingTracks();
        setRecordingSurface(false);
        clearRecordedAudio();
        setRecordButtonState(false);
        recordingStartedAt = 0;
      }
      if (sendWithEnter) {
        const stored = window.localStorage.getItem('pixels_send_with_enter');
        sendWithEnter.checked = stored === null ? true : stored === '1';
        sendWithEnter.addEventListener('change', () => {
          window.localStorage.setItem('pixels_send_with_enter', sendWithEnter.checked ? '1' : '0');
        });
      }
      if (textarea) {
        textarea.addEventListener('keydown', (event) => {
          if (event.key !== 'Enter' || event.shiftKey || event.ctrlKey || event.metaKey || event.altKey) return;
          if (!sendWithEnter || !sendWithEnter.checked) return;
          event.preventDefault();
          if (typeof replyForm.requestSubmit === 'function') replyForm.requestSubmit();
          else replyForm.dispatchEvent(new Event('submit', { cancelable: true }));
        });
      }
      if (emojiToggle && emojiPanel && textarea) {
        emojiToggle.addEventListener('click', () => {
          const isOpen = emojiPanel.classList.toggle('is-open');
          emojiToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        });
        emojiPanel.querySelectorAll('[data-emoji]').forEach(button => {
          button.addEventListener('click', () => {
            insertAtCursor(textarea, button.getAttribute('data-emoji') || '');
            emojiPanel.classList.remove('is-open');
            emojiToggle.setAttribute('aria-expanded', 'false');
          });
        });
        document.addEventListener('click', (event) => {
          if (!emojiPanel.classList.contains('is-open')) return;
          if (event.target.closest('.emoji-wrap')) return;
          emojiPanel.classList.remove('is-open');
          emojiToggle.setAttribute('aria-expanded', 'false');
        });
      }
      if (mediaInput && mediaFileName) {
        mediaInput.addEventListener('change', () => {
          const files = selectedMediaFiles();
          if (!files.length) {
            mediaFileName.textContent = 'Sin adjunto';
            return;
          }
          if (!validateSelectedMediaFiles(files)) {
            mediaInput.value = '';
            mediaFileName.textContent = 'Sin adjunto';
            return;
          }
          clearRecordedAudio();
          mediaFileName.textContent = files.length === 1 ? files[0].name : `${files.length} fotos seleccionadas`;
        });
      }
      if (audioRecordButton) {
        audioRecordButton.addEventListener('click', () => {
          if (mediaRecorder && mediaRecorder.state === 'recording') stopAudioRecording();
          else startAudioRecording();
        });
      }
      if (audioQuickSendButton) {
        audioQuickSendButton.addEventListener('click', () => {
          if (mediaRecorder && mediaRecorder.state === 'recording') {
            sendAfterRecordingStops = true;
            stopAudioRecording();
          }
        });
      }
      if (audioCancelButton) {
        audioCancelButton.addEventListener('click', cancelAudioRecording);
      }

      function mediaKindFromFile(file) {
        const type = String(file?.type || '').toLowerCase();
        if (type.startsWith('image/')) return 'image';
        if (type.startsWith('audio/') || type === 'video/mp4' || type === 'video/webm' || type === 'application/ogg') return 'audio';
        return 'file';
      }

      function selectedMediaFiles() {
        return mediaInput && mediaInput.files ? Array.from(mediaInput.files) : [];
      }

      function validateSelectedMediaFiles(files) {
        if (!Array.isArray(files) || !files.length) return true;
        if (files.length > 5) {
          showNotice('Puedes adjuntar un maximo de 5 fotos por envio.', 'error');
          return false;
        }
        if (files.length > 1 && files.some(file => mediaKindFromFile(file) !== 'image')) {
          showNotice('Solo puedes adjuntar varias fotos juntas. Los audios se envian uno por uno.', 'error');
          return false;
        }
        return true;
      }

      function optimisticAttachmentForFile(file) {
        if (!file) return null;
        const mediaType = mediaKindFromFile(file);
        if (!['image', 'audio'].includes(mediaType)) return null;
        return {
          id: `local-${Date.now()}`,
          media_type: mediaType,
          mime_type: file.type || '',
          file_size: file.size || 0,
          filename: file.name || (mediaType === 'audio' ? 'Audio' : 'Imagen'),
          url: URL.createObjectURL(file),
          is_local: true
        };
      }

      function cleanupOptimisticUrls(messages) {
        messages.forEach(message => {
          (message.attachments || []).forEach(attachment => {
            if (attachment.is_local && attachment.url) {
              window.setTimeout(() => URL.revokeObjectURL(attachment.url), 2000);
            }
          });
        });
      }

      function buildOptimisticMessages(text, files) {
        const messages = [];
        const stamp = Date.now();
        const mediaFiles = Array.isArray(files) ? files : (files ? [files] : []);
        if (text) {
          messages.push({
            id: `tmp-text-${stamp}`,
            direction: 'outbound',
            text,
            attachments: [],
            time: 'ahora'
          });
        }
        mediaFiles.forEach((file, index) => {
          const attachment = optimisticAttachmentForFile(file);
          if (attachment) {
            messages.push({
              id: `tmp-media-${stamp}-${index}`,
              direction: 'outbound',
              text: attachment.media_type === 'audio' ? 'Audio enviado' : 'Imagen enviada',
              attachments: [attachment],
              time: 'ahora'
            });
          }
        });
        return messages;
      }

      function reconcileOptimisticMessages(tempMessages, serverMessages) {
        const realMessages = Array.isArray(serverMessages) ? serverMessages : [];
        realMessages.forEach((message, index) => {
          const temp = tempMessages[index];
          if (temp) replaceOptimisticMessage(temp.id, message);
          else appendMessage(message);
        });
        if (realMessages.length < tempMessages.length) {
          tempMessages.slice(realMessages.length).forEach(message => {
            const node = messageNodeById(message.id);
            if (node) node.remove();
          });
        }
        cleanupOptimisticUrls(tempMessages);
      }

      replyForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        const textarea = replyForm.querySelector('textarea[name="message"]');
        const mediaInput = document.getElementById('mediaInput');
        const button = replyForm.querySelector('button[type="submit"]');
        const messageText = textarea ? textarea.value.trim() : '';
        const hasText = Boolean(messageText);
        const mediaFiles = selectedMediaFiles();
        const hasMedia = mediaFiles.length > 0;
        const hasRecordedAudio = recordedAudioBlob && recordedAudioBlob.size > 0;
        if (mediaRecorder && mediaRecorder.state === 'recording') {
          showNotice('Deten la grabacion antes de enviar.', 'error');
          return;
        }
        if (!inboxState.canReply) {
          showNotice('Chat vencido. Espera un nuevo mensaje del cliente para responder desde el CRM.', 'error');
          return;
        }
        if (!hasText && !hasMedia && !hasRecordedAudio) return;
        if (hasMedia && !validateSelectedMediaFiles(mediaFiles)) return;
        const optimisticFiles = hasRecordedAudio
          ? new File([recordedAudioBlob], `nota-de-voz-${Date.now()}.${audioExtensionFromMime(recordedAudioBlob.type)}`, { type: recordedAudioBlob.type || 'audio/webm' })
          : mediaFiles;
        const optimisticMessages = buildOptimisticMessages(messageText, optimisticFiles);
        optimisticMessages.forEach(appendOptimisticMessage);
        const formData = new FormData(replyForm);
        if (hasRecordedAudio) {
          formData.delete('media');
          formData.delete('media[]');
          const optimisticFile = Array.isArray(optimisticFiles) ? optimisticFiles[0] : optimisticFiles;
          formData.append('media', optimisticFile);
        }
        if (textarea) {
          textarea.value = '';
          textarea.focus();
        }
        if (mediaInput) mediaInput.value = '';
        if (mediaFileName) mediaFileName.textContent = 'Sin adjunto';
        clearRecordedAudio();
        replyForm.classList.add('is-sending');
        if (button) button.disabled = true;
        try {
          const response = await fetch(replyForm.action, {
            method: 'POST',
            body: formData,
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'fetch' },
            cache: 'no-store'
          });
          const data = await response.json();
          if (!data.ok) throw new Error(data.error || 'No se pudo enviar el mensaje.');
          reconcileOptimisticMessages(optimisticMessages, Array.isArray(data.messages) ? data.messages : (data.message ? [data.message] : []));
          scrollMessagesToBottomSoon();
          showNotice(data.notice || 'Mensaje enviado.');
          pollInbox(true);
        } catch (error) {
          markOptimisticFailed(optimisticMessages.map(message => message.id), error.message || 'Error al enviar');
          showNotice(error.message || 'No se pudo enviar el mensaje.', 'error');
        } finally {
          replyForm.classList.remove('is-sending');
          setComposerEnabled(inboxState.canReply);
        }
      });
    }

    document.querySelectorAll('[data-auto-status-form]').forEach(form => {
      const select = form.querySelector('select');
      if (!select) return;
      let previousValue = select.value;

      select.addEventListener('change', async () => {
        const nextValue = select.value;
        if (nextValue === previousValue) return;
        const isSalesStatus = String(form.querySelector('input[name="action"]')?.value || '') === 'update_sales_status';
        let changeReason = '';
        if (isSalesStatus) {
          const previousLabel = Array.from(select.options).find(option => option.value === previousValue)?.textContent || previousValue;
          const nextLabel = select.options[select.selectedIndex]?.textContent || nextValue;
          const reason = await requestStatusChangeReason(previousLabel, nextLabel);
          if (reason === null) {
            select.value = previousValue;
            return;
          }
          changeReason = reason;
        }
        const formData = new FormData(form);
        if (isSalesStatus) formData.append('change_reason', changeReason);
        const actionUrl = form.getAttribute('action') || window.location.href;
        select.disabled = true;
        try {
          const response = await fetch(actionUrl, {
            method: 'POST',
            body: formData,
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'fetch' },
            cache: 'no-store'
          });
          const data = await response.json();
          if (!response.ok || !data.ok) throw new Error(data.error || 'No se pudo actualizar.');
          previousValue = nextValue;
          const targetId = form.getAttribute('data-status-target');
          const target = targetId ? document.getElementById(targetId) : null;
          if (target && data.label) target.textContent = data.label;
          showNotice(data.notice || 'Actualizado.');
          pollInbox(true);
        } catch (error) {
          select.value = previousValue;
          showNotice(error.message || 'No se pudo actualizar.', 'error');
        } finally {
          select.disabled = false;
        }
      });
    });

    document.querySelectorAll('[data-history-open]').forEach(button => {
      button.addEventListener('click', () => {
        const leadId = button.getAttribute('data-lead-id');
        if (leadId) openLeadHistory(leadId);
      });
    });

    document.querySelectorAll('[data-lead-notes]').forEach(field => {
      let previousValue = field.value;

      field.addEventListener('focus', () => {
        previousValue = field.value;
      });

      field.addEventListener('blur', async () => {
        const leadId = field.getAttribute('data-lead-id');
        const nextValue = field.value;
        if (!leadId || nextValue === previousValue) return;

        field.disabled = true;
        field.classList.add('is-saving');

        try {
          const formData = new FormData();
          formData.append('id', leadId);
          formData.append('notes', nextValue);
          formData.append('csrf', inboxState.csrf);

          const response = await fetch('update_lead_notes.php', {
            method: 'POST',
            body: formData,
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'fetch' },
            cache: 'no-store'
          });
          const data = await response.json();
          if (!response.ok || !data.ok) throw new Error(data.error || 'No se pudieron actualizar las anotaciones.');

          previousValue = nextValue;
          showNotice('Anotaciones actualizadas.');
          pollInbox(true);
        } catch (error) {
          field.value = previousValue;
          showNotice(error.message || 'No se pudieron actualizar las anotaciones.', 'error');
        } finally {
          field.disabled = false;
          field.classList.remove('is-saving');
        }
      });
    });

    bindMessageMediaScroll(messageList);
    scrollMessagesToBottomSoon();
    window.addEventListener('load', scrollMessagesToBottomSoon);
    window.setInterval(() => pollInbox(false), 3000);
    window.setTimeout(() => pollInbox(true), 900);
  </script>
</body>
</html>
