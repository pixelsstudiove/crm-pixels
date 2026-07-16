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

function oauth_instagram_profile(string $accessToken): array {
  $bases = [ig_instagram_graph_base(), 'https://graph.instagram.com'];
  $fieldSets = [
    'user_id,username,name,account_type,profile_picture_url',
    'id,user_id,username,name',
  ];
  foreach ($bases as $baseUrl) {
    foreach ($fieldSets as $fields) {
      $response = ig_graph_request_base($baseUrl, 'GET', 'me', [
        'fields' => $fields,
        'access_token' => $accessToken,
      ]);
      if (($response['ok'] ?? false) && isset($response['data']) && is_array($response['data'])) return $response;
    }
  }
  return ['ok' => false, 'error' => 'No se pudo leer el perfil de Instagram.'];
}

$state = (string) ($_GET['state'] ?? '');
$code = (string) ($_GET['code'] ?? '');
$expectedState = (string) ($_SESSION['instagram_oauth_state'] ?? '');
$redirectUri = (string) ($_SESSION['instagram_oauth_redirect'] ?? '');
$targetAccountId = (int) (($_SESSION['instagram_oauth_account_id'] ?? 0) ?: current_account_id() ?: accounts_default_id($pdo));
$targetAccountSlug = trim((string) ($_SESSION['instagram_oauth_account_slug'] ?? accounts_slug_for_id($pdo, $targetAccountId)));
$provider = strtolower(trim((string) ($_SESSION['instagram_oauth_provider'] ?? 'facebook')));
if (!in_array($provider, ['facebook', 'instagram'], true)) $provider = 'facebook';
$facebookMode = strtolower(trim((string) ($_SESSION['instagram_oauth_facebook_mode'] ?? 'both')));
if (!in_array($facebookMode, ['both', 'instagram', 'messenger'], true)) $facebookMode = 'both';
$oauthReturnSlug = $targetAccountSlug;

unset($_SESSION['instagram_oauth_state'], $_SESSION['instagram_oauth_redirect'], $_SESSION['instagram_oauth_account_id'], $_SESSION['instagram_oauth_account_slug'], $_SESSION['instagram_oauth_provider'], $_SESSION['instagram_oauth_facebook_mode']);

if ($state === '' || $expectedState === '' || !hash_equals($expectedState, $state)) {
  oauth_fail('No se pudo validar la conexion con Meta.');
}
if ($code === '' || $redirectUri === '') {
  oauth_fail('Meta no envio un codigo de autorizacion valido.');
}

$appId = $provider === 'facebook'
  ? (string) app_config('instagram.facebook_app_id', '')
  : (string) app_config('instagram.app_id', '');
$appSecret = $provider === 'facebook'
  ? (string) app_config('instagram.facebook_app_secret', '')
  : (string) app_config('instagram.app_secret', '');
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
  $tokenCandidates = [
    ['token' => $shortToken, 'expires_at' => null],
  ];
  $longTokenResp = oauth_http_request('GET', 'https://graph.instagram.com/access_token', [
    'grant_type' => 'ig_exchange_token',
    'client_secret' => $appSecret,
    'access_token' => $shortToken,
  ]);
  if ($longTokenResp['ok']) {
    $longToken = (string) ($longTokenResp['data']['access_token'] ?? '');
    $expiresIn = (int) ($longTokenResp['data']['expires_in'] ?? 0);
    $longExpiresAt = $expiresIn > 0 ? gmdate('Y-m-d H:i:s', time() + $expiresIn) : null;
    if ($longToken !== '') array_unshift($tokenCandidates, ['token' => $longToken, 'expires_at' => $longExpiresAt]);
  }

  $profileResp = ['ok' => false, 'error' => 'No se pudo leer el perfil de Instagram.'];
  foreach ($tokenCandidates as $candidate) {
    $candidateToken = (string) ($candidate['token'] ?? '');
    if ($candidateToken === '') continue;
    $candidateProfile = oauth_instagram_profile($candidateToken);
    if (($candidateProfile['ok'] ?? false) && isset($candidateProfile['data']) && is_array($candidateProfile['data'])) {
      $accessToken = $candidateToken;
      $expiresAt = $candidate['expires_at'] ?? null;
      $profileResp = $candidateProfile;
      break;
    }
  }
  $profile = $profileResp['ok'] ? (array) ($profileResp['data'] ?? []) : [];
  $instagramUserId = (string) ($profile['user_id'] ?? $profile['id'] ?? $instagramUserId);
  $username = (string) ($profile['username'] ?? '');
  $name = (string) ($profile['name'] ?? 'Instagram Login');

  try {
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
      'receive_instagram' => 1,
      'receive_messenger' => 0,
      'connected_by' => (int) ($_SESSION['user_id'] ?? 0) ?: null,
    ]);
  } catch (RuntimeException $e) {
    oauth_fail($e->getMessage());
  }

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
$blocked = [];
$wantsInstagram = in_array($facebookMode, ['both', 'instagram'], true);
$wantsMessenger = in_array($facebookMode, ['both', 'messenger'], true);
foreach (($pagesResp['data']['data'] ?? []) as $page) {
  if (!is_array($page)) continue;
  $ig = $page['instagram_business_account'] ?? null;

  $pageToken = (string) ($page['access_token'] ?? '');
  $pageId = (string) ($page['id'] ?? '');
  if ($pageId === '' || $pageToken === '') continue;
  $hasInstagram = is_array($ig) && !empty($ig['id']);
  if ($wantsInstagram && !$hasInstagram && !$wantsMessenger) continue;

  // Mantiene la fanpage suscrita a la app. El CRM decide si procesa Instagram, Messenger o ambos por canal.
  ig_graph_request('POST', $pageId . '/subscribed_apps', [
    'subscribed_fields' => 'messages,messaging_postbacks,messaging_optins,message_deliveries,message_reads',
    'access_token' => $pageToken,
  ]);

  try {
    ig_channel_upsert($pdo, $channelsTable, [
      'account_id' => $targetAccountId,
      'connection_type' => 'facebook',
      'page_id' => $pageId,
      'page_name' => (string) ($page['name'] ?? ''),
      'instagram_user_id' => $hasInstagram ? (string) $ig['id'] : 'messenger:' . $pageId,
      'instagram_username' => $hasInstagram ? (string) ($ig['username'] ?? $ig['name'] ?? '') : '',
      'page_access_token' => $pageToken,
      'scopes' => (string) app_config('instagram.oauth_scopes', ''),
      'receive_instagram' => $wantsInstagram && $hasInstagram ? 1 : 0,
      'receive_messenger' => $wantsMessenger ? 1 : 0,
      'connected_by' => (int) ($_SESSION['user_id'] ?? 0) ?: null,
    ]);
    $saved++;
  } catch (RuntimeException $e) {
    $blocked[] = $e->getMessage();
  }
}

if ($saved <= 0) {
  if ($blocked) oauth_fail((string) $blocked[0]);
  oauth_fail('No encontramos paginas disponibles para conectar.');
}

$notice = $facebookMode === 'instagram'
  ? 'Canales de Instagram por Facebook conectados correctamente.'
  : ($facebookMode === 'messenger' ? 'Canales de Messenger conectados correctamente.' : 'Canales de Instagram y Messenger conectados correctamente.');
if ($blocked) $notice .= ' Algunos canales no se integraron porque ya pertenecen a otra cuenta.';
header('Location: ' . account_url('channels.php', ['notice' => $notice], $targetAccountSlug !== '' ? $targetAccountSlug : null));
exit;
