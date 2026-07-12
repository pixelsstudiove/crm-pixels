<?php
// instagram_oauth_callback.php
declare(strict_types=1);
require_once __DIR__ . '/auth/require_auth.php';
require_once __DIR__ . '/config/instagram_channels.php';
require_permission('manage_integrations');

$channelsTable = ig_channels_table();
ig_channels_ensure_schema($pdo, $channelsTable);

function oauth_fail(string $message): void {
  global $oauthReturnSlug;
  $slug = trim((string) ($oauthReturnSlug ?? ($_SESSION['instagram_oauth_account_slug'] ?? '')));
  header('Location: ' . account_url('channels.php', ['notice' => $message], $slug !== '' ? $slug : null));
  exit;
}

$state = (string) ($_GET['state'] ?? '');
$code = (string) ($_GET['code'] ?? '');
$expectedState = (string) ($_SESSION['instagram_oauth_state'] ?? '');
$redirectUri = (string) ($_SESSION['instagram_oauth_redirect'] ?? '');
$targetAccountId = (int) (($_SESSION['instagram_oauth_account_id'] ?? 0) ?: current_account_id() ?: accounts_default_id($pdo));
$targetAccountSlug = trim((string) ($_SESSION['instagram_oauth_account_slug'] ?? accounts_slug_for_id($pdo, $targetAccountId)));
$oauthReturnSlug = $targetAccountSlug;

unset($_SESSION['instagram_oauth_state'], $_SESSION['instagram_oauth_redirect'], $_SESSION['instagram_oauth_account_id'], $_SESSION['instagram_oauth_account_slug']);

if ($state === '' || $expectedState === '' || !hash_equals($expectedState, $state)) {
  oauth_fail('No se pudo validar la conexion con Meta.');
}
if ($code === '' || $redirectUri === '') {
  oauth_fail('Meta no envio un codigo de autorizacion valido.');
}

$appId = (string) app_config('instagram.app_id', '');
$appSecret = (string) app_config('instagram.app_secret', '');
if ($appId === '' || $appSecret === '') {
  oauth_fail('Falta configurar App ID o App Secret de Meta.');
}

$tokenResp = ig_graph_request('GET', 'oauth/access_token', [
  'client_id' => $appId,
  'client_secret' => $appSecret,
  'redirect_uri' => $redirectUri,
  'code' => $code,
]);
if (!$tokenResp['ok']) oauth_fail('Meta no entrego el token de acceso.');
$userToken = (string) ($tokenResp['data']['access_token'] ?? '');
if ($userToken === '') oauth_fail('Token de Meta vacio.');

$pagesResp = ig_graph_request('GET', 'me/accounts', [
  'fields' => 'id,name,access_token,instagram_business_account{id,username,name}',
  'access_token' => $userToken,
]);
if (!$pagesResp['ok']) oauth_fail('No se pudieron leer las paginas conectadas.');

$saved = 0;
foreach (($pagesResp['data']['data'] ?? []) as $page) {
  if (!is_array($page)) continue;
  $ig = $page['instagram_business_account'] ?? null;
  if (!is_array($ig) || empty($ig['id'])) continue;

  $pageToken = (string) ($page['access_token'] ?? '');
  $pageId = (string) ($page['id'] ?? '');
  if ($pageId === '' || $pageToken === '') continue;

  // Intenta suscribir la pagina a la app. Si ya estaba suscrita, Meta responde OK o no bloquea la conexion local.
  ig_graph_request('POST', $pageId . '/subscribed_apps', [
    'subscribed_fields' => 'messages,messaging_postbacks,messaging_optins,message_deliveries,message_reads',
    'access_token' => $pageToken,
  ]);

  ig_channel_upsert($pdo, $channelsTable, [
    'account_id' => $targetAccountId,
    'page_id' => $pageId,
    'page_name' => (string) ($page['name'] ?? ''),
    'instagram_user_id' => (string) $ig['id'],
    'instagram_username' => (string) ($ig['username'] ?? $ig['name'] ?? ''),
    'page_access_token' => $pageToken,
    'connected_by' => (int) ($_SESSION['user_id'] ?? 0) ?: null,
  ]);
  $saved++;
}

if ($saved <= 0) {
  oauth_fail('No encontramos una cuenta de Instagram profesional conectada a tus paginas.');
}

header('Location: ' . account_url('channels.php', ['notice' => 'Instagram conectado correctamente.'], $targetAccountSlug !== '' ? $targetAccountSlug : null));
exit;
