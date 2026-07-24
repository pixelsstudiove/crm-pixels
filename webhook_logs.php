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

$summaryWhereParts = [];
$summaryParams = [];
if (!is_super_admin()) {
  $summaryWhereParts[] = 'account_id = :summary_account_id';
  $summaryParams[':summary_account_id'] = $currentAccountId;
} elseif ($filterAccountId > 0) {
  $summaryWhereParts[] = 'account_id = :summary_account_id';
  $summaryParams[':summary_account_id'] = $filterAccountId;
}
$summaryWhere = $summaryWhereParts ? 'WHERE ' . implode(' AND ', $summaryWhereParts) : '';
$summaryStmt = $pdo->prepare("SELECT status, COUNT(*) AS total, SUM(CASE WHEN error_message IS NOT NULL AND error_message <> '' THEN 1 ELSE 0 END) AS errors FROM {$logsTable} {$summaryWhere} GROUP BY status");
foreach ($summaryParams as $key => $value) $summaryStmt->bindValue($key, $value);
$summaryStmt->execute();
$summaryRows = $summaryStmt->fetchAll();
$statusTotals = ['processed' => 0, 'duplicate' => 0, 'lead_only' => 0, 'ignored' => 0];
$totalEvents = 0;
$totalErrors = 0;
foreach ($summaryRows as $row) {
  $rowStatus = (string) ($row['status'] ?? '');
  $rowTotal = (int) ($row['total'] ?? 0);
  if (array_key_exists($rowStatus, $statusTotals)) $statusTotals[$rowStatus] = $rowTotal;
  $totalEvents += $rowTotal;
  $totalErrors += (int) ($row['errors'] ?? 0);
}

function log_badge_class(string $status): string {
  if ($status === 'processed') return 'ok';
  if ($status === 'duplicate') return 'ok';
  if ($status === 'lead_only') return 'warn';
  return 'muted';
}

function log_status_label(string $status): string {
  return [
    'processed' => 'Procesado',
    'duplicate' => 'Duplicado',
    'lead_only' => 'Solo lead',
    'ignored' => 'Ignorado',
  ][$status] ?? ($status !== '' ? $status : 'Sin estado');
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
    :root { --container-w:min(98vw, 1440px); }
    .events-page { --event-ink:#071120; --event-muted:#68758d; --event-line:#dce8f4; --event-soft:#f5f9fd; --event-dark:#050b18; --event-cyan:#13c7e8; --event-blue:#316bff; --event-green:#23a970; --event-amber:#f0a51f; --event-red:#e6576d; }
    .events-page .dashboard-card .panel { padding:clamp(16px,2vw,30px)!important; }
    .events-console { display:grid; gap:18px; }
    .logs-header { display:grid!important; grid-template-columns:minmax(0,1fr) auto; gap:18px; align-items:start!important; margin-bottom:0!important; padding:24px!important; border:1px solid rgba(255,255,255,.12)!important; border-radius:30px!important; background:var(--event-dark)!important; color:#fff; overflow:hidden; position:relative; }
    .logs-header::before { content:""; position:absolute; inset:0; background:linear-gradient(90deg,rgba(19,199,232,.20) 1px,transparent 1px),linear-gradient(0deg,rgba(255,255,255,.06) 1px,transparent 1px); background-size:42px 42px; mask-image:linear-gradient(90deg,rgba(0,0,0,.75),transparent 82%); pointer-events:none; }
    .logs-header > * { position:relative; z-index:1; }
    .logs-header .eyebrow { color:var(--event-cyan); }
    .logs-header .title { color:#fff!important; font-size:clamp(2.1rem,4.2vw,4.8rem); line-height:.9; letter-spacing:-.055em; }
    .logs-header .subtitle { max-width:760px; color:#b8c5d9!important; font-weight:760; line-height:1.45; }
    .events-page .logs-header .menu-trigger,
    .events-page .logs-header .nav-direct-button { border-color:rgba(255,255,255,.22)!important; background:rgba(255,255,255,.08)!important; color:#fff!important; box-shadow:none!important; }
    .events-page .logs-header .menu-trigger:hover,
    .events-page .logs-header .nav-direct-button:hover { border-color:#fff!important; background:#fff!important; color:var(--event-dark)!important; }
    .event-metrics { display:grid; grid-template-columns:repeat(5,minmax(0,1fr)); gap:12px; }
    .event-metric { min-height:108px; padding:18px; border:1px solid var(--event-line); border-radius:24px; background:#fff; box-shadow:0 18px 42px rgba(15,23,42,.06); position:relative; overflow:hidden; }
    .event-metric::after { content:""; position:absolute; right:17px; top:17px; width:12px; height:12px; border-radius:50%; background:var(--tone,var(--event-cyan)); box-shadow:0 0 0 8px color-mix(in srgb,var(--tone,var(--event-cyan)) 14%,transparent); }
    .event-metric span { display:block; max-width:78%; color:var(--event-muted); font-size:.68rem; font-weight:950; letter-spacing:.1em; text-transform:uppercase; }
    .event-metric strong { display:block; margin-top:15px; color:var(--event-ink); font-size:2.1rem; line-height:1; font-weight:950; letter-spacing:-.045em; }
    .event-toolbar { display:grid; grid-template-columns:minmax(0,1fr) auto; gap:12px; align-items:start; padding:14px; border:1px solid var(--event-line); border-radius:24px; background:#fff; box-shadow:0 18px 42px rgba(15,23,42,.06); }
    .logs-filter { display:flex; gap:8px; flex-wrap:wrap; margin:0!important; }
    .logs-filter select { min-width:230px; min-height:44px; padding:0 38px 0 14px; border:1px solid var(--event-line)!important; border-radius:14px!important; color:var(--event-ink); background:#fff; font:inherit; font-weight:850; }
    .logs-btn { min-height:44px!important; padding:0 14px!important; border-radius:999px!important; border:1px solid var(--event-line)!important; background:#fff!important; color:var(--event-muted)!important; box-shadow:none!important; font-weight:950!important; cursor:pointer; text-decoration:none; }
    .logs-btn:hover,
    .logs-btn.is-active { border-color:var(--event-dark)!important; background:var(--event-dark)!important; color:#fff!important; transform:translateY(-1px); }
    .repair-form { margin:0!important; display:flex; justify-content:flex-end; }
    .repair-form .logs-btn { border-color:var(--event-dark)!important; background:var(--event-dark)!important; color:#fff!important; }
    .logs-table-wrap { max-width:100%; overflow-x:auto; -webkit-overflow-scrolling:touch; border:1px solid var(--event-line)!important; border-radius:24px!important; background:#fff!important; box-shadow:0 18px 42px rgba(15,23,42,.06)!important; }
    .logs-table { width:100%; border-collapse:separate!important; border-spacing:0; font-size:.86rem; min-width:1180px; }
    .logs-table th { position:sticky; top:0; z-index:1; background:var(--event-dark)!important; color:#fff!important; text-align:left; padding:14px 13px!important; white-space:nowrap; font-size:.7rem; letter-spacing:.09em; text-transform:uppercase; }
    .logs-table td { padding:13px!important; border-bottom:1px solid #e6edf5!important; vertical-align:top; color:#24324a!important; background:#fff!important; }
    .logs-table tr { position:relative; }
    .logs-table tr:hover td { background:#f8fbff!important; }
    .logs-table tr.status-processed td:first-child { box-shadow:inset 4px 0 0 var(--event-green); }
    .logs-table tr.status-duplicate td:first-child { box-shadow:inset 4px 0 0 var(--event-cyan); }
    .logs-table tr.status-lead_only td:first-child { box-shadow:inset 4px 0 0 var(--event-amber); }
    .logs-table tr.status-ignored td:first-child { box-shadow:inset 4px 0 0 var(--event-muted); }
    .log-badge { display:inline-flex; align-items:center; min-height:26px; padding:0 9px; border-radius:999px; font-size:.72rem; font-weight:950; white-space:nowrap; }
    .log-badge.ok { background:#eef9f0!important; color:#217a43!important; border:1px solid #a8e0ba; }
    .log-badge.warn { background:#fff8df!important; color:#946200!important; border:1px solid #efda85; }
    .log-badge.muted { background:#f1f5f9!important; color:#64748b!important; border:1px solid #cbd5e1; }
    .mono { font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace; font-size:.78rem; line-height:1.45; }
    .preview { max-width:330px; white-space:normal; overflow-wrap:anywhere; line-height:1.42; }
    .error-cell:not(:empty) { color:#991b1b!important; font-weight:850; }
    .event-empty { padding:28px!important; color:var(--event-muted)!important; text-align:center; font-weight:850; }
    @media (max-width: 1180px) { .logs-header { grid-template-columns:1fr; } .event-metrics { grid-template-columns:repeat(2,minmax(0,1fr)); } .event-toolbar { grid-template-columns:1fr; } .repair-form { justify-content:flex-start; } }
    @media (max-width: 760px) { .events-page .dashboard-card .panel { padding:14px 10px!important; } .logs-header { padding:20px!important; border-radius:24px!important; } .event-metrics { grid-template-columns:1fr; } .logs-actions, .logs-filter { width:100%; } .logs-btn, .repair-form .logs-btn { flex:1; } .logs-filter select { min-width:0; width:100%; } }
  </style>
</head>
<body class="dashboard-page config-page events-page">
  <main class="dashboard-shell">
    <section class="form-card dashboard-card">
      <div class="panel">
        <div class="events-console">
          <header class="logs-header">
            <div>
              <p class="eyebrow"><?= h(app_config('brand.name', 'Pixels Studio')) ?></p>
              <h1 class="title">Eventos de Meta</h1>
              <p class="subtitle">Monitoreo técnico de mensajes recibidos por Instagram, Messenger y los webhooks conectados al CRM.</p>
            </div>
            <?php nav_render_config_top_nav($pdo, 'webhook_logs.php', $filterAccountId, ['status' => $status], ['status' => $status]); ?>
          </header>

          <div class="event-metrics" aria-label="Resumen de eventos">
            <article class="event-metric" style="--tone:var(--event-cyan)"><span>Total eventos</span><strong><?= number_format($totalEvents, 0, ',', '.') ?></strong></article>
            <article class="event-metric" style="--tone:var(--event-green)"><span>Procesados</span><strong><?= number_format($statusTotals['processed'], 0, ',', '.') ?></strong></article>
            <article class="event-metric" style="--tone:var(--event-blue)"><span>Duplicados</span><strong><?= number_format($statusTotals['duplicate'], 0, ',', '.') ?></strong></article>
            <article class="event-metric" style="--tone:var(--event-amber)"><span>Ignorados</span><strong><?= number_format($statusTotals['ignored'], 0, ',', '.') ?></strong></article>
            <article class="event-metric" style="--tone:var(--event-red)"><span>Con error</span><strong><?= number_format($totalErrors, 0, ',', '.') ?></strong></article>
          </div>

          <div class="admin-layout">
            <?php nav_render_admin_side_nav('events'); ?>
            <div class="admin-content">
              <div class="event-toolbar">
                <form class="logs-filter" method="get" action="<?= h(account_url('webhook_logs.php')) ?>">
                  <?php if (is_super_admin()): ?>
                    <select name="account_id" onchange="this.form.submit()" aria-label="Filtrar por cuenta">
                      <option value="">Todas las cuentas</option>
                      <?php foreach ($accountOptions as $account): ?>
                        <option value="<?= (int) $account['id'] ?>" <?= $filterAccountId === (int) $account['id'] ? 'selected' : '' ?>><?= h((string) $account['name']) ?></option>
                      <?php endforeach; ?>
                    </select>
                  <?php endif; ?>
                  <button class="logs-btn <?= $status === '' ? 'is-active' : '' ?>" name="status" value="" type="submit">Todos</button>
                  <button class="logs-btn <?= $status === 'processed' ? 'is-active' : '' ?>" name="status" value="processed" type="submit">Procesados</button>
                  <button class="logs-btn <?= $status === 'duplicate' ? 'is-active' : '' ?>" name="status" value="duplicate" type="submit">Duplicados</button>
                  <button class="logs-btn <?= $status === 'lead_only' ? 'is-active' : '' ?>" name="status" value="lead_only" type="submit">Solo lead</button>
                  <button class="logs-btn <?= $status === 'ignored' ? 'is-active' : '' ?>" name="status" value="ignored" type="submit">Ignorados</button>
                </form>

                <form class="repair-form" method="post" action="<?= h(account_url('webhook_logs.php')) ?>">
                  <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf'] ?? '') ?>">
                  <input type="hidden" name="action" value="repair_missing_messages">
                  <?php if ($filterAccountId > 0): ?><input type="hidden" name="account_id" value="<?= (int) $filterAccountId ?>"><?php endif; ?>
                  <button class="logs-btn" type="submit">Reparar historial</button>
                </form>
              </div>

              <?php if ($notice !== ''): ?><div class="form-alert alert-info" style="margin-bottom:14px"><?= h($notice) ?></div><?php endif; ?>

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
                      <?php
                        $logStatus = (string) ($log['status'] ?? '');
                        $logStatusClass = preg_replace('/[^a-z0-9_-]/i', '_', $logStatus) ?: 'unknown';
                        $logError = trim((string) ($log['error_message'] ?? ''));
                      ?>
                      <tr class="status-<?= h($logStatusClass) ?>">
                        <td class="mono" data-label="ID">#<?= (int) $log['id'] ?></td>
                        <td data-label="Estado"><span class="log-badge <?= h(log_badge_class($logStatus)) ?>"><?= h(log_status_label($logStatus)) ?></span></td>
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
                        <td class="preview <?= $logError !== '' ? 'error-cell' : '' ?>" data-label="Error"><?= h($logError !== '' ? $logError : '—') ?></td>
                        <td data-label="Fecha"><?= h(app_datetime($log['created_at'] ?? '')) ?></td>
                      </tr>
                    <?php endforeach; else: ?>
                      <tr><td class="event-empty" data-label="Eventos" colspan="11">Todavía no hay eventos registrados.</td></tr>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>
  </main>
  <script src="js/navigation.js?v=<?= (int) @filemtime(__DIR__ . '/js/navigation.js') ?>" defer></script>
</body>
</html>
