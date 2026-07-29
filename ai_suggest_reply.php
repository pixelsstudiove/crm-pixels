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
      'Si existe conocimiento aprobado y activo de la cuenta, usalo como referencia prioritaria solo cuando sea relevante para esta conversacion.',
      'Escribe con saltos de linea reales para que sea facil de leer en el chat: 2 a 4 parrafos cortos separados por una linea en blanco. Evita bloques largos de texto.',
      'Usa emojis con moderacion: maximo 1 emoji en una respuesta normal, maximo 2 solo si aporta calidez. No uses emojis en cada frase.',
      'No inventes precios, disponibilidad, garantias, tiempos de entrega, promociones, ubicaciones ni condiciones. Si falta informacion, pregunta o ofrece validar.',
      'Evita saludos repetidos si la conversacion ya empezo. Evita despedidas largas. Maximo 90 palabras.',
      'Responde exclusivamente JSON valido con las claves reply, intent, next_step y confidence.',
    ]),
    'input' => "Contexto del CRM:\n" . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
    'max_output_tokens' => 500,
    'temperature' => 0.92,
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
  $approvedKnowledge = [];
  foreach (ai_knowledge_active_items($pdo, (int) ($conversation['account_id'] ?? 0), 10) as $knowledgeItem) {
    $approvedKnowledge[] = [
      'title' => ai_suggest_clean_text($knowledgeItem['title'] ?? '', 140),
      'response' => ai_suggest_clean_text($knowledgeItem['response_text'] ?? '', 1200),
      'category' => ai_suggest_clean_text($knowledgeItem['category'] ?? '', 80),
    ];
  }

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
    'operator_draft' => ai_suggest_clean_text($_POST['draft'] ?? '', 800),
    'approved_sales_knowledge' => $approvedKnowledge,
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
  ai_knowledge_create_suggestion($pdo, (int) ($conversation['account_id'] ?? 0), (int) $conversation['id'], $suggestion['reply'], [
    'title' => 'Sugerencia para ' . $contactName,
    'category' => 'ai_suggestion',
    'source' => 'ai_suggestion',
    'created_by' => (int) ($_SESSION['user_id'] ?? 0) ?: null,
  ]);
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
