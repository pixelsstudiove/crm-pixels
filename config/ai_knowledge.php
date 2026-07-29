<?php
// config/ai_knowledge.php
declare(strict_types=1);

require_once __DIR__ . '/accounts.php';

function ai_knowledge_table(): string {
  return safe_identifier((string) app_config('database.ai_knowledge_table', 'ai_knowledge_items'), 'ai_knowledge_items');
}

function ai_knowledge_log_error(string $message, array $context = []): void {
  $dir = dirname(__DIR__) . '/storage';
  if (!is_dir($dir)) @mkdir($dir, 0775, true);
  $line = json_encode([
    'at' => gmdate('c'),
    'message' => $message,
    'context' => $context,
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
  @file_put_contents($dir . '/ai_knowledge_errors.log', $line . PHP_EOL, FILE_APPEND);
}

function ai_knowledge_ensure_schema(PDO $pdo): void {
  global $DB_NAME;

  $table = ai_knowledge_table();
  $defaultAccountId = accounts_default_id($pdo);
  $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS {$table} (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  account_id INT UNSIGNED NOT NULL DEFAULT {$defaultAccountId},
  title VARCHAR(180) NOT NULL,
  response_text TEXT NOT NULL,
  raw_response_text TEXT NULL,
  response_hash CHAR(64) NOT NULL,
  context_hash CHAR(64) NULL,
  category VARCHAR(60) NOT NULL DEFAULT 'ai_suggestion',
  source VARCHAR(60) NOT NULL DEFAULT 'ai_suggestion',
  source_conversation_id INT UNSIGNED NULL,
  source_message_id INT UNSIGNED NULL,
  analysis_json LONGTEXT NULL,
  confidence DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  is_approved TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 0,
  usage_count INT UNSIGNED NOT NULL DEFAULT 0,
  last_used_at DATETIME NULL,
  created_by INT UNSIGNED NULL,
  approved_by INT UNSIGNED NULL,
  approved_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_account_response_hash (account_id, response_hash),
  UNIQUE KEY uniq_account_context_hash (account_id, context_hash),
  KEY idx_account_approved_active (account_id, is_approved, is_active),
  KEY idx_source_conversation (source_conversation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

  $dbName = (string) ($DB_NAME ?? '');
  if ($dbName === '') return;

  $columns = [
    'account_id' => "ALTER TABLE {$table} ADD account_id INT UNSIGNED NOT NULL DEFAULT {$defaultAccountId} AFTER id",
    'title' => "ALTER TABLE {$table} ADD title VARCHAR(180) NOT NULL DEFAULT 'Respuesta IA' AFTER account_id",
    'response_text' => "ALTER TABLE {$table} ADD response_text TEXT NOT NULL AFTER title",
    'raw_response_text' => "ALTER TABLE {$table} ADD raw_response_text TEXT NULL AFTER response_text",
    'response_hash' => "ALTER TABLE {$table} ADD response_hash CHAR(64) NOT NULL DEFAULT '' AFTER raw_response_text",
    'context_hash' => "ALTER TABLE {$table} ADD context_hash CHAR(64) NULL AFTER response_hash",
    'category' => "ALTER TABLE {$table} ADD category VARCHAR(60) NOT NULL DEFAULT 'ai_suggestion' AFTER context_hash",
    'source' => "ALTER TABLE {$table} ADD source VARCHAR(60) NOT NULL DEFAULT 'ai_suggestion' AFTER category",
    'source_conversation_id' => "ALTER TABLE {$table} ADD source_conversation_id INT UNSIGNED NULL AFTER source",
    'source_message_id' => "ALTER TABLE {$table} ADD source_message_id INT UNSIGNED NULL AFTER source_conversation_id",
    'analysis_json' => "ALTER TABLE {$table} ADD analysis_json LONGTEXT NULL AFTER source_message_id",
    'confidence' => "ALTER TABLE {$table} ADD confidence DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER analysis_json",
    'is_approved' => "ALTER TABLE {$table} ADD is_approved TINYINT(1) NOT NULL DEFAULT 0 AFTER confidence",
    'is_active' => "ALTER TABLE {$table} ADD is_active TINYINT(1) NOT NULL DEFAULT 0 AFTER is_approved",
    'usage_count' => "ALTER TABLE {$table} ADD usage_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER is_active",
    'last_used_at' => "ALTER TABLE {$table} ADD last_used_at DATETIME NULL AFTER usage_count",
    'created_by' => "ALTER TABLE {$table} ADD created_by INT UNSIGNED NULL AFTER last_used_at",
    'approved_by' => "ALTER TABLE {$table} ADD approved_by INT UNSIGNED NULL AFTER created_by",
    'approved_at' => "ALTER TABLE {$table} ADD approved_at DATETIME NULL AFTER approved_by",
    'updated_at' => "ALTER TABLE {$table} ADD updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP",
  ];

  foreach ($columns as $column => $sql) {
    try {
      if (!account_column_exists($pdo, $dbName, $table, $column)) $pdo->exec($sql);
    } catch (Throwable $e) {
      ai_knowledge_log_error('No se pudo ajustar columna', ['column' => $column, 'error' => $e->getMessage()]);
    }
  }

  try {
    if (!account_index_exists($pdo, $dbName, $table, 'uniq_account_response_hash')) {
      $pdo->exec("ALTER TABLE {$table} ADD UNIQUE KEY uniq_account_response_hash (account_id, response_hash)");
    }
  } catch (Throwable $e) {
    ai_knowledge_log_error('No se pudo crear indice unico', ['error' => $e->getMessage()]);
  }

  try {
    if (!account_index_exists($pdo, $dbName, $table, 'uniq_account_context_hash')) {
      $pdo->exec("ALTER TABLE {$table} ADD UNIQUE KEY uniq_account_context_hash (account_id, context_hash)");
    }
  } catch (Throwable $e) {
    ai_knowledge_log_error('No se pudo crear indice de contexto', ['error' => $e->getMessage()]);
  }
}

function ai_knowledge_clean_text($value, int $max = 2000): string {
  $text = trim(str_replace("\0", '', (string) $value));
  if ($text === '') return '';
  return function_exists('mb_substr') ? mb_substr($text, 0, $max, 'UTF-8') : substr($text, 0, $max);
}

function ai_knowledge_hash(string $text): string {
  $normalized = preg_replace('/\s+/u', ' ', trim($text)) ?: trim($text);
  $normalized = function_exists('mb_strtolower') ? mb_strtolower($normalized, 'UTF-8') : strtolower($normalized);
  return hash('sha256', $normalized);
}

function ai_knowledge_title_from_text(string $text): string {
  $firstLine = trim((string) preg_split('/\R/u', $text)[0]);
  $firstLine = $firstLine !== '' ? $firstLine : 'Respuesta IA';
  return ai_knowledge_clean_text($firstLine, 90);
}

function ai_knowledge_create_suggestion(PDO $pdo, int $accountId, int $conversationId, string $reply, array $meta = []): void {
  try {
    ai_knowledge_ensure_schema($pdo);
    $reply = ai_knowledge_clean_text($reply, 2000);
    if ($accountId <= 0 || $reply === '') return;

    $table = ai_knowledge_table();
    $title = ai_knowledge_clean_text((string) ($meta['title'] ?? ''), 120);
    if ($title === '') $title = ai_knowledge_title_from_text($reply);
    $hash = ai_knowledge_hash($reply);
    $createdBy = max(0, (int) ($meta['created_by'] ?? ($_SESSION['user_id'] ?? 0))) ?: null;

    $stmt = $pdo->prepare("
      INSERT INTO {$table}
        (account_id, title, response_text, response_hash, category, source, source_conversation_id, created_by, is_approved, is_active)
      VALUES
        (?, ?, ?, ?, ?, ?, ?, ?, 0, 0)
      ON DUPLICATE KEY UPDATE
        updated_at = NOW(),
        source_conversation_id = COALESCE(source_conversation_id, VALUES(source_conversation_id))
    ");
    $stmt->execute([
      $accountId,
      $title,
      $reply,
      $hash,
      ai_knowledge_clean_text($meta['category'] ?? 'ai_suggestion', 60),
      ai_knowledge_clean_text($meta['source'] ?? 'ai_suggestion', 60),
      $conversationId > 0 ? $conversationId : null,
      $createdBy,
    ]);
  } catch (Throwable $e) {
    ai_knowledge_log_error('No se pudo guardar sugerencia IA', ['error' => $e->getMessage()]);
  }
}

function ai_knowledge_active_items(PDO $pdo, int $accountId, int $limit = 8): array {
  ai_knowledge_ensure_schema($pdo);
  if ($accountId <= 0) return [];

  $limit = max(1, min(20, $limit));
  $table = ai_knowledge_table();
  $stmt = $pdo->prepare("
    SELECT id, title, response_text, category, usage_count
    FROM {$table}
    WHERE account_id = ?
      AND is_approved = 1
      AND is_active = 1
    ORDER BY usage_count ASC, updated_at DESC, id DESC
    LIMIT {$limit}
  ");
  $stmt->execute([$accountId]);
  return $stmt->fetchAll() ?: [];
}

function ai_knowledge_word_count(string $text): int {
  $parts = preg_split('/\s+/u', trim($text));
  if (!is_array($parts)) return 0;
  $parts = array_filter($parts, static fn($part) => trim((string) $part) !== '');
  return count($parts);
}

function ai_knowledge_learning_skip_reason(string $reply): string {
  $reply = ai_knowledge_clean_text($reply, 2000);
  if ($reply === '') return 'empty';

  $length = function_exists('mb_strlen') ? mb_strlen($reply, 'UTF-8') : strlen($reply);
  $words = ai_knowledge_word_count($reply);
  if ($length < 35 || $words < 7) return 'too_short';

  $normalized = preg_replace('/[^\pL\pN\s]+/u', ' ', $reply) ?: $reply;
  $normalized = preg_replace('/\s+/u', ' ', trim($normalized)) ?: trim($normalized);
  $normalized = function_exists('mb_strtolower') ? mb_strtolower($normalized, 'UTF-8') : strtolower($normalized);
  $trivial = [
    'hola',
    'ok',
    'okay',
    'listo',
    'perfecto',
    'gracias',
    'de nada',
    'buen dia',
    'buenas tardes',
    'buenas noches',
    'ya va',
    'un momento',
    'si',
    'no',
  ];
  if (in_array($normalized, $trivial, true)) return 'trivial';
  if (preg_match('/^(hola|ok|listo|perfecto|gracias|de nada|un momento)[\s\pP\pS]*$/iu', $reply)) return 'trivial';

  return '';
}

function ai_knowledge_recent_messages(PDO $pdo, int $conversationId, int $limit = 10): array {
  if ($conversationId <= 0 || !function_exists('conv_messages_table')) return [];
  $limit = max(3, min(20, $limit));
  $messagesTable = conv_messages_table();
  $usersTable = safe_identifier((string) app_config('database.users_table', 'users'), 'users');
  try {
    $stmt = $pdo->prepare("
      SELECT m.direction, m.message_type, m.message_text, m.sent_at, u.username AS sent_by_username
      FROM {$messagesTable} m
      LEFT JOIN {$usersTable} u ON u.id = m.sent_by
      WHERE m.conversation_id = ?
      ORDER BY m.sent_at DESC, m.id DESC
      LIMIT {$limit}
    ");
    $stmt->execute([$conversationId]);
    $rows = array_reverse($stmt->fetchAll() ?: []);
  } catch (Throwable $e) {
    ai_knowledge_log_error('No se pudo leer historial para aprendizaje IA', ['error' => $e->getMessage()]);
    return [];
  }

  $messages = [];
  foreach ($rows as $row) {
    $direction = (string) ($row['direction'] ?? '');
    $messages[] = [
      'role' => $direction === 'outbound' ? 'agente' : ($direction === 'system' ? 'sistema' : 'cliente'),
      'type' => ai_knowledge_clean_text($row['message_type'] ?? 'text', 40),
      'text' => ai_knowledge_clean_text($row['message_text'] ?? '', 700),
      'sent_at' => function_exists('app_datetime') ? app_datetime($row['sent_at'] ?? '') : (string) ($row['sent_at'] ?? ''),
      'sent_by' => ai_knowledge_clean_text($row['sent_by_username'] ?? '', 80),
    ];
  }
  return $messages;
}

function ai_knowledge_extract_output_text(array $data): string {
  if (isset($data['output_text']) && is_string($data['output_text'])) return trim($data['output_text']);
  $parts = [];
  foreach ((array) ($data['output'] ?? []) as $item) {
    foreach ((array) ($item['content'] ?? []) as $content) {
      $text = $content['text'] ?? ($content['output_text'] ?? null);
      if (is_string($text) && trim($text) !== '') $parts[] = trim($text);
    }
  }
  return trim(implode("\n", $parts));
}

function ai_knowledge_decode_json_text(string $text): ?array {
  $text = trim($text);
  if ($text === '') return null;
  if (preg_match('/```(?:json)?\s*(.*?)```/is', $text, $match)) {
    $text = trim((string) $match[1]);
  } elseif (preg_match('/\{.*\}/s', $text, $match)) {
    $text = trim((string) $match[0]);
  }
  $decoded = json_decode($text, true);
  return is_array($decoded) ? $decoded : null;
}

function ai_knowledge_call_openai_for_learning(array $context): ?array {
  $apiKey = trim((string) app_config('openai.api_key', ''));
  if ($apiKey === '') return null;
  if (!function_exists('curl_init')) {
    ai_knowledge_log_error('cURL no esta disponible para aprendizaje IA');
    return null;
  }

  $model = trim((string) app_config('openai.model', 'gpt-4.1-mini'));
  if ($model === '') $model = 'gpt-4.1-mini';
  $baseUrl = rtrim((string) app_config('openai.base_url', 'https://api.openai.com/v1'), '/');
  $payload = [
    'model' => $model,
    'instructions' => implode("\n", [
      'Eres un analista comercial de CRM Pixels. Tu trabajo es convertir respuestas reales de vendedores en conocimiento reutilizable para futuras sugerencias de IA.',
      'Analiza el contexto de la conversacion y la respuesta del vendedor. Guarda conocimiento solo si ensena una regla, dato, argumento, proceso, condicion comercial, forma de cotizar, objecion o siguiente paso reutilizable.',
      'No guardes saludos, agradecimientos, confirmaciones simples, respuestas sin contexto, mensajes personales, datos privados del cliente, precios o disponibilidad si no queda claro a que producto/servicio aplican.',
      'Evita duplicados: crea un context_key corto que represente la intencion reutilizable, por ejemplo producto_fregadero_ubicacion, pago_zelle, delivery_valencia, precio_bloques_rojos.',
      'Si ya existe conocimiento para el mismo contexto, el context_key debe ser igual aunque el vendedor use otras palabras.',
      'La respuesta knowledge debe quedar lista para que otra IA la use como referencia interna, no como texto obligatorio para copiar literal.',
      'Responde exclusivamente JSON valido con estas claves: save, reason, context_key, title, knowledge, category, confidence.',
      'category debe ser una de: producto, precio, pago, envio, horario, garantia, objecion, proceso, promocion, seguimiento, general.',
      'confidence debe ser un numero de 0 a 100.',
    ]),
    'input' => "Contexto:\n" . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
    'max_output_tokens' => 650,
    'temperature' => 0.15,
    'top_p' => 0.9,
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
    CURLOPT_TIMEOUT => 25,
  ]);

  $raw = curl_exec($ch);
  $curlError = curl_error($ch);
  $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($raw === false || $raw === '') {
    ai_knowledge_log_error('OpenAI no respondio para aprendizaje IA', ['curl_error' => $curlError]);
    return null;
  }

  $data = json_decode((string) $raw, true);
  if (!is_array($data)) {
    ai_knowledge_log_error('OpenAI devolvio JSON invalido en aprendizaje IA', ['body' => ai_knowledge_clean_text($raw, 1500)]);
    return null;
  }

  if ($status < 200 || $status >= 300) {
    ai_knowledge_log_error('OpenAI rechazo aprendizaje IA', [
      'status' => $status,
      'error' => ai_knowledge_clean_text($data['error']['message'] ?? 'Error desconocido', 700),
    ]);
    return null;
  }

  return ai_knowledge_decode_json_text(ai_knowledge_extract_output_text($data));
}

function ai_knowledge_normalize_context_key(string $contextKey, string $category): string {
  $contextKey = ai_knowledge_clean_text($contextKey, 120);
  $contextKey = function_exists('mb_strtolower') ? mb_strtolower($contextKey, 'UTF-8') : strtolower($contextKey);
  $contextKey = preg_replace('/[^\pL\pN]+/u', '_', $contextKey) ?: $contextKey;
  $contextKey = trim($contextKey, '_');
  if ($contextKey === '') $contextKey = $category . '_general';
  return ai_knowledge_clean_text($category . ':' . $contextKey, 120);
}

function ai_knowledge_learn_from_outbound_message(PDO $pdo, int $accountId, int $conversationId, int $messageId, string $reply, array $meta = []): void {
  try {
    ai_knowledge_ensure_schema($pdo);
    $reply = ai_knowledge_clean_text($reply, 2000);
    if ($accountId <= 0 || $conversationId <= 0 || $messageId <= 0) return;

    $skipReason = ai_knowledge_learning_skip_reason($reply);
    if ($skipReason !== '') return;

    $conversation = is_array($meta['conversation'] ?? null) ? $meta['conversation'] : [];
    $recentMessages = is_array($meta['recent_messages'] ?? null)
      ? $meta['recent_messages']
      : ai_knowledge_recent_messages($pdo, $conversationId, 12);

    $analysisContext = [
      'account_id' => $accountId,
      'conversation_id' => $conversationId,
      'provider' => ai_knowledge_clean_text($meta['provider'] ?? ($conversation['external_source'] ?? ''), 60),
      'operator' => [
        'id' => (int) ($meta['operator_id'] ?? ($_SESSION['user_id'] ?? 0)),
        'username' => ai_knowledge_clean_text($meta['operator_username'] ?? ($_SESSION['username'] ?? ''), 80),
      ],
      'contact' => [
        'name' => ai_knowledge_clean_text($conversation['display_name'] ?? '', 160),
        'username' => ai_knowledge_clean_text($conversation['username'] ?? '', 160),
      ],
      'campaign' => [
        'campaign' => ai_knowledge_clean_text($conversation['campaign_name'] ?? '', 220),
        'adset' => ai_knowledge_clean_text($conversation['adset_name'] ?? '', 220),
        'ad' => ai_knowledge_clean_text($conversation['ad_name'] ?? '', 220),
      ],
      'recent_messages' => $recentMessages,
      'seller_reply' => $reply,
    ];

    $analysis = ai_knowledge_call_openai_for_learning($analysisContext);
    if (!is_array($analysis)) return;

    $save = filter_var($analysis['save'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $confidence = (float) ($analysis['confidence'] ?? 0);
    if (!$save || $confidence < 65) return;

    $allowedCategories = ['producto', 'precio', 'pago', 'envio', 'horario', 'garantia', 'objecion', 'proceso', 'promocion', 'seguimiento', 'general'];
    $category = ai_knowledge_clean_text($analysis['category'] ?? 'general', 60);
    if (!in_array($category, $allowedCategories, true)) $category = 'general';

    $knowledge = ai_knowledge_clean_text($analysis['knowledge'] ?? '', 2000);
    $contextKey = ai_knowledge_normalize_context_key((string) ($analysis['context_key'] ?? ''), $category);
    if ($knowledge === '' || $contextKey === '') return;

    $title = ai_knowledge_clean_text($analysis['title'] ?? '', 140);
    if ($title === '') $title = ai_knowledge_title_from_text($knowledge);

    $table = ai_knowledge_table();
    $createdBy = max(0, (int) ($meta['operator_id'] ?? ($_SESSION['user_id'] ?? 0))) ?: null;
    $responseHash = ai_knowledge_hash($knowledge);
    $contextHash = ai_knowledge_hash($contextKey);
    $analysisJson = json_encode([
      'analysis' => $analysis,
      'context_key' => $contextKey,
      'reason' => ai_knowledge_clean_text($analysis['reason'] ?? '', 500),
      'learned_at' => gmdate('c'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

    $stmt = $pdo->prepare("
      INSERT INTO {$table}
        (account_id, title, response_text, raw_response_text, response_hash, context_hash, category, source, source_conversation_id, source_message_id, analysis_json, confidence, created_by, is_approved, is_active)
      VALUES
        (?, ?, ?, ?, ?, ?, ?, 'seller_reply', ?, ?, ?, ?, ?, 0, 0)
      ON DUPLICATE KEY UPDATE
        updated_at = NOW(),
        source_conversation_id = VALUES(source_conversation_id),
        source_message_id = VALUES(source_message_id),
        raw_response_text = VALUES(raw_response_text),
        analysis_json = VALUES(analysis_json),
        confidence = IF(confidence < VALUES(confidence), VALUES(confidence), confidence),
        title = IF(is_approved = 1, title, VALUES(title)),
        response_text = IF(is_approved = 1, response_text, VALUES(response_text)),
        response_hash = IF(is_approved = 1, response_hash, VALUES(response_hash)),
        context_hash = IF(is_approved = 1, context_hash, VALUES(context_hash)),
        category = IF(is_approved = 1, category, VALUES(category)),
        source = IF(is_approved = 1, source, VALUES(source))
    ");
    $stmt->execute([
      $accountId,
      $title,
      $knowledge,
      $reply,
      $responseHash,
      $contextHash,
      $category,
      $conversationId,
      $messageId,
      $analysisJson,
      $confidence,
      $createdBy,
    ]);
  } catch (Throwable $e) {
    ai_knowledge_log_error('No se pudo aprender de respuesta del vendedor', [
      'error' => $e->getMessage(),
      'conversation_id' => $conversationId,
      'message_id' => $messageId,
    ]);
  }
}
