<?php
// instalar_base_datos.php
// Ejecuta la creación/actualización de la base de datos desde el navegador.
// IMPORTANTE: elimina este archivo del servidor después de usarlo.
declare(strict_types=1);

require_once __DIR__ . '/config/db.php';

$installKey = (string) app_config('security.install_key', '');
$providedKey = (string) ($_GET['key'] ?? $_POST['key'] ?? '');
$isLocalRequest = in_array($_SERVER['SERVER_NAME'] ?? '', ['localhost', '127.0.0.1', '::1'], true);
$usesDefaultInstallKey = hash_equals('pixels-install-2026', $installKey);
$isAuthorized = $installKey !== '' && hash_equals($installKey, $providedKey) && ($isLocalRequest || !$usesDefaultInstallKey);

function page_start(string $title = 'Instalador de base de datos'): void {
  echo '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
  echo '<title>' . h($title) . '</title>';
  echo '<style>
    :root{--brand:#071120;--accent:#00d4ff;--ink:#0b1324;--line:#cfe5f4;--soft:#edf8ff;--ok:#217a43;--err:#b12d37;}
    *{box-sizing:border-box} body{margin:0;min-height:100vh;background:radial-gradient(circle at top left, rgba(0,212,255,.22), transparent 34%), #071120;font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;color:var(--ink);display:grid;place-items:center;padding:24px;}
    .card{width:min(96vw,860px);background:#f8fbff;border-radius:24px;box-shadow:0 24px 60px rgba(0,14,32,.35);padding:28px;}
    h1{margin:0 0 8px;color:#071120;font-size:1.8rem} p{line-height:1.5}.muted{color:#5a6b86}.box{background:var(--soft);border:1px solid var(--line);border-radius:16px;padding:16px;margin:16px 0}.btn{border:0;border-radius:14px;background:var(--accent);color:#071120;padding:13px 18px;font-weight:900;cursor:pointer}.btn:hover{filter:brightness(.94)}
    code{background:#e5f8ff;border:1px solid #b9eaff;border-radius:7px;padding:2px 6px}.ok{color:var(--ok)}.err{color:var(--err)} ul{padding-left:20px} li{margin:7px 0}.small{font-size:.9rem}.warn{background:#fff8df;border-color:#efda85}.danger{background:#fff1f2;border-color:#f0bfc5}
  </style></head><body><main class="card">';
}
function page_end(): void { echo '</main></body></html>'; }
function table_exists(PDO $pdo, string $table): bool { try { $stmt=$pdo->prepare('SHOW TABLES LIKE ?'); $stmt->execute([$table]); return (bool)$stmt->fetchColumn(); } catch(Throwable $e){ return false; } }
function column_exists(PDO $pdo, string $table, string $column): bool { try { $stmt=$pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?"); $stmt->execute([$column]); return (bool)$stmt->fetchColumn(); } catch(Throwable $e){ return false; } }
function index_exists(PDO $pdo, string $table, string $index): bool { try { $stmt=$pdo->prepare("SHOW INDEX FROM `{$table}` WHERE Key_name = ?"); $stmt->execute([$index]); return (bool)$stmt->fetchColumn(); } catch(Throwable $e){ return false; } }

function split_sql_statements(string $sql): array {
  $sql = preg_replace('/\/\*.*?\*\//s', '', $sql) ?? $sql;
  $lines = preg_split('/\R/', $sql) ?: [];
  $clean = [];
  foreach ($lines as $line) {
    $trim = trim($line);
    if ($trim === '' || str_starts_with($trim, '--') || str_starts_with($trim, '#')) continue;
    $clean[] = $line;
  }
  $sql = implode("\n", $clean);
  $statements = [];
  $buffer = '';
  $inSingle = false;
  $inDouble = false;
  $len = strlen($sql);
  for ($i = 0; $i < $len; $i++) {
    $char = $sql[$i];
    $prev = $i > 0 ? $sql[$i - 1] : '';
    if ($char === "'" && !$inDouble && $prev !== '\\') $inSingle = !$inSingle;
    if ($char === '"' && !$inSingle && $prev !== '\\') $inDouble = !$inDouble;
    if ($char === ';' && !$inSingle && !$inDouble) {
      $stmt = trim($buffer);
      if ($stmt !== '') $statements[] = $stmt;
      $buffer = '';
      continue;
    }
    $buffer .= $char;
  }
  $stmt = trim($buffer);
  if ($stmt !== '') $statements[] = $stmt;
  return $statements;
}
function run_sql_file(PDO $pdo, string $path, array &$log): void {
  if (!is_file($path)) { $log[] = ['err', 'No se encontró el archivo: ' . basename($path)]; return; }
  $sql = (string) file_get_contents($path);
  $statements = split_sql_statements($sql);
  $count = 0;
  foreach ($statements as $statement) { $pdo->exec($statement); $count++; }
  $log[] = ['ok', basename($path) . ' ejecutado correctamente (' . $count . ' instrucciones).'];
}
function ensure_column(PDO $pdo, string $table, string $column, string $definition, array &$log): void {
  if (column_exists($pdo, $table, $column)) { $log[] = ['ok', "Columna {$table}.{$column} ya existe."]; return; }
  $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN {$definition}");
  $log[] = ['ok', "Columna {$table}.{$column} creada."];
}
function ensure_index(PDO $pdo, string $table, string $index, string $definition, array &$log): void {
  if (index_exists($pdo, $table, $index)) { $log[] = ['ok', "Índice {$index} ya existe."]; return; }
  try { $pdo->exec("ALTER TABLE `{$table}` ADD {$definition}"); $log[] = ['ok', "Índice {$index} creado."]; }
  catch (Throwable $e) { $log[] = ['err', "No se pudo crear el índice {$index}: " . $e->getMessage()]; }
}
function make_legacy_column_nullable(PDO $pdo, string $table, string $column, array &$log): void {
  if (!column_exists($pdo, $table, $column)) return;
  try {
    if (in_array($column, ['message'], true)) $pdo->exec("ALTER TABLE `{$table}` MODIFY `{$column}` TEXT NULL");
    else $pdo->exec("ALTER TABLE `{$table}` MODIFY `{$column}` VARCHAR(160) NULL");
    $log[] = ['ok', "Campo anterior {$column} ajustado como opcional."];
  } catch (Throwable $e) {
    $log[] = ['err', "No se pudo ajustar el campo anterior {$column}: " . $e->getMessage()];
  }
}
function ensure_latest_schema(PDO $pdo, string $leadsTable, string $usersTable, array &$log): void {
  if (!table_exists($pdo, $usersTable)) $log[] = ['err', "La tabla {$usersTable} no existe. Revisa crear_tablas.sql."];
  if (!table_exists($pdo, $leadsTable)) { $log[] = ['err', "La tabla {$leadsTable} no existe. Revisa crear_tablas.sql."]; return; }
  if (table_exists($pdo, $usersTable)) {
    ensure_column($pdo, $usersTable, 'role', "`role` VARCHAR(30) NOT NULL DEFAULT 'super_admin'", $log);
    try {
      $pdo->exec("ALTER TABLE `{$usersTable}` MODIFY `role` VARCHAR(30) NOT NULL DEFAULT 'super_admin'");
      $pdo->exec("UPDATE `{$usersTable}` SET `role`='super_admin' WHERE `role`='admin'");
      $log[] = ['ok', 'Roles de usuarios actualizados.'];
    } catch (Throwable $e) {
      $log[] = ['err', 'No se pudieron actualizar los roles de usuarios: ' . $e->getMessage()];
    }
  }
  $columns = [
    'email' => '`email` VARCHAR(150) NULL',
    'brand_instagram' => '`brand_instagram` VARCHAR(120) NULL',
    'business_type' => '`business_type` VARCHAR(80) NULL',
    'business_type_other' => '`business_type_other` VARCHAR(120) NULL',
    'services_needed' => '`services_needed` TEXT NULL',
    'main_objective' => '`main_objective` VARCHAR(120) NULL',
    'message' => '`message` TEXT NULL',
    'source_platform' => '`source_platform` VARCHAR(80) NULL',
    'utm_source' => '`utm_source` VARCHAR(80) NULL',
    'utm_medium' => '`utm_medium` VARCHAR(80) NULL',
    'utm_campaign' => '`utm_campaign` VARCHAR(120) NULL',
    'utm_content' => '`utm_content` VARCHAR(160) NULL',
    'utm_term' => '`utm_term` VARCHAR(160) NULL',
    'ad_name' => '`ad_name` VARCHAR(180) NULL',
    'ad_id' => '`ad_id` VARCHAR(120) NULL',
    'gclid' => '`gclid` VARCHAR(180) NULL',
    'fbclid' => '`fbclid` VARCHAR(180) NULL',
    'landing_url' => '`landing_url` TEXT NULL',
    'referrer' => '`referrer` TEXT NULL',
    'sales_status' => "`sales_status` VARCHAR(50) NOT NULL DEFAULT 'nuevo_lead'",
    'notes' => '`notes` TEXT NULL',
    'reminder_at' => '`reminder_at` DATETIME NULL',
    'reminder_note' => '`reminder_note` VARCHAR(255) NULL',
    'status' => "`status` VARCHAR(32) NOT NULL DEFAULT 'pending'",
    'ip' => '`ip` VARCHAR(64) NULL',
    'user_agent' => '`user_agent` VARCHAR(255) NULL',
    'whatsapp_sent' => '`whatsapp_sent` TINYINT(1) NOT NULL DEFAULT 0',
    'whatsapp_status' => '`whatsapp_status` VARCHAR(32) NULL',
    'updated_at' => '`updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP',
  ];
  foreach ($columns as $column => $definition) ensure_column($pdo, $leadsTable, $column, $definition, $log);

  foreach (['brand_business','city_country','current_situation','budget_range','start_timeline','location_state','interest_category','sexo','age','birthdate'] as $legacyColumn) {
    make_legacy_column_nullable($pdo, $leadsTable, $legacyColumn, $log);
  }

  $indexes = [
    'idx_brand_instagram' => 'KEY `idx_brand_instagram` (`brand_instagram`)',
    'idx_business_type' => 'KEY `idx_business_type` (`business_type`)',
    'idx_main_objective' => 'KEY `idx_main_objective` (`main_objective`)',
    'idx_source_platform' => 'KEY `idx_source_platform` (`source_platform`)',
    'idx_utm_campaign' => 'KEY `idx_utm_campaign` (`utm_campaign`)',
    'idx_utm_content' => 'KEY `idx_utm_content` (`utm_content`)',
    'idx_ad_name' => 'KEY `idx_ad_name` (`ad_name`)',
    'idx_sales_status' => 'KEY `idx_sales_status` (`sales_status`)',
    'idx_reminder_at' => 'KEY `idx_reminder_at` (`reminder_at`)',
    'idx_status' => 'KEY `idx_status` (`status`)',
    'idx_created_at' => 'KEY `idx_created_at` (`created_at`)',
  ];
  foreach ($indexes as $index => $definition) ensure_index($pdo, $leadsTable, $index, $definition, $log);
}

if (!$isAuthorized) {
  http_response_code(403);
  page_start('Acceso restringido');
  echo '<h1>Acceso restringido</h1><p class="muted">Para ejecutar el instalador, abre este archivo con la clave configurada en <code>config/app.php</code>.</p><div class="box danger"><p>Ejemplo:</p><p><code>instalar_base_datos.php?key=TU_CLAVE_DE_INSTALACION</code></p><p>En producción debes cambiar la clave por defecto antes de usar este instalador.</p></div>';
  page_end();
  exit;
}

if (empty($_SESSION['install_csrf'])) $_SESSION['install_csrf'] = bin2hex(random_bytes(32));
$ran = false;
$log = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $csrf = (string) ($_POST['csrf'] ?? '');
  if (!$csrf || !hash_equals((string) $_SESSION['install_csrf'], $csrf)) $log[] = ['err', 'CSRF inválido. Recarga la página e inténtalo de nuevo.'];
  else {
    $ran = true;
    try { run_sql_file($pdo, __DIR__ . '/crear_tablas.sql', $log); ensure_latest_schema($pdo, $TABLE_LEADS, $TABLE_USERS, $log); $log[] = ['ok', 'Instalación/actualización finalizada.']; }
    catch (Throwable $e) { $log[] = ['err', 'Error durante la instalación: ' . $e->getMessage()]; }
  }
}

page_start('Instalador de base de datos');
echo '<h1>Instalador de base de datos</h1>';
echo '<p class="muted">Este script crea las tablas necesarias para el formulario reducido de diagnóstico de Pixels Studio y verifica que la tabla de leads tenga los campos de tracking y embudo comercial.</p>';
echo '<div class="box"><p><strong>Base conectada:</strong> ' . h((string) ($DB_NAME ?? '')) . '</p><p><strong>Tablas:</strong> <code>' . h((string) $TABLE_USERS) . '</code> y <code>' . h((string) $TABLE_LEADS) . '</code></p></div>';
echo '<div class="box warn"><p><strong>Importante:</strong> después de ejecutar correctamente, elimina <code>instalar_base_datos.php</code> del servidor.</p></div>';
if ($ran || $log) {
  echo '<div class="box"><h2>Resultado</h2><ul>';
  foreach ($log as [$type, $message]) echo '<li class="' . h($type) . '">' . h($message) . '</li>';
  echo '</ul></div>';
}
echo '<form method="post" action="instalar_base_datos.php?key=' . h($providedKey) . '"><input type="hidden" name="csrf" value="' . h($_SESSION['install_csrf']) . '"><button class="btn" type="submit">Crear / actualizar base de datos</button></form>';
echo '<p class="small muted">Luego entra a <code>login.php</code>. Si la tabla <code>users</code> está vacía, el sistema podrá crear el usuario administrador inicial según <code>config/app.php</code>.</p>';
page_end();
