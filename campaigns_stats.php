<?php
// campaigns_stats.php
declare(strict_types=1);
require_once __DIR__ . '/auth/require_auth.php';
require_once __DIR__ . '/config/conversations.php';
require_once __DIR__ . '/config/lead_status_history.php';
require_once __DIR__ . '/config/navigation.php';
require_once __DIR__ . '/config/ad_attribution.php';

function camp_log_runtime_error(Throwable $e): void {
  $storageDir = __DIR__ . '/storage';
  if (!is_dir($storageDir)) {
    @mkdir($storageDir, 0775, true);
  }
  $context = [
    'time' => gmdate('c'),
    'uri' => (string) ($_SERVER['REQUEST_URI'] ?? ''),
    'method' => (string) ($_SERVER['REQUEST_METHOD'] ?? ''),
    'message' => $e->getMessage(),
    'file' => $e->getFile(),
    'line' => $e->getLine(),
  ];
  $line = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: $e->getMessage();
  @file_put_contents($storageDir . '/campaigns_stats_errors.log', $line . PHP_EOL, FILE_APPEND);
}

set_exception_handler(static function (Throwable $e): void {
  camp_log_runtime_error($e);
  if (!headers_sent()) {
    http_response_code(200);
    header('Content-Type: text/html; charset=utf-8');
  }

  $showDetails = false;
  try {
    $showDetails = function_exists('is_super_admin') && is_super_admin();
  } catch (Throwable $ignored) {
    $showDetails = false;
  }

  $message = htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
  $file = htmlspecialchars($e->getFile() . ':' . $e->getLine(), ENT_QUOTES, 'UTF-8');
  echo '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Campañas - Error controlado</title><style>body{margin:0;background:#f4f7fb;color:#09111f;font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.wrap{min-height:100vh;display:grid;place-items:center;padding:24px;box-sizing:border-box}.card{max-width:760px;width:100%;background:#fff;border:1px solid #dbe7f4;border-radius:28px;box-shadow:0 22px 58px rgba(15,23,42,.08);padding:32px}.eyebrow{margin:0 0 10px;color:#12c7e8;font-weight:950;letter-spacing:.14em;text-transform:uppercase;font-size:.76rem}.card h1{margin:0 0 12px;font-size:clamp(2rem,4vw,3.5rem);line-height:.95;letter-spacing:-.05em}.card p{margin:0 0 16px;color:#69758d;font-weight:750;line-height:1.45}.card code{display:block;white-space:pre-wrap;word-break:break-word;background:#fff5f5;border:1px solid #fecaca;border-radius:16px;padding:14px;color:#9f1239;font-weight:800}.actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:22px}.actions a{min-height:46px;padding:0 18px;border-radius:16px;background:#050b18;color:#fff;text-decoration:none;display:inline-flex;align-items:center;font-weight:950}</style></head><body><main class="wrap"><section class="card"><p class="eyebrow">Pixels Studio</p><h1>No se pudieron cargar las estadísticas de campañas</h1><p>Registré el error técnico en <strong>storage/campaigns_stats_errors.log</strong>. Esto evita que la ruta quede en 500 y nos permite ver exactamente qué está fallando en el hosting.</p>';
  if ($showDetails) {
    echo '<code>' . $message . "\n" . $file . '</code>';
  }
  echo '<div class="actions"><a href="stats.php">Volver a estadísticas</a></div></section></main></body></html>';
  exit;
});

require_permission('view_reports');
conv_ensure_schema($pdo);
lead_status_history_ensure_schema($pdo);

$leadsTable = $TABLE_LEADS;
$usersTable = $TABLE_USERS;
$channelsTable = safe_identifier((string) app_config('database.instagram_channels_table', 'instagram_channels'), 'instagram_channels');
$conversationsTable = conv_conversations_table();
$messagesTable = conv_messages_table();
$campaignsTable = ads_campaigns_table();
$adsetsTable = ads_adsets_table();
$adsTable = ads_ads_table();

function camp_column_exists(PDO $pdo, string $dbName, string $table, string $column): bool {
  if ($dbName === '') return false;
  $stmt = $pdo->prepare('SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?');
  $stmt->execute([$dbName, $table, $column]);
  return (bool) $stmt->fetchColumn();
}

function camp_add_column_if_missing(PDO $pdo, string $dbName, string $table, string $column, string $preferredSql, string $fallbackSql): void {
  if (camp_column_exists($pdo, $dbName, $table, $column)) return;
  try {
    $pdo->exec($preferredSql);
    return;
  } catch (Throwable $e) {
    /* Try again without AFTER dependencies for partially migrated installs. */
  }
  try { $pdo->exec($fallbackSql); } catch (Throwable $e) { /* no-op */ }
}

function camp_ensure_lead_attribution_schema(PDO $pdo, string $dbName, string $table): void {
  $columns = [
    'campaign_id' => ["ALTER TABLE {$table} ADD COLUMN campaign_id VARCHAR(120) NULL AFTER utm_campaign", "ALTER TABLE {$table} ADD COLUMN campaign_id VARCHAR(120) NULL"],
    'campaign_name' => ["ALTER TABLE {$table} ADD COLUMN campaign_name VARCHAR(180) NULL AFTER campaign_id", "ALTER TABLE {$table} ADD COLUMN campaign_name VARCHAR(180) NULL"],
    'adset_id' => ["ALTER TABLE {$table} ADD COLUMN adset_id VARCHAR(120) NULL AFTER utm_term", "ALTER TABLE {$table} ADD COLUMN adset_id VARCHAR(120) NULL"],
    'adset_name' => ["ALTER TABLE {$table} ADD COLUMN adset_name VARCHAR(180) NULL AFTER adset_id", "ALTER TABLE {$table} ADD COLUMN adset_name VARCHAR(180) NULL"],
    'ad_name' => ["ALTER TABLE {$table} ADD COLUMN ad_name VARCHAR(180) NULL AFTER utm_content", "ALTER TABLE {$table} ADD COLUMN ad_name VARCHAR(180) NULL"],
    'ad_id' => ["ALTER TABLE {$table} ADD COLUMN ad_id VARCHAR(120) NULL AFTER ad_name", "ALTER TABLE {$table} ADD COLUMN ad_id VARCHAR(120) NULL"],
    'ad_referral_payload' => ["ALTER TABLE {$table} ADD COLUMN ad_referral_payload TEXT NULL AFTER ad_referral_type", "ALTER TABLE {$table} ADD COLUMN ad_referral_payload TEXT NULL"],
    'ad_enrichment_error' => ["ALTER TABLE {$table} ADD COLUMN ad_enrichment_error VARCHAR(255) NULL AFTER ad_referral_payload", "ALTER TABLE {$table} ADD COLUMN ad_enrichment_error VARCHAR(255) NULL"],
  ];
  foreach ($columns as $column => $sqls) {
    camp_add_column_if_missing($pdo, $dbName, $table, $column, $sqls[0], $sqls[1]);
  }
}

function camp_valid_date(string $date, string $fallback): string {
  $dt = DateTimeImmutable::createFromFormat('Y-m-d', $date);
  return $dt && $dt->format('Y-m-d') === $date ? $date : $fallback;
}

function camp_local_date_to_utc(string $date, bool $endOfDay = false): string {
  $timezone = new DateTimeZone((string) app_config('system.timezone', 'America/Caracas'));
  $time = $endOfDay ? '23:59:59' : '00:00:00';
  return (new DateTimeImmutable($date . ' ' . $time, $timezone))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

function camp_fetch_all(PDO $pdo, string $sql, array $params = []): array {
  $stmt = $pdo->prepare($sql);
  foreach ($params as $key => $value) {
    $stmt->bindValue($key, is_int($value) ? $value : (string) $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
  }
  $stmt->execute();
  return $stmt->fetchAll() ?: [];
}

function camp_scope(string $dateExpression, string $fromUtc, string $toUtc, int $accountId, int $channelId): array {
  $where = ["{$dateExpression} BETWEEN :from_utc AND :to_utc"];
  $params = [':from_utc' => $fromUtc, ':to_utc' => $toUtc];
  if ($accountId > 0) {
    $where[] = 'c.account_id = :account_id';
    $params[':account_id'] = $accountId;
  }
  if ($channelId > 0) {
    $where[] = 'c.channel_id = :channel_id';
    $params[':channel_id'] = $channelId;
  }
  return ['sql' => 'WHERE ' . implode(' AND ', $where), 'params' => $params];
}

function camp_lead_expr(array $columns, string $column): string {
  return in_array($column, $columns, true) ? 'l.' . $column : 'NULL';
}

function camp_clean(?string $value): string {
  return trim((string) $value);
}

function camp_is_generic(?string $value): bool {
  $value = strtoupper(trim((string) $value));
  return $value === '' || in_array($value, ['ADS', 'AD', 'NONE', 'NULL'], true);
}

function camp_best_label(array $values, string $fallback): string {
  foreach ($values as $value) {
    $value = camp_clean(is_scalar($value) ? (string) $value : '');
    if ($value !== '' && !camp_is_generic($value)) return $value;
  }
  return $fallback;
}

function camp_key(string $prefix, array $ids, array $labels, string $fallbackSeed): string {
  foreach ($ids as $id) {
    $id = camp_clean(is_scalar($id) ? (string) $id : '');
    if ($id !== '') return $prefix . ':id:' . $id;
  }
  foreach ($labels as $label) {
    $label = camp_clean(is_scalar($label) ? (string) $label : '');
    if ($label !== '' && !camp_is_generic($label)) return $prefix . ':name:' . sha1(mb_strtolower($label));
  }
  return $prefix . ':unknown:' . sha1($fallbackSeed);
}

function camp_empty_group(string $key, string $label, string $level, ?string $parentKey = null): array {
  return [
    'key' => $key,
    'label' => $label,
    'level' => $level,
    'parent_key' => $parentKey,
    'conversations' => 0,
    'lead_ids' => [],
    'conversation_ids' => [],
    'received' => 0,
    'outbound' => 0,
    'attended_conversations' => 0,
    'won' => 0,
    'lost' => 0,
    'no_response' => 0,
    'not_qualified' => 0,
    'statuses' => [],
    'response_seconds' => [],
    'agents' => [],
    'children' => [],
    'last_seen_at' => '',
  ];
}

function camp_status_label(string $status): string {
  return (string) (((array) app_config('sales_funnel.statuses', []))[$status] ?? $status);
}

function camp_add_conversation(array &$group, array $row): void {
  $conversationId = (int) ($row['conversation_id'] ?? 0);
  if ($conversationId > 0 && !isset($group['conversation_ids'][$conversationId])) {
    $group['conversation_ids'][$conversationId] = true;
    $group['conversations']++;
  }
  $leadId = (int) ($row['lead_id'] ?? 0);
  if ($leadId > 0) $group['lead_ids'][$leadId] = true;
  $status = (string) ($row['sales_status'] ?? app_config('sales_funnel.default_status', 'nuevo_lead'));
  $group['statuses'][$status] = ($group['statuses'][$status] ?? 0) + 1;
  if ($status === 'cliente_ganado') $group['won']++;
  if ($status === 'cliente_perdido') $group['lost']++;
  if ($status === 'no_responde') $group['no_response']++;
  if ($status === 'no_califica') $group['not_qualified']++;
  $lastSeen = (string) ($row['last_message_at'] ?? $row['created_at'] ?? '');
  if ($lastSeen !== '' && ($group['last_seen_at'] === '' || $lastSeen > $group['last_seen_at'])) $group['last_seen_at'] = $lastSeen;
}

function camp_add_messages(array &$group, int $inbound, int $outbound): void {
  $group['received'] += $inbound;
  $group['outbound'] += $outbound;
  if ($outbound > 0) $group['attended_conversations']++;
}

function camp_business_seconds_between(DateTimeImmutable $startUtc, DateTimeImmutable $endUtc): int {
  if ($endUtc <= $startUtc) return 0;
  $timezone = new DateTimeZone((string) app_config('business_hours.timezone', 'America/Caracas'));
  $startLocal = $startUtc->setTimezone($timezone);
  $endLocal = $endUtc->setTimezone($timezone);
  $workdays = array_map('intval', (array) app_config('business_hours.workdays', [1, 2, 3, 4, 5]));
  $startTime = (string) app_config('business_hours.start', '09:00');
  $endTime = (string) app_config('business_hours.end', '18:00');
  $seconds = 0;
  $day = $startLocal->setTime(0, 0, 0);
  $endDay = $endLocal->setTime(0, 0, 0);
  while ($day <= $endDay) {
    if (in_array((int) $day->format('N'), $workdays, true)) {
      $windowStart = new DateTimeImmutable($day->format('Y-m-d') . ' ' . $startTime, $timezone);
      $windowEnd = new DateTimeImmutable($day->format('Y-m-d') . ' ' . $endTime, $timezone);
      $from = max($startLocal->getTimestamp(), $windowStart->getTimestamp());
      $to = min($endLocal->getTimestamp(), $windowEnd->getTimestamp());
      if ($to > $from) $seconds += $to - $from;
    }
    $day = $day->modify('+1 day');
  }
  return $seconds;
}

function camp_format_duration(?int $seconds): string {
  if ($seconds === null) return 'Sin datos';
  if ($seconds < 60) return $seconds . 's';
  $minutes = (int) round($seconds / 60);
  if ($minutes < 60) return $minutes . 'm';
  $hours = intdiv($minutes, 60);
  $rest = $minutes % 60;
  return $rest > 0 ? $hours . 'h ' . $rest . 'm' : $hours . 'h';
}

function camp_percent(int $part, int $total): string {
  return $total > 0 ? number_format(($part / $total) * 100, 1, ',', '.') . '%' : '0,0%';
}

function camp_in_condition(string $column, array $ids, array &$params, string $prefix): string {
  $placeholders = [];
  foreach (array_values($ids) as $index => $id) {
    $name = ':' . $prefix . '_' . $index;
    $placeholders[] = $name;
    $params[$name] = (int) $id;
  }
  return $column . ' IN (' . implode(',', $placeholders) . ')';
}

function camp_agent_summary(array $agents): string {
  if (!$agents) return 'Sin agente';
  uasort($agents, static fn($a, $b) => ((int) $b['messages'] <=> (int) $a['messages']));
  $labels = [];
  foreach (array_slice($agents, 0, 3, true) as $agent) {
    $labels[] = (string) $agent['name'] . ' (' . (int) $agent['messages'] . ')';
  }
  return implode(', ', $labels);
}

camp_ensure_lead_attribution_schema($pdo, (string) ($DB_NAME ?? ''), $leadsTable);
ads_ensure_schema($pdo, $leadsTable);
try { ads_backfill_from_leads($pdo, $leadsTable, 500); } catch (Throwable $e) { /* no-op */ }

$requestSlug = accounts_request_slug();
$requestAccount = accounts_request_account($pdo);
if ($requestSlug !== '' && !$requestAccount) {
  http_response_code(404);
  exit('Cuenta no encontrada.');
}

$currentAccountId = (int) (current_account_id() ?: accounts_default_id($pdo));
$accountOptions = nav_fetch_account_options($pdo);
$filterAccountId = $currentAccountId;
if (is_super_admin()) {
  if ($requestAccount) {
    $filterAccountId = (int) ($requestAccount['id'] ?? 0);
  } else {
    $filterAccountId = isset($_GET['account_id']) && is_numeric($_GET['account_id']) ? max(0, (int) $_GET['account_id']) : 0;
  }
} elseif ($requestAccount) {
  $filterAccountId = (int) ($requestAccount['id'] ?? $currentAccountId);
}

$today = (new DateTimeImmutable('now', new DateTimeZone((string) app_config('system.timezone', 'America/Caracas'))))->format('Y-m-d');
$defaultFrom = (new DateTimeImmutable($today))->modify('-29 days')->format('Y-m-d');
$fromInput = camp_valid_date((string) ($_GET['from'] ?? $defaultFrom), $defaultFrom);
$toInput = camp_valid_date((string) ($_GET['to'] ?? $today), $today);
if ($fromInput > $toInput) [$fromInput, $toInput] = [$toInput, $fromInput];
$fromUtc = camp_local_date_to_utc($fromInput);
$toUtc = camp_local_date_to_utc($toInput, true);
$filterChannelId = isset($_GET['channel_id']) && is_numeric($_GET['channel_id']) ? max(0, (int) $_GET['channel_id']) : 0;

$channelParams = [];
$channelWhere = [];
if ($filterAccountId > 0) {
  $channelWhere[] = 'account_id = :account_id';
  $channelParams[':account_id'] = $filterAccountId;
}
$channelSql = $channelWhere ? 'WHERE ' . implode(' AND ', $channelWhere) : '';
$channelOptions = camp_fetch_all($pdo, "SELECT id, page_name, instagram_username FROM {$channelsTable} {$channelSql} ORDER BY page_name ASC, instagram_username ASC", $channelParams);

$leadColumns = [];
try {
  $stmt = $pdo->prepare('SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=?');
  $stmt->execute([(string) ($DB_NAME ?? ''), $leadsTable]);
  $leadColumns = array_map('strval', array_column($stmt->fetchAll() ?: [], 'COLUMN_NAME'));
} catch (Throwable $e) {
  $leadColumns = [];
}

$campaignIdExpr = camp_lead_expr($leadColumns, 'campaign_id');
$campaignNameExpr = camp_lead_expr($leadColumns, 'campaign_name');
$utmCampaignExpr = camp_lead_expr($leadColumns, 'utm_campaign');
$adsetIdExpr = camp_lead_expr($leadColumns, 'adset_id');
$adsetNameExpr = camp_lead_expr($leadColumns, 'adset_name');
$adIdExpr = camp_lead_expr($leadColumns, 'ad_id');
$adNameExpr = camp_lead_expr($leadColumns, 'ad_name');
$utmContentExpr = camp_lead_expr($leadColumns, 'utm_content');
$referralPayloadExpr = camp_lead_expr($leadColumns, 'ad_referral_payload');
$enrichmentErrorExpr = camp_lead_expr($leadColumns, 'ad_enrichment_error');
$salesStatusExpr = camp_lead_expr($leadColumns, 'sales_status');
$campaignRefExpr = camp_lead_expr($leadColumns, 'campaign_ref_id');
$adsetRefExpr = camp_lead_expr($leadColumns, 'adset_ref_id');
$adRefExpr = camp_lead_expr($leadColumns, 'ad_ref_id');

$scope = camp_scope('COALESCE(c.last_message_at, c.created_at)', $fromUtc, $toUtc, $filterAccountId, $filterChannelId);
$baseParams = $scope['params'];
$baseParams[':default_status'] = (string) app_config('sales_funnel.default_status', 'nuevo_lead');
$conversationRows = camp_fetch_all($pdo, <<<SQL
SELECT
  c.id AS conversation_id,
  c.lead_id,
  c.account_id,
  c.channel_id,
  c.created_at,
  c.last_message_at,
  COALESCE({$salesStatusExpr}, :default_status) AS sales_status,
  ch.page_name AS channel_label,
  ch.instagram_username AS channel_username,
  ac.external_campaign_id AS ref_campaign_id,
  ac.campaign_name AS ref_campaign_name,
  adst.external_adset_id AS ref_adset_id,
  adst.adset_name AS ref_adset_name,
  ad.external_ad_id AS ref_ad_id,
  ad.ad_name AS ref_ad_name,
  {$campaignIdExpr} AS lead_campaign_id,
  {$campaignNameExpr} AS lead_campaign_name,
  {$utmCampaignExpr} AS lead_utm_campaign,
  {$adsetIdExpr} AS lead_adset_id,
  {$adsetNameExpr} AS lead_adset_name,
  {$adIdExpr} AS lead_ad_id,
  {$adNameExpr} AS lead_ad_name,
  {$utmContentExpr} AS lead_utm_content,
  {$referralPayloadExpr} AS lead_referral_payload,
  {$enrichmentErrorExpr} AS lead_enrichment_error
FROM {$conversationsTable} c
LEFT JOIN {$leadsTable} l ON l.id = c.lead_id
LEFT JOIN {$channelsTable} ch ON ch.id = c.channel_id
LEFT JOIN {$campaignsTable} ac ON ac.id = {$campaignRefExpr}
LEFT JOIN {$adsetsTable} adst ON adst.id = {$adsetRefExpr}
LEFT JOIN {$adsTable} ad ON ad.id = {$adRefExpr}
{$scope['sql']}
  AND (
    ac.id IS NOT NULL
    OR adst.id IS NOT NULL
    OR ad.id IS NOT NULL
    OR NULLIF({$campaignIdExpr}, '') IS NOT NULL
    OR NULLIF({$campaignNameExpr}, '') IS NOT NULL
    OR NULLIF({$utmCampaignExpr}, '') IS NOT NULL
    OR NULLIF({$adsetIdExpr}, '') IS NOT NULL
    OR NULLIF({$adsetNameExpr}, '') IS NOT NULL
    OR NULLIF({$adIdExpr}, '') IS NOT NULL
    OR NULLIF({$adNameExpr}, '') IS NOT NULL
    OR NULLIF({$utmContentExpr}, '') IS NOT NULL
    OR NULLIF({$referralPayloadExpr}, '') IS NOT NULL
  )
ORDER BY COALESCE(c.last_message_at, c.created_at) DESC
LIMIT 5000
SQL, $baseParams);

$campaignGroups = [];
$adsetGroups = [];
$adGroups = [];
$conversationMap = [];

foreach ($conversationRows as $row) {
  $conversationId = (int) ($row['conversation_id'] ?? 0);
  if ($conversationId <= 0) continue;
  $fallbackSeed = implode('|', [
    (string) ($row['account_id'] ?? ''),
    (string) ($row['channel_id'] ?? ''),
    (string) ($row['lead_referral_payload'] ?? ''),
    (string) ($row['lead_ad_name'] ?? ''),
    (string) ($row['lead_adset_name'] ?? ''),
  ]);
  $campaignLabel = camp_best_label([
    $row['ref_campaign_name'] ?? '',
    $row['lead_campaign_name'] ?? '',
    $row['lead_utm_campaign'] ?? '',
  ], 'Campaña no disponible');
  $adsetLabel = camp_best_label([
    $row['ref_adset_name'] ?? '',
    $row['lead_adset_name'] ?? '',
    $row['lead_adset_id'] ?? '',
  ], 'Sin conjunto');
  $adLabel = camp_best_label([
    $row['ref_ad_name'] ?? '',
    $row['lead_ad_name'] ?? '',
    $row['lead_utm_content'] ?? '',
    $row['lead_ad_id'] ?? '',
  ], 'Sin anuncio');

  $campaignKey = camp_key('campaign', [
    $row['ref_campaign_id'] ?? '',
    $row['lead_campaign_id'] ?? '',
  ], [
    $row['ref_campaign_name'] ?? '',
    $row['lead_campaign_name'] ?? '',
    $row['lead_utm_campaign'] ?? '',
  ], $fallbackSeed . '|campaign|' . ($row['ref_adset_id'] ?? '') . '|' . ($row['lead_adset_id'] ?? '') . '|' . $adsetLabel);
  $adsetKey = camp_key('adset', [
    $row['ref_adset_id'] ?? '',
    $row['lead_adset_id'] ?? '',
  ], [
    $row['ref_adset_name'] ?? '',
    $row['lead_adset_name'] ?? '',
  ], $campaignKey . '|adset|' . $fallbackSeed);
  $adKey = camp_key('ad', [
    $row['ref_ad_id'] ?? '',
    $row['lead_ad_id'] ?? '',
  ], [
    $row['ref_ad_name'] ?? '',
    $row['lead_ad_name'] ?? '',
    $row['lead_utm_content'] ?? '',
  ], $adsetKey . '|ad|' . $fallbackSeed);

  $campaignGroups[$campaignKey] ??= camp_empty_group($campaignKey, $campaignLabel, 'campaign');
  $adsetGroups[$adsetKey] ??= camp_empty_group($adsetKey, $adsetLabel, 'adset', $campaignKey);
  $adGroups[$adKey] ??= camp_empty_group($adKey, $adLabel, 'ad', $adsetKey);
  $campaignGroups[$campaignKey]['children'][$adsetKey] = true;
  $adsetGroups[$adsetKey]['children'][$adKey] = true;

  camp_add_conversation($campaignGroups[$campaignKey], $row);
  camp_add_conversation($adsetGroups[$adsetKey], $row);
  camp_add_conversation($adGroups[$adKey], $row);
  $conversationMap[$conversationId] = ['campaign' => $campaignKey, 'adset' => $adsetKey, 'ad' => $adKey];
}

$conversationIds = array_keys($conversationMap);
if ($conversationIds) {
  $messageParams = [':from_utc' => $fromUtc, ':to_utc' => $toUtc];
  $inCondition = camp_in_condition('conversation_id', $conversationIds, $messageParams, 'conv');
  $messageRows = camp_fetch_all($pdo, <<<SQL
SELECT conversation_id,
  SUM(CASE WHEN direction='inbound' THEN 1 ELSE 0 END) AS inbound_count,
  SUM(CASE WHEN direction='outbound' THEN 1 ELSE 0 END) AS outbound_count
FROM {$messagesTable}
WHERE {$inCondition}
  AND sent_at BETWEEN :from_utc AND :to_utc
GROUP BY conversation_id
SQL, $messageParams);
  foreach ($messageRows as $row) {
    $conversationId = (int) ($row['conversation_id'] ?? 0);
    $keys = $conversationMap[$conversationId] ?? null;
    if (!$keys) continue;
    $inbound = (int) ($row['inbound_count'] ?? 0);
    $outbound = (int) ($row['outbound_count'] ?? 0);
    camp_add_messages($campaignGroups[$keys['campaign']], $inbound, $outbound);
    camp_add_messages($adsetGroups[$keys['adset']], $inbound, $outbound);
    camp_add_messages($adGroups[$keys['ad']], $inbound, $outbound);
  }

  $agentParams = [':from_utc' => $fromUtc, ':to_utc' => $toUtc];
  $agentInCondition = camp_in_condition('m.conversation_id', $conversationIds, $agentParams, 'agent_conv');
  $agentRows = camp_fetch_all($pdo, <<<SQL
SELECT m.conversation_id, COALESCE(m.sent_by, 0) AS agent_id, COALESCE(u.username, 'Sin agente') AS agent_name, COUNT(*) AS total
FROM {$messagesTable} m
LEFT JOIN {$usersTable} u ON u.id = m.sent_by
WHERE {$agentInCondition}
  AND m.direction='outbound'
  AND m.sent_at BETWEEN :from_utc AND :to_utc
GROUP BY m.conversation_id, COALESCE(m.sent_by, 0), COALESCE(u.username, 'Sin agente')
SQL, $agentParams);
  foreach ($agentRows as $row) {
    $conversationId = (int) ($row['conversation_id'] ?? 0);
    $keys = $conversationMap[$conversationId] ?? null;
    if (!$keys) continue;
    $agentId = (int) ($row['agent_id'] ?? 0);
    $agentName = (string) ($row['agent_name'] ?? 'Sin agente');
    $total = (int) ($row['total'] ?? 0);
    $agentTargets = [
      &$campaignGroups[$keys['campaign']],
      &$adsetGroups[$keys['adset']],
      &$adGroups[$keys['ad']],
    ];
    foreach ($agentTargets as &$targetGroup) {
      $targetGroup['agents'][$agentId] ??= ['name' => $agentName, 'messages' => 0];
      $targetGroup['agents'][$agentId]['messages'] += $total;
    }
    unset($targetGroup, $agentTargets);
  }

  $timelineParams = [':from_utc' => $fromUtc, ':to_utc' => $toUtc];
  $timelineInCondition = camp_in_condition('conversation_id', $conversationIds, $timelineParams, 'timeline_conv');
  $timelineRows = camp_fetch_all($pdo, <<<SQL
SELECT conversation_id, direction, sent_at
FROM {$messagesTable}
WHERE {$timelineInCondition}
  AND direction IN ('inbound', 'outbound')
  AND sent_at BETWEEN :from_utc AND :to_utc
ORDER BY conversation_id ASC, sent_at ASC, id ASC
SQL, $timelineParams);
  $firstInbound = [];
  $responses = [];
  foreach ($timelineRows as $row) {
    $conversationId = (int) ($row['conversation_id'] ?? 0);
    $direction = (string) ($row['direction'] ?? '');
    $sentAt = (string) ($row['sent_at'] ?? '');
    if ($direction === 'inbound' && !isset($firstInbound[$conversationId])) {
      $firstInbound[$conversationId] = $sentAt;
      continue;
    }
    if ($direction === 'outbound' && isset($firstInbound[$conversationId]) && !isset($responses[$conversationId])) {
      $inboundAt = app_utc_datetime($firstInbound[$conversationId]);
      $outboundAt = app_utc_datetime($sentAt);
      if ($inboundAt && $outboundAt && $outboundAt >= $inboundAt) {
        $responses[$conversationId] = camp_business_seconds_between($inboundAt, $outboundAt);
      }
    }
  }
  foreach ($responses as $conversationId => $seconds) {
    $keys = $conversationMap[(int) $conversationId] ?? null;
    if (!$keys) continue;
    $campaignGroups[$keys['campaign']]['response_seconds'][] = $seconds;
    $adsetGroups[$keys['adset']]['response_seconds'][] = $seconds;
    $adGroups[$keys['ad']]['response_seconds'][] = $seconds;
  }
}

$sortGroups = static function (array &$groups): void {
  uasort($groups, static fn($a, $b) => ((int) $b['conversations'] <=> (int) $a['conversations']) ?: strcmp((string) $a['label'], (string) $b['label']));
};
$sortGroups($campaignGroups);
$sortGroups($adsetGroups);
$sortGroups($adGroups);

$totalCampaigns = count($campaignGroups);
$totalConversations = array_sum(array_map(static fn($g) => (int) $g['conversations'], $campaignGroups));
$totalReceived = array_sum(array_map(static fn($g) => (int) $g['received'], $campaignGroups));
$totalAttended = array_sum(array_map(static fn($g) => (int) $g['attended_conversations'], $campaignGroups));
$totalWon = array_sum(array_map(static fn($g) => (int) $g['won'], $campaignGroups));
$allResponses = [];
foreach ($campaignGroups as $group) $allResponses = array_merge($allResponses, $group['response_seconds']);
$avgResponse = $allResponses ? (int) round(array_sum($allResponses) / count($allResponses)) : null;
$maxCampaignConversations = max(1, ...array_map(static fn($g) => (int) $g['conversations'], $campaignGroups ?: [['conversations' => 1]]));

$selectedAccountParamsAll = ['from' => $fromInput, 'to' => $toInput, 'channel_id' => $filterChannelId > 0 ? $filterChannelId : null];
$selectedAccountParamsAccount = $selectedAccountParamsAll;
$statsParams = $selectedAccountParamsAll + (is_super_admin() && $requestSlug === '' && $filterAccountId > 0 ? ['account_id' => $filterAccountId] : []);
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Campañas - <?= h(app_config('brand.name', 'Pixels Studio')) ?></title>
  <link rel="stylesheet" href="css/app.css">
  <style>
    :root{--pro-bg:#f4f7fb;--pro-panel:#fff;--pro-ink:#09111f;--pro-muted:#69758d;--pro-line:#dbe7f4;--pro-dark:#050b18;--pro-cyan:#12c7e8;--pro-violet:#7c3cff;--pro-green:#1fad72;--pro-amber:#f4a62a;--pro-red:#e6576d;--pro-shadow:0 22px 58px rgba(15,23,42,.08)}
    body.dashboard-page{min-height:100vh;margin:0;background:var(--pro-bg);color:var(--pro-ink);font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
    .pro-shell{width:100%;min-height:100vh;padding:clamp(18px,2.2vw,34px);box-sizing:border-box}
    .pro-grid{display:grid;gap:18px}
    .pro-header{display:flex;justify-content:space-between;align-items:flex-start;gap:20px}
    .pro-actions{display:flex;align-items:center;justify-content:flex-end;gap:10px;flex-wrap:wrap}
    .eyebrow{margin:0 0 8px;color:var(--pro-cyan);font-weight:950;font-size:.76rem;letter-spacing:.14em;text-transform:uppercase}
    .title{margin:0;color:var(--pro-ink);font-size:clamp(2.35rem,4.8vw,5.4rem);line-height:.9;letter-spacing:-.055em;font-weight:950}
    .subtitle{max-width:860px;margin:14px 0 0;color:var(--pro-muted);font-weight:750;font-size:1rem}
    .menu-trigger,.nav-direct-button,.pro-btn{min-height:50px;padding:0 20px;border:1px solid var(--pro-line);border-radius:18px;background:#fff;color:var(--pro-ink);display:inline-flex;align-items:center;justify-content:center;gap:8px;font-weight:950;text-decoration:none;box-shadow:0 8px 20px rgba(9,17,31,.05);transition:background .18s ease,color .18s ease,border-color .18s ease,transform .18s ease}
    .menu-trigger:hover,.nav-direct-button:hover,.pro-btn:hover{background:var(--pro-dark);color:#fff;border-color:var(--pro-dark);transform:translateY(-1px)}
    .menu-panel{border:1px solid var(--pro-line);border-radius:22px;box-shadow:var(--pro-shadow)}
    .filters-card,.metric-card,.content-card,.campaign-card{background:var(--pro-panel);border:1px solid var(--pro-line);border-radius:28px;box-shadow:var(--pro-shadow)}
    .filters-card{padding:16px;display:grid;grid-template-columns:minmax(150px,180px) minmax(150px,180px) minmax(220px,320px) auto;gap:12px;align-items:end}
    .field{display:grid;gap:7px}.field label{color:var(--pro-muted);font-size:.72rem;font-weight:950;letter-spacing:.1em;text-transform:uppercase}
    .field input,.field select{width:100%;min-height:48px;box-sizing:border-box;border:1px solid var(--pro-line);border-radius:16px;padding:0 14px;color:var(--pro-ink);background:#fff;font-weight:850;outline:none}
    .stats-layout{display:grid;grid-template-columns:minmax(210px,260px) minmax(0,1fr);gap:18px;align-items:start}
    .stats-side-nav{position:sticky;top:18px;display:grid;gap:8px;padding:14px;background:#fff;border:1px solid var(--pro-line);border-radius:26px;box-shadow:var(--pro-shadow)}
    .stats-side-nav span{color:var(--pro-muted);font-size:.72rem;font-weight:950;letter-spacing:.1em;text-transform:uppercase;padding:4px 8px 8px}
    .stats-side-nav a{min-height:42px;display:flex;align-items:center;border-radius:14px;padding:0 12px;color:var(--pro-muted);text-decoration:none;font-weight:900;transition:background .16s ease,color .16s ease,transform .16s ease}
    .stats-side-nav a:hover,.stats-side-nav a.is-active{background:var(--pro-dark);color:#fff;transform:translateX(2px)}
    .stats-content{min-width:0;display:grid;gap:18px}.stats-section{scroll-margin-top:22px}
    .metrics-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px}
    .metric-card{padding:22px;min-height:135px;position:relative;overflow:hidden}
    .metric-card::after{content:"";position:absolute;right:18px;top:18px;width:12px;height:12px;border-radius:50%;background:var(--tone,var(--pro-cyan));box-shadow:0 0 0 8px color-mix(in srgb,var(--tone,var(--pro-cyan)) 14%,transparent)}
    .metric-card span{display:block;color:var(--pro-muted);font-size:.76rem;font-weight:950;letter-spacing:.09em;text-transform:uppercase}.metric-card strong{display:block;margin-top:12px;color:var(--pro-ink);font-size:2.4rem;line-height:1;font-weight:950;letter-spacing:-.04em}
    .metric-card small{display:block;margin-top:10px;color:var(--pro-muted);font-weight:850}
    .section-head{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;margin-bottom:16px}.section-head h2{margin:0;color:var(--pro-ink);font-size:1.32rem;letter-spacing:-.02em}.section-head p{margin:6px 0 0;color:var(--pro-muted);font-weight:750}
    .campaign-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:14px}
    .campaign-card{padding:18px;display:grid;gap:14px;border-left:6px solid var(--pro-violet)}
    .campaign-top{display:flex;justify-content:space-between;gap:12px;align-items:flex-start}.campaign-top h3{margin:0;font-size:1.2rem;line-height:1.08}.campaign-top p{margin:6px 0 0;color:var(--pro-muted);font-weight:800}
    .pill{display:inline-flex;align-items:center;justify-content:center;min-height:34px;padding:0 12px;border-radius:999px;background:var(--pro-dark);color:#fff;font-size:.78rem;font-weight:950;white-space:nowrap}
    .mini-metrics{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}.mini-metric{border:1px solid var(--pro-line);border-radius:16px;background:#f8fbff;padding:11px}.mini-metric span{display:block;color:var(--pro-muted);font-size:.68rem;font-weight:950;text-transform:uppercase;letter-spacing:.07em}.mini-metric strong{display:block;margin-top:6px;font-size:1rem}
    .track{height:14px;overflow:hidden;border-radius:999px;background:#edf3f9}.fill{display:block;height:100%;width:var(--w);border-radius:999px;background:var(--tone,var(--pro-violet));transform-origin:left;animation:growx .82s ease both}@keyframes growx{from{transform:scaleX(0)}to{transform:scaleX(1)}}
    .status-row{display:flex;gap:8px;flex-wrap:wrap}.status-chip{border:1px solid var(--pro-line);border-radius:999px;padding:7px 10px;color:var(--pro-muted);font-weight:900;font-size:.78rem;background:#fff}
    .table-scroll{overflow:auto;border:1px solid var(--pro-line);border-radius:24px;background:#fff}.data-table{width:100%;min-width:980px;border-collapse:separate;border-spacing:0}.data-table th{background:var(--pro-dark);color:#fff;text-align:left;padding:16px;font-size:.78rem;text-transform:uppercase;letter-spacing:.08em}.data-table td{padding:15px 16px;border-top:1px solid var(--pro-line);vertical-align:top;font-weight:800;color:var(--pro-ink)}.data-table small{display:block;margin-top:4px;color:var(--pro-muted);font-weight:800}
    .empty{padding:28px;border:1px dashed var(--pro-line);border-radius:20px;color:var(--pro-muted);text-align:center;font-weight:850}
    @media(max-width:1180px){.metrics-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.filters-card{grid-template-columns:repeat(2,minmax(0,1fr))}.stats-layout{grid-template-columns:1fr}.stats-side-nav{position:relative;top:auto;display:flex;overflow:auto;white-space:nowrap}.stats-side-nav span{display:none}.stats-side-nav a{flex:0 0 auto}}
    @media(max-width:720px){.pro-shell{padding:14px}.pro-header{display:grid}.pro-actions{justify-content:flex-start}.metrics-grid,.filters-card,.mini-metrics{grid-template-columns:1fr}.campaign-grid{grid-template-columns:1fr}.title{font-size:2.45rem}}
  </style>
</head>
<body class="dashboard-page">
  <main class="pro-shell">
    <section class="pro-grid">
      <header class="pro-header">
        <div>
          <p class="eyebrow"><?= h(app_config('brand.name', 'Pixels Studio')) ?></p>
          <h1 class="title">Campañas</h1>
          <p class="subtitle">Lectura comercial por campaña, conjunto y anuncio: volumen, atención, status, agentes, respuesta operativa y ventas concretadas.</p>
        </div>
        <div class="pro-actions app-nav-actions">
          <?php nav_render_view_button('stats'); ?>
          <a class="menu-trigger nav-direct-button" href="<?= h(account_url('stats.php', $statsParams)) ?>">Stats Pro</a>
          <?php nav_render_account_switch($pdo, $accountOptions, $filterAccountId, 'campaigns_stats.php', $selectedAccountParamsAll, $selectedAccountParamsAccount); ?>
          <?php nav_render_user_menu(true); ?>
        </div>
      </header>

      <form class="filters-card" method="get" action="<?= h(account_url('campaigns_stats.php')) ?>">
        <?php if (is_super_admin() && $requestSlug === '' && $filterAccountId > 0): ?>
          <input type="hidden" name="account_id" value="<?= (int) $filterAccountId ?>">
        <?php endif; ?>
        <div class="field"><label for="from">Desde</label><input id="from" type="date" name="from" value="<?= h($fromInput) ?>"></div>
        <div class="field"><label for="to">Hasta</label><input id="to" type="date" name="to" value="<?= h($toInput) ?>"></div>
        <div class="field">
          <label for="channel_id">Canal</label>
          <select id="channel_id" name="channel_id">
            <option value="">Todos los canales</option>
            <?php foreach ($channelOptions as $channel): ?>
              <?php
                $channelId = (int) ($channel['id'] ?? 0);
                $channelName = trim((string) ($channel['page_name'] ?? '')) ?: trim((string) ($channel['instagram_username'] ?? '')) ?: 'Canal #' . $channelId;
              ?>
              <option value="<?= $channelId ?>" <?= $filterChannelId === $channelId ? 'selected' : '' ?>><?= h($channelName) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <button class="pro-btn" type="submit">Actualizar campañas</button>
      </form>

      <div class="stats-layout">
        <aside class="stats-side-nav" aria-label="Secciones de campañas">
          <span>Campañas</span>
          <a class="is-active" href="#resumen">Resumen</a>
          <a href="#campanas">Campañas</a>
          <a href="#conjuntos">Conjuntos</a>
          <a href="#anuncios">Anuncios</a>
          <a href="#agentes">Agentes</a>
        </aside>
        <div class="stats-content">
          <section id="resumen" class="metrics-grid stats-section" aria-label="Resumen de campañas">
            <article class="metric-card" style="--tone:var(--pro-violet)"><span>Campañas detectadas</span><strong><?= number_format($totalCampaigns, 0, ',', '.') ?></strong><small>Agrupadas por ID cuando Meta lo entrega.</small></article>
            <article class="metric-card" style="--tone:var(--pro-cyan)"><span>Mensajes recibidos</span><strong><?= number_format($totalReceived, 0, ',', '.') ?></strong><small>Entrantes atribuidos en el periodo.</small></article>
            <article class="metric-card" style="--tone:var(--pro-blue)"><span>Chats atendidos</span><strong><?= h(camp_percent($totalAttended, $totalConversations)) ?></strong><small><?= number_format($totalAttended, 0, ',', '.') ?> de <?= number_format($totalConversations, 0, ',', '.') ?> conversaciones.</small></article>
            <article class="metric-card" style="--tone:var(--pro-green)"><span>Ventas concretadas</span><strong><?= number_format($totalWon, 0, ',', '.') ?></strong><small>Leads en Cliente ganado.</small></article>
          </section>

          <section id="campanas" class="content-card stats-section" style="padding:22px">
            <div class="section-head">
              <div><h2>Rendimiento por campaña</h2><p>Incluye mensajes, atención, cierres, status comercial y agentes que respondieron.</p></div>
              <span class="pill">Resp. prom: <?= h(camp_format_duration($avgResponse)) ?></span>
            </div>
            <?php if (!$campaignGroups): ?>
              <div class="empty">Todavía no hay conversaciones con datos de campaña, conjunto o anuncio en este periodo.</div>
            <?php else: ?>
              <div class="campaign-grid">
                <?php foreach ($campaignGroups as $group): ?>
                  <?php
                    $closed = (int) $group['won'] + (int) $group['lost'] + (int) $group['not_qualified'];
                    $winRate = $closed > 0 ? camp_percent((int) $group['won'], $closed) : '0,0%';
                    $avg = $group['response_seconds'] ? (int) round(array_sum($group['response_seconds']) / count($group['response_seconds'])) : null;
                    $width = round(((int) $group['conversations'] / $maxCampaignConversations) * 100, 2);
                    arsort($group['statuses']);
                  ?>
                  <article class="campaign-card">
                    <div class="campaign-top">
                      <div><h3><?= h((string) $group['label']) ?></h3><p><?= count($group['children']) ?> conjuntos · <?= camp_agent_summary($group['agents']) ?></p></div>
                      <span class="pill"><?= (int) $group['conversations'] ?> chats</span>
                    </div>
                    <div class="track"><span class="fill" style="--w:<?= h((string) $width) ?>%;--tone:var(--pro-violet)"></span></div>
                    <div class="mini-metrics">
                      <div class="mini-metric"><span>Recibidos</span><strong><?= (int) $group['received'] ?></strong></div>
                      <div class="mini-metric"><span>Atendidos</span><strong><?= h(camp_percent((int) $group['attended_conversations'], (int) $group['conversations'])) ?></strong></div>
                      <div class="mini-metric"><span>Ventas</span><strong><?= (int) $group['won'] ?> · <?= h($winRate) ?></strong></div>
                      <div class="mini-metric"><span>Resp. prom.</span><strong><?= h(camp_format_duration($avg)) ?></strong></div>
                      <div class="mini-metric"><span>No responde</span><strong><?= (int) $group['no_response'] ?></strong></div>
                      <div class="mini-metric"><span>No califica</span><strong><?= (int) $group['not_qualified'] ?></strong></div>
                    </div>
                    <div class="status-row">
                      <?php foreach (array_slice($group['statuses'], 0, 5, true) as $status => $total): ?>
                        <span class="status-chip"><?= h(camp_status_label((string) $status)) ?>: <?= (int) $total ?></span>
                      <?php endforeach; ?>
                    </div>
                  </article>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </section>

          <section id="conjuntos" class="content-card stats-section" style="padding:22px">
            <div class="section-head"><div><h2>Conjuntos de anuncio</h2><p>Lectura intermedia para comparar públicos, segmentaciones y presupuestos.</p></div></div>
            <div class="table-scroll">
              <table class="data-table">
                <thead><tr><th>Conjunto</th><th>Campaña</th><th>Mensajes</th><th>Atención</th><th>Status principal</th><th>Resp. prom.</th><th>Agentes</th></tr></thead>
                <tbody>
                  <?php foreach ($adsetGroups as $group): ?>
                    <?php $parent = $campaignGroups[$group['parent_key']] ?? null; arsort($group['statuses']); $status = array_key_first($group['statuses']); $avg = $group['response_seconds'] ? (int) round(array_sum($group['response_seconds']) / count($group['response_seconds'])) : null; ?>
                    <tr>
                      <td><?= h((string) $group['label']) ?><small><?= (int) $group['conversations'] ?> conversaciones</small></td>
                      <td><?= h((string) ($parent['label'] ?? 'Campaña no disponible')) ?></td>
                      <td><?= (int) $group['received'] ?> recibidos<small><?= (int) $group['outbound'] ?> enviados</small></td>
                      <td><?= h(camp_percent((int) $group['attended_conversations'], (int) $group['conversations'])) ?></td>
                      <td><?= h(camp_status_label((string) $status)) ?><small><?= (int) ($group['statuses'][$status] ?? 0) ?> leads</small></td>
                      <td><?= h(camp_format_duration($avg)) ?></td>
                      <td><?= h(camp_agent_summary($group['agents'])) ?></td>
                    </tr>
                  <?php endforeach; ?>
                  <?php if (!$adsetGroups): ?><tr><td colspan="7">Sin conjuntos atribuidos en este periodo.</td></tr><?php endif; ?>
                </tbody>
              </table>
            </div>
          </section>

          <section id="anuncios" class="content-card stats-section" style="padding:22px">
            <div class="section-head"><div><h2>Anuncios</h2><p>Ranking de piezas creativas según conversación, atención y ventas dentro del CRM.</p></div></div>
            <div class="table-scroll">
              <table class="data-table">
                <thead><tr><th>Anuncio</th><th>Conjunto</th><th>Mensajes recibidos</th><th>Atendidos</th><th>Ventas</th><th>Resp. prom.</th><th>Agentes</th></tr></thead>
                <tbody>
                  <?php foreach ($adGroups as $group): ?>
                    <?php $parent = $adsetGroups[$group['parent_key']] ?? null; $avg = $group['response_seconds'] ? (int) round(array_sum($group['response_seconds']) / count($group['response_seconds'])) : null; ?>
                    <tr>
                      <td><?= h((string) $group['label']) ?><small><?= (int) $group['conversations'] ?> conversaciones</small></td>
                      <td><?= h((string) ($parent['label'] ?? 'Sin conjunto')) ?></td>
                      <td><?= (int) $group['received'] ?></td>
                      <td><?= h(camp_percent((int) $group['attended_conversations'], (int) $group['conversations'])) ?></td>
                      <td><?= (int) $group['won'] ?></td>
                      <td><?= h(camp_format_duration($avg)) ?></td>
                      <td><?= h(camp_agent_summary($group['agents'])) ?></td>
                    </tr>
                  <?php endforeach; ?>
                  <?php if (!$adGroups): ?><tr><td colspan="7">Sin anuncios atribuidos en este periodo.</td></tr><?php endif; ?>
                </tbody>
              </table>
            </div>
          </section>

          <section id="agentes" class="content-card stats-section" style="padding:22px">
            <div class="section-head"><div><h2>Mensajes por agente</h2><p>Operadores que respondieron conversaciones atribuidas a campañas en este periodo.</p></div></div>
            <?php
              $globalAgents = [];
              foreach ($campaignGroups as $group) {
                foreach ($group['agents'] as $agentId => $agent) {
                  $globalAgents[$agentId] ??= ['name' => $agent['name'], 'messages' => 0, 'campaigns' => 0];
                  $globalAgents[$agentId]['messages'] += (int) $agent['messages'];
                  $globalAgents[$agentId]['campaigns']++;
                }
              }
              uasort($globalAgents, static fn($a, $b) => ((int) $b['messages'] <=> (int) $a['messages']));
            ?>
            <div class="table-scroll">
              <table class="data-table">
                <thead><tr><th>Agente</th><th>Mensajes enviados</th><th>Campañas tocadas</th><th>Lectura</th></tr></thead>
                <tbody>
                  <?php foreach ($globalAgents as $agent): ?>
                    <tr><td><?= h((string) $agent['name']) ?></td><td><?= (int) $agent['messages'] ?></td><td><?= (int) $agent['campaigns'] ?></td><td>Participación en conversaciones atribuidas a campañas.</td></tr>
                  <?php endforeach; ?>
                  <?php if (!$globalAgents): ?><tr><td colspan="4">Sin mensajes enviados por agentes en campañas atribuidas.</td></tr><?php endif; ?>
                </tbody>
              </table>
            </div>
          </section>
        </div>
      </div>
    </section>
  </main>
  <script>
    document.querySelectorAll('[data-menu]').forEach((menu) => {
      const trigger = menu.querySelector('[data-menu-trigger]');
      if (!trigger) return;
      trigger.addEventListener('click', (event) => {
        event.stopPropagation();
        document.querySelectorAll('[data-menu].is-open').forEach((openMenu) => {
          if (openMenu !== menu) openMenu.classList.remove('is-open');
        });
        menu.classList.toggle('is-open');
        trigger.setAttribute('aria-expanded', menu.classList.contains('is-open') ? 'true' : 'false');
      });
    });
    document.addEventListener('click', () => {
      document.querySelectorAll('[data-menu].is-open').forEach((menu) => {
        menu.classList.remove('is-open');
        const trigger = menu.querySelector('[data-menu-trigger]');
        if (trigger) trigger.setAttribute('aria-expanded', 'false');
      });
    });
  </script>
</body>
</html>
