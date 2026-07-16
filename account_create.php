<?php
// account_create.php
declare(strict_types=1);
require_once __DIR__ . '/auth/require_auth.php';
require_once __DIR__ . '/config/instagram_channels.php';
require_once __DIR__ . '/config/navigation.php';
require_permission('manage_accounts');

$accountsTable = accounts_table();
$errors = [];
$notice = '';
$statusOptions = [
  'active' => 'Activa',
  'suspended' => 'Suspendida',
];

function account_create_slug_from_name(string $name): string {
  $slug = strtolower(trim($name));
  $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
  $slug = trim($slug, '-');
  return $slug !== '' ? mb_substr($slug, 0, 80) : 'cuenta';
}

function account_create_validate_payload(array &$errors, string $name, string $slug, string $status, array $statusOptions): void {
  if (mb_strlen($name) < 3 || mb_strlen($name) > 160) $errors[] = 'El nombre debe tener entre 3 y 160 caracteres.';
  if (!preg_match('/^[a-z0-9-]{3,80}$/', $slug)) $errors[] = 'El slug debe tener entre 3 y 80 caracteres, solo minúsculas, números y guiones.';
  if (!array_key_exists($status, $statusOptions)) $errors[] = 'Selecciona un estado válido.';
}

function account_create_optional_limit(array &$errors, string $value, string $label): ?int {
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

function account_create_bool_from_post(string $key): int {
  return isset($_POST[$key]) ? 1 : 0;
}

function account_create_slug_is_used(PDO $pdo, string $accountsTable, string $slug): bool {
  $stmt = $pdo->prepare("SELECT id FROM {$accountsTable} WHERE slug=? LIMIT 1");
  $stmt->execute([$slug]);
  return (bool) $stmt->fetchColumn();
}

function account_create_name_is_used(PDO $pdo, string $accountsTable, string $name): bool {
  $stmt = $pdo->prepare("SELECT id FROM {$accountsTable} WHERE LOWER(name)=LOWER(?) LIMIT 1");
  $stmt->execute([$name]);
  return (bool) $stmt->fetchColumn();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $csrf = (string) ($_POST['csrf'] ?? '');
  if (!$csrf || !isset($_SESSION['csrf']) || !hash_equals((string) $_SESSION['csrf'], $csrf)) {
    $errors[] = 'CSRF inválido. Recarga la página.';
  } else {
    $name = trim((string) ($_POST['create_name'] ?? ''));
    $rawSlug = (string) ($_POST['create_slug'] ?? $name);
    $slug = account_create_slug_from_name($rawSlug);
    $status = (string) ($_POST['create_status'] ?? 'active');
    $maxOperators = account_create_optional_limit($errors, (string) ($_POST['create_max_operators'] ?? ''), 'El límite de agentes');
    $maxChannels = account_create_optional_limit($errors, (string) ($_POST['create_max_channels'] ?? ''), 'El límite de canales');
    $allowInstagram = account_create_bool_from_post('create_allow_instagram');
    $allowMessenger = account_create_bool_from_post('create_allow_messenger');
    $allowWhatsapp = account_create_bool_from_post('create_allow_whatsapp');

    account_create_validate_payload($errors, $name, $slug, $status, $statusOptions);
    if (!$errors && account_create_name_is_used($pdo, $accountsTable, $name)) $errors[] = 'Ya existe una cuenta con ese nombre.';
    if (!$errors && account_create_slug_is_used($pdo, $accountsTable, $slug)) $errors[] = 'Ese slug ya está siendo usado por otra cuenta.';
    if (!$errors && !$allowInstagram && !$allowMessenger && !$allowWhatsapp) $errors[] = 'Selecciona al menos un canal permitido.';

    if (!$errors) {
      try {
        $stmt = $pdo->prepare("INSERT INTO {$accountsTable} (name, slug, status, max_operators, max_channels, allow_instagram, allow_messenger, allow_whatsapp) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$name, $slug, $status, $maxOperators, $maxChannels, $allowInstagram, $allowMessenger, $allowWhatsapp]);
        $notice = 'Cuenta creada correctamente.';
      } catch (Throwable $e) {
        $errors[] = 'No se pudo crear la cuenta.';
      }
    }
  }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover" />
  <title>Crear cuenta - Pixels Studio</title>
  <link rel="stylesheet" href="css/app.css?v=<?= (int) @filemtime(__DIR__ . '/css/app.css') ?>">
  <style>
    :root { --container-w:min(98vw, 1320px); }
    .accounts-header { display:flex; align-items:flex-start; justify-content:space-between; gap:16px; flex-wrap:wrap; margin-bottom:16px; }
    .account-create-shell { max-width:860px; }
    .account-create-card { border:1px solid rgba(0,212,255,.18); border-radius:20px; background:#fff; padding:20px; box-shadow:0 12px 30px rgba(0, 76, 110, .08); }
    .account-create-card h2 { margin:0 0 6px; color:var(--brand-ink); font-size:1.35rem; letter-spacing:-.02em; }
    .account-create-card > p { margin:0 0 18px; color:var(--brand-muted); font-weight:750; line-height:1.45; }
    .account-create-form { display:grid; gap:18px; }
    .account-create-section { display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:14px; }
    .account-create-section.full { grid-template-columns:1fr; }
    .account-create-form input,
    .account-create-form select {
      width:100%;
      min-width:0;
      min-height:48px;
      padding:0 14px;
      border:1px solid var(--line);
      border-radius:14px;
      color:var(--brand-ink);
      background:#fff;
      outline:none;
      font:inherit;
      font-weight:800;
    }
    .account-create-form input:focus,
    .account-create-form select:focus { border-color:var(--focus); box-shadow:0 0 0 3px rgba(0,212,255,.14); }
    .account-create-form select { appearance:none; padding-right:44px; cursor:pointer; }
    .account-select-field { position:relative; }
    .account-select-field::after { content:"⌄"; position:absolute; right:14px; bottom:13px; color:var(--brand-primary); font-size:1.15rem; line-height:1; pointer-events:none; }
    .account-channel-grid { display:grid; grid-template-columns:repeat(3, minmax(0, 1fr)); gap:10px; }
    .account-channel-option {
      display:flex;
      align-items:center;
      gap:10px;
      min-height:54px;
      padding:0 14px;
      border:1px solid rgba(0,68,99,.12);
      border-radius:14px;
      background:#f8fcff;
      color:var(--brand-ink);
      font-weight:900;
    }
    .account-channel-icon { width:26px; height:26px; border-radius:999px; object-fit:cover; flex:0 0 auto; }
    .account-channel-option input { width:20px!important; height:20px!important; min-height:20px!important; padding:0!important; accent-color:var(--brand-primary); }
    .account-create-actions { display:flex; justify-content:flex-end; gap:10px; flex-wrap:wrap; padding-top:4px; }
    .accounts-link { display:inline-flex; align-items:center; justify-content:center; min-height:44px; padding:0 16px; border:1px solid var(--line); border-radius:12px; color:#007ea8; background:var(--surface-soft); font:inherit; font-weight:900; text-decoration:none; cursor:pointer; }
    .accounts-link:hover { background:#071120; border-color:#071120; color:#fff; }
    .accounts-link.primary { border-color:#071120; background:#071120; color:#fff; }
    .accounts-link.primary:hover { background:#fff; color:#071120; }
    .notice { display:block; margin-bottom:14px; }
    @media (max-width: 760px) {
      .account-create-section,
      .account-channel-grid { grid-template-columns:1fr; }
      .account-create-card { padding:16px; }
      .account-create-actions .accounts-link { width:100%; }
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
            <h1 class="title">Crear cuenta</h1>
            <p class="subtitle">Define los límites y canales permitidos antes de conectar inbox comerciales.</p>
          </div>
          <?php nav_render_config_top_nav($pdo, 'dashboard.php'); ?>
        </header>

        <?php if ($notice !== ''): ?><div class="form-alert alert-info notice"><?= h($notice) ?></div><?php endif; ?>
        <?php foreach ($errors as $error): ?><div class="form-alert alert-error notice"><?= h($error) ?></div><?php endforeach; ?>

        <div class="admin-layout">
          <?php nav_render_admin_side_nav('account_create'); ?>
          <div class="admin-content">
            <section class="account-create-shell">
              <div class="account-create-card">
                <h2>Nueva cuenta cliente</h2>
                <p>Esta cuenta tendrá sus propios usuarios, canales, conversaciones y embudo comercial.</p>
                <form class="account-create-form" method="post" action="/account_create.php" autocomplete="off">
                  <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf'] ?? '') ?>">

                  <div class="account-create-section">
                    <label class="field">
                      <span class="field-label">Nombre de la cuenta</span>
                      <input type="text" name="create_name" maxlength="160" required autocomplete="off">
                    </label>
                    <label class="field">
                      <span class="field-label">Slug</span>
                      <input type="text" name="create_slug" maxlength="80" placeholder="cliente-demo" autocomplete="off">
                    </label>
                  </div>

                  <div class="account-create-section">
                    <label class="field account-select-field">
                      <span class="field-label">Estado de la cuenta</span>
                      <select name="create_status">
                        <?php foreach ($statusOptions as $value => $label): ?>
                          <option value="<?= h($value) ?>"><?= h($label) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </label>
                    <label class="field">
                      <span class="field-label">Cantidad de agentes permitidos</span>
                      <input type="number" name="create_max_operators" min="0" max="9999" placeholder="Ilimitado" inputmode="numeric">
                    </label>
                  </div>

                  <div class="account-create-section">
                    <label class="field">
                      <span class="field-label">Cantidad máxima de canales</span>
                      <input type="number" name="create_max_channels" min="0" max="9999" placeholder="Ilimitado" inputmode="numeric">
                    </label>
                  </div>

                  <div class="account-create-section full">
                    <div>
                      <span class="field-label">Canales permitidos</span>
                      <div class="account-channel-grid">
                        <label class="account-channel-option"><input type="checkbox" name="create_allow_instagram" value="1" checked><img class="account-channel-icon" src="/images/icon_instagram.png" alt="" aria-hidden="true"> Instagram</label>
                        <label class="account-channel-option"><input type="checkbox" name="create_allow_messenger" value="1" checked><img class="account-channel-icon" src="/images/icon_messenger.png" alt="" aria-hidden="true"> Facebook</label>
                        <label class="account-channel-option"><input type="checkbox" name="create_allow_whatsapp" value="1" checked><img class="account-channel-icon" src="/images/icon_whatwsapp.png" alt="" aria-hidden="true"> WhatsApp</label>
                      </div>
                    </div>
                  </div>

                  <div class="account-create-actions">
                    <a class="accounts-link" href="/accounts.php">Ver cuentas</a>
                    <button class="accounts-link primary" type="submit" name="create_account_submit" value="1">Crear cuenta</button>
                  </div>
                </form>
              </div>
            </section>
          </div>
        </div>
      </div>
    </section>
    <div class="credit">Desarrollado por <strong><?= h(app_config('brand.developer', 'Pixels Studio')) ?></strong></div>
  </main>
  <script src="js/navigation.js?v=<?= (int) @filemtime(__DIR__ . '/js/navigation.js') ?>" defer></script>
</body>
</html>
