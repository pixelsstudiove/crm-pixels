<?php
// config/lead_status_history.php
declare(strict_types=1);

require_once __DIR__ . '/accounts.php';

function lead_status_history_table(): string {
  return safe_identifier((string) app_config('database.lead_status_history_table', 'lead_status_history'), 'lead_status_history');
}

function lead_status_history_ensure_schema(PDO $pdo): void {
  global $DB_NAME;
  $table = lead_status_history_table();
  $defaultAccountId = accounts_default_id($pdo);
  $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS {$table} (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  account_id INT UNSIGNED NOT NULL DEFAULT {$defaultAccountId},
  lead_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NULL,
  username VARCHAR(120) NULL,
  previous_status VARCHAR(50) NULL,
  new_status VARCHAR(50) NOT NULL,
  change_reason TEXT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_account_id (account_id),
  KEY idx_lead_created (lead_id, created_at),
  KEY idx_user_id (user_id),
  KEY idx_new_status (new_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);
  try { accounts_add_account_column($pdo, (string) ($DB_NAME ?? ''), $table, $defaultAccountId); } catch (Throwable $e) { /* no-op */ }
}

function lead_status_label(string $status): string {
  $statuses = (array) app_config('sales_funnel.statuses', []);
  return (string) ($statuses[$status] ?? $status);
}

function lead_status_history_record(PDO $pdo, int $leadId, ?string $previousStatus, string $newStatus, string $reason, ?int $accountId = null): void {
  lead_status_history_ensure_schema($pdo);
  $table = lead_status_history_table();
  $reason = trim($reason);
  if ($leadId <= 0 || $newStatus === '' || $reason === '') return;
  $accountId = $accountId !== null && $accountId > 0 ? $accountId : (int) (current_account_id() ?: accounts_default_id($pdo));

  $stmt = $pdo->prepare(<<<SQL
INSERT INTO {$table} (
  account_id, lead_id, user_id, username, previous_status, new_status, change_reason
) VALUES (?, ?, ?, ?, ?, ?, ?)
SQL);
  $stmt->execute([
    $accountId,
    $leadId,
    isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null,
    isset($_SESSION['username']) ? mb_substr((string) $_SESSION['username'], 0, 120) : null,
    $previousStatus,
    $newStatus,
    $reason,
  ]);
}

function lead_status_history_rows(PDO $pdo, int $leadId, int $limit = 80): array {
  if ($leadId <= 0) return [];
  lead_status_history_ensure_schema($pdo);
  $table = lead_status_history_table();
  $limit = max(1, min(200, $limit));
  if (is_super_admin()) {
    $stmt = $pdo->prepare("SELECT * FROM {$table} WHERE lead_id=? ORDER BY created_at ASC, id ASC LIMIT {$limit}");
    $stmt->execute([$leadId]);
  } else {
    $stmt = $pdo->prepare("SELECT * FROM {$table} WHERE account_id=? AND lead_id=? ORDER BY created_at ASC, id ASC LIMIT {$limit}");
    $stmt->execute([(int) (current_account_id() ?: accounts_default_id($pdo)), $leadId]);
  }
  return $stmt->fetchAll() ?: [];
}
