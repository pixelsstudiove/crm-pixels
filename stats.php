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
$contactsTable = conv_contacts_table();
$conversationsTable = conv_conversations_table();
$messagesTable = conv_messages_table();
$channelsTable = ig_channels_table();
$usersTable = safe_identifier((string) ($TABLE_USERS ?? app_config('database.users_table', 'users')), 'users');
$historyTable = lead_status_history_table();

function stats_valid_date(string $value): bool {
  return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $value);
}

function stats_local_date_to_utc(string $date, bool $endOfDay = false): string {
  $time = $endOfDay ? '23:59:59' : '00:00:00';
  return (new DateTimeImmutable($date . ' ' . $time, app_timezone()))
    ->setTimezone(new DateTimeZone('UTC'))
    ->format('Y-m-d H:i:s');
}

function stats_fetch_all(PDO $pdo, string $sql, array $params = []): array {
  $stmt = $pdo->prepare($sql);
  foreach ($params as $key => $value) {
    $stmt->bindValue(is_int($key) ? $key + 1 : $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
  }
  $stmt->execute();
  return $stmt->fetchAll() ?: [];
}

function stats_fetch_value(PDO $pdo, string $sql, array $params = []) {
  $stmt = $pdo->prepare($sql);
  foreach ($params as $key => $value) {
    $stmt->bindValue(is_int($key) ? $key + 1 : $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
  }
  $stmt->execute();
  return $stmt->fetchColumn();
}

function stats_format_duration(?int $seconds): string {
  if ($seconds === null || $seconds < 0) return 'Sin datos';
  if ($seconds < 60) return $seconds . 's';
  $minutes = (int) floor($seconds / 60);
  if ($minutes < 60) return $minutes . ' min';
  $hours = (int) floor($minutes / 60);
  $remainingMinutes = $minutes % 60;
  return $remainingMinutes > 0 ? $hours . 'h ' . $remainingMinutes . 'm' : $hours . 'h';
}

function stats_format_percent(float $value): string {
  return number_format($value, 1, ',', '.') . '%';
}

function stats_status_label(string $status): string {
  return (string) (app_config('sales_funnel.statuses.' . $status, $status) ?: $status);
}

function stats_business_seconds_between(DateTimeImmutable $startUtc, DateTimeImmutable $endUtc): int {
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
        if ($to > $from) $seconds += ($to - $from);
      }
    }
    $day = $day->modify('+1 day');
  }

  return $seconds;
}

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
if (!stats_valid_date($fromInput)) $fromInput = $today->modify('-29 days')->format('Y-m-d');
if (!stats_valid_date($toInput)) $toInput = $today->format('Y-m-d');
if ($fromInput > $toInput) [$fromInput, $toInput] = [$toInput, $fromInput];

$fromUtc = stats_local_date_to_utc($fromInput);
$toUtc = stats_local_date_to_utc($toInput, true);
$filterChannelId = max(0, (int) ($_GET['channel_id'] ?? 0));

$channelWhere = [];
$channelParams = [];
if ($filterAccountId > 0) {
  $channelWhere[] = 'account_id = :channel_account_id';
  $channelParams[':channel_account_id'] = $filterAccountId;
}
$channelWhereSql = $channelWhere ? 'WHERE ' . implode(' AND ', $channelWhere) : '';
$channelOptions = stats_fetch_all($pdo, "SELECT id, page_name, instagram_username, connection_type, receive_instagram, receive_messenger FROM {$channelsTable} {$channelWhereSql} ORDER BY page_name ASC, instagram_username ASC", $channelParams);

$scope = ['COALESCE(c.last_message_at, c.created_at) BETWEEN :from_at AND :to_at'];
$scopeParams = [':from_at' => $fromUtc, ':to_at' => $toUtc];
if ($filterAccountId > 0) {
  $scope[] = 'c.account_id = :account_id';
  $scopeParams[':account_id'] = $filterAccountId;
}
if ($filterChannelId > 0) {
  $scope[] = 'c.channel_id = :channel_id';
  $scopeParams[':channel_id'] = $filterChannelId;
}
$scopeSql = 'WHERE ' . implode(' AND ', $scope);

$messageScope = ['m.sent_at BETWEEN :from_at AND :to_at'];
$messageParams = [':from_at' => $fromUtc, ':to_at' => $toUtc];
if ($filterAccountId > 0) {
  $messageScope[] = 'c.account_id = :account_id';
  $messageParams[':account_id'] = $filterAccountId;
}
if ($filterChannelId > 0) {
  $messageScope[] = 'c.channel_id = :channel_id';
  $messageParams[':channel_id'] = $filterChannelId;
}
$messageScopeSql = 'WHERE ' . implode(' AND ', $messageScope);
$inboundMessageScopeSql = str_replace('m.sent_at', 'mi.sent_at', $messageScopeSql);

$totalConversations = (int) stats_fetch_value($pdo, "SELECT COUNT(*) FROM {$conversationsTable} c {$scopeSql}", $scopeParams);
$totalLeads = (int) stats_fetch_value($pdo, "SELECT COUNT(DISTINCT c.lead_id) FROM {$conversationsTable} c {$scopeSql} AND c.lead_id IS NOT NULL", $scopeParams);

$statusRows = stats_fetch_all($pdo, <<<SQL
SELECT COALESCE(l.sales_status, :default_status) AS status_key, COUNT(DISTINCT c.id) AS total
FROM {$conversationsTable} c
LEFT JOIN {$leadsTable} l ON l.id = c.lead_id
{$scopeSql}
GROUP BY COALESCE(l.sales_status, :default_status_group)
SQL, array_merge($scopeParams, [
  ':default_status' => (string) app_config('sales_funnel.default_status', 'nuevo_lead'),
  ':default_status_group' => (string) app_config('sales_funnel.default_status', 'nuevo_lead'),
]));

$salesStatuses = (array) app_config('sales_funnel.statuses', []);
$statusTotals = array_fill_keys(array_keys($salesStatuses), 0);
foreach ($statusRows as $row) {
  $key = (string) ($row['status_key'] ?? '');
  if ($key !== '') $statusTotals[$key] = (int) ($row['total'] ?? 0);
}

$won = (int) ($statusTotals['cliente_ganado'] ?? 0);
$lost = (int) ($statusTotals['cliente_perdido'] ?? 0);
$notQualified = (int) ($statusTotals['no_califica'] ?? 0);
$closedTotal = $won + $lost + $notQualified;
$winRate = $closedTotal > 0 ? ($won / $closedTotal) * 100 : 0.0;

$messageRows = stats_fetch_all($pdo, <<<SQL
SELECT m.direction, m.sent_at
FROM {$messagesTable} m
INNER JOIN {$conversationsTable} c ON c.id = m.conversation_id
{$messageScopeSql}
ORDER BY m.sent_at ASC
SQL, $messageParams);

$dayBuckets = [];
$period = new DatePeriod(new DateTimeImmutable($fromInput, app_timezone()), new DateInterval('P1D'), (new DateTimeImmutable($toInput, app_timezone()))->modify('+1 day'));
foreach ($period as $day) {
  $dayBuckets[$day->format('Y-m-d')] = ['label' => $day->format('d/m'), 'inbound' => 0, 'outbound' => 0];
}
foreach ($messageRows as $messageRow) {
  $date = app_utc_datetime($messageRow['sent_at'] ?? '');
  if (!$date) continue;
  $key = $date->setTimezone(app_timezone())->format('Y-m-d');
  if (!isset($dayBuckets[$key])) continue;
  $direction = (string) ($messageRow['direction'] ?? '');
  if ($direction === 'inbound') $dayBuckets[$key]['inbound']++;
  if ($direction === 'outbound') $dayBuckets[$key]['outbound']++;
}
$maxDailyMessages = 1;
foreach ($dayBuckets as $bucket) {
  $maxDailyMessages = max($maxDailyMessages, (int) $bucket['inbound'] + (int) $bucket['outbound']);
}

$channelRows = stats_fetch_all($pdo, <<<SQL
SELECT
  COALESCE(ch.page_name, ch.instagram_username, c.external_source, 'Sin canal') AS channel_label,
  COALESCE(c.external_source, ch.connection_type, 'desconocido') AS source_type,
  COUNT(DISTINCT c.id) AS conversations_count
FROM {$conversationsTable} c
LEFT JOIN {$channelsTable} ch ON ch.id = c.channel_id
{$scopeSql}
GROUP BY c.channel_id, channel_label, source_type
ORDER BY conversations_count DESC
LIMIT 8
SQL, $scopeParams);
$maxChannelCount = max(1, ...array_map(static fn($row) => (int) ($row['conversations_count'] ?? 0), $channelRows ?: [['conversations_count' => 1]]));

$firstResponseRows = stats_fetch_all($pdo, <<<SQL
SELECT firsts.conversation_id, firsts.first_inbound_at, MIN(mo.sent_at) AS first_outbound_at
FROM (
  SELECT c.id AS conversation_id, MIN(mi.sent_at) AS first_inbound_at
  FROM {$conversationsTable} c
  INNER JOIN {$messagesTable} mi ON mi.conversation_id = c.id AND mi.direction = 'inbound'
  {$inboundMessageScopeSql}
  GROUP BY c.id
) firsts
INNER JOIN {$messagesTable} mo ON mo.conversation_id = firsts.conversation_id
  AND mo.direction = 'outbound'
  AND mo.sent_at >= firsts.first_inbound_at
GROUP BY firsts.conversation_id, firsts.first_inbound_at
SQL, $messageParams);

$realResponseSeconds = [];
$businessResponseSeconds = [];
foreach ($firstResponseRows as $row) {
  $inboundAt = app_utc_datetime($row['first_inbound_at'] ?? '');
  $outboundAt = app_utc_datetime($row['first_outbound_at'] ?? '');
  if (!$inboundAt || !$outboundAt || $outboundAt < $inboundAt) continue;
  $realSeconds = $outboundAt->getTimestamp() - $inboundAt->getTimestamp();
  $realResponseSeconds[] = $realSeconds;
  $businessResponseSeconds[] = stats_business_seconds_between($inboundAt, $outboundAt);
}
$avgRealResponse = $realResponseSeconds ? (int) round(array_sum($realResponseSeconds) / count($realResponseSeconds)) : null;
$avgBusinessResponse = $businessResponseSeconds ? (int) round(array_sum($businessResponseSeconds) / count($businessResponseSeconds)) : null;

$unansweredCount = (int) stats_fetch_value($pdo, <<<SQL
SELECT COUNT(*) FROM (
  SELECT c.id,
    MAX(CASE WHEN m.direction = 'inbound' THEN m.sent_at ELSE NULL END) AS last_inbound_at,
    MAX(CASE WHEN m.direction = 'outbound' THEN m.sent_at ELSE NULL END) AS last_outbound_at
  FROM {$conversationsTable} c
  LEFT JOIN {$messagesTable} m ON m.conversation_id = c.id
  {$scopeSql}
  GROUP BY c.id
  HAVING last_inbound_at IS NOT NULL AND (last_outbound_at IS NULL OR last_outbound_at < last_inbound_at)
) pending
SQL, $scopeParams);

$lastInboundRows = stats_fetch_all($pdo, <<<SQL
SELECT c.id, MAX(m.sent_at) AS last_inbound_at
FROM {$conversationsTable} c
INNER JOIN {$messagesTable} m ON m.conversation_id = c.id AND m.direction = 'inbound'
{$scopeSql}
GROUP BY c.id
SQL, $scopeParams);
$metaWindowTotals = ['active' => 0, 'warning' => 0, 'expired' => 0, 'unknown' => 0];
foreach ($lastInboundRows as $row) {
  $info = meta_reply_window_info($row['last_inbound_at'] ?? '');
  $status = (string) ($info['status'] ?? 'unknown');
  $metaWindowTotals[$status] = ($metaWindowTotals[$status] ?? 0) + 1;
}

$agentRows = stats_fetch_all($pdo, <<<SQL
SELECT COALESCE(u.username, 'Sin usuario') AS agent_name,
  COUNT(*) AS messages_count,
  COUNT(DISTINCT m.conversation_id) AS conversations_count
FROM {$messagesTable} m
INNER JOIN {$conversationsTable} c ON c.id = m.conversation_id
LEFT JOIN {$usersTable} u ON u.id = m.sent_by
{$messageScopeSql} AND m.direction = 'outbound'
GROUP BY COALESCE(u.username, 'Sin usuario')
ORDER BY messages_count DESC
LIMIT 6
SQL, $messageParams);
$maxAgentMessages = max(1, ...array_map(static fn($row) => (int) ($row['messages_count'] ?? 0), $agentRows ?: [['messages_count' => 1]]));

$historyScope = ['h.created_at BETWEEN :from_at AND :to_at'];
$historyParams = [':from_at' => $fromUtc, ':to_at' => $toUtc];
if ($filterAccountId > 0) {
  $historyScope[] = 'h.account_id = :account_id';
  $historyParams[':account_id'] = $filterAccountId;
}
if ($filterChannelId > 0) {
  $historyScope[] = 'c.channel_id = :channel_id';
  $historyParams[':channel_id'] = $filterChannelId;
}
$historyScopeSql = 'WHERE ' . implode(' AND ', $historyScope);
$statusChangeRows = stats_fetch_all($pdo, <<<SQL
SELECT h.new_status, COUNT(DISTINCT h.id) AS total
FROM {$historyTable} h
LEFT JOIN {$conversationsTable} c ON c.lead_id = h.lead_id
{$historyScopeSql}
GROUP BY h.new_status
ORDER BY total DESC
LIMIT 8
SQL, $historyParams);
$maxStatusChanges = max(1, ...array_map(static fn($row) => (int) ($row['total'] ?? 0), $statusChangeRows ?: [['total' => 1]]));

$activeWindowCount = (int) ($metaWindowTotals['active'] ?? 0);
$warningWindowCount = (int) ($metaWindowTotals['warning'] ?? 0);
$expiredWindowCount = (int) ($metaWindowTotals['expired'] ?? 0);

?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Estadísticas – <?= h(app_config('brand.name', 'Pixels Studio')) ?></title>
  <link rel="stylesheet" href="css/app.css">
  <style>
    :root {
      --stats-bg:#f3f6fb;
      --stats-panel:#ffffff;
      --stats-ink:#0b1220;
      --stats-muted:#6c778f;
      --stats-border:#d8e6f5;
      --stats-dark:#050b18;
      --stats-cyan:#12c7e8;
      --stats-blue:#2563eb;
      --stats-purple:#7c3cff;
      --stats-green:#23b26b;
      --stats-yellow:#f5b83d;
      --stats-red:#e75267;
      --stats-shadow:0 24px 70px rgba(19, 35, 67, .10);
    }
    body.dashboard-page {
      min-height:100vh;
      margin:0;
      background:var(--stats-bg);
      color:var(--stats-ink);
      font-family:Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    }
    .stats-shell {
      width:100%;
      min-height:100vh;
      padding:clamp(18px, 2.4vw, 34px);
      box-sizing:border-box;
    }
    .stats-panel {
      width:100%;
      display:grid;
      gap:18px;
    }
    .stats-topbar {
      display:flex;
      align-items:flex-start;
      justify-content:space-between;
      gap:18px;
    }
    .stats-actions {
      display:flex;
      align-items:center;
      justify-content:flex-end;
      gap:10px;
      flex-wrap:wrap;
    }
    .eyebrow {
      margin:0 0 6px;
      color:var(--stats-cyan);
      font-size:.78rem;
      font-weight:900;
      letter-spacing:.12em;
      text-transform:uppercase;
    }
    .title {
      margin:0;
      font-size:clamp(2rem, 4vw, 4.2rem);
      line-height:.96;
      letter-spacing:0;
      color:var(--stats-ink);
    }
    .subtitle {
      margin:10px 0 0;
      max-width:760px;
      color:var(--stats-muted);
      font-weight:750;
      font-size:1rem;
    }
    .menu-trigger,
    .nav-direct-button,
    .stats-button {
      border:1px solid var(--stats-border);
      border-radius:18px;
      background:#fff;
      color:var(--stats-ink);
      min-height:52px;
      padding:0 22px;
      display:inline-flex;
      align-items:center;
      justify-content:center;
      gap:8px;
      font-weight:900;
      text-decoration:none;
      box-shadow:0 8px 20px rgba(11, 18, 32, .05);
      transition:background .18s ease, color .18s ease, border-color .18s ease, transform .18s ease;
    }
    .menu-trigger:hover,
    .nav-direct-button:hover,
    .stats-button:hover {
      background:var(--stats-dark);
      color:#fff;
      border-color:var(--stats-dark);
      transform:translateY(-1px);
    }
    .menu-panel {
      border:1px solid var(--stats-border);
      border-radius:22px;
      box-shadow:var(--stats-shadow);
    }
    .stats-filters {
      display:grid;
      grid-template-columns:minmax(150px, 190px) minmax(150px, 190px) minmax(220px, 320px) auto;
      gap:12px;
      align-items:end;
      background:#eef8fd;
      border:1px solid #caeff9;
      border-radius:24px;
      padding:16px;
    }
    .stats-field {
      display:grid;
      gap:6px;
    }
    .stats-field label {
      color:var(--stats-muted);
      font-size:.72rem;
      font-weight:950;
      letter-spacing:.1em;
      text-transform:uppercase;
    }
    .stats-field input,
    .stats-field select {
      width:100%;
      min-height:48px;
      box-sizing:border-box;
      border:1px solid var(--stats-border);
      border-radius:16px;
      padding:0 14px;
      color:var(--stats-ink);
      background:#fff;
      font-weight:850;
      font-size:.95rem;
      outline:none;
    }
    .stats-field select {
      appearance:auto;
    }
    .stats-grid {
      display:grid;
      grid-template-columns:repeat(4, minmax(0, 1fr));
      gap:14px;
    }
    .metric-card,
    .chart-card {
      background:var(--stats-panel);
      border:1px solid var(--stats-border);
      border-radius:26px;
      box-shadow:0 16px 40px rgba(20, 39, 74, .06);
    }
    .metric-card {
      min-height:122px;
      padding:22px;
      position:relative;
      overflow:hidden;
    }
    .metric-card::before {
      content:"";
      position:absolute;
      top:0;
      left:0;
      width:7px;
      height:100%;
      background:var(--tone, var(--stats-cyan));
    }
    .metric-card span {
      color:var(--stats-muted);
      display:block;
      font-size:.78rem;
      font-weight:950;
      letter-spacing:.08em;
      text-transform:uppercase;
    }
    .metric-card strong {
      display:block;
      margin-top:12px;
      color:var(--stats-ink);
      font-size:2.4rem;
      line-height:1;
      letter-spacing:0;
    }
    .metric-card small {
      color:var(--stats-muted);
      display:block;
      margin-top:10px;
      font-weight:800;
    }
    .charts-grid {
      display:grid;
      grid-template-columns:1.2fr .8fr;
      gap:14px;
    }
    .chart-card {
      padding:22px;
      min-width:0;
    }
    .chart-title-row {
      display:flex;
      justify-content:space-between;
      gap:16px;
      align-items:flex-start;
      margin-bottom:18px;
    }
    .chart-title-row h2 {
      margin:0;
      color:var(--stats-ink);
      font-size:1.35rem;
      line-height:1.05;
      letter-spacing:0;
    }
    .chart-title-row p {
      margin:6px 0 0;
      color:var(--stats-muted);
      font-weight:750;
    }
    .funnel-bars {
      display:grid;
      gap:12px;
    }
    .bar-row {
      display:grid;
      grid-template-columns:minmax(130px, 220px) 1fr auto;
      gap:12px;
      align-items:center;
    }
    .bar-label {
      font-weight:900;
      color:var(--stats-ink);
    }
    .bar-track {
      height:15px;
      background:#edf3f9;
      border-radius:999px;
      overflow:hidden;
    }
    .bar-fill {
      display:block;
      width:calc(var(--value, 0) * 1%);
      height:100%;
      border-radius:999px;
      background:var(--tone, var(--stats-cyan));
    }
    .bar-value {
      min-width:36px;
      text-align:right;
      font-weight:950;
      color:var(--stats-ink);
    }
    .daily-chart {
      display:grid;
      grid-template-columns:repeat(auto-fit, minmax(26px, 1fr));
      align-items:end;
      gap:8px;
      min-height:230px;
      padding-top:18px;
    }
    .day-column {
      display:grid;
      grid-template-rows:1fr auto;
      gap:8px;
      min-width:0;
    }
    .day-stack {
      display:flex;
      flex-direction:column;
      justify-content:flex-end;
      gap:3px;
      height:190px;
    }
    .day-segment {
      min-height:3px;
      border-radius:8px 8px 0 0;
    }
    .day-segment.inbound { background:var(--stats-cyan); }
    .day-segment.outbound { background:var(--stats-purple); }
    .day-label {
      color:var(--stats-muted);
      font-size:.68rem;
      font-weight:850;
      text-align:center;
      white-space:nowrap;
    }
    .legend {
      display:flex;
      flex-wrap:wrap;
      gap:10px;
      color:var(--stats-muted);
      font-weight:850;
      font-size:.84rem;
    }
    .legend span::before {
      content:"";
      display:inline-block;
      width:10px;
      height:10px;
      border-radius:50%;
      margin-right:6px;
      background:var(--tone);
    }
    .split-grid {
      display:grid;
      grid-template-columns:repeat(3, minmax(0, 1fr));
      gap:14px;
    }
    .window-grid {
      display:grid;
      grid-template-columns:repeat(3, minmax(0, 1fr));
      gap:10px;
    }
    .window-pill {
      border-radius:20px;
      padding:16px;
      background:#f6f9fd;
      border:1px solid var(--stats-border);
    }
    .window-pill span {
      display:block;
      color:var(--stats-muted);
      font-weight:900;
      font-size:.8rem;
    }
    .window-pill strong {
      display:block;
      margin-top:8px;
      font-size:2rem;
      color:var(--stats-ink);
      line-height:1;
    }
    .insight-list {
      display:grid;
      gap:12px;
    }
    .insight-item {
      display:grid;
      grid-template-columns:1fr auto;
      gap:12px;
      align-items:center;
    }
    .insight-meta strong {
      display:block;
      color:var(--stats-ink);
      font-weight:950;
    }
    .insight-meta span {
      color:var(--stats-muted);
      font-weight:750;
      font-size:.9rem;
    }
    .empty-state {
      padding:28px;
      border:1px dashed var(--stats-border);
      border-radius:20px;
      color:var(--stats-muted);
      font-weight:850;
      text-align:center;
    }
    @media (max-width: 1120px) {
      .stats-grid,
      .split-grid {
        grid-template-columns:repeat(2, minmax(0, 1fr));
      }
      .charts-grid {
        grid-template-columns:1fr;
      }
      .stats-filters {
        grid-template-columns:repeat(2, minmax(0, 1fr));
      }
    }
    @media (max-width: 720px) {
      .stats-shell {
        padding:14px;
      }
      .stats-topbar {
        display:grid;
      }
      .stats-actions {
        justify-content:flex-start;
      }
      .stats-grid,
      .split-grid,
      .window-grid,
      .stats-filters {
        grid-template-columns:1fr;
      }
      .title {
        font-size:2.15rem;
      }
      .bar-row {
        grid-template-columns:1fr auto;
      }
      .bar-track {
        grid-column:1 / -1;
        grid-row:2;
      }
      .daily-chart {
        overflow-x:auto;
        grid-template-columns:repeat(<?= max(7, count($dayBuckets)) ?>, 36px);
      }
    }
  </style>
</head>
<body class="dashboard-page">
  <main class="stats-shell">
    <section class="stats-panel">
      <header class="stats-topbar">
        <div>
          <p class="eyebrow"><?= h(app_config('brand.name', 'Pixels Studio')) ?></p>
          <h1 class="title">Estadísticas comerciales</h1>
          <p class="subtitle">Mide el comportamiento del embudo, canales, mensajes y tiempos de respuesta sin castigar las horas fuera de jornada.</p>
        </div>
        <div class="stats-actions app-nav-actions">
          <?php nav_render_view_button('stats'); ?>
          <?php nav_render_account_switch($pdo, $accountOptions, $filterAccountId, 'stats.php', ['from' => $fromInput, 'to' => $toInput, 'channel_id' => $filterChannelId > 0 ? $filterChannelId : null], ['from' => $fromInput, 'to' => $toInput, 'channel_id' => $filterChannelId > 0 ? $filterChannelId : null]); ?>
          <?php nav_render_user_menu(true); ?>
        </div>
      </header>

      <form class="stats-filters" method="get" action="<?= h(account_url('stats.php')) ?>">
        <?php if (is_super_admin() && $requestSlug === '' && $filterAccountId > 0): ?>
          <input type="hidden" name="account_id" value="<?= (int) $filterAccountId ?>">
        <?php endif; ?>
        <div class="stats-field">
          <label for="from">Desde</label>
          <input id="from" type="date" name="from" value="<?= h($fromInput) ?>">
        </div>
        <div class="stats-field">
          <label for="to">Hasta</label>
          <input id="to" type="date" name="to" value="<?= h($toInput) ?>">
        </div>
        <div class="stats-field">
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
        <button class="stats-button" type="submit">Filtrar</button>
      </form>

      <section class="stats-grid" aria-label="Indicadores principales">
        <article class="metric-card" style="--tone:var(--stats-cyan)">
          <span>Conversaciones</span>
          <strong><?= number_format($totalConversations, 0, ',', '.') ?></strong>
          <small><?= h($fromInput) ?> al <?= h($toInput) ?></small>
        </article>
        <article class="metric-card" style="--tone:var(--stats-purple)">
          <span>Leads vinculados</span>
          <strong><?= number_format($totalLeads, 0, ',', '.') ?></strong>
          <small>Conversaciones con ficha comercial</small>
        </article>
        <article class="metric-card" style="--tone:var(--stats-green)">
          <span>Tasa ganada</span>
          <strong><?= stats_format_percent($winRate) ?></strong>
          <small><?= $won ?> ganados sobre <?= max(1, $closedTotal) ?> cierres</small>
        </article>
        <article class="metric-card" style="--tone:var(--stats-yellow)">
          <span>Sin respuesta</span>
          <strong><?= number_format($unansweredCount, 0, ',', '.') ?></strong>
          <small>Último mensaje del cliente pendiente</small>
        </article>
      </section>

      <section class="charts-grid">
        <article class="chart-card">
          <div class="chart-title-row">
            <div>
              <h2>Embudo por status comercial</h2>
              <p>Distribución actual de conversaciones en cada etapa.</p>
            </div>
          </div>
          <div class="funnel-bars">
            <?php $maxStatusTotal = max(1, ...array_values($statusTotals)); ?>
            <?php foreach ($statusTotals as $statusKey => $total): ?>
              <?php
                $toneMap = [
                  'nuevo_lead' => 'var(--stats-cyan)',
                  'en_conversacion' => 'var(--stats-blue)',
                  'propuesta_enviada' => 'var(--stats-yellow)',
                  'no_responde' => 'var(--stats-red)',
                  'cliente_ganado' => 'var(--stats-green)',
                  'cliente_perdido' => 'var(--stats-purple)',
                  'no_califica' => '#6b7280',
                ];
                $width = $maxStatusTotal > 0 ? round(((int) $total / $maxStatusTotal) * 100, 2) : 0;
              ?>
              <div class="bar-row">
                <span class="bar-label"><?= h(stats_status_label((string) $statusKey)) ?></span>
                <span class="bar-track"><span class="bar-fill" style="--value:<?= h((string) $width) ?>;--tone:<?= h($toneMap[$statusKey] ?? 'var(--stats-cyan)') ?>"></span></span>
                <span class="bar-value"><?= (int) $total ?></span>
              </div>
            <?php endforeach; ?>
          </div>
        </article>

        <article class="chart-card">
          <div class="chart-title-row">
            <div>
              <h2>Ventana de conversación</h2>
              <p>Riesgo operativo según la última respuesta del cliente.</p>
            </div>
          </div>
          <div class="window-grid">
            <div class="window-pill">
              <span>Chat activo</span>
              <strong><?= $activeWindowCount ?></strong>
            </div>
            <div class="window-pill">
              <span>Por vencer</span>
              <strong><?= $warningWindowCount ?></strong>
            </div>
            <div class="window-pill">
              <span>Vencidos</span>
              <strong><?= $expiredWindowCount ?></strong>
            </div>
          </div>
        </article>
      </section>

      <section class="charts-grid">
        <article class="chart-card">
          <div class="chart-title-row">
            <div>
              <h2>Mensajes por día</h2>
              <p>Entrantes vs salientes durante el periodo filtrado.</p>
            </div>
            <div class="legend">
              <span style="--tone:var(--stats-cyan)">Entrantes</span>
              <span style="--tone:var(--stats-purple)">Salientes</span>
            </div>
          </div>
          <?php if (!$messageRows): ?>
            <div class="empty-state">Aún no hay mensajes en este periodo.</div>
          <?php else: ?>
            <div class="daily-chart" aria-label="Mensajes entrantes y salientes por día">
              <?php foreach ($dayBuckets as $bucket): ?>
                <?php
                  $inboundHeight = max(3, ((int) $bucket['inbound'] / $maxDailyMessages) * 190);
                  $outboundHeight = max(3, ((int) $bucket['outbound'] / $maxDailyMessages) * 190);
                  if ((int) $bucket['inbound'] === 0) $inboundHeight = 0;
                  if ((int) $bucket['outbound'] === 0) $outboundHeight = 0;
                ?>
                <div class="day-column" title="<?= h($bucket['label']) ?>: <?= (int) $bucket['inbound'] ?> entrantes, <?= (int) $bucket['outbound'] ?> salientes">
                  <div class="day-stack">
                    <span class="day-segment outbound" style="height:<?= h((string) $outboundHeight) ?>px"></span>
                    <span class="day-segment inbound" style="height:<?= h((string) $inboundHeight) ?>px"></span>
                  </div>
                  <span class="day-label"><?= h($bucket['label']) ?></span>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </article>

        <article class="chart-card">
          <div class="chart-title-row">
            <div>
              <h2>Tiempos de primera respuesta</h2>
              <p>Comparación entre reloj corrido y horario comercial.</p>
            </div>
          </div>
          <div class="insight-list">
            <div class="insight-item">
              <div class="insight-meta">
                <strong>Promedio real</strong>
                <span>Incluye noches, fines de semana y horas off.</span>
              </div>
              <span class="bar-value"><?= h(stats_format_duration($avgRealResponse)) ?></span>
            </div>
            <div class="insight-item">
              <div class="insight-meta">
                <strong>Promedio operativo</strong>
                <span><?= h(app_config('business_hours.start', '09:00')) ?> a <?= h(app_config('business_hours.end', '18:00')) ?>, días hábiles.</span>
              </div>
              <span class="bar-value"><?= h(stats_format_duration($avgBusinessResponse)) ?></span>
            </div>
            <div class="insight-item">
              <div class="insight-meta">
                <strong>Muestras válidas</strong>
                <span>Conversaciones con entrada y primera salida.</span>
              </div>
              <span class="bar-value"><?= count($realResponseSeconds) ?></span>
            </div>
          </div>
        </article>
      </section>

      <section class="split-grid">
        <article class="chart-card">
          <div class="chart-title-row">
            <div>
              <h2>Canales</h2>
              <p>Conversaciones por origen.</p>
            </div>
          </div>
          <?php if (!$channelRows): ?>
            <div class="empty-state">Sin actividad por canal.</div>
          <?php else: ?>
            <div class="funnel-bars">
              <?php foreach ($channelRows as $row): ?>
                <?php $width = round(((int) ($row['conversations_count'] ?? 0) / $maxChannelCount) * 100, 2); ?>
                <div class="bar-row">
                  <span class="bar-label"><?= h((string) ($row['channel_label'] ?? 'Canal')) ?></span>
                  <span class="bar-track"><span class="bar-fill" style="--value:<?= h((string) $width) ?>;--tone:var(--stats-cyan)"></span></span>
                  <span class="bar-value"><?= (int) ($row['conversations_count'] ?? 0) ?></span>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </article>

        <article class="chart-card">
          <div class="chart-title-row">
            <div>
              <h2>Agentes</h2>
              <p>Mensajes enviados por operador.</p>
            </div>
          </div>
          <?php if (!$agentRows): ?>
            <div class="empty-state">Aún no hay respuestas de agentes.</div>
          <?php else: ?>
            <div class="funnel-bars">
              <?php foreach ($agentRows as $row): ?>
                <?php $width = round(((int) ($row['messages_count'] ?? 0) / $maxAgentMessages) * 100, 2); ?>
                <div class="bar-row">
                  <span class="bar-label"><?= h((string) ($row['agent_name'] ?? 'Agente')) ?></span>
                  <span class="bar-track"><span class="bar-fill" style="--value:<?= h((string) $width) ?>;--tone:var(--stats-purple)"></span></span>
                  <span class="bar-value"><?= (int) ($row['messages_count'] ?? 0) ?></span>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </article>

        <article class="chart-card">
          <div class="chart-title-row">
            <div>
              <h2>Cambios de status</h2>
              <p>Movimientos comerciales registrados.</p>
            </div>
          </div>
          <?php if (!$statusChangeRows): ?>
            <div class="empty-state">Sin cambios de status en el periodo.</div>
          <?php else: ?>
            <div class="funnel-bars">
              <?php foreach ($statusChangeRows as $row): ?>
                <?php $width = round(((int) ($row['total'] ?? 0) / $maxStatusChanges) * 100, 2); ?>
                <div class="bar-row">
                  <span class="bar-label"><?= h(stats_status_label((string) ($row['new_status'] ?? ''))) ?></span>
                  <span class="bar-track"><span class="bar-fill" style="--value:<?= h((string) $width) ?>;--tone:var(--stats-green)"></span></span>
                  <span class="bar-value"><?= (int) ($row['total'] ?? 0) ?></span>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </article>
      </section>
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
