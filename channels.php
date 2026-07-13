<?php
// channels.php
declare(strict_types=1);
require_once __DIR__ . '/auth/require_auth.php';
require_once __DIR__ . '/config/instagram_channels.php';
require_once __DIR__ . '/config/navigation.php';
require_permission('manage_integrations');

$channelsTable = ig_channels_table();
ig_channels_ensure_schema($pdo, $channelsTable);
$currentAccountId = (int) (current_account_id() ?: accounts_default_id($pdo));
$requestAccountId = accounts_request_account_id($pdo);
$scopeAccountId = $requestAccountId > 0 ? $requestAccountId : $currentAccountId;

$errors = [];
$notice = trim((string) ($_GET['notice'] ?? ''));
$instagramAppId = (string) app_config('instagram.app_id', '');
$instagramAppSecret = (string) app_config('instagram.app_secret', '');
$facebookAppId = (string) app_config('instagram.facebook_app_id', '');
$facebookAppSecret = (string) app_config('instagram.facebook_app_secret', '');
$graphVersion = (string) app_config('instagram.graph_version', 'v20.0');
if (preg_match('/^v\d+$/', trim($graphVersion))) $graphVersion = trim($graphVersion) . '.0';
if (!preg_match('/^v\d+\.\d+$/', trim($graphVersion))) $graphVersion = 'v20.0';
$configuredCallbackUrl = trim((string) app_config('instagram.oauth_redirect_uri', ''));
$fallbackCallbackUrl = ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '') . rtrim(dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '')), '/\\') . '/instagram_oauth_callback.php';
$callbackUrl = $configuredCallbackUrl !== '' ? $configuredCallbackUrl : $fallbackCallbackUrl;
$webhookUrl = ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'tu-dominio') . rtrim(dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '')), '/\\') . '/instagram_webhook.php';
$canConnectInstagram = $instagramAppId !== '' && $instagramAppSecret !== '';
$canConnectFacebook = $facebookAppId !== '' && $facebookAppSecret !== '';
$canConnect = $canConnectInstagram || $canConnectFacebook;

$connectProvider = strtolower(trim((string) ($_GET['connect'] ?? '')));
if (in_array($connectProvider, ['facebook', 'instagram'], true)) {
  $providerAppId = $connectProvider === 'facebook' ? $facebookAppId : $instagramAppId;
  $providerAppSecret = $connectProvider === 'facebook' ? $facebookAppSecret : $instagramAppSecret;
  if ($providerAppId === '' || $providerAppSecret === '') {
    $errors[] = $connectProvider === 'facebook'
      ? 'Falta configurar FACEBOOK_APP_ID y/o FACEBOOK_APP_SECRET en config/local.php.'
      : 'Falta configurar INSTAGRAM_APP_ID y/o INSTAGRAM_APP_SECRET en config/local.php.';
  } else {
    $_SESSION['instagram_oauth_state'] = bin2hex(random_bytes(24));
    $_SESSION['instagram_oauth_redirect'] = $callbackUrl;
    $_SESSION['instagram_oauth_account_id'] = $scopeAccountId;
    $_SESSION['instagram_oauth_account_slug'] = accounts_slug_for_id($pdo, $scopeAccountId);
    $_SESSION['instagram_oauth_provider'] = $connectProvider;

    if ($connectProvider === 'instagram') {
      $authUrl = 'https://www.instagram.com/oauth/authorize?' . http_build_query([
        'client_id' => $providerAppId,
        'redirect_uri' => $callbackUrl,
        'state' => $_SESSION['instagram_oauth_state'],
        'scope' => (string) app_config('instagram.direct_oauth_scopes', ''),
        'response_type' => 'code',
        'enable_fb_login' => '0',
        'force_reauth' => 'true',
      ]);
    } else {
      $authUrl = 'https://www.facebook.com/' . rawurlencode($graphVersion) . '/dialog/oauth?' . http_build_query([
        'client_id' => $providerAppId,
        'redirect_uri' => $callbackUrl,
        'state' => $_SESSION['instagram_oauth_state'],
        'scope' => (string) app_config('instagram.oauth_scopes', ''),
        'response_type' => 'code',
      ]);
    }

    if ((string) ($_GET['debug_oauth'] ?? '') === '1') {
      if (!headers_sent()) header('Content-Type: text/plain; charset=utf-8');
      echo "Proveedor: {$connectProvider}\n";
      echo "App ID: {$providerAppId}\n";
      echo "Redirect URI: {$callbackUrl}\n";
      echo "Scopes: " . ($connectProvider === 'instagram' ? (string) app_config('instagram.direct_oauth_scopes', '') : (string) app_config('instagram.oauth_scopes', '')) . "\n";
      echo "URL OAuth:\n{$authUrl}\n";
      exit;
    }

    header('Location: ' . $authUrl);
    exit;
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $csrf = $_POST['csrf'] ?? '';
  if (!$csrf || !isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], (string) $csrf)) {
    $errors[] = 'CSRF invalido. Recarga la pagina.';
  } else {
    $action = (string) ($_POST['action'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);
    if ($action === 'disconnect' && $id > 0) {
      if (is_super_admin() && $requestAccountId <= 0) {
        $stmt = $pdo->prepare("UPDATE {$channelsTable} SET is_active=0, updated_at=NOW() WHERE id=?");
        $stmt->execute([$id]);
      } else {
        $stmt = $pdo->prepare("UPDATE {$channelsTable} SET is_active=0, updated_at=NOW() WHERE id=? AND account_id=?");
        $stmt->execute([$id, $scopeAccountId]);
      }
      $notice = 'Canal desconectado.';
    } elseif ($action === 'connect' && $id > 0) {
      if (is_super_admin() && $requestAccountId <= 0) {
        $find = $pdo->prepare("SELECT * FROM {$channelsTable} WHERE id=? LIMIT 1");
        $find->execute([$id]);
      } else {
        $find = $pdo->prepare("SELECT * FROM {$channelsTable} WHERE id=? AND account_id=? LIMIT 1");
        $find->execute([$id, $scopeAccountId]);
      }
      $channel = $find->fetch();
      if (!$channel) {
        $errors[] = 'No encontramos el canal que intentas conectar.';
      } else {
        $existingInOtherAccount = ig_channel_active_in_other_account($pdo, $channelsTable, (int) $channel['account_id'], (string) $channel['page_id'], (string) $channel['instagram_user_id'], (int) $channel['id']);
        if ($existingInOtherAccount) {
          $errors[] = ig_channel_existing_account_message($existingInOtherAccount);
        } else {
          $stmt = $pdo->prepare("UPDATE {$channelsTable} SET is_active=1, connected_by=?, updated_at=NOW() WHERE id=?");
          $stmt->execute([(int) ($_SESSION['user_id'] ?? 0) ?: null, $id]);
          $notice = 'Canal conectado. Los proximos mensajes entraran al inbox.';
        }
      }
    }
  }
}

$facebookConnectUrl = $canConnect ? account_url('channels.php', ['connect' => 'facebook']) : '#';
$instagramConnectUrl = $canConnect ? account_url('channels.php', ['connect' => 'instagram']) : '#';

$channels = [];
try {
  if (is_super_admin() && $requestAccountId <= 0) {
    $stmt = $pdo->query("SELECT * FROM {$channelsTable} ORDER BY is_active DESC, updated_at DESC, created_at DESC");
  } else {
    $stmt = $pdo->prepare("SELECT * FROM {$channelsTable} WHERE account_id=? ORDER BY is_active DESC, updated_at DESC, created_at DESC");
    $stmt->execute([$scopeAccountId]);
  }
  $channels = $stmt ? $stmt->fetchAll() : [];
} catch (Throwable $e) {
  $errors[] = 'No se pudieron cargar los canales.';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover" />
  <title>Canales - Pixels Studio</title>
  <link rel="stylesheet" href="css/app.css?v=<?= (int) @filemtime(__DIR__ . '/css/app.css') ?>">
  <style>
    :root { --container-w:min(96vw, 1180px); }
    .channels-header { display:flex; justify-content:space-between; gap:16px; align-items:flex-start; flex-wrap:wrap; margin-bottom:16px; }
    .channel-actions { display:flex; gap:10px; flex-wrap:wrap; }
    .channel-link, .channel-btn { display:inline-flex; align-items:center; justify-content:center; min-height:40px; padding:0 14px; border:1px solid var(--line); border-radius:10px; color:#007ea8; background:var(--surface-soft); font-weight:850; text-decoration:none; cursor:pointer; }
    .channel-link:hover, .channel-btn:hover { background:#dff6ff; border-color:#8bdfff; }
    .channel-link.primary, .channel-btn.primary { background:#071120; border-color:#071120; color:#eafaff; }
    .channel-btn.warning { background:#fff8df; border-color:#efda85; color:#946200; }
    .channel-link.is-disabled { opacity:.55; pointer-events:none; }
    .connect-panel { display:grid; grid-template-columns:repeat(auto-fit, minmax(260px, 1fr)); gap:12px; margin-bottom:14px; }
    .connect-card { border:1px solid rgba(0,212,255,.16); border-radius:16px; background:#fff; padding:16px; box-shadow:0 8px 22px rgba(0, 76, 110, .07); }
    .connect-card h2 { margin:0 0 8px; color:var(--brand-ink); font-size:1.1rem; }
    .connect-card p { margin:6px 0 12px; color:var(--brand-muted); line-height:1.4; }
    .channel-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(260px, 1fr)); gap:14px; }
    .channel-card { border:1px solid rgba(0,212,255,.16); border-radius:16px; background:#fff; padding:16px; box-shadow:0 8px 22px rgba(0, 76, 110, .07); }
    .channel-card h2 { margin:0 0 8px; color:var(--brand-ink); font-size:1.1rem; }
    .channel-card p { margin:6px 0; color:var(--brand-muted); line-height:1.4; }
    .channel-status { display:inline-flex; align-items:center; min-height:28px; padding:0 10px; border-radius:999px; font-size:.8rem; font-weight:900; }
    .channel-status.on { background:#eef9f0; color:#217a43; border:1px solid #a8e0ba; }
    .channel-status.off { background:#f1f5f9; color:#64748b; border:1px solid #cbd5e1; }
    .notice { display:block; margin-bottom:14px; }
  </style>
</head>
<body class="dashboard-page">
  <main class="dashboard-shell">
    <section class="form-card dashboard-card">
      <div class="panel">
        <header class="channels-header">
          <div>
            <p class="eyebrow"><?= h(app_config('brand.name', 'Pixels Studio')) ?></p>
            <h1 class="title">Canales conectados</h1>
            <p class="subtitle">Conecta canales por Facebook/Fanpage o por Login directo de Instagram para capturar DMs como leads.</p>
          </div>
          <?php nav_render_config_top_nav($pdo, 'channels.php', is_super_admin() ? $requestAccountId : 0); ?>
        </header>

        <?php if ($notice !== ''): ?><div class="form-alert alert-info notice"><?= h($notice) ?></div><?php endif; ?>
        <?php foreach ($errors as $error): ?><div class="form-alert alert-error notice"><?= h($error) ?></div><?php endforeach; ?>
        <div class="admin-layout">
          <?php nav_render_admin_side_nav('channels'); ?>
          <div class="admin-content">
            <?php if (!$canConnect): ?>
              <div class="form-alert alert-error notice">Falta configurar INSTAGRAM_APP_ID y/o INSTAGRAM_APP_SECRET en config/local.php.</div>
            <?php endif; ?>

        <div class="connect-panel">
          <article class="connect-card">
            <h2>Facebook / Fanpage</h2>
            <p>Usa el flujo actual para conectar páginas de Facebook con una cuenta profesional de Instagram vinculada.</p>
            <a class="channel-link primary <?= $canConnectFacebook ? '' : 'is-disabled' ?>" href="<?= h($facebookConnectUrl) ?>">Conectar por Facebook</a>
          </article>
          <article class="connect-card">
            <h2>Instagram Login</h2>
            <p>Conecta directamente una cuenta profesional de Instagram usando los permisos de Instagram Login.</p>
            <a class="channel-link primary <?= $canConnectInstagram ? '' : 'is-disabled' ?>" href="<?= h($instagramConnectUrl) ?>">Conectar por Instagram</a>
          </article>
        </div>

        <div class="channel-card" style="margin-bottom:14px">
          <h2>Webhook de Meta</h2>
          <p><strong>URL:</strong> <?= h($webhookUrl) ?></p>
          <p><strong>Redirect OAuth:</strong> <?= h($callbackUrl) ?></p>
        </div>

        <div class="channel-grid">
          <?php if ($channels): foreach ($channels as $channel): ?>
            <article class="channel-card">
              <span class="channel-status <?= (int) $channel['is_active'] === 1 ? 'on' : 'off' ?>"><?= (int) $channel['is_active'] === 1 ? 'Activo' : 'Inactivo' ?></span>
              <h2><?= h((string) ($channel['instagram_username'] ?: 'Instagram conectado')) ?></h2>
              <p><strong>Tipo:</strong> <?= h((string) (($channel['connection_type'] ?? 'facebook') === 'instagram_login' ? 'Instagram Login' : 'Facebook / Fanpage')) ?></p>
              <?php $isDirectLogin = (string) ($channel['connection_type'] ?? 'facebook') === 'instagram_login'; ?>
              <p><strong><?= $isDirectLogin ? 'Cuenta:' : 'Fanpage:' ?></strong> <?= h((string) ($channel['page_name'] ?: $channel['page_id'])) ?></p>
              <p><strong><?= $isDirectLogin ? 'Cuenta ID:' : 'Page ID:' ?></strong> <?= h((string) $channel['page_id']) ?></p>
              <p><strong>Instagram ID:</strong> <?= h((string) $channel['instagram_user_id']) ?></p>
              <?php if (!empty($channel['token_expires_at'])): ?><p><strong>Token vence:</strong> <?= h(app_datetime($channel['token_expires_at'])) ?></p><?php endif; ?>
              <p><strong>Ultimo evento:</strong> <?= h((string) ($channel['last_event_at'] ?: 'Sin eventos')) ?></p>
              <form method="post" action="<?= h(account_url('channels.php')) ?>">
                <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf'] ?? '') ?>">
                <input type="hidden" name="id" value="<?= (int) $channel['id'] ?>">
                <?php if ((int) $channel['is_active'] === 1): ?>
                  <input type="hidden" name="action" value="disconnect">
                  <button class="channel-btn warning" type="submit">Desconectar</button>
                <?php else: ?>
                  <input type="hidden" name="action" value="connect">
                  <button class="channel-btn primary" type="submit">Conectar</button>
                <?php endif; ?>
              </form>
            </article>
          <?php endforeach; else: ?>
            <article class="channel-card">
              <h2>Sin canales conectados</h2>
              <p>Conecta Instagram para que los mensajes entrantes se creen como leads dentro del CRM.</p>
            </article>
          <?php endif; ?>
        </div>
          </div>
        </div>
      </div>
    </section>
  </main>
  <script src="js/navigation.js?v=<?= (int) @filemtime(__DIR__ . '/js/navigation.js') ?>" defer></script>
</body>
</html>
