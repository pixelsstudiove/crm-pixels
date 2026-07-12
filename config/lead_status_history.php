<?php
// config/lead_status_history.php
declare(strict_types=1);

function lead_status_history_table(): string {
  return safe_identifier((string) app_config('database.lead_status_history_table', 'lead_status_history'), 'lead_status_history');
}

function lead_status_history_ensure_schema(PDO $pdo): void {
  $table = lead_status_history_table();
  $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS {$table} (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  lead_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NULL,
  username VARCHAR(120) NULL,
  previous_status VARCHAR(50) NULL,
  new_status VARCHAR(50) NOT NULL,
  change_reason TEXT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_lead_created (lead_id, created_at),
  KEY idx_user_id (user_id),
  KEY idx_new_status (new_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);
}

function lead_status_label(string $status): string {
  $statuses = (array) app_config('sales_funnel.statuses', []);
  return (string) ($statuses[$status] ?? $status);
}

function lead_status_history_record(PDO $pdo, int $leadId, ?string $previousStatus, string $newStatus, string $reason): void {
  lead_status_history_ensure_schema($pdo);
  $table = lead_status_history_table();
  $reason = trim($reason);
  if ($leadId <= 0 || $newStatus === '' || $reason === '') return;

  $stmt = $pdo->prepare(<<<SQL
INSERT INTO {$table} (
  lead_id, user_id, username, previous_status, new_status, change_reason
) VALUES (?, ?, ?, ?, ?, ?)
SQL);
  $stmt->execute([
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
  $stmt = $pdo->prepare("SELECT * FROM {$table} WHERE lead_id=? ORDER BY created_at ASC, id ASC LIMIT {$limit}");
  $stmt->execute([$leadId]);
  return $stmt->fetchAll() ?: [];
}
