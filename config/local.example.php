<?php
// Copia este archivo como config/local.php en el hosting y completa los valores reales.
return [
  'DB_HOST' => 'localhost',
  'DB_PORT' => '3306',
  'DB_NAME' => '',
  'DB_USER' => '',
  'DB_PASS' => '',
  'APP_TIMEZONE' => 'America/Caracas',
  'META_REPLY_WINDOW_HOURS' => '24',
  'META_REPLY_WARNING_HOURS' => '22',
  'META_NO_RESPONSE_THRESHOLD_HOURS' => '2',
  'META_GRAPH_VERSION' => 'v25.0',
  // Facebook/Fanpage habilita mensajes de Messenger y, si la fanpage tiene Instagram vinculado, DMs de Instagram.
  'FACEBOOK_APP_ID' => '',
  'FACEBOOK_APP_SECRET' => '',
  // Instagram Login directo habilita conexión sin pasar por fanpage.
  'INSTAGRAM_APP_ID' => '',
  'INSTAGRAM_WEBHOOK_VERIFY_TOKEN' => '',
  'INSTAGRAM_APP_SECRET' => '',
  'INSTAGRAM_OAUTH_REDIRECT_URI' => 'https://chat.pixelstudiove.com/instagram_oauth_callback.php',
  'INSTAGRAM_OAUTH_SCOPES' => 'pages_show_list,pages_manage_metadata,pages_messaging,instagram_basic,instagram_manage_messages,ads_read',
  'META_ADS_ACCESS_TOKEN' => '',
  'INSTAGRAM_DIRECT_OAUTH_SCOPES' => 'instagram_business_basic,instagram_business_manage_messages',
  'INSTAGRAM_DM_INBOX_URL' => 'https://www.instagram.com/direct/inbox/',
  'R2_ACCOUNT_ID' => '',
  'R2_ACCESS_KEY_ID' => '',
  'R2_SECRET_ACCESS_KEY' => '',
  'R2_BUCKET' => 'crm-pixels-media',
  'R2_ENDPOINT' => '',
  'R2_REGION' => 'auto',
  'R2_PUBLIC_BASE_URL' => '',
];
