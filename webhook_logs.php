<?php
// webhook_logs.php
declare(strict_types=1);
require_once __DIR__ . '/auth/require_auth.php';
require_once __DIR__ . '/config/conversations.php';
require_permission('manage_integrations');

conv_ensure_schema($pdo);

$logsTable = conv_webhook_logs_table();
$status = trim((string) ($_GET['status'] ?? ''));
$allowedStatuses = ['processed', 'lead_only', 'ignored'];
if ($status !== '' && !in_array($status, $allowedStatuses, true)) $status = '';

$where = $status !== '' ? 'WHERE status = :status' : '';
$stmt = $pdo->prepare("SELECT * FROM {$logsTable} {$where} ORDER BY id DESC LIMIT 150");
if ($status !== '') $stmt->bindValue(':status', $status);
$stmt->execute();
$logs = $stmt->fetchAll();

function log_badge_class(string $status): string {
  if ($status === 'processed') return 'ok';
  if ($status === 'lead_only') return 'warn';
  return 'muted';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover" />
  <title>Eventos de Instagram - Pixels Studio</title>
  <link rel="stylesheet" href="css/app.css">
  <style>
    :root { --container-w:min(98vw, 1360px); }
    .logs-header { display:flex; justify-content:space-between; align-items:flex-start; gap:16px; flex-wrap:wrap; margin-bottom:16px; }
    .logs-actions { display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
    .logs-link, .logs-btn { display:inline-flex; align-items:center; justify-content:center; min-height:40px; padding:0 14px; border:1px solid var(--line); border-radius:10px; color:#007ea8; background:var(--surface-soft); font-weight:850; text-decoration:none; cursor:pointer; }
    .logs-link:hover, .logs-btn:hover { background:#dff6ff; border-color:#8bdfff; }
    .logs-filter { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:14px; }
    .logs-table-wrap { overflow:auto; border:1px solid rgba(0,212,255,.14); border-radius:16px; background:#fff; }
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
<body class="dashboard-page">
  <main class="dashboard-shell">
    <section class="form-card dashboard-card">
      <div class="panel">
        <header class="logs-header">
          <div>
            <p class="eyebrow"><?= h(app_config('brand.name', 'Pixels Studio')) ?></p>
            <h1 class="title">Eventos de Instagram</h1>
            <p class="subtitle">Monitoreo tecnico de mensajes recibidos por el webhook.</p>
          </div>
          <div class="logs-actions">
            <a class="logs-link" href="inbox.php">Inbox</a>
            <a class="logs-link" href="channels.php">Canales</a>
            <a class="logs-link" href="dashboard.php">Dashboard</a>
          </div>
        </header>

        <form class="logs-filter" method="get" action="webhook_logs.php">
          <button class="logs-btn" name="status" value="" type="submit">Todos</button>
          <button class="logs-btn" name="status" value="processed" type="submit">Procesados</button>
          <button class="logs-btn" name="status" value="lead_only" type="submit">Solo lead</button>
          <button class="logs-btn" name="status" value="ignored" type="submit">Ignorados</button>
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
                  <td class="mono">#<?= (int) $log['id'] ?></td>
                  <td><span class="log-badge <?= h(log_badge_class((string) $log['status'])) ?>"><?= h((string) $log['status']) ?></span></td>
                  <td><?= h((string) ($log['event_type'] ?: '—')) ?></td>
                  <td><?= h((string) ($log['channel_username'] ?: '—')) ?><br><span class="mono"><?= $log['channel_id'] ? '#' . (int) $log['channel_id'] : '—' ?></span></td>
                  <td class="mono"><?= h((string) ($log['recipient_id'] ?: '—')) ?></td>
                  <td class="mono"><?= h((string) ($log['sender_id'] ?: '—')) ?></td>
                  <td class="preview"><?= h((string) ($log['message_preview'] ?: '—')) ?><br><span class="mono"><?= h((string) ($log['external_message_id'] ?: '—')) ?></span></td>
                  <td class="mono"><?= $log['lead_id'] ? '#' . (int) $log['lead_id'] : '—' ?></td>
                  <td class="mono"><?= $log['conversation_id'] ? '#' . (int) $log['conversation_id'] : '—' ?></td>
                  <td class="preview"><?= h((string) ($log['error_message'] ?: '—')) ?></td>
                  <td><?= h((string) $log['created_at']) ?></td>
                </tr>
              <?php endforeach; else: ?>
                <tr><td colspan="11">Todavia no hay eventos registrados.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </section>
  </main>
</body>
</html>
