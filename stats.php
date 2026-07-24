<?php
// stats.php
declare(strict_types=1);

require_once __DIR__ . '/auth/require_auth.php';
require_once __DIR__ . '/config/conversations.php';
require_once __DIR__ . '/config/lead_status_history.php';
require_once __DIR__ . '/config/navigation.php';

require_permission('view_reports');
conv_ensure_schema($pdo);
lead_status_history_ensure_schema($pdo);

$leadsTable = safe_identifier((string) ($TABLE_LEADS ?? app_config('database.leads_table', 'leads')), 'leads');
$conversationsTable = conv_conversations_table();
$messagesTable = conv_messages_table();
$channelsTable = ig_channels_table();
$usersTable = safe_identifier((string) ($TABLE_USERS ?? app_config('database.users_table', 'users')), 'users');
$historyTable = lead_status_history_table();

function adv_valid_date(string $value): bool {
  return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $value);
}

function adv_local_date_to_utc(string $date, bool $endOfDay = false): string {
  $time = $endOfDay ? '23:59:59' : '00:00:00';
  return (new DateTimeImmutable($date . ' ' . $time, app_timezone()))
    ->setTimezone(new DateTimeZone('UTC'))
    ->format('Y-m-d H:i:s');
}

function adv_fetch_all(PDO $pdo, string $sql, array $params = []): array {
  $stmt = $pdo->prepare($sql);
  foreach ($params as $key => $value) {
    $stmt->bindValue(is_int($key) ? $key + 1 : $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
  }
  $stmt->execute();
  return $stmt->fetchAll() ?: [];
}

function adv_fetch_value(PDO $pdo, string $sql, array $params = []) {
  $stmt = $pdo->prepare($sql);
  foreach ($params as $key => $value) {
    $stmt->bindValue(is_int($key) ? $key + 1 : $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
  }
  $stmt->execute();
  return $stmt->fetchColumn();
}

function adv_scope(string $dateExpression, string $fromUtc, string $toUtc, int $accountId, int $channelId): array {
  $clauses = [$dateExpression . ' BETWEEN :from_at AND :to_at'];
  $params = [':from_at' => $fromUtc, ':to_at' => $toUtc];
  if ($accountId > 0) {
    $clauses[] = 'c.account_id = :account_id';
    $params[':account_id'] = $accountId;
  }
  if ($channelId > 0) {
    $clauses[] = 'c.channel_id = :channel_id';
    $params[':channel_id'] = $channelId;
  }
  return ['sql' => 'WHERE ' . implode(' AND ', $clauses), 'params' => $params];
}

function adv_format_duration(?int $seconds): string {
  if ($seconds === null || $seconds < 0) return 'Sin datos';
  if ($seconds < 60) return $seconds . 's';
  $minutes = (int) floor($seconds / 60);
  if ($minutes < 60) return $minutes . ' min';
  $hours = (int) floor($minutes / 60);
  $remaining = $minutes % 60;
  return $remaining > 0 ? $hours . 'h ' . $remaining . 'm' : $hours . 'h';
}

function adv_format_percent(float $value): string {
  return number_format($value, 1, ',', '.') . '%';
}

function adv_status_label(string $status): string {
  return (string) (app_config('sales_funnel.statuses.' . $status, $status) ?: $status);
}

function adv_business_seconds_between(DateTimeImmutable $startUtc, DateTimeImmutable $endUtc): int {
  if ($endUtc <= $startUtc) return 0;
  $timezoneName = (string) app_config('business_hours.timezone', app_config('system.timezone', 'America/Caracas'));
  try {
    $timezone = new DateTimeZone($timezoneName);
  } catch (Throwable $e) {
    $timezone = app_timezone();
  }
  $workdays = array_map('intval', (array) app_config('business_hours.workdays', [1, 2, 3, 4, 5]));
  $startTime = (string) app_config('business_hours.start', '09:00');
  $endTime = (string) app_config('business_hours.end', '18:00');
  if (!preg_match('/^\d{2}:\d{2}$/', $startTime)) $startTime = '09:00';
  if (!preg_match('/^\d{2}:\d{2}$/', $endTime)) $endTime = '18:00';

  $start = $startUtc->setTimezone($timezone);
  $end = $endUtc->setTimezone($timezone);
  $day = $start->setTime(0, 0, 0);
  $lastDay = $end->setTime(0, 0, 0);
  $seconds = 0;
  while ($day <= $lastDay) {
    if (in_array((int) $day->format('N'), $workdays, true)) {
      $workStart = new DateTimeImmutable($day->format('Y-m-d') . ' ' . $startTime . ':00', $timezone);
      $workEnd = new DateTimeImmutable($day->format('Y-m-d') . ' ' . $endTime . ':00', $timezone);
      if ($workEnd > $workStart) {
        $from = max($start->getTimestamp(), $workStart->getTimestamp());
        $to = min($end->getTimestamp(), $workEnd->getTimestamp());
        if ($to > $from) $seconds += $to - $from;
      }
    }
    $day = $day->modify('+1 day');
  }
  return $seconds;
}

function adv_delta(float $current, float $previous, bool $lowerIsBetter = false): array {
  if ($previous <= 0 && $current <= 0) return ['value' => 0.0, 'label' => 'sin variación', 'tone' => 'flat'];
  if ($previous <= 0) return ['value' => 100.0, 'label' => '+100%', 'tone' => $lowerIsBetter ? 'bad' : 'good'];
  $delta = (($current - $previous) / $previous) * 100;
  $good = $lowerIsBetter ? $delta < 0 : $delta >= 0;
  $prefix = $delta > 0 ? '+' : '';
  return [
    'value' => $delta,
    'label' => $prefix . number_format($delta, 1, ',', '.') . '%',
    'tone' => abs($delta) < .01 ? 'flat' : ($good ? 'good' : 'bad'),
  ];
}

function adv_first_response_rows(PDO $pdo, string $conversationsTable, string $messagesTable, string $fromUtc, string $toUtc, int $accountId, int $channelId): array {
  $messageScope = adv_scope('mi.sent_at', $fromUtc, $toUtc, $accountId, $channelId);
  $sql = <<<SQL
SELECT firsts.conversation_id, firsts.first_inbound_at, mo.sent_at AS first_outbound_at, mo.sent_by
FROM (
  SELECT c.id AS conversation_id, MIN(mi.sent_at) AS first_inbound_at
  FROM {$conversationsTable} c
  INNER JOIN {$messagesTable} mi ON mi.conversation_id = c.id AND mi.direction = 'inbound'
  {$messageScope['sql']}
  GROUP BY c.id
) firsts
INNER JOIN {$messagesTable} mo ON mo.conversation_id = firsts.conversation_id
  AND mo.direction = 'outbound'
  AND mo.sent_at >= firsts.first_inbound_at
LEFT JOIN {$messagesTable} earlier ON earlier.conversation_id = firsts.conversation_id
  AND earlier.direction = 'outbound'
  AND earlier.sent_at >= firsts.first_inbound_at
  AND earlier.sent_at < mo.sent_at
WHERE earlier.id IS NULL
SQL;
  return adv_fetch_all($pdo, $sql, $messageScope['params']);
}

function adv_snapshot(PDO $pdo, string $conversationsTable, string $messagesTable, string $leadsTable, string $fromUtc, string $toUtc, int $accountId, int $channelId): array {
  $convScope = adv_scope('COALESCE(c.last_message_at, c.created_at)', $fromUtc, $toUtc, $accountId, $channelId);
  $msgScope = adv_scope('m.sent_at', $fromUtc, $toUtc, $accountId, $channelId);

  $totalConversations = (int) adv_fetch_value($pdo, "SELECT COUNT(*) FROM {$conversationsTable} c {$convScope['sql']}", $convScope['params']);
  $totalLeads = (int) adv_fetch_value($pdo, "SELECT COUNT(DISTINCT c.lead_id) FROM {$conversationsTable} c {$convScope['sql']} AND c.lead_id IS NOT NULL", $convScope['params']);
  $won = (int) adv_fetch_value($pdo, "SELECT COUNT(DISTINCT c.id) FROM {$conversationsTable} c LEFT JOIN {$leadsTable} l ON l.id = c.lead_id {$convScope['sql']} AND l.sales_status = 'cliente_ganado'", $convScope['params']);
  $lost = (int) adv_fetch_value($pdo, "SELECT COUNT(DISTINCT c.id) FROM {$conversationsTable} c LEFT JOIN {$leadsTable} l ON l.id = c.lead_id {$convScope['sql']} AND l.sales_status IN ('cliente_perdido','no_califica')", $convScope['params']);

  $messageCounts = adv_fetch_all($pdo, <<<SQL
SELECT
  SUM(CASE WHEN m.direction = 'inbound' THEN 1 ELSE 0 END) AS inbound_count,
  SUM(CASE WHEN m.direction = 'outbound' THEN 1 ELSE 0 END) AS outbound_count
FROM {$messagesTable} m
INNER JOIN {$conversationsTable} c ON c.id = m.conversation_id
{$msgScope['sql']}
SQL, $msgScope['params']);
  $messageCounts = $messageCounts[0] ?? [];

  $responseRows = adv_first_response_rows($pdo, $conversationsTable, $messagesTable, $fromUtc, $toUtc, $accountId, $channelId);
  $businessSeconds = [];
  foreach ($responseRows as $row) {
    $inboundAt = app_utc_datetime($row['first_inbound_at'] ?? '');
    $outboundAt = app_utc_datetime($row['first_outbound_at'] ?? '');
    if (!$inboundAt || !$outboundAt || $outboundAt < $inboundAt) continue;
    $businessSeconds[] = adv_business_seconds_between($inboundAt, $outboundAt);
  }

  $closed = $won + $lost;
  return [
    'conversations' => $totalConversations,
    'leads' => $totalLeads,
    'won' => $won,
    'lost' => $lost,
    'closed' => $closed,
    'win_rate' => $closed > 0 ? ($won / $closed) * 100 : 0.0,
    'inbound' => (int) ($messageCounts['inbound_count'] ?? 0),
    'outbound' => (int) ($messageCounts['outbound_count'] ?? 0),
    'avg_business_response' => $businessSeconds ? (int) round(array_sum($businessSeconds) / count($businessSeconds)) : null,
    'response_samples' => count($businessSeconds),
  ];
}

function adv_column_exists(PDO $pdo, string $dbName, string $table, string $column): bool {
  $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?");
  $stmt->execute([$dbName, $table, $column]);
  return (int) $stmt->fetchColumn() > 0;
}

function adv_index_exists(PDO $pdo, string $dbName, string $table, string $index): bool {
  $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?");
  $stmt->execute([$dbName, $table, $index]);
  return (int) $stmt->fetchColumn() > 0;
}

function adv_ensure_ad_attribution_schema(PDO $pdo, string $dbName, string $table): void {
  $columns = [
    'campaign_id' => "ALTER TABLE {$table} ADD COLUMN campaign_id VARCHAR(120) NULL AFTER utm_campaign",
    'campaign_name' => "ALTER TABLE {$table} ADD COLUMN campaign_name VARCHAR(180) NULL AFTER campaign_id",
    'adset_id' => "ALTER TABLE {$table} ADD COLUMN adset_id VARCHAR(120) NULL AFTER utm_term",
    'adset_name' => "ALTER TABLE {$table} ADD COLUMN adset_name VARCHAR(180) NULL AFTER adset_id",
    'ad_referral_source' => "ALTER TABLE {$table} ADD COLUMN ad_referral_source VARCHAR(80) NULL AFTER ad_id",
    'ad_referral_type' => "ALTER TABLE {$table} ADD COLUMN ad_referral_type VARCHAR(80) NULL AFTER ad_referral_source",
    'ad_referral_payload' => "ALTER TABLE {$table} ADD COLUMN ad_referral_payload TEXT NULL AFTER ad_referral_type",
    'ad_enrichment_error' => "ALTER TABLE {$table} ADD COLUMN ad_enrichment_error VARCHAR(255) NULL AFTER ad_referral_payload",
  ];
  foreach ($columns as $column => $sql) {
    if (!adv_column_exists($pdo, $dbName, $table, $column)) {
      try { $pdo->exec($sql); } catch (Throwable $e) { /* no-op */ }
    }
  }
  $indexes = [
    'idx_campaign_name' => "ALTER TABLE {$table} ADD KEY idx_campaign_name (campaign_name)",
    'idx_adset_name' => "ALTER TABLE {$table} ADD KEY idx_adset_name (adset_name)",
    'idx_ad_id' => "ALTER TABLE {$table} ADD KEY idx_ad_id (ad_id)",
  ];
  foreach ($indexes as $index => $sql) {
    if (!adv_index_exists($pdo, $dbName, $table, $index)) {
      try { $pdo->exec($sql); } catch (Throwable $e) { /* no-op */ }
    }
  }
}

adv_ensure_ad_attribution_schema($pdo, (string) ($DB_NAME ?? ''), $leadsTable);

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
  $filterAccountId = $requestAccount ? (int) ($requestAccount['id'] ?? 0) : max(0, (int) ($_GET['account_id'] ?? 0));
}

$today = new DateTimeImmutable('today', app_timezone());
$fromInput = trim((string) ($_GET['from'] ?? $today->modify('-29 days')->format('Y-m-d')));
$toInput = trim((string) ($_GET['to'] ?? $today->format('Y-m-d')));
if (!adv_valid_date($fromInput)) $fromInput = $today->modify('-29 days')->format('Y-m-d');
if (!adv_valid_date($toInput)) $toInput = $today->format('Y-m-d');
if ($fromInput > $toInput) [$fromInput, $toInput] = [$toInput, $fromInput];

$fromLocal = new DateTimeImmutable($fromInput, app_timezone());
$toLocal = new DateTimeImmutable($toInput, app_timezone());
$periodDays = max(1, (int) $fromLocal->diff($toLocal)->days + 1);
$previousToLocal = $fromLocal->modify('-1 day');
$previousFromLocal = $previousToLocal->modify('-' . ($periodDays - 1) . ' days');

$fromUtc = adv_local_date_to_utc($fromInput);
$toUtc = adv_local_date_to_utc($toInput, true);
$previousFromUtc = adv_local_date_to_utc($previousFromLocal->format('Y-m-d'));
$previousToUtc = adv_local_date_to_utc($previousToLocal->format('Y-m-d'), true);
$filterChannelId = max(0, (int) ($_GET['channel_id'] ?? 0));

$channelWhere = [];
$channelParams = [];
if ($filterAccountId > 0) {
  $channelWhere[] = 'account_id = :account_id';
  $channelParams[':account_id'] = $filterAccountId;
}
$channelWhereSql = $channelWhere ? 'WHERE ' . implode(' AND ', $channelWhere) : '';
$channelOptions = adv_fetch_all($pdo, "SELECT id, page_name, instagram_username, connection_type FROM {$channelsTable} {$channelWhereSql} ORDER BY page_name ASC, instagram_username ASC", $channelParams);

$current = adv_snapshot($pdo, $conversationsTable, $messagesTable, $leadsTable, $fromUtc, $toUtc, $filterAccountId, $filterChannelId);
$previous = adv_snapshot($pdo, $conversationsTable, $messagesTable, $leadsTable, $previousFromUtc, $previousToUtc, $filterAccountId, $filterChannelId);

$convScope = adv_scope('COALESCE(c.last_message_at, c.created_at)', $fromUtc, $toUtc, $filterAccountId, $filterChannelId);
$msgScope = adv_scope('m.sent_at', $fromUtc, $toUtc, $filterAccountId, $filterChannelId);

$statusRows = adv_fetch_all($pdo, <<<SQL
SELECT COALESCE(l.sales_status, :fallback_status) AS status_key, COUNT(DISTINCT c.id) AS total
FROM {$conversationsTable} c
LEFT JOIN {$leadsTable} l ON l.id = c.lead_id
{$convScope['sql']}
GROUP BY COALESCE(l.sales_status, :fallback_status_group)
SQL, array_merge($convScope['params'], [
  ':fallback_status' => (string) app_config('sales_funnel.default_status', 'nuevo_lead'),
  ':fallback_status_group' => (string) app_config('sales_funnel.default_status', 'nuevo_lead'),
]));
$salesStatuses = (array) app_config('sales_funnel.statuses', []);
$statusTotals = array_fill_keys(array_keys($salesStatuses), 0);
foreach ($statusRows as $row) {
  $key = (string) ($row['status_key'] ?? '');
  if ($key !== '') $statusTotals[$key] = (int) ($row['total'] ?? 0);
}
$maxStatusTotal = max(1, ...array_values($statusTotals));

$messageRows = adv_fetch_all($pdo, <<<SQL
SELECT m.direction, m.sent_at
FROM {$messagesTable} m
INNER JOIN {$conversationsTable} c ON c.id = m.conversation_id
{$msgScope['sql']}
ORDER BY m.sent_at ASC
SQL, $msgScope['params']);

$dailyBuckets = [];
$period = new DatePeriod(new DateTimeImmutable($fromInput, app_timezone()), new DateInterval('P1D'), (new DateTimeImmutable($toInput, app_timezone()))->modify('+1 day'));
foreach ($period as $day) {
  $dailyBuckets[$day->format('Y-m-d')] = ['label' => $day->format('d/m'), 'inbound' => 0, 'outbound' => 0];
}
$heatmap = [];
$dayNames = [1 => 'Lun', 2 => 'Mar', 3 => 'Mié', 4 => 'Jue', 5 => 'Vie', 6 => 'Sáb', 7 => 'Dom'];
for ($day = 1; $day <= 7; $day++) {
  $heatmap[$day] = array_fill(0, 24, 0);
}
foreach ($messageRows as $row) {
  $date = app_utc_datetime($row['sent_at'] ?? '');
  if (!$date) continue;
  $local = $date->setTimezone(app_timezone());
  $dayKey = $local->format('Y-m-d');
  if (isset($dailyBuckets[$dayKey])) {
    $direction = (string) ($row['direction'] ?? '');
    if ($direction === 'inbound') $dailyBuckets[$dayKey]['inbound']++;
    if ($direction === 'outbound') $dailyBuckets[$dayKey]['outbound']++;
  }
  if ((string) ($row['direction'] ?? '') === 'inbound') {
    $heatmap[(int) $local->format('N')][(int) $local->format('G')]++;
  }
}
$maxDaily = 1;
foreach ($dailyBuckets as $bucket) $maxDaily = max($maxDaily, (int) $bucket['inbound'] + (int) $bucket['outbound']);
$maxHeat = 1;
$peakDay = 'Sin datos';
$peakHour = '—';
$peakCount = 0;
foreach ($heatmap as $day => $hours) {
  foreach ($hours as $hour => $count) {
    $maxHeat = max($maxHeat, $count);
    if ($count > $peakCount) {
      $peakCount = $count;
      $peakDay = $dayNames[$day] ?? 'Día';
      $peakHour = str_pad((string) $hour, 2, '0', STR_PAD_LEFT) . ':00';
    }
  }
}

$currentChannelRows = adv_fetch_all($pdo, <<<SQL
SELECT
  c.channel_id,
  COALESCE(ch.page_name, ch.instagram_username, c.external_source, 'Sin canal') AS channel_label,
  COALESCE(c.external_source, ch.connection_type, 'desconocido') AS source_type,
  COUNT(DISTINCT c.id) AS conversations_count
FROM {$conversationsTable} c
LEFT JOIN {$channelsTable} ch ON ch.id = c.channel_id
{$convScope['sql']}
GROUP BY c.channel_id, channel_label, source_type
ORDER BY conversations_count DESC
LIMIT 10
SQL, $convScope['params']);

$previousScope = adv_scope('COALESCE(c.last_message_at, c.created_at)', $previousFromUtc, $previousToUtc, $filterAccountId, $filterChannelId);
$previousChannelRows = adv_fetch_all($pdo, <<<SQL
SELECT c.channel_id, COUNT(DISTINCT c.id) AS conversations_count
FROM {$conversationsTable} c
{$previousScope['sql']}
GROUP BY c.channel_id
SQL, $previousScope['params']);
$previousChannelMap = [];
foreach ($previousChannelRows as $row) $previousChannelMap[(int) ($row['channel_id'] ?? 0)] = (int) ($row['conversations_count'] ?? 0);
$maxChannel = max(1, ...array_map(static fn($row) => (int) ($row['conversations_count'] ?? 0), $currentChannelRows ?: [['conversations_count' => 1]]));

$campaignRows = adv_fetch_all($pdo, <<<SQL
SELECT
  COALESCE(NULLIF(l.campaign_name, ''), NULLIF(l.utm_campaign, ''), 'Sin campaña') AS campaign_label,
  COALESCE(NULLIF(l.adset_name, ''), 'Sin conjunto') AS adset_label,
  COALESCE(NULLIF(l.ad_name, ''), NULLIF(l.ad_id, ''), 'Sin anuncio') AS ad_label,
  COUNT(DISTINCT c.id) AS conversations_count,
  SUM(CASE WHEN l.sales_status = 'cliente_ganado' THEN 1 ELSE 0 END) AS won_count,
  SUM(CASE WHEN l.sales_status IN ('cliente_perdido', 'no_califica') THEN 1 ELSE 0 END) AS lost_count
FROM {$conversationsTable} c
LEFT JOIN {$leadsTable} l ON l.id = c.lead_id
{$convScope['sql']}
  AND (
    NULLIF(l.campaign_name, '') IS NOT NULL
    OR NULLIF(l.utm_campaign, '') IS NOT NULL
    OR NULLIF(l.adset_name, '') IS NOT NULL
    OR NULLIF(l.ad_name, '') IS NOT NULL
    OR NULLIF(l.ad_id, '') IS NOT NULL
  )
GROUP BY campaign_label, adset_label, ad_label
ORDER BY conversations_count DESC, won_count DESC
LIMIT 10
SQL, $convScope['params']);
$maxCampaign = max(1, ...array_map(static fn($row) => (int) ($row['conversations_count'] ?? 0), $campaignRows ?: [['conversations_count' => 1]]));

$firstResponseRows = adv_first_response_rows($pdo, $conversationsTable, $messagesTable, $fromUtc, $toUtc, $filterAccountId, $filterChannelId);
$operatorResponse = [];
foreach ($firstResponseRows as $row) {
  $operatorId = (int) ($row['sent_by'] ?? 0);
  $inboundAt = app_utc_datetime($row['first_inbound_at'] ?? '');
  $outboundAt = app_utc_datetime($row['first_outbound_at'] ?? '');
  if (!$inboundAt || !$outboundAt || $outboundAt < $inboundAt) continue;
  $operatorResponse[$operatorId] ??= ['business' => [], 'real' => []];
  $operatorResponse[$operatorId]['business'][] = adv_business_seconds_between($inboundAt, $outboundAt);
  $operatorResponse[$operatorId]['real'][] = $outboundAt->getTimestamp() - $inboundAt->getTimestamp();
}

$agentScope = $msgScope;
$agentRows = adv_fetch_all($pdo, <<<SQL
SELECT COALESCE(m.sent_by, 0) AS operator_id,
  COUNT(*) AS outbound_count,
  COUNT(DISTINCT m.conversation_id) AS conversations_count,
  MAX(m.sent_at) AS last_activity_at
FROM {$messagesTable} m
INNER JOIN {$conversationsTable} c ON c.id = m.conversation_id
{$agentScope['sql']} AND m.direction = 'outbound'
GROUP BY COALESCE(m.sent_by, 0)
SQL, $agentScope['params']);
$agentMap = [];
foreach ($agentRows as $row) {
  $operatorId = (int) ($row['operator_id'] ?? 0);
  $agentMap[$operatorId] = [
    'outbound' => (int) ($row['outbound_count'] ?? 0),
    'conversations' => (int) ($row['conversations_count'] ?? 0),
    'last_activity_at' => (string) ($row['last_activity_at'] ?? ''),
  ];
}

$operatorWhere = ["role <> 'super_admin'"];
$operatorParams = [];
if ($filterAccountId > 0) {
  $operatorWhere[] = 'account_id = :operator_account_id';
  $operatorParams[':operator_account_id'] = $filterAccountId;
}
$operatorRows = adv_fetch_all($pdo, "SELECT id, username, role, account_id FROM {$usersTable} WHERE " . implode(' AND ', $operatorWhere) . " ORDER BY username ASC", $operatorParams);
$operators = [];
foreach ($operatorRows as $operator) {
  $operatorId = (int) ($operator['id'] ?? 0);
  $stats = $agentMap[$operatorId] ?? ['outbound' => 0, 'conversations' => 0, 'last_activity_at' => ''];
  $response = $operatorResponse[$operatorId] ?? ['business' => [], 'real' => []];
  $businessAvg = $response['business'] ? (int) round(array_sum($response['business']) / count($response['business'])) : null;
  $realAvg = $response['real'] ? (int) round(array_sum($response['real']) / count($response['real'])) : null;
  $operators[] = [
    'id' => $operatorId,
    'username' => (string) ($operator['username'] ?? 'Operador'),
    'role' => role_label((string) ($operator['role'] ?? 'vendedor')),
    'outbound' => $stats['outbound'],
    'conversations' => $stats['conversations'],
    'last_activity_at' => $stats['last_activity_at'],
    'avg_business_response' => $businessAvg,
    'avg_real_response' => $realAvg,
    'response_samples' => count($response['business'] ?? []),
  ];
}
usort($operators, static fn($a, $b) => ($b['outbound'] <=> $a['outbound']) ?: strcmp($a['username'], $b['username']));
$maxOperatorOutbound = max(1, ...array_map(static fn($row) => (int) ($row['outbound'] ?? 0), $operators ?: [['outbound' => 1]]));

$historyClauses = ['h.created_at BETWEEN :from_at AND :to_at'];
$historyParams = [':from_at' => $fromUtc, ':to_at' => $toUtc];
if ($filterAccountId > 0) {
  $historyClauses[] = 'h.account_id = :history_account_id';
  $historyParams[':history_account_id'] = $filterAccountId;
}
$historySql = 'WHERE ' . implode(' AND ', $historyClauses);
if ($filterChannelId > 0) {
  $historySql .= ' AND c.channel_id = :history_channel_id';
}
if ($filterChannelId > 0) $historyParams[':history_channel_id'] = $filterChannelId;
$transitionRows = adv_fetch_all($pdo, <<<SQL
SELECT h.new_status, COUNT(DISTINCT h.id) AS total
FROM {$historyTable} h
LEFT JOIN {$conversationsTable} c ON c.lead_id = h.lead_id
{$historySql}
GROUP BY h.new_status
ORDER BY total DESC
LIMIT 7
SQL, $historyParams);
$maxTransitions = max(1, ...array_map(static fn($row) => (int) ($row['total'] ?? 0), $transitionRows ?: [['total' => 1]]));

$avgResponseText = adv_format_duration($current['avg_business_response']);
$previousAvgResponse = $previous['avg_business_response'];
$responseDelta = adv_delta((float) ($current['avg_business_response'] ?? 0), (float) ($previousAvgResponse ?? 0), true);
$conversationDelta = adv_delta((float) $current['conversations'], (float) $previous['conversations']);
$inboundDelta = adv_delta((float) $current['inbound'], (float) $previous['inbound']);
$winRateDelta = adv_delta((float) $current['win_rate'], (float) $previous['win_rate']);
$selectedAccountParamsAll = ['from' => $fromInput, 'to' => $toInput, 'channel_id' => $filterChannelId > 0 ? $filterChannelId : null];
$selectedAccountParamsAccount = $selectedAccountParamsAll;
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Estadísticas - <?= h(app_config('brand.name', 'Pixels Studio')) ?></title>
  <link rel="stylesheet" href="css/app.css">
  <style>
    :root {
      --pro-bg:#f4f7fb;
      --pro-panel:#ffffff;
      --pro-ink:#09111f;
      --pro-muted:#69758d;
      --pro-line:#dbe7f4;
      --pro-dark:#050b18;
      --pro-cyan:#12c7e8;
      --pro-blue:#3667ff;
      --pro-violet:#7c3cff;
      --pro-green:#1fad72;
      --pro-amber:#f4a62a;
      --pro-red:#e6576d;
      --pro-shadow:0 22px 58px rgba(15,23,42,.08);
    }
    body.dashboard-page {
      min-height:100vh;
      margin:0;
      background:var(--pro-bg);
      color:var(--pro-ink);
      font-family:Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    }
    .pro-shell { width:100%; min-height:100vh; padding:clamp(18px,2.2vw,34px); box-sizing:border-box; }
    .pro-grid { display:grid; gap:18px; }
    .pro-header { display:flex; justify-content:space-between; align-items:flex-start; gap:20px; }
    .pro-actions { display:flex; align-items:center; justify-content:flex-end; gap:10px; flex-wrap:wrap; }
    .eyebrow { margin:0 0 8px; color:var(--pro-cyan); font-weight:950; font-size:.76rem; letter-spacing:.14em; text-transform:uppercase; }
    .title { margin:0; color:var(--pro-ink); font-size:clamp(2.4rem,4.8vw,5.7rem); line-height:.9; letter-spacing:-.055em; font-weight:950; }
    .subtitle { max-width:820px; margin:14px 0 0; color:var(--pro-muted); font-weight:750; font-size:1rem; }
    .menu-trigger,.nav-direct-button,.pro-btn {
      min-height:50px; padding:0 20px; border:1px solid var(--pro-line); border-radius:18px; background:#fff; color:var(--pro-ink);
      display:inline-flex; align-items:center; justify-content:center; gap:8px; font-weight:950; text-decoration:none; box-shadow:0 8px 20px rgba(9,17,31,.05);
      transition:background .18s ease,color .18s ease,border-color .18s ease,transform .18s ease;
    }
    .menu-trigger:hover,.nav-direct-button:hover,.pro-btn:hover { background:var(--pro-dark); color:#fff; border-color:var(--pro-dark); transform:translateY(-1px); }
    .menu-panel { border:1px solid var(--pro-line); border-radius:22px; box-shadow:var(--pro-shadow); }
    .filters-card,.metric-card,.chart-card,.operator-card {
      background:var(--pro-panel); border:1px solid var(--pro-line); border-radius:28px; box-shadow:var(--pro-shadow);
    }
    .filters-card { padding:16px; display:grid; grid-template-columns:minmax(150px,180px) minmax(150px,180px) minmax(220px,320px) auto; gap:12px; align-items:end; }
    .stats-layout { display:grid; grid-template-columns:minmax(210px,260px) minmax(0,1fr); gap:18px; align-items:start; }
    .stats-side-nav {
      position:sticky; top:18px; display:grid; gap:8px; padding:14px; background:var(--pro-panel);
      border:1px solid var(--pro-line); border-radius:26px; box-shadow:var(--pro-shadow);
    }
    .stats-side-nav span {
      color:var(--pro-muted); font-size:.72rem; font-weight:950; letter-spacing:.1em; text-transform:uppercase;
      padding:4px 8px 8px;
    }
    .stats-side-nav a {
      min-height:42px; display:flex; align-items:center; border-radius:14px; padding:0 12px;
      color:var(--pro-muted); text-decoration:none; font-weight:900;
      transition:background .16s ease,color .16s ease,transform .16s ease;
    }
    .stats-side-nav a:hover { background:var(--pro-dark); color:#fff; transform:translateX(2px); }
    .stats-content { min-width:0; display:grid; gap:18px; }
    .stats-section { scroll-margin-top:22px; }
    .field { display:grid; gap:7px; }
    .field label { color:var(--pro-muted); font-size:.72rem; font-weight:950; letter-spacing:.1em; text-transform:uppercase; }
    .field input,.field select {
      width:100%; min-height:48px; box-sizing:border-box; border:1px solid var(--pro-line); border-radius:16px; padding:0 14px;
      color:var(--pro-ink); background:#fff; font-weight:850; outline:none;
    }
    .field select { appearance:auto; }
    .benchmark-grid { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:14px; }
    .metric-card { padding:22px; min-height:150px; position:relative; overflow:hidden; }
    .metric-card::after { content:""; position:absolute; right:18px; top:18px; width:12px; height:12px; border-radius:50%; background:var(--tone,var(--pro-cyan)); box-shadow:0 0 0 8px color-mix(in srgb,var(--tone,var(--pro-cyan)) 14%,transparent); }
    .metric-card span { display:block; color:var(--pro-muted); font-size:.76rem; font-weight:950; letter-spacing:.09em; text-transform:uppercase; }
    .metric-card strong { display:block; margin-top:12px; color:var(--pro-ink); font-size:2.6rem; line-height:1; font-weight:950; letter-spacing:-.04em; }
    .delta { display:inline-flex; margin-top:14px; padding:7px 10px; border-radius:999px; font-weight:950; font-size:.82rem; }
    .delta.good { color:#087a4c; background:#e9fbf3; }
    .delta.bad { color:#b4233b; background:#fff0f3; }
    .delta.flat { color:#667085; background:#eef2f7; }
    .main-analytics { display:grid; grid-template-columns:1.2fr .8fr; gap:14px; }
    .chart-card { padding:22px; min-width:0; }
    .chart-head { display:flex; justify-content:space-between; align-items:flex-start; gap:16px; margin-bottom:18px; }
    .chart-head h2 { margin:0; color:var(--pro-ink); font-size:1.28rem; line-height:1.05; letter-spacing:-.02em; }
    .chart-head p { margin:6px 0 0; color:var(--pro-muted); font-weight:750; }
    .legend { display:flex; flex-wrap:wrap; gap:10px; color:var(--pro-muted); font-weight:850; font-size:.84rem; }
    .legend span::before { content:""; display:inline-block; width:10px; height:10px; margin-right:6px; border-radius:50%; background:var(--tone); }
    .daily-chart { display:grid; grid-template-columns:repeat(auto-fit,minmax(28px,1fr)); gap:8px; min-height:240px; align-items:end; }
    .day-col { display:grid; grid-template-rows:1fr auto; gap:8px; min-width:0; }
    .day-stack { height:190px; display:flex; flex-direction:column; justify-content:flex-end; gap:3px; }
    .seg { border-radius:9px 9px 0 0; transform-origin:bottom; animation:rise .72s ease both; }
    .seg.in { background:var(--pro-cyan); }
    .seg.out { background:var(--pro-violet); animation-delay:.08s; }
    .day-label { color:var(--pro-muted); font-size:.68rem; font-weight:850; text-align:center; white-space:nowrap; }
    @keyframes rise { from { transform:scaleY(0); opacity:.35; } to { transform:scaleY(1); opacity:1; } }
    .funnel-list,.channel-list,.transition-list { display:grid; gap:12px; }
    .bar-row { display:grid; grid-template-columns:minmax(130px,220px) 1fr auto; gap:12px; align-items:center; }
    .bar-name { font-weight:950; color:var(--pro-ink); }
    .bar-name small { display:block; margin-top:3px; color:var(--pro-muted); font-size:.76rem; font-weight:850; line-height:1.25; }
    .track { height:15px; overflow:hidden; border-radius:999px; background:#edf3f9; }
    .fill { display:block; height:100%; width:var(--w); border-radius:999px; background:var(--tone,var(--pro-cyan)); transform-origin:left; animation:growx .82s ease both; }
    @keyframes growx { from { transform:scaleX(0); } to { transform:scaleX(1); } }
    .bar-value { color:var(--pro-ink); font-weight:950; min-width:48px; text-align:right; }
    .donut-card { display:grid; place-items:center; min-height:300px; }
    .donut { --pct:0; width:190px; aspect-ratio:1; border-radius:50%; background:conic-gradient(var(--pro-green) calc(var(--pct) * 1%), #edf3f9 0); display:grid; place-items:center; box-shadow:inset 0 0 0 1px var(--pro-line); animation:spinIn .85s ease both; }
    .donut::before { content:""; width:126px; aspect-ratio:1; border-radius:50%; background:#fff; position:absolute; }
    .donut strong { position:relative; z-index:1; font-size:2.4rem; letter-spacing:-.05em; }
    @keyframes spinIn { from { filter:saturate(.4); transform:rotate(-18deg) scale(.92); } to { filter:saturate(1); transform:rotate(0) scale(1); } }
    .heatmap-wrap { overflow:auto; padding-bottom:4px; }
    .heatmap { min-width:980px; display:grid; grid-template-columns:56px repeat(24,1fr); gap:5px; }
    .heat-label,.hour-label { color:var(--pro-muted); font-size:.72rem; font-weight:900; display:flex; align-items:center; justify-content:center; }
    .heat-cell { height:34px; border-radius:10px; background:color-mix(in srgb,var(--pro-cyan) calc(var(--intensity) * 100%), #edf3f9); border:1px solid rgba(255,255,255,.62); animation:heat .55s ease both; animation-delay:calc(var(--intensity) * .16s); }
    @keyframes heat { from { opacity:.25; transform:scale(.92); } to { opacity:1; transform:scale(1); } }
    .peak-note { margin-top:14px; color:var(--pro-muted); font-weight:850; }
    .operator-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(280px,1fr)); gap:14px; }
    .operator-card { padding:18px; display:grid; gap:14px; }
    .operator-top { display:flex; justify-content:space-between; gap:12px; align-items:flex-start; }
    .operator-avatar { width:48px; height:48px; border-radius:16px; display:grid; place-items:center; background:var(--pro-dark); color:#fff; font-weight:950; }
    .operator-top h3 { margin:0; font-size:1rem; line-height:1.1; }
    .operator-top p { margin:5px 0 0; color:var(--pro-muted); font-weight:800; font-size:.88rem; }
    .operator-stats { display:grid; grid-template-columns:repeat(3,1fr); gap:10px; }
    .operator-stat { border:1px solid var(--pro-line); border-radius:16px; padding:12px; background:#f8fbff; }
    .operator-stat span { display:block; color:var(--pro-muted); font-weight:900; font-size:.72rem; text-transform:uppercase; letter-spacing:.06em; }
    .operator-stat strong { display:block; margin-top:6px; color:var(--pro-ink); font-size:1.18rem; }
    .operator-bar { height:12px; border-radius:999px; background:#edf3f9; overflow:hidden; }
    .operator-bar span { display:block; height:100%; width:var(--w); background:var(--pro-violet); border-radius:999px; animation:growx .82s ease both; }
    .compact-grid { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:14px; }
    .empty { padding:28px; border:1px dashed var(--pro-line); border-radius:20px; color:var(--pro-muted); text-align:center; font-weight:850; }
    @media (max-width:1180px) {
      .benchmark-grid,.compact-grid { grid-template-columns:repeat(2,minmax(0,1fr)); }
      .main-analytics { grid-template-columns:1fr; }
      .filters-card { grid-template-columns:repeat(2,minmax(0,1fr)); }
      .stats-layout { grid-template-columns:1fr; }
      .stats-side-nav { position:relative; top:auto; display:flex; overflow:auto; white-space:nowrap; }
      .stats-side-nav span { display:none; }
      .stats-side-nav a { flex:0 0 auto; }
    }
    @media (max-width:720px) {
      .pro-shell { padding:14px; }
      .pro-header { display:grid; }
      .pro-actions { justify-content:flex-start; }
      .benchmark-grid,.compact-grid,.filters-card,.operator-stats { grid-template-columns:1fr; }
      .title { font-size:2.45rem; }
      .bar-row { grid-template-columns:1fr auto; }
      .track { grid-column:1 / -1; grid-row:2; }
      .daily-chart { overflow-x:auto; grid-template-columns:repeat(<?= max(7, count($dailyBuckets)) ?>, 36px); }
    }
  </style>
</head>
<body class="dashboard-page">
  <main class="pro-shell">
    <section class="pro-grid">
      <header class="pro-header">
        <div>
          <p class="eyebrow"><?= h(app_config('brand.name', 'Pixels Studio')) ?></p>
          <h1 class="title">Estadísticas</h1>
          <p class="subtitle">Benchmark, operadores, tráfico por hora y salud comercial del embudo conversacional.</p>
        </div>
        <div class="pro-actions app-nav-actions">
          <?php nav_render_view_button('stats'); ?>
          <?php nav_render_account_switch($pdo, $accountOptions, $filterAccountId, 'stats.php', $selectedAccountParamsAll, $selectedAccountParamsAccount); ?>
          <?php nav_render_user_menu(true); ?>
        </div>
      </header>

      <form class="filters-card" method="get" action="<?= h(account_url('stats.php')) ?>">
        <?php if (is_super_admin() && $requestSlug === '' && $filterAccountId > 0): ?>
          <input type="hidden" name="account_id" value="<?= (int) $filterAccountId ?>">
        <?php endif; ?>
        <div class="field">
          <label for="from">Desde</label>
          <input id="from" type="date" name="from" value="<?= h($fromInput) ?>">
        </div>
        <div class="field">
          <label for="to">Hasta</label>
          <input id="to" type="date" name="to" value="<?= h($toInput) ?>">
        </div>
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
        <button class="pro-btn" type="submit">Actualizar benchmark</button>
      </form>

      <div class="stats-layout">
        <aside class="stats-side-nav" aria-label="Secciones de estadísticas">
          <span>Indicadores</span>
          <a href="#benchmark">Benchmark</a>
          <a href="#actividad">Actividad</a>
          <a href="#trafico">Tráfico por hora</a>
          <a href="#embudo">Embudo</a>
          <a href="#operadores">Operadores</a>
          <a href="#canales">Canales</a>
          <a href="#campanas">Campañas</a>
          <a href="#status">Status</a>
          <a href="#lectura">Lectura rápida</a>
        </aside>
        <div class="stats-content">

      <section id="benchmark" class="benchmark-grid stats-section" aria-label="Benchmark del periodo">
        <?php
          $metricCards = [
            ['label' => 'Conversaciones', 'value' => number_format((int) $current['conversations'], 0, ',', '.'), 'delta' => $conversationDelta, 'tone' => 'var(--pro-cyan)', 'sub' => 'vs periodo anterior'],
            ['label' => 'Mensajes entrantes', 'value' => number_format((int) $current['inbound'], 0, ',', '.'), 'delta' => $inboundDelta, 'tone' => 'var(--pro-blue)', 'sub' => 'demanda comercial'],
            ['label' => 'Tasa ganada', 'value' => adv_format_percent((float) $current['win_rate']), 'delta' => $winRateDelta, 'tone' => 'var(--pro-green)', 'sub' => 'cierres ganados'],
            ['label' => 'Primera respuesta', 'value' => $avgResponseText, 'delta' => $responseDelta, 'tone' => 'var(--pro-violet)', 'sub' => 'tiempo operativo'],
          ];
        ?>
        <?php foreach ($metricCards as $card): ?>
          <article class="metric-card" style="--tone:<?= h($card['tone']) ?>">
            <span><?= h($card['label']) ?></span>
            <strong><?= h($card['value']) ?></strong>
            <small><?= h($card['sub']) ?></small>
            <div class="delta <?= h($card['delta']['tone']) ?>"><?= h($card['delta']['label']) ?></div>
          </article>
        <?php endforeach; ?>
      </section>

      <section id="actividad" class="main-analytics stats-section">
        <article class="chart-card">
          <div class="chart-head">
            <div>
              <h2>Actividad de mensajes</h2>
              <p>Entrantes y salientes por día, con animación para detectar picos rápido.</p>
            </div>
            <div class="legend">
              <span style="--tone:var(--pro-cyan)">Entrantes</span>
              <span style="--tone:var(--pro-violet)">Salientes</span>
            </div>
          </div>
          <?php if (!$messageRows): ?>
            <div class="empty">No hay mensajes registrados en el periodo filtrado.</div>
          <?php else: ?>
            <div class="daily-chart">
              <?php foreach ($dailyBuckets as $bucket): ?>
                <?php
                  $inHeight = (int) $bucket['inbound'] > 0 ? max(4, ((int) $bucket['inbound'] / $maxDaily) * 190) : 0;
                  $outHeight = (int) $bucket['outbound'] > 0 ? max(4, ((int) $bucket['outbound'] / $maxDaily) * 190) : 0;
                ?>
                <div class="day-col" title="<?= h($bucket['label']) ?>: <?= (int) $bucket['inbound'] ?> entrantes, <?= (int) $bucket['outbound'] ?> salientes">
                  <div class="day-stack">
                    <span class="seg out" style="height:<?= h((string) $outHeight) ?>px"></span>
                    <span class="seg in" style="height:<?= h((string) $inHeight) ?>px"></span>
                  </div>
                  <span class="day-label"><?= h($bucket['label']) ?></span>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </article>

        <article class="chart-card donut-card">
          <div class="chart-head" style="width:100%">
            <div>
              <h2>Benchmark de cierre</h2>
              <p>Clientes ganados sobre cierres registrados.</p>
            </div>
          </div>
          <div class="donut" style="--pct:<?= h((string) min(100, max(0, $current['win_rate']))) ?>"><strong><?= h(adv_format_percent((float) $current['win_rate'])) ?></strong></div>
          <p class="peak-note"><?= (int) $current['won'] ?> ganados · <?= (int) $current['lost'] ?> perdidos/no califica</p>
        </article>
      </section>

      <section class="main-analytics">
        <article id="trafico" class="chart-card stats-section">
          <div class="chart-head">
            <div>
              <h2>Mapa de calor de tráfico</h2>
              <p>Mensajes recibidos por día de semana y hora local de Venezuela.</p>
            </div>
          </div>
          <div class="heatmap-wrap">
            <div class="heatmap" role="img" aria-label="Mapa de calor de tráfico por día y hora">
              <span></span>
              <?php for ($hour = 0; $hour < 24; $hour++): ?>
                <span class="hour-label"><?= str_pad((string) $hour, 2, '0', STR_PAD_LEFT) ?></span>
              <?php endfor; ?>
              <?php foreach ($dayNames as $day => $label): ?>
                <span class="heat-label"><?= h($label) ?></span>
                <?php for ($hour = 0; $hour < 24; $hour++): ?>
                  <?php $count = (int) ($heatmap[$day][$hour] ?? 0); $intensity = $maxHeat > 0 ? min(1, $count / $maxHeat) : 0; ?>
                  <span class="heat-cell" style="--intensity:<?= h((string) $intensity) ?>" title="<?= h($label) ?> <?= str_pad((string) $hour, 2, '0', STR_PAD_LEFT) ?>:00 · <?= $count ?> mensajes"></span>
                <?php endfor; ?>
              <?php endforeach; ?>
            </div>
          </div>
          <p class="peak-note">Mayor tráfico: <strong><?= h($peakDay) ?> <?= h($peakHour) ?></strong> con <?= (int) $peakCount ?> mensajes entrantes.</p>
        </article>

        <article id="embudo" class="chart-card stats-section">
          <div class="chart-head">
            <div>
              <h2>Embudo animado</h2>
              <p>Status comercial actual de los leads conversacionales.</p>
            </div>
          </div>
          <div class="funnel-list">
            <?php foreach ($statusTotals as $statusKey => $total): ?>
              <?php
                $toneMap = [
                  'nuevo_lead' => 'var(--pro-cyan)',
                  'en_conversacion' => 'var(--pro-blue)',
                  'propuesta_enviada' => 'var(--pro-amber)',
                  'no_responde' => 'var(--pro-red)',
                  'cliente_ganado' => 'var(--pro-green)',
                  'cliente_perdido' => 'var(--pro-violet)',
                  'no_califica' => '#6b7280',
                ];
                $width = round(((int) $total / $maxStatusTotal) * 100, 2);
              ?>
              <div class="bar-row">
                <span class="bar-name"><?= h(adv_status_label((string) $statusKey)) ?></span>
                <span class="track"><span class="fill" style="--w:<?= h((string) $width) ?>%;--tone:<?= h($toneMap[$statusKey] ?? 'var(--pro-cyan)') ?>"></span></span>
                <span class="bar-value"><?= (int) $total ?></span>
              </div>
            <?php endforeach; ?>
          </div>
        </article>
      </section>

      <section id="operadores" class="stats-section">
        <div class="chart-head">
          <div>
            <h2>Operadores</h2>
            <p>Lista de agentes con volumen, conversaciones tocadas y tiempo de respuesta.</p>
          </div>
        </div>
        <?php if (!$operators): ?>
          <div class="empty">No hay operadores para esta cuenta o filtro.</div>
        <?php else: ?>
          <div class="operator-grid">
            <?php foreach ($operators as $operator): ?>
              <?php
                $initials = mb_strtoupper(mb_substr($operator['username'], 0, 2));
                $width = round(((int) $operator['outbound'] / $maxOperatorOutbound) * 100, 2);
              ?>
              <article class="operator-card">
                <div class="operator-top">
                  <div style="display:flex;gap:12px;align-items:center">
                    <div class="operator-avatar"><?= h($initials) ?></div>
                    <div>
                      <h3><?= h($operator['username']) ?></h3>
                      <p><?= h($operator['role']) ?></p>
                    </div>
                  </div>
                  <span class="delta flat"><?= (int) $operator['response_samples'] ?> muestras</span>
                </div>
                <div class="operator-bar"><span style="--w:<?= h((string) $width) ?>%"></span></div>
                <div class="operator-stats">
                  <div class="operator-stat"><span>Mensajes</span><strong><?= (int) $operator['outbound'] ?></strong></div>
                  <div class="operator-stat"><span>Chats</span><strong><?= (int) $operator['conversations'] ?></strong></div>
                  <div class="operator-stat"><span>Resp. operativa</span><strong><?= h(adv_format_duration($operator['avg_business_response'])) ?></strong></div>
                </div>
              </article>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>

      <section class="compact-grid stats-section">
        <article id="canales" class="chart-card stats-section">
          <div class="chart-head"><div><h2>Canales con benchmark</h2><p>Comparado contra el periodo anterior.</p></div></div>
          <?php if (!$currentChannelRows): ?>
            <div class="empty">Sin conversaciones por canal.</div>
          <?php else: ?>
            <div class="channel-list">
              <?php foreach ($currentChannelRows as $row): ?>
                <?php
                  $channelId = (int) ($row['channel_id'] ?? 0);
                  $count = (int) ($row['conversations_count'] ?? 0);
                  $width = round(($count / $maxChannel) * 100, 2);
                  $delta = adv_delta((float) $count, (float) ($previousChannelMap[$channelId] ?? 0));
                ?>
                <div class="bar-row">
                  <span class="bar-name"><?= h((string) ($row['channel_label'] ?? 'Canal')) ?></span>
                  <span class="track"><span class="fill" style="--w:<?= h((string) $width) ?>%;--tone:var(--pro-cyan)"></span></span>
                  <span class="bar-value"><?= $count ?> <small class="delta <?= h($delta['tone']) ?>"><?= h($delta['label']) ?></small></span>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </article>

        <article id="campanas" class="chart-card stats-section">
          <div class="chart-head"><div><h2>Campañas y anuncios</h2><p>Conversaciones atribuidas a campañas de Meta en este periodo.</p></div></div>
          <?php if (!$campaignRows): ?>
            <div class="empty">Todavía no hay conversaciones con atribución de campaña.</div>
          <?php else: ?>
            <div class="channel-list">
              <?php foreach ($campaignRows as $row): ?>
                <?php
                  $count = (int) ($row['conversations_count'] ?? 0);
                  $won = (int) ($row['won_count'] ?? 0);
                  $lost = (int) ($row['lost_count'] ?? 0);
                  $closed = $won + $lost;
                  $winRate = $closed > 0 ? round(($won / $closed) * 100, 1) : 0;
                  $width = round(($count / $maxCampaign) * 100, 2);
                ?>
                <div class="bar-row" title="<?= h((string) ($row['ad_label'] ?? 'Sin anuncio')) ?>">
                  <span class="bar-name"><?= h((string) ($row['campaign_label'] ?? 'Sin campaña')) ?><small><?= h((string) ($row['adset_label'] ?? 'Sin conjunto')) ?></small></span>
                  <span class="track"><span class="fill" style="--w:<?= h((string) $width) ?>%;--tone:var(--pro-violet)"></span></span>
                  <span class="bar-value"><?= $count ?> <small><?= h(adv_format_percent($winRate)) ?></small></span>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </article>

        <article id="status" class="chart-card stats-section">
          <div class="chart-head"><div><h2>Movimientos de status</h2><p>Últimos cambios registrados en el periodo.</p></div></div>
          <?php if (!$transitionRows): ?>
            <div class="empty">Sin cambios de status.</div>
          <?php else: ?>
            <div class="transition-list">
              <?php foreach ($transitionRows as $row): ?>
                <?php $width = round(((int) ($row['total'] ?? 0) / $maxTransitions) * 100, 2); ?>
                <div class="bar-row">
                  <span class="bar-name"><?= h(adv_status_label((string) ($row['new_status'] ?? ''))) ?></span>
                  <span class="track"><span class="fill" style="--w:<?= h((string) $width) ?>%;--tone:var(--pro-green)"></span></span>
                  <span class="bar-value"><?= (int) ($row['total'] ?? 0) ?></span>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </article>

        <article id="lectura" class="chart-card stats-section">
          <div class="chart-head"><div><h2>Lectura rápida</h2><p>Señales para decidir dónde mirar primero.</p></div></div>
          <div class="operator-stats">
            <div class="operator-stat"><span>Periodo actual</span><strong><?= h($fromInput) ?> → <?= h($toInput) ?></strong></div>
            <div class="operator-stat"><span>Benchmark</span><strong><?= h($previousFromLocal->format('Y-m-d')) ?> → <?= h($previousToLocal->format('Y-m-d')) ?></strong></div>
            <div class="operator-stat"><span>Horario operativo</span><strong><?= h(app_config('business_hours.start', '09:00')) ?> - <?= h(app_config('business_hours.end', '18:00')) ?></strong></div>
          </div>
        </article>
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
