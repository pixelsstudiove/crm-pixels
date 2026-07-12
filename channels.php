<?php
// channels.php
declare(strict_types=1);
require_once __DIR__ . '/auth/require_auth.php';
require_once __DIR__ . '/config/instagram_channels.php';
require_permission('manage_integrations');

$channelsTable = ig_channels_table();
ig_channels_ensure_schema($pdo, $channelsTable);
$currentAccountId = (int) (current_account_id() ?: accounts_default_id($pdo));

$errors = [];
$notice = trim((string) ($_GET['notice'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $csrf = $_POST['csrf'] ?? '';
  if (!$csrf || !isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], (string) $csrf)) {
    $errors[] = 'CSRF invalido. Recarga la pagina.';
  } else {
    $action = (string) ($_POST['action'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);
    if ($action === 'disconnect' && $id > 0) {
      if (is_super_admin()) {
        $stmt = $pdo->prepare("UPDATE {$channelsTable} SET is_active=0, updated_at=NOW() WHERE id=?");
        $stmt->execute([$id]);
      } else {
        $stmt = $pdo->prepare("UPDATE {$channelsTable} SET is_active=0, updated_at=NOW() WHERE id=? AND account_id=?");
        $stmt->execute([$id, $currentAccountId]);
      }
      $notice = 'Canal desconectado.';
    } elseif ($action === 'connect' && $id > 0) {
      if (is_super_admin()) {
        $stmt = $pdo->prepare("UPDATE {$channelsTable} SET is_active=1, connected_by=?, updated_at=NOW() WHERE id=?");
        $stmt->execute([(int) ($_SESSION['user_id'] ?? 0) ?: null, $id]);
      } else {
        $stmt = $pdo->prepare("UPDATE {$channelsTable} SET is_active=1, connected_by=?, updated_at=NOW() WHERE id=? AND account_id=?");
        $stmt->execute([(int) ($_SESSION['user_id'] ?? 0) ?: null, $id, $currentAccountId]);
      }
      $notice = 'Canal conectado. Los proximos mensajes entraran al inbox.';
    }
  }
}

$appId = (string) app_config('instagram.app_id', '');
$appSecret = (string) app_config('instagram.app_secret', '');
$callbackUrl = ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '') . rtrim(dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '')), '/\\') . '/instagram_oauth_callback.php';
$canConnect = $appId !== '' && $appSecret !== '';

if ($canConnect) {
  $_SESSION['instagram_oauth_state'] = bin2hex(random_bytes(24));
  $_SESSION['instagram_oauth_redirect'] = $callbackUrl;
  $authUrl = 'https://www.facebook.com/' . rawurlencode((string) app_config('instagram.graph_version', 'v20.0')) . '/dialog/oauth?' . http_build_query([
    'client_id' => $appId,
    'redirect_uri' => $callbackUrl,
    'state' => $_SESSION['instagram_oauth_state'],
    'scope' => (string) app_config('instagram.oauth_scopes', ''),
    'response_type' => 'code',
  ]);
} else {
  $authUrl = '#';
}

$channels = [];
try {
  if (is_super_admin()) {
    $stmt = $pdo->query("SELECT * FROM {$channelsTable} ORDER BY is_active DESC, updated_at DESC, created_at DESC");
  } else {
    $stmt = $pdo->prepare("SELECT * FROM {$channelsTable} WHERE account_id=? ORDER BY is_active DESC, updated_at DESC, created_at DESC");
    $stmt->execute([$currentAccountId]);
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
            <p class="subtitle">Conecta la fanpage e Instagram del cliente para capturar DMs como leads.</p>
          </div>
          <div class="channel-actions">
            <a class="channel-link" href="dashboard.php">Dashboard</a>
            <?php if (can('view_conversations')): ?><a class="channel-link" href="inbox.php">Inbox</a><?php endif; ?>
            <a class="channel-link" href="webhook_logs.php">Eventos</a>
            <a class="channel-link primary <?= $canConnect ? '' : 'is-disabled' ?>" href="<?= h($authUrl) ?>">Conectar Instagram</a>
          </div>
        </header>

        <?php if ($notice !== ''): ?><div class="form-alert alert-info notice"><?= h($notice) ?></div><?php endif; ?>
        <?php foreach ($errors as $error): ?><div class="form-alert alert-error notice"><?= h($error) ?></div><?php endforeach; ?>
        <?php if (!$canConnect): ?>
          <div class="form-alert alert-error notice">Falta configurar INSTAGRAM_APP_ID y/o INSTAGRAM_APP_SECRET en config/local.php.</div>
        <?php endif; ?>

        <div class="channel-card" style="margin-bottom:14px">
          <h2>Webhook de Meta</h2>
          <p><strong>URL:</strong> <?= h(((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'tu-dominio') . rtrim(dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '')), '/\\') . '/instagram_webhook.php') ?></p>
          <p><strong>Redirect OAuth:</strong> <?= h($callbackUrl) ?></p>
        </div>

        <div class="channel-grid">
          <?php if ($channels): foreach ($channels as $channel): ?>
            <article class="channel-card">
              <span class="channel-status <?= (int) $channel['is_active'] === 1 ? 'on' : 'off' ?>"><?= (int) $channel['is_active'] === 1 ? 'Activo' : 'Inactivo' ?></span>
              <h2><?= h((string) ($channel['instagram_username'] ?: 'Instagram conectado')) ?></h2>
              <p><strong>Fanpage:</strong> <?= h((string) ($channel['page_name'] ?: $channel['page_id'])) ?></p>
              <p><strong>Page ID:</strong> <?= h((string) $channel['page_id']) ?></p>
              <p><strong>Instagram ID:</strong> <?= h((string) $channel['instagram_user_id']) ?></p>
              <p><strong>Ultimo evento:</strong> <?= h((string) ($channel['last_event_at'] ?: 'Sin eventos')) ?></p>
              <form method="post" action="channels.php">
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
    </section>
  </main>
</body>
</html>
