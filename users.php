<?php
// users.php
declare(strict_types=1);
require_once __DIR__ . '/auth/require_auth.php';
require_once __DIR__ . '/config/navigation.php';
require_permission('manage_users');

$roleProfiles = role_profiles();
if (!is_super_admin()) {
  $roleProfiles = array_intersect_key($roleProfiles, ['vendedor' => true]);
}
$defaultAccountId = accounts_default_id($pdo);
$currentAccountId = (int) (current_account_id() ?: $defaultAccountId);
$accounts = [];
try {
  $accountsStmt = $pdo->query("SELECT id, name, slug, status FROM " . accounts_table() . " ORDER BY name ASC");
  $accounts = $accountsStmt ? $accountsStmt->fetchAll() : [];
} catch (Throwable $e) {
  $accounts = [];
}
$errors = [];
$notice = '';

function valid_username(string $username): bool {
  return (bool) preg_match('/^[a-zA-Z0-9._-]{3,60}$/', $username);
}

function super_admin_count(PDO $pdo, string $table): int {
  $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE role=?");
  $stmt->execute(['super_admin']);
  return (int) $stmt->fetchColumn();
}

function find_user(PDO $pdo, string $table, int $id): ?array {
  if (is_super_admin()) {
    $stmt = $pdo->prepare("SELECT id, account_id, username, role FROM {$table} WHERE id=? LIMIT 1");
    $stmt->execute([$id]);
  } else {
    $stmt = $pdo->prepare("SELECT id, account_id, username, role FROM {$table} WHERE id=? AND account_id=? LIMIT 1");
    $stmt->execute([$id, (int) (current_account_id() ?: accounts_default_id($pdo))]);
  }
  $row = $stmt->fetch();
  return $row ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $csrf = $_POST['csrf'] ?? '';
  if (!$csrf || !isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], (string) $csrf)) {
    $errors[] = 'CSRF inválido. Recarga la página.';
  } else {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'create_user') {
      $username = trim((string) ($_POST['username'] ?? ''));
      $role = trim((string) ($_POST['role'] ?? ''));
      $password = (string) ($_POST['password'] ?? '');
      $confirm = (string) ($_POST['confirm'] ?? '');
      $targetAccountId = is_super_admin() ? max(0, (int) ($_POST['account_id'] ?? $currentAccountId)) : $currentAccountId;

      if (!valid_username($username)) $errors[] = 'El usuario debe tener entre 3 y 60 caracteres. Usa letras, números, punto, guion o guion bajo.';
      if (!array_key_exists($role, $roleProfiles)) $errors[] = 'Selecciona un rol válido.';
      if ($targetAccountId <= 0) $errors[] = 'Selecciona una cuenta válida.';
      if (strlen($password) < 8) $errors[] = 'La contraseña debe tener al menos 8 caracteres.';
      if ($password !== $confirm) $errors[] = 'La confirmación de contraseña no coincide.';

      if (!$errors) {
        try {
          $stmt = $pdo->prepare("INSERT INTO {$TABLE_USERS} (account_id, username, password_hash, role) VALUES (?, ?, ?, ?)");
          $stmt->execute([$targetAccountId, $username, password_hash($password, PASSWORD_DEFAULT), $role]);
          $notice = 'Usuario creado correctamente.';
        } catch (Throwable $e) {
          $errors[] = 'No se pudo crear el usuario. Verifica que el nombre no esté repetido.';
        }
      }
    } elseif ($action === 'update_user') {
      $id = (int) ($_POST['id'] ?? 0);
      $role = trim((string) ($_POST['role'] ?? ''));
      $password = (string) ($_POST['password'] ?? '');
      $confirm = (string) ($_POST['confirm'] ?? '');
      $targetAccountId = is_super_admin() ? max(0, (int) ($_POST['account_id'] ?? 0)) : $currentAccountId;
      $target = $id > 0 ? find_user($pdo, $TABLE_USERS, $id) : null;

      if (!$target) $errors[] = 'Usuario no encontrado.';
      if (!array_key_exists($role, $roleProfiles)) $errors[] = 'Selecciona un rol válido.';
      if ($targetAccountId <= 0) $errors[] = 'Selecciona una cuenta válida.';
      if ($password !== '' && strlen($password) < 8) $errors[] = 'La nueva contraseña debe tener al menos 8 caracteres.';
      if ($password !== $confirm) $errors[] = 'La confirmación de contraseña no coincide.';

      if ($target && (int) $target['id'] === (int) $_SESSION['user_id'] && $role !== 'super_admin') {
        $errors[] = 'No puedes quitarte tu propio acceso de super administrador.';
      }
      if ($target && normalize_role($target['role'] ?? '') === 'super_admin' && $role !== 'super_admin' && super_admin_count($pdo, $TABLE_USERS) <= 1) {
        $errors[] = 'Debe existir al menos un super administrador activo.';
      }
      if (!is_super_admin() && normalize_role($target['role'] ?? '') !== 'vendedor') {
        $errors[] = 'Solo puedes actualizar vendedores de tu cuenta.';
      }

      if (!$errors) {
        try {
          if ($password !== '') {
            $stmt = $pdo->prepare("UPDATE {$TABLE_USERS} SET account_id=?, role=?, password_hash=? WHERE id=?");
            $stmt->execute([$targetAccountId, $role, password_hash($password, PASSWORD_DEFAULT), $id]);
          } else {
            $stmt = $pdo->prepare("UPDATE {$TABLE_USERS} SET account_id=?, role=? WHERE id=?");
            $stmt->execute([$targetAccountId, $role, $id]);
          }
          $notice = 'Usuario actualizado correctamente.';
        } catch (Throwable $e) {
          $errors[] = 'No se pudo actualizar el usuario.';
        }
      }
    }
  }
}

$users = [];
try {
  $accountsTable = accounts_table();
  if (is_super_admin()) {
    $stmt = $pdo->query("SELECT u.id, u.account_id, u.username, u.role, u.created_at, a.name AS account_name FROM {$TABLE_USERS} u LEFT JOIN {$accountsTable} a ON a.id = u.account_id ORDER BY u.id ASC");
  } else {
    $stmt = $pdo->prepare("SELECT u.id, u.account_id, u.username, u.role, u.created_at, a.name AS account_name FROM {$TABLE_USERS} u LEFT JOIN {$accountsTable} a ON a.id = u.account_id WHERE u.account_id=? ORDER BY u.id ASC");
    $stmt->execute([$currentAccountId]);
  }
  $users = $stmt ? $stmt->fetchAll() : [];
} catch (Throwable $e) {
  $errors[] = 'No se pudo cargar la lista de usuarios.';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover" />
  <title>Usuarios - Pixels Studio</title>
  <link rel="stylesheet" href="css/app.css?v=<?= (int) @filemtime(__DIR__ . '/css/app.css') ?>">
  <style>
    :root { --container-w: min(96vw, 1180px); }
    .users-header { display:flex; align-items:flex-start; justify-content:space-between; gap:16px; flex-wrap:wrap; margin-bottom:16px; }
    .users-actions { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
    .users-link { display:inline-flex; align-items:center; justify-content:center; min-height:40px; padding:0 14px; border:1px solid var(--line); border-radius:10px; color:#007ea8; background:var(--surface-soft); font-weight:850; text-decoration:none; }
    .users-link:hover { background:#dff6ff; border-color:#8bdfff; }
    .users-grid { display:grid; grid-template-columns:minmax(260px, 360px) 1fr; gap:16px; align-items:start; }
    .users-box { border:1px solid rgba(0,212,255,.16); border-radius:16px; background:#fff; padding:16px; box-shadow:0 8px 22px rgba(0, 76, 110, .07); }
    .users-box h2 { margin:0 0 12px; color:var(--brand-ink); font-size:1.1rem; }
    .users-form { display:grid; gap:12px; }
    .users-form input, .users-form select { width:100%; height:40px; padding:0 10px; border:1px solid var(--line); border-radius:10px; color:var(--brand-ink); background:#fff; outline:none; font:inherit; }
    .users-form input:focus, .users-form select:focus { border-color:var(--brand-primary); box-shadow:0 0 0 3px rgba(0,212,255,.16); }
    .users-form small { color:var(--brand-muted); line-height:1.35; }
    .users-table-wrap { overflow:auto; border:1px solid rgba(0,212,255,.14); border-radius:16px; }
    .users-table { width:100%; border-collapse:collapse; font-size:.94rem; }
    .users-table th { background:#071120; color:#eafaff; text-align:left; padding:12px; white-space:nowrap; }
    .users-table td { padding:12px; border-bottom:1px solid rgba(0,68,99,.10); vertical-align:top; background:#fbfdff; }
    .role-description { color:var(--brand-muted); font-size:.82rem; line-height:1.35; max-width:280px; }
    .inline-fields { display:grid; grid-template-columns:repeat(4, minmax(150px, 1fr)) auto; gap:8px; align-items:start; min-width:860px; }
    .notice { display:block; margin-bottom:14px; }
    @media (max-width: 920px) { .users-grid { grid-template-columns:1fr; } .inline-fields { min-width:680px; } }
  </style>
</head>
<body class="dashboard-page">
  <main class="dashboard-shell">
    <section class="form-card dashboard-card">
      <div class="panel">
        <header class="users-header">
          <div>
            <p class="eyebrow"><?= h(app_config('brand.name', 'Pixels Studio')) ?></p>
            <h1 class="title">Usuarios y roles</h1>
            <p class="subtitle">Administra el acceso del equipo al CRM.</p>
          </div>
        </header>

        <?php if ($notice !== ''): ?><div class="form-alert alert-info notice"><?= h($notice) ?></div><?php endif; ?>
        <?php foreach ($errors as $error): ?><div class="form-alert alert-error notice"><?= h($error) ?></div><?php endforeach; ?>

        <div class="admin-layout">
          <?php nav_render_admin_side_nav('users'); ?>
          <div class="admin-content">
        <div class="users-grid">
          <section class="users-box">
            <h2>Crear usuario</h2>
            <form class="users-form" method="post" action="/users.php" autocomplete="off">
              <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf'] ?? '') ?>">
              <input type="hidden" name="action" value="create_user">
              <label class="field">
                <span class="field-label">Usuario</span>
                <input type="text" name="username" minlength="3" maxlength="60" required>
              </label>
              <?php if (is_super_admin()): ?>
              <label class="field">
                <span class="field-label">Cuenta</span>
                <select name="account_id" required>
                  <?php foreach ($accounts as $account): ?>
                    <option value="<?= (int) $account['id'] ?>" <?= (int) $account['id'] === $currentAccountId ? 'selected' : '' ?>><?= h((string) $account['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <?php endif; ?>
              <label class="field">
                <span class="field-label">Rol</span>
                <select name="role" required>
                  <?php foreach ($roleProfiles as $roleKey => $profile): ?>
                    <option value="<?= h($roleKey) ?>" <?= $roleKey === app_config('roles.default', 'asesor') ? 'selected' : '' ?>><?= h($profile['label'] ?? $roleKey) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <label class="field">
                <span class="field-label">Contraseña</span>
                <input type="password" name="password" minlength="8" required autocomplete="new-password">
              </label>
              <label class="field">
                <span class="field-label">Confirmar contraseña</span>
                <input type="password" name="confirm" minlength="8" required autocomplete="new-password">
              </label>
              <small>Recomendado: crea usuarios personales para cada asesor, sin compartir claves.</small>
              <button class="btn" type="submit">Crear usuario</button>
            </form>
          </section>

          <section class="users-box">
            <h2>Usuarios actuales</h2>
            <div class="users-table-wrap">
              <table class="users-table">
                <thead>
                  <tr>
                    <th>Usuario</th>
                    <th>Rol y permisos</th>
                    <th>Actualizar</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if ($users): foreach ($users as $user): ?>
                    <?php $userRole = normalize_role($user['role'] ?? ''); ?>
                    <tr>
                      <td>
                        <strong><?= h($user['username'] ?? '') ?></strong><br>
                        <small>ID #<?= (int) $user['id'] ?> · <?= h((string) ($user['created_at'] ?? '')) ?></small>
                        <?php if (is_super_admin()): ?><br><small>Cuenta: <?= h((string) ($user['account_name'] ?? 'Cuenta por defecto')) ?></small><?php endif; ?>
                      </td>
                      <td>
                        <strong><?= h(role_label($userRole)) ?></strong>
                        <div class="role-description"><?= h((string) app_config('roles.profiles.' . $userRole . '.description', '')) ?></div>
                      </td>
                      <td>
                        <form class="users-form inline-fields" method="post" action="/users.php" autocomplete="off">
                          <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf'] ?? '') ?>">
                          <input type="hidden" name="action" value="update_user">
                          <input type="hidden" name="id" value="<?= (int) $user['id'] ?>">
                          <?php if (is_super_admin()): ?>
                          <select name="account_id" aria-label="Cuenta">
                            <?php foreach ($accounts as $account): ?>
                              <option value="<?= (int) $account['id'] ?>" <?= (int) ($user['account_id'] ?? 0) === (int) $account['id'] ? 'selected' : '' ?>><?= h((string) $account['name']) ?></option>
                            <?php endforeach; ?>
                          </select>
                          <?php else: ?>
                          <input type="hidden" name="account_id" value="<?= $currentAccountId ?>">
                          <?php endif; ?>
                          <select name="role" aria-label="Rol">
                            <?php foreach ($roleProfiles as $roleKey => $profile): ?>
                              <option value="<?= h($roleKey) ?>" <?= $userRole === (string) $roleKey ? 'selected' : '' ?>><?= h($profile['label'] ?? $roleKey) ?></option>
                            <?php endforeach; ?>
                          </select>
                          <input type="password" name="password" minlength="8" placeholder="Nueva contraseña" autocomplete="new-password">
                          <input type="password" name="confirm" minlength="8" placeholder="Confirmar contraseña" autocomplete="new-password">
                          <button class="users-link" type="submit">Guardar</button>
                        </form>
                      </td>
                    </tr>
                  <?php endforeach; else: ?>
                    <tr><td colspan="3">Sin usuarios registrados.</td></tr>
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
