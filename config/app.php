<?php
// config/app.php
// Configuración centralizada del formulario de captación de leads.
declare(strict_types=1);

if (!function_exists('env_value')) {
  function env_value(string $key, $default = null) {
    static $localConfig = null;
    if ($localConfig === null) {
      $localPath = __DIR__ . '/local.php';
      $loaded = is_file($localPath) ? require $localPath : [];
      $localConfig = is_array($loaded) ? $loaded : [];
    }

    $value = getenv($key);
    if ($value !== false && $value !== '') return $value;
    return array_key_exists($key, $localConfig) && $localConfig[$key] !== '' ? $localConfig[$key] : $default;
  }
}

$app = [
  'brand' => [
    'name' => 'Pixels Studio',
    'developer' => 'Pixels Studio',
    'logo_path' => 'images/logo.png',
    'logo_alt' => 'Logo de Pixels Studio',
  ],

  'ui' => [
    'page_title' => 'Diagnóstico gratuito – Pixels Studio',
    'login_title' => 'Acceso – Pixels Studio',
    'dashboard_title' => 'Dashboard – Pixels Studio',
    'form_heading' => 'Diagnóstico gratuito para marcas',
    'form_subtitle' => 'Cuéntanos qué necesitas y nuestro equipo te contactará para recomendarte la mejor estrategia digital.',
    'form_button' => 'Solicitar diagnóstico',
    'form_success' => '✅ Solicitud recibida.<br>El equipo de Pixels Studio revisará tu información y te contactará pronto.',
    'dashboard_heading' => 'Leads comerciales',
    'dashboard_subtitle' => 'Registros recibidos desde el formulario de diagnóstico comercial.',
  ],

  'database' => [
    'leads_table' => 'leads',
    'users_table' => 'users',
    'instagram_channels_table' => 'instagram_channels',
    'conversation_contacts_table' => 'conversation_contacts',
    'conversations_table' => 'conversations',
    'conversation_messages_table' => 'conversation_messages',
    'conversation_attachments_table' => 'conversation_attachments',
    'webhook_event_logs_table' => 'webhook_event_logs',
  ],

  'session' => [
    'name' => 'pixels_lead_capture_sess',
  ],

  'phone' => [
    'country_code' => '58',
  ],

  'lead_fields' => [
    'business_type' => [
      'label' => 'Tipo de negocio',
      'placeholder' => 'Selecciona el tipo de negocio',
      'options' => [
        'restaurante_comida' => 'Restaurante / comida',
        'tienda_fisica' => 'Tienda física',
        'marca_productos' => 'Marca de productos',
        'servicio_profesional' => 'Servicio profesional',
        'salud_estetica' => 'Salud / estética',
        'eventos_entretenimiento' => 'Eventos / entretenimiento',
        'inmobiliaria' => 'Inmobiliaria',
        'automotriz' => 'Automotriz',
        'educacion' => 'Educación',
        'otro' => 'Otro',
      ],
    ],
    'services_needed' => [
      'label' => 'Servicio que necesitas',
      'options' => [
        'redes_sociales' => 'Manejo de redes sociales',
        'produccion_audiovisual' => 'Producción audiovisual',
        'diseno_grafico' => 'Diseño gráfico',
        'branding' => 'Branding / identidad visual',
        'campanas_publicitarias' => 'Campañas publicitarias',
        'pagina_web_landing' => 'Página web / landing page',
        'automatizacion_crm' => 'Automatización / WhatsApp / CRM',
        'fotografia_producto' => 'Fotografía de producto',
        'cobertura_eventos' => 'Cobertura de eventos',
        'asesoria' => 'No estoy seguro, necesito asesoría',
      ],
    ],
    'main_objective' => [
      'label' => 'Objetivo principal',
      'placeholder' => 'Selecciona tu objetivo principal',
      'options' => [
        'conseguir_mas_clientes' => 'Quiero conseguir más clientes',
        'mejorar_imagen' => 'Quiero mejorar la imagen de mi marca',
        'vender_mas_redes' => 'Quiero vender más por redes sociales',
        'lanzar_marca' => 'Quiero lanzar una marca nueva',
        'promocionar_evento' => 'Quiero promocionar un evento',
        'crear_contenido' => 'Quiero crear contenido profesional',
        'automatizar_atencion' => 'Quiero automatizar la atención de clientes',
        'ordenar_presencia_digital' => 'Quiero ordenar mi presencia digital',
      ],
    ],
  ],

  'sales_funnel' => [
    'default_status' => 'nuevo_lead',
    'statuses' => [
      'nuevo_lead' => 'Nuevo lead',
      'contactado' => 'Contactado',
      'en_conversacion' => 'En conversación',
      'interesado' => 'Interesado',
      'propuesta_enviada' => 'Propuesta enviada',
      'en_seguimiento' => 'En seguimiento',
      'cliente_ganado' => 'Cliente ganado',
      'cliente_perdido' => 'Cliente perdido',
      'no_responde' => 'No responde',
    ],
  ],

  'roles' => [
    'default' => 'asesor',
    'legacy_map' => [
      'admin' => 'super_admin',
    ],
    'profiles' => [
      'super_admin' => [
        'label' => 'Super administrador',
        'description' => 'Control total del CRM, usuarios, leads y configuracion.',
        'permissions' => ['view_dashboard', 'edit_leads', 'manage_users', 'manage_integrations', 'view_reports', 'view_conversations', 'send_messages', 'manage_conversations'],
      ],
      'admin_comercial' => [
        'label' => 'Administrador comercial',
        'description' => 'Gestiona leads, conversaciones, embudo, recordatorios y metricas comerciales.',
        'permissions' => ['view_dashboard', 'edit_leads', 'view_reports', 'view_conversations', 'send_messages', 'manage_conversations'],
      ],
      'asesor' => [
        'label' => 'Asesor comercial',
        'description' => 'Da seguimiento a leads y conversaciones, cambia status, agrega notas y recordatorios.',
        'permissions' => ['view_dashboard', 'edit_leads', 'view_conversations', 'send_messages'],
      ],
      'lectura' => [
        'label' => 'Solo lectura',
        'description' => 'Consulta el dashboard e inbox sin modificar leads ni conversaciones.',
        'permissions' => ['view_dashboard', 'view_conversations'],
      ],
    ],
  ],

  'whatsapp' => [
    'enabled' => filter_var(env_value('EVO_ENABLED', 'false'), FILTER_VALIDATE_BOOL),
    'base_url' => (string) env_value('EVO_BASE', ''),
    'instance' => (string) env_value('EVO_INSTANCE', ''),
    'apikey' => (string) env_value('EVO_APIKEY', ''),
    'delay_ms' => 400,
    'message_template' => "¡Hola {fullname}! 👋\nRecibimos tu solicitud de diagnóstico en {brand_name}.\nInstagram: {brand_instagram}.\nServicio: {services_needed}.\nNuestro equipo te contactará pronto.",
  ],

  'instagram' => [
    'app_id' => (string) env_value('INSTAGRAM_APP_ID', env_value('META_APP_ID', '')),
    'webhook_verify_token' => (string) env_value('INSTAGRAM_WEBHOOK_VERIFY_TOKEN', ''),
    'app_secret' => (string) env_value('INSTAGRAM_APP_SECRET', ''),
    'dm_inbox_url' => (string) env_value('INSTAGRAM_DM_INBOX_URL', 'https://www.instagram.com/direct/inbox/'),
    'graph_version' => (string) env_value('META_GRAPH_VERSION', 'v20.0'),
    'oauth_scopes' => (string) env_value('INSTAGRAM_OAUTH_SCOPES', 'pages_show_list,pages_manage_metadata,pages_messaging,instagram_basic,instagram_manage_messages'),
    'default_business_type' => 'Instagram DM',
    'default_service' => 'Mensaje directo de Instagram',
    'default_objective' => 'Conversación iniciada desde Instagram',
  ],

  'media' => [
    'max_upload_bytes' => (int) env_value('MEDIA_MAX_UPLOAD_BYTES', '8388608'),
    'allowed_image_mimes' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
    'allowed_audio_mimes' => ['audio/mpeg', 'audio/mp3', 'audio/mp4', 'audio/m4a', 'audio/x-m4a', 'audio/aac', 'audio/ogg', 'audio/wav', 'audio/x-wav', 'audio/wave', 'audio/vnd.wave', 'audio/webm', 'audio/3gpp'],
  ],

  'r2' => [
    'account_id' => (string) env_value('R2_ACCOUNT_ID', ''),
    'access_key_id' => (string) env_value('R2_ACCESS_KEY_ID', ''),
    'secret_access_key' => (string) env_value('R2_SECRET_ACCESS_KEY', ''),
    'bucket' => (string) env_value('R2_BUCKET', ''),
    'endpoint' => (string) env_value('R2_ENDPOINT', ''),
    'region' => (string) env_value('R2_REGION', 'auto'),
    'public_base_url' => (string) env_value('R2_PUBLIC_BASE_URL', ''),
  ],

  'security' => [
    'allow_default_admin_seed' => filter_var(env_value('ALLOW_DEFAULT_ADMIN_SEED', in_array($_SERVER['SERVER_NAME'] ?? '', ['localhost', '127.0.0.1', '::1'], true) ? 'true' : 'false'), FILTER_VALIDATE_BOOL),
    'default_admin_user' => (string) env_value('DEFAULT_ADMIN_USER', 'admin'),
    'default_admin_pass' => (string) env_value('DEFAULT_ADMIN_PASS', 'CambiaEstaClave#2026'),
    'install_key' => (string) env_value('INSTALL_KEY', 'pixels-install-2026'),
    'login_max_attempts' => 5,
    'login_window_seconds' => 900,
    'login_lock_seconds' => 900,
    'lead_max_submits' => 20,
    'lead_window_seconds' => 3600,
  ],
];

if (!function_exists('app_config')) {
  function app_config(?string $key = null, $default = null) {
    global $app;
    if ($key === null || $key === '') return $app;
    $segments = explode('.', $key);
    $value = $app;
    foreach ($segments as $segment) {
      if (!is_array($value) || !array_key_exists($segment, $value)) {
        return $default;
      }
      $value = $value[$segment];
    }
    return $value;
  }
}

if (!function_exists('h')) {
  function h($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
  }
}

if (!function_exists('role_profiles')) {
  function role_profiles(): array {
    return (array) app_config('roles.profiles', []);
  }
}

if (!function_exists('normalize_role')) {
  function normalize_role($role): string {
    $role = trim((string) $role);
    $legacyMap = (array) app_config('roles.legacy_map', []);
    if (isset($legacyMap[$role])) $role = (string) $legacyMap[$role];
    $profiles = role_profiles();
    if (isset($profiles[$role])) return $role;
    $default = (string) app_config('roles.default', 'asesor');
    return isset($profiles[$default]) ? $default : (array_key_first($profiles) ?: 'asesor');
  }
}

if (!function_exists('role_label')) {
  function role_label($role): string {
    $role = normalize_role($role);
    return (string) app_config('roles.profiles.' . $role . '.label', $role);
  }
}

if (!function_exists('current_user_role')) {
  function current_user_role(): string {
    return normalize_role($_SESSION['role'] ?? app_config('roles.default', 'asesor'));
  }
}

if (!function_exists('current_user_permissions')) {
  function current_user_permissions(): array {
    $role = current_user_role();
    $permissions = app_config('roles.profiles.' . $role . '.permissions', []);
    return is_array($permissions) ? $permissions : [];
  }
}

if (!function_exists('can')) {
  function can(string $permission): bool {
    return in_array($permission, current_user_permissions(), true);
  }
}

if (!function_exists('require_permission')) {
  function require_permission(string $permission): void {
    if (can($permission)) return;
    if (!headers_sent()) {
      header('Content-Type: text/html; charset=utf-8');
      http_response_code(403);
    }
    echo 'No tienes permiso para acceder a esta seccion.';
    exit;
  }
}

if (!function_exists('safe_identifier')) {
  function safe_identifier(string $identifier, string $fallback): string {
    $clean = preg_replace('/[^a-zA-Z0-9_]/', '', $identifier);
    return $clean !== '' ? $clean : $fallback;
  }
}

if (!function_exists('security_headers')) {
  function security_headers(): void {
    if (PHP_SAPI === 'cli' || headers_sent()) return;
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
  }
}

security_headers();
