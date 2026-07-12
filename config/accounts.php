<?php
// config/accounts.php
declare(strict_types=1);

function accounts_table(): string {
  return safe_identifier((string) app_config('database.accounts_table', 'accounts'), 'accounts');
}

function account_column_exists(PDO $pdo, string $dbName, string $table, string $column): bool {
  $stmt = $pdo->prepare('SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?');
  $stmt->execute([$dbName, $table, $column]);
  return (bool) $stmt->fetchColumn();
}

function account_index_exists(PDO $pdo, string $dbName, string $table, string $index): bool {
  $stmt = $pdo->prepare('SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND INDEX_NAME=?');
  $stmt->execute([$dbName, $table, $index]);
  return (bool) $stmt->fetchColumn();
}

function accounts_ensure_schema(PDO $pdo): void {
  $table = accounts_table();
  $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS {$table} (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(160) NOT NULL,
  slug VARCHAR(80) NOT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'active',
  plan VARCHAR(60) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_slug (slug),
  KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);
}

function accounts_default_id(PDO $pdo): int {
  accounts_ensure_schema($pdo);
  $table = accounts_table();
  $slug = (string) app_config('accounts.default_slug', 'pixels-studio');
  $name = (string) app_config('accounts.default_name', 'Pixels Studio');
  $stmt = $pdo->prepare("INSERT INTO {$table} (name, slug, status) VALUES (?, ?, 'active') ON DUPLICATE KEY UPDATE name=VALUES(name), status='active'");
  $stmt->execute([$name, $slug]);
  $find = $pdo->prepare("SELECT id FROM {$table} WHERE slug=? LIMIT 1");
  $find->execute([$slug]);
  return (int) ($find->fetchColumn() ?: 1);
}

function accounts_find(PDO $pdo, int $accountId): ?array {
  if ($accountId <= 0) return null;
  accounts_ensure_schema($pdo);
  $table = accounts_table();
  $stmt = $pdo->prepare("SELECT * FROM {$table} WHERE id=? LIMIT 1");
  $stmt->execute([$accountId]);
  $row = $stmt->fetch();
  return $row ?: null;
}

function accounts_find_by_slug(PDO $pdo, string $slug): ?array {
  $slug = strtolower(trim($slug));
  if (!preg_match('/^[a-z0-9-]{3,80}$/', $slug)) return null;
  accounts_ensure_schema($pdo);
  $table = accounts_table();
  $stmt = $pdo->prepare("SELECT * FROM {$table} WHERE slug=? LIMIT 1");
  $stmt->execute([$slug]);
  $row = $stmt->fetch();
  return $row ?: null;
}

function accounts_slug_for_id(PDO $pdo, int $accountId): string {
  $account = accounts_find($pdo, $accountId);
  return trim((string) ($account['slug'] ?? ''));
}

function accounts_request_slug(): string {
  $slug = strtolower(trim((string) ($_GET['account_slug'] ?? $_POST['account_slug'] ?? '')));
  if ($slug !== '' && preg_match('/^[a-z0-9-]{3,80}$/', $slug)) return $slug;
  return '';
}

function accounts_request_account(PDO $pdo): ?array {
  $slug = accounts_request_slug();
  return $slug !== '' ? accounts_find_by_slug($pdo, $slug) : null;
}

function accounts_request_account_id(PDO $pdo): int {
  $account = accounts_request_account($pdo);
  return $account ? (int) ($account['id'] ?? 0) : 0;
}

function accounts_request_account_slug(PDO $pdo): string {
  $account = accounts_request_account($pdo);
  return $account ? (string) ($account['slug'] ?? '') : '';
}

function account_url(string $script, array $params = [], ?string $slug = null): string {
  $script = ltrim($script, '/');
  $slug = $slug !== null ? strtolower(trim($slug)) : accounts_request_slug();
  $path = $slug !== '' ? '/' . rawurlencode($slug) . '/' . $script : '/' . $script;
  $query = http_build_query(array_filter($params, static fn($value) => $value !== null && $value !== ''), '', '&', PHP_QUERY_RFC3986);
  return $path . ($query !== '' ? '?' . $query : '');
}

function accounts_is_active(PDO $pdo, int $accountId): bool {
  $account = accounts_find($pdo, $accountId);
  return !$account || (string) ($account['status'] ?? 'active') === 'active';
}

function accounts_add_account_column(PDO $pdo, string $dbName, string $table, int $defaultAccountId, string $after = 'id'): void {
  if (!account_column_exists($pdo, $dbName, $table, 'account_id')) {
    $pdo->exec("ALTER TABLE {$table} ADD COLUMN account_id INT UNSIGNED NULL AFTER {$after}");
  }
  $stmt = $pdo->prepare("UPDATE {$table} SET account_id=? WHERE account_id IS NULL OR account_id=0");
  $stmt->execute([$defaultAccountId]);
  try { $pdo->exec("ALTER TABLE {$table} MODIFY account_id INT UNSIGNED NOT NULL DEFAULT {$defaultAccountId}"); } catch (Throwable $e) { /* no-op */ }
  if (!account_index_exists($pdo, $dbName, $table, 'idx_account_id')) {
    try { $pdo->exec("ALTER TABLE {$table} ADD KEY idx_account_id (account_id)"); } catch (Throwable $e) { /* no-op */ }
  }
}

function accounts_drop_index_if_exists(PDO $pdo, string $dbName, string $table, string $index): void {
  if (account_index_exists($pdo, $dbName, $table, $index)) {
    try { $pdo->exec("ALTER TABLE {$table} DROP INDEX {$index}"); } catch (Throwable $e) { /* no-op */ }
  }
}

function accounts_rebuild_unique_index(PDO $pdo, string $dbName, string $table, string $index, string $columns): void {
  if ($dbName === '') return;
  accounts_drop_index_if_exists($pdo, $dbName, $table, $index);
  try { $pdo->exec("ALTER TABLE {$table} ADD UNIQUE KEY {$index} ({$columns})"); } catch (Throwable $e) { /* no-op */ }
}

function accounts_ensure_runtime_schema(PDO $pdo, string $dbName): int {
  $defaultAccountId = accounts_default_id($pdo);
  $tables = [
    safe_identifier((string) app_config('database.users_table', 'users'), 'users') => 'id',
    safe_identifier((string) app_config('database.leads_table', 'leads'), 'leads') => 'id',
    safe_identifier((string) app_config('database.instagram_channels_table', 'instagram_channels'), 'instagram_channels') => 'id',
    safe_identifier((string) app_config('database.conversation_contacts_table', 'conversation_contacts'), 'conversation_contacts') => 'id',
    safe_identifier((string) app_config('database.conversations_table', 'conversations'), 'conversations') => 'id',
    safe_identifier((string) app_config('database.conversation_messages_table', 'conversation_messages'), 'conversation_messages') => 'id',
    safe_identifier((string) app_config('database.conversation_attachments_table', 'conversation_attachments'), 'conversation_attachments') => 'id',
    safe_identifier((string) app_config('database.webhook_event_logs_table', 'webhook_event_logs'), 'webhook_event_logs') => 'id',
    safe_identifier((string) app_config('database.lead_status_history_table', 'lead_status_history'), 'lead_status_history') => 'id',
  ];

  foreach ($tables as $table => $after) {
    try { accounts_add_account_column($pdo, $dbName, $table, $defaultAccountId, $after); } catch (Throwable $e) { /* La tabla puede no existir aun. */ }
  }

  return $defaultAccountId;
}

function current_account_id(): int {
  return max(0, (int) ($_SESSION['account_id'] ?? 0));
}

function is_super_admin(): bool {
  return current_user_role() === 'super_admin';
}
