<?php
// config/instagram_channels.php
declare(strict_types=1);
require_once __DIR__ . '/accounts.php';

function ig_channels_table(): string {
  return safe_identifier((string) app_config('database.instagram_channels_table', 'instagram_channels'), 'instagram_channels');
}

function ig_channels_ensure_schema(PDO $pdo, string $table): void {
  global $DB_NAME;
  $defaultAccountId = accounts_default_id($pdo);
  $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS {$table} (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  account_id INT UNSIGNED NOT NULL DEFAULT {$defaultAccountId},
  page_id VARCHAR(120) NOT NULL,
  page_name VARCHAR(180) NULL,
  instagram_user_id VARCHAR(120) NOT NULL,
  instagram_username VARCHAR(180) NULL,
  page_access_token TEXT NULL,
  connected_by INT UNSIGNED NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  last_event_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_page_id (page_id),
  UNIQUE KEY uniq_instagram_user_id (instagram_user_id),
  KEY idx_account_id (account_id),
  KEY idx_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);
  try { accounts_add_account_column($pdo, (string) ($DB_NAME ?? ''), $table, $defaultAccountId); } catch (Throwable $e) { /* no-op */ }
}

function ig_graph_base(): string {
  $version = trim((string) app_config('instagram.graph_version', 'v20.0'));
  $version = preg_match('/^v\d+\.\d+$/', $version) ? $version : 'v20.0';
  return 'https://graph.facebook.com/' . $version;
}

function ig_graph_request(string $method, string $path, array $params = []): array {
  $method = strtoupper($method);
  $url = rtrim(ig_graph_base(), '/') . '/' . ltrim($path, '/');
  $ch = curl_init();
  if ($method === 'GET') {
    if ($params) $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($params);
  } else {
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
  }
  curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 25,
  ]);
  $raw = curl_exec($ch);
  $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $error = curl_error($ch);
  curl_close($ch);
  $json = json_decode((string) $raw, true);
  if ($error !== '' || $http < 200 || $http >= 300 || !is_array($json)) {
    return ['ok' => false, 'http' => $http, 'error' => $error !== '' ? $error : ($json['error']['message'] ?? 'Respuesta invalida de Meta'), 'raw' => $raw];
  }
  return ['ok' => true, 'http' => $http, 'data' => $json];
}

function ig_channel_find_by_recipient(PDO $pdo, string $table, ?string $recipientId): ?array {
  $recipientId = trim((string) $recipientId);
  if ($recipientId === '') return null;
  try {
    $stmt = $pdo->prepare("SELECT * FROM {$table} WHERE is_active=1 AND (page_id=? OR instagram_user_id=?) LIMIT 1");
    $stmt->execute([$recipientId, $recipientId]);
    $row = $stmt->fetch();
    return $row ?: null;
  } catch (Throwable $e) {
    return null;
  }
}

function ig_channel_upsert(PDO $pdo, string $table, array $channel): void {
  $accountId = (int) (($channel['account_id'] ?? current_account_id()) ?: accounts_default_id($pdo));
  $stmt = $pdo->prepare(<<<SQL
INSERT INTO {$table} (
  account_id, page_id, page_name, instagram_user_id, instagram_username, page_access_token, connected_by, is_active, updated_at
) VALUES (?, ?, ?, ?, ?, ?, ?, 1, NOW())
ON DUPLICATE KEY UPDATE
  account_id = VALUES(account_id),
  page_name = VALUES(page_name),
  instagram_user_id = VALUES(instagram_user_id),
  instagram_username = VALUES(instagram_username),
  page_access_token = VALUES(page_access_token),
  connected_by = VALUES(connected_by),
  is_active = 1,
  updated_at = NOW()
SQL);
  $stmt->execute([
    $accountId,
    (string) $channel['page_id'],
    $channel['page_name'] ?? null,
    (string) $channel['instagram_user_id'],
    $channel['instagram_username'] ?? null,
    $channel['page_access_token'] ?? null,
    $channel['connected_by'] ?? null,
  ]);
}
