<?php
// meta_data_deletion.php
declare(strict_types=1);
require_once __DIR__ . '/auth/require_auth.php';
require_once __DIR__ . '/config/conversations.php';
require_once __DIR__ . '/config/lead_status_history.php';
require_once __DIR__ . '/config/navigation.php';
require_permission('manage_accounts');

if (!is_super_admin()) {
  http_response_code(403);
  exit('Acceso denegado.');
}

conv_ensure_schema($pdo);
lead_status_history_ensure_schema($pdo);

$confirmPhrase = 'ELIMINAR DATOS META';
$notice = '';
$errors = [];
$ids = [];
$plan = null;
$deleted = null;

function meta_delete_table_exists(PDO $pdo, string $table): bool {
  global $DB_NAME;
  $stmt = $pdo->prepare('SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?');
  $stmt->execute([(string) $DB_NAME, $table]);
  return (bool) $stmt->fetchColumn();
}

function meta_delete_column_exists(PDO $pdo, string $table, string $column): bool {
  global $DB_NAME;
  $stmt = $pdo->prepare('SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?');
  $stmt->execute([(string) $DB_NAME, $table, $column]);
  return (bool) $stmt->fetchColumn();
}

function meta_delete_parse_ids(string $text): array {
  $text = preg_replace('/^\xEF\xBB\xBF/', '', $text) ?? $text;
  $tokens = preg_split('/[^a-zA-Z0-9._:-]+/', $text) ?: [];
  $ids = [];
  foreach ($tokens as $token) {
    $token = trim($token);
    if ($token === '' || mb_strlen($token) < 3 || mb_strlen($token) > 180) continue;
    $ids[$token] = true;
  }
  return array_keys($ids);
}

function meta_delete_uploaded_text(): string {
  if (!isset($_FILES['ids_file']) || !is_array($_FILES['ids_file'])) return '';
  if ((int) ($_FILES['ids_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return '';
  $tmp = (string) ($_FILES['ids_file']['tmp_name'] ?? '');
  if ($tmp === '' || !is_uploaded_file($tmp)) return '';
  $size = (int) ($_FILES['ids_file']['size'] ?? 0);
  if ($size > 1024 * 1024) return '';
  return (string) file_get_contents($tmp);
}

function meta_delete_placeholders(array $values): string {
  return implode(',', array_fill(0, count($values), '?'));
}

function meta_delete_fetch_ids(PDO $pdo, string $table, array $clauses, array $params): array {
  if (!$clauses || !meta_delete_table_exists($pdo, $table)) return [];
  $sql = "SELECT id FROM {$table} WHERE " . implode(' OR ', array_map(static fn($clause) => "({$clause})", $clauses));
  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
  return array_values(array_unique(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [])));
}

function meta_delete_exact_clause(string $table, string $column, array $ids, array &$clauses, array &$params): void {
  if (!$ids || !meta_delete_column_exists($GLOBALS['pdo'], $table, $column)) return;
  $clauses[] = "{$column} IN (" . meta_delete_placeholders($ids) . ')';
  array_push($params, ...$ids);
}

function meta_delete_like_clause(string $table, string $column, array $ids, array &$clauses, array &$params): void {
  if (!$ids || !meta_delete_column_exists($GLOBALS['pdo'], $table, $column)) return;
  foreach ($ids as $id) {
    $clauses[] = "{$column} LIKE ?";
    $params[] = '%' . $id . '%';
  }
}

function meta_delete_thread_clause(string $table, string $column, array $ids, array &$clauses, array &$params): void {
  if (!$ids || !meta_delete_column_exists($GLOBALS['pdo'], $table, $column)) return;
  foreach ($ids as $id) {
    $clauses[] = "{$column} = ? OR {$column} LIKE ? OR {$column} LIKE ?";
    array_push($params, $id, $id . ':%', '%:' . $id);
  }
}

function meta_delete_in_ids_clause(string $table, string $column, array $values, array &$clauses, array &$params): void {
  $values = array_values(array_unique(array_filter(array_map('intval', $values), static fn($value) => $value > 0)));
  if (!$values || !meta_delete_column_exists($GLOBALS['pdo'], $table, $column)) return;
  $clauses[] = "{$column} IN (" . meta_delete_placeholders($values) . ')';
  array_push($params, ...$values);
}

function meta_delete_build_plan(PDO $pdo, array $ids): array {
  $tables = [
    'channels' => ig_channels_table(),
    'contacts' => conv_contacts_table(),
    'conversations' => conv_conversations_table(),
    'messages' => conv_messages_table(),
    'attachments' => conv_attachments_table(),
    'logs' => conv_webhook_logs_table(),
    'leads' => safe_identifier((string) app_config('database.leads_table', 'leads'), 'leads'),
    'history' => lead_status_history_table(),
  ];

  $channelClauses = $channelParams = [];
  foreach (['page_id', 'instagram_user_id', 'whatsapp_business_account_id', 'whatsapp_phone_number_id'] as $column) {
    meta_delete_exact_clause($tables['channels'], $column, $ids, $channelClauses, $channelParams);
  }
  $channelIds = meta_delete_fetch_ids($pdo, $tables['channels'], $channelClauses, $channelParams);

  $contactClauses = $contactParams = [];
  foreach (['external_contact_id', 'username'] as $column) {
    meta_delete_exact_clause($tables['contacts'], $column, $ids, $contactClauses, $contactParams);
  }
  foreach (['profile_url', 'avatar_url'] as $column) {
    meta_delete_like_clause($tables['contacts'], $column, $ids, $contactClauses, $contactParams);
  }
  $contactIds = meta_delete_fetch_ids($pdo, $tables['contacts'], $contactClauses, $contactParams);

  $leadClauses = $leadParams = [];
  foreach (['external_contact_id', 'brand_instagram', 'phone'] as $column) {
    meta_delete_exact_clause($tables['leads'], $column, $ids, $leadClauses, $leadParams);
  }
  foreach (['external_thread_id'] as $column) {
    meta_delete_thread_clause($tables['leads'], $column, $ids, $leadClauses, $leadParams);
  }
  foreach (['last_external_message_id', 'ad_referral_payload', 'landing_url', 'referrer', 'user_agent'] as $column) {
    meta_delete_like_clause($tables['leads'], $column, $ids, $leadClauses, $leadParams);
  }
  $leadIds = meta_delete_fetch_ids($pdo, $tables['leads'], $leadClauses, $leadParams);

  $conversationClauses = $conversationParams = [];
  meta_delete_in_ids_clause($tables['conversations'], 'contact_id', $contactIds, $conversationClauses, $conversationParams);
  meta_delete_in_ids_clause($tables['conversations'], 'lead_id', $leadIds, $conversationClauses, $conversationParams);
  meta_delete_in_ids_clause($tables['conversations'], 'channel_id', $channelIds, $conversationClauses, $conversationParams);
  meta_delete_thread_clause($tables['conversations'], 'external_thread_id', $ids, $conversationClauses, $conversationParams);
  $conversationIds = meta_delete_fetch_ids($pdo, $tables['conversations'], $conversationClauses, $conversationParams);

  $messageClauses = $messageParams = [];
  meta_delete_in_ids_clause($tables['messages'], 'conversation_id', $conversationIds, $messageClauses, $messageParams);
  meta_delete_exact_clause($tables['messages'], 'sender_external_id', $ids, $messageClauses, $messageParams);
  foreach (['external_message_id', 'payload_json'] as $column) {
    meta_delete_like_clause($tables['messages'], $column, $ids, $messageClauses, $messageParams);
  }
  $messageIds = meta_delete_fetch_ids($pdo, $tables['messages'], $messageClauses, $messageParams);

  $attachmentClauses = $attachmentParams = [];
  meta_delete_in_ids_clause($tables['attachments'], 'conversation_id', $conversationIds, $attachmentClauses, $attachmentParams);
  meta_delete_in_ids_clause($tables['attachments'], 'message_id', $messageIds, $attachmentClauses, $attachmentParams);
  meta_delete_exact_clause($tables['attachments'], 'external_attachment_id', $ids, $attachmentClauses, $attachmentParams);
  meta_delete_like_clause($tables['attachments'], 'original_url', $ids, $attachmentClauses, $attachmentParams);
  $attachmentIds = meta_delete_fetch_ids($pdo, $tables['attachments'], $attachmentClauses, $attachmentParams);

  $logClauses = $logParams = [];
  foreach (['recipient_id', 'sender_id'] as $column) {
    meta_delete_exact_clause($tables['logs'], $column, $ids, $logClauses, $logParams);
  }
  meta_delete_in_ids_clause($tables['logs'], 'channel_id', $channelIds, $logClauses, $logParams);
  meta_delete_in_ids_clause($tables['logs'], 'lead_id', $leadIds, $logClauses, $logParams);
  meta_delete_in_ids_clause($tables['logs'], 'conversation_id', $conversationIds, $logClauses, $logParams);
  foreach (['external_message_id', 'payload_json'] as $column) {
    meta_delete_like_clause($tables['logs'], $column, $ids, $logClauses, $logParams);
  }
  $logIds = meta_delete_fetch_ids($pdo, $tables['logs'], $logClauses, $logParams);

  $historyClauses = $historyParams = [];
  meta_delete_in_ids_clause($tables['history'], 'lead_id', $leadIds, $historyClauses, $historyParams);
  $historyIds = meta_delete_fetch_ids($pdo, $tables['history'], $historyClauses, $historyParams);

  return [
    'tables' => $tables,
    'ids' => $ids,
    'matches' => [
      'Canales conectados' => ['table' => $tables['channels'], 'ids' => $channelIds],
      'Contactos conversacionales' => ['table' => $tables['contacts'], 'ids' => $contactIds],
      'Conversaciones' => ['table' => $tables['conversations'], 'ids' => $conversationIds],
      'Mensajes' => ['table' => $tables['messages'], 'ids' => $messageIds],
      'Adjuntos' => ['table' => $tables['attachments'], 'ids' => $attachmentIds],
      'Eventos webhook' => ['table' => $tables['logs'], 'ids' => $logIds],
      'Leads' => ['table' => $tables['leads'], 'ids' => $leadIds],
      'Historial de status' => ['table' => $tables['history'], 'ids' => $historyIds],
    ],
  ];
}

function meta_delete_by_ids(PDO $pdo, string $table, array $ids): int {
  $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn($id) => $id > 0)));
  if (!$ids || !meta_delete_table_exists($pdo, $table)) return 0;
  $stmt = $pdo->prepare("DELETE FROM {$table} WHERE id IN (" . meta_delete_placeholders($ids) . ')');
  $stmt->execute($ids);
  return $stmt->rowCount();
}

function meta_delete_execute(PDO $pdo, array $plan): array {
  $matches = $plan['matches'];
  $order = [
    'Adjuntos',
    'Mensajes',
    'Eventos webhook',
    'Historial de status',
    'Conversaciones',
    'Contactos conversacionales',
    'Leads',
    'Canales conectados',
  ];
  $deleted = [];
  $pdo->beginTransaction();
  try {
    foreach ($order as $label) {
      $deleted[$label] = meta_delete_by_ids($pdo, (string) ($matches[$label]['table'] ?? ''), (array) ($matches[$label]['ids'] ?? []));
    }
    $pdo->commit();
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
  }
  return $deleted;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $csrf = (string) ($_POST['csrf'] ?? '');
  if (!$csrf || !isset($_SESSION['csrf']) || !hash_equals((string) $_SESSION['csrf'], $csrf)) {
    $errors[] = 'CSRF inválido. Recarga la página.';
  } else {
    $text = trim((string) ($_POST['ids_text'] ?? '') . "\n" . meta_delete_uploaded_text());
    $ids = meta_delete_parse_ids($text);
    if (!$ids) {
      $errors[] = 'No encontré identificadores válidos en el texto o archivo.';
    } else {
      try {
        $plan = meta_delete_build_plan($pdo, $ids);
        if ((string) ($_POST['action'] ?? '') === 'delete') {
          if ((string) ($_POST['confirm_phrase'] ?? '') !== $confirmPhrase) {
            $errors[] = 'Para eliminar debes escribir exactamente: ' . $confirmPhrase;
          } else {
            $deleted = meta_delete_execute($pdo, $plan);
            $notice = 'Eliminación completada. Registros eliminados: ' . array_sum($deleted) . '.';
            $plan = meta_delete_build_plan($pdo, $ids);
          }
        }
      } catch (Throwable $e) {
        $errors[] = 'No se pudo procesar la solicitud: ' . $e->getMessage();
      }
    }
  }
}

$totalMatches = 0;
if ($plan) {
  foreach ($plan['matches'] as $row) $totalMatches += count((array) ($row['ids'] ?? []));
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover" />
  <title>Eliminación Meta - Pixels Studio</title>
  <link rel="stylesheet" href="css/app.css?v=<?= (int) @filemtime(__DIR__ . '/css/app.css') ?>">
  <style>
    .meta-deletion-page .dashboard-card,
    .meta-deletion-page .panel,
    .meta-deletion-page .admin-layout,
    .meta-deletion-page .admin-content { overflow:visible; }
    .privacy-header { position:relative; z-index:20; display:flex; align-items:flex-start; justify-content:space-between; gap:18px; flex-wrap:wrap; margin-bottom:22px; }
    .privacy-header .title { margin:0; max-width:840px; }
    .privacy-header .subtitle { max-width:860px; margin-top:8px; }
    .privacy-content { min-width:0; display:grid; gap:18px; }
    .privacy-intro {
      display:grid;
      grid-template-columns:minmax(0, 1fr) auto;
      gap:18px;
      align-items:center;
      border:1px solid var(--config-line);
      border-radius:28px;
      background:#fff;
      padding:22px;
      box-shadow:0 18px 42px rgba(15,23,42,.05);
    }
    .privacy-intro strong { display:block; color:var(--brand-ink); font-size:1.1rem; font-weight:950; letter-spacing:-.02em; }
    .privacy-intro span { display:block; margin-top:6px; color:var(--brand-muted); font-weight:760; line-height:1.42; }
    .privacy-intro-badge {
      display:inline-grid;
      place-items:center;
      min-height:46px;
      padding:0 16px;
      border-radius:999px;
      background:var(--brand-ink);
      color:#fff;
      font-weight:950;
      white-space:nowrap;
    }
    .privacy-grid { display:grid; grid-template-columns:minmax(360px, .72fr) minmax(540px, 1.28fr); gap:18px; align-items:start; }
    .privacy-card { border:1px solid var(--config-line); border-radius:28px; background:#fff; padding:clamp(18px, 1.7vw, 28px); box-shadow:0 18px 42px rgba(15,23,42,.05); }
    .privacy-card h2 { margin:0 0 8px; color:var(--brand-ink); font-size:1.22rem; letter-spacing:-.02em; }
    .privacy-card p { margin:0 0 18px; color:var(--brand-muted); font-weight:760; line-height:1.45; }
    .privacy-form { display:grid; gap:16px; }
    .privacy-form label { display:grid; gap:8px; min-width:0; }
    .privacy-form textarea,
    .privacy-form input[type="file"],
    .privacy-form input[type="text"] {
      width:100%;
      min-width:0;
      border:1px solid var(--line);
      border-radius:16px;
      background:#fff;
      color:var(--brand-ink);
      font:inherit;
      font-weight:800;
      outline:none;
    }
    .privacy-form textarea { min-height:160px; padding:14px; resize:vertical; }
    .privacy-form input[type="file"] { min-height:64px; height:auto; padding:15px; line-height:1.35; }
    .privacy-form input[type="file"]::file-selector-button {
      min-height:36px;
      margin-right:12px;
      border:1px solid var(--config-line);
      border-radius:999px;
      background:#f8fafc;
      color:var(--brand-ink);
      font:inherit;
      font-weight:900;
      cursor:pointer;
    }
    .privacy-form input[type="text"] { min-height:52px; padding:12px 14px; }
    .privacy-actions { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
    .privacy-btn {
      border:1px solid var(--brand-ink);
      border-radius:16px;
      min-height:48px;
      padding:0 18px;
      background:var(--brand-ink);
      color:#fff;
      font:inherit;
      font-weight:950;
      cursor:pointer;
      text-decoration:none;
    }
    .privacy-btn:hover { background:#fff; color:var(--brand-ink); }
    .privacy-btn-danger { background:#fff3f5; color:#be123c; border-color:#fecdd3; }
    .privacy-btn-danger:hover { background:#be123c; color:#fff; border-color:#be123c; }
    .privacy-alert { border:1px solid #bae6fd; background:#f0f9ff; color:#075985; border-radius:16px; padding:14px; font-weight:850; line-height:1.45; margin-bottom:14px; }
    .privacy-alert.error { border-color:#fecdd3; background:#fff1f2; color:#9f1239; }
    .privacy-summary { display:grid; grid-template-columns:repeat(3, minmax(0, 1fr)); gap:12px; margin-bottom:16px; }
    .privacy-stat { border:1px solid var(--line); border-radius:18px; padding:16px; background:#f8fbff; }
    .privacy-stat strong { display:block; color:var(--brand-ink); font-size:1.8rem; line-height:1; }
    .privacy-stat span { display:block; margin-top:8px; color:var(--brand-muted); font-weight:900; text-transform:uppercase; letter-spacing:.08em; font-size:.78rem; }
    .privacy-table-wrap { width:100%; overflow:auto; border:1px solid var(--line); border-radius:18px; }
    .privacy-table { width:100%; min-width:720px; border-collapse:collapse; color:var(--brand-ink); }
    .privacy-table th { background:var(--brand-ink); color:#fff; text-align:left; padding:14px; font-size:.78rem; text-transform:uppercase; letter-spacing:.12em; }
    .privacy-table td { border-top:1px solid var(--line); padding:14px; font-weight:800; vertical-align:top; }
    .privacy-ids { max-width:520px; color:var(--brand-muted); overflow-wrap:anywhere; }
    .privacy-muted { color:var(--brand-muted); font-weight:850; }
    @media (max-width: 1180px) {
      .privacy-grid { grid-template-columns:1fr; }
      .privacy-intro { grid-template-columns:1fr; }
      .privacy-intro-badge { width:max-content; }
    }
    @media (max-width: 760px) {
      .privacy-header { align-items:flex-start; }
      .privacy-card,
      .privacy-intro { border-radius:22px; }
      .privacy-summary { grid-template-columns:1fr; }
    }
  </style>
</head>
<body class="dashboard-page config-page meta-deletion-page">
  <main class="dashboard-shell">
    <section class="form-card dashboard-card">
      <div class="panel">
      <header class="privacy-header">
        <div>
          <p class="eyebrow"><?= h(app_config('brand.name', 'Pixels Studio')) ?></p>
          <h1 class="title">Eliminación de datos Meta</h1>
          <p class="subtitle">Carga el CSV de Meta, audita coincidencias y elimina datos asociados a identificadores solicitados.</p>
        </div>
        <?php nav_render_config_top_nav($pdo, 'dashboard.php'); ?>
      </header>

      <div class="admin-layout">
        <?php nav_render_admin_side_nav('meta_deletion'); ?>
        <div class="admin-content privacy-content">
          <section class="privacy-intro" aria-label="Flujo de eliminación">
            <div>
              <strong>Auditoría primero, eliminación solo con confirmación.</strong>
              <span>El archivo no se guarda en el servidor. El sistema muestra coincidencias por área antes de permitir una eliminación definitiva.</span>
            </div>
            <span class="privacy-intro-badge">Meta compliance</span>
          </section>

          <div class="privacy-grid">
          <section class="privacy-card">
            <h2>Archivo de Meta</h2>
            <p>Sube el CSV descargado desde App Manager o pega los IDs manualmente. El archivo no se guarda en el servidor.</p>
            <?php if ($notice !== ''): ?><div class="privacy-alert"><?= h($notice) ?></div><?php endif; ?>
            <?php foreach ($errors as $error): ?><div class="privacy-alert error"><?= h($error) ?></div><?php endforeach; ?>
            <form class="privacy-form" method="post" enctype="multipart/form-data">
              <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf'] ?? '') ?>">
              <label>
                <span class="privacy-muted">CSV de identificadores</span>
                <input type="file" name="ids_file" accept=".csv,text/csv,text/plain">
              </label>
              <label>
                <span class="privacy-muted">IDs manuales</span>
                <textarea name="ids_text" placeholder="Pega aquí los identificadores, uno por línea"><?= h(implode("\n", $ids)) ?></textarea>
              </label>
              <div class="privacy-actions">
                <button class="privacy-btn" type="submit" name="action" value="audit">Auditar coincidencias</button>
              </div>
            </form>
          </section>

          <section class="privacy-card">
            <h2>Resultado de auditoría</h2>
            <?php if (!$plan): ?>
              <p>Primero carga el archivo para saber si existen registros asociados en el CRM.</p>
            <?php else: ?>
              <div class="privacy-summary">
                <div class="privacy-stat"><strong><?= count($ids) ?></strong><span>IDs revisados</span></div>
                <div class="privacy-stat"><strong><?= $totalMatches ?></strong><span>Registros encontrados</span></div>
                <div class="privacy-stat"><strong><?= $deleted ? array_sum($deleted) : 0 ?></strong><span>Eliminados</span></div>
              </div>
              <div class="privacy-table-wrap">
                <table class="privacy-table">
                  <thead>
                    <tr>
                      <th>Área</th>
                      <th>Tabla</th>
                      <th>Coincidencias</th>
                      <th>IDs internos</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($plan['matches'] as $label => $row): ?>
                      <?php $rowIds = (array) ($row['ids'] ?? []); ?>
                      <tr>
                        <td><?= h($label) ?></td>
                        <td class="privacy-muted"><?= h((string) ($row['table'] ?? '')) ?></td>
                        <td><?= count($rowIds) ?></td>
                        <td class="privacy-ids"><?= h($rowIds ? implode(', ', $rowIds) : '—') ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
              <form class="privacy-form" method="post" enctype="multipart/form-data" style="margin-top:16px">
                <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf'] ?? '') ?>">
                <textarea name="ids_text" hidden><?= h(implode("\n", $ids)) ?></textarea>
                <label>
                  <span class="privacy-muted">Confirmación requerida</span>
                  <input type="text" name="confirm_phrase" placeholder="<?= h($confirmPhrase) ?>">
                </label>
                <div class="privacy-actions">
                  <button class="privacy-btn privacy-btn-danger" type="submit" name="action" value="delete">Eliminar datos encontrados</button>
                  <span class="privacy-muted">Esta acción borra conversaciones, leads, mensajes, adjuntos, logs y canales vinculados a esos IDs.</span>
                </div>
              </form>
            <?php endif; ?>
          </section>
          </div>
        </div>
      </div>
      </div>
    </section>
  </main>
  <script>
    document.querySelectorAll('[data-menu]').forEach((menu) => {
      const trigger = menu.querySelector('[data-menu-trigger]');
      if (!trigger) return;
      trigger.addEventListener('click', (event) => {
        event.stopPropagation();
        document.querySelectorAll('[data-menu].is-open').forEach((openMenu) => {
          if (openMenu !== menu) openMenu.classList.remove('is-open');
        });
        menu.classList.toggle('is-open');
      });
    });
    document.addEventListener('click', () => {
      document.querySelectorAll('[data-menu].is-open').forEach((menu) => menu.classList.remove('is-open'));
    });
  </script>
</body>
</html>
