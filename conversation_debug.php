<?php
// conversation_debug.php
declare(strict_types=1);
require_once __DIR__ . '/auth/require_auth.php';
require_once __DIR__ . '/config/conversations.php';
require_permission('manage_integrations');

conv_ensure_schema($pdo);

$conversationRouteId = trim((string) ($_GET['id'] ?? ''));
$requestAccountId = accounts_request_account_id($pdo);
$conversationId = $requestAccountId > 0
  ? conv_resolve_conversation_route_id($pdo, $requestAccountId, $conversationRouteId)
  : 0;
$conversationsTable = conv_conversations_table();
$contactsTable = conv_contacts_table();
$messagesTable = conv_messages_table();
$logsTable = conv_webhook_logs_table();
$currentAccountId = (int) (current_account_id() ?: accounts_default_id($pdo));

$conversation = null;
$messages = [];
$logs = [];

if ($conversationId > 0) {
  if (is_super_admin()) {
    $stmt = $pdo->prepare("SELECT c.*, ct.display_name, ct.username, ct.external_contact_id FROM {$conversationsTable} c JOIN {$contactsTable} ct ON ct.id=c.contact_id WHERE c.id=? LIMIT 1");
    $stmt->execute([$conversationId]);
  } else {
    $stmt = $pdo->prepare("SELECT c.*, ct.display_name, ct.username, ct.external_contact_id FROM {$conversationsTable} c JOIN {$contactsTable} ct ON ct.id=c.contact_id WHERE c.id=? AND c.account_id=? LIMIT 1");
    $stmt->execute([$conversationId, $currentAccountId]);
  }
  $conversation = $stmt->fetch() ?: null;

  if ($conversation) {
    if (is_super_admin()) {
      $msgStmt = $pdo->prepare("SELECT * FROM {$messagesTable} WHERE conversation_id=? ORDER BY sent_at ASC, id ASC");
      $msgStmt->execute([$conversationId]);

      $logStmt = $pdo->prepare("SELECT * FROM {$logsTable} WHERE conversation_id=? ORDER BY id DESC LIMIT 80");
      $logStmt->execute([$conversationId]);
    } else {
      $msgStmt = $pdo->prepare("SELECT * FROM {$messagesTable} WHERE conversation_id=? AND account_id=? ORDER BY sent_at ASC, id ASC");
      $msgStmt->execute([$conversationId, $currentAccountId]);

      $logStmt = $pdo->prepare("SELECT * FROM {$logsTable} WHERE conversation_id=? AND account_id=? ORDER BY id DESC LIMIT 80");
      $logStmt->execute([$conversationId, $currentAccountId]);
    }
    $messages = $msgStmt->fetchAll();
    $logs = $logStmt->fetchAll();
  }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover" />
  <title>Diagnóstico de conversación - Pixels Studio</title>
  <link rel="stylesheet" href="css/app.css?v=<?= (int) @filemtime(__DIR__ . '/css/app.css') ?>">
  <style>
    :root { --container-w:min(98vw, 1320px); }
    .debug-header { display:flex; justify-content:space-between; gap:16px; flex-wrap:wrap; margin-bottom:16px; }
    .debug-actions { display:flex; gap:10px; flex-wrap:wrap; }
    .debug-link { display:inline-flex; align-items:center; justify-content:center; min-height:40px; padding:0 14px; border:1px solid var(--line); border-radius:10px; color:#007ea8; background:var(--surface-soft); font-weight:850; text-decoration:none; }
    .debug-grid { display:grid; grid-template-columns:1fr 1fr; gap:14px; align-items:start; }
    .debug-box { max-width:100%; border:1px solid rgba(0,212,255,.14); border-radius:16px; background:#fff; padding:16px; overflow-x:auto; -webkit-overflow-scrolling:touch; }
    .debug-box h2 { margin:0 0 12px; color:#071120; font-size:1.05rem; }
    table { width:100%; border-collapse:collapse; font-size:.86rem; min-width:720px; }
    th { background:#071120; color:#eafaff; text-align:left; padding:10px; white-space:nowrap; }
    td { padding:10px; border-bottom:1px solid rgba(0,68,99,.10); vertical-align:top; }
    .mono { font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace; font-size:.78rem; }
    .preview { max-width:360px; overflow-wrap:anywhere; }
    @media (max-width: 980px) { .debug-grid { grid-template-columns:1fr; } }
  </style>
</head>
<body class="dashboard-page">
  <main class="dashboard-shell">
    <section class="form-card dashboard-card">
      <div class="panel">
        <header class="debug-header">
          <div>
            <p class="eyebrow"><?= h(app_config('brand.name', 'Pixels Studio')) ?></p>
            <h1 class="title">Diagnóstico de conversación #<?= h($conversationRouteId !== '' ? $conversationRouteId : (string) $conversationId) ?></h1>
            <p class="subtitle">Compara eventos recibidos vs mensajes guardados en el historial.</p>
          </div>
          <div class="debug-actions">
            <a class="debug-link" href="<?= h(account_url('webhook_logs.php')) ?>">Eventos</a>
            <a class="debug-link" href="<?= h(account_url('inbox.php', ['id' => $conversationRouteId !== '' ? $conversationRouteId : conv_route_id($conversation ?: [])])) ?>">Inbox</a>
          </div>
        </header>

        <?php if (!$conversation): ?>
          <div class="form-alert alert-error">No se encontró la conversación solicitada.</div>
        <?php else: ?>
          <div class="debug-box" style="margin-bottom:14px">
            <h2>Resumen</h2>
            <p><strong>Contacto:</strong> <?= h((string) ($conversation['display_name'] ?: $conversation['username'] ?: 'Contacto')) ?></p>
            <p><strong>External thread:</strong> <span class="mono"><?= h((string) $conversation['external_thread_id']) ?></span></p>
            <p><strong>Último preview:</strong> <?= h((string) ($conversation['last_message_preview'] ?: '—')) ?></p>
            <p><strong>Último mensaje:</strong> <?= h(app_datetime($conversation['last_message_at'] ?? '', 'd/m/Y H:i', '—')) ?></p>
          </div>

          <div class="debug-grid">
            <section class="debug-box">
              <h2>Mensajes guardados</h2>
              <table>
                <thead><tr><th>ID</th><th>Dirección</th><th>External ID</th><th>Texto</th><th>Fecha</th></tr></thead>
                <tbody>
                  <?php if ($messages): foreach ($messages as $message): ?>
                    <tr>
                      <td class="mono">#<?= (int) $message['id'] ?></td>
                      <td><?= h((string) $message['direction']) ?></td>
                      <td class="mono"><?= h((string) ($message['external_message_id'] ?: '—')) ?></td>
                      <td class="preview"><?= h((string) ($message['message_text'] ?: '—')) ?></td>
                      <td><?= h(app_datetime($message['sent_at'] ?? '', 'd/m/Y H:i', '—')) ?></td>
                    </tr>
                  <?php endforeach; else: ?>
                    <tr><td colspan="5">No hay mensajes guardados.</td></tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </section>

            <section class="debug-box">
              <h2>Eventos del webhook</h2>
              <table>
                <thead><tr><th>ID</th><th>Estado</th><th>External ID</th><th>Preview</th><th>Error</th><th>Fecha</th></tr></thead>
                <tbody>
                  <?php if ($logs): foreach ($logs as $log): ?>
                    <tr>
                      <td class="mono">#<?= (int) $log['id'] ?></td>
                      <td><?= h((string) $log['status']) ?></td>
                      <td class="mono"><?= h((string) ($log['external_message_id'] ?: '—')) ?></td>
                      <td class="preview"><?= h((string) ($log['message_preview'] ?: '—')) ?></td>
                      <td class="preview"><?= h((string) ($log['error_message'] ?: '—')) ?></td>
                      <td><?= h(app_datetime($log['created_at'] ?? '', 'd/m/Y H:i', '—')) ?></td>
                    </tr>
                  <?php endforeach; else: ?>
                    <tr><td colspan="6">No hay logs asociados a esta conversación.</td></tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </section>
          </div>
        <?php endif; ?>
      </div>
    </section>
  </main>
</body>
</html>
