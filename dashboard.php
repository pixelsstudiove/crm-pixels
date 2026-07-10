<?php
// dashboard.php
declare(strict_types=1);
require_once __DIR__ . '/auth/require_auth.php';
require_once __DIR__ . '/config/conversations.php';

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
  $defaultSalesStatus = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) app_config('sales_funnel.default_status', 'nuevo_lead')) ?: 'nuevo_lead';
  $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS {$table} (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
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
  UNIQUE KEY uniq_external_contact (external_source, external_contact_id),
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

  $columns = [
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
    'idx_brand_instagram' => "ALTER TABLE {$table} ADD KEY idx_brand_instagram (brand_instagram)",
    'idx_business_type' => "ALTER TABLE {$table} ADD KEY idx_business_type (business_type)",
    'idx_main_objective' => "ALTER TABLE {$table} ADD KEY idx_main_objective (main_objective)",
    'idx_source_platform' => "ALTER TABLE {$table} ADD KEY idx_source_platform (source_platform)",
    'idx_utm_campaign' => "ALTER TABLE {$table} ADD KEY idx_utm_campaign (utm_campaign)",
    'idx_utm_content' => "ALTER TABLE {$table} ADD KEY idx_utm_content (utm_content)",
    'idx_ad_name' => "ALTER TABLE {$table} ADD KEY idx_ad_name (ad_name)",
    'idx_sales_status' => "ALTER TABLE {$table} ADD KEY idx_sales_status (sales_status)",
    'idx_reminder_at' => "ALTER TABLE {$table} ADD KEY idx_reminder_at (reminder_at)",
    'uniq_external_contact' => "ALTER TABLE {$table} ADD UNIQUE KEY uniq_external_contact (external_source, external_contact_id)",
    'idx_last_message_at' => "ALTER TABLE {$table} ADD KEY idx_last_message_at (last_message_at)",
    'idx_status' => "ALTER TABLE {$table} ADD KEY idx_status (status)",
    'idx_created_at' => "ALTER TABLE {$table} ADD KEY idx_created_at (created_at)",
  ];
  foreach ($indexes as $index => $sql) if (!index_exists_dash($pdo, $dbName, $table, $index)) { try { $pdo->exec($sql); } catch (Throwable $e) {} }
}

ensure_dashboard_schema($pdo, $DB_NAME, $TABLE_LEADS);
conv_ensure_schema($pdo);

$contactsTable = conv_contacts_table();
$conversationsTable = conv_conversations_table();
$channelsTable = ig_channels_table();

$currentRole = current_user_role();
$currentRoleLabel = role_label($currentRole);
require_permission('view_dashboard');
$canEditLeads = can('edit_leads');
$canManageUsers = can('manage_users');
$canManageIntegrations = can('manage_integrations');

$q = trim((string) ($_GET['q'] ?? ''));

$channelOptions = [];
try {
  $channelStmt = $pdo->query(<<<SQL
SELECT DISTINCT ch.id, ch.page_name, ch.page_id, ch.instagram_username
FROM {$conversationsTable} c
JOIN {$channelsTable} ch ON ch.id = c.channel_id
ORDER BY COALESCE(ch.instagram_username, ch.page_name, ch.page_id) ASC
SQL);
  $channelOptions = $channelStmt ? $channelStmt->fetchAll() : [];
} catch (Throwable $e) {
  $channelOptions = [];
}
$channelIds = array_map(static fn($row) => (int) ($row['id'] ?? 0), $channelOptions);
$filterChannelId = max(0, (int) ($_GET['channel_id'] ?? 0));
if ($filterChannelId > 0 && !in_array($filterChannelId, $channelIds, true)) $filterChannelId = 0;

$salesStatusOptions = (array) app_config('sales_funnel.statuses', []);

$defaultSalesStatus = (string) app_config('sales_funnel.default_status', 'nuevo_lead');
$whereConditions = [];
$whereParams = [];
if ($filterChannelId > 0) {
  $whereConditions[] = 'c.channel_id = :channel_id';
  $whereParams[':channel_id'] = $filterChannelId;
}
if ($q !== '') {
  $digits = preg_replace('/\D+/', '', $q) ?: $q;
  $whereConditions[] = "(c.id LIKE :q OR l.id LIKE :q OR ct.display_name LIKE :q OR ct.username LIKE :q OR ct.external_contact_id LIKE :q OR c.last_message_preview LIKE :q OR ch.page_name LIKE :q OR ch.instagram_username LIKE :q OR l.fullname LIKE :q OR l.phone LIKE :q OR REPLACE(COALESCE(l.phone,''),'-','') LIKE :qd OR l.email LIKE :q OR l.brand_instagram LIKE :q OR l.business_type LIKE :q OR l.business_type_other LIKE :q OR l.services_needed LIKE :q OR l.main_objective LIKE :q OR l.message LIKE :q OR l.last_inbound_message LIKE :q OR l.source_platform LIKE :q OR l.utm_source LIKE :q OR l.utm_medium LIKE :q OR l.utm_campaign LIKE :q OR l.utm_content LIKE :q OR l.utm_term LIKE :q OR l.ad_name LIKE :q OR l.ad_id LIKE :q OR l.external_contact_id LIKE :q OR l.sales_status LIKE :q OR l.notes LIKE :q OR l.reminder_note LIKE :q)";
  $whereParams[':q'] = '%' . $q . '%';
  $whereParams[':qd'] = '%' . $digits . '%';
}

$whereSql = $whereConditions ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
$activeFilters = array_filter([
  'channel_id' => $filterChannelId > 0 ? $filterChannelId : null,
  'q' => $q,
], static fn($v) => $v !== '' && $v !== null);

$conversationFromSql = "FROM {$conversationsTable} c JOIN {$contactsTable} ct ON ct.id = c.contact_id LEFT JOIN {$channelsTable} ch ON ch.id = c.channel_id LEFT JOIN {$TABLE_LEADS} l ON l.id = c.lead_id";

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
$summaryCards = [
  ['label' => 'Resultados', 'value' => $total, 'tone' => 'total'],
  ['label' => (string) ($salesStatusOptions['nuevo_lead'] ?? 'Nuevo lead'), 'value' => $statusCounts['nuevo_lead'] ?? 0, 'tone' => 'new'],
  ['label' => (string) ($salesStatusOptions['contactado'] ?? 'Contactado'), 'value' => $statusCounts['contactado'] ?? 0, 'tone' => 'contacted'],
  ['label' => (string) ($salesStatusOptions['diagnostico_agendado'] ?? 'Diagnóstico agendado'), 'value' => $statusCounts['diagnostico_agendado'] ?? 0, 'tone' => 'scheduled'],
  ['label' => (string) ($salesStatusOptions['cliente_ganado'] ?? 'Cliente ganado'), 'value' => $statusCounts['cliente_ganado'] ?? 0, 'tone' => 'won'],
  ['label' => (string) ($salesStatusOptions['cliente_perdido'] ?? 'Cliente perdido'), 'value' => $statusCounts['cliente_perdido'] ?? 0, 'tone' => 'lost'],
];
$funnelLimit = 300;
$funnelLeadsByStatus = [];
foreach ($salesStatusOptions as $statusValue => $statusLabel) {
  $funnelLeadsByStatus[(string) $statusValue] = [];
}
$funnelOverflow = false;
$funnelSql = <<<SQL
SELECT
  COALESCE(l.id, 0) AS id,
  c.id AS conversation_id,
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
  COALESCE(l.fullname, ct.display_name, NULLIF(CONCAT('@', TRIM(LEADING '@' FROM COALESCE(ct.username, ''))), '@'), 'Contacto de Instagram') AS fullname,
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
  COALESCE(l.last_message_at, c.last_message_at) AS last_message_at,
  COALESCE(l.last_inbound_message, c.last_message_preview) AS last_inbound_message,
  COALESCE(l.created_at, c.created_at) AS created_at,
  COALESCE(l.updated_at, c.updated_at) AS updated_at
{$conversationFromSql}
%WHERE%
ORDER BY COALESCE(c.last_message_at, c.created_at) DESC
LIMIT :limit
SQL;
$funnelSql = str_replace('%WHERE%', $whereSql, $funnelSql);
$funnelStmt = $pdo->prepare($funnelSql);
$funnelStmt->bindValue(':default_sales_status_select', $defaultSalesStatus);
foreach ($whereParams as $key => $value) $funnelStmt->bindValue($key, $value);
$funnelStmt->bindValue(':limit', $funnelLimit, PDO::PARAM_INT);
$funnelStmt->execute();
$funnelLeads = $funnelStmt->fetchAll();
$funnelOverflow = $total > $funnelLimit && count($funnelLeads) >= $funnelLimit;

foreach ($funnelLeads as $lead) {
  $statusValue = (string) ($lead['sales_status'] ?? '');
  if (!array_key_exists($statusValue, $salesStatusOptions)) {
    $statusValue = (string) app_config('sales_funnel.default_status', 'nuevo_lead');
  }
  if (!isset($funnelLeadsByStatus[$statusValue])) $funnelLeadsByStatus[$statusValue] = [];
  $funnelLeadsByStatus[$statusValue][] = $lead;
}

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
  return dash_value($lead['updated_at'] ?? null) !== '—' ? (string) $lead['updated_at'] : 'Sin cambios';
}
function lead_message_display(array $lead): string {
  $last = dash_value($lead['last_inbound_message'] ?? null);
  if ($last !== '—') return $last;
  return dash_value($lead['message'] ?? null);
}
function lead_contact_display(array $lead): string {
  $phone = dash_value($lead['phone'] ?? null);
  if ($phone !== '—') return $phone;
  if (is_instagram_lead($lead)) return 'Instagram DM';
  return '—';
}
function datetime_local_value($value): string {
  $value = trim((string) $value);
  if ($value === '') return '';
  $time = strtotime($value);
  return $time ? date('Y-m-d\TH:i', $time) : '';
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
  <link rel="stylesheet" href="css/app.css">
  <meta name="csrf" content="<?= h($_SESSION['csrf'] ?? '') ?>">
  <style>
    :root { --container-w: min(98vw, 1480px); }
    .notes-input { width:240px; min-height:38px; resize:vertical; padding:9px 10px; border:1px solid var(--line); border-radius:10px; color:var(--brand-ink); background:#fff; outline:none; font:inherit; line-height:1.35; }
    .notes-input:focus { border-color:var(--brand-primary); box-shadow:0 0 0 3px rgba(0,212,255,.16); }
    .notes-input.is-saving { opacity:.65; cursor:progress; }
    .topbar { display:flex; align-items:flex-start; justify-content:space-between; margin-bottom:10px; flex-wrap:wrap; gap:16px; }
    .topbar-right { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
    .wa-btn, .user-btn, .logout-btn, .search-btn { display:inline-flex; align-items:center; justify-content:center; gap:6px; border-radius:10px; border:1px solid var(--line); background:var(--surface-soft); color:#007ea8; text-decoration:none; height:40px; padding:0 14px; font-size:.95rem; font-weight:800; cursor:pointer; transition:background .2s ease, transform .06s ease, border-color .2s ease, color .2s ease; }
    .wa-btn:hover, .user-btn:hover, .logout-btn:hover, .search-btn:hover { background:#dff6ff; border-color:#8bdfff; }
    .wa-btn:active, .user-btn:active, .logout-btn:active, .search-btn:active { transform:translateY(1px); }
    .role-pill { display:inline-flex; align-items:center; justify-content:center; min-height:28px; padding:0 10px; border-radius:999px; border:1px solid #8bdfff; background:#eefaff; color:#006e95; font-size:.78rem; font-weight:900; white-space:nowrap; }
    .search-form { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
    .search-input { height:40px; min-width:360px; padding:0 12px; color:var(--brand-ink); border-radius:10px; border:1px solid var(--line); background:#fff; outline:none; }
    .search-input::placeholder { color:#7b8ca5; }
    .search-input:focus { border-color:var(--brand-primary); box-shadow:0 0 0 3px rgba(0,212,255,.16); }
    .summary-grid { display:grid; grid-template-columns:repeat(6, minmax(130px, 1fr)); gap:10px; margin-top:18px; }
    .summary-card { min-height:82px; padding:14px; border-radius:14px; border:1px solid var(--line); background:#fff; box-shadow:0 8px 22px rgba(0, 76, 110, .07); }
    .summary-card strong { display:block; color:var(--brand-ink); font-size:1.7rem; line-height:1; font-weight:900; }
    .summary-card span { display:block; margin-top:8px; color:var(--brand-muted); font-size:.78rem; font-weight:850; letter-spacing:.03em; text-transform:uppercase; line-height:1.25; }
    .summary-card[data-tone="total"] { border-color:#8bdfff; background:#eefaff; }
    .summary-card[data-tone="new"] { border-left:5px solid #00a9e0; }
    .summary-card[data-tone="contacted"] { border-left:5px solid #5f7cff; }
    .summary-card[data-tone="scheduled"] { border-left:5px solid #9b6bff; }
    .summary-card[data-tone="won"] { border-left:5px solid #2f9e62; }
    .summary-card[data-tone="lost"] { border-left:5px solid #cf4d5b; }
    .lead-filters { margin-top:18px; padding:14px; border:1px solid rgba(0,212,255,.16); border-radius:18px; background:#eefaff; }
    .filters-toggle { display:none; align-items:center; justify-content:center; min-height:40px; margin-top:14px; padding:0 14px; border-radius:10px; border:1px solid var(--line); background:var(--surface-soft); color:#007ea8; font-size:.95rem; font-weight:800; cursor:pointer; }
    .filters-toggle:hover { background:#dff6ff; border-color:#8bdfff; }
    .filters-toggle:active { transform:translateY(1px); }
    .filters-form { display:grid; grid-template-columns:minmax(220px, 320px) auto; gap:10px; align-items:end; justify-content:start; }
    .filter-field { display:grid; gap:6px; min-width:0; }
    .filter-field span { color:var(--brand-muted); font-size:.78rem; font-weight:850; letter-spacing:.04em; text-transform:uppercase; }
    .filter-field select { width:100%; height:40px; padding:0 10px; border:1px solid var(--line); border-radius:10px; color:var(--brand-ink); background:#fff; outline:none; font-weight:700; }
    .filter-field select:focus { border-color:var(--brand-primary); box-shadow:0 0 0 3px rgba(0,212,255,.16); }
    .filter-actions { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
    .clear-filters { display:inline-flex; align-items:center; justify-content:center; min-height:40px; padding:0 12px; border-radius:10px; border:1px solid var(--line); color:#007ea8; background:#fff; font-weight:800; text-decoration:none; }
    .clear-filters:hover { background:#dff6ff; }
    .active-filter-note { margin:10px 0 0; color:var(--brand-muted); font-size:.88rem; font-weight:650; }
    .sales-status-select { --status-color:#8bdfff; --status-bg:#fff; width:185px; height:38px; padding:0 10px; border:1px solid var(--status-color); border-left-width:5px; border-radius:10px; color:var(--brand-ink); background:linear-gradient(90deg, var(--status-bg), #fff 76%); outline:none; font-weight:750; cursor:pointer; }
    .sales-status-select:focus { border-color:var(--brand-primary); box-shadow:0 0 0 3px rgba(0,212,255,.16); }
    .sales-status-select.is-saving { opacity:.65; cursor:progress; }
    .sales-status-select[data-status="nuevo_lead"] { --status-color:#00a9e0; --status-bg:#e8faff; }
    .sales-status-select[data-status="contactado"] { --status-color:#5f7cff; --status-bg:#eef2ff; }
    .sales-status-select[data-status="diagnostico_agendado"] { --status-color:#9b6bff; --status-bg:#f4efff; }
    .sales-status-select[data-status="propuesta_enviada"] { --status-color:#d69e2e; --status-bg:#fff8df; }
    .sales-status-select[data-status="en_negociacion"] { --status-color:#f97316; --status-bg:#fff2e8; }
    .sales-status-select[data-status="cliente_ganado"] { --status-color:#2f9e62; --status-bg:#eef9f0; }
    .sales-status-select[data-status="cliente_perdido"] { --status-color:#cf4d5b; --status-bg:#fff1f2; }
    .sales-status-select[data-status="no_responde"] { --status-color:#64748b; --status-bg:#f1f5f9; }
    .sales-status-select[data-status="no_califica"] { --status-color:#8a5a44; --status-bg:#f8f1ed; }
    .sales-status-badge { --status-color:#8bdfff; --status-bg:#fff; display:inline-flex; align-items:center; min-height:34px; padding:0 11px; border:1px solid var(--status-color); border-left-width:5px; border-radius:10px; background:var(--status-bg); color:var(--brand-ink); font-size:.9rem; font-weight:850; white-space:nowrap; }
    .sales-status-badge[data-status="nuevo_lead"] { --status-color:#00a9e0; --status-bg:#e8faff; }
    .sales-status-badge[data-status="contactado"] { --status-color:#5f7cff; --status-bg:#eef2ff; }
    .sales-status-badge[data-status="diagnostico_agendado"] { --status-color:#9b6bff; --status-bg:#f4efff; }
    .sales-status-badge[data-status="propuesta_enviada"] { --status-color:#d69e2e; --status-bg:#fff8df; }
    .sales-status-badge[data-status="en_negociacion"] { --status-color:#f97316; --status-bg:#fff2e8; }
    .sales-status-badge[data-status="cliente_ganado"] { --status-color:#2f9e62; --status-bg:#eef9f0; }
    .sales-status-badge[data-status="cliente_perdido"] { --status-color:#cf4d5b; --status-bg:#fff1f2; }
    .sales-status-badge[data-status="no_responde"] { --status-color:#64748b; --status-bg:#f1f5f9; }
    .sales-status-badge[data-status="no_califica"] { --status-color:#8a5a44; --status-bg:#f8f1ed; }
    .readonly-text { min-width:220px; max-width:300px; color:var(--brand-ink); line-height:1.35; white-space:pre-wrap; overflow-wrap:anywhere; }
    .funnel-wrap { margin-top:18px; overflow-x:auto; padding-bottom:6px; }
    .funnel-board { display:flex; gap:12px; align-items:flex-start; min-width:max-content; }
    .funnel-column { --status-color:#00a9e0; --status-bg:#eefaff; width:300px; max-height:72vh; display:flex; flex-direction:column; border:1px solid rgba(0,212,255,.16); border-top:5px solid var(--status-color); border-radius:16px; background:var(--status-bg); overflow:hidden; }
    .funnel-column[data-status="nuevo_lead"] { --status-color:#00a9e0; --status-bg:#eefaff; }
    .funnel-column[data-status="contactado"] { --status-color:#5f7cff; --status-bg:#eef2ff; }
    .funnel-column[data-status="diagnostico_agendado"] { --status-color:#9b6bff; --status-bg:#f4efff; }
    .funnel-column[data-status="propuesta_enviada"] { --status-color:#d69e2e; --status-bg:#fff8df; }
    .funnel-column[data-status="en_negociacion"] { --status-color:#f97316; --status-bg:#fff2e8; }
    .funnel-column[data-status="cliente_ganado"] { --status-color:#2f9e62; --status-bg:#eef9f0; }
    .funnel-column[data-status="cliente_perdido"] { --status-color:#cf4d5b; --status-bg:#fff1f2; }
    .funnel-column[data-status="no_responde"] { --status-color:#64748b; --status-bg:#f1f5f9; }
    .funnel-column[data-status="no_califica"] { --status-color:#8a5a44; --status-bg:#f8f1ed; }
    .funnel-column-header { position:sticky; top:0; z-index:1; display:flex; align-items:center; justify-content:space-between; gap:10px; padding:12px 14px; background:#071120; color:#eafaff; }
    .funnel-column-header h2 { margin:0; font-size:.92rem; line-height:1.2; }
    .funnel-count { display:inline-flex; align-items:center; justify-content:center; min-width:28px; height:28px; padding:0 8px; border-radius:999px; background:rgba(0,212,255,.18); color:#eafaff; font-weight:900; }
    .funnel-list { display:grid; gap:10px; padding:10px; overflow:auto; }
    .funnel-card { display:grid; gap:8px; padding:12px; border:1px solid var(--line); border-left:5px solid var(--status-color); border-radius:12px; background:#fff; box-shadow:0 8px 22px rgba(0, 76, 110, .07); }
    .funnel-card-title { display:flex; align-items:flex-start; justify-content:space-between; gap:10px; }
    .funnel-card-title strong { color:var(--brand-ink); line-height:1.2; }
    .funnel-id { color:#007ea8; font-size:.78rem; font-weight:900; }
    .funnel-actions { display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin-top:2px; }
    .funnel-action-link { display:inline-flex; width:100%; align-items:center; justify-content:center; min-height:38px; padding:0 10px; border-radius:10px; border:1px solid var(--line); background:var(--surface-soft); color:#007ea8; font-size:.82rem; font-weight:850; text-decoration:none; }
    .funnel-action-link:hover { background:#dff6ff; border-color:#8bdfff; }
    .funnel-meta { display:grid; gap:4px; color:var(--brand-muted); font-size:.83rem; line-height:1.3; }
    .funnel-meta-line { display:flex; gap:5px; align-items:center; flex-wrap:wrap; }
    .funnel-meta a { color:#007ea8; font-weight:800; text-decoration:none; }
    .funnel-meta a:hover { text-decoration:underline; }
    .funnel-note { color:var(--brand-ink); font-size:.86rem; line-height:1.35; white-space:pre-wrap; overflow-wrap:anywhere; }
    .funnel-card .sales-status-select { width:100%; }
    .funnel-card .notes-input { width:100%; min-height:74px; font-size:.86rem; }
    .funnel-empty { margin:0; padding:14px; color:var(--brand-muted); font-weight:750; }
    .funnel-limit-note { margin:12px 0 0; color:var(--brand-muted); font-size:.88rem; font-weight:700; }
    .modal-backdrop { position:fixed; inset:0; background:rgba(0,8,18,.72); display:none; align-items:center; justify-content:center; z-index:9999; }
    .modal-backdrop.is-open { display:flex; }
    .modal { width:min(92vw, 520px); border-radius:22px; border:1px solid rgba(255,255,255,.32); background:rgba(7,17,32,.96); backdrop-filter:blur(18px) saturate(120%); box-shadow:0 20px 60px rgba(0,0,0,.45); padding:24px; color:#eafaff; }
    .modal h2 { margin:0 0 10px; font-size:1.4rem; color:#fff; }
    .modal .subtitle { color:rgba(234,250,255,.86); }
    .modal .actions { display:flex; gap:10px; justify-content:flex-end; margin-top:14px; }
    .modal .field-label { color:#eafaff; }
    .modal input { background:rgba(255,255,255,.08); border-color:rgba(255,255,255,.22); color:#fff; }
    .btn-secondary { appearance:none; border:1px solid rgba(255,255,255,.35); background:transparent; color:#eafaff; padding:10px 14px; border-radius:12px; cursor:pointer; }
    .btn-secondary:hover { background:rgba(255,255,255,.08); }
    @media (max-width: 1200px) { .summary-grid { grid-template-columns:repeat(3, minmax(150px, 1fr)); } }
    @media (max-width: 760px) {
      .summary-grid { grid-template-columns:repeat(2, minmax(0, 1fr)); gap:8px; }
      .summary-card { min-height:72px; padding:12px; }
      .summary-card strong { font-size:1.45rem; }
      .summary-card span { font-size:.72rem; }
      .filters-toggle { display:inline-flex; }
      .lead-filters { display:none; }
      .lead-filters.is-open { display:block; }
      .filters-form { grid-template-columns:1fr; }
      .funnel-column { width:280px; max-height:68vh; }
    }
    @media (max-width: 700px) { .search-input { min-width:220px; width:100%; } .search-form { width:100%; } .topbar-right { width:100%; } }
  </style>
</head>
<body class="dashboard-page">
  <main class="dashboard-shell">
    <section class="form-card dashboard-card">
      <div class="panel">
        <div class="topbar">
          <div>
            <p class="eyebrow"><?= h(app_config('brand.name', 'Marca')) ?></p>
            <h1 class="title"><?= h(app_config('ui.dashboard_heading', 'Dashboard')) ?></h1>
          </div>
          <div class="topbar-right">
            <form class="search-form" method="get" action="dashboard.php">
              <input class="search-input" type="text" name="q" value="<?= h($q) ?>" placeholder="Buscar conversación, cliente, Instagram o mensaje">
              <?php if ($filterChannelId > 0): ?><input type="hidden" name="channel_id" value="<?= (int) $filterChannelId ?>"><?php endif; ?>
              <button class="search-btn" type="submit">Buscar</button>
            </form>
            <span class="role-pill"><?= h($currentRoleLabel) ?></span>
            <?php if (can('view_conversations')): ?><a class="user-btn" href="inbox.php" title="Inbox conversacional">Inbox</a><?php endif; ?>
            <?php if ($canManageIntegrations): ?><a class="user-btn" href="channels.php" title="Canales conectados">Canales</a><?php endif; ?>
            <?php if ($canManageUsers): ?><a class="user-btn" href="users.php" title="Administrar usuarios">Usuarios</a><?php endif; ?>
            <button type="button" class="user-btn" data-modal-open="profileModal" title="Perfil de usuario">👤 <?= h($_SESSION['username']) ?></button>
            <form action="logout.php" method="post" style="margin:0">
              <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf'] ?? '') ?>">
              <button class="logout-btn" type="submit" title="Cerrar sesión">⎋</button>
            </form>
          </div>
        </div>

        <p class="subtitle">
          <?= h(app_config('ui.dashboard_subtitle', 'Listado de registros')) ?><?= $q !== '' ? " – Búsqueda: <strong>" . h($q) . "</strong>" : '' ?>
        </p>

        <div class="summary-grid" aria-label="Resumen comercial">
          <?php foreach ($summaryCards as $card): ?>
            <div class="summary-card" data-tone="<?= h($card['tone']) ?>">
              <strong><?= (int) $card['value'] ?></strong>
              <span><?= h($card['label']) ?></span>
            </div>
          <?php endforeach; ?>
        </div>

        <button class="filters-toggle" type="button" data-filters-toggle aria-controls="leadFilters" aria-expanded="false">Filtros<?= $activeFilters ? ' (' . count($activeFilters) . ')' : '' ?></button>

        <div class="lead-filters" id="leadFilters" aria-label="Filtros de conversaciones">
          <form class="filters-form" method="get" action="dashboard.php">
            <?php if ($q !== ''): ?><input type="hidden" name="q" value="<?= h($q) ?>"><?php endif; ?>

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
              <button class="search-btn" type="submit">Filtrar canal</button>
              <?php if ($activeFilters): ?><a class="clear-filters" href="dashboard.php">Limpiar</a><?php endif; ?>
            </div>
          </form>
          <?php if ($activeFilters): ?>
            <p class="active-filter-note">Mostrando <?= (int) $total ?> conversacion<?= $total === 1 ? '' : 'es' ?> con los filtros activos.</p>
          <?php endif; ?>
        </div>

        <div class="funnel-wrap" aria-label="Embudo comercial">
          <div class="funnel-board">
            <?php foreach (sales_status_options() as $statusValue => $statusLabel): ?>
              <?php $cards = $funnelLeadsByStatus[(string) $statusValue] ?? []; ?>
              <section class="funnel-column" data-status="<?= h((string) $statusValue) ?>" aria-labelledby="funnel-<?= h((string) $statusValue) ?>">
                <header class="funnel-column-header">
                  <h2 id="funnel-<?= h((string) $statusValue) ?>"><?= h($statusLabel) ?></h2>
                  <span class="funnel-count"><?= (int) ($statusCounts[(string) $statusValue] ?? 0) ?></span>
                </header>
                <div class="funnel-list">
                  <?php if ($cards): foreach ($cards as $lead): ?>
                    <?php
                      $conversationId = (int) ($lead['conversation_id'] ?? 0);
                      $leadId = (int) ($lead['id'] ?? 0);
                      $phoneValue = dash_value($lead['phone'] ?? null);
                      $wa = $phoneValue !== '—' ? wa_number_from_formatted($phoneValue) : '';
                      $isInstagramLead = is_instagram_lead($lead);
                      $salesStatus = (string) ($lead['sales_status'] ?? app_config('sales_funnel.default_status', 'nuevo_lead'));
                      $salesStatusLabel = (string) ($salesStatusOptions[$salesStatus] ?? $salesStatus);
                      $adValue = dash_pick($lead, ['ad_name','utm_content','ad_id']);
                      $igUrl = instagram_url($lead['brand_instagram'] ?? '');
                      $igHandle = instagram_handle($lead['brand_instagram'] ?? '');
                    ?>
                    <article class="funnel-card" data-id="<?= $leadId ?>" data-conversation-id="<?= $conversationId ?>">
                      <div class="funnel-card-title">
                        <strong><?= h(short_value($lead['fullname'] ?? null, 34)) ?></strong>
                        <span class="funnel-id">Conv #<?= $conversationId ?></span>
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
                          <?php else: ?>
                            <?= h(lead_contact_display($lead)) ?>
                          <?php endif; ?>
                        </span>
                        <span>Actualizado: <?= h(updated_display($lead)) ?></span>
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
                        <a class="funnel-action-link" href="inbox.php?id=<?= $conversationId ?><?= $filterChannelId > 0 ? '&channel_id=' . (int) $filterChannelId : '' ?>">Abrir conversación</a>
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

  <script src="js/dashboard.js" defer></script>
</body>
</html>
