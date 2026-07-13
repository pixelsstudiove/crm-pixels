<?php
// accounts.php
declare(strict_types=1);
require_once __DIR__ . '/auth/require_auth.php';
require_once __DIR__ . '/config/instagram_channels.php';
require_once __DIR__ . '/config/navigation.php';
require_permission('manage_accounts');

$accountsTable = accounts_table();
$usersTable = safe_identifier((string) app_config('database.users_table', 'users'), 'users');
$channelsTable = ig_channels_table();
$errors = [];
$notice = '';
$statusOptions = [
  'active' => 'Activa',
  'suspended' => 'Suspendida',
];

function account_slug_from_name(string $name): string {
  $slug = strtolower(trim($name));
  $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
  $slug = trim($slug, '-');
  return $slug !== '' ? mb_substr($slug, 0, 80) : 'cuenta';
}

function account_validate_payload(array &$errors, string $name, string $slug, string $status, array $statusOptions): void {
  if (mb_strlen($name) < 3 || mb_strlen($name) > 160) $errors[] = 'El nombre debe tener entre 3 y 160 caracteres.';
  if (!preg_match('/^[a-z0-9-]{3,80}$/', $slug)) $errors[] = 'El slug debe tener entre 3 y 80 caracteres, solo minúsculas, números y guiones.';
  if (!array_key_exists($status, $statusOptions)) $errors[] = 'Selecciona un estado válido.';
}

function account_exists(PDO $pdo, string $accountsTable, int $accountId): bool {
  if ($accountId <= 0) return false;
  $stmt = $pdo->prepare("SELECT id FROM {$accountsTable} WHERE id=? LIMIT 1");
  $stmt->execute([$accountId]);
  return (bool) $stmt->fetchColumn();
}

function account_slug_is_used(PDO $pdo, string $accountsTable, string $slug, int $ignoreAccountId = 0): bool {
  $stmt = $ignoreAccountId > 0
    ? $pdo->prepare("SELECT id FROM {$accountsTable} WHERE slug=? AND id<>? LIMIT 1")
    : $pdo->prepare("SELECT id FROM {$accountsTable} WHERE slug=? LIMIT 1");
  $ignoreAccountId > 0 ? $stmt->execute([$slug, $ignoreAccountId]) : $stmt->execute([$slug]);
  return (bool) $stmt->fetchColumn();
}

function account_name_is_used(PDO $pdo, string $accountsTable, string $name): bool {
  $stmt = $pdo->prepare("SELECT id FROM {$accountsTable} WHERE LOWER(name)=LOWER(?) LIMIT 1");
  $stmt->execute([$name]);
  return (bool) $stmt->fetchColumn();
}

function account_delete_table(PDO $pdo, string $dbName, string $table, int $accountId): void {
  if (!account_column_exists($pdo, $dbName, $table, 'account_id')) return;
  $stmt = $pdo->prepare("DELETE FROM {$table} WHERE account_id=?");
  $stmt->execute([$accountId]);
}

function account_delete_all_data(PDO $pdo, string $dbName, string $accountsTable, int $accountId): void {
  $tables = [
    safe_identifier((string) app_config('database.lead_status_history_table', 'lead_status_history'), 'lead_status_history'),
    safe_identifier((string) app_config('database.conversation_attachments_table', 'conversation_attachments'), 'conversation_attachments'),
    safe_identifier((string) app_config('database.conversation_messages_table', 'conversation_messages'), 'conversation_messages'),
    safe_identifier((string) app_config('database.webhook_event_logs_table', 'webhook_event_logs'), 'webhook_event_logs'),
    safe_identifier((string) app_config('database.conversations_table', 'conversations'), 'conversations'),
    safe_identifier((string) app_config('database.conversation_contacts_table', 'conversation_contacts'), 'conversation_contacts'),
    safe_identifier((string) app_config('database.instagram_channels_table', 'instagram_channels'), 'instagram_channels'),
    safe_identifier((string) app_config('database.leads_table', 'leads'), 'leads'),
    safe_identifier((string) app_config('database.users_table', 'users'), 'users'),
  ];

  $pdo->beginTransaction();
  try {
    foreach ($tables as $table) account_delete_table($pdo, $dbName, $table, $accountId);
    $stmt = $pdo->prepare("DELETE FROM {$accountsTable} WHERE id=?");
    $stmt->execute([$accountId]);
    $pdo->commit();
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $csrf = (string) ($_POST['csrf'] ?? '');
  if (!$csrf || !isset($_SESSION['csrf']) || !hash_equals((string) $_SESSION['csrf'], $csrf)) {
    $errors[] = 'CSRF inválido. Recarga la página.';
  } else {
    if (isset($_POST['create_account_submit'])) {
      $name = trim((string) ($_POST['create_name'] ?? ''));
      $rawSlug = (string) ($_POST['create_slug'] ?? $name);
      $slug = account_slug_from_name($rawSlug);
      $status = (string) ($_POST['create_status'] ?? 'active');
      account_validate_payload($errors, $name, $slug, $status, $statusOptions);
      if (!$errors && account_name_is_used($pdo, $accountsTable, $name)) $errors[] = 'Ya existe una cuenta con ese nombre.';
      if (!$errors && account_slug_is_used($pdo, $accountsTable, $slug)) $errors[] = 'Ese slug ya está siendo usado por otra cuenta.';

      if (!$errors) {
        try {
          $stmt = $pdo->prepare("INSERT INTO {$accountsTable} (name, slug, status) VALUES (?, ?, ?)");
          $stmt->execute([$name, $slug, $status]);
          $notice = 'Cuenta creada correctamente.';
        } catch (Throwable $e) {
          $errors[] = 'No se pudo crear la cuenta.';
        }
      }
    } elseif (isset($_POST['update_account_submit'])) {
      $accountId = max(0, (int) ($_POST['update_id'] ?? 0));
      $name = trim((string) ($_POST['update_name'] ?? ''));
      $rawSlug = (string) ($_POST['update_slug'] ?? $name);
      $slug = account_slug_from_name($rawSlug);
      $status = (string) ($_POST['update_status'] ?? 'active');
      account_validate_payload($errors, $name, $slug, $status, $statusOptions);
      if (!$errors && !account_exists($pdo, $accountsTable, $accountId)) $errors[] = 'La cuenta que intentas actualizar no existe.';
      if (!$errors && account_slug_is_used($pdo, $accountsTable, $slug, $accountId)) $errors[] = 'Ese slug ya está siendo usado por otra cuenta.';

      if (!$errors) {
        try {
          $stmt = $pdo->prepare("UPDATE {$accountsTable} SET name=?, slug=?, status=?, updated_at=NOW() WHERE id=?");
          $stmt->execute([$name, $slug, $status, $accountId]);
          $notice = 'Cuenta actualizada correctamente.';
        } catch (Throwable $e) {
          $errors[] = 'No se pudo actualizar la cuenta.';
        }
      }
    } elseif (isset($_POST['delete_account_submit'])) {
      $accountId = max(0, (int) ($_POST['delete_id'] ?? 0));
      if (!account_exists($pdo, $accountsTable, $accountId)) $errors[] = 'La cuenta que intentas eliminar no existe.';
      if ($accountId === current_account_id()) $errors[] = 'No puedes eliminar la cuenta con la que iniciaste sesión.';

      if (!$errors) {
        try {
          account_delete_all_data($pdo, (string) $DB_NAME, $accountsTable, $accountId);
          $notice = 'Cuenta eliminada correctamente junto con sus datos relacionados.';
        } catch (Throwable $e) {
          $errors[] = 'No se pudo eliminar la cuenta y sus datos relacionados.';
        }
      }
    } else {
      $errors[] = 'Acción inválida. Usa el panel de Nueva cuenta para crear o el botón Guardar para actualizar una cuenta existente.';
    }
  }
}

$accounts = [];
try {
  $stmt = $pdo->query(<<<SQL
SELECT
  a.*,
  (SELECT COUNT(*) FROM {$usersTable} u WHERE u.account_id = a.id) AS users_total,
  (SELECT COUNT(*) FROM {$channelsTable} ch WHERE ch.account_id = a.id) AS channels_total
FROM {$accountsTable} a
ORDER BY a.id ASC
SQL);
  $accounts = $stmt ? $stmt->fetchAll() : [];
} catch (Throwable $e) {
  $errors[] = 'No se pudo cargar la lista de cuentas.';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover" />
  <title>Cuentas - Pixels Studio</title>
  <link rel="stylesheet" href="css/app.css?v=<?= (int) @filemtime(__DIR__ . '/css/app.css') ?>">
  <style>
    :root { --container-w:min(96vw, 1080px); }
    .accounts-header { display:flex; align-items:flex-start; justify-content:space-between; gap:16px; flex-wrap:wrap; margin-bottom:16px; }
    .accounts-actions { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
    .accounts-link { display:inline-flex; align-items:center; justify-content:center; min-height:40px; padding:0 14px; border:1px solid var(--line); border-radius:10px; color:#007ea8; background:var(--surface-soft); font-weight:850; text-decoration:none; }
    .accounts-grid { display:grid; grid-template-columns:minmax(260px, 340px) 1fr; gap:16px; align-items:start; }
    .accounts-box { border:1px solid rgba(0,212,255,.16); border-radius:16px; background:#fff; padding:16px; box-shadow:0 8px 22px rgba(0, 76, 110, .07); }
    .accounts-box h2 { margin:0 0 12px; color:var(--brand-ink); font-size:1.1rem; }
    .accounts-form { display:grid; gap:12px; }
    .accounts-form input, .accounts-form select { width:100%; height:42px; padding:0 12px; border:1px solid var(--line); border-radius:10px; color:var(--brand-ink); background:#fff; outline:none; font:inherit; }
    .accounts-inline { display:grid; grid-template-columns:1.2fr 1fr 150px auto; gap:8px; align-items:start; min-width:760px; }
    .accounts-row-actions { display:grid; gap:8px; }
    .accounts-delete-form { margin:0; }
    .accounts-link.danger { width:100%; border-color:#f1c2c6; background:#fff1f2; color:#9f2631; }
    .accounts-link.danger:hover { background:#ffe4e6; border-color:#e998a1; }
    .accounts-table-wrap { overflow:auto; border:1px solid rgba(0,212,255,.14); border-radius:16px; }
    .accounts-table { width:100%; border-collapse:collapse; font-size:.94rem; }
    .accounts-table th { background:#071120; color:#eafaff; text-align:left; padding:12px; white-space:nowrap; }
    .accounts-table td { padding:12px; border-bottom:1px solid rgba(0,68,99,.10); background:#fbfdff; }
    .notice { display:block; margin-bottom:14px; }
    @media (max-width: 860px) { .accounts-grid { grid-template-columns:1fr; } }
  </style>
</head>
<body class="dashboard-page">
  <main class="dashboard-shell">
    <section class="form-card dashboard-card">
      <div class="panel">
        <header class="accounts-header">
          <div>
            <p class="eyebrow"><?= h(app_config('brand.name', 'Pixels Studio')) ?></p>
            <h1 class="title">Cuentas</h1>
            <p class="subtitle">Crea las cuentas cliente que luego tendrán sus propios usuarios, canales e inbox.</p>
          </div>
        </header>

        <?php if ($notice !== ''): ?><div class="form-alert alert-info notice"><?= h($notice) ?></div><?php endif; ?>
        <?php foreach ($errors as $error): ?><div class="form-alert alert-error notice"><?= h($error) ?></div><?php endforeach; ?>

        <div class="admin-layout">
          <?php nav_render_admin_side_nav('accounts'); ?>
          <div class="admin-content">
        <div class="accounts-grid">
          <section class="accounts-box">
            <h2>Nueva cuenta</h2>
            <form class="accounts-form" method="post" action="/accounts.php" autocomplete="off">
              <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf'] ?? '') ?>">
              <label class="field">
                <span class="field-label">Nombre</span>
                <input type="text" name="create_name" maxlength="160" required autocomplete="off">
              </label>
              <label class="field">
                <span class="field-label">Slug</span>
                <input type="text" name="create_slug" maxlength="80" placeholder="cliente-demo" autocomplete="off">
              </label>
              <label class="field">
                <span class="field-label">Estado</span>
                <select name="create_status">
                  <?php foreach ($statusOptions as $value => $label): ?>
                    <option value="<?= h($value) ?>"><?= h($label) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <button class="btn" type="submit" name="create_account_submit" value="1">Crear cuenta</button>
            </form>
          </section>

          <section class="accounts-box">
            <h2>Cuentas actuales</h2>
            <div class="accounts-table-wrap">
              <table class="accounts-table">
                <thead>
                  <tr>
                    <th>ID</th>
                    <th>Nombre</th>
                    <th>Slug</th>
                    <th>Estado</th>
                    <th>Uso</th>
                    <th>Actualizar</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if ($accounts): foreach ($accounts as $account): ?>
                    <tr>
                      <td>#<?= (int) $account['id'] ?></td>
                      <td><strong><?= h((string) $account['name']) ?></strong></td>
                      <td><?= h((string) $account['slug']) ?></td>
                      <td><?= h((string) ($statusOptions[(string) ($account['status'] ?? '')] ?? $account['status'])) ?></td>
                      <td><?= (int) ($account['users_total'] ?? 0) ?> usuarios · <?= (int) ($account['channels_total'] ?? 0) ?> canales</td>
                      <td>
                        <div class="accounts-row-actions">
                          <form class="accounts-form accounts-inline" method="post" action="/accounts.php" autocomplete="off">
                            <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf'] ?? '') ?>">
                            <input type="hidden" name="update_id" value="<?= (int) $account['id'] ?>">
                            <input type="text" name="update_name" value="<?= h((string) $account['name']) ?>" maxlength="160" aria-label="Nombre" autocomplete="off">
                            <input type="text" name="update_slug" value="<?= h((string) $account['slug']) ?>" maxlength="80" aria-label="Slug" autocomplete="off">
                            <select name="update_status" aria-label="Estado">
                              <?php foreach ($statusOptions as $value => $label): ?>
                                <option value="<?= h($value) ?>" <?= (string) ($account['status'] ?? '') === $value ? 'selected' : '' ?>><?= h($label) ?></option>
                              <?php endforeach; ?>
                            </select>
                            <button class="accounts-link" type="submit" name="update_account_submit" value="1">Guardar</button>
                          </form>
                          <form class="accounts-delete-form" method="post" action="/accounts.php" onsubmit="return confirm('Esta accion eliminara la cuenta y todos sus datos relacionados. ¿Deseas continuar?');">
                            <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf'] ?? '') ?>">
                            <input type="hidden" name="delete_id" value="<?= (int) $account['id'] ?>">
                            <button class="accounts-link danger" type="submit" name="delete_account_submit" value="1">Eliminar cuenta</button>
                          </form>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; else: ?>
                    <tr><td colspan="6">Sin cuentas registradas.</td></tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </section>
        </div>
          </div>
        </div>
      </div>
    </section>
    <div class="credit">Desarrollado por <strong><?= h(app_config('brand.developer', 'Pixels Studio')) ?></strong></div>
  </main>
</body>
</html>
