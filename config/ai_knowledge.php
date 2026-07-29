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
  category VARCHAR(60) NOT NULL DEFAULT 'manual',
  source VARCHAR(60) NOT NULL DEFAULT 'manual',
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
    'category' => "ALTER TABLE {$table} ADD category VARCHAR(60) NOT NULL DEFAULT 'manual' AFTER context_hash",
    'source' => "ALTER TABLE {$table} ADD source VARCHAR(60) NOT NULL DEFAULT 'manual' AFTER category",
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

function ai_knowledge_learning_threshold(): int {
  return max(2, (int) app_config('openai.knowledge_learning_min_distinct_leads', 4));
}

function ai_knowledge_decode_analysis_payload($json): array {
  if (!is_string($json) || trim($json) === '') return [];
  $decoded = json_decode($json, true);
  return is_array($decoded) ? $decoded : [];
}

function ai_knowledge_fold_text(string $text): string {
  $text = ai_knowledge_clean_text($text, 4000);
  $text = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
  $map = [
    'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a',
    'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
    'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
    'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o',
    'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
    'ñ' => 'n',
  ];
  $text = strtr($text, $map);
  $text = preg_replace('/[^\pL\pN]+/u', ' ', $text) ?: $text;
  return preg_replace('/\s+/u', ' ', trim($text)) ?: trim($text);
}

function ai_knowledge_topic_slug(string $topic): string {
  $topic = ai_knowledge_fold_text($topic);
  $topic = preg_replace('/[^\pL\pN]+/u', '_', $topic) ?: $topic;
  return trim($topic, '_') ?: 'general';
}

function ai_knowledge_canonical_topic_rules(string $text, string $category = 'general'): ?array {
  $folded = ai_knowledge_fold_text($text);
  if ($folded === '') return null;

  $rules = [
    [
      'pattern' => '/\b(cashea|financiamiento|cuotas|credito)\b/u',
      'title' => 'Pago con Cashea',
      'context_key' => 'pago_cashea',
      'category' => 'pago',
      'aliases' => ['cashea', 'financiamiento', 'cuotas'],
    ],
    [
      'pattern' => '/\b(zelle|pago movil|transferencia|tarjeta|punto de venta|efectivo|divisa|divisas|dolar|dolares|bolivar|bolivares|metodo de pago|metodos de pago)\b/u',
      'title' => 'Métodos de pago',
      'context_key' => 'metodos_pago',
      'category' => 'pago',
      'aliases' => ['formas de pago', 'zelle', 'pago móvil', 'transferencia'],
    ],
    [
      'pattern' => '/\b(ubicacion|direccion|sede|sedes|sucursal|sucursales|tienda fisica|tiendas fisicas|donde estan|donde se ubican|como llegar|referencia|referencias|maps|google maps|valencia)\b/u',
      'title' => 'Ubicación y sedes',
      'context_key' => 'ubicacion_sedes',
      'category' => 'general',
      'aliases' => ['dirección', 'sucursales', 'tienda física', 'referencias'],
    ],
    [
      'pattern' => '/\b(horario|horarios|abren|cierran|hora de apertura|hora de cierre|atienden|atencion)\b/u',
      'title' => 'Horarios de atención',
      'context_key' => 'horarios_atencion',
      'category' => 'horario',
      'aliases' => ['horario', 'apertura', 'cierre'],
    ],
    [
      'pattern' => '/\b(delivery|envio|envios|despacho|domicilio|entrega|flete|motorizado)\b/u',
      'title' => 'Envíos y delivery',
      'context_key' => 'envios_delivery',
      'category' => 'envio',
      'aliases' => ['delivery', 'despacho', 'entrega'],
    ],
    [
      'pattern' => '/\b(garantia|garantias|cambio|cambios|devolucion|devoluciones|reclamo)\b/u',
      'title' => 'Garantías y cambios',
      'context_key' => 'garantias_cambios',
      'category' => 'garantia',
      'aliases' => ['garantía', 'cambios', 'devoluciones'],
    ],
  ];

  foreach ($rules as $rule) {
    if (preg_match($rule['pattern'], $folded)) {
      $rule['topic_source'] = 'rule';
      return $rule;
    }
  }

  return null;
}

function ai_knowledge_existing_topics(PDO $pdo, int $accountId, int $limit = 80): array {
  if ($accountId <= 0) return [];
  $limit = max(10, min(150, $limit));
  $table = ai_knowledge_table();

  try {
    $stmt = $pdo->prepare("
      SELECT id, title, category, source, response_text, analysis_json, usage_count, updated_at
      FROM {$table}
      WHERE account_id = ?
        AND context_hash IS NOT NULL
        AND source IN ('manual', 'seller_reply_candidate', 'seller_reply')
      ORDER BY is_approved DESC, usage_count DESC, updated_at DESC, id DESC
      LIMIT {$limit}
    ");
    $stmt->execute([$accountId]);
  } catch (Throwable $e) {
    ai_knowledge_log_error('No se pudieron leer topicos existentes', ['error' => $e->getMessage()]);
    return [];
  }

  $topics = [];
  foreach ($stmt->fetchAll() ?: [] as $row) {
    $payload = ai_knowledge_decode_analysis_payload($row['analysis_json'] ?? null);
    $analysis = is_array($payload['analysis'] ?? null) ? $payload['analysis'] : [];
    $contextKey = (string) ($payload['context_key'] ?? ($analysis['context_key'] ?? ''));
    $canonicalTopic = (string) ($payload['canonical_topic'] ?? ($analysis['canonical_topic'] ?? ($row['title'] ?? '')));
    $aliases = $payload['topic_aliases'] ?? ($analysis['aliases'] ?? []);
    if (!is_array($aliases)) $aliases = [];
    $topics[] = [
      'id' => (int) ($row['id'] ?? 0),
      'title' => ai_knowledge_clean_text($canonicalTopic !== '' ? $canonicalTopic : (string) ($row['title'] ?? ''), 140),
      'context_key' => ai_knowledge_clean_text($contextKey, 140),
      'category' => ai_knowledge_clean_text($row['category'] ?? 'general', 60),
      'source' => ai_knowledge_clean_text($row['source'] ?? '', 60),
      'aliases' => array_values(array_filter(array_map(static fn($alias) => ai_knowledge_clean_text((string) $alias, 80), $aliases))),
      'summary' => ai_knowledge_clean_text($row['response_text'] ?? '', 260),
    ];
  }

  return $topics;
}

function ai_knowledge_topic_tokens(string $text): array {
  $folded = ai_knowledge_fold_text($text);
  if ($folded === '') return [];
  $words = preg_split('/\s+/u', $folded) ?: [];
  $stopWords = array_flip([
    'de', 'del', 'la', 'las', 'el', 'los', 'y', 'o', 'para', 'por', 'con', 'sin',
    'en', 'un', 'una', 'unos', 'unas', 'que', 'como', 'sobre', 'cliente', 'clientes',
    'tienda', 'tiendas', 'fisica', 'fisicas', 'opciones', 'atencion', 'servicio',
  ]);
  $tokens = [];
  foreach ($words as $word) {
    $word = trim((string) $word);
    if ($word === '' || isset($stopWords[$word])) continue;
    if ((function_exists('mb_strlen') ? mb_strlen($word, 'UTF-8') : strlen($word)) < 3) continue;
    $tokens[$word] = true;
  }
  return array_keys($tokens);
}

function ai_knowledge_match_existing_topic(array $existingTopics, string $candidateTitle, string $candidateContextKey, string $category): ?array {
  $candidateSlug = ai_knowledge_topic_slug($candidateContextKey !== '' ? $candidateContextKey : $candidateTitle);
  $candidateTokens = ai_knowledge_topic_tokens($candidateTitle . ' ' . $candidateContextKey);
  foreach ($existingTopics as $topic) {
    if (!is_array($topic)) continue;
    $topicKey = (string) ($topic['context_key'] ?? '');
    $topicTitle = (string) ($topic['title'] ?? '');
    if ($topicKey !== '' && ai_knowledge_topic_slug($topicKey) === $candidateSlug) {
      $topic['topic_source'] = 'existing';
      return $topic;
    }

    $topicTokens = ai_knowledge_topic_tokens($topicTitle . ' ' . $topicKey . ' ' . implode(' ', (array) ($topic['aliases'] ?? [])));
    if (!$candidateTokens || !$topicTokens) continue;
    $overlap = count(array_intersect($candidateTokens, $topicTokens));
    $smaller = max(1, min(count($candidateTokens), count($topicTokens)));
    if ($overlap >= 2 && ($overlap / $smaller) >= 0.5) {
      $topic['topic_source'] = 'existing';
      return $topic;
    }
  }
  return null;
}

function ai_knowledge_resolve_canonical_topic(PDO $pdo, int $accountId, array $analysis, array $analysisContext): array {
  $rawCategory = ai_knowledge_clean_text($analysis['category'] ?? 'general', 60);
  $candidateText = implode("\n", [
    (string) ($analysis['context_key'] ?? ''),
    (string) ($analysis['title'] ?? ''),
    (string) ($analysis['knowledge'] ?? ''),
    (string) ($analysisContext['seller_reply'] ?? ''),
    json_encode($analysisContext['recent_messages'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: '',
  ]);

  $ruleTopic = ai_knowledge_canonical_topic_rules($candidateText, $rawCategory);
  if (is_array($ruleTopic)) return $ruleTopic;

  $existingTopics = is_array($analysisContext['existing_topics'] ?? null)
    ? $analysisContext['existing_topics']
    : ai_knowledge_existing_topics($pdo, $accountId);
  $existingTopic = ai_knowledge_match_existing_topic(
    $existingTopics,
    (string) ($analysis['title'] ?? ''),
    (string) ($analysis['context_key'] ?? ''),
    $rawCategory
  );
  if (is_array($existingTopic)) {
    return [
      'title' => ai_knowledge_clean_text($existingTopic['title'] ?? ($analysis['title'] ?? ''), 140),
      'context_key' => ai_knowledge_clean_text($existingTopic['context_key'] ?? ($analysis['context_key'] ?? ''), 140),
      'category' => ai_knowledge_clean_text($existingTopic['category'] ?? $rawCategory, 60),
      'aliases' => is_array($existingTopic['aliases'] ?? null) ? $existingTopic['aliases'] : [],
      'topic_source' => 'existing',
    ];
  }

  $title = ai_knowledge_clean_text($analysis['canonical_topic'] ?? ($analysis['title'] ?? ''), 140);
  $contextKey = ai_knowledge_clean_text($analysis['context_key'] ?? '', 140);
  return [
    'title' => $title,
    'context_key' => $contextKey,
    'category' => $rawCategory,
    'aliases' => is_array($analysis['aliases'] ?? null) ? $analysis['aliases'] : [],
    'topic_source' => 'model',
  ];
}

function ai_knowledge_evidence_key(array $evidence): string {
  $conversationId = (int) ($evidence['conversation_id'] ?? 0);
  $messageId = (int) ($evidence['message_id'] ?? 0);
  if ($conversationId > 0 && $messageId > 0) return $conversationId . ':' . $messageId;
  return hash('sha256', json_encode($evidence, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function ai_knowledge_merge_evidence(array $current, array $newEvidence): array {
  $merged = [];
  foreach ($current as $item) {
    if (!is_array($item)) continue;
    $merged[ai_knowledge_evidence_key($item)] = $item;
  }
  $merged[ai_knowledge_evidence_key($newEvidence)] = $newEvidence;
  return array_slice(array_values($merged), -25);
}

function ai_knowledge_distinct_conversation_count(array $evidence): int {
  $ids = [];
  foreach ($evidence as $item) {
    if (!is_array($item)) continue;
    $conversationId = (int) ($item['conversation_id'] ?? 0);
    if ($conversationId > 0) $ids[$conversationId] = true;
  }
  return count($ids);
}

function ai_knowledge_create_suggestion(PDO $pdo, int $accountId, int $conversationId, string $reply, array $meta = []): void {
  try {
    ai_knowledge_ensure_schema($pdo);
    $reply = ai_knowledge_clean_text($reply, 2000);
    if ($accountId <= 0 || $reply === '') return;
    $source = ai_knowledge_clean_text($meta['source'] ?? 'ai_suggestion', 60);
    $category = ai_knowledge_clean_text($meta['category'] ?? 'ai_suggestion', 60);
    if ($source === 'ai_suggestion' || $category === 'ai_suggestion') return;

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
      $category,
      $source,
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

  $limit = max(1, min(120, $limit));
  $table = ai_knowledge_table();
  $stmt = $pdo->prepare("
    SELECT id, title, response_text, category, usage_count
    FROM {$table}
    WHERE account_id = ?
      AND is_approved = 1
      AND is_active = 1
      AND source NOT IN ('ai_suggestion', 'seller_reply_candidate')
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
      'Antes de crear un tema nuevo, revisa existing_topics. Si la respuesta habla del mismo tema reutilizable que un topico existente, reutiliza exactamente su context_key y su title aunque el vendedor use palabras distintas.',
      'Evita duplicados: crea un context_key corto y estable que represente el topico canonico reutilizable, por ejemplo ubicacion_sedes, pago_cashea, metodos_pago, delivery_valencia, precio_bloques_rojos.',
      'No crees topicos demasiado especificos si el dato aplica a toda la empresa. Por ejemplo direccion, ubicacion, sedes, sucursales, referencias y como llegar deben caer en ubicacion_sedes.',
      'La respuesta knowledge debe quedar lista para que otra IA la use como referencia interna, no como texto obligatorio para copiar literal.',
      'Responde exclusivamente JSON valido con estas claves: save, reason, context_key, canonical_topic, title, aliases, knowledge, category, confidence.',
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
  if (preg_match('/^([a-z0-9_]+)\s*:\s*(.+)$/u', $contextKey, $match)) {
    $category = ai_knowledge_clean_text((string) $match[1], 60);
    $contextKey = (string) $match[2];
  }
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
    $sourceChannel = ai_knowledge_clean_text($meta['source_channel'] ?? 'crm', 60);
    $recentMessages = is_array($meta['recent_messages'] ?? null)
      ? $meta['recent_messages']
      : ai_knowledge_recent_messages($pdo, $conversationId, 12);

    $analysisContext = [
      'account_id' => $accountId,
      'conversation_id' => $conversationId,
      'provider' => ai_knowledge_clean_text($meta['provider'] ?? ($conversation['external_source'] ?? ''), 60),
      'source_channel' => $sourceChannel,
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
      'existing_topics' => ai_knowledge_existing_topics($pdo, $accountId),
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
    if ($knowledge === '') return;

    $rawContextKey = ai_knowledge_clean_text((string) ($analysis['context_key'] ?? ''), 140);
    $topic = ai_knowledge_resolve_canonical_topic($pdo, $accountId, $analysis, $analysisContext);
    $category = ai_knowledge_clean_text($topic['category'] ?? $category, 60);
    if (!in_array($category, $allowedCategories, true)) $category = 'general';
    $contextKey = ai_knowledge_normalize_context_key((string) ($topic['context_key'] ?? $rawContextKey), $category);
    if ($contextKey === '') return;

    $title = ai_knowledge_clean_text($topic['title'] ?? ($analysis['title'] ?? ''), 140);
    if ($title === '') $title = ai_knowledge_title_from_text($knowledge);
    $aliases = is_array($topic['aliases'] ?? null) ? $topic['aliases'] : [];
    $aliases = array_values(array_filter(array_map(static fn($alias) => ai_knowledge_clean_text((string) $alias, 80), $aliases)));

    $table = ai_knowledge_table();
    $createdBy = max(0, (int) ($meta['operator_id'] ?? ($_SESSION['user_id'] ?? 0))) ?: null;
    $responseHash = ai_knowledge_hash($knowledge);
    $contextHash = ai_knowledge_hash($contextKey);
    $newEvidence = [
      'conversation_id' => $conversationId,
      'message_id' => $messageId,
      'operator_id' => (int) ($meta['operator_id'] ?? ($_SESSION['user_id'] ?? 0)),
      'operator_username' => ai_knowledge_clean_text($meta['operator_username'] ?? ($_SESSION['username'] ?? ''), 80),
      'source_channel' => $sourceChannel,
      'official_channel_name' => ai_knowledge_clean_text($meta['official_channel_name'] ?? '', 120),
      'reply' => $reply,
      'knowledge' => $knowledge,
      'captured_at' => gmdate('c'),
    ];

    $existing = null;
    $existingStmt = $pdo->prepare("SELECT id, analysis_json, source, is_approved, is_active FROM {$table} WHERE account_id=? AND context_hash=? LIMIT 1");
    $existingStmt->execute([$accountId, $contextHash]);
    $existing = $existingStmt->fetch() ?: null;

    $existingPayload = ai_knowledge_decode_analysis_payload($existing['analysis_json'] ?? null);
    $evidence = ai_knowledge_merge_evidence(
      is_array($existingPayload['evidence'] ?? null) ? $existingPayload['evidence'] : [],
      $newEvidence
    );
    $distinctConversationCount = ai_knowledge_distinct_conversation_count($evidence);
    $threshold = ai_knowledge_learning_threshold();
    $source = $distinctConversationCount >= $threshold ? 'seller_reply' : 'seller_reply_candidate';
    $analysisJson = json_encode([
      'analysis' => $analysis,
      'context_key' => $contextKey,
      'raw_context_key' => $rawContextKey,
      'canonical_topic' => $title,
      'topic_aliases' => $aliases,
      'topic_source' => ai_knowledge_clean_text($topic['topic_source'] ?? 'model', 40),
      'reason' => ai_knowledge_clean_text($analysis['reason'] ?? '', 500),
      'source_channel' => $sourceChannel,
      'evidence' => $evidence,
      'evidence_conversation_count' => $distinctConversationCount,
      'minimum_distinct_conversations' => $threshold,
      'learned_at' => gmdate('c'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

    $stmt = $pdo->prepare("
      INSERT INTO {$table}
        (account_id, title, response_text, raw_response_text, response_hash, context_hash, category, source, source_conversation_id, source_message_id, analysis_json, confidence, usage_count, created_by, is_approved, is_active)
      VALUES
        (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0)
      ON DUPLICATE KEY UPDATE
        updated_at = NOW(),
        source_conversation_id = VALUES(source_conversation_id),
        source_message_id = VALUES(source_message_id),
        raw_response_text = VALUES(raw_response_text),
        analysis_json = VALUES(analysis_json),
        confidence = IF(confidence < VALUES(confidence), VALUES(confidence), confidence),
        usage_count = VALUES(usage_count),
        title = IF(is_approved = 1, title, VALUES(title)),
        response_text = IF(is_approved = 1, response_text, VALUES(response_text)),
        response_hash = IF(is_approved = 1, response_hash, VALUES(response_hash)),
        context_hash = IF(is_approved = 1, context_hash, VALUES(context_hash)),
        category = IF(is_approved = 1, category, VALUES(category)),
        source = IF(is_approved = 1 AND source != 'seller_reply_candidate', source, VALUES(source))
    ");
    $stmt->execute([
      $accountId,
      $title,
      $knowledge,
      $reply,
      $responseHash,
      $contextHash,
      $category,
      $source,
      $conversationId,
      $messageId,
      $analysisJson,
      $confidence,
      $distinctConversationCount,
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
