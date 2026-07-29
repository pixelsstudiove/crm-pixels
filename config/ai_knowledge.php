<?php
// config/ai_knowledge.php
declare(strict_types=1);

require_once __DIR__ . '/accounts.php';

function ai_knowledge_table(): string {
  return safe_identifier((string) app_config('database.ai_knowledge_table', 'ai_knowledge_items'), 'ai_knowledge_items');
}

function ai_knowledge_log_error(string $message, array $context = []): void {
  $dir = dirname(__DIR__) . '/storage';
  if (!is_dir($dir)) @mkdir($dir, 0775, true);
  $line = json_encode([
    'at' => gmdate('c'),
    'message' => $message,
    'context' => $context,
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
  @file_put_contents($dir . '/ai_knowledge_errors.log', $line . PHP_EOL, FILE_APPEND);
}

function ai_knowledge_ensure_schema(PDO $pdo): void {
  global $DB_NAME;

  $table = ai_knowledge_table();
  $defaultAccountId = accounts_default_id($pdo);
  $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS {$table} (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  account_id INT UNSIGNED NOT NULL DEFAULT {$defaultAccountId},
  title VARCHAR(180) NOT NULL,
  response_text TEXT NOT NULL,
  response_hash CHAR(64) NOT NULL,
  category VARCHAR(60) NOT NULL DEFAULT 'ai_suggestion',
  source VARCHAR(60) NOT NULL DEFAULT 'ai_suggestion',
  source_conversation_id INT UNSIGNED NULL,
  source_message_id INT UNSIGNED NULL,
  is_approved TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 0,
  usage_count INT UNSIGNED NOT NULL DEFAULT 0,
  last_used_at DATETIME NULL,
  created_by INT UNSIGNED NULL,
  approved_by INT UNSIGNED NULL,
  approved_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_account_response_hash (account_id, response_hash),
  KEY idx_account_approved_active (account_id, is_approved, is_active),
  KEY idx_source_conversation (source_conversation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

  $dbName = (string) ($DB_NAME ?? '');
  if ($dbName === '') return;

  $columns = [
    'account_id' => "ALTER TABLE {$table} ADD account_id INT UNSIGNED NOT NULL DEFAULT {$defaultAccountId} AFTER id",
    'title' => "ALTER TABLE {$table} ADD title VARCHAR(180) NOT NULL DEFAULT 'Respuesta IA' AFTER account_id",
    'response_text' => "ALTER TABLE {$table} ADD response_text TEXT NOT NULL AFTER title",
    'response_hash' => "ALTER TABLE {$table} ADD response_hash CHAR(64) NOT NULL DEFAULT '' AFTER response_text",
    'category' => "ALTER TABLE {$table} ADD category VARCHAR(60) NOT NULL DEFAULT 'ai_suggestion' AFTER response_hash",
    'source' => "ALTER TABLE {$table} ADD source VARCHAR(60) NOT NULL DEFAULT 'ai_suggestion' AFTER category",
    'source_conversation_id' => "ALTER TABLE {$table} ADD source_conversation_id INT UNSIGNED NULL AFTER source",
    'source_message_id' => "ALTER TABLE {$table} ADD source_message_id INT UNSIGNED NULL AFTER source_conversation_id",
    'is_approved' => "ALTER TABLE {$table} ADD is_approved TINYINT(1) NOT NULL DEFAULT 0 AFTER source_message_id",
    'is_active' => "ALTER TABLE {$table} ADD is_active TINYINT(1) NOT NULL DEFAULT 0 AFTER is_approved",
    'usage_count' => "ALTER TABLE {$table} ADD usage_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER is_active",
    'last_used_at' => "ALTER TABLE {$table} ADD last_used_at DATETIME NULL AFTER usage_count",
    'created_by' => "ALTER TABLE {$table} ADD created_by INT UNSIGNED NULL AFTER last_used_at",
    'approved_by' => "ALTER TABLE {$table} ADD approved_by INT UNSIGNED NULL AFTER created_by",
    'approved_at' => "ALTER TABLE {$table} ADD approved_at DATETIME NULL AFTER approved_by",
    'updated_at' => "ALTER TABLE {$table} ADD updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP",
  ];

  foreach ($columns as $column => $sql) {
    try {
      if (!account_column_exists($pdo, $dbName, $table, $column)) $pdo->exec($sql);
    } catch (Throwable $e) {
      ai_knowledge_log_error('No se pudo ajustar columna', ['column' => $column, 'error' => $e->getMessage()]);
    }
  }

  try {
    if (!account_index_exists($pdo, $dbName, $table, 'uniq_account_response_hash')) {
      $pdo->exec("ALTER TABLE {$table} ADD UNIQUE KEY uniq_account_response_hash (account_id, response_hash)");
    }
  } catch (Throwable $e) {
    ai_knowledge_log_error('No se pudo crear indice unico', ['error' => $e->getMessage()]);
  }
}

function ai_knowledge_clean_text($value, int $max = 2000): string {
  $text = trim(str_replace("\0", '', (string) $value));
  if ($text === '') return '';
  return function_exists('mb_substr') ? mb_substr($text, 0, $max, 'UTF-8') : substr($text, 0, $max);
}

function ai_knowledge_hash(string $text): string {
  $normalized = preg_replace('/\s+/u', ' ', trim($text)) ?: trim($text);
  $normalized = function_exists('mb_strtolower') ? mb_strtolower($normalized, 'UTF-8') : strtolower($normalized);
  return hash('sha256', $normalized);
}

function ai_knowledge_title_from_text(string $text): string {
  $firstLine = trim((string) preg_split('/\R/u', $text)[0]);
  $firstLine = $firstLine !== '' ? $firstLine : 'Respuesta IA';
  return ai_knowledge_clean_text($firstLine, 90);
}

function ai_knowledge_create_suggestion(PDO $pdo, int $accountId, int $conversationId, string $reply, array $meta = []): void {
  try {
    ai_knowledge_ensure_schema($pdo);
    $reply = ai_knowledge_clean_text($reply, 2000);
    if ($accountId <= 0 || $reply === '') return;

    $table = ai_knowledge_table();
    $title = ai_knowledge_clean_text((string) ($meta['title'] ?? ''), 120);
    if ($title === '') $title = ai_knowledge_title_from_text($reply);
    $hash = ai_knowledge_hash($reply);
    $createdBy = max(0, (int) ($meta['created_by'] ?? ($_SESSION['user_id'] ?? 0))) ?: null;

    $stmt = $pdo->prepare("
      INSERT INTO {$table}
        (account_id, title, response_text, response_hash, category, source, source_conversation_id, created_by, is_approved, is_active)
      VALUES
        (?, ?, ?, ?, ?, ?, ?, ?, 0, 0)
      ON DUPLICATE KEY UPDATE
        updated_at = NOW(),
        source_conversation_id = COALESCE(source_conversation_id, VALUES(source_conversation_id))
    ");
    $stmt->execute([
      $accountId,
      $title,
      $reply,
      $hash,
      ai_knowledge_clean_text($meta['category'] ?? 'ai_suggestion', 60),
      ai_knowledge_clean_text($meta['source'] ?? 'ai_suggestion', 60),
      $conversationId > 0 ? $conversationId : null,
      $createdBy,
    ]);
  } catch (Throwable $e) {
    ai_knowledge_log_error('No se pudo guardar sugerencia IA', ['error' => $e->getMessage()]);
  }
}

function ai_knowledge_active_items(PDO $pdo, int $accountId, int $limit = 8): array {
  ai_knowledge_ensure_schema($pdo);
  if ($accountId <= 0) return [];

  $limit = max(1, min(20, $limit));
  $table = ai_knowledge_table();
  $stmt = $pdo->prepare("
    SELECT id, title, response_text, category, usage_count
    FROM {$table}
    WHERE account_id = ?
      AND is_approved = 1
      AND is_active = 1
    ORDER BY usage_count ASC, updated_at DESC, id DESC
    LIMIT {$limit}
  ");
  $stmt->execute([$accountId]);
  return $stmt->fetchAll() ?: [];
}
