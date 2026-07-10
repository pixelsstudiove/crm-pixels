<?php
// inbox.php
declare(strict_types=1);
require_once __DIR__ . '/auth/require_auth.php';
require_once __DIR__ . '/config/conversations.php';
require_permission('view_conversations');

conv_ensure_schema($pdo);

$contactsTable = conv_contacts_table();
$conversationsTable = conv_conversations_table();
$messagesTable = conv_messages_table();
$channelsTable = ig_channels_table();

$canSendMessages = can('send_messages');
$canManageConversations = can('manage_conversations') || can('send_messages');
$statusOptions = [
  'abierta' => 'Abierta',
  'pendiente' => 'Pendiente',
  'seguimiento' => 'En seguimiento',
  'cerrada' => 'Cerrada',
  'spam' => 'Spam / no califica',
];

$errors = [];
$notice = trim((string) ($_GET['notice'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $csrf = (string) ($_POST['csrf'] ?? '');
  if (!$csrf || !isset($_SESSION['csrf']) || !hash_equals((string) $_SESSION['csrf'], $csrf)) {
    $errors[] = 'CSRF invalido. Recarga la pagina.';
  } else {
    $action = (string) ($_POST['action'] ?? '');
    $conversationId = (int) ($_POST['conversation_id'] ?? 0);
    if ($action === 'update_status' && $canManageConversations && $conversationId > 0) {
      $status = (string) ($_POST['status'] ?? '');
      if (array_key_exists($status, $statusOptions)) {
        $stmt = $pdo->prepare("UPDATE {$conversationsTable} SET status=?, updated_at=NOW() WHERE id=?");
        $stmt->execute([$status, $conversationId]);
        header('Location: inbox.php?id=' . $conversationId . '&notice=' . rawurlencode('Estado actualizado.'));
        exit;
      }
      $errors[] = 'Selecciona un estado valido.';
    }
  }
}

$filterStatus = trim((string) ($_GET['status'] ?? ''));
if ($filterStatus !== '' && !array_key_exists($filterStatus, $statusOptions)) $filterStatus = '';
$q = trim((string) ($_GET['q'] ?? ''));
$selectedId = max(0, (int) ($_GET['id'] ?? 0));

$where = [];
$params = [];
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
  l.fullname AS lead_fullname
FROM {$conversationsTable} c
JOIN {$contactsTable} ct ON ct.id = c.contact_id
LEFT JOIN {$channelsTable} ch ON ch.id = c.channel_id
LEFT JOIN {$TABLE_LEADS} l ON l.id = c.lead_id
{$whereSql}
ORDER BY COALESCE(c.last_message_at, c.created_at) DESC
LIMIT 80
SQL;
$stmt = $pdo->prepare($listSql);
foreach ($params as $key => $value) $stmt->bindValue($key, $value);
$stmt->execute();
$conversations = $stmt->fetchAll();

if ($selectedId <= 0 && $conversations) $selectedId = (int) $conversations[0]['id'];

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
  l.fullname AS lead_fullname,
  l.sales_status AS lead_sales_status
FROM {$conversationsTable} c
JOIN {$contactsTable} ct ON ct.id = c.contact_id
LEFT JOIN {$channelsTable} ch ON ch.id = c.channel_id
LEFT JOIN {$TABLE_LEADS} l ON l.id = c.lead_id
WHERE c.id = ?
LIMIT 1
SQL;
  $detailStmt = $pdo->prepare($detailSql);
  $detailStmt->execute([$selectedId]);
  $selected = $detailStmt->fetch() ?: null;
  if ($selected) conv_mark_read($pdo, (int) $selected['id']);
}

$messages = [];
if ($selected) {
  $msgStmt = $pdo->prepare("SELECT m.*, u.username AS sent_by_username FROM {$messagesTable} m LEFT JOIN {$TABLE_USERS} u ON u.id = m.sent_by WHERE m.conversation_id=? ORDER BY m.sent_at ASC, m.id ASC");
  $msgStmt->execute([(int) $selected['id']]);
  $messages = $msgStmt->fetchAll();
}

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
  $value = trim((string) $value);
  if ($value === '') return 'Sin fecha';
  $time = strtotime($value);
  return $time ? date('d/m/Y H:i', $time) : $value;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover" />
  <title>Inbox conversacional - Pixels Studio</title>
  <link rel="stylesheet" href="css/app.css">
  <style>
    :root { --container-w:min(98vw, 1440px); --inbox-line:#d6ecf8; --inbox-soft:#eef9ff; --inbox-ink:#071120; --inbox-muted:#5d6d86; }
    .inbox-card { min-height:calc(100vh - 54px); }
    .inbox-header { display:flex; justify-content:space-between; align-items:flex-start; gap:16px; flex-wrap:wrap; margin-bottom:14px; }
    .inbox-actions { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
    .inbox-link, .inbox-btn { display:inline-flex; align-items:center; justify-content:center; min-height:40px; padding:0 14px; border:1px solid var(--line); border-radius:10px; background:var(--surface-soft); color:#007ea8; font-weight:850; text-decoration:none; cursor:pointer; }
    .inbox-link.primary, .inbox-btn.primary { background:#071120; border-color:#071120; color:#eafaff; }
    .inbox-link:hover, .inbox-btn:hover { background:#dff6ff; border-color:#8bdfff; }
    .inbox-layout { display:grid; grid-template-columns:minmax(280px, 360px) minmax(0, 1fr) minmax(260px, 320px); gap:12px; min-height:640px; }
    .inbox-panel { border:1px solid rgba(0,212,255,.16); border-radius:16px; background:#fff; overflow:hidden; box-shadow:0 8px 22px rgba(0, 76, 110, .06); }
    .conversation-filters { display:grid; gap:8px; padding:12px; border-bottom:1px solid var(--inbox-line); background:#fbfdff; }
    .conversation-filters input, .conversation-filters select, .status-form select { width:100%; min-height:40px; border:1px solid var(--line); border-radius:10px; padding:0 10px; font:inherit; color:var(--inbox-ink); background:#fff; }
    .conversation-list { max-height:650px; overflow:auto; }
    .conversation-item { display:block; padding:13px 14px; border-bottom:1px solid rgba(0,68,99,.10); color:inherit; text-decoration:none; background:#fff; }
    .conversation-item:hover, .conversation-item.is-active { background:#effaff; }
    .conversation-row { display:flex; justify-content:space-between; gap:8px; align-items:flex-start; }
    .conversation-name { font-weight:900; color:var(--inbox-ink); }
    .conversation-time { color:var(--inbox-muted); font-size:.78rem; white-space:nowrap; }
    .conversation-preview { color:var(--inbox-muted); margin-top:5px; font-size:.9rem; line-height:1.35; }
    .badge { display:inline-flex; align-items:center; min-height:24px; padding:0 8px; border-radius:999px; font-size:.74rem; font-weight:900; background:#eef9f0; color:#217a43; border:1px solid #a8e0ba; }
    .badge.unread { background:#071120; color:#eafaff; border-color:#071120; }
    .chat-header { padding:16px; border-bottom:1px solid var(--inbox-line); display:flex; justify-content:space-between; gap:12px; align-items:flex-start; background:#fbfdff; }
    .chat-header h2 { margin:0; font-size:1.15rem; color:var(--inbox-ink); }
    .chat-header p { margin:4px 0 0; color:var(--inbox-muted); }
    .message-list { height:500px; overflow:auto; padding:18px; background:linear-gradient(180deg,#f8fdff,#eef8ff); display:flex; flex-direction:column; gap:10px; }
    .message { max-width:min(78%, 620px); border:1px solid var(--inbox-line); border-radius:14px; padding:10px 12px; background:#fff; color:var(--inbox-ink); box-shadow:0 4px 14px rgba(0, 76, 110, .05); }
    .message.outbound { align-self:flex-end; background:#071120; border-color:#071120; color:#eafaff; }
    .message.inbound { align-self:flex-start; }
    .message-text { white-space:pre-wrap; overflow-wrap:anywhere; line-height:1.45; }
    .message-meta { margin-top:6px; font-size:.72rem; opacity:.72; }
    .reply-box { padding:14px; border-top:1px solid var(--inbox-line); background:#fff; }
    .reply-box textarea { width:100%; min-height:92px; resize:vertical; border:1px solid var(--line); border-radius:12px; padding:10px 12px; font:inherit; outline:none; }
    .reply-box textarea:focus { border-color:var(--brand-primary); box-shadow:0 0 0 3px rgba(0,212,255,.16); }
    .reply-actions { display:flex; justify-content:space-between; gap:10px; align-items:center; margin-top:10px; flex-wrap:wrap; color:var(--inbox-muted); font-size:.88rem; }
    .side-panel { padding:16px; display:grid; align-content:start; gap:14px; }
    .side-panel h2 { margin:0; font-size:1.05rem; color:var(--inbox-ink); }
    .info-row { display:grid; gap:3px; color:var(--inbox-muted); font-size:.9rem; }
    .info-row strong { color:var(--inbox-ink); }
    .status-form { display:grid; gap:8px; }
    .empty-state { display:grid; place-items:center; min-height:500px; text-align:center; color:var(--inbox-muted); padding:24px; }
    .notice { margin-bottom:14px; }
    @media (max-width: 1100px) { .inbox-layout { grid-template-columns:minmax(260px, 340px) 1fr; } .side-panel { grid-column:1 / -1; } }
    @media (max-width: 760px) { .inbox-layout { grid-template-columns:1fr; } .conversation-list { max-height:300px; } .message-list { height:430px; padding:12px; } .message { max-width:92%; } }
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
            <p class="subtitle">Gestiona conversaciones de Instagram sin mezclar la bandeja con el CRM de formularios.</p>
          </div>
          <div class="inbox-actions">
            <a class="inbox-link" href="dashboard.php">CRM de formularios</a>
            <?php if (can('manage_integrations')): ?><a class="inbox-link" href="channels.php">Canales</a><?php endif; ?>
            <span class="role-pill"><?= h(role_label(current_user_role())) ?></span>
          </div>
        </header>

        <?php if ($notice !== ''): ?><div class="form-alert alert-info notice"><?= h($notice) ?></div><?php endif; ?>
        <?php foreach ($errors as $error): ?><div class="form-alert alert-error notice"><?= h($error) ?></div><?php endforeach; ?>

        <div class="inbox-layout">
          <aside class="inbox-panel" aria-label="Conversaciones">
            <form class="conversation-filters" method="get" action="inbox.php">
              <input type="text" name="q" value="<?= h($q) ?>" placeholder="Buscar conversación">
              <select name="status" onchange="this.form.submit()" aria-label="Filtrar por estado">
                <option value="">Todos los estados</option>
                <?php foreach ($statusOptions as $value => $label): ?>
                  <option value="<?= h($value) ?>" <?= $filterStatus === (string) $value ? 'selected' : '' ?>><?= h($label) ?></option>
                <?php endforeach; ?>
              </select>
              <button class="inbox-btn" type="submit">Buscar</button>
            </form>
            <div class="conversation-list">
              <?php if ($conversations): foreach ($conversations as $conversation): ?>
                <?php $isActive = $selected && (int) $selected['id'] === (int) $conversation['id']; ?>
                <a class="conversation-item <?= $isActive ? 'is-active' : '' ?>" href="inbox.php?id=<?= (int) $conversation['id'] ?><?= $filterStatus !== '' ? '&status=' . h(rawurlencode($filterStatus)) : '' ?><?= $q !== '' ? '&q=' . h(rawurlencode($q)) : '' ?>">
                  <div class="conversation-row">
                    <span class="conversation-name"><?= h(inbox_contact_name($conversation)) ?></span>
                    <span class="conversation-time"><?= h(inbox_time($conversation['last_message_at'] ?? $conversation['created_at'] ?? '')) ?></span>
                  </div>
                  <div class="conversation-row" style="margin-top:6px">
                    <span class="badge"><?= h($statusOptions[(string) ($conversation['status'] ?? '')] ?? 'Abierta') ?></span>
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
              <header class="chat-header">
                <div>
                  <h2><?= h(inbox_contact_name($selected)) ?></h2>
                  <p><?= h((string) ($selected['channel_username'] ?: $selected['page_name'] ?: 'Instagram')) ?> · <?= h($statusOptions[(string) ($selected['status'] ?? '')] ?? 'Abierta') ?></p>
                </div>
                <?php if (!empty($selected['lead_id'])): ?><a class="inbox-link" href="dashboard.php?q=<?= (int) $selected['lead_id'] ?>">Ver lead #<?= (int) $selected['lead_id'] ?></a><?php endif; ?>
              </header>

              <div class="message-list">
                <?php if ($messages): foreach ($messages as $message): ?>
                  <?php $direction = (string) ($message['direction'] ?? 'inbound'); ?>
                  <article class="message <?= $direction === 'outbound' ? 'outbound' : 'inbound' ?>">
                    <div class="message-text"><?= h($message['message_text'] ?: 'Mensaje sin texto') ?></div>
                    <div class="message-meta">
                      <?= $direction === 'outbound' ? 'Enviado' : 'Recibido' ?> · <?= h(inbox_time($message['sent_at'] ?? '')) ?>
                      <?php if ($direction === 'outbound' && !empty($message['sent_by_username'])): ?> · <?= h($message['sent_by_username']) ?><?php endif; ?>
                    </div>
                  </article>
                <?php endforeach; else: ?>
                  <div class="empty-state">Esta conversacion aun no tiene mensajes guardados.</div>
                <?php endif; ?>
              </div>

              <form class="reply-box" method="post" action="send_instagram_message.php">
                <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf'] ?? '') ?>">
                <input type="hidden" name="conversation_id" value="<?= (int) $selected['id'] ?>">
                <textarea name="message" maxlength="1000" placeholder="Escribe una respuesta para Instagram" <?= $canSendMessages ? '' : 'disabled' ?> required></textarea>
                <div class="reply-actions">
                  <span>Las respuestas dependen de las ventanas y reglas de Meta para Instagram Messaging.</span>
                  <button class="inbox-btn primary" type="submit" <?= $canSendMessages ? '' : 'disabled' ?>>Enviar</button>
                </div>
              </form>
            <?php else: ?>
              <div class="empty-state">Selecciona una conversacion para ver el historial.</div>
            <?php endif; ?>
          </section>

          <aside class="inbox-panel side-panel" aria-label="Ficha de conversacion">
            <?php if ($selected): ?>
              <h2>Ficha conversacional</h2>
              <div class="info-row"><span>Contacto</span><strong><?= h(inbox_contact_name($selected)) ?></strong></div>
              <div class="info-row"><span>Instagram</span><strong><?= !empty($selected['username']) ? '<a href="' . h((string) ($selected['profile_url'] ?: ('https://instagram.com/' . ltrim((string) $selected['username'], '@')))) . '" target="_blank" rel="noopener">@' . h((string) $selected['username']) . '</a>' : '—' ?></strong></div>
              <div class="info-row"><span>Canal</span><strong><?= h((string) ($selected['channel_username'] ?: $selected['page_name'] ?: 'Instagram')) ?></strong></div>
              <div class="info-row"><span>Ultimo mensaje</span><strong><?= h(inbox_time($selected['last_message_at'] ?? '')) ?></strong></div>
              <div class="info-row"><span>Lead vinculado</span><strong><?= !empty($selected['lead_id']) ? '#' . (int) $selected['lead_id'] . ' · ' . h((string) ($selected['lead_fullname'] ?? '')) : 'Sin vincular' ?></strong></div>

              <form class="status-form" method="post" action="inbox.php?id=<?= (int) $selected['id'] ?>">
                <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf'] ?? '') ?>">
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="conversation_id" value="<?= (int) $selected['id'] ?>">
                <label class="field">
                  <span class="field-label">Estado conversacional</span>
                  <select name="status" <?= $canManageConversations ? '' : 'disabled' ?>>
                    <?php foreach ($statusOptions as $value => $label): ?>
                      <option value="<?= h($value) ?>" <?= (string) ($selected['status'] ?? '') === (string) $value ? 'selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                  </select>
                </label>
                <button class="inbox-btn" type="submit" <?= $canManageConversations ? '' : 'disabled' ?>>Actualizar estado</button>
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
</body>
</html>
