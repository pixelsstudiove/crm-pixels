<?php
// webhook_logs.php
declare(strict_types=1);
require_once __DIR__ . '/auth/require_auth.php';
require_once __DIR__ . '/config/conversations.php';
require_once __DIR__ . '/config/navigation.php';
require_permission('manage_integrations');

conv_ensure_schema($pdo);

$logsTable = conv_webhook_logs_table();
$messagesTable = conv_messages_table();
$currentAccountId = (int) (current_account_id() ?: accounts_default_id($pdo));
$notice = '';
$accountOptions = [];
$filterAccountId = 0;
if (is_super_admin()) {
  try {
    $accountStmt = $pdo->query("SELECT id, name FROM " . accounts_table() . " ORDER BY name ASC");
    $accountOptions = $accountStmt ? $accountStmt->fetchAll() : [];
  } catch (Throwable $e) {
    $accountOptions = [];
  }
  $accountIds = array_map(static fn($row) => (int) ($row['id'] ?? 0), $accountOptions);
  $filterAccountId = max(0, (int) ($_GET['account_id'] ?? ($_POST['account_id'] ?? 0)));
  if ($filterAccountId > 0 && !in_array($filterAccountId, $accountIds, true)) $filterAccountId = 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $csrf = (string) ($_POST['csrf'] ?? '');
  if (!$csrf || !isset($_SESSION['csrf']) || !hash_equals((string) $_SESSION['csrf'], $csrf)) {
    $notice = 'CSRF inválido. Recarga la página.';
  } elseif ((string) ($_POST['action'] ?? '') === 'repair_missing_messages') {
    $repairSql = <<<SQL
SELECT l.*
FROM {$logsTable} l
LEFT JOIN {$messagesTable} m
  ON m.conversation_id = l.conversation_id
  AND m.direction = 'inbound'
  AND m.message_text = l.message_preview
WHERE l.source IN ('instagram', 'messenger')
  AND l.conversation_id IS NOT NULL
  AND l.message_preview IS NOT NULL
  AND l.status IN ('processed', 'duplicate', 'lead_only')
  %s
  AND m.id IS NULL
ORDER BY l.id ASC
LIMIT 500
SQL;
    $repairAccountSql = '';
    if (!is_super_admin()) $repairAccountSql = 'AND l.account_id = ' . (int) $currentAccountId;
    elseif ($filterAccountId > 0) $repairAccountSql = 'AND l.account_id = ' . (int) $filterAccountId;
    $rows = $pdo->query(sprintf($repairSql, $repairAccountSql))->fetchAll();
    $repaired = 0;
    foreach ($rows as $row) {
      $externalMessageId = (string) ($row['external_message_id'] ?? '');
      if ($externalMessageId !== '') {
        $dupStmt = $pdo->prepare("SELECT id FROM {$messagesTable} WHERE conversation_id=? AND external_message_hash=? LIMIT 1");
        $dupStmt->execute([(int) $row['conversation_id'], hash('sha256', $externalMessageId)]);
        if ($dupStmt->fetchColumn()) $externalMessageId = 'repaired-log-' . (int) $row['id'] . '-' . mb_substr($externalMessageId, 0, 460);
      } else {
        $externalMessageId = 'repaired-log-' . (int) $row['id'];
      }
      $messageId = conv_add_message($pdo, [
        'conversation_id' => (int) $row['conversation_id'],
        'external_message_id' => $externalMessageId,
        'direction' => 'inbound',
        'sender_external_id' => (string) ($row['sender_id'] ?? ''),
        'message_type' => (string) ($row['event_type'] ?: 'message'),
        'message_text' => (string) ($row['message_preview'] ?? ''),
        'payload_json' => (string) ($row['payload_json'] ?? ''),
        'sent_at' => (string) ($row['created_at'] ?? gmdate('Y-m-d H:i:s')),
        'delivery_status' => 'repaired',
      ]);
      if ($messageId > 0) $repaired++;
    }
    $notice = 'Mensajes reparados desde logs: ' . $repaired . '.';
  }
}

$status = trim((string) ($_GET['status'] ?? ''));
$allowedStatuses = ['processed', 'duplicate', 'lead_only', 'ignored'];
if ($status !== '' && !in_array($status, $allowedStatuses, true)) $status = '';

$whereParts = [];
$params = [];
if ($status !== '') {
  $whereParts[] = 'l.status = :status';
  $params[':status'] = $status;
}
if (!is_super_admin()) {
  $whereParts[] = 'l.account_id = :account_id';
  $params[':account_id'] = $currentAccountId;
} elseif ($filterAccountId > 0) {
  $whereParts[] = 'l.account_id = :account_id';
  $params[':account_id'] = $filterAccountId;
}
$where = $whereParts ? 'WHERE ' . implode(' AND ', $whereParts) : '';
$conversationsTable = conv_conversations_table();
$accountsTable = accounts_table();
$stmt = $pdo->prepare("SELECT l.*, c.public_id AS conversation_public_id, c.public_uid AS conversation_public_uid, a.slug AS account_slug FROM {$logsTable} l LEFT JOIN {$conversationsTable} c ON c.id = l.conversation_id LEFT JOIN {$accountsTable} a ON a.id = l.account_id {$where} ORDER BY l.id DESC LIMIT 150");
foreach ($params as $key => $value) $stmt->bindValue($key, $value);
$stmt->execute();
$logs = $stmt->fetchAll();

function log_badge_class(string $status): string {
  if ($status === 'processed') return 'ok';
  if ($status === 'duplicate') return 'ok';
  if ($status === 'lead_only') return 'warn';
  return 'muted';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover" />
  <title>Eventos de Meta - Pixels Studio</title>
  <link rel="stylesheet" href="css/app.css?v=<?= (int) @filemtime(__DIR__ . '/css/app.css') ?>">
  <style>
    :root { --container-w:min(98vw, 1360px); }
    .logs-header { display:flex; justify-content:space-between; align-items:flex-start; gap:16px; flex-wrap:wrap; margin-bottom:16px; }
    .logs-actions { display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
    .logs-link, .logs-btn { display:inline-flex; align-items:center; justify-content:center; min-height:40px; padding:0 14px; border:1px solid var(--line); border-radius:10px; color:#007ea8; background:var(--surface-soft); font-weight:850; text-decoration:none; cursor:pointer; }
    .logs-link:hover, .logs-btn:hover { background:#dff6ff; border-color:#8bdfff; }
    .logs-filter { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:14px; }
    .logs-filter select { min-height:40px; padding:0 12px; border:1px solid var(--line); border-radius:10px; color:#071120; background:#fff; font:inherit; font-weight:800; }
    .logs-table-wrap { max-width:100%; overflow-x:auto; -webkit-overflow-scrolling:touch; border:1px solid rgba(0,212,255,.14); border-radius:16px; background:#fff; }
    .logs-table { width:100%; border-collapse:collapse; font-size:.88rem; min-width:1120px; }
    .logs-table th { background:#071120; color:#eafaff; text-align:left; padding:11px; white-space:nowrap; }
    .logs-table td { padding:10px 11px; border-bottom:1px solid rgba(0,68,99,.10); vertical-align:top; color:#24324a; }
    .log-badge { display:inline-flex; align-items:center; min-height:24px; padding:0 8px; border-radius:999px; font-size:.74rem; font-weight:900; }
    .log-badge.ok { background:#eef9f0; color:#217a43; border:1px solid #a8e0ba; }
    .log-badge.warn { background:#fff8df; color:#946200; border:1px solid #efda85; }
    .log-badge.muted { background:#f1f5f9; color:#64748b; border:1px solid #cbd5e1; }
    .mono { font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace; font-size:.8rem; }
    .preview { max-width:280px; white-space:normal; overflow-wrap:anywhere; }
    @media (max-width: 760px) { .logs-actions, .logs-filter { width:100%; } .logs-link, .logs-btn { flex:1; } }
  </style>
</head>
<body class="dashboard-page config-page">
  <main class="dashboard-shell">
    <section class="form-card dashboard-card">
      <div class="panel">
        <header class="logs-header">
          <div>
            <p class="eyebrow"><?= h(app_config('brand.name', 'Pixels Studio')) ?></p>
            <h1 class="title">Eventos de Meta</h1>
            <p class="subtitle">Monitoreo tecnico de mensajes recibidos por el webhook.</p>
          </div>
          <?php nav_render_config_top_nav($pdo, 'webhook_logs.php', $filterAccountId, ['status' => $status], ['status' => $status]); ?>
        </header>

        <div class="admin-layout">
          <?php nav_render_admin_side_nav('events'); ?>
          <div class="admin-content">
        <form class="logs-filter" method="get" action="<?= h(account_url('webhook_logs.php')) ?>">
          <?php if (is_super_admin()): ?>
            <select name="account_id" onchange="this.form.submit()" aria-label="Filtrar por cuenta">
              <option value="">Todas las cuentas</option>
              <?php foreach ($accountOptions as $account): ?>
                <option value="<?= (int) $account['id'] ?>" <?= $filterAccountId === (int) $account['id'] ? 'selected' : '' ?>><?= h((string) $account['name']) ?></option>
              <?php endforeach; ?>
            </select>
          <?php endif; ?>
          <button class="logs-btn" name="status" value="" type="submit">Todos</button>
          <button class="logs-btn" name="status" value="processed" type="submit">Procesados</button>
          <button class="logs-btn" name="status" value="duplicate" type="submit">Duplicados</button>
          <button class="logs-btn" name="status" value="lead_only" type="submit">Solo lead</button>
          <button class="logs-btn" name="status" value="ignored" type="submit">Ignorados</button>
        </form>

        <?php if ($notice !== ''): ?><div class="form-alert alert-info" style="margin-bottom:14px"><?= h($notice) ?></div><?php endif; ?>
        <form method="post" action="<?= h(account_url('webhook_logs.php')) ?>" style="margin-bottom:14px">
          <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf'] ?? '') ?>">
          <input type="hidden" name="action" value="repair_missing_messages">
          <?php if ($filterAccountId > 0): ?><input type="hidden" name="account_id" value="<?= (int) $filterAccountId ?>"><?php endif; ?>
          <button class="logs-btn" type="submit">Reparar historial desde logs</button>
        </form>

        <div class="logs-table-wrap">
          <table class="logs-table">
            <thead>
              <tr>
                <th>ID</th>
                <th>Estado</th>
                <th>Evento</th>
                <th>Canal</th>
                <th>Recipient</th>
                <th>Sender</th>
                <th>Mensaje</th>
                <th>Lead</th>
                <th>Conversación</th>
                <th>Error</th>
                <th>Fecha</th>
              </tr>
            </thead>
            <tbody>
              <?php if ($logs): foreach ($logs as $log): ?>
                <tr>
                  <td class="mono" data-label="ID">#<?= (int) $log['id'] ?></td>
                  <td data-label="Estado"><span class="log-badge <?= h(log_badge_class((string) $log['status'])) ?>"><?= h((string) $log['status']) ?></span></td>
                  <td data-label="Evento"><?= h((string) ($log['event_type'] ?: '—')) ?></td>
                  <td data-label="Canal"><?= h((string) ($log['channel_username'] ?: '—')) ?><br><span class="mono"><?= $log['channel_id'] ? '#' . (int) $log['channel_id'] : '—' ?></span></td>
                  <td class="mono" data-label="Recipient"><?= h((string) ($log['recipient_id'] ?: '—')) ?></td>
                  <td class="mono" data-label="Sender"><?= h((string) ($log['sender_id'] ?: '—')) ?></td>
                  <td class="preview" data-label="Mensaje"><?= h((string) ($log['message_preview'] ?: '—')) ?><br><span class="mono"><?= h((string) ($log['external_message_id'] ?: '—')) ?></span></td>
                  <td class="mono" data-label="Lead"><?= $log['lead_id'] ? '#' . (int) $log['lead_id'] : '—' ?></td>
                  <td class="mono" data-label="Conversación">
                    <?php if ($log['conversation_id']): ?>
                      <?php
                        $logPublicId = trim((string) ($log['conversation_public_uid'] ?? ''));
                        if ($logPublicId === '') $logPublicId = (string) ((int) ($log['conversation_public_id'] ?? $log['conversation_id']));
                        $logSlug = trim((string) ($log['account_slug'] ?? accounts_request_slug()));
                      ?>
                      <a href="<?= h(account_url('conversation_debug.php', ['id' => $logPublicId], $logSlug !== '' ? $logSlug : null)) ?>">#<?= h($logPublicId) ?></a>
                    <?php else: ?>
                      —
                    <?php endif; ?>
                  </td>
                  <td class="preview" data-label="Error"><?= h((string) ($log['error_message'] ?: '—')) ?></td>
                  <td data-label="Fecha"><?= h(app_datetime($log['created_at'] ?? '')) ?></td>
                </tr>
              <?php endforeach; else: ?>
                <tr><td data-label="Eventos" colspan="11">Todavia no hay eventos registrados.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
          </div>
        </div>
      </div>
    </section>
  </main>
  <script src="js/navigation.js?v=<?= (int) @filemtime(__DIR__ . '/js/navigation.js') ?>" defer></script>
</body>
</html>
