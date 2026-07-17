<?php
// auth/require_auth.php
declare(strict_types=1);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/accounts.php';

if (!function_exists('is_logged_in')) {
  function is_logged_in(): bool {
    return isset($_SESSION['user_id']) && is_numeric($_SESSION['user_id']);
  }
}

if (!function_exists('ensure_auth_user_roles')) {
  function ensure_auth_user_roles(PDO $pdo, string $dbName, string $table): void {
    try {
      $defaultAccountId = accounts_ensure_runtime_schema($pdo, $dbName);
      $chk = $pdo->prepare('SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?');
      $chk->execute([$dbName, $table, 'role']);
      if (!$chk->fetch()) {
        $pdo->exec("ALTER TABLE {$table} ADD COLUMN role VARCHAR(30) NOT NULL DEFAULT 'super_admin' AFTER password_hash");
      } else {
        try { $pdo->exec("ALTER TABLE {$table} MODIFY role VARCHAR(30) NOT NULL DEFAULT 'super_admin'"); } catch (Throwable $e) { /* no-op */ }
      }
      if (!account_column_exists($pdo, $dbName, $table, 'account_id')) {
        $pdo->exec("ALTER TABLE {$table} ADD COLUMN account_id INT UNSIGNED NULL AFTER id");
      }
      $stmt = $pdo->prepare("UPDATE {$table} SET account_id=? WHERE account_id IS NULL OR account_id=0");
      $stmt->execute([$defaultAccountId]);
      try { $pdo->exec("ALTER TABLE {$table} MODIFY account_id INT UNSIGNED NOT NULL DEFAULT {$defaultAccountId}"); } catch (Throwable $e) { /* no-op */ }
      if (!account_index_exists($pdo, $dbName, $table, 'idx_account_id')) {
        try { $pdo->exec("ALTER TABLE {$table} ADD KEY idx_account_id (account_id)"); } catch (Throwable $e) { /* no-op */ }
      }

      $superCount = (int) ($pdo->query("SELECT COUNT(*) FROM {$table} WHERE role='super_admin'")->fetchColumn() ?: 0);
      if ($superCount <= 0) {
        try { $pdo->exec("UPDATE {$table} SET role='super_admin' WHERE role='admin'"); } catch (Throwable $e) { /* no-op */ }
      }

      $legacyMap = (array) app_config('roles.legacy_map', []);
      foreach ($legacyMap as $legacyRole => $newRole) {
        $stmt = $pdo->prepare("UPDATE {$table} SET role=? WHERE role=?");
        $stmt->execute([(string) $newRole, (string) $legacyRole]);
      }
    } catch (Throwable $e) {
      /* Si la tabla aun no existe, login.php se encarga de crearla. */
    }
  }
}

if (empty($_SESSION['csrf'])) {
  $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

if (!is_logged_in()) {
  header('Location: login.php'); // sin barra
  exit;
}

ensure_auth_user_roles($pdo, $DB_NAME, $TABLE_USERS);

try {
  $stmt = $pdo->prepare("SELECT username, role, account_id FROM {$TABLE_USERS} WHERE id=? LIMIT 1");
  $stmt->execute([(int) $_SESSION['user_id']]);
  $row = $stmt->fetch();
  if (!$row) {
    session_unset();
    session_destroy();
    header('Location: login.php');
    exit;
  }
  $_SESSION['username'] = (string) ($row['username'] ?? $_SESSION['username'] ?? '');
  $_SESSION['role'] = normalize_role($row['role'] ?? null);
  $_SESSION['account_id'] = (int) ($row['account_id'] ?? accounts_default_id($pdo));
  if (current_user_role() !== 'super_admin' && !accounts_is_active($pdo, (int) $_SESSION['account_id'])) {
    session_unset();
    session_destroy();
    header('Location: login.php?error=account_suspended');
    exit;
  }
  $requestAccount = accounts_request_account($pdo);
  if ($requestAccount && current_user_role() !== 'super_admin' && (int) ($requestAccount['id'] ?? 0) !== (int) $_SESSION['account_id']) {
    http_response_code(403);
    exit('Acceso denegado.');
  }
  if ($_SERVER['REQUEST_METHOD'] === 'GET' && current_user_role() !== 'super_admin' && !$requestAccount) {
    $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if (in_array($script, ['dashboard.php', 'inbox.php', 'channels.php', 'webhook_logs.php', 'stats.php'], true)) {
      $slug = accounts_slug_for_id($pdo, (int) $_SESSION['account_id']);
      if ($slug !== '') {
        $params = $_GET;
        unset($params['account_slug']);
        header('Location: ' . account_url($script, $params, $slug));
        exit;
      }
    }
  }
} catch (Throwable $e) {
  $_SESSION['role'] = normalize_role($_SESSION['role'] ?? null);
  $_SESSION['account_id'] = (int) ($_SESSION['account_id'] ?? accounts_default_id($pdo));
}
