<?php
// config/ad_attribution.php
declare(strict_types=1);

require_once __DIR__ . '/accounts.php';

function ads_campaigns_table(): string {
  return safe_identifier((string) app_config('database.ad_campaigns_table', 'ad_campaigns'), 'ad_campaigns');
}

function ads_adsets_table(): string {
  return safe_identifier((string) app_config('database.ad_sets_table', 'ad_sets'), 'ad_sets');
}

function ads_ads_table(): string {
  return safe_identifier((string) app_config('database.ads_table', 'ads'), 'ads');
}

function ads_column_exists(PDO $pdo, string $dbName, string $table, string $column): bool {
  if ($dbName === '') return false;
  $stmt = $pdo->prepare('SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?');
  $stmt->execute([$dbName, $table, $column]);
  return (bool) $stmt->fetchColumn();
}

function ads_index_exists(PDO $pdo, string $dbName, string $table, string $index): bool {
  if ($dbName === '') return false;
  $stmt = $pdo->prepare('SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND INDEX_NAME=?');
  $stmt->execute([$dbName, $table, $index]);
  return (bool) $stmt->fetchColumn();
}

function ads_clean($value, int $max = 180): ?string {
  $value = trim(str_replace("\0", '', (string) $value));
  if ($value === '') return null;
  return mb_substr($value, 0, $max);
}

function ads_normalized_key(?string $externalId, ?string $name, string $prefix): ?string {
  $externalId = ads_clean($externalId, 120);
  if ($externalId !== null) return 'id:' . $externalId;
  $name = ads_clean($name, 180);
  if ($name === null) return null;
  return $prefix . ':name:' . sha1(mb_strtolower($name));
}

function ads_ensure_schema(PDO $pdo, ?string $leadsTable = null): void {
  global $DB_NAME, $TABLE_LEADS;
  $dbName = (string) ($DB_NAME ?? '');
  $defaultAccountId = accounts_default_id($pdo);
  $campaignsTable = ads_campaigns_table();
  $adsetsTable = ads_adsets_table();
  $adsTable = ads_ads_table();

  $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS {$campaignsTable} (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  account_id INT UNSIGNED NOT NULL DEFAULT {$defaultAccountId},
  provider VARCHAR(40) NOT NULL DEFAULT 'meta',
  campaign_key VARCHAR(180) NOT NULL,
  external_campaign_id VARCHAR(120) NULL,
  campaign_name VARCHAR(180) NOT NULL,
  first_seen_at DATETIME NULL,
  last_seen_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_campaign (account_id, provider, campaign_key),
  KEY idx_account_id (account_id),
  KEY idx_campaign_name (campaign_name),
  KEY idx_last_seen_at (last_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

  $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS {$adsetsTable} (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  account_id INT UNSIGNED NOT NULL DEFAULT {$defaultAccountId},
  campaign_ref_id INT UNSIGNED NOT NULL DEFAULT 0,
  provider VARCHAR(40) NOT NULL DEFAULT 'meta',
  adset_key VARCHAR(180) NOT NULL,
  external_adset_id VARCHAR(120) NULL,
  adset_name VARCHAR(180) NOT NULL,
  first_seen_at DATETIME NULL,
  last_seen_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_adset (account_id, provider, campaign_ref_id, adset_key),
  KEY idx_account_id (account_id),
  KEY idx_campaign_ref_id (campaign_ref_id),
  KEY idx_adset_name (adset_name),
  KEY idx_last_seen_at (last_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

  $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS {$adsTable} (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  account_id INT UNSIGNED NOT NULL DEFAULT {$defaultAccountId},
  campaign_ref_id INT UNSIGNED NOT NULL DEFAULT 0,
  adset_ref_id INT UNSIGNED NOT NULL DEFAULT 0,
  provider VARCHAR(40) NOT NULL DEFAULT 'meta',
  ad_key VARCHAR(180) NOT NULL,
  external_ad_id VARCHAR(120) NULL,
  ad_name VARCHAR(180) NOT NULL,
  first_seen_at DATETIME NULL,
  last_seen_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_ad (account_id, provider, ad_key),
  KEY idx_account_id (account_id),
  KEY idx_campaign_ref_id (campaign_ref_id),
  KEY idx_adset_ref_id (adset_ref_id),
  KEY idx_ad_name (ad_name),
  KEY idx_last_seen_at (last_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

  $leadsTable = safe_identifier((string) ($leadsTable ?: ($TABLE_LEADS ?? app_config('database.leads_table', 'leads'))), 'leads');
  $columns = [
    'campaign_ref_id' => "ALTER TABLE {$leadsTable} ADD COLUMN campaign_ref_id INT UNSIGNED NULL AFTER campaign_name",
    'adset_ref_id' => "ALTER TABLE {$leadsTable} ADD COLUMN adset_ref_id INT UNSIGNED NULL AFTER adset_name",
    'ad_ref_id' => "ALTER TABLE {$leadsTable} ADD COLUMN ad_ref_id INT UNSIGNED NULL AFTER ad_id",
  ];
  foreach ($columns as $column => $sql) {
    if (!ads_column_exists($pdo, $dbName, $leadsTable, $column)) {
      try { $pdo->exec($sql); } catch (Throwable $e) { /* no-op */ }
    }
  }

  $indexes = [
    'idx_campaign_ref_id' => "ALTER TABLE {$leadsTable} ADD KEY idx_campaign_ref_id (campaign_ref_id)",
    'idx_adset_ref_id' => "ALTER TABLE {$leadsTable} ADD KEY idx_adset_ref_id (adset_ref_id)",
    'idx_ad_ref_id' => "ALTER TABLE {$leadsTable} ADD KEY idx_ad_ref_id (ad_ref_id)",
  ];
  foreach ($indexes as $index => $sql) {
    if (!ads_index_exists($pdo, $dbName, $leadsTable, $index)) {
      try { $pdo->exec($sql); } catch (Throwable $e) { /* no-op */ }
    }
  }
}

function ads_select_id(PDO $pdo, string $table, string $keyColumn, int $accountId, string $provider, string $key, ?int $parentId = null): int {
  if ($keyColumn === 'adset_key') {
    $stmt = $pdo->prepare("SELECT id FROM {$table} WHERE account_id=? AND provider=? AND campaign_ref_id=? AND {$keyColumn}=? LIMIT 1");
    $stmt->execute([$accountId, $provider, max(0, (int) $parentId), $key]);
  } else {
    $stmt = $pdo->prepare("SELECT id FROM {$table} WHERE account_id=? AND provider=? AND {$keyColumn}=? LIMIT 1");
    $stmt->execute([$accountId, $provider, $key]);
  }
  return (int) ($stmt->fetchColumn() ?: 0);
}

function ads_upsert_from_ref(PDO $pdo, int $accountId, array $ref, string $provider = 'meta', ?string $seenAt = null, ?string $leadsTable = null): array {
  ads_ensure_schema($pdo, $leadsTable);
  $seenAt = ads_clean($seenAt, 19) ?: gmdate('Y-m-d H:i:s');
  $accountId = $accountId > 0 ? $accountId : accounts_default_id($pdo);
  $provider = ads_clean($provider, 40) ?: 'meta';

  $campaignId = ads_clean($ref['campaign_id'] ?? null, 120);
  $campaignName = ads_clean($ref['campaign_name'] ?? $ref['campaign'] ?? null, 180);
  $campaignKey = ads_normalized_key($campaignId, $campaignName, 'campaign');
  $campaignRefId = 0;
  if ($campaignKey !== null) {
    $campaignName = $campaignName ?: ($campaignId ? 'Campaña ' . $campaignId : 'Campaña sin nombre');
    $table = ads_campaigns_table();
    $stmt = $pdo->prepare(<<<SQL
INSERT INTO {$table} (account_id, provider, campaign_key, external_campaign_id, campaign_name, first_seen_at, last_seen_at)
VALUES (?, ?, ?, ?, ?, ?, ?)
ON DUPLICATE KEY UPDATE
  external_campaign_id = COALESCE(VALUES(external_campaign_id), external_campaign_id),
  campaign_name = COALESCE(NULLIF(VALUES(campaign_name), ''), campaign_name),
  last_seen_at = GREATEST(COALESCE(last_seen_at, VALUES(last_seen_at)), VALUES(last_seen_at)),
  updated_at = NOW()
SQL);
    $stmt->execute([$accountId, $provider, $campaignKey, $campaignId, $campaignName, $seenAt, $seenAt]);
    $campaignRefId = ads_select_id($pdo, $table, 'campaign_key', $accountId, $provider, $campaignKey);
  }

  $adsetId = ads_clean($ref['adset_id'] ?? null, 120);
  $adsetName = ads_clean($ref['adset_name'] ?? null, 180);
  $adsetKey = ads_normalized_key($adsetId, $adsetName, 'adset');
  $adsetRefId = 0;
  if ($adsetKey !== null) {
    $adsetName = $adsetName ?: ($adsetId ? 'Conjunto ' . $adsetId : 'Conjunto sin nombre');
    $table = ads_adsets_table();
    $stmt = $pdo->prepare(<<<SQL
INSERT INTO {$table} (account_id, campaign_ref_id, provider, adset_key, external_adset_id, adset_name, first_seen_at, last_seen_at)
VALUES (?, ?, ?, ?, ?, ?, ?, ?)
ON DUPLICATE KEY UPDATE
  external_adset_id = COALESCE(VALUES(external_adset_id), external_adset_id),
  adset_name = COALESCE(NULLIF(VALUES(adset_name), ''), adset_name),
  last_seen_at = GREATEST(COALESCE(last_seen_at, VALUES(last_seen_at)), VALUES(last_seen_at)),
  updated_at = NOW()
SQL);
    $stmt->execute([$accountId, $campaignRefId, $provider, $adsetKey, $adsetId, $adsetName, $seenAt, $seenAt]);
    $adsetRefId = ads_select_id($pdo, $table, 'adset_key', $accountId, $provider, $adsetKey, $campaignRefId);
  }

  $adId = ads_clean($ref['ad_id'] ?? null, 120);
  $adName = ads_clean($ref['ad_name'] ?? $ref['content'] ?? null, 180);
  $adKey = ads_normalized_key($adId, $adName, 'ad');
  $adRefId = 0;
  if ($adKey !== null) {
    $adName = $adName ?: ($adId ? 'Anuncio ' . $adId : 'Anuncio sin nombre');
    $table = ads_ads_table();
    $stmt = $pdo->prepare(<<<SQL
INSERT INTO {$table} (account_id, campaign_ref_id, adset_ref_id, provider, ad_key, external_ad_id, ad_name, first_seen_at, last_seen_at)
VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
ON DUPLICATE KEY UPDATE
  campaign_ref_id = CASE WHEN VALUES(campaign_ref_id) > 0 THEN VALUES(campaign_ref_id) ELSE campaign_ref_id END,
  adset_ref_id = CASE WHEN VALUES(adset_ref_id) > 0 THEN VALUES(adset_ref_id) ELSE adset_ref_id END,
  external_ad_id = COALESCE(VALUES(external_ad_id), external_ad_id),
  ad_name = COALESCE(NULLIF(VALUES(ad_name), ''), ad_name),
  last_seen_at = GREATEST(COALESCE(last_seen_at, VALUES(last_seen_at)), VALUES(last_seen_at)),
  updated_at = NOW()
SQL);
    $stmt->execute([$accountId, $campaignRefId, $adsetRefId, $provider, $adKey, $adId, $adName, $seenAt, $seenAt]);
    $adRefId = ads_select_id($pdo, $table, 'ad_key', $accountId, $provider, $adKey);
  }

  return [
    'campaign_ref_id' => $campaignRefId > 0 ? $campaignRefId : null,
    'adset_ref_id' => $adsetRefId > 0 ? $adsetRefId : null,
    'ad_ref_id' => $adRefId > 0 ? $adRefId : null,
  ];
}

function ads_backfill_from_leads(PDO $pdo, string $leadsTable, int $limit = 500): int {
  ads_ensure_schema($pdo, $leadsTable);
  $leadsTable = safe_identifier($leadsTable, 'leads');
  $sql = <<<SQL
SELECT id, account_id, campaign_id, campaign_name, utm_campaign, adset_id, adset_name, ad_name, ad_id, utm_content, created_at, last_message_at
FROM {$leadsTable}
WHERE (campaign_ref_id IS NULL OR adset_ref_id IS NULL OR ad_ref_id IS NULL)
  AND (
    NULLIF(campaign_id, '') IS NOT NULL
    OR NULLIF(campaign_name, '') IS NOT NULL
    OR NULLIF(utm_campaign, '') IS NOT NULL
    OR NULLIF(adset_id, '') IS NOT NULL
    OR NULLIF(adset_name, '') IS NOT NULL
    OR NULLIF(ad_name, '') IS NOT NULL
    OR NULLIF(ad_id, '') IS NOT NULL
    OR NULLIF(utm_content, '') IS NOT NULL
  )
ORDER BY id DESC
LIMIT ?
SQL;
  $stmt = $pdo->prepare($sql);
  $stmt->bindValue(1, max(1, $limit), PDO::PARAM_INT);
  $stmt->execute();
  $updated = 0;
  $update = $pdo->prepare("UPDATE {$leadsTable} SET campaign_ref_id=COALESCE(?, campaign_ref_id), adset_ref_id=COALESCE(?, adset_ref_id), ad_ref_id=COALESCE(?, ad_ref_id) WHERE id=?");
  foreach ($stmt->fetchAll() ?: [] as $lead) {
    $refs = ads_upsert_from_ref($pdo, (int) ($lead['account_id'] ?? 0), [
      'campaign_id' => $lead['campaign_id'] ?? null,
      'campaign_name' => $lead['campaign_name'] ?? $lead['utm_campaign'] ?? null,
      'campaign' => $lead['utm_campaign'] ?? null,
      'adset_id' => $lead['adset_id'] ?? null,
      'adset_name' => $lead['adset_name'] ?? null,
      'ad_name' => $lead['ad_name'] ?? null,
      'ad_id' => $lead['ad_id'] ?? null,
      'content' => $lead['utm_content'] ?? null,
    ], 'meta', (string) ($lead['last_message_at'] ?? $lead['created_at'] ?? gmdate('Y-m-d H:i:s')), $leadsTable);
    $update->execute([$refs['campaign_ref_id'], $refs['adset_ref_id'], $refs['ad_ref_id'], (int) $lead['id']]);
    $updated += $update->rowCount() > 0 ? 1 : 0;
  }
  return $updated;
}
