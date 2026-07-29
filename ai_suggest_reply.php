<?php
declare(strict_types=1);

require_once __DIR__ . '/auth/require_auth.php';
require_once __DIR__ . '/config/conversations.php';
require_once __DIR__ . '/config/lead_status_history.php';
require_once __DIR__ . '/config/ai_knowledge.php';

function ai_suggest_json(array $payload, int $status = 200): void {
  http_response_code($status);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
  exit;
}

function ai_suggest_clean_text($value, int $max = 1000): string {
  $text = trim(str_replace("\0", '', (string) $value));
  if ($text === '') return '';
  if (function_exists('mb_substr')) return mb_substr($text, 0, $max, 'UTF-8');
  return substr($text, 0, $max);
}

function ai_suggest_message_public_text(array $message): string {
  $text = ai_suggest_clean_text($message['message_text'] ?? '', 1200);
  if ($text !== '') return $text;

  $type = strtolower(trim((string) ($message['message_type'] ?? '')));
  if ($type === 'image') return '[imagen]';
  if ($type === 'audio') return '[audio]';
  if ($type === 'video') return '[video]';
  if ($type === 'attachment') return '[adjunto]';
  if ($type === 'unsupported' || $type === 'unsupported_type') return '[mensaje no soportado]';
  return '[mensaje sin texto]';
}

function ai_suggest_extract_output_text(array $data): string {
  $direct = ai_suggest_clean_text($data['output_text'] ?? '', 4000);
  if ($direct !== '') return $direct;

  $parts = [];
  foreach ((array) ($data['output'] ?? []) as $output) {
    foreach ((array) ($output['content'] ?? []) as $content) {
      $text = ai_suggest_clean_text($content['text'] ?? '', 4000);
      if ($text !== '') $parts[] = $text;
    }
  }

  return trim(implode("\n", $parts));
}

function ai_suggest_log_error(string $message, array $context = []): void {
  $dir = __DIR__ . '/storage';
  if (!is_dir($dir)) {
    @mkdir($dir, 0775, true);
  }
  $line = json_encode([
    'at' => gmdate('c'),
    'message' => $message,
    'context' => $context,
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
  @file_put_contents($dir . '/ai_suggest_errors.log', $line . PHP_EOL, FILE_APPEND);
}

function ai_suggest_sales_variant(): array {
  $variants = [
    [
      'name' => 'diagnostico_consultivo',
      'focus' => 'Detecta necesidad, uso esperado, urgencia y presupuesto sin sonar interrogatorio.',
      'cta' => 'Cierra con una pregunta concreta que ayude a cotizar o recomendar mejor.',
    ],
    [
      'name' => 'avance_a_cotizacion',
      'focus' => 'Orienta la conversacion hacia cotizacion, disponibilidad, medidas, modelo o siguiente paso comercial.',
      'cta' => 'Cierra pidiendo el dato minimo necesario para avanzar.',
    ],
    [
      'name' => 'manejo_de_objecion',
      'focus' => 'Responde con seguridad, reduce dudas y refuerza valor sin inventar informacion.',
      'cta' => 'Cierra ofreciendo una alternativa o una validacion rapida.',
    ],
    [
      'name' => 'cierre_suave',
      'focus' => 'Mantiene tono cercano y empuja una accion simple: confirmar, reservar, enviar datos o agendar.',
      'cta' => 'Cierra con una pregunta de si/no o una accion facil de responder.',
    ],
    [
      'name' => 'seguimiento_activo',
      'focus' => 'Retoma la conversacion con contexto, evita presionar y crea urgencia razonable.',
      'cta' => 'Cierra con una opcion concreta para no dejar morir el lead.',
    ],
  ];

  try {
    return $variants[random_int(0, count($variants) - 1)];
  } catch (Throwable $e) {
    return $variants[array_rand($variants)];
  }
}

function ai_suggest_match_normalize($value): string {
  $text = ai_suggest_clean_text($value, 7000);
  if ($text === '') return '';

  $text = strtr($text, [
    'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'Á' => 'a', 'À' => 'a', 'Ä' => 'a', 'Â' => 'a',
    'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e', 'É' => 'e', 'È' => 'e', 'Ë' => 'e', 'Ê' => 'e',
    'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i', 'Í' => 'i', 'Ì' => 'i', 'Ï' => 'i', 'Î' => 'i',
    'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o', 'Ó' => 'o', 'Ò' => 'o', 'Ö' => 'o', 'Ô' => 'o',
    'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u', 'Ú' => 'u', 'Ù' => 'u', 'Ü' => 'u', 'Û' => 'u',
    'ñ' => 'n', 'Ñ' => 'n',
  ]);
  $text = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
  $text = preg_replace('/[^\pL\pN]+/u', ' ', $text) ?: $text;
  return trim(preg_replace('/\s+/u', ' ', $text) ?: $text);
}

function ai_suggest_keyword_tokens(string $text): array {
  $text = ai_suggest_match_normalize($text);
  if ($text === '') return [];

  $stopwords = array_flip([
    'para', 'pero', 'como', 'con', 'por', 'que', 'del', 'las', 'los', 'una', 'uno', 'unos', 'unas',
    'este', 'esta', 'esto', 'ese', 'esa', 'eso', 'aqui', 'alla', 'hola', 'buenas', 'buenos',
    'gracias', 'quiero', 'quisiera', 'necesito', 'puede', 'pueden', 'tiene', 'tienen', 'sobre',
    'desde', 'donde', 'cuando', 'cual', 'cuales', 'cliente', 'mensaje', 'instagram', 'whatsapp',
  ]);

  $tokens = [];
  foreach (preg_split('/\s+/u', $text) ?: [] as $token) {
    $token = trim((string) $token);
    $length = function_exists('mb_strlen') ? mb_strlen($token, 'UTF-8') : strlen($token);
    if ($length < 3 || isset($stopwords[$token])) continue;
    $tokens[$token] = true;
  }

  return array_keys($tokens);
}

function ai_suggest_detect_intent_keywords(string $searchText): array {
  $normalized = ai_suggest_match_normalize($searchText);
  $groups = [
    'ubicacion' => [
      'ubicacion', 'direccion', 'direcciones', 'sede', 'sedes', 'sucursal', 'sucursales',
      'tienda', 'tiendas', 'local', 'locales', 'queda', 'quedan', 'ubicado', 'ubicada',
      'ubicados', 'ubicadas', 'llegar', 'visitar', 'visito', 'mapa',
    ],
    'horario' => ['horario', 'hora', 'abren', 'abierto', 'atienden', 'atencion', 'cerrado', 'cierran'],
    'precio' => ['precio', 'cuanto', 'costo', 'valor', 'presupuesto', 'cotizar', 'cotizacion'],
    'pago' => ['pago', 'pagar', 'zelle', 'transferencia', 'punto', 'efectivo', 'divisas', 'bcv'],
    'envio' => ['envio', 'delivery', 'despacho', 'entrega', 'enviar', 'recibir', 'domicilio'],
    'garantia' => ['garantia', 'cambio', 'devolucion', 'reclamo'],
  ];

  $keywords = [];
  foreach ($groups as $groupKeywords) {
    foreach ($groupKeywords as $keyword) {
      if (preg_match('/\b' . preg_quote($keyword, '/') . '\b/u', $normalized)) {
        $keywords = array_merge($keywords, $groupKeywords);
        break;
      }
    }
  }

  return array_values(array_unique($keywords));
}

function ai_suggest_knowledge_search_text(array $conversation, array $recentMessages, string $draft): string {
  $parts = [
    $draft,
    (string) ($conversation['lead_notes'] ?? ''),
    (string) ($conversation['campaign_name'] ?? ''),
    (string) ($conversation['adset_name'] ?? ''),
    (string) ($conversation['ad_name'] ?? ''),
  ];

  $inbound = array_values(array_filter($recentMessages, static fn($message) => ($message['role'] ?? '') === 'cliente'));
  foreach (array_slice(array_reverse($inbound), 0, 5) as $message) {
    $parts[] = (string) ($message['text'] ?? '');
  }

  return implode(' ', array_filter($parts, static fn($part) => trim((string) $part) !== ''));
}

function ai_suggest_score_knowledge_item(array $item, string $searchText, array $tokens, array $intentKeywords): int {
  $title = ai_suggest_match_normalize($item['title'] ?? '');
  $category = ai_suggest_match_normalize($item['category'] ?? '');
  $response = ai_suggest_match_normalize($item['response_text'] ?? '');
  $haystack = trim($title . ' ' . $category . ' ' . $response);
  if ($haystack === '') return 0;

  $score = 0;
  foreach ($tokens as $token) {
    if (preg_match('/\b' . preg_quote($token, '/') . '\b/u', $title)) $score += 5;
    if (preg_match('/\b' . preg_quote($token, '/') . '\b/u', $category)) $score += 3;
    if (preg_match('/\b' . preg_quote($token, '/') . '\b/u', $response)) $score += 2;
  }

  foreach ($intentKeywords as $keyword) {
    if (preg_match('/\b' . preg_quote($keyword, '/') . '\b/u', $haystack)) $score += 8;
  }

  if (in_array('ubicacion', $intentKeywords, true)) {
    $locationMarkers = ['avenida', 'av', 'calle', 'centro comercial', 'cc', 'local', 'sede', 'tienda', 'valencia', 'naguanagua'];
    foreach ($locationMarkers as $marker) {
      if (strpos($haystack, $marker) !== false) $score += 4;
    }
  }

  if ($searchText !== '' && strpos($haystack, ai_suggest_match_normalize($searchText)) !== false) {
    $score += 10;
  }

  return $score;
}

function ai_suggest_select_relevant_knowledge(array $items, array $conversation, array $recentMessages, string $draft, int $limit = 12): array {
  if (!$items) return [];

  $searchText = ai_suggest_knowledge_search_text($conversation, $recentMessages, $draft);
  $tokens = ai_suggest_keyword_tokens($searchText);
  $intentKeywords = ai_suggest_detect_intent_keywords($searchText);
  $scored = [];

  foreach ($items as $index => $item) {
    $score = ai_suggest_score_knowledge_item($item, $searchText, $tokens, $intentKeywords);
    $scored[] = [
      'score' => $score,
      'index' => $index,
      'item' => $item,
    ];
  }

  usort($scored, static function ($a, $b): int {
    if ($a['score'] !== $b['score']) return $b['score'] <=> $a['score'];
    $usageA = (int) ($a['item']['usage_count'] ?? 0);
    $usageB = (int) ($b['item']['usage_count'] ?? 0);
    if ($usageA !== $usageB) return $usageA <=> $usageB;
    return ((int) ($b['item']['id'] ?? 0)) <=> ((int) ($a['item']['id'] ?? 0));
  });

  $selected = [];
  foreach ($scored as $row) {
    if ((int) $row['score'] <= 0 && count($selected) >= 4) continue;
    $item = $row['item'];
    $selected[] = [
      'id' => (int) ($item['id'] ?? 0),
      'title' => ai_suggest_clean_text($item['title'] ?? '', 140),
      'response' => ai_suggest_clean_text($item['response_text'] ?? '', 1200),
      'category' => ai_suggest_clean_text($item['category'] ?? '', 80),
      'relevance_score' => (int) $row['score'],
    ];
    if (count($selected) >= $limit) break;
  }

  return $selected;
}

function ai_suggest_required_knowledge(array $approvedKnowledge): array {
  $top = $approvedKnowledge[0] ?? null;
  if (!is_array($top)) {
    return [
      'required' => false,
      'reason' => '',
      'title' => '',
      'response' => '',
      'relevance_score' => 0,
    ];
  }

  $score = (int) ($top['relevance_score'] ?? 0);
  if ($score < 18) {
    return [
      'required' => false,
      'reason' => '',
      'title' => '',
      'response' => '',
      'relevance_score' => $score,
    ];
  }

  return [
    'required' => true,
    'reason' => 'El cliente pregunto algo que coincide con conocimiento aprobado de la cuenta. Debes responder usando este dato de forma directa.',
    'title' => ai_suggest_clean_text($top['title'] ?? '', 140),
    'response' => ai_suggest_clean_text($top['response'] ?? '', 1400),
    'relevance_score' => $score,
  ];
}

function ai_suggest_required_knowledge_tokens(string $text): array {
  $tokens = ai_suggest_keyword_tokens($text);
  $important = [];
  foreach ($tokens as $token) {
    $length = function_exists('mb_strlen') ? mb_strlen($token, 'UTF-8') : strlen($token);
    if ($length < 5) continue;
    $important[$token] = true;
  }
  return array_keys($important);
}

function ai_suggest_reply_uses_required_knowledge(string $reply, array $requiredKnowledge): bool {
  if (empty($requiredKnowledge['required'])) return true;

  $source = ai_suggest_clean_text($requiredKnowledge['response'] ?? '', 1600);
  if ($source === '') return true;

  $replyNormalized = ai_suggest_match_normalize($reply);
  $tokens = ai_suggest_required_knowledge_tokens($source);
  if (!$tokens) return true;

  $matches = 0;
  foreach ($tokens as $token) {
    if (preg_match('/\b' . preg_quote($token, '/') . '\b/u', $replyNormalized)) {
      $matches++;
    }
  }

  return $matches >= min(3, max(1, (int) ceil(count($tokens) * 0.18)));
}

function ai_suggest_direct_knowledge_reply(array $context): string {
  $required = is_array($context['required_knowledge'] ?? null) ? $context['required_knowledge'] : [];
  $knowledge = ai_suggest_clean_text($required['response'] ?? '', 1600);
  if ($knowledge === '') return '';

  $name = ai_suggest_clean_text($context['contact']['name'] ?? '', 80);
  $nameParts = preg_split('/\s+/u', trim($name));
  $firstName = is_array($nameParts) ? trim((string) ($nameParts[0] ?? '')) : '';
  $greeting = $firstName !== '' && ai_suggest_match_normalize($firstName) !== 'contacto'
    ? 'Hola ' . $firstName . ', claro.'
    : 'Hola, claro.';

  $title = ai_suggest_match_normalize($required['title'] ?? '');
  $knowledgeNormalized = ai_suggest_match_normalize($knowledge);
  $isLocation = strpos($title, 'ubic') !== false
    || preg_match('/\b(sede|sedes|direccion|direcciones|av|avenida|valencia)\b/u', $knowledgeNormalized);

  $suffix = $isLocation
    ? "\n\n¿A cuál de las sedes te queda mejor acercarte?"
    : "\n\nQuedo atento para ayudarte con el siguiente paso.";

  return ai_suggest_clean_text($greeting . "\n\n" . $knowledge . $suffix, 1000);
}

function ai_suggest_history_key(int $accountId, int $conversationId): string {
  return 'a' . $accountId . '_c' . $conversationId;
}

function ai_suggest_previous_replies(string $key): array {
  $history = $_SESSION['ai_suggestion_history'][$key] ?? [];
  if (!is_array($history)) return [];

  $replies = [];
  foreach ($history as $item) {
    $reply = is_array($item) ? ($item['reply'] ?? '') : $item;
    $reply = ai_suggest_clean_text($reply, 700);
    if ($reply !== '') $replies[] = $reply;
  }

  return array_slice($replies, -6);
}

function ai_suggest_store_reply(string $key, string $reply): void {
  if (!isset($_SESSION['ai_suggestion_history']) || !is_array($_SESSION['ai_suggestion_history'])) {
    $_SESSION['ai_suggestion_history'] = [];
  }

  $history = $_SESSION['ai_suggestion_history'][$key] ?? [];
  if (!is_array($history)) $history = [];

  $history[] = [
    'at' => time(),
    'reply' => ai_suggest_clean_text($reply, 1000),
  ];

  $_SESSION['ai_suggestion_history'][$key] = array_slice($history, -8);
}

function ai_suggest_call_openai(array $context): array {
  $apiKey = trim((string) app_config('openai.api_key', ''));
  if ($apiKey === '') {
    ai_suggest_json([
      'ok' => false,
      'error' => 'Falta configurar OPENAI_API_KEY en config/local.php.',
    ], 422);
  }

  if (!function_exists('curl_init')) {
    ai_suggest_json([
      'ok' => false,
      'error' => 'El servidor no tiene habilitada la extension cURL de PHP.',
    ], 500);
  }

  $model = trim((string) app_config('openai.model', 'gpt-4.1-mini'));
  if ($model === '') $model = 'gpt-4.1-mini';
  $baseUrl = rtrim((string) app_config('openai.base_url', 'https://api.openai.com/v1'), '/');

  $payload = [
    'model' => $model,
    'instructions' => implode("\n", [
      'Eres un asistente comercial senior dentro de CRM Pixels. Tu objetivo principal es ayudar al operador a vender mejor desde Instagram, Messenger o WhatsApp.',
      'Genera una respuesta lista para enviar al cliente, escrita en espanol natural de Venezuela, cercana, profesional y enfocada en avanzar el proceso comercial.',
      'Prioriza: entender necesidad, calificar interes, resolver dudas, pedir el dato minimo necesario, proponer siguiente paso y mantener la conversacion activa.',
      'No repitas respuestas anteriores. Si el operador presiona varias veces generar respuesta IA, cambia completamente el enfoque, estructura, inicio y cierre.',
      'No repitas literalmente el mensaje del cliente ni uses plantillas genericas. La respuesta debe sonar humana y especifica al contexto.',
      'El bloque approved_sales_knowledge contiene conocimiento aprobado y activo de la cuenta actual. Tratalo como fuente de verdad cuando sea relevante.',
      'Si required_knowledge.required es true, tu respuesta debe usar required_knowledge.response como dato principal y responder directo. No cambies el tema, no preguntes que necesita si el dato ya responde la pregunta.',
      'Si el cliente pregunta por ubicacion, direccion, sedes, horario, pagos, precios, garantia, envios o disponibilidad y el conocimiento aprobado incluye ese dato, respondelo de forma directa antes de pedir mas informacion.',
      'Si el conocimiento aprobado incluye varias sedes o instrucciones concretas, listalas claramente con saltos de linea. No digas que vas a validar un dato que ya esta en el conocimiento.',
      'Escribe con saltos de linea reales para que sea facil de leer en el chat: 2 a 4 parrafos cortos separados por una linea en blanco. Evita bloques largos de texto.',
      'Usa emojis con moderacion: maximo 1 emoji en una respuesta normal, maximo 2 solo si aporta calidez. No uses emojis en cada frase.',
      'No inventes precios, disponibilidad, garantias, tiempos de entrega, promociones, ubicaciones ni condiciones. Si falta informacion, pregunta o ofrece validar.',
      'Evita saludos repetidos si la conversacion ya empezo. Evita despedidas largas. Maximo 90 palabras.',
      'Responde exclusivamente JSON valido con las claves reply, intent, next_step y confidence.',
    ]),
    'input' => "Contexto del CRM:\n" . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
    'max_output_tokens' => 500,
    'temperature' => 0.78,
    'top_p' => 0.96,
  ];

  $ch = curl_init($baseUrl . '/responses');
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
      'Authorization: Bearer ' . $apiKey,
      'Content-Type: application/json',
    ],
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
  ]);

  $raw = curl_exec($ch);
  $curlError = curl_error($ch);
  $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($raw === false || $raw === '') {
    ai_suggest_log_error('OpenAI request failed', ['curl_error' => $curlError]);
    ai_suggest_json(['ok' => false, 'error' => 'No se pudo conectar con OpenAI.'], 502);
  }

  $data = json_decode((string) $raw, true);
  if (!is_array($data)) {
    ai_suggest_log_error('OpenAI returned invalid JSON', ['status' => $status, 'body' => ai_suggest_clean_text($raw, 2000)]);
    ai_suggest_json(['ok' => false, 'error' => 'OpenAI devolvio una respuesta invalida.'], 502);
  }

  if ($status < 200 || $status >= 300) {
    $message = ai_suggest_clean_text($data['error']['message'] ?? 'OpenAI rechazo la solicitud.', 500);
    ai_suggest_log_error('OpenAI HTTP error', ['status' => $status, 'error' => $message]);
    ai_suggest_json(['ok' => false, 'error' => $message], 502);
  }

  $text = ai_suggest_extract_output_text($data);
  $decoded = json_decode($text, true);
  if (!is_array($decoded) && preg_match('/\{.*\}/s', $text, $match)) {
    $decoded = json_decode((string) $match[0], true);
  }
  if (!is_array($decoded)) {
    $decoded = ['reply' => $text];
  }

  $reply = ai_suggest_clean_text($decoded['reply'] ?? '', 1000);
  if ($reply === '') {
    ai_suggest_log_error('OpenAI returned empty suggestion', ['body' => ai_suggest_clean_text($raw, 2000)]);
    ai_suggest_json(['ok' => false, 'error' => 'La IA no devolvio una respuesta sugerida.'], 502);
  }

  if (!ai_suggest_reply_uses_required_knowledge($reply, $context['required_knowledge'] ?? [])) {
    $directReply = ai_suggest_direct_knowledge_reply($context);
    if ($directReply !== '') {
      ai_suggest_log_error('AI suggestion replaced by required knowledge fallback', [
        'required_title' => ai_suggest_clean_text($context['required_knowledge']['title'] ?? '', 140),
        'original_reply' => $reply,
      ]);
      $reply = $directReply;
      $decoded['intent'] = 'Responder con conocimiento aprobado';
      $decoded['next_step'] = 'Dar seguimiento a partir de la informacion entregada';
      $decoded['confidence'] = max((float) ($decoded['confidence'] ?? 0), 0.96);
    }
  }

  return [
    'reply' => $reply,
    'intent' => ai_suggest_clean_text($decoded['intent'] ?? '', 160),
    'next_step' => ai_suggest_clean_text($decoded['next_step'] ?? '', 220),
    'confidence' => $decoded['confidence'] ?? null,
  ];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  ai_suggest_json(['ok' => false, 'error' => 'Metodo no permitido.'], 405);
}

if (!can('send_messages')) {
  ai_suggest_json(['ok' => false, 'error' => 'No tienes permiso para generar respuestas.'], 403);
}

if (!hash_equals((string) ($_SESSION['csrf'] ?? ''), (string) ($_POST['csrf'] ?? ''))) {
  ai_suggest_json(['ok' => false, 'error' => 'Token de seguridad invalido.'], 403);
}

try {
  conv_ensure_schema($pdo);
  ai_knowledge_ensure_schema($pdo);

  $conversationRouteId = trim((string) ($_POST['conversation_id'] ?? ''));
  $requestAccountId = accounts_request_account_id($pdo);
  $postAccountId = max(0, (int) ($_POST['account_id'] ?? 0));
  $lookupAccountId = $requestAccountId > 0 ? $requestAccountId : $postAccountId;
  $conversationId = $lookupAccountId > 0
    ? conv_resolve_conversation_route_id($pdo, $lookupAccountId, $conversationRouteId)
    : 0;

  if ($conversationId <= 0) {
    ai_suggest_json(['ok' => false, 'error' => 'No se encontro la conversacion.'], 404);
  }

  $conversationsTable = conv_conversations_table();
  $contactsTable = conv_contacts_table();
  $messagesTable = conv_messages_table();
  $channelsTable = safe_identifier((string) app_config('database.instagram_channels_table', 'instagram_channels'), 'instagram_channels');
  $accountsTable = accounts_table();
  $leadsTable = safe_identifier((string) app_config('database.leads_table', 'leads'), 'leads');
  $usersTable = safe_identifier((string) app_config('database.users_table', 'users'), 'users');

  $accountSql = is_super_admin() ? '' : 'AND c.account_id = ?';
  $params = [$conversationId];
  if (!is_super_admin()) $params[] = current_account_id();

  $stmt = $pdo->prepare("
    SELECT
      c.*,
      ct.external_contact_id AS contact_external_id,
      ct.display_name,
      ct.username,
      ct.profile_url,
      ct.avatar_url,
      ch.page_name,
      ch.instagram_username AS channel_username,
      a.name AS account_name,
      a.slug AS account_slug,
      l.fullname AS lead_fullname,
      l.sales_status AS lead_sales_status,
      l.notes AS lead_notes,
      l.campaign_name,
      l.adset_name,
      l.ad_name,
      l.ad_referral_source,
      (
        SELECT MAX(im.sent_at)
        FROM {$messagesTable} im
        WHERE im.conversation_id = c.id AND im.direction = 'inbound'
      ) AS last_inbound_at
    FROM {$conversationsTable} c
    JOIN {$contactsTable} ct ON ct.id = c.contact_id
    LEFT JOIN {$channelsTable} ch ON ch.id = c.channel_id
    LEFT JOIN {$accountsTable} a ON a.id = c.account_id
    LEFT JOIN {$leadsTable} l ON l.id = c.lead_id
    WHERE c.id = ?
      {$accountSql}
    LIMIT 1
  ");
  $stmt->execute($params);
  $conversation = $stmt->fetch();

  if (!$conversation) {
    ai_suggest_json(['ok' => false, 'error' => 'No se encontro la conversacion.'], 404);
  }

  $replyWindow = meta_reply_window_info($conversation['last_inbound_at'] ?? '');
  if (!($replyWindow['can_reply'] ?? false)) {
    ai_suggest_json([
      'ok' => false,
      'error' => 'Esta conversacion no esta dentro de la ventana activa de respuesta.',
    ], 409);
  }

  if (!conv_instagram_channel_for_conversation($pdo, $conversation)) {
    ai_suggest_json([
      'ok' => false,
      'error' => 'No hay canal activo disponible para responder esta conversacion.',
    ], 409);
  }

  $messagesStmt = $pdo->prepare("
    SELECT m.direction, m.message_type, m.message_text, m.sent_at, m.delivery_status, u.username AS sent_by_username
    FROM {$messagesTable} m
    LEFT JOIN {$usersTable} u ON u.id = m.sent_by
    WHERE m.conversation_id = ?
    ORDER BY m.sent_at DESC, m.id DESC
    LIMIT 14
  ");
  $messagesStmt->execute([(int) $conversation['id']]);
  $messages = array_reverse($messagesStmt->fetchAll() ?: []);

  $recentMessages = [];
  foreach ($messages as $message) {
    $direction = (string) ($message['direction'] ?? '');
    $recentMessages[] = [
      'role' => $direction === 'outbound' ? 'agente' : ($direction === 'system' ? 'sistema' : 'cliente'),
      'text' => ai_suggest_message_public_text($message),
      'sent_at' => app_datetime($message['sent_at'] ?? ''),
      'sent_by' => ai_suggest_clean_text($message['sent_by_username'] ?? '', 80),
    ];
  }

  $provider = conv_conversation_provider($conversation);
  $contactName = trim((string) ($conversation['lead_fullname'] ?: $conversation['display_name'] ?: $conversation['username'] ?: 'Contacto'));
  $username = trim((string) ($conversation['username'] ?? ''));
  $channelName = trim((string) ($conversation['channel_username'] ?: $conversation['page_name'] ?: ''));
  $leadStatus = (string) ($conversation['lead_sales_status'] ?: app_config('sales_funnel.default_status', 'nuevo_lead'));
  $historyKey = ai_suggest_history_key((int) ($conversation['account_id'] ?? 0), (int) $conversation['id']);
  $variant = ai_suggest_sales_variant();
  $draft = ai_suggest_clean_text($_POST['draft'] ?? '', 800);
  $approvedKnowledge = ai_suggest_select_relevant_knowledge(
    ai_knowledge_active_items($pdo, (int) ($conversation['account_id'] ?? 0), 80),
    $conversation,
    $recentMessages,
    $draft,
    12
  );

  $context = [
    'account' => ai_suggest_clean_text($conversation['account_name'] ?? '', 160),
    'provider' => conv_provider_label($provider),
    'channel' => ai_suggest_clean_text($channelName, 160),
    'contact' => [
      'name' => ai_suggest_clean_text($contactName, 160),
      'username' => $username !== '' ? '@' . ltrim($username, '@') : '',
    ],
    'lead' => [
      'sales_status' => lead_status_label($leadStatus),
      'notes' => ai_suggest_clean_text($conversation['lead_notes'] ?? '', 1200),
    ],
    'campaign' => [
      'campaign' => ai_suggest_clean_text($conversation['campaign_name'] ?? '', 220),
      'adset' => ai_suggest_clean_text($conversation['adset_name'] ?? '', 220),
      'ad' => ai_suggest_clean_text($conversation['ad_name'] ?? '', 220),
      'reference' => ai_suggest_clean_text($conversation['ad_referral_source'] ?? '', 220),
    ],
    'operator_draft' => $draft,
    'approved_sales_knowledge' => $approvedKnowledge,
    'required_knowledge' => ai_suggest_required_knowledge($approvedKnowledge),
    'sales_generation_rules' => [
      'variant' => $variant,
      'must_be_different_from_previous' => true,
      'previous_ai_suggestions_to_avoid' => ai_suggest_previous_replies($historyKey),
      'generation_seed' => bin2hex(random_bytes(8)),
      'generated_at' => app_datetime(gmdate('Y-m-d H:i:s')),
    ],
    'recent_messages' => $recentMessages,
  ];

  $suggestion = ai_suggest_call_openai($context);
  ai_suggest_store_reply($historyKey, $suggestion['reply']);
  ai_suggest_json([
    'ok' => true,
    'reply' => $suggestion['reply'],
    'intent' => $suggestion['intent'],
    'next_step' => $suggestion['next_step'],
    'confidence' => $suggestion['confidence'],
  ]);
} catch (Throwable $e) {
  ai_suggest_log_error($e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
  ai_suggest_json(['ok' => false, 'error' => 'No se pudo generar la sugerencia.'], 500);
}
