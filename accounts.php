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

function account_optional_limit(array &$errors, string $value, string $label): ?int {
  $value = trim($value);
  if ($value === '') return null;
  if (!preg_match('/^\d+$/', $value)) {
    $errors[] = "{$label} debe ser un número entero positivo o dejarse vacío para ilimitado.";
    return null;
  }
  $limit = (int) $value;
  if ($limit > 9999) {
    $errors[] = "{$label} no puede ser mayor a 9999.";
    return null;
  }
  return $limit;
}

function account_bool_from_post(string $key): int {
  return isset($_POST[$key]) ? 1 : 0;
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
      $errors[] = 'Para crear una cuenta usa la sección Crear cuenta del menú de configuración.';
    } elseif (isset($_POST['update_account_submit'])) {
      $accountId = max(0, (int) ($_POST['update_id'] ?? 0));
      $name = trim((string) ($_POST['update_name'] ?? ''));
      $rawSlug = (string) ($_POST['update_slug'] ?? $name);
      $slug = account_slug_from_name($rawSlug);
      $status = (string) ($_POST['update_status'] ?? 'active');
      $maxOperators = account_optional_limit($errors, (string) ($_POST['update_max_operators'] ?? ''), 'El límite de operadores');
      $maxChannels = account_optional_limit($errors, (string) ($_POST['update_max_channels'] ?? ''), 'El límite de canales');
      $allowInstagram = account_bool_from_post('update_allow_instagram');
      $allowMessenger = account_bool_from_post('update_allow_messenger');
      $allowWhatsapp = account_bool_from_post('update_allow_whatsapp');
      account_validate_payload($errors, $name, $slug, $status, $statusOptions);
      if (!$errors && !account_exists($pdo, $accountsTable, $accountId)) $errors[] = 'La cuenta que intentas actualizar no existe.';
      if (!$errors && account_slug_is_used($pdo, $accountsTable, $slug, $accountId)) $errors[] = 'Ese slug ya está siendo usado por otra cuenta.';

      if (!$errors) {
        try {
          $stmt = $pdo->prepare("UPDATE {$accountsTable} SET name=?, slug=?, status=?, max_operators=?, max_channels=?, allow_instagram=?, allow_messenger=?, allow_whatsapp=?, updated_at=NOW() WHERE id=?");
          $stmt->execute([$name, $slug, $status, $maxOperators, $maxChannels, $allowInstagram, $allowMessenger, $allowWhatsapp, $accountId]);
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
    :root { --container-w:min(98vw, 1320px); }
    .accounts-header { display:flex; align-items:flex-start; justify-content:space-between; gap:16px; flex-wrap:wrap; margin-bottom:16px; }
    .accounts-link { display:inline-flex; align-items:center; justify-content:center; min-height:42px; padding:0 16px; border:1px solid var(--line); border-radius:12px; color:#007ea8; background:var(--surface-soft); font:inherit; font-weight:900; text-decoration:none; cursor:pointer; transition:background .16s,color .16s,border-color .16s; }
    .accounts-link:hover { background:#071120; border-color:#071120; color:#fff; }
    .accounts-link.primary { background:#071120; border-color:#071120; color:#fff; }
    .accounts-link.primary:hover { background:#fff; color:#071120; }
    .accounts-link.danger { border-color:#f1c2c6; background:#fff1f2; color:#9f2631; }
    .accounts-link.danger:hover { background:#9f2631; border-color:#9f2631; color:#fff; }
    .accounts-box { border:1px solid rgba(0,212,255,.16); border-radius:20px; background:#fff; padding:20px; box-shadow:0 10px 26px rgba(0, 76, 110, .07); }
    .accounts-box h2 { margin:0 0 16px; color:var(--brand-ink); font-size:1.25rem; letter-spacing:-.02em; }
    .accounts-current { min-width:0; }
    .accounts-list { display:grid; gap:14px; }
    .account-row { display:grid; gap:16px; padding:18px; border:1px solid rgba(0,212,255,.16); border-radius:18px; background:#fbfdff; box-shadow:0 8px 18px rgba(6, 24, 44, .04); }
    .account-summary { display:grid; grid-template-columns:auto minmax(180px, 1fr) auto auto; gap:14px; align-items:center; }
    .account-id { display:inline-flex; align-items:center; justify-content:center; min-width:52px; min-height:52px; padding:0 12px; border-radius:14px; background:#071120; color:#eafaff; font-weight:950; font-size:1.05rem; }
    .account-name { min-width:0; }
    .account-name strong { display:block; color:var(--brand-ink); font-size:1.15rem; line-height:1.16; overflow-wrap:anywhere; }
    .account-name span { display:block; margin-top:4px; color:var(--brand-muted); font-size:.92rem; font-weight:850; overflow-wrap:anywhere; }
    .account-pill { display:inline-flex; align-items:center; justify-content:center; min-height:34px; padding:0 14px; border:1px solid #a8e0ba; border-radius:999px; background:#eef9f0; color:#217a43; font-size:.84rem; font-weight:950; white-space:nowrap; }
    .account-pill.off { border-color:#cbd5e1; background:#f1f5f9; color:#64748b; }
    .account-usage { color:var(--brand-muted); font-size:.94rem; font-weight:900; text-align:right; white-space:nowrap; }
    .account-meta-grid { display:grid; grid-template-columns:repeat(5, minmax(120px, 1fr)); gap:10px; }
    .account-metric { min-width:0; padding:12px; border:1px solid rgba(0,68,99,.10); border-radius:14px; background:#fff; color:var(--brand-muted); font-size:.84rem; font-weight:900; }
    .account-metric strong { display:block; margin-bottom:3px; color:var(--brand-ink); font-size:1.02rem; overflow-wrap:anywhere; }
    .account-metric.channel-metric { display:grid; grid-template-columns:auto minmax(0, 1fr); column-gap:10px; align-items:center; }
    .account-metric.channel-metric strong { margin:0; }
    .account-channel-icon { width:26px; height:26px; border-radius:999px; object-fit:cover; flex:0 0 auto; }
    .accounts-form { display:grid; gap:14px; }
    .account-form-grid { display:grid; grid-template-columns:minmax(180px, 1.35fr) minmax(160px, 1fr) minmax(130px, .8fr) repeat(2, minmax(140px, .9fr)); gap:10px; align-items:end; }
    .account-field { display:grid; gap:7px; min-width:0; }
    .account-field span,
    .account-channel-title { color:var(--brand-muted); font-size:.76rem; font-weight:950; letter-spacing:.05em; text-transform:uppercase; }
    .account-field input,
    .account-field select {
      width:100%;
      min-width:0;
      min-height:46px;
      padding:0 13px;
      border:1px solid var(--line);
      border-radius:13px;
      color:var(--brand-ink);
      background:#fff;
      outline:none;
      font:inherit;
      font-weight:850;
    }
    .account-field input:focus,
    .account-field select:focus { border-color:var(--focus); box-shadow:0 0 0 3px rgba(0,212,255,.14); }
    .account-field select { appearance:none; padding-right:40px; cursor:pointer; }
    .account-select-field { position:relative; }
    .account-select-field::after { content:"⌄"; position:absolute; right:13px; bottom:13px; color:var(--brand-primary); font-size:1.08rem; pointer-events:none; line-height:1; }
    .account-channel-options { display:grid; grid-template-columns:repeat(3, minmax(0, 1fr)); gap:10px; }
    .account-toggle { display:flex; align-items:center; gap:10px; min-height:46px; padding:0 13px; border:1px solid rgba(0,68,99,.10); border-radius:13px; background:#fff; color:var(--brand-ink); font-weight:900; }
    .account-toggle input { width:19px!important; height:19px!important; min-height:19px!important; padding:0!important; accent-color:var(--brand-primary); }
    .accounts-row-actions { display:flex; justify-content:flex-end; gap:10px; flex-wrap:wrap; padding-top:2px; }
    .accounts-delete-form { margin:0; }
    .accounts-empty { margin:0; padding:16px; border:1px dashed rgba(0,212,255,.28); border-radius:14px; color:var(--brand-muted); font-weight:850; }
    .notice { display:block; margin-bottom:14px; }
    .admin-content { container-type:inline-size; }
    @media (max-width: 1280px) {
      .account-meta-grid,
      .account-form-grid { grid-template-columns:repeat(3, minmax(0, 1fr)); }
    }
    @media (max-width: 980px) {
      .account-summary { grid-template-columns:auto minmax(0, 1fr); align-items:start; }
      .account-pill { justify-self:start; }
      .account-usage { text-align:left; white-space:normal; }
      .account-meta-grid,
      .account-form-grid { grid-template-columns:repeat(2, minmax(0, 1fr)); }
    }
    @media (max-width: 720px) {
      .accounts-box { padding:14px; }
      .account-row { padding:14px; }
      .account-summary,
      .account-meta-grid,
      .account-form-grid,
      .account-channel-options { grid-template-columns:1fr; }
      .account-id { width:max-content; }
      .accounts-row-actions { display:grid; grid-template-columns:1fr; }
      .accounts-row-actions .accounts-link { width:100%; }
    }
  </style>
</head>
<body class="dashboard-page config-page">
  <main class="dashboard-shell">
    <section class="form-card dashboard-card">
      <div class="panel">
        <header class="accounts-header">
          <div>
            <p class="eyebrow"><?= h(app_config('brand.name', 'Pixels Studio')) ?></p>
            <h1 class="title">Cuentas</h1>
            <p class="subtitle">Crea las cuentas cliente que luego tendrán sus propios usuarios, canales e inbox.</p>
          </div>
          <?php nav_render_config_top_nav($pdo, 'dashboard.php'); ?>
        </header>

        <?php if ($notice !== ''): ?><div class="form-alert alert-info notice"><?= h($notice) ?></div><?php endif; ?>
        <?php foreach ($errors as $error): ?><div class="form-alert alert-error notice"><?= h($error) ?></div><?php endforeach; ?>

        <div class="admin-layout">
          <?php nav_render_admin_side_nav('accounts'); ?>
          <div class="admin-content">
          <section class="accounts-box accounts-current">
            <h2>Cuentas actuales</h2>
            <div class="accounts-list">
              <?php if ($accounts): foreach ($accounts as $account): ?>
                <?php
                  $accountStatus = (string) ($account['status'] ?? '');
                  $accountStatusLabel = (string) ($statusOptions[$accountStatus] ?? $accountStatus);
                ?>
                <article class="account-row">
                  <div class="account-summary">
                    <span class="account-id">#<?= (int) $account['id'] ?></span>
                    <div class="account-name">
                      <strong><?= h((string) $account['name']) ?></strong>
                      <span><?= h((string) $account['slug']) ?></span>
                    </div>
                    <span class="account-pill <?= $accountStatus === 'active' ? '' : 'off' ?>"><?= h($accountStatusLabel) ?></span>
                    <span class="account-usage"><?= (int) ($account['users_total'] ?? 0) ?> usuarios · <?= (int) ($account['channels_total'] ?? 0) ?> canales</span>
                  </div>
                  <div class="account-meta-grid">
                    <span class="account-metric"><strong><?= h(accounts_limit_label(isset($account['max_operators']) && $account['max_operators'] !== null ? (int) $account['max_operators'] : null)) ?></strong>Agentes permitidos</span>
                    <span class="account-metric"><strong><?= h(accounts_limit_label(isset($account['max_channels']) && $account['max_channels'] !== null ? (int) $account['max_channels'] : null)) ?></strong>Canales máximos</span>
                    <span class="account-metric channel-metric"><img class="account-channel-icon" src="/images/icon_instagram.png" alt="" aria-hidden="true"><span><strong><?= (int) ($account['allow_instagram'] ?? 1) === 1 ? 'Sí' : 'No' ?></strong>Instagram</span></span>
                    <span class="account-metric channel-metric"><img class="account-channel-icon" src="/images/icon_messenger.png" alt="" aria-hidden="true"><span><strong><?= (int) ($account['allow_messenger'] ?? 1) === 1 ? 'Sí' : 'No' ?></strong>Facebook</span></span>
                    <span class="account-metric channel-metric"><img class="account-channel-icon" src="/images/icon_whatwsapp.png" alt="" aria-hidden="true"><span><strong><?= (int) ($account['allow_whatsapp'] ?? 1) === 1 ? 'Sí' : 'No' ?></strong>WhatsApp</span></span>
                  </div>
                  <form id="account-update-<?= (int) $account['id'] ?>" class="accounts-form" method="post" action="/accounts.php" autocomplete="off">
                    <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf'] ?? '') ?>">
                    <input type="hidden" name="update_id" value="<?= (int) $account['id'] ?>">
                    <div class="account-form-grid">
                      <label class="account-field">
                        <span>Nombre</span>
                        <input type="text" name="update_name" value="<?= h((string) $account['name']) ?>" maxlength="160" aria-label="Nombre" autocomplete="off">
                      </label>
                      <label class="account-field">
                        <span>Slug</span>
                        <input type="text" name="update_slug" value="<?= h((string) $account['slug']) ?>" maxlength="80" aria-label="Slug" autocomplete="off">
                      </label>
                      <label class="account-field account-select-field">
                        <span>Estado</span>
                        <select name="update_status" aria-label="Estado">
                          <?php foreach ($statusOptions as $value => $label): ?>
                            <option value="<?= h($value) ?>" <?= $accountStatus === $value ? 'selected' : '' ?>><?= h($label) ?></option>
                          <?php endforeach; ?>
                        </select>
                      </label>
                      <label class="account-field">
                        <span>Agentes permitidos</span>
                        <input type="number" name="update_max_operators" value="<?= h((string) ($account['max_operators'] ?? '')) ?>" min="0" max="9999" placeholder="Ilimitado" aria-label="Agentes permitidos" inputmode="numeric">
                      </label>
                      <label class="account-field">
                        <span>Canales máximos</span>
                        <input type="number" name="update_max_channels" value="<?= h((string) ($account['max_channels'] ?? '')) ?>" min="0" max="9999" placeholder="Ilimitado" aria-label="Canales máximos" inputmode="numeric">
                      </label>
                    </div>
                    <div>
                      <span class="account-channel-title">Tipos de canal permitidos</span>
                      <div class="account-channel-options">
                        <label class="account-toggle"><input type="checkbox" name="update_allow_instagram" value="1" <?= (int) ($account['allow_instagram'] ?? 1) === 1 ? 'checked' : '' ?>><img class="account-channel-icon" src="/images/icon_instagram.png" alt="" aria-hidden="true"> Instagram</label>
                        <label class="account-toggle"><input type="checkbox" name="update_allow_messenger" value="1" <?= (int) ($account['allow_messenger'] ?? 1) === 1 ? 'checked' : '' ?>><img class="account-channel-icon" src="/images/icon_messenger.png" alt="" aria-hidden="true"> Facebook</label>
                        <label class="account-toggle"><input type="checkbox" name="update_allow_whatsapp" value="1" <?= (int) ($account['allow_whatsapp'] ?? 1) === 1 ? 'checked' : '' ?>><img class="account-channel-icon" src="/images/icon_whatwsapp.png" alt="" aria-hidden="true"> WhatsApp</label>
                      </div>
                    </div>
                  </form>
                  <div class="accounts-row-actions">
                    <button class="accounts-link primary" form="account-update-<?= (int) $account['id'] ?>" type="submit" name="update_account_submit" value="1">Guardar</button>
                    <form class="accounts-delete-form" method="post" action="/accounts.php" onsubmit="return confirm('Esta accion eliminara la cuenta y todos sus datos relacionados. ¿Deseas continuar?');">
                      <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf'] ?? '') ?>">
                      <input type="hidden" name="delete_id" value="<?= (int) $account['id'] ?>">
                      <button class="accounts-link danger" type="submit" name="delete_account_submit" value="1">Eliminar cuenta</button>
                    </form>
                  </div>
                </article>
              <?php endforeach; else: ?>
                <p class="accounts-empty">Sin cuentas registradas.</p>
              <?php endif; ?>
            </div>
          </section>
        </div>
          </div>
        </div>
      </div>
    </section>
    <div class="credit">Desarrollado por <strong><?= h(app_config('brand.developer', 'Pixels Studio')) ?></strong></div>
  </main>
  <script src="js/navigation.js?v=<?= (int) @filemtime(__DIR__ . '/js/navigation.js') ?>" defer></script>
</body>
</html>
