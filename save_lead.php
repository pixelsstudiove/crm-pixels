<?php
// save_lead.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/accounts.php';

function column_exists(PDO $pdo, string $dbName, string $table, string $column): bool {
  $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?");
  $stmt->execute([$dbName, $table, $column]);
  return (int) $stmt->fetchColumn() > 0;
}

function index_exists(PDO $pdo, string $dbName, string $table, string $index): bool {
  $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?");
  $stmt->execute([$dbName, $table, $index]);
  return (int) $stmt->fetchColumn() > 0;
}

function ensure_leads_schema(PDO $pdo, string $dbName, string $table): void {
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
  accounts_rebuild_unique_index($pdo, $dbName, $table, 'uniq_external_contact', 'account_id, external_source, external_contact_id');

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

  foreach ($columns as $column => $alterSql) {
    if (!column_exists($pdo, $dbName, $table, $column)) {
      $pdo->exec($alterSql);
    }
  }

  foreach ([
    'phone' => 'VARCHAR(64) NULL',
    'email' => 'VARCHAR(150) NULL',
    'brand_instagram' => 'VARCHAR(120) NULL',
    'business_type' => 'VARCHAR(80) NULL',
    'services_needed' => 'TEXT NULL',
    'main_objective' => 'VARCHAR(120) NULL',
  ] as $column => $definition) {
    if (column_exists($pdo, $dbName, $table, $column)) {
      try { $pdo->exec("ALTER TABLE {$table} MODIFY {$column} {$definition}"); } catch (Throwable $e) { /* no-op */ }
    }
  }

  // Si el proyecto viene de una versión anterior, estos campos ya no se usan.
  // Se vuelven opcionales para que no bloqueen nuevos registros.
  foreach (['brand_business','city_country','current_situation','budget_range','start_timeline','location_state','interest_category','sexo','age','birthdate'] as $legacyColumn) {
    if (column_exists($pdo, $dbName, $table, $legacyColumn)) {
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
    'uniq_external_contact' => "ALTER TABLE {$table} ADD UNIQUE KEY uniq_external_contact (account_id, external_source, external_contact_id)",
    'idx_account_id' => "ALTER TABLE {$table} ADD KEY idx_account_id (account_id)",
    'idx_last_message_at' => "ALTER TABLE {$table} ADD KEY idx_last_message_at (last_message_at)",
    'idx_status' => "ALTER TABLE {$table} ADD KEY idx_status (status)",
    'idx_created_at' => "ALTER TABLE {$table} ADD KEY idx_created_at (created_at)",
  ];
  foreach ($indexes as $index => $sql) {
    if (!index_exists($pdo, $dbName, $table, $index)) {
      try { $pdo->exec($sql); } catch (Throwable $e) { /* índice preexistente con otro nombre */ }
    }
  }
}

function normalize_phone_for_whatsapp(string $raw): string {
  $countryCode = (string) app_config('phone.country_code', '58');
  $digits = preg_replace('/\D+/', '', $raw) ?: '';
  if (str_starts_with($digits, '0')) $digits = substr($digits, 1);
  if (!str_starts_with($digits, $countryCode)) $digits = $countryCode . $digits;
  return $digits;
}

function clean_short($value, int $max = 160): ?string {
  $value = trim(str_replace("\0", '', (string) $value));
  if ($value === '') return null;
  return mb_substr($value, 0, $max);
}

function clean_url_value($value, int $max = 2000): ?string {
  $value = trim(str_replace("\0", '', (string) $value));
  if ($value === '') return null;
  $value = mb_substr($value, 0, $max);
  if (!preg_match('#^https?://#i', $value)) return null;
  return $value;
}

function detect_source_platform(?string $source, ?string $referrer = null, ?string $gclid = null, ?string $fbclid = null): string {
  $raw = strtolower(trim((string) $source));
  $ref = strtolower(trim((string) $referrer));
  $haystack = trim($raw . ' ' . $ref);
  if ($fbclid !== null && $fbclid !== '') return 'facebook';
  if ($gclid !== null && $gclid !== '') return 'google';
  if (preg_match('/instagram|\big\b/', $haystack)) return 'instagram';
  if (preg_match('/facebook|\bfb\b|meta/', $haystack)) return 'facebook';
  if (preg_match('/google|gclid/', $haystack)) return 'google';
  if (preg_match('/tiktok/', $haystack)) return 'tiktok';
  if (preg_match('/whatsapp|wa\.me/', $haystack)) return 'whatsapp';
  if (preg_match('/youtube/', $haystack)) return 'youtube';
  if (preg_match('/linkedin/', $haystack)) return 'linkedin';
  if (preg_match('/email|mail/', $haystack)) return 'email';
  if ($raw !== '') return mb_substr($raw, 0, 80);
  if ($ref !== '') return 'referido';
  return 'directo';
}

function evo_send_text(string $base, string $instance, string $apikey, string $number, string $text, int $delayMs = 0): array {
  $url = rtrim($base, '/') . '/message/sendText/' . rawurlencode($instance);
  $payload = ['number' => $number, 'text' => $text];
  if ($delayMs > 0) $payload['delay'] = $delayMs;
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'apikey: ' . $apikey],
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
    CURLOPT_TIMEOUT => 20,
  ]);
  $resp = curl_exec($ch);
  $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err = curl_error($ch);
  curl_close($ch);
  return ['http' => $http, 'error' => $err, 'raw' => $resp, 'json' => json_decode((string) $resp, true)];
}

function lead_security_dir(): string {
  $dir = __DIR__ . '/storage/security/leads';
  if (!is_dir($dir)) @mkdir($dir, 0770, true);
  return $dir;
}
function lead_rate_file(): string {
  $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
  return lead_security_dir() . '/' . hash('sha256', $ip) . '.json';
}
function lead_rate_state(): array {
  $file = lead_rate_file();
  if (!is_file($file)) return ['count' => 0, 'first_at' => 0];
  $data = json_decode((string) file_get_contents($file), true);
  return is_array($data) ? $data + ['count' => 0, 'first_at' => 0] : ['count' => 0, 'first_at' => 0];
}
function lead_rate_exceeded(): bool {
  $state = lead_rate_state();
  $window = (int) app_config('security.lead_window_seconds', 3600);
  $max = (int) app_config('security.lead_max_submits', 20);
  $first = (int) ($state['first_at'] ?? 0);
  $count = (int) ($state['count'] ?? 0);
  return $first > 0 && (time() - $first) <= $window && $count >= $max;
}
function lead_register_attempt(): void {
  $now = time();
  $window = (int) app_config('security.lead_window_seconds', 3600);
  $state = lead_rate_state();
  $first = (int) ($state['first_at'] ?? 0);
  $count = (int) ($state['count'] ?? 0);
  if ($first <= 0 || ($now - $first) > $window) {
    $first = $now;
    $count = 0;
  }
  @file_put_contents(lead_rate_file(), json_encode(['count' => $count + 1, 'first_at' => $first]));
}

try {
  ensure_leads_schema($pdo, $DB_NAME, $TABLE_LEADS);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'errors' => ['No se pudo preparar la tabla de registros.']], JSON_UNESCAPED_UNICODE);
  exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok' => false, 'errors' => ['Método no permitido.']], JSON_UNESCAPED_UNICODE);
  exit;
}

if (trim((string) ($_POST['website_url'] ?? '')) !== '') {
  echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
  exit;
}

if (lead_rate_exceeded()) {
  http_response_code(429);
  echo json_encode(['ok' => false, 'errors' => ['Demasiadas solicitudes. Intenta nuevamente más tarde.']], JSON_UNESCAPED_UNICODE);
  exit;
}
lead_register_attempt();

$fullname = trim((string) ($_POST['fullname'] ?? ''));
$phone = trim((string) ($_POST['phone'] ?? ''));
$email = strtolower(trim((string) ($_POST['email'] ?? '')));
$brandInstagram = trim((string) ($_POST['brand_instagram'] ?? ''));
$businessTypeRaw = trim((string) ($_POST['business_type'] ?? ''));
$businessTypeOther = trim((string) ($_POST['business_type_other'] ?? ''));
$servicesRaw = $_POST['services_needed'] ?? [];
$mainObjectiveRaw = trim((string) ($_POST['main_objective'] ?? ''));
$message = trim((string) ($_POST['message'] ?? ''));

$utmSource = clean_short($_POST['utm_source'] ?? null, 80);
$utmMedium = clean_short($_POST['utm_medium'] ?? null, 80);
$utmCampaign = clean_short($_POST['utm_campaign'] ?? null, 120);
$utmContent = clean_short($_POST['utm_content'] ?? null, 160);
$utmTerm = clean_short($_POST['utm_term'] ?? null, 160);
$adName = clean_short($_POST['ad_name'] ?? null, 180);
$adId = clean_short($_POST['ad_id'] ?? null, 120);
$gclid = clean_short($_POST['gclid'] ?? null, 180);
$fbclid = clean_short($_POST['fbclid'] ?? null, 180);
$landingUrl = clean_url_value($_POST['landing_url'] ?? null, 2000);
$referrer = clean_url_value($_POST['referrer'] ?? null, 2000);
$sourcePlatform = clean_short($_POST['source_platform'] ?? null, 80);
$sourcePlatform = detect_source_platform($sourcePlatform ?: $utmSource, $referrer, $gclid, $fbclid);

$errors = [];
if ($fullname === '' || mb_strlen($fullname) < 3 || mb_strlen($fullname) > 120) $errors['fullname'] = 'El nombre y apellido es obligatorio y debe tener entre 3 y 120 caracteres.';
if (!preg_match('/^[0-9]{4}-[0-9]{7}$/', $phone)) $errors['phone'] = 'Teléfono inválido. Usa el formato 0000-0000000.';
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 150) $errors['email'] = 'Ingresa un correo electrónico válido.';
if ($brandInstagram === '' || mb_strlen($brandInstagram) < 2 || mb_strlen($brandInstagram) > 120) $errors['brand_instagram'] = 'Indica el Instagram de la marca o escribe “no tiene”.';
if (mb_strlen($message) > 1200) $errors['message'] = 'El mensaje no debe superar 1200 caracteres.';

$businessTypes = (array) app_config('lead_fields.business_type.options', []);
if (!array_key_exists($businessTypeRaw, $businessTypes)) $errors['business_type'] = 'Selecciona el tipo de negocio.';
$businessType = $businessTypes[$businessTypeRaw] ?? null;
if ($businessTypeRaw === 'otro') {
  if ($businessTypeOther === '' || mb_strlen($businessTypeOther) < 2 || mb_strlen($businessTypeOther) > 120) {
    $errors['business_type_other'] = 'Indica cuál es tu área de negocio.';
  }
} else {
  $businessTypeOther = '';
}

$serviceOptions = (array) app_config('lead_fields.services_needed.options', []);
$servicesRaw = is_array($servicesRaw) ? $servicesRaw : [];
$servicesLabels = [];
foreach ($servicesRaw as $serviceKey) {
  $serviceKey = (string) $serviceKey;
  if (array_key_exists($serviceKey, $serviceOptions)) $servicesLabels[] = $serviceOptions[$serviceKey];
}
if (!$servicesLabels) $errors['services_needed'] = 'Selecciona al menos un servicio.';
$servicesNeeded = implode(', ', $servicesLabels);

$objectives = (array) app_config('lead_fields.main_objective.options', []);
if (!array_key_exists($mainObjectiveRaw, $objectives)) $errors['main_objective'] = 'Selecciona el objetivo principal.';
$mainObjective = $objectives[$mainObjectiveRaw] ?? null;

if ($errors) {
  http_response_code(422);
  echo json_encode(['ok' => false, 'errors' => $errors], JSON_UNESCAPED_UNICODE);
  exit;
}

try {
  $stmt = $pdo->prepare("SELECT id FROM {$TABLE_LEADS} WHERE phone = ? LIMIT 1");
  $stmt->execute([$phone]);
  if ($stmt->fetch()) {
    http_response_code(409);
    echo json_encode(['ok' => false, 'errors' => ['phone' => 'Este número ya está registrado.']], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $defaultSalesStatus = (string) app_config('sales_funnel.default_status', 'nuevo_lead');
  $accountId = accounts_default_id($pdo);
  $ip = $_SERVER['REMOTE_ADDR'] ?? null;
  $ua = isset($_SERVER['HTTP_USER_AGENT']) ? mb_substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 255) : null;

  $insert = $pdo->prepare(<<<SQL
INSERT INTO {$TABLE_LEADS} (
  account_id, fullname, phone, email, brand_instagram, business_type, business_type_other, services_needed, main_objective, message,
  source_platform, utm_source, utm_medium, utm_campaign, utm_content, utm_term, ad_name, ad_id, gclid, fbclid, landing_url, referrer,
  sales_status, status, ip, user_agent, whatsapp_sent, whatsapp_status
) VALUES (
  :account_id, :fullname, :phone, :email, :brand_instagram, :business_type, :business_type_other, :services_needed, :main_objective, :message,
  :source_platform, :utm_source, :utm_medium, :utm_campaign, :utm_content, :utm_term, :ad_name, :ad_id, :gclid, :fbclid, :landing_url, :referrer,
  :sales_status, 'pending', :ip, :user_agent, 0, 'disabled'
)
SQL);

  $insert->execute([
    ':account_id' => $accountId,
    ':fullname' => $fullname,
    ':phone' => $phone,
    ':email' => $email,
    ':brand_instagram' => $brandInstagram,
    ':business_type' => $businessType,
    ':business_type_other' => $businessTypeOther !== '' ? $businessTypeOther : null,
    ':services_needed' => $servicesNeeded,
    ':main_objective' => $mainObjective,
    ':message' => $message !== '' ? $message : null,
    ':source_platform' => $sourcePlatform,
    ':utm_source' => $utmSource,
    ':utm_medium' => $utmMedium,
    ':utm_campaign' => $utmCampaign,
    ':utm_content' => $utmContent,
    ':utm_term' => $utmTerm,
    ':ad_name' => $adName,
    ':ad_id' => $adId,
    ':gclid' => $gclid,
    ':fbclid' => $fbclid,
    ':landing_url' => $landingUrl,
    ':referrer' => $referrer,
    ':sales_status' => $defaultSalesStatus,
    ':ip' => $ip,
    ':user_agent' => $ua,
  ]);

  $leadId = (int) $pdo->lastInsertId();

  $whatsappSent = 0;
  $whatsappStatus = 'disabled';
  if ((bool) app_config('whatsapp.enabled', false)) {
    $base = (string) app_config('whatsapp.base_url', '');
    $instance = (string) app_config('whatsapp.instance', '');
    $apikey = (string) app_config('whatsapp.apikey', '');
    if ($base !== '' && $instance !== '' && $apikey !== '') {
      $template = (string) app_config('whatsapp.message_template', '');
      $text = strtr($template, [
        '{fullname}' => $fullname,
        '{brand_name}' => (string) app_config('brand.name', 'Pixels Studio'),
        '{brand_instagram}' => $brandInstagram,
        '{services_needed}' => $servicesNeeded,
        '{main_objective}' => (string) $mainObjective,
      ]);
      $result = evo_send_text($base, $instance, $apikey, normalize_phone_for_whatsapp($phone), $text, (int) app_config('whatsapp.delay_ms', 0));
      $whatsappSent = ($result['http'] >= 200 && $result['http'] < 300) ? 1 : 0;
      $whatsappStatus = $whatsappSent ? 'sent' : 'failed';
    } else {
      $whatsappStatus = 'missing_config';
    }
    $upd = $pdo->prepare("UPDATE {$TABLE_LEADS} SET whatsapp_sent = ?, whatsapp_status = ? WHERE id = ?");
    $upd->execute([$whatsappSent, $whatsappStatus, $leadId]);
  }

  echo json_encode(['ok' => true, 'id' => $leadId], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'errors' => ['No se pudo guardar el registro.']], JSON_UNESCAPED_UNICODE);
}
