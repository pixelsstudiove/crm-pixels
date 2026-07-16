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

function lead_status_normalize_legacy_statuses(PDO $pdo, string $leadsTable): void {
  $leadsTable = safe_identifier($leadsTable, 'leads');
  $updates = [
    'contactado' => 'en_conversacion',
    'interesado' => 'en_conversacion',
    'en_seguimiento' => 'en_conversacion',
    'diagnostico_agendado' => 'en_conversacion',
    'en_negociacion' => 'propuesta_enviada',
  ];
  $allowedStatuses = array_keys((array) app_config('sales_funnel.statuses', []));
  try {
    $stmt = $pdo->prepare("UPDATE {$leadsTable} SET sales_status=?, updated_at=NOW() WHERE sales_status=?");
  } catch (Throwable $e) {
    return;
  }
  foreach ($updates as $from => $to) {
    if (!in_array($to, $allowedStatuses, true)) continue;
    try { $stmt->execute([$to, $from]); } catch (Throwable $e) { /* no-op */ }
  }
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

function lead_status_has_operator_reply(PDO $pdo, int $leadId, ?int $accountId = null): bool {
  if ($leadId <= 0) return false;
  $conversationsTable = safe_identifier((string) app_config('database.conversations_table', 'conversations'), 'conversations');
  $messagesTable = safe_identifier((string) app_config('database.conversation_messages_table', 'conversation_messages'), 'conversation_messages');
  $accountSql = '';
  $params = [':lead_id' => $leadId];
  if ($accountId !== null && $accountId > 0) {
    $accountSql = 'AND c.account_id = :account_id';
    $params[':account_id'] = $accountId;
  }

  try {
    $stmt = $pdo->prepare(<<<SQL
SELECT 1
FROM {$conversationsTable} c
JOIN {$messagesTable} m ON m.conversation_id = c.id
WHERE c.lead_id = :lead_id
  AND m.direction = 'outbound'
  {$accountSql}
LIMIT 1
SQL);
    foreach ($params as $key => $value) $stmt->bindValue($key, $value);
    $stmt->execute();
    return (bool) $stmt->fetchColumn();
  } catch (Throwable $e) {
    return false;
  }
}

function lead_status_auto_mark_no_response(PDO $pdo, string $leadsTable, ?int $accountId = null): int {
  $windowHours = max(1, (int) app_config('instagram.reply_window_hours', 24));
  $thresholdHours = max(1, min($windowHours, (int) app_config('instagram.no_response_threshold_hours', 2)));
  $fromStatuses = ['nuevo_lead', 'en_conversacion'];
  $targetStatus = 'no_responde';
  $allowedStatuses = array_keys((array) app_config('sales_funnel.statuses', []));
  if (!in_array($targetStatus, $allowedStatuses, true)) return 0;

  $leadsTable = safe_identifier($leadsTable, 'leads');
  $conversationsTable = safe_identifier((string) app_config('database.conversations_table', 'conversations'), 'conversations');
  $messagesTable = safe_identifier((string) app_config('database.conversation_messages_table', 'conversation_messages'), 'conversation_messages');
  $historyTable = lead_status_history_table();

  $windowSeconds = $windowHours * 3600;
  $thresholdSeconds = $thresholdHours * 3600;
  $now = time();
  $maxLastInbound = gmdate('Y-m-d H:i:s', $now - ($windowSeconds - $thresholdSeconds));
  $reason = 'Ventana Meta por vencer: quedan ' . $thresholdHours . ' hora' . ($thresholdHours === 1 ? '' : 's') . ' o menos para responder. Status actualizado automaticamente a ' . lead_status_label($targetStatus) . '.';

  $accountSql = '';
  $params = [
    ':target_status' => $targetStatus,
    ':max_last_inbound' => $maxLastInbound,
    ':status_a' => $fromStatuses[0],
    ':status_b' => $fromStatuses[1],
  ];
  if ($accountId !== null && $accountId > 0) {
    $accountSql = 'AND l.account_id = :account_id';
    $params[':account_id'] = $accountId;
  }

  lead_status_history_ensure_schema($pdo);
  $selectSql = <<<SQL
SELECT l.id, l.account_id, l.sales_status
FROM {$leadsTable} l
JOIN {$conversationsTable} c ON c.lead_id = l.id
JOIN (
  SELECT conversation_id, MAX(sent_at) AS last_inbound_at
  FROM {$messagesTable}
  WHERE direction = 'inbound'
  GROUP BY conversation_id
) im ON im.conversation_id = c.id
WHERE l.sales_status IN (:status_a, :status_b)
  AND im.last_inbound_at <= :max_last_inbound
  AND NOT EXISTS (
    SELECT 1
    FROM {$historyTable} h
    WHERE h.lead_id = l.id
      AND h.account_id = l.account_id
      AND h.previous_status = :target_status
      AND h.new_status <> :target_status
      AND h.created_at >= im.last_inbound_at
  )
  {$accountSql}
GROUP BY l.id, l.account_id, l.sales_status
LIMIT 500
SQL;
  try {
    $select = $pdo->prepare($selectSql);
    foreach ($params as $key => $value) $select->bindValue($key, $value);
    $select->execute();
    $rows = $select->fetchAll() ?: [];
  } catch (Throwable $e) {
    return 0;
  }
  if (!$rows) return 0;

  try {
    $update = $pdo->prepare("UPDATE {$leadsTable} SET sales_status=?, updated_at=NOW() WHERE id=? AND account_id=? AND sales_status=?");
  } catch (Throwable $e) {
    return 0;
  }
  $affected = 0;
  foreach ($rows as $row) {
    $leadId = (int) ($row['id'] ?? 0);
    $rowAccountId = (int) ($row['account_id'] ?? 0);
    $previousStatus = (string) ($row['sales_status'] ?? '');
    if ($leadId <= 0 || $rowAccountId <= 0 || !in_array($previousStatus, $fromStatuses, true)) continue;

    $update->execute([$targetStatus, $leadId, $rowAccountId, $previousStatus]);
    if ($update->rowCount() < 1) continue;

    lead_status_history_record($pdo, $leadId, $previousStatus, $targetStatus, $reason, $rowAccountId);
    $affected++;
  }

  return $affected;
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
