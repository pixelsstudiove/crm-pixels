<?php
// login.php
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/accounts.php';

$defaultAccountId = accounts_ensure_runtime_schema($pdo, $DB_NAME);

$pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS {$TABLE_USERS} (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  account_id INT UNSIGNED NOT NULL DEFAULT {$defaultAccountId},
  username VARCHAR(60) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role VARCHAR(30) NOT NULL DEFAULT 'super_admin',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_account_id (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

$seed_notice = '';
try {
  $chk = $pdo->prepare('SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?');
  $chk->execute([$DB_NAME, $TABLE_USERS, 'role']);
  if (!$chk->fetch()) {
    $pdo->exec("ALTER TABLE {$TABLE_USERS} ADD COLUMN role VARCHAR(30) NOT NULL DEFAULT 'super_admin' AFTER password_hash");
  } else {
    try { $pdo->exec("ALTER TABLE {$TABLE_USERS} MODIFY role VARCHAR(30) NOT NULL DEFAULT 'super_admin'"); } catch (Throwable $e) { /* no-op */ }
  }
  if (!account_column_exists($pdo, $DB_NAME, $TABLE_USERS, 'account_id')) {
    $pdo->exec("ALTER TABLE {$TABLE_USERS} ADD COLUMN account_id INT UNSIGNED NULL AFTER id");
  }
  $assignAccount = $pdo->prepare("UPDATE {$TABLE_USERS} SET account_id=? WHERE account_id IS NULL OR account_id=0");
  $assignAccount->execute([$defaultAccountId]);
  try { $pdo->exec("ALTER TABLE {$TABLE_USERS} MODIFY account_id INT UNSIGNED NOT NULL DEFAULT {$defaultAccountId}"); } catch (Throwable $e) { /* no-op */ }
  if (!account_index_exists($pdo, $DB_NAME, $TABLE_USERS, 'idx_account_id')) {
    try { $pdo->exec("ALTER TABLE {$TABLE_USERS} ADD KEY idx_account_id (account_id)"); } catch (Throwable $e) { /* no-op */ }
  }
  $superCount = (int) ($pdo->query("SELECT COUNT(*) FROM {$TABLE_USERS} WHERE role='super_admin'")->fetchColumn() ?: 0);
  if ($superCount <= 0) {
    try { $pdo->exec("UPDATE {$TABLE_USERS} SET role='super_admin' WHERE role='admin'"); } catch (Throwable $e) { /* no-op */ }
  }
  $legacyMap = (array) app_config('roles.legacy_map', []);
  foreach ($legacyMap as $legacyRole => $newRole) {
    $migrate = $pdo->prepare("UPDATE {$TABLE_USERS} SET role=? WHERE role=?");
    $migrate->execute([(string) $newRole, (string) $legacyRole]);
  }

  $exists = (int) $pdo->query("SELECT COUNT(*) FROM {$TABLE_USERS}")->fetchColumn();
  if ($exists === 0 && (bool) app_config('security.allow_default_admin_seed', true)) {
    $user = (string) app_config('security.default_admin_user', 'admin');
    $pass = (string) app_config('security.default_admin_pass', 'CambiaEstaClave#2026');
    $hash = password_hash($pass, PASSWORD_DEFAULT);
    $ins = $pdo->prepare("INSERT INTO {$TABLE_USERS} (account_id, username, password_hash, role) VALUES (?, ?, ?, ?)");
    $ins->execute([$defaultAccountId, $user, $hash, 'super_admin']);
    $seed_notice = "Usuario creado: <strong>" . h($user) . "</strong> / <strong>" . h($pass) . "</strong>";
  }
} catch (Throwable $e) { /* log opcional */ }

if (empty($_SESSION['csrf'])) {
  $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

function login_security_dir(): string {
  $dir = __DIR__ . '/storage/security/login';
  if (!is_dir($dir)) @mkdir($dir, 0770, true);
  return $dir;
}
function login_rate_file(string $username): string {
  $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
  return login_security_dir() . '/' . hash('sha256', $ip . '|' . mb_strtolower($username)) . '.json';
}
function login_rate_state(string $username): array {
  $file = login_rate_file($username);
  if (!is_file($file)) return ['attempts' => 0, 'first_at' => 0, 'locked_until' => 0];
  $data = json_decode((string) file_get_contents($file), true);
  return is_array($data) ? $data + ['attempts' => 0, 'first_at' => 0, 'locked_until' => 0] : ['attempts' => 0, 'first_at' => 0, 'locked_until' => 0];
}
function login_is_locked(string $username): int {
  $state = login_rate_state($username);
  $until = (int) ($state['locked_until'] ?? 0);
  return $until > time() ? $until : 0;
}
function login_register_failure(string $username): void {
  $now = time();
  $window = (int) app_config('security.login_window_seconds', 900);
  $max = (int) app_config('security.login_max_attempts', 5);
  $lock = (int) app_config('security.login_lock_seconds', 900);
  $state = login_rate_state($username);
  $first = (int) ($state['first_at'] ?? 0);
  $attempts = (int) ($state['attempts'] ?? 0);
  if ($first <= 0 || ($now - $first) > $window) {
    $first = $now;
    $attempts = 0;
  }
  $attempts++;
  $state = ['attempts' => $attempts, 'first_at' => $first, 'locked_until' => $attempts >= $max ? $now + $lock : 0];
  @file_put_contents(login_rate_file($username), json_encode($state));
}
function login_clear_failures(string $username): void {
  $file = login_rate_file($username);
  if (is_file($file)) @unlink($file);
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $csrf = $_POST['csrf'] ?? '';
  if (!$csrf || !hash_equals($_SESSION['csrf'], (string) $csrf)) {
    $error = 'CSRF inválido. Recarga la página.';
  } else {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
      $error = 'Usuario y clave son obligatorios.';
    } elseif ($lockedUntil = login_is_locked($username)) {
      $minutes = max(1, (int) ceil(($lockedUntil - time()) / 60));
      $error = 'Demasiados intentos fallidos. Intenta nuevamente en ' . $minutes . ' minuto' . ($minutes === 1 ? '' : 's') . '.';
    } else {
      $stmt = $pdo->prepare("SELECT id, account_id, username, password_hash, role FROM {$TABLE_USERS} WHERE username = ? LIMIT 1");
      $stmt->execute([$username]);
      $row = $stmt->fetch();

      if (!$row || !password_verify($password, $row['password_hash'])) {
        login_register_failure($username);
        $error = 'Credenciales inválidas.';
      } else {
        login_clear_failures($username);
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $row['id'];
        $_SESSION['account_id'] = (int) ($row['account_id'] ?? $defaultAccountId);
        $_SESSION['username'] = (string) $row['username'];
        $_SESSION['role'] = normalize_role($row['role'] ?? null);

        if (empty($_SESSION['csrf'])) {
          $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }

        header('Location: dashboard.php');
        exit;
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
  <title><?= h(app_config('ui.login_title', 'Acceso')) ?></title>
  <link rel="stylesheet" href="css/app.css?v=<?= (int) @filemtime(__DIR__ . '/css/app.css') ?>">
</head>
<body class="login-page">
  <main class="login-shell">
    <section class="form-card login-card" aria-labelledby="login-title">
      <div class="panel login-panel">
        <header class="login-header">
          <h1 class="title" id="login-title">Acceso al Dashboard</h1>
          <p class="subtitle">Ingresa tus credenciales</p>
        </header>

        <?php if ($seed_notice !== ''): ?>
          <div class="form-alert alert-info" style="display:block; margin-bottom:14px"><?= $seed_notice ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
          <div class="form-alert alert-error" style="display:block; margin-bottom:14px" role="alert"><?= h($error) ?></div>
        <?php endif; ?>

        <form method="post" action="login.php" class="form login-form" novalidate>
          <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">

          <div class="login-fields" aria-label="Credenciales de acceso">
            <label class="field" id="f-username">
              <span class="field-label">Usuario</span>
              <input type="text" name="username" placeholder="Ingresa tu usuario" required autocomplete="username" autofocus>
              <small class="err" data-for="username">Usuario obligatorio.</small>
            </label>

            <label class="field" id="f-password">
              <span class="field-label">Contraseña</span>
              <input type="password" name="password" placeholder="Ingresa tu contraseña" required autocomplete="current-password">
              <small class="err" data-for="password">Contraseña obligatoria.</small>
            </label>
          </div>

          <div id="formAlert" class="form-alert" aria-live="polite" style="display:none"></div>
          <button class="btn" type="submit">Ingresar</button>
        </form>
      </div>
    </section>

    <div class="credit">Desarrollado por <strong><?= h(app_config('brand.developer', 'Pixels Studio')) ?></strong></div>
  </main>
  <script src="js/login.js" defer></script>
</body>
</html>
