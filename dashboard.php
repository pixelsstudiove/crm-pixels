<?php
// dashboard.php
declare(strict_types=1);
require_once __DIR__ . '/auth/require_auth.php';
require_once __DIR__ . '/config/conversations.php';
require_once __DIR__ . '/config/lead_status_history.php';
require_once __DIR__ . '/config/navigation.php';

function column_exists_dash(PDO $pdo, string $dbName, string $table, string $column): bool {
  $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?");
  $stmt->execute([$dbName, $table, $column]);
  return (int) $stmt->fetchColumn() > 0;
}
function index_exists_dash(PDO $pdo, string $dbName, string $table, string $index): bool {
  $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?");
  $stmt->execute([$dbName, $table, $index]);
  return (int) $stmt->fetchColumn() > 0;
}
function ensure_dashboard_schema(PDO $pdo, string $dbName, string $table): void {
  $defaultAccountId = accounts_default_id($pdo);
  $defaultSalesStatus = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) app_config('sales_funnel.default_status', 'nuevo_lead')) ?: 'nuevo_lead';
  $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS {$table} (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  account_id INT UNSIGNED NOT NULL DEFAULT {$defaultAccountId},
  fullname VARCHAR(120) NOT NULL,
  phone VARCHAR(64) NULL,
  email VARCHAR(150) NULL,
  brand_instagram VARCHAR(120) NULL,
  business_type VARCHAR(80) NULL,
  business_type_other VARCHAR(120) NULL,
  services_needed TEXT NULL,
  main_objective VARCHAR(120) NULL,
  message TEXT NULL,
  source_platform VARCHAR(80) NULL,
  utm_source VARCHAR(80) NULL,
  utm_medium VARCHAR(80) NULL,
  utm_campaign VARCHAR(120) NULL,
  utm_content VARCHAR(160) NULL,
  utm_term VARCHAR(160) NULL,
  ad_name VARCHAR(180) NULL,
  ad_id VARCHAR(120) NULL,
  gclid VARCHAR(180) NULL,
  fbclid VARCHAR(180) NULL,
  landing_url TEXT NULL,
  referrer TEXT NULL,
  sales_status VARCHAR(50) NOT NULL DEFAULT '{$defaultSalesStatus}',
  notes TEXT NULL,
  reminder_at DATETIME NULL,
  reminder_note VARCHAR(255) NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'pending',
  ip VARCHAR(64) NULL,
  user_agent VARCHAR(255) NULL,
  whatsapp_sent TINYINT(1) NOT NULL DEFAULT 0,
  whatsapp_status VARCHAR(32) NULL,
  external_source VARCHAR(40) NULL,
  external_contact_id VARCHAR(120) NULL,
  external_thread_id VARCHAR(120) NULL,
  last_external_message_id VARCHAR(120) NULL,
  first_message_at DATETIME NULL,
  last_message_at DATETIME NULL,
  last_inbound_message TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_phone (phone),
  UNIQUE KEY uniq_external_contact (account_id, external_source, external_contact_id),
  KEY idx_account_id (account_id),
  KEY idx_brand_instagram (brand_instagram),
  KEY idx_business_type (business_type),
  KEY idx_main_objective (main_objective),
  KEY idx_source_platform (source_platform),
  KEY idx_utm_campaign (utm_campaign),
  KEY idx_utm_content (utm_content),
  KEY idx_ad_name (ad_name),
  KEY idx_sales_status (sales_status),
  KEY idx_status (status),
  KEY idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

  try { accounts_add_account_column($pdo, $dbName, $table, $defaultAccountId); } catch (Throwable $e) { /* no-op */ }

  $columns = [
    'account_id' => "ALTER TABLE {$table} ADD COLUMN account_id INT UNSIGNED NOT NULL DEFAULT {$defaultAccountId} AFTER id",
    'phone' => "ALTER TABLE {$table} ADD COLUMN phone VARCHAR(64) NULL AFTER fullname",
    'email' => "ALTER TABLE {$table} ADD COLUMN email VARCHAR(150) NULL AFTER phone",
    'brand_instagram' => "ALTER TABLE {$table} ADD COLUMN brand_instagram VARCHAR(120) NULL AFTER email",
    'business_type' => "ALTER TABLE {$table} ADD COLUMN business_type VARCHAR(80) NULL AFTER brand_instagram",
    'business_type_other' => "ALTER TABLE {$table} ADD COLUMN business_type_other VARCHAR(120) NULL AFTER business_type",
    'services_needed' => "ALTER TABLE {$table} ADD COLUMN services_needed TEXT NULL AFTER business_type_other",
    'main_objective' => "ALTER TABLE {$table} ADD COLUMN main_objective VARCHAR(120) NULL AFTER services_needed",
    'message' => "ALTER TABLE {$table} ADD COLUMN message TEXT NULL AFTER main_objective",
    'source_platform' => "ALTER TABLE {$table} ADD COLUMN source_platform VARCHAR(80) NULL AFTER message",
    'utm_source' => "ALTER TABLE {$table} ADD COLUMN utm_source VARCHAR(80) NULL AFTER source_platform",
    'utm_medium' => "ALTER TABLE {$table} ADD COLUMN utm_medium VARCHAR(80) NULL AFTER utm_source",
    'utm_campaign' => "ALTER TABLE {$table} ADD COLUMN utm_campaign VARCHAR(120) NULL AFTER utm_medium",
    'utm_content' => "ALTER TABLE {$table} ADD COLUMN utm_content VARCHAR(160) NULL AFTER utm_campaign",
    'utm_term' => "ALTER TABLE {$table} ADD COLUMN utm_term VARCHAR(160) NULL AFTER utm_content",
    'ad_name' => "ALTER TABLE {$table} ADD COLUMN ad_name VARCHAR(180) NULL AFTER utm_term",
    'ad_id' => "ALTER TABLE {$table} ADD COLUMN ad_id VARCHAR(120) NULL AFTER ad_name",
    'gclid' => "ALTER TABLE {$table} ADD COLUMN gclid VARCHAR(180) NULL AFTER ad_id",
    'fbclid' => "ALTER TABLE {$table} ADD COLUMN fbclid VARCHAR(180) NULL AFTER gclid",
    'landing_url' => "ALTER TABLE {$table} ADD COLUMN landing_url TEXT NULL AFTER fbclid",
    'referrer' => "ALTER TABLE {$table} ADD COLUMN referrer TEXT NULL AFTER landing_url",
    'sales_status' => "ALTER TABLE {$table} ADD COLUMN sales_status VARCHAR(50) NOT NULL DEFAULT '{$defaultSalesStatus}' AFTER referrer",
    'notes' => "ALTER TABLE {$table} ADD COLUMN notes TEXT NULL AFTER sales_status",
    'reminder_at' => "ALTER TABLE {$table} ADD COLUMN reminder_at DATETIME NULL AFTER notes",
    'reminder_note' => "ALTER TABLE {$table} ADD COLUMN reminder_note VARCHAR(255) NULL AFTER reminder_at",
    'status' => "ALTER TABLE {$table} ADD COLUMN status VARCHAR(32) NOT NULL DEFAULT 'pending'",
    'ip' => "ALTER TABLE {$table} ADD COLUMN ip VARCHAR(64) NULL",
    'user_agent' => "ALTER TABLE {$table} ADD COLUMN user_agent VARCHAR(255) NULL",
    'whatsapp_sent' => "ALTER TABLE {$table} ADD COLUMN whatsapp_sent TINYINT(1) NOT NULL DEFAULT 0",
    'whatsapp_status' => "ALTER TABLE {$table} ADD COLUMN whatsapp_status VARCHAR(32) NULL",
    'external_source' => "ALTER TABLE {$table} ADD COLUMN external_source VARCHAR(40) NULL AFTER whatsapp_status",
    'external_contact_id' => "ALTER TABLE {$table} ADD COLUMN external_contact_id VARCHAR(120) NULL AFTER external_source",
    'external_thread_id' => "ALTER TABLE {$table} ADD COLUMN external_thread_id VARCHAR(120) NULL AFTER external_contact_id",
    'last_external_message_id' => "ALTER TABLE {$table} ADD COLUMN last_external_message_id VARCHAR(120) NULL AFTER external_thread_id",
    'first_message_at' => "ALTER TABLE {$table} ADD COLUMN first_message_at DATETIME NULL AFTER last_external_message_id",
    'last_message_at' => "ALTER TABLE {$table} ADD COLUMN last_message_at DATETIME NULL AFTER first_message_at",
    'last_inbound_message' => "ALTER TABLE {$table} ADD COLUMN last_inbound_message TEXT NULL AFTER last_message_at",
    'updated_at' => "ALTER TABLE {$table} ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP",
  ];
  foreach ($columns as $column => $alterSql) if (!column_exists_dash($pdo, $dbName, $table, $column)) $pdo->exec($alterSql);

  foreach ([
    'phone' => 'VARCHAR(64) NULL',
    'email' => 'VARCHAR(150) NULL',
    'brand_instagram' => 'VARCHAR(120) NULL',
    'business_type' => 'VARCHAR(80) NULL',
    'services_needed' => 'TEXT NULL',
    'main_objective' => 'VARCHAR(120) NULL',
  ] as $column => $definition) {
    if (column_exists_dash($pdo, $dbName, $table, $column)) {
      try { $pdo->exec("ALTER TABLE {$table} MODIFY {$column} {$definition}"); } catch (Throwable $e) { /* no-op */ }
    }
  }

  foreach (['brand_business','city_country','current_situation','budget_range','start_timeline','location_state','interest_category','sexo','age','birthdate'] as $legacyColumn) {
    if (column_exists_dash($pdo, $dbName, $table, $legacyColumn)) {
      try { $pdo->exec("ALTER TABLE {$table} MODIFY {$legacyColumn} VARCHAR(160) NULL"); } catch (Throwable $e) { /* no-op */ }
    }
  }

  $indexes = [
    'idx_account_id' => "ALTER TABLE {$table} ADD KEY idx_account_id (account_id)",
    'idx_brand_instagram' => "ALTER TABLE {$table} ADD KEY idx_brand_instagram (brand_instagram)",
    'idx_business_type' => "ALTER TABLE {$table} ADD KEY idx_business_type (business_type)",
    'idx_main_objective' => "ALTER TABLE {$table} ADD KEY idx_main_objective (main_objective)",
    'idx_source_platform' => "ALTER TABLE {$table} ADD KEY idx_source_platform (source_platform)",
    'idx_utm_campaign' => "ALTER TABLE {$table} ADD KEY idx_utm_campaign (utm_campaign)",
    'idx_utm_content' => "ALTER TABLE {$table} ADD KEY idx_utm_content (utm_content)",
    'idx_ad_name' => "ALTER TABLE {$table} ADD KEY idx_ad_name (ad_name)",
    'idx_sales_status' => "ALTER TABLE {$table} ADD KEY idx_sales_status (sales_status)",
    'idx_reminder_at' => "ALTER TABLE {$table} ADD KEY idx_reminder_at (reminder_at)",
    'uniq_external_contact' => "ALTER TABLE {$table} ADD UNIQUE KEY uniq_external_contact (account_id, external_source, external_contact_id)",
    'idx_last_message_at' => "ALTER TABLE {$table} ADD KEY idx_last_message_at (last_message_at)",
    'idx_status' => "ALTER TABLE {$table} ADD KEY idx_status (status)",
    'idx_created_at' => "ALTER TABLE {$table} ADD KEY idx_created_at (created_at)",
  ];
  accounts_rebuild_unique_index($pdo, $dbName, $table, 'uniq_external_contact', 'account_id, external_source, external_contact_id');
  foreach ($indexes as $index => $sql) if (!index_exists_dash($pdo, $dbName, $table, $index)) { try { $pdo->exec($sql); } catch (Throwable $e) {} }
}

ensure_dashboard_schema($pdo, $DB_NAME, $TABLE_LEADS);
conv_ensure_schema($pdo);
lead_status_normalize_legacy_statuses($pdo, $TABLE_LEADS);
lead_status_auto_mark_no_response($pdo, $TABLE_LEADS, is_super_admin() ? null : (int) (current_account_id() ?: accounts_default_id($pdo)));

$contactsTable = conv_contacts_table();
$conversationsTable = conv_conversations_table();
$channelsTable = ig_channels_table();
$messagesTable = conv_messages_table();

$currentRole = current_user_role();
$currentRoleLabel = role_label($currentRole);
require_permission('view_dashboard');
$canEditLeads = can('edit_leads');
$canManageUsers = can('manage_users');
$canManageIntegrations = can('manage_integrations');

$q = trim((string) ($_GET['q'] ?? ''));
$currentAccountId = (int) (current_account_id() ?: accounts_default_id($pdo));
$requestSlug = accounts_request_slug();
$requestAccount = accounts_request_account($pdo);
if ($requestSlug !== '' && !$requestAccount) {
  http_response_code(404);
  exit('Cuenta no encontrada.');
}
$requestAccountId = $requestAccount ? (int) ($requestAccount['id'] ?? 0) : 0;
$accountOptions = [];
$filterAccountId = 0;
if (is_super_admin()) {
  try {
    $accountStmt = $pdo->query("SELECT id, name, slug FROM " . accounts_table() . " ORDER BY name ASC");
    $accountOptions = $accountStmt ? $accountStmt->fetchAll() : [];
  } catch (Throwable $e) {
    $accountOptions = [];
  }
  $accountIds = array_map(static fn($row) => (int) ($row['id'] ?? 0), $accountOptions);
  $filterAccountId = $requestAccountId > 0 ? $requestAccountId : max(0, (int) ($_GET['account_id'] ?? 0));
  if ($filterAccountId > 0 && !in_array($filterAccountId, $accountIds, true)) $filterAccountId = 0;
}

$channelOptions = [];
try {
  $channelSql = <<<SQL
SELECT DISTINCT ch.id, ch.page_name, ch.page_id, ch.instagram_username
FROM {$conversationsTable} c
JOIN {$channelsTable} ch ON ch.id = c.channel_id
%s
ORDER BY COALESCE(ch.instagram_username, ch.page_name, ch.page_id) ASC
SQL;
  if (is_super_admin() && $filterAccountId > 0) {
    $channelStmt = $pdo->prepare(sprintf($channelSql, 'WHERE c.account_id = ?'));
    $channelStmt->execute([$filterAccountId]);
  } elseif (is_super_admin()) {
    $channelStmt = $pdo->query(sprintf($channelSql, ''));
  } else {
    $channelStmt = $pdo->prepare(sprintf($channelSql, 'WHERE c.account_id = ?'));
    $channelStmt->execute([$currentAccountId]);
  }
  $channelOptions = $channelStmt ? $channelStmt->fetchAll() : [];
} catch (Throwable $e) {
  $channelOptions = [];
}
$channelIds = array_map(static fn($row) => (int) ($row['id'] ?? 0), $channelOptions);
$filterChannelId = max(0, (int) ($_GET['channel_id'] ?? 0));
if ($filterChannelId > 0 && !in_array($filterChannelId, $channelIds, true)) $filterChannelId = 0;

$salesStatusOptions = (array) app_config('sales_funnel.statuses', []);

$defaultSalesStatus = (string) app_config('sales_funnel.default_status', 'nuevo_lead');
$filterSalesStatus = trim((string) ($_GET['sales_status'] ?? ''));
if ($filterSalesStatus !== '' && !array_key_exists($filterSalesStatus, $salesStatusOptions)) $filterSalesStatus = '';
$whereConditions = [];
$whereParams = [];
if (!is_super_admin()) {
  $whereConditions[] = 'c.account_id = :account_id';
  $whereParams[':account_id'] = $currentAccountId;
} elseif ($filterAccountId > 0) {
  $whereConditions[] = 'c.account_id = :account_id';
  $whereParams[':account_id'] = $filterAccountId;
}
if ($filterChannelId > 0) {
  $whereConditions[] = 'c.channel_id = :channel_id';
  $whereParams[':channel_id'] = $filterChannelId;
}
if ($q !== '') {
  $digits = preg_replace('/\D+/', '', $q) ?: $q;
  $whereConditions[] = "(c.public_id LIKE :q OR l.id LIKE :q OR ct.display_name LIKE :q OR ct.username LIKE :q OR ct.external_contact_id LIKE :q OR c.last_message_preview LIKE :q OR ch.page_name LIKE :q OR ch.instagram_username LIKE :q OR l.fullname LIKE :q OR l.phone LIKE :q OR REPLACE(COALESCE(l.phone,''),'-','') LIKE :qd OR l.email LIKE :q OR l.brand_instagram LIKE :q OR l.business_type LIKE :q OR l.business_type_other LIKE :q OR l.services_needed LIKE :q OR l.main_objective LIKE :q OR l.message LIKE :q OR l.last_inbound_message LIKE :q OR l.source_platform LIKE :q OR l.utm_source LIKE :q OR l.utm_medium LIKE :q OR l.utm_campaign LIKE :q OR l.utm_content LIKE :q OR l.utm_term LIKE :q OR l.ad_name LIKE :q OR l.ad_id LIKE :q OR l.external_contact_id LIKE :q OR l.sales_status LIKE :q OR l.notes LIKE :q OR l.reminder_note LIKE :q)";
  $whereParams[':q'] = '%' . $q . '%';
  $whereParams[':qd'] = '%' . $digits . '%';
}

$whereSql = $whereConditions ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
$funnelWhereConditions = $whereConditions;
$funnelWhereParams = $whereParams;
if ($filterSalesStatus !== '') {
  $funnelWhereConditions[] = 'COALESCE(l.sales_status, :default_sales_status_filter) = :sales_status_filter';
  $funnelWhereParams[':default_sales_status_filter'] = $defaultSalesStatus;
  $funnelWhereParams[':sales_status_filter'] = $filterSalesStatus;
}
$funnelWhereSql = $funnelWhereConditions ? 'WHERE ' . implode(' AND ', $funnelWhereConditions) : '';
$activeFilters = array_filter([
  'account_id' => $filterAccountId > 0 && $requestSlug === '' ? $filterAccountId : null,
  'channel_id' => $filterChannelId > 0 ? $filterChannelId : null,
  'q' => $q,
  'sales_status' => $filterSalesStatus,
], static fn($v) => $v !== '' && $v !== null);

$accountsTable = accounts_table();
$conversationFromSql = "FROM {$conversationsTable} c JOIN {$contactsTable} ct ON ct.id = c.contact_id LEFT JOIN {$channelsTable} ch ON ch.id = c.channel_id LEFT JOIN {$TABLE_LEADS} l ON l.id = c.lead_id LEFT JOIN {$accountsTable} a ON a.id = c.account_id";

$countSql = "SELECT COUNT(*) {$conversationFromSql} {$whereSql}";
$countStmt = $pdo->prepare($countSql);
foreach ($whereParams as $key => $value) $countStmt->bindValue($key, $value);
$countStmt->execute();
$total = (int) $countStmt->fetchColumn();

$statusCountsSql = "SELECT COALESCE(l.sales_status, :default_sales_status_count) AS sales_status, COUNT(*) AS total {$conversationFromSql} {$whereSql} GROUP BY COALESCE(l.sales_status, :default_sales_status_group)";
$statusCountsStmt = $pdo->prepare($statusCountsSql);
$statusCountsStmt->bindValue(':default_sales_status_count', $defaultSalesStatus);
$statusCountsStmt->bindValue(':default_sales_status_group', $defaultSalesStatus);
foreach ($whereParams as $key => $value) $statusCountsStmt->bindValue($key, $value);
$statusCountsStmt->execute();
$statusCounts = [];
foreach ($statusCountsStmt->fetchAll() as $row) {
  $statusCounts[(string) ($row['sales_status'] ?? '')] = (int) ($row['total'] ?? 0);
}
$summaryToneByStatus = [
  'nuevo_lead' => 'new',
  'en_conversacion' => 'scheduled',
  'propuesta_enviada' => 'proposal',
  'no_responde' => 'muted',
  'cliente_ganado' => 'won',
  'cliente_perdido' => 'lost',
  'no_califica' => 'default',
];
$summaryBaseParams = [];
if ($filterAccountId > 0 && $requestSlug === '') $summaryBaseParams['account_id'] = $filterAccountId;
if ($filterChannelId > 0) $summaryBaseParams['channel_id'] = $filterChannelId;
if ($q !== '') $summaryBaseParams['q'] = $q;
function dashboard_query_url(array $params): string {
  return account_url('dashboard.php', $params);
}
$summaryCards = [[
  'label' => 'Resultados',
  'value' => $total,
  'tone' => 'total',
  'href' => dashboard_query_url($summaryBaseParams),
  'active' => $filterSalesStatus === '',
]];
foreach ($salesStatusOptions as $statusValue => $statusLabel) {
  $statusValue = (string) $statusValue;
  $summaryCards[] = [
    'label' => (string) $statusLabel,
    'value' => $statusCounts[$statusValue] ?? 0,
    'tone' => $summaryToneByStatus[$statusValue] ?? 'default',
    'href' => dashboard_query_url($summaryBaseParams + ['sales_status' => $statusValue]),
    'active' => $filterSalesStatus === $statusValue,
  ];
}
$displayTotal = $filterSalesStatus !== '' ? ($statusCounts[$filterSalesStatus] ?? 0) : $total;
$filteredPageSize = 40;
$funnelPage = $filterSalesStatus !== '' ? max(1, (int) ($_GET['page'] ?? 1)) : 1;
$funnelLimit = $filterSalesStatus !== '' ? $filteredPageSize : 300;
$funnelTotalPages = $filterSalesStatus !== '' ? max(1, (int) ceil($displayTotal / $filteredPageSize)) : 1;
if ($funnelPage > $funnelTotalPages) $funnelPage = $funnelTotalPages;
$funnelOffset = $filterSalesStatus !== '' ? (($funnelPage - 1) * $filteredPageSize) : 0;
$funnelLeadsByStatus = [];
foreach ($salesStatusOptions as $statusValue => $statusLabel) {
  $funnelLeadsByStatus[(string) $statusValue] = [];
}
$funnelOverflow = false;
$funnelSql = <<<SQL
SELECT
  COALESCE(l.id, 0) AS id,
  c.account_id AS conversation_account_id,
  c.id AS conversation_id,
  c.public_id AS conversation_public_id,
  c.status AS conversation_status,
  c.unread_count,
  c.last_message_preview AS conversation_preview,
  c.last_message_at AS conversation_last_message_at,
  c.created_at AS conversation_created_at,
  c.updated_at AS conversation_updated_at,
  ct.display_name AS contact_display_name,
  ct.username AS contact_username,
  ct.profile_url AS contact_profile_url,
  ch.page_name,
  ch.instagram_username AS channel_username,
  a.slug AS account_slug,
  COALESCE(
    l.fullname,
    ct.display_name,
    NULLIF(CONCAT('@', TRIM(LEADING '@' FROM COALESCE(ct.username, ''))), '@'),
    CASE
      WHEN c.external_source = 'messenger' THEN 'Contacto de Messenger'
      ELSE 'Contacto de Instagram'
    END
  ) AS fullname,
  l.phone,
  l.email,
  COALESCE(l.brand_instagram, ct.username) AS brand_instagram,
  l.business_type,
  l.business_type_other,
  l.services_needed,
  l.main_objective,
  l.message,
  COALESCE(l.source_platform, c.external_source) AS source_platform,
  l.utm_campaign,
  l.utm_content,
  l.ad_name,
  l.ad_id,
  COALESCE(l.sales_status, :default_sales_status_select) AS sales_status,
  l.notes,
  l.reminder_at,
  l.reminder_note,
  COALESCE(l.external_source, c.external_source) AS external_source,
  COALESCE(l.external_contact_id, ct.external_contact_id) AS external_contact_id,
  c.external_thread_id,
  (
    SELECT MAX(im.sent_at)
    FROM {$messagesTable} im
    WHERE im.conversation_id = c.id AND im.direction = 'inbound'
  ) AS last_inbound_at,
  COALESCE(l.last_message_at, c.last_message_at) AS last_message_at,
  COALESCE(l.last_inbound_message, c.last_message_preview) AS last_inbound_message,
  COALESCE(l.created_at, c.created_at) AS created_at,
  COALESCE(l.updated_at, c.updated_at) AS updated_at
{$conversationFromSql}
%WHERE%
ORDER BY COALESCE(c.last_message_at, c.created_at) DESC
LIMIT :limit
OFFSET :offset
SQL;
$funnelSql = str_replace('%WHERE%', $funnelWhereSql, $funnelSql);
$funnelStmt = $pdo->prepare($funnelSql);
$funnelStmt->bindValue(':default_sales_status_select', $defaultSalesStatus);
foreach ($funnelWhereParams as $key => $value) $funnelStmt->bindValue($key, $value);
$funnelStmt->bindValue(':limit', $funnelLimit, PDO::PARAM_INT);
$funnelStmt->bindValue(':offset', $funnelOffset, PDO::PARAM_INT);
$funnelStmt->execute();
$funnelLeads = $funnelStmt->fetchAll();
$funnelOverflow = $filterSalesStatus === '' && $displayTotal > $funnelLimit && count($funnelLeads) >= $funnelLimit;

foreach ($funnelLeads as $lead) {
  $statusValue = (string) ($lead['sales_status'] ?? '');
  if (!array_key_exists($statusValue, $salesStatusOptions)) {
    $statusValue = (string) app_config('sales_funnel.default_status', 'nuevo_lead');
  }
  if (!isset($funnelLeadsByStatus[$statusValue])) $funnelLeadsByStatus[$statusValue] = [];
  $funnelLeadsByStatus[$statusValue][] = $lead;
}
$visibleStatusOptions = $filterSalesStatus !== '' ? [$filterSalesStatus => (string) $salesStatusOptions[$filterSalesStatus]] : sales_status_options();

function wa_number_from_formatted(string $phone): string {
  $countryCode = (string) app_config('phone.country_code', '58');
  $digits = preg_replace('/\D+/', '', $phone) ?: '';
  if (str_starts_with($digits, '0')) $digits = substr($digits, 1);
  if (!str_starts_with($digits, $countryCode)) $digits = $countryCode . $digits;
  return $digits;
}
function is_instagram_lead(array $lead): bool {
  $external = mb_strtolower(trim((string) ($lead['external_source'] ?? '')));
  $source = mb_strtolower(trim((string) ($lead['source_platform'] ?? '')));
  return $external === 'instagram' || str_contains($source, 'instagram');
}
function is_messenger_lead(array $lead): bool {
  $external = mb_strtolower(trim((string) ($lead['external_source'] ?? '')));
  $source = mb_strtolower(trim((string) ($lead['source_platform'] ?? '')));
  return $external === 'messenger' || str_contains($source, 'messenger');
}
function instagram_inbox_url(): string {
  return (string) app_config('instagram.dm_inbox_url', 'https://www.instagram.com/direct/inbox/');
}
function instagram_dm_url(array $lead): string {
  $handle = instagram_handle($lead['brand_instagram'] ?? '');
  if ($handle !== '') return 'https://ig.me/m/' . rawurlencode($handle);
  return instagram_inbox_url();
}
function dash_value($value): string { $value = trim((string) $value); return $value !== '' ? $value : '—'; }
function short_value($value, int $max = 46): string {
  $value = dash_value($value);
  if ($value === '—') return $value;
  return mb_strlen($value) > $max ? mb_substr($value, 0, max(1, $max - 1)) . '…' : $value;
}
function dash_pick(array $lead, array $keys): string {
  foreach ($keys as $key) if (isset($lead[$key]) && trim((string) $lead[$key]) !== '') return (string) $lead[$key];
  return '—';
}
function sales_status_options(): array { return (array) app_config('sales_funnel.statuses', []); }
function instagram_handle($value): string {
  $value = trim((string) $value);
  if ($value === '' || preg_match('/^(no\s*tiene|no tiene|ninguno|n\/a|na)$/iu', $value)) return '';
  $value = preg_replace('#^https?://(www\.)?instagram\.com/#i', '', $value) ?? $value;
  $value = preg_replace('/[\?#].*$/', '', $value) ?? $value;
  $value = trim($value, "@/ \t\n\r\0\x0B");
  if (!preg_match('/^[a-zA-Z0-9._]{1,30}$/', $value)) return '';
  return $value;
}
function instagram_url($value): ?string {
  $handle = instagram_handle($value);
  return $handle !== '' ? 'https://instagram.com/' . $handle : null;
}
function business_type_display(array $lead): string {
  $type = dash_value($lead['business_type'] ?? null);
  $other = dash_value($lead['business_type_other'] ?? null);
  if ($type !== '—' && mb_strtolower($type) === 'otro' && $other !== '—') return 'Otro: ' . $other;
  if ($type !== '—' && $other !== '—') return $type . ': ' . $other;
  return $type;
}
function updated_display(array $lead): string {
  return dash_value($lead['updated_at'] ?? null) !== '—' ? app_datetime($lead['updated_at']) : 'Sin cambios';
}
function dashboard_unsupported_message_text(): string {
  return 'Se ha recibido un mensaje no soportado en esta plataforma, accede a este mensaje directamente desde la app oficial.';
}
function dashboard_normalize_message_text($value): string {
  $text = trim((string) $value);
  $legacyUnsupported = [
    'Mensaje recibido desde Instagram DM.',
    'Mensaje recibido desde Facebook Messenger.',
    'Adjunto recibido: unsupported_type',
  ];
  return in_array($text, $legacyUnsupported, true) ? dashboard_unsupported_message_text() : $text;
}
function lead_message_display(array $lead): string {
  $last = dash_value(dashboard_normalize_message_text($lead['last_inbound_message'] ?? null));
  if ($last !== '—') return $last;
  return dash_value(dashboard_normalize_message_text($lead['message'] ?? null));
}
function lead_contact_display(array $lead): string {
  $phone = dash_value($lead['phone'] ?? null);
  if ($phone !== '—') return $phone;
  if (is_instagram_lead($lead)) return 'Instagram DM';
  if (is_messenger_lead($lead)) return 'Facebook Messenger';
  return '—';
}
function datetime_local_value($value): string {
  return app_datetime($value, 'Y-m-d\TH:i', '');
}
function reminder_display(array $lead): string {
  $at = dash_value($lead['reminder_at'] ?? null);
  $note = dash_value($lead['reminder_note'] ?? null);
  if ($at === '—' && $note === '—') return 'Sin recordatorio';
  if ($at !== '—' && $note !== '—') return $at . ' · ' . $note;
  return $at !== '—' ? $at : $note;
}
function dash_channel_label(array $channel): string {
  $username = trim((string) ($channel['instagram_username'] ?? ''));
  if ($username !== '') return '@' . ltrim($username, '@');
  $pageName = trim((string) ($channel['page_name'] ?? ''));
  if ($pageName !== '') return $pageName;
  return trim((string) ($channel['page_id'] ?? 'Canal de Instagram'));
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover" />
  <title><?= h(app_config('ui.dashboard_title', 'Dashboard')) ?></title>
  <link rel="stylesheet" href="css/app.css?v=<?= (int) @filemtime(__DIR__ . '/css/app.css') ?>">
  <meta name="csrf" content="<?= h($_SESSION['csrf'] ?? '') ?>">
  <style>
    :root {
      --container-w: 100vw;
      --dash-bg:#f3f6fb;
      --dash-ink:#101524;
      --dash-muted:#667085;
      --dash-panel:#ffffff;
      --dash-panel-soft:#f8fafc;
      --dash-line:#dde6f0;
      --dash-navy:#070b18;
      --dash-cyan:#16c7e8;
      --dash-violet:#7c3cff;
      --dash-green:#20b486;
      --dash-amber:#f5a524;
      --dash-red:#e05766;
      --dash-shadow:0 18px 44px rgba(15, 23, 42, .10);
    }
    .dashboard-page {
      display:block;
      min-height:100vh;
      color:var(--dash-ink);
      background:#c7c1dc;
    }
    .dashboard-shell {
      --dashboard-pad:0px;
      width:100%;
      max-width:none;
      margin:0;
      padding:var(--dashboard-pad);
    }
    .dashboard-card {
      width:100%;
      min-height:100vh;
      border:0;
      border-radius:0;
      background:#fff;
      box-shadow:none;
      backdrop-filter:none;
    }
    .dashboard-card .panel {
      min-width:0;
      padding:clamp(18px, 2vw, 34px);
      background:#f6f7fb;
    }
    .topbar {
      display:flex;
      align-items:flex-start;
      justify-content:space-between;
      gap:18px;
      flex-wrap:wrap;
      margin-bottom:22px;
      padding:0;
      border:0;
      border-radius:0;
      background:transparent;
    }
    .topbar-right,
    .app-nav-actions {
      display:flex;
      align-items:center;
      justify-content:flex-end;
      gap:10px;
      flex-wrap:wrap;
    }
    .eyebrow {
      margin:0 0 8px;
      color:var(--dash-cyan);
      font-size:.72rem;
      font-weight:950;
      letter-spacing:.14em;
      text-transform:uppercase;
    }
    .dashboard-card .title {
      margin:0;
      color:var(--dash-ink);
      font-size:clamp(1.8rem, 2.7vw, 3rem);
      line-height:.98;
      letter-spacing:-.055em;
      font-weight:950;
    }
    .dashboard-card .subtitle {
      max-width:none;
      margin:0 0 18px;
      color:var(--dash-muted);
      font-size:.98rem;
      font-weight:650;
    }
    .dashboard-card .subtitle strong {
      color:var(--dash-violet);
    }
    .account-switch {
      display:inline-flex;
      align-items:center;
      height:44px;
    }
    .menu-trigger,
    .wa-btn,
    .user-btn,
    .logout-btn,
    .search-btn,
    .clear-filters {
      appearance:none;
      display:inline-flex;
      align-items:center;
      justify-content:center;
      min-height:44px;
      padding:0 15px;
      border:1px solid var(--dash-line);
      border-radius:999px;
      color:var(--dash-ink);
      background:#fff;
      box-shadow:0 1px 0 rgba(16,21,36,.04);
      font:inherit;
      font-size:.88rem;
      font-weight:850;
      text-decoration:none;
      cursor:pointer;
      transition:transform .12s ease, border-color .18s ease, background .18s ease, box-shadow .18s ease;
    }
    .account-switch .menu-trigger {
      min-width:210px;
      justify-content:space-between;
    }
    .account-switch .menu-panel {
      max-height:min(62vh, 420px);
      overflow:auto;
    }
    .menu-trigger:hover,
    .menu-dropdown.is-open .menu-trigger,
    .wa-btn:hover,
    .user-btn:hover,
    .logout-btn:hover,
    .search-btn:hover,
    .clear-filters:hover {
      border-color:var(--dash-navy);
      color:#fff;
      background:var(--dash-navy);
      box-shadow:0 10px 24px rgba(15,23,42,.08);
      outline:none;
    }
    .menu-trigger:active,
    .search-btn:active,
    .clear-filters:active {
      transform:translateY(1px);
    }
    .menu-trigger::after {
      content:"⌄";
      color:var(--dash-cyan);
      font-size:.95rem;
      line-height:1;
      transform:translateY(-1px);
    }
    .nav-direct-button {
      color:#fff;
      border-color:var(--dash-navy);
      background:var(--dash-navy);
    }
    .nav-direct-button::after,
    .menu-trigger.nav-direct-button::after {
      display:none;
      content:none;
    }
    .nav-control-label {
      position:absolute;
      width:1px;
      height:1px;
      overflow:hidden;
      clip:rect(0,0,0,0);
      white-space:nowrap;
    }
    .menu-dropdown {
      position:relative;
    }
    .menu-panel {
      position:absolute;
      top:calc(100% + 10px);
      right:0;
      z-index:40;
      display:none;
      min-width:240px;
      padding:8px;
      border:1px solid var(--dash-line);
      border-radius:18px;
      background:#fff;
      box-shadow:0 24px 60px rgba(15,23,42,.16);
    }
    .menu-dropdown.is-open .menu-panel {
      display:block;
    }
    .menu-item {
      width:100%;
      min-height:42px;
      display:flex;
      align-items:center;
      justify-content:flex-start;
      gap:8px;
      padding:0 12px;
      border:0;
      border-radius:12px;
      color:var(--dash-ink);
      background:transparent;
      font:inherit;
      font-size:.9rem;
      font-weight:850;
      text-align:left;
      text-decoration:none;
      cursor:pointer;
    }
    .menu-item:hover {
      color:#06101f;
      background:#f2f6fb;
    }
    .menu-item.is-active {
      color:#fff;
      background:var(--dash-navy);
    }
    .menu-meta {
      display:block;
      margin-bottom:6px;
      padding:8px 10px 10px;
      border-bottom:1px solid var(--dash-line);
      color:var(--dash-muted);
      font-size:.78rem;
      font-weight:850;
    }
    .menu-form {
      margin:0;
    }
    .menu-panel-wide {
      min-width:270px;
    }
    .menu-section-title {
      display:block;
      margin:10px 10px 4px;
      color:#98a2b3;
      font-size:.7rem;
      font-weight:950;
      letter-spacing:.1em;
      text-transform:uppercase;
    }
    .menu-item-nested {
      padding-left:24px!important;
    }
    .menu-item-danger {
      color:#b4232f!important;
    }
    .summary-grid {
      display:grid;
      grid-template-columns:repeat(auto-fit, minmax(155px, 1fr));
      gap:12px;
      margin-top:18px;
    }
    .summary-card {
      position:relative;
      min-height:104px;
      padding:16px 16px 14px;
      overflow:hidden;
      border:1px solid var(--dash-line);
      border-radius:18px;
      background:var(--dash-panel);
      box-shadow:0 10px 26px rgba(15,23,42,.06);
      text-decoration:none;
      transition:transform .16s ease, border-color .18s ease, box-shadow .18s ease;
    }
    .summary-card::before {
      content:"";
      position:absolute;
      inset:0 auto 0 0;
      width:5px;
      background:var(--tone, var(--dash-cyan));
    }
    .summary-card::after {
      content:"";
      position:absolute;
      right:16px;
      top:16px;
      width:10px;
      height:10px;
      border-radius:50%;
      background:var(--tone, var(--dash-cyan));
      box-shadow:0 0 0 5px color-mix(in srgb, var(--tone, var(--dash-cyan)) 14%, transparent);
    }
    .summary-card:hover {
      transform:translateY(-2px);
      border-color:var(--dash-navy);
      background:var(--dash-navy);
      box-shadow:0 18px 34px rgba(15,23,42,.16);
    }
    .summary-card:hover strong,
    .summary-card:hover span { color:#fff; }
    .summary-card.is-active {
      border-color:var(--dash-navy);
      box-shadow:0 0 0 3px rgba(7,11,24,.08), 0 18px 34px rgba(15,23,42,.10);
    }
    .summary-card strong {
      display:block;
      color:var(--dash-ink);
      font-size:2rem;
      line-height:1;
      font-weight:950;
      letter-spacing:-.04em;
    }
    .summary-card span {
      display:block;
      max-width:130px;
      margin-top:12px;
      color:var(--dash-muted);
      font-size:.72rem;
      font-weight:950;
      letter-spacing:.08em;
      text-transform:uppercase;
      line-height:1.25;
    }
    .summary-card[data-tone="total"] { --tone:var(--dash-navy); background:#f9fafb; }
    .summary-card[data-tone="new"] { --tone:var(--dash-cyan); }
    .summary-card[data-tone="contacted"] { --tone:#5271ff; }
    .summary-card[data-tone="scheduled"] { --tone:var(--dash-violet); }
    .summary-card[data-tone="interested"] { --tone:var(--dash-green); }
    .summary-card[data-tone="proposal"] { --tone:var(--dash-amber); }
    .summary-card[data-tone="followup"] { --tone:#f97316; }
    .summary-card[data-tone="won"] { --tone:#159a61; }
    .summary-card[data-tone="lost"] { --tone:var(--dash-red); }
    .summary-card[data-tone="muted"] { --tone:#667085; }
    .lead-filters {
      width:100%;
      max-width:100%;
      margin-top:18px;
      padding:0;
      border:0;
      border-radius:0;
      background:transparent;
      box-shadow:none;
    }
    .filters-toggle {
      display:none;
      align-items:center;
      justify-content:center;
      min-height:44px;
      margin-top:14px;
      padding:0 15px;
      border:1px solid var(--dash-line);
      border-radius:999px;
      color:var(--dash-ink);
      background:#fff;
      font-size:.9rem;
      font-weight:850;
      cursor:pointer;
    }
    .filters-form {
      display:grid;
      grid-template-columns:minmax(260px, 390px) minmax(190px, 240px) auto;
      gap:12px;
      align-items:end;
      justify-content:start;
    }
    .filter-field {
      position:relative;
      display:grid;
      gap:7px;
      min-width:0;
    }
    .filter-field::after {
      content:"⌄";
      position:absolute;
      right:14px;
      bottom:13px;
      color:#667085;
      font-size:.95rem;
      font-weight:950;
      pointer-events:none;
    }
    .filter-field:has(input)::after {
      content:"→";
    }
    .filter-field span {
      color:var(--dash-muted);
      font-size:.68rem;
      font-weight:950;
      letter-spacing:.1em;
      text-transform:uppercase;
    }
    .filter-field input,
    .filter-field select {
      width:100%;
      height:44px;
      padding:0 36px 0 13px;
      border:1px solid var(--dash-line);
      border-radius:14px;
      color:var(--dash-ink);
      background:#fff;
      outline:none;
      font:inherit;
      font-size:.92rem;
      font-weight:760;
    }
    .filter-field select {
      appearance:none;
    }
    .filter-field input::placeholder {
      color:#98a2b3;
      font-weight:700;
    }
    .filter-field input:focus,
    .filter-field select:focus,
    .notes-input:focus,
    .sales-status-select:focus {
      border-color:var(--dash-violet);
      box-shadow:0 0 0 4px rgba(124,60,255,.12);
    }
    .filter-actions {
      display:flex;
      gap:8px;
      align-items:center;
      flex-wrap:wrap;
    }
    .search-btn {
      color:#fff;
      border-color:var(--dash-violet);
      background:var(--dash-violet);
    }
    .search-btn:hover {
      color:#fff;
      border-color:var(--dash-navy);
      background:var(--dash-navy);
    }
    .active-filter-note,
    .funnel-limit-note,
    .funnel-page-status {
      margin:10px 0 0;
      color:var(--dash-muted);
      font-size:.86rem;
      font-weight:700;
    }
    .sales-status-select {
      --status-color:#b7c7d9;
      width:185px;
      height:42px;
      padding:0 36px 0 12px;
      border:1px solid var(--dash-line);
      border-left:5px solid var(--status-color);
      border-radius:14px;
      color:var(--dash-ink);
      background:#fff;
      background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 20 20' fill='none'%3E%3Cpath d='M5 7.5L10 12.5L15 7.5' stroke='%23667085' stroke-width='2.2' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");
      background-repeat:no-repeat;
      background-position:right 13px center;
      background-size:14px;
      appearance:none;
      outline:none;
      font:inherit;
      font-size:.86rem;
      font-weight:850;
      cursor:pointer;
    }
    .sales-status-select.is-saving,
    .notes-input.is-saving {
      opacity:.65;
      cursor:progress;
    }
    .sales-status-select[data-status="nuevo_lead"] { --status-color:var(--dash-cyan); }
    .sales-status-select[data-status="en_conversacion"] { --status-color:var(--dash-violet); }
    .sales-status-select[data-status="propuesta_enviada"] { --status-color:var(--dash-amber); }
    .sales-status-select[data-status="no_responde"] { --status-color:#667085; }
    .sales-status-select[data-status="cliente_ganado"] { --status-color:#159a61; }
    .sales-status-select[data-status="cliente_perdido"] { --status-color:var(--dash-red); }
    .sales-status-select[data-status="no_califica"] { --status-color:#475467; }
    .sales-status-badge {
      --status-color:#b7c7d9;
      display:inline-flex;
      align-items:center;
      min-height:34px;
      width:max-content;
      padding:0 11px;
      border:1px solid var(--dash-line);
      border-left:5px solid var(--status-color);
      border-radius:999px;
      background:#fff;
      color:var(--dash-ink);
      font-size:.82rem;
      font-weight:850;
      white-space:nowrap;
    }
    .sales-status-badge[data-status="nuevo_lead"] { --status-color:var(--dash-cyan); }
    .sales-status-badge[data-status="en_conversacion"] { --status-color:var(--dash-violet); }
    .sales-status-badge[data-status="propuesta_enviada"] { --status-color:var(--dash-amber); }
    .sales-status-badge[data-status="no_responde"] { --status-color:#667085; }
    .sales-status-badge[data-status="cliente_ganado"] { --status-color:#159a61; }
    .sales-status-badge[data-status="cliente_perdido"] { --status-color:var(--dash-red); }
    .sales-status-badge[data-status="no_califica"] { --status-color:#475467; }
    .readonly-text {
      min-width:220px;
      max-width:300px;
      color:var(--dash-ink);
      line-height:1.35;
      white-space:pre-wrap;
      overflow-wrap:anywhere;
    }
    .notes-input {
      width:240px;
      min-height:42px;
      resize:vertical;
      padding:10px 12px;
      border:1px solid var(--dash-line);
      border-radius:14px;
      color:var(--dash-ink);
      background:#fff;
      outline:none;
      font:inherit;
      font-size:.86rem;
      line-height:1.4;
    }
    .funnel-wrap {
      margin-top:18px;
      overflow-x:auto;
      padding:2px 2px 12px;
      scrollbar-color:#b7c7d9 transparent;
    }
    .funnel-board {
      display:flex;
      gap:14px;
      align-items:flex-start;
      min-width:max-content;
    }
    .funnel-column {
      --status-color:var(--dash-cyan);
      --status-bg:#f8fafc;
      width:clamp(245px, 18vw, 305px);
      max-height:72vh;
      display:flex;
      flex-direction:column;
      overflow:hidden;
      border:1px solid var(--dash-line);
      border-radius:8px;
      background:transparent;
      box-shadow:none;
    }
    .funnel-wrap.is-filtered {
      overflow:visible;
    }
    .funnel-board.is-filtered {
      display:block;
      min-width:0;
    }
    .funnel-column.is-filtered {
      width:100%;
      max-height:none;
      overflow:visible;
    }
    .funnel-column.is-filtered .funnel-column-header {
      position:static;
    }
    .funnel-column.is-filtered .funnel-list {
      grid-template-columns:repeat(auto-fit, minmax(280px, 1fr));
      align-items:start;
      overflow:visible;
      padding:16px;
    }
    .funnel-column.is-filtered .funnel-empty {
      grid-column:1 / -1;
    }
    .funnel-column[data-status="nuevo_lead"] { --status-color:var(--dash-cyan); --status-bg:#f3fbff; }
    .funnel-column[data-status="en_conversacion"] { --status-color:var(--dash-violet); --status-bg:#f8f5ff; }
    .funnel-column[data-status="propuesta_enviada"] { --status-color:var(--dash-amber); --status-bg:#fffaf0; }
    .funnel-column[data-status="no_responde"] { --status-color:#667085; --status-bg:#f8fafc; }
    .funnel-column[data-status="cliente_ganado"] { --status-color:#159a61; --status-bg:#f1fbf5; }
    .funnel-column[data-status="cliente_perdido"] { --status-color:var(--dash-red); --status-bg:#fff5f6; }
    .funnel-column[data-status="no_califica"] { --status-color:#475467; --status-bg:#f2f4f7; }
    .funnel-column-header {
      position:sticky;
      top:0;
      z-index:1;
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:10px;
      padding:14px 15px;
      border-bottom:0;
      border-radius:8px 8px 0 0;
      color:var(--dash-ink);
      background:#fff;
      backdrop-filter:none;
    }
    .funnel-column-header::before {
      content:"";
      width:10px;
      height:10px;
      border-radius:50%;
      background:var(--status-color);
      box-shadow:0 0 0 5px color-mix(in srgb, var(--status-color) 14%, transparent);
    }
    .funnel-column-header h2 {
      flex:1;
      margin:0;
      font-size:.9rem;
      line-height:1.2;
      letter-spacing:-.01em;
    }
    .funnel-count {
      display:inline-flex;
      align-items:center;
      justify-content:center;
      min-width:30px;
      height:30px;
      padding:0 9px;
      border-radius:999px;
      color:#fff;
      background:var(--dash-navy);
      font-weight:950;
      font-size:.82rem;
    }
    .funnel-list {
      display:grid;
      gap:12px;
      padding:12px;
      overflow:auto;
    }
    .funnel-card {
      display:grid;
      gap:10px;
      padding:14px;
      border:1px solid rgba(16,21,36,.10);
      border-radius:6px;
      background:#fff;
      box-shadow:0 6px 18px rgba(15,23,42,.05);
      transition:transform .16s ease, box-shadow .18s ease, border-color .18s ease;
    }
    .funnel-card:hover {
      transform:translateY(-2px);
      border-color:#b8c4d6;
      box-shadow:0 14px 28px rgba(15,23,42,.09);
    }
    .funnel-card-title {
      display:flex;
      align-items:flex-start;
      justify-content:space-between;
      gap:10px;
    }
    .funnel-card-title strong {
      color:var(--dash-ink);
      line-height:1.18;
      font-size:1rem;
      letter-spacing:-.02em;
    }
    .funnel-id {
      display:none;
    }
    .funnel-meta {
      display:grid;
      gap:5px;
      color:var(--dash-muted);
      font-size:.8rem;
      line-height:1.35;
    }
    .funnel-meta-line {
      display:flex;
      gap:5px;
      align-items:center;
      flex-wrap:wrap;
    }
    .funnel-meta a {
      color:#087ea4;
      font-weight:850;
      text-decoration:none;
    }
    .funnel-meta a:hover {
      text-decoration:underline;
    }
    .reply-window-badge {
      display:inline-flex;
      align-items:center;
      min-height:28px;
      width:max-content;
      padding:0 9px;
      border-radius:999px;
      border:1px solid rgba(32,180,134,.28);
      background:#edfdf7;
      color:#0f7d5b;
      font-size:.72rem;
      font-weight:950;
    }
    .reply-window-badge.warning {
      border-color:rgba(245,165,36,.35);
      background:#fff7df;
      color:#946200;
    }
    .reply-window-badge.expired,
    .reply-window-badge.unknown {
      border-color:rgba(224,87,102,.35);
      background:#fff0f2;
      color:#a32d3b;
    }
    .funnel-note {
      padding:10px 11px;
      border-radius:12px;
      color:var(--dash-ink);
      background:#f8fafc;
      font-size:.86rem;
      line-height:1.4;
      white-space:pre-wrap;
      overflow-wrap:anywhere;
    }
    .funnel-card .sales-status-select,
    .funnel-card .notes-input {
      width:100%;
    }
    .funnel-card .notes-input {
      min-height:78px;
    }
    .funnel-actions {
      display:grid;
      grid-template-columns:1fr;
      gap:8px;
      align-items:center;
      margin-top:2px;
    }
    .funnel-action-link {
      appearance:none;
      display:inline-flex;
      width:100%;
      align-items:center;
      justify-content:center;
      min-height:40px;
      padding:0 10px;
      border:1px solid var(--dash-line);
      border-radius:999px;
      color:var(--dash-ink);
      background:#fff;
      font:inherit;
      font-size:.8rem;
      font-weight:900;
      text-decoration:none;
      cursor:pointer;
    }
    .funnel-action-link:first-child {
      color:#fff;
      border-color:var(--dash-navy);
      background:var(--dash-navy);
    }
    .funnel-action-link:hover {
      color:#fff;
      border-color:#356dff;
      background:#356dff;
      box-shadow:0 10px 22px rgba(53,109,255,.18);
    }
    .funnel-empty {
      margin:0;
      padding:18px;
      color:var(--dash-muted);
      font-weight:780;
      line-height:1.35;
    }
    .funnel-pagination {
      display:flex;
      align-items:center;
      justify-content:center;
      gap:8px;
      flex-wrap:wrap;
      margin:18px 0 0;
    }
    .funnel-page-link,
    .funnel-page-current {
      display:inline-flex;
      align-items:center;
      justify-content:center;
      min-height:40px;
      min-width:40px;
      padding:0 13px;
      border-radius:999px;
      border:1px solid var(--dash-line);
      font-size:.86rem;
      font-weight:850;
      text-decoration:none;
    }
    .funnel-page-link {
      color:var(--dash-ink);
      background:#fff;
    }
    .funnel-page-link:hover {
      border-color:#b7c7d9;
      background:#f8fafc;
    }
    .funnel-page-current {
      color:#fff;
      border-color:var(--dash-navy);
      background:var(--dash-navy);
    }
    .modal-backdrop {
      position:fixed;
      inset:0;
      display:none;
      align-items:center;
      justify-content:center;
      z-index:9999;
      padding:18px;
      background:rgba(7,11,24,.68);
    }
    .modal-backdrop.is-open {
      display:flex;
    }
    .modal {
      width:min(92vw, 540px);
      padding:24px;
      border:1px solid rgba(255,255,255,.18);
      border-radius:24px;
      color:#fff;
      background:#070b18;
      box-shadow:0 28px 80px rgba(0,0,0,.36);
    }
    .modal h2 {
      margin:0 0 10px;
      color:#fff;
      font-size:1.45rem;
      letter-spacing:-.03em;
    }
    .modal .subtitle {
      color:rgba(255,255,255,.72);
    }
    .modal .actions {
      display:flex;
      gap:10px;
      justify-content:flex-end;
      margin-top:14px;
    }
    .modal .field-label {
      color:#fff;
    }
    .modal input,
    .modal textarea {
      width:100%;
      padding:10px 12px;
      border:1px solid rgba(255,255,255,.18);
      border-radius:14px;
      color:#fff;
      background:rgba(255,255,255,.08);
      font:inherit;
      outline:none;
    }
    .modal textarea {
      min-height:110px;
      resize:vertical;
      line-height:1.4;
    }
    .modal input:focus,
    .modal textarea:focus {
      border-color:var(--dash-cyan);
      box-shadow:0 0 0 4px rgba(22,199,232,.14);
    }
    .history-list {
      display:grid;
      gap:10px;
      margin-top:14px;
      max-height:360px;
      overflow:auto;
    }
    .history-item {
      padding:12px;
      border:1px solid rgba(255,255,255,.14);
      border-radius:16px;
      background:rgba(255,255,255,.06);
    }
    .history-item strong {
      display:block;
      margin-bottom:5px;
      color:#fff;
    }
    .history-item p {
      margin:0;
      color:rgba(255,255,255,.82);
      line-height:1.4;
    }
    .history-meta {
      margin-top:7px;
      color:rgba(255,255,255,.58);
      font-size:.82rem;
      font-weight:750;
    }
    .btn-secondary {
      appearance:none;
      padding:10px 14px;
      border:1px solid rgba(255,255,255,.25);
      border-radius:999px;
      color:#fff;
      background:transparent;
      cursor:pointer;
    }
    .btn-secondary:hover {
      background:rgba(255,255,255,.08);
    }
    @media (max-width: 1200px) {
      .filters-form {
        grid-template-columns:minmax(220px, 360px) minmax(200px, 260px) auto;
      }
    }
    @media (max-width: 760px) {
      .dashboard-shell {
        --dashboard-pad:0;
      }
      .dashboard-card {
        min-height:100vh;
        border-left:0;
        border-right:0;
        border-radius:0;
      }
      .dashboard-card .panel {
        padding:16px 12px 18px;
      }
      .topbar {
        padding:12px;
        border-radius:18px;
      }
      .topbar-right,
      .app-nav-actions {
        width:100%;
        justify-content:stretch;
      }
      .app-nav-actions .account-switch,
      .app-nav-actions .menu-dropdown {
        flex:1 1 100%;
      }
      .app-nav-actions .account-switch .menu-trigger,
      .app-nav-actions .menu-trigger {
        width:100%;
      }
      .summary-grid {
        grid-template-columns:repeat(2, minmax(0, 1fr));
        gap:8px;
      }
      .summary-card {
        min-height:90px;
        padding:13px;
      }
      .summary-card strong {
        font-size:1.55rem;
      }
      .summary-card span {
        font-size:.66rem;
      }
      .filters-toggle {
        display:inline-flex;
      }
      .lead-filters {
        display:none;
        width:100%;
      }
      .lead-filters.is-open {
        display:block;
      }
      .filters-form {
        grid-template-columns:1fr;
      }
      .filter-actions,
      .search-btn,
      .clear-filters {
        width:100%;
      }
      .funnel-wrap {
        margin-left:-12px;
        margin-right:-12px;
        padding-left:12px;
        padding-right:12px;
      }
      .funnel-column {
        width:min(86vw, 330px);
        max-height:68vh;
      }
      .funnel-board.is-filtered {
        min-width:0;
      }
      .funnel-column.is-filtered {
        width:100%;
        max-height:none;
      }
      .funnel-column.is-filtered .funnel-list {
        grid-template-columns:1fr;
      }
      .funnel-actions {
        grid-template-columns:1fr;
      }
      .menu-panel {
        left:0;
        right:auto;
        width:min(92vw, 300px);
      }
    }
  </style>
</head>
<body class="dashboard-page">
  <main class="dashboard-shell">
    <section class="form-card dashboard-card">
      <div class="panel" data-dashboard-auto-update>
        <div class="topbar">
          <div>
            <p class="eyebrow"><?= h(app_config('brand.name', 'Marca')) ?></p>
            <h1 class="title"><?= h(app_config('ui.dashboard_heading', 'Dashboard')) ?></h1>
          </div>
          <div class="topbar-right app-nav-actions">
            <?php nav_render_view_button('dashboard'); ?>
            <?php nav_render_account_switch($pdo, $accountOptions, $filterAccountId, 'dashboard.php', ['q' => $q, 'channel_id' => $filterChannelId > 0 ? $filterChannelId : null, 'sales_status' => $filterSalesStatus], ['q' => $q, 'sales_status' => $filterSalesStatus]); ?>
            <?php nav_render_user_menu(true); ?>
          </div>
        </div>

        <p class="subtitle">
          <?= h(app_config('ui.dashboard_subtitle', 'Listado de registros')) ?><?= $q !== '' ? " – Búsqueda: <strong>" . h($q) . "</strong>" : '' ?>
        </p>

        <div class="summary-grid" aria-label="Resumen comercial" data-summary-grid>
          <?php foreach ($summaryCards as $card): ?>
            <a class="summary-card <?= !empty($card['active']) ? 'is-active' : '' ?>" href="<?= h((string) $card['href']) ?>" data-tone="<?= h($card['tone']) ?>" aria-current="<?= !empty($card['active']) ? 'true' : 'false' ?>">
              <strong><?= (int) $card['value'] ?></strong>
              <span><?= h($card['label']) ?></span>
            </a>
          <?php endforeach; ?>
        </div>

        <button class="filters-toggle" type="button" data-filters-toggle aria-controls="leadFilters" aria-expanded="false">Filtros<?= $activeFilters ? ' (' . count($activeFilters) . ')' : '' ?></button>

        <div class="lead-filters" id="leadFilters" aria-label="Filtros de conversaciones">
          <form class="filters-form" method="get" action="dashboard.php">
            <?php if ($filterSalesStatus !== ''): ?><input type="hidden" name="sales_status" value="<?= h($filterSalesStatus) ?>"><?php endif; ?>
            <label class="filter-field">
              <span>Buscar</span>
              <input type="text" name="q" value="<?= h($q) ?>" placeholder="Cliente, Instagram o mensaje">
            </label>

            <label class="filter-field">
              <span>Canal</span>
              <select name="channel_id">
                <option value="">Todos</option>
                <?php foreach ($channelOptions as $channel): ?>
                  <option value="<?= (int) $channel['id'] ?>" <?= $filterChannelId === (int) $channel['id'] ? 'selected' : '' ?>><?= h(dash_channel_label($channel)) ?></option>
                <?php endforeach; ?>
              </select>
            </label>

            <div class="filter-actions">
              <button class="search-btn" type="submit">Filtrar</button>
              <?php if ($activeFilters): ?><a class="clear-filters" href="dashboard.php">Limpiar</a><?php endif; ?>
            </div>
          </form>
          <?php if ($activeFilters): ?>
            <p class="active-filter-note">Mostrando <?= (int) $displayTotal ?> conversacion<?= $displayTotal === 1 ? '' : 'es' ?><?= $filterSalesStatus !== '' ? ' en ' . h((string) $salesStatusOptions[$filterSalesStatus]) : '' ?> con los filtros activos.</p>
          <?php endif; ?>
        </div>

        <div class="funnel-wrap<?= $filterSalesStatus !== '' ? ' is-filtered' : '' ?>" aria-label="Embudo comercial" data-funnel-wrap>
          <div class="funnel-board<?= $filterSalesStatus !== '' ? ' is-filtered' : '' ?>">
            <?php foreach ($visibleStatusOptions as $statusValue => $statusLabel): ?>
              <?php $cards = $funnelLeadsByStatus[(string) $statusValue] ?? []; ?>
              <section class="funnel-column<?= $filterSalesStatus !== '' ? ' is-filtered' : '' ?>" data-status="<?= h((string) $statusValue) ?>" aria-labelledby="funnel-<?= h((string) $statusValue) ?>">
                <header class="funnel-column-header">
                  <h2 id="funnel-<?= h((string) $statusValue) ?>"><?= h($statusLabel) ?></h2>
                  <span class="funnel-count"><?= (int) ($statusCounts[(string) $statusValue] ?? 0) ?></span>
                </header>
                <div class="funnel-list">
                  <?php if ($cards): foreach ($cards as $lead): ?>
                    <?php
                      $conversationId = (int) ($lead['conversation_id'] ?? 0);
                      $conversationPublicId = (int) ($lead['conversation_public_id'] ?? $conversationId);
                      $conversationSlug = trim((string) ($lead['account_slug'] ?? $requestSlug));
                      $leadId = (int) ($lead['id'] ?? 0);
                      $phoneValue = dash_value($lead['phone'] ?? null);
                      $wa = $phoneValue !== '—' ? wa_number_from_formatted($phoneValue) : '';
                      $isInstagramLead = is_instagram_lead($lead);
                      $isMessengerLead = is_messenger_lead($lead);
                      $salesStatus = (string) ($lead['sales_status'] ?? app_config('sales_funnel.default_status', 'nuevo_lead'));
                      $salesStatusLabel = (string) ($salesStatusOptions[$salesStatus] ?? $salesStatus);
                      $adValue = dash_pick($lead, ['ad_name','utm_content','ad_id']);
                      $igUrl = instagram_url($lead['brand_instagram'] ?? '');
                      $igHandle = instagram_handle($lead['brand_instagram'] ?? '');
                      $replyWindow = meta_reply_window_info($lead['last_inbound_at'] ?? '');
                    ?>
                    <article class="funnel-card" data-id="<?= $leadId ?>" data-conversation-id="<?= $conversationPublicId ?>">
                      <div class="funnel-card-title">
                        <strong><?= h(short_value($lead['fullname'] ?? null, 34)) ?></strong>
                      </div>
                      <div class="funnel-meta">
                        <span class="funnel-meta-line">
                          <?php if ($wa !== ''): ?>
                            <a href="https://wa.me/<?= h($wa) ?>" target="_blank" rel="noopener"><?= h($phoneValue) ?></a>
                          <?php elseif ($isInstagramLead): ?>
                            <a href="<?= h(instagram_dm_url($lead)) ?>" target="_blank" rel="noopener">Instagram DM</a>
                            <?php if ($igUrl && $igHandle !== ''): ?>
                              <span>-</span>
                              <a href="<?= h($igUrl) ?>" target="_blank" rel="noopener">@<?= h($igHandle) ?></a>
                            <?php endif; ?>
                          <?php elseif ($isMessengerLead): ?>
                            <span>Facebook Messenger</span>
                          <?php else: ?>
                            <?= h(lead_contact_display($lead)) ?>
                          <?php endif; ?>
                        </span>
                        <span>Actualizado: <?= h(updated_display($lead)) ?></span>
                        <span class="reply-window-badge <?= h((string) ($replyWindow['status'] ?? 'unknown')) ?>" title="<?= h((string) ($replyWindow['detail'] ?? '')) ?>"><?= h((string) ($replyWindow['label'] ?? 'Chat')) ?></span>
                        <?php if ($adValue !== '—'): ?><span><?= h(short_value($adValue, 46)) ?></span><?php endif; ?>
                      </div>
                      <?php if (lead_message_display($lead) !== '—'): ?>
                        <div class="funnel-note"><?= h(short_value(lead_message_display($lead), 130)) ?></div>
                      <?php endif; ?>
                      <?php if ($canEditLeads && $leadId > 0): ?>
                        <textarea class="notes-input" data-id="<?= $leadId ?>" maxlength="2000" rows="3" placeholder="Agregar anotación..." aria-label="Anotaciones del cliente"><?= h((string) ($lead['notes'] ?? '')) ?></textarea>
                        <select class="sales-status-select" data-id="<?= $leadId ?>" data-status="<?= h($salesStatus) ?>" aria-label="Status comercial">
                          <?php foreach (sales_status_options() as $value => $label): ?>
                            <option value="<?= h($value) ?>" <?= $salesStatus === (string) $value ? 'selected' : '' ?>><?= h($label) ?></option>
                          <?php endforeach; ?>
                        </select>
                      <?php else: ?>
                        <div class="readonly-text"><?= h(dash_value($lead['notes'] ?? null)) ?></div>
                        <span class="sales-status-badge" data-status="<?= h($salesStatus) ?>"><?= h($salesStatusLabel) ?></span>
                      <?php endif; ?>
                      <div class="funnel-actions">
                        <a class="funnel-action-link" href="<?= h(account_url('inbox.php', ['id' => $conversationPublicId, 'channel_id' => $filterChannelId > 0 ? $filterChannelId : null], $conversationSlug !== '' ? $conversationSlug : null)) ?>">Abrir conversación</a>
                        <?php if ($leadId > 0): ?><button class="funnel-action-link" type="button" data-history-open data-lead-id="<?= $leadId ?>">Ver historial</button><?php endif; ?>
                      </div>
                    </article>
                  <?php endforeach; else: ?>
                    <p class="funnel-empty">Sin conversaciones en este estado.</p>
                  <?php endif; ?>
                </div>
              </section>
            <?php endforeach; ?>
          </div>
          <?php if ($funnelOverflow): ?>
            <p class="funnel-limit-note">Mostrando las <?= (int) $funnelLimit ?> conversaciones más recientes del resultado filtrado.</p>
          <?php endif; ?>
          <?php if ($filterSalesStatus !== '' && $funnelTotalPages > 1): ?>
            <?php
              $paginationBaseParams = $summaryBaseParams + ['sales_status' => $filterSalesStatus];
              $paginationWindowStart = max(1, $funnelPage - 2);
              $paginationWindowEnd = min($funnelTotalPages, $funnelPage + 2);
            ?>
            <nav class="funnel-pagination" aria-label="Paginación de conversaciones filtradas">
              <span class="funnel-page-status">Página <?= (int) $funnelPage ?> de <?= (int) $funnelTotalPages ?> · 40 conversaciones por página</span>
              <?php if ($funnelPage > 1): ?>
                <a class="funnel-page-link" href="<?= h(dashboard_query_url($paginationBaseParams + ['page' => $funnelPage - 1])) ?>">Anterior</a>
              <?php endif; ?>
              <?php for ($page = $paginationWindowStart; $page <= $paginationWindowEnd; $page++): ?>
                <?php if ($page === $funnelPage): ?>
                  <span class="funnel-page-current" aria-current="page"><?= (int) $page ?></span>
                <?php else: ?>
                  <a class="funnel-page-link" href="<?= h(dashboard_query_url($paginationBaseParams + ['page' => $page])) ?>"><?= (int) $page ?></a>
                <?php endif; ?>
              <?php endfor; ?>
              <?php if ($funnelPage < $funnelTotalPages): ?>
                <a class="funnel-page-link" href="<?= h(dashboard_query_url($paginationBaseParams + ['page' => $funnelPage + 1])) ?>">Siguiente</a>
              <?php endif; ?>
            </nav>
          <?php endif; ?>
        </div>
      </div>
    </section>

    <div class="credit">Desarrollado por <strong><?= h(app_config('brand.developer', 'Pixels Studio')) ?></strong></div>
  </main>

  <div id="profileModal" class="modal-backdrop" data-modal>
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="profileTitle">
      <h2 id="profileTitle">Actualizar contraseña</h2>
      <p class="subtitle">Usuario: <strong><?= h($_SESSION['username']) ?></strong></p>
      <form id="profileForm" class="form" action="account_update.php" method="post" novalidate>
        <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf'] ?? '') ?>">
        <label class="field"><span class="field-label">Contraseña actual</span><input type="password" name="currentpass" minlength="8" required autocomplete="current-password"></label>
        <label class="field"><span class="field-label">Nueva contraseña</span><input type="password" name="newpass" minlength="8" required autocomplete="new-password"></label>
        <label class="field"><span class="field-label">Confirmar contraseña</span><input type="password" name="confirm" minlength="8" required autocomplete="new-password"></label>
        <div id="profileAlert" class="form-alert" aria-live="polite"></div>
        <div class="actions">
          <button type="button" class="btn-secondary" data-modal-close>Cancelar</button>
          <button type="submit" class="btn-secondary">Guardar</button>
        </div>
      </form>
    </div>
  </div>

  <script src="js/dashboard.js?v=<?= (int) @filemtime(__DIR__ . '/js/dashboard.js') ?>" defer></script>
</body>
</html>
