<?php
// channels.php
declare(strict_types=1);
require_once __DIR__ . '/auth/require_auth.php';
require_once __DIR__ . '/config/instagram_channels.php';
require_once __DIR__ . '/config/conversations.php';
require_once __DIR__ . '/config/lead_status_history.php';
require_once __DIR__ . '/config/navigation.php';
require_permission('manage_integrations');

$channelsTable = ig_channels_table();
ig_channels_ensure_schema($pdo, $channelsTable);
$currentAccountId = (int) (current_account_id() ?: accounts_default_id($pdo));
$requestAccountId = accounts_request_account_id($pdo);
$scopeAccountId = $requestAccountId > 0 ? $requestAccountId : $currentAccountId;
$connectAccountOptions = [];
if (is_super_admin()) {
  try {
    $stmt = $pdo->query("SELECT id, name, slug, status FROM " . accounts_table() . " ORDER BY name ASC");
    $connectAccountOptions = $stmt ? $stmt->fetchAll() : [];
  } catch (Throwable $e) {
    $connectAccountOptions = nav_fetch_account_options($pdo);
  }
}
$connectAccountId = $scopeAccountId;
$connectAccountSlug = $requestAccountId > 0 ? accounts_slug_for_id($pdo, $requestAccountId) : '';
$mustChooseConnectAccount = is_super_admin() && $requestAccountId <= 0;
if ($mustChooseConnectAccount) {
  $connectAccountId = max(0, (int) ($_GET['connect_account_id'] ?? 0));
  $connectAccountSlug = $connectAccountId > 0 ? accounts_slug_for_id($pdo, $connectAccountId) : '';
}
$selectedConnectAccount = $connectAccountId > 0 ? accounts_find($pdo, $connectAccountId) : null;
if ($mustChooseConnectAccount && (!$selectedConnectAccount || (string) ($selectedConnectAccount['status'] ?? 'active') !== 'active')) {
  $connectAccountId = 0;
  $connectAccountSlug = '';
}

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
$metaConfigStatus = [
  'facebook_app_id' => $facebookAppId !== '',
  'facebook_app_secret' => $facebookAppSecret !== '',
  'instagram_app_id' => $instagramAppId !== '',
  'instagram_app_secret' => $instagramAppSecret !== '',
  'verify_token' => trim((string) app_config('instagram.webhook_verify_token', '')) !== '',
];

function channels_find_channel(PDO $pdo, string $channelsTable, int $id, int $scopeAccountId, int $requestAccountId): ?array {
  if ($id <= 0) return null;
  if (is_super_admin() && $requestAccountId <= 0) {
    $stmt = $pdo->prepare("SELECT * FROM {$channelsTable} WHERE id=? LIMIT 1");
    $stmt->execute([$id]);
  } else {
    $stmt = $pdo->prepare("SELECT * FROM {$channelsTable} WHERE id=? AND account_id=? LIMIT 1");
    $stmt->execute([$id, $scopeAccountId]);
  }
  $channel = $stmt->fetch();
  return $channel ?: null;
}

function channels_unsubscribe_meta_app(array $channel): bool {
  $connectionType = (string) ($channel['connection_type'] ?? 'facebook');
  $pageId = trim((string) ($channel['page_id'] ?? ''));
  $token = trim((string) ($channel['page_access_token'] ?? ''));
  if ($connectionType !== 'facebook' || $pageId === '' || $token === '') return false;
  $response = ig_graph_request('DELETE', $pageId . '/subscribed_apps', [
    'access_token' => $token,
  ]);
  return (bool) ($response['ok'] ?? false);
}

function channels_int_placeholders(array $ids): string {
  return implode(',', array_fill(0, count($ids), '?'));
}

function channels_delete_by_ids(PDO $pdo, string $table, string $column, array $ids, ?int $accountId = null): int {
  $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn($id) => $id > 0)));
  if (!$ids) return 0;
  $deleted = 0;
  foreach (array_chunk($ids, 200) as $chunk) {
    $sql = "DELETE FROM {$table} WHERE {$column} IN (" . channels_int_placeholders($chunk) . ")";
    $params = $chunk;
    if ($accountId !== null && $accountId > 0) {
      $sql .= ' AND account_id=?';
      $params[] = $accountId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $deleted += $stmt->rowCount();
  }
  return $deleted;
}

function channels_delete_related_data(PDO $pdo, string $channelsTable, array $channel, string $leadsTable): array {
  conv_ensure_schema($pdo);
  lead_status_history_ensure_schema($pdo);

  $channelId = (int) ($channel['id'] ?? 0);
  $accountId = (int) ($channel['account_id'] ?? 0);
  if ($channelId <= 0 || $accountId <= 0) {
    return ['conversations' => 0, 'messages' => 0, 'attachments' => 0, 'leads' => 0, 'history' => 0, 'logs' => 0, 'contacts' => 0];
  }

  $conversationsTable = conv_conversations_table();
  $messagesTable = conv_messages_table();
  $attachmentsTable = conv_attachments_table();
  $contactsTable = conv_contacts_table();
  $logsTable = conv_webhook_logs_table();
  $historyTable = lead_status_history_table();
  $leadsTable = safe_identifier($leadsTable, 'leads');
  $channelsTable = safe_identifier($channelsTable, 'instagram_channels');

  $conversationStmt = $pdo->prepare("SELECT id, contact_id, lead_id FROM {$conversationsTable} WHERE channel_id=? AND account_id=?");
  $conversationStmt->execute([$channelId, $accountId]);
  $rows = $conversationStmt->fetchAll();
  $conversationIds = [];
  $contactIds = [];
  $leadIds = [];
  foreach ($rows as $row) {
    $conversationIds[] = (int) ($row['id'] ?? 0);
    $contactIds[] = (int) ($row['contact_id'] ?? 0);
    $leadIds[] = (int) ($row['lead_id'] ?? 0);
  }
  $conversationIds = array_values(array_unique(array_filter($conversationIds)));
  $contactIds = array_values(array_unique(array_filter($contactIds)));
  $leadIds = array_values(array_unique(array_filter($leadIds)));

  $counts = ['conversations' => 0, 'messages' => 0, 'attachments' => 0, 'leads' => 0, 'history' => 0, 'logs' => 0, 'contacts' => 0];
  $pdo->beginTransaction();
  try {
    if ($conversationIds) {
      $counts['attachments'] = channels_delete_by_ids($pdo, $attachmentsTable, 'conversation_id', $conversationIds, $accountId);
      $counts['messages'] = channels_delete_by_ids($pdo, $messagesTable, 'conversation_id', $conversationIds, $accountId);
      $counts['logs'] += channels_delete_by_ids($pdo, $logsTable, 'conversation_id', $conversationIds, $accountId);
      if ($leadIds) {
        $counts['logs'] += channels_delete_by_ids($pdo, $logsTable, 'lead_id', $leadIds, $accountId);
        $counts['history'] = channels_delete_by_ids($pdo, $historyTable, 'lead_id', $leadIds, $accountId);
        $counts['leads'] = channels_delete_by_ids($pdo, $leadsTable, 'id', $leadIds, $accountId);
      }
      $counts['conversations'] = channels_delete_by_ids($pdo, $conversationsTable, 'id', $conversationIds, $accountId);

      foreach (array_chunk($contactIds, 200) as $chunk) {
        $sql = "DELETE ct FROM {$contactsTable} ct
                LEFT JOIN {$conversationsTable} c ON c.contact_id = ct.id
                WHERE ct.id IN (" . channels_int_placeholders($chunk) . ")
                  AND ct.account_id=?
                  AND c.id IS NULL";
        $params = array_merge($chunk, [$accountId]);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $counts['contacts'] += $stmt->rowCount();
      }
    }
    $channelLogStmt = $pdo->prepare("DELETE FROM {$logsTable} WHERE channel_id=? AND account_id=?");
    $channelLogStmt->execute([$channelId, $accountId]);
    $counts['logs'] += $channelLogStmt->rowCount();
    $channelStmt = $pdo->prepare("DELETE FROM {$channelsTable} WHERE id=? AND account_id=?");
    $channelStmt->execute([$channelId, $accountId]);

    $pdo->commit();
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
  }

  return $counts;
}

function channels_pending_meta_key(array $channel): string {
  return (string) ($channel['pending_key'] ?? hash('sha256', implode('|', [
    (string) ($channel['connection_type'] ?? ''),
    (string) ($channel['page_id'] ?? ''),
    (string) ($channel['instagram_user_id'] ?? ''),
    (string) ($channel['receive_instagram'] ?? ''),
    (string) ($channel['receive_messenger'] ?? ''),
  ])));
}

function channels_pending_meta_payload(): ?array {
  $pending = $_SESSION['meta_pending_channels'] ?? null;
  if (!is_array($pending)) return null;
  $createdAt = (int) ($pending['created_at'] ?? 0);
  if ($createdAt <= 0 || time() - $createdAt > 1800) {
    unset($_SESSION['meta_pending_channels']);
    return null;
  }
  $channels = $pending['channels'] ?? [];
  if (!is_array($channels) || !$channels) {
    unset($_SESSION['meta_pending_channels']);
    return null;
  }
  return $pending;
}

function channels_pending_meta_label(array $channel): string {
  $types = [];
  if (!empty($channel['receive_instagram'])) $types[] = 'Instagram';
  if (!empty($channel['receive_messenger'])) $types[] = 'Messenger';
  return $types ? implode(' + ', $types) : 'Canal Meta';
}

$connectProvider = strtolower(trim((string) ($_GET['connect'] ?? '')));
if (in_array($connectProvider, ['facebook', 'instagram'], true)) {
  $facebookMode = strtolower(trim((string) ($_GET['mode'] ?? 'both')));
  if (!in_array($facebookMode, ['both', 'instagram', 'messenger'], true)) $facebookMode = 'both';
  $providerAppId = $connectProvider === 'facebook' ? $facebookAppId : $instagramAppId;
  $providerAppSecret = $connectProvider === 'facebook' ? $facebookAppSecret : $instagramAppSecret;
  $requestedTypes = $connectProvider === 'instagram'
    ? ['instagram']
    : ($facebookMode === 'both' ? ['instagram', 'messenger'] : [$facebookMode]);
  if ($mustChooseConnectAccount && $connectAccountId <= 0) {
    $errors[] = 'Selecciona la cuenta cliente a la que quieres asignar este nuevo canal antes de conectar con Meta.';
  } elseif ($providerAppId === '' || $providerAppSecret === '') {
    $errors[] = $connectProvider === 'facebook'
      ? 'Falta configurar FACEBOOK_APP_ID y/o FACEBOOK_APP_SECRET en config/local.php.'
      : 'Falta configurar INSTAGRAM_APP_ID y/o INSTAGRAM_APP_SECRET en config/local.php.';
  } elseif (!accounts_channel_types_allowed($selectedConnectAccount ?: [], $requestedTypes)) {
    $errors[] = 'Esta cuenta no tiene permitido conectar: ' . implode(', ', accounts_channel_types_denied($selectedConnectAccount ?: [], $requestedTypes)) . '.';
  } elseif (!accounts_can_add_channel($pdo, $connectAccountId)) {
    $errors[] = accounts_limit_error($pdo, $connectAccountId, 'channels');
  } else {
    $_SESSION['instagram_oauth_state'] = bin2hex(random_bytes(24));
    $_SESSION['instagram_oauth_redirect'] = $callbackUrl;
    $_SESSION['instagram_oauth_account_id'] = $connectAccountId;
    $_SESSION['instagram_oauth_account_slug'] = $connectAccountSlug !== '' ? $connectAccountSlug : accounts_slug_for_id($pdo, $connectAccountId);
    $_SESSION['instagram_oauth_provider'] = $connectProvider;
    $_SESSION['instagram_oauth_facebook_mode'] = $connectProvider === 'facebook' ? $facebookMode : 'instagram';

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
      if ($connectProvider === 'facebook') echo "Modo Facebook: {$facebookMode}\n";
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
	    if ($action === 'confirm_meta_channels') {
	      $pending = channels_pending_meta_payload();
	      $selectedKeys = $_POST['pending_channels'] ?? [];
	      $selectedKeys = is_array($selectedKeys) ? array_values(array_unique(array_map('strval', $selectedKeys))) : [];
	      if (!$pending) {
	        $errors[] = 'La confirmación de Meta expiró. Inicia la conexión nuevamente.';
	      } elseif (!$selectedKeys) {
	        $errors[] = 'Selecciona al menos un canal para conectar.';
	      } else {
	        $saved = 0;
	        $blocked = [];
	        foreach (($pending['channels'] ?? []) as $pendingChannel) {
	          if (!is_array($pendingChannel)) continue;
	          $pendingKey = channels_pending_meta_key($pendingChannel);
	          if (!in_array($pendingKey, $selectedKeys, true)) continue;
	          try {
	            if ((string) ($pendingChannel['connection_type'] ?? '') === 'facebook') {
	              $subscribeResp = ig_graph_request('POST', (string) ($pendingChannel['page_id'] ?? '') . '/subscribed_apps', [
	                'subscribed_fields' => 'messages,messaging_postbacks,messaging_optins,message_deliveries,message_reads',
	                'access_token' => (string) ($pendingChannel['page_access_token'] ?? ''),
	              ]);
	              if (!($subscribeResp['ok'] ?? false)) {
	                throw new RuntimeException('Meta no permitió suscribir la fanpage al webhook.');
	              }
	            }
	            ig_channel_upsert($pdo, $channelsTable, $pendingChannel);
	            $saved++;
	          } catch (RuntimeException $e) {
	            $blocked[] = $e->getMessage();
	          }
	        }
	        if ($saved > 0) {
	          unset($_SESSION['meta_pending_channels']);
	          $notice = $saved === 1 ? 'Canal conectado correctamente.' : "{$saved} canales conectados correctamente.";
	          if ($blocked) $notice .= ' Algunos canales no se integraron porque ya pertenecen a otra cuenta o exceden los límites.';
	        } else {
	          $errors[] = $blocked ? (string) $blocked[0] : 'No se pudo conectar ninguno de los canales seleccionados.';
	        }
	      }
	    } elseif ($action === 'cancel_meta_channels') {
	      unset($_SESSION['meta_pending_channels']);
	      $notice = 'Conexión cancelada. No se agregó ningún canal al CRM.';
	    } elseif ($action === 'toggle' && $id > 0) {
	      $channel = channels_find_channel($pdo, $channelsTable, $id, $scopeAccountId, $requestAccountId);
	      if (!$channel) {
	        $errors[] = 'No encontramos el canal que intentas actualizar.';
	      } else {
	        $nextActive = (int) ($_POST['is_active'] ?? 0) === 1 ? 1 : 0;
	        if ($nextActive === 1) {
	          $existingInOtherAccount = ig_channel_active_in_other_account($pdo, $channelsTable, (int) $channel['account_id'], (string) $channel['page_id'], (string) $channel['instagram_user_id'], (int) $channel['id']);
	          if ($existingInOtherAccount) {
	            $errors[] = ig_channel_existing_account_message($existingInOtherAccount);
	            $nextActive = (int) ($channel['is_active'] ?? 0);
	          }
	        }
	        if (!$errors) {
	          $stmt = $pdo->prepare("UPDATE {$channelsTable} SET is_active=?, connected_by=?, updated_at=NOW() WHERE id=?");
	          $stmt->execute([$nextActive, (int) ($_SESSION['user_id'] ?? 0) ?: null, $id]);
	          $notice = $nextActive === 1 ? 'Canal activado. Los proximos mensajes entraran al inbox.' : 'Canal pausado. Puedes activarlo nuevamente cuando quieras.';
	        }
	      }
	    } elseif ($action === 'disconnect' && $id > 0) {
	      $channel = channels_find_channel($pdo, $channelsTable, $id, $scopeAccountId, $requestAccountId);
	      if (!$channel) {
	        $errors[] = 'No encontramos el canal que intentas desconectar.';
	      } else {
	        $unsubscribed = channels_unsubscribe_meta_app($channel);
	        $cleanup = channels_delete_related_data($pdo, $channelsTable, $channel, $TABLE_LEADS);
	        $cleanupSummary = sprintf(
	          ' Se eliminaron %d conversaciones y %d leads relacionados.',
	          (int) ($cleanup['conversations'] ?? 0),
	          (int) ($cleanup['leads'] ?? 0)
	        );
	        $notice = $unsubscribed
	          ? 'Canal desconectado de Meta y eliminado del CRM. Para usarlo de nuevo debes iniciar sesion otra vez.' . $cleanupSummary
	          : 'Canal eliminado del CRM. Si Meta no permitio revocar la suscripcion, reconecta el canal o revisa la app en Meta.' . $cleanupSummary;
	      }
	    }
	  }
}

$pendingMetaChannels = channels_pending_meta_payload();
$connectAccountParam = $mustChooseConnectAccount && $connectAccountId > 0 ? $connectAccountId : null;
$connectLinkEnabled = $canConnect && (!$mustChooseConnectAccount || $connectAccountId > 0);
$connectBaseSlug = $requestAccountId > 0 ? null : '';
$canAddChannelToSelected = $connectLinkEnabled && $connectAccountId > 0 && accounts_can_add_channel($pdo, $connectAccountId);
$facebookBothAllowed = $canConnectFacebook && $canAddChannelToSelected && accounts_channel_types_allowed($selectedConnectAccount ?: [], ['instagram', 'messenger']);
$facebookInstagramAllowed = $canConnectFacebook && $canAddChannelToSelected && accounts_channel_types_allowed($selectedConnectAccount ?: [], ['instagram']);
$facebookMessengerAllowed = $canConnectFacebook && $canAddChannelToSelected && accounts_channel_types_allowed($selectedConnectAccount ?: [], ['messenger']);
$instagramDirectAllowed = $canConnectInstagram && $canAddChannelToSelected && accounts_channel_types_allowed($selectedConnectAccount ?: [], ['instagram']);
$facebookConnectBothUrl = $facebookBothAllowed ? account_url('channels.php', ['connect' => 'facebook', 'mode' => 'both', 'connect_account_id' => $connectAccountParam], $connectBaseSlug) : '#';
$facebookConnectInstagramUrl = $facebookInstagramAllowed ? account_url('channels.php', ['connect' => 'facebook', 'mode' => 'instagram', 'connect_account_id' => $connectAccountParam], $connectBaseSlug) : '#';
$facebookConnectMessengerUrl = $facebookMessengerAllowed ? account_url('channels.php', ['connect' => 'facebook', 'mode' => 'messenger', 'connect_account_id' => $connectAccountParam], $connectBaseSlug) : '#';
$instagramConnectUrl = $instagramDirectAllowed ? account_url('channels.php', ['connect' => 'instagram', 'connect_account_id' => $connectAccountParam], $connectBaseSlug) : '#';

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
    .channel-link, .channel-btn { display:inline-flex; align-items:center; justify-content:center; gap:8px; min-height:40px; padding:0 14px; border:1px solid var(--line); border-radius:10px; color:#007ea8; background:var(--surface-soft); font-weight:850; text-decoration:none; cursor:pointer; }
    .channel-link:hover, .channel-btn:hover { background:#dff6ff; border-color:#8bdfff; }
    .channel-link.primary, .channel-btn.primary { background:#071120; border-color:#071120; color:#eafaff; }
    .channel-btn.warning { background:#fff8df; border-color:#efda85; color:#946200; }
    .channel-btn.danger { background:#fff1f2; border-color:#fecdd3; color:#be123c; }
    .channel-link.is-disabled,
    .channel-link.primary.is-disabled {
      color:#64748b;
      background:#e5e7eb;
      border-color:#cbd5e1;
      box-shadow:none;
      cursor:not-allowed;
      pointer-events:none;
    }
    .channel-link.is-disabled .channel-choice-icon { filter:grayscale(1); opacity:.62; }
    .connect-actions { display:flex; flex-wrap:wrap; gap:8px; }
    .connect-actions .channel-link { min-height:36px; font-size:.9rem; }
    .channel-choice-icon { width:22px; height:22px; border-radius:999px; object-fit:cover; flex:0 0 auto; }
    .connect-panel { display:grid; grid-template-columns:repeat(auto-fit, minmax(260px, 1fr)); gap:12px; margin-bottom:14px; }
    .connect-card { border:1px solid rgba(0,212,255,.16); border-radius:16px; background:#fff; padding:16px; box-shadow:0 8px 22px rgba(0, 76, 110, .07); }
    .connect-card h2 { margin:0 0 8px; color:var(--brand-ink); font-size:1.1rem; }
    .connect-card p { margin:6px 0 12px; color:var(--brand-muted); line-height:1.4; }
    .meta-confirm-card { margin-bottom:14px; border:1px solid rgba(0,212,255,.22); border-radius:18px; background:#fff; padding:18px; box-shadow:0 16px 38px rgba(15, 23, 42, .06); }
    .meta-confirm-head { display:flex; justify-content:space-between; gap:14px; align-items:flex-start; margin-bottom:14px; }
    .meta-confirm-head h2 { margin:0 0 6px; color:var(--brand-ink); font-size:1.15rem; letter-spacing:-.02em; }
    .meta-confirm-head p { margin:0; color:var(--brand-muted); line-height:1.45; font-weight:750; }
    .meta-confirm-list { display:grid; gap:10px; margin-bottom:14px; }
    .meta-confirm-option { display:grid; grid-template-columns:auto 1fr; gap:12px; align-items:flex-start; padding:14px; border:1px solid var(--line); border-radius:14px; background:#f8fcff; }
    .meta-confirm-option input { width:18px!important; height:18px!important; min-height:18px!important; margin-top:4px; accent-color:#071120; }
    .meta-confirm-title { display:flex; gap:8px; align-items:center; flex-wrap:wrap; color:var(--brand-ink); font-weight:950; }
    .meta-confirm-tag { display:inline-flex; align-items:center; min-height:24px; padding:0 9px; border-radius:999px; background:#071120; color:#fff; font-size:.76rem; font-weight:900; }
    .meta-confirm-meta { margin:4px 0 0; color:var(--brand-muted); font-weight:800; line-height:1.45; }
    .meta-confirm-actions { display:flex; gap:10px; justify-content:flex-end; flex-wrap:wrap; }
    .connect-account-card { margin-bottom:14px; border:1px solid rgba(0,212,255,.18); border-radius:16px; background:#f8fcff; padding:16px; box-shadow:0 8px 22px rgba(0, 76, 110, .06); }
    .connect-account-form { display:grid; grid-template-columns:minmax(220px, 360px) 1fr; gap:12px; align-items:end; }
    .connect-account-form label { display:grid; gap:6px; color:var(--brand-ink); font-weight:900; }
    .connect-account-form select { width:100%; min-height:44px; border:1px solid var(--line); border-radius:12px; padding:0 42px 0 12px; color:var(--brand-ink); background:#fff; font-weight:800; }
    .connect-account-help { margin:0; color:var(--brand-muted); line-height:1.45; }
    .channel-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(260px, 1fr)); gap:14px; }
    .channel-card { border:1px solid rgba(0,212,255,.16); border-radius:16px; background:#fff; padding:16px; box-shadow:0 8px 22px rgba(0, 76, 110, .07); }
    .channel-card-head { display:flex; justify-content:space-between; gap:12px; align-items:flex-start; margin-bottom:10px; }
    .channel-toggle-form { margin:0; }
    .channel-toggle { position:relative; display:inline-flex; align-items:center; gap:8px; min-height:32px; padding:0 10px 0 36px; border:1px solid #cbd5e1; border-radius:999px; background:#f8fafc; color:#64748b; font-weight:900; cursor:pointer; }
    .channel-toggle::before { content:""; position:absolute; left:7px; width:20px; height:20px; border-radius:999px; background:#94a3b8; transition:.18s ease; }
    .channel-toggle.is-on { border-color:#a8e0ba; background:#eef9f0; color:#217a43; }
    .channel-toggle.is-on::before { background:#22c55e; transform:translateX(0); }
    .channel-card h2 { margin:0 0 8px; color:var(--brand-ink); font-size:1.1rem; }
    .channel-card p { margin:6px 0; color:var(--brand-muted); line-height:1.4; }
    .channel-status { display:inline-flex; align-items:center; min-height:28px; padding:0 10px; border-radius:999px; font-size:.8rem; font-weight:900; }
    .channel-status.on { background:#eef9f0; color:#217a43; border:1px solid #a8e0ba; }
    .channel-status.off { background:#f1f5f9; color:#64748b; border:1px solid #cbd5e1; }
    .notice { display:block; margin-bottom:14px; }
    @media (max-width: 760px) {
      .connect-account-form { grid-template-columns:1fr; }
      .meta-confirm-head { display:grid; }
      .meta-confirm-actions { display:grid; grid-template-columns:1fr; }
      .meta-confirm-actions .channel-btn { width:100%; }
    }
  </style>
</head>
<body class="dashboard-page config-page">
  <main class="dashboard-shell">
    <section class="form-card dashboard-card">
      <div class="panel">
        <header class="channels-header">
          <div>
            <p class="eyebrow"><?= h(app_config('brand.name', 'Pixels Studio')) ?></p>
            <h1 class="title">Canales conectados</h1>
            <p class="subtitle">Conecta canales por Facebook/Fanpage o por Login directo de Instagram para capturar mensajes como leads.</p>
          </div>
          <?php nav_render_config_top_nav($pdo, 'channels.php', is_super_admin() ? $requestAccountId : 0); ?>
        </header>

        <?php if ($notice !== ''): ?><div class="form-alert alert-info notice"><?= h($notice) ?></div><?php endif; ?>
        <?php foreach ($errors as $error): ?><div class="form-alert alert-error notice"><?= h($error) ?></div><?php endforeach; ?>
        <div class="admin-layout">
          <?php nav_render_admin_side_nav('channels'); ?>
          <div class="admin-content">
            <?php if ($pendingMetaChannels): ?>
              <?php
                $pendingAccountId = (int) ($pendingMetaChannels['account_id'] ?? 0);
                $pendingAccount = $pendingAccountId > 0 ? accounts_find($pdo, $pendingAccountId) : null;
                $pendingChannels = array_values(array_filter((array) ($pendingMetaChannels['channels'] ?? []), 'is_array'));
              ?>
              <article class="meta-confirm-card">
                <div class="meta-confirm-head">
                  <div>
                    <h2>Confirma los canales seleccionados en Meta</h2>
                    <p>Solo se guardarán los activos que marques aquí. Esta revisión evita conectar cuentas que Meta pueda devolver por permisos anteriores.</p>
                  </div>
                  <?php if ($pendingAccount): ?>
                    <span class="meta-confirm-tag"><?= h((string) ($pendingAccount['name'] ?? 'Cuenta')) ?></span>
                  <?php endif; ?>
                </div>
                <form method="post" action="<?= h(account_url('channels.php')) ?>">
                  <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf'] ?? '') ?>">
                  <div class="meta-confirm-list">
                    <?php foreach ($pendingChannels as $pendingChannel): ?>
                      <?php
                        $pendingKey = channels_pending_meta_key($pendingChannel);
                        $pendingLabel = channels_pending_meta_label($pendingChannel);
                        $pendingIgUsername = trim((string) ($pendingChannel['instagram_username'] ?? ''));
                        $pendingPageName = trim((string) ($pendingChannel['page_name'] ?? ''));
                      ?>
                      <label class="meta-confirm-option">
                        <input type="checkbox" name="pending_channels[]" value="<?= h($pendingKey) ?>" checked>
                        <span>
                          <span class="meta-confirm-title">
                            <?= h($pendingIgUsername !== '' ? '@' . ltrim($pendingIgUsername, '@') : ($pendingPageName !== '' ? $pendingPageName : 'Canal Meta')) ?>
                            <span class="meta-confirm-tag"><?= h($pendingLabel) ?></span>
                          </span>
                          <span class="meta-confirm-meta">
                            Fanpage: <?= h($pendingPageName !== '' ? $pendingPageName : (string) ($pendingChannel['page_id'] ?? '')) ?>
                            · Page ID: <?= h((string) ($pendingChannel['page_id'] ?? '')) ?>
                            <?php if (!empty($pendingChannel['receive_instagram'])): ?>
                              · Instagram ID: <?= h((string) ($pendingChannel['instagram_user_id'] ?? '')) ?>
                            <?php endif; ?>
                          </span>
                        </span>
                      </label>
                    <?php endforeach; ?>
                  </div>
                  <div class="meta-confirm-actions">
                    <button class="channel-btn" type="submit" name="action" value="cancel_meta_channels">Cancelar conexión</button>
                    <button class="channel-btn primary" type="submit" name="action" value="confirm_meta_channels">Guardar canales seleccionados</button>
                  </div>
                </form>
              </article>
            <?php endif; ?>
            <?php if (!$canConnect): ?>
              <div class="form-alert alert-error notice">Falta configurar credenciales de Meta en config/local.php. Para Messenger usa FACEBOOK_APP_ID y FACEBOOK_APP_SECRET; para Instagram Login usa INSTAGRAM_APP_ID e INSTAGRAM_APP_SECRET.</div>
            <?php endif; ?>

        <?php if ($mustChooseConnectAccount): ?>
          <article class="connect-account-card">
            <form class="connect-account-form" method="get" action="<?= h(account_url('channels.php', [], '')) ?>">
              <label>
                Cuenta destino del nuevo canal
                <select name="connect_account_id" onchange="this.form.submit()">
                  <option value="">Selecciona una cuenta</option>
                  <?php foreach ($connectAccountOptions as $account): ?>
                    <?php $accountStatus = (string) ($account['status'] ?? 'active'); ?>
                    <option value="<?= (int) $account['id'] ?>" <?= $connectAccountId === (int) $account['id'] ? 'selected' : '' ?> <?= $accountStatus !== 'active' ? 'disabled' : '' ?>>
                      <?= h((string) ($account['name'] ?? 'Cuenta')) ?><?= $accountStatus !== 'active' ? ' (inactiva)' : '' ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </label>
              <p class="connect-account-help">
                Como estás viendo todas las cuentas, primero elige a qué cliente se asignará el canal. Esa cuenta quedará guardada durante el login de Meta.
              </p>
            </form>
          </article>
        <?php endif; ?>

        <div class="connect-panel">
          <article class="connect-card">
            <h2>Facebook / Fanpage</h2>
            <p>Conecta páginas de Facebook y elige si quieres recibir Instagram, Messenger o ambos desde esa fanpage.</p>
            <div class="connect-actions">
              <a class="channel-link primary <?= $facebookBothAllowed ? '' : 'is-disabled' ?>" href="<?= h($facebookConnectBothUrl) ?>" <?= $facebookBothAllowed ? '' : 'aria-disabled="true"' ?>><img class="channel-choice-icon" src="/images/icon_instagram.png" alt="" aria-hidden="true"><img class="channel-choice-icon" src="/images/icon_messenger.png" alt="" aria-hidden="true"> Instagram + Messenger</a>
              <a class="channel-link <?= $facebookInstagramAllowed ? '' : 'is-disabled' ?>" href="<?= h($facebookConnectInstagramUrl) ?>" <?= $facebookInstagramAllowed ? '' : 'aria-disabled="true"' ?>><img class="channel-choice-icon" src="/images/icon_instagram.png" alt="" aria-hidden="true"> Solo Instagram</a>
              <a class="channel-link <?= $facebookMessengerAllowed ? '' : 'is-disabled' ?>" href="<?= h($facebookConnectMessengerUrl) ?>" <?= $facebookMessengerAllowed ? '' : 'aria-disabled="true"' ?>><img class="channel-choice-icon" src="/images/icon_messenger.png" alt="" aria-hidden="true"> Solo Messenger</a>
            </div>
          </article>
          <article class="connect-card">
            <h2>Instagram Login</h2>
            <p>Conecta directamente una cuenta profesional de Instagram usando los permisos de Instagram Login.</p>
            <a class="channel-link primary <?= $instagramDirectAllowed ? '' : 'is-disabled' ?>" href="<?= h($instagramConnectUrl) ?>" <?= $instagramDirectAllowed ? '' : 'aria-disabled="true"' ?>><img class="channel-choice-icon" src="/images/icon_instagram.png" alt="" aria-hidden="true"> Conectar por Instagram</a>
          </article>
        </div>

        <div class="channel-card" style="margin-bottom:14px">
          <h2>Webhook de Meta</h2>
          <p><strong>URL Instagram/Messenger:</strong> <?= h($webhookUrl) ?></p>
          <p><strong>Redirect OAuth:</strong> <?= h($callbackUrl) ?></p>
          <p><strong>Facebook App ID:</strong> <?= $metaConfigStatus['facebook_app_id'] ? 'Configurado' : 'Falta configurar' ?></p>
          <p><strong>Facebook App Secret:</strong> <?= $metaConfigStatus['facebook_app_secret'] ? 'Configurado' : 'Falta configurar' ?></p>
          <p><strong>Verify Token:</strong> <?= $metaConfigStatus['verify_token'] ? 'Configurado' : 'Falta configurar' ?></p>
        </div>

        <div class="channel-grid">
          <?php if ($channels): foreach ($channels as $channel): ?>
            <article class="channel-card">
              <?php $isChannelActive = (int) $channel['is_active'] === 1; ?>
              <div class="channel-card-head">
                <span class="channel-status <?= $isChannelActive ? 'on' : 'off' ?>"><?= $isChannelActive ? 'Activo' : 'Inactivo' ?></span>
                <form class="channel-toggle-form" method="post" action="<?= h(account_url('channels.php')) ?>">
                  <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf'] ?? '') ?>">
                  <input type="hidden" name="id" value="<?= (int) $channel['id'] ?>">
                  <input type="hidden" name="action" value="toggle">
                  <input type="hidden" name="is_active" value="<?= $isChannelActive ? 0 : 1 ?>">
                  <button class="channel-toggle <?= $isChannelActive ? 'is-on' : '' ?>" type="submit" aria-label="<?= $isChannelActive ? 'Pausar canal' : 'Activar canal' ?>">
                    <?= $isChannelActive ? 'Activo' : 'Pausado' ?>
                  </button>
                </form>
              </div>
              <?php $hasInstagramChannel = !str_starts_with((string) ($channel['instagram_user_id'] ?? ''), 'messenger:'); ?>
              <h2><?= h((string) ($channel['instagram_username'] ?: ($channel['page_name'] ?: 'Canal Meta conectado'))) ?></h2>
              <p><strong>Tipo:</strong> <?= h((string) (($channel['connection_type'] ?? 'facebook') === 'instagram_login' ? 'Instagram Login' : 'Facebook / Fanpage')) ?></p>
              <?php $isDirectLogin = (string) ($channel['connection_type'] ?? 'facebook') === 'instagram_login'; ?>
              <p><strong><?= $isDirectLogin ? 'Cuenta:' : 'Fanpage:' ?></strong> <?= h((string) ($channel['page_name'] ?: $channel['page_id'])) ?></p>
              <p><strong><?= $isDirectLogin ? 'Cuenta ID:' : 'Page ID:' ?></strong> <?= h((string) $channel['page_id']) ?></p>
              <p><strong>Instagram ID:</strong> <?= $hasInstagramChannel ? h((string) $channel['instagram_user_id']) : '—' ?></p>
              <p><strong>Recibe:</strong>
                <?= !empty($channel['receive_instagram']) ? 'Instagram' : '' ?><?= !empty($channel['receive_instagram']) && !empty($channel['receive_messenger']) ? ' + ' : '' ?><?= !empty($channel['receive_messenger']) ? 'Messenger' : '' ?><?= empty($channel['receive_instagram']) && empty($channel['receive_messenger']) ? '—' : '' ?>
              </p>
              <?php if (!empty($channel['token_expires_at'])): ?><p><strong>Token vence:</strong> <?= h(app_datetime($channel['token_expires_at'])) ?></p><?php endif; ?>
              <p><strong>Ultimo evento:</strong> <?= h((string) ($channel['last_event_at'] ?: 'Sin eventos')) ?></p>
              <form method="post" action="<?= h(account_url('channels.php')) ?>">
                <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf'] ?? '') ?>">
                <input type="hidden" name="id" value="<?= (int) $channel['id'] ?>">
                <input type="hidden" name="action" value="disconnect">
                <button class="channel-btn danger" type="submit" onclick="return confirm('Esto eliminara la conexion del canal con el CRM, sus chats del inbox y sus leads del embudo. Para volver a usarlo deberas iniciar sesion nuevamente en Meta. ¿Deseas continuar?')">Desconectar canal</button>
              </form>
            </article>
          <?php endforeach; else: ?>
            <article class="channel-card">
              <h2>Sin canales conectados</h2>
              <p>Conecta Facebook o Instagram para que los mensajes entrantes se creen como leads dentro del CRM.</p>
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
