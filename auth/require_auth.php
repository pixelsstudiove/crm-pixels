<?php
// auth/require_auth.php
declare(strict_types=1);
require_once __DIR__ . '/../config/db.php';

if (!function_exists('is_logged_in')) {
  function is_logged_in(): bool {
    return isset($_SESSION['user_id']) && is_numeric($_SESSION['user_id']);
  }
}

if (!function_exists('ensure_auth_user_roles')) {
  function ensure_auth_user_roles(PDO $pdo, string $dbName, string $table): void {
    try {
      $chk = $pdo->prepare('SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?');
      $chk->execute([$dbName, $table, 'role']);
      if (!$chk->fetch()) {
        $pdo->exec("ALTER TABLE {$table} ADD COLUMN role VARCHAR(30) NOT NULL DEFAULT 'super_admin' AFTER password_hash");
      } else {
        try { $pdo->exec("ALTER TABLE {$table} MODIFY role VARCHAR(30) NOT NULL DEFAULT 'super_admin'"); } catch (Throwable $e) { /* no-op */ }
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
  $stmt = $pdo->prepare("SELECT username, role FROM {$TABLE_USERS} WHERE id=? LIMIT 1");
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
} catch (Throwable $e) {
  $_SESSION['role'] = normalize_role($_SESSION['role'] ?? null);
}
