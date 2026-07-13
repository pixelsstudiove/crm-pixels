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

function oauth_http_request(string $method, string $url, array $params = []): array {
  $method = strtoupper($method);
  $ch = curl_init();
  if ($method === 'GET') {
    if ($params) $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($params);
  } else {
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
  }
  curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 25,
  ]);
  $raw = curl_exec($ch);
  $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $error = curl_error($ch);
  curl_close($ch);
  $json = json_decode((string) $raw, true);
  if ($error !== '' || $http < 200 || $http >= 300 || !is_array($json)) {
    return ['ok' => false, 'http' => $http, 'error' => $error !== '' ? $error : ($json['error_message'] ?? $json['error']['message'] ?? 'Respuesta invalida de Meta'), 'raw' => $raw];
  }
  return ['ok' => true, 'http' => $http, 'data' => $json];
}

$state = (string) ($_GET['state'] ?? '');
$code = (string) ($_GET['code'] ?? '');
$expectedState = (string) ($_SESSION['instagram_oauth_state'] ?? '');
$redirectUri = (string) ($_SESSION['instagram_oauth_redirect'] ?? '');
$targetAccountId = (int) (($_SESSION['instagram_oauth_account_id'] ?? 0) ?: current_account_id() ?: accounts_default_id($pdo));
$targetAccountSlug = trim((string) ($_SESSION['instagram_oauth_account_slug'] ?? accounts_slug_for_id($pdo, $targetAccountId)));
$provider = strtolower(trim((string) ($_SESSION['instagram_oauth_provider'] ?? 'facebook')));
if (!in_array($provider, ['facebook', 'instagram'], true)) $provider = 'facebook';
$oauthReturnSlug = $targetAccountSlug;

unset($_SESSION['instagram_oauth_state'], $_SESSION['instagram_oauth_redirect'], $_SESSION['instagram_oauth_account_id'], $_SESSION['instagram_oauth_account_slug'], $_SESSION['instagram_oauth_provider']);

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

if ($provider === 'instagram') {
  $tokenResp = oauth_http_request('POST', 'https://api.instagram.com/oauth/access_token', [
    'client_id' => $appId,
    'client_secret' => $appSecret,
    'grant_type' => 'authorization_code',
    'redirect_uri' => $redirectUri,
    'code' => $code,
  ]);
  if (!$tokenResp['ok']) oauth_fail('Instagram no entrego el token de acceso.');

  $shortToken = (string) ($tokenResp['data']['access_token'] ?? '');
  $instagramUserId = (string) ($tokenResp['data']['user_id'] ?? $tokenResp['data']['id'] ?? '');
  if ($shortToken === '' || $instagramUserId === '') oauth_fail('Instagram no entrego una cuenta valida.');

  $accessToken = $shortToken;
  $expiresAt = null;
  $longTokenResp = oauth_http_request('GET', 'https://graph.instagram.com/access_token', [
    'grant_type' => 'ig_exchange_token',
    'client_secret' => $appSecret,
    'access_token' => $shortToken,
  ]);
  if ($longTokenResp['ok']) {
    $accessToken = (string) ($longTokenResp['data']['access_token'] ?? $accessToken);
    $expiresIn = (int) ($longTokenResp['data']['expires_in'] ?? 0);
    if ($expiresIn > 0) $expiresAt = gmdate('Y-m-d H:i:s', time() + $expiresIn);
  }

  $graphVersion = trim((string) app_config('instagram.graph_version', 'v20.0'));
  if (preg_match('/^v\d+$/', $graphVersion)) $graphVersion .= '.0';
  if (!preg_match('/^v\d+\.\d+$/', $graphVersion)) $graphVersion = 'v20.0';
  $profileResp = oauth_http_request('GET', 'https://graph.instagram.com/' . $graphVersion . '/me', [
    'fields' => 'user_id,username,name,account_type,profile_picture_url',
    'access_token' => $accessToken,
  ]);
  if (!$profileResp['ok']) {
    $profileResp = oauth_http_request('GET', 'https://graph.instagram.com/me', [
      'fields' => 'id,user_id,username',
      'access_token' => $accessToken,
    ]);
  }
  $profile = $profileResp['ok'] ? (array) ($profileResp['data'] ?? []) : [];
  $instagramUserId = (string) ($profile['user_id'] ?? $profile['id'] ?? $instagramUserId);
  $username = (string) ($profile['username'] ?? '');
  $name = (string) ($profile['name'] ?? 'Instagram Login');

  ig_channel_upsert($pdo, $channelsTable, [
    'account_id' => $targetAccountId,
    'connection_type' => 'instagram_login',
    'page_id' => $instagramUserId,
    'page_name' => $name !== '' ? $name : 'Instagram Login',
    'instagram_user_id' => $instagramUserId,
    'instagram_username' => $username,
    'page_access_token' => $accessToken,
    'token_expires_at' => $expiresAt,
    'scopes' => (string) app_config('instagram.direct_oauth_scopes', ''),
    'connected_by' => (int) ($_SESSION['user_id'] ?? 0) ?: null,
  ]);

  header('Location: ' . account_url('channels.php', ['notice' => 'Instagram conectado correctamente.'], $targetAccountSlug !== '' ? $targetAccountSlug : null));
  exit;
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
    'connection_type' => 'facebook',
    'page_id' => $pageId,
    'page_name' => (string) ($page['name'] ?? ''),
    'instagram_user_id' => (string) $ig['id'],
    'instagram_username' => (string) ($ig['username'] ?? $ig['name'] ?? ''),
    'page_access_token' => $pageToken,
    'scopes' => (string) app_config('instagram.oauth_scopes', ''),
    'connected_by' => (int) ($_SESSION['user_id'] ?? 0) ?: null,
  ]);
  $saved++;
}

if ($saved <= 0) {
  oauth_fail('No encontramos una cuenta de Instagram profesional conectada a tus paginas.');
}

header('Location: ' . account_url('channels.php', ['notice' => 'Instagram conectado correctamente.'], $targetAccountSlug !== '' ? $targetAccountSlug : null));
exit;
