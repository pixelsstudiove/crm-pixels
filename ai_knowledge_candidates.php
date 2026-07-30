<?php
declare(strict_types=1);

require_once __DIR__ . '/auth/require_auth.php';
require_once __DIR__ . '/config/navigation.php';
require_once __DIR__ . '/config/ai_knowledge.php';
require_once __DIR__ . '/config/conversations.php';

require_permission('manage_integrations');

accounts_ensure_schema($pdo);
ai_knowledge_ensure_schema($pdo);
conv_ensure_schema($pdo);

$table = ai_knowledge_table();
$accountsTable = accounts_table();
$conversationsTable = conv_conversations_table();
$usersTable = safe_identifier((string) app_config('database.users_table', 'users'), 'users');
$accountOptions = nav_fetch_account_options($pdo);
$requestAccountId = accounts_request_account_id($pdo);
$selectedAccountId = is_super_admin() ? $requestAccountId : current_account_id();
$threshold = ai_knowledge_learning_threshold();

$statusFilter = strtolower(trim((string) ($_GET['status'] ?? 'all')));
if (!in_array($statusFilter, ['all', 'candidate', 'ready'], true)) $statusFilter = 'all';
$sourceFilter = strtolower(trim((string) ($_GET['source_channel'] ?? 'all')));
if (!in_array($sourceFilter, ['all', 'crm', 'canal_oficial'], true)) $sourceFilter = 'all';
$search = trim((string) ($_GET['q'] ?? ''));
$currentAction = account_url('ai_knowledge_candidates.php', [], accounts_request_account_slug($pdo) ?: null);
$unifyResult = null;
$unifyError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'unify_repeated') {
  $targetAccountId = is_super_admin() ? max(0, (int) ($_POST['account_id'] ?? $selectedAccountId)) : current_account_id();
  try {
    $unifyResult = ai_knowledge_unify_repeated_candidates($pdo, $targetAccountId);
  } catch (Throwable $e) {
    $unifyError = 'No se pudieron unificar las respuestas repetidas. Revisa el log técnico para más detalles.';
    ai_knowledge_log_error('Fallo al unificar candidatos desde el log IA', [
      'account_id' => $targetAccountId,
      'error' => $e->getMessage(),
    ]);
  }
}

function ai_candidate_clean_excerpt(string $text, int $max = 240): string {
  $text = preg_replace('/\s+/u', ' ', trim($text)) ?: trim($text);
  if ($text === '') return 'Sin texto disponible.';
  $length = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
  if ($length <= $max) return $text;
  return (function_exists('mb_substr') ? mb_substr($text, 0, $max - 1, 'UTF-8') : substr($text, 0, $max - 1)) . '...';
}

function ai_candidate_date($value, string $fallback = 'Sin fecha'): string {
  return function_exists('app_datetime') ? app_datetime($value, 'd/m/Y H:i', $fallback) : ((string) $value ?: $fallback);
}

function ai_candidate_source_label(string $source): string {
  return [
    'crm' => 'CRM',
    'canal_oficial' => 'Canal oficial',
  ][$source] ?? 'Sin origen';
}

function ai_candidate_payload(array $row): array {
  $payload = ai_knowledge_decode_analysis_payload($row['analysis_json'] ?? null);
  return is_array($payload) ? $payload : [];
}

function ai_candidate_evidence(array $payload): array {
  $evidence = $payload['evidence'] ?? [];
  return is_array($evidence) ? $evidence : [];
}

function ai_candidate_latest_evidence(array $evidence): array {
  if (!$evidence) return [];
  usort($evidence, static function (array $a, array $b): int {
    return strcmp((string) ($b['captured_at'] ?? ''), (string) ($a['captured_at'] ?? ''));
  });
  return $evidence[0] ?? [];
}

function ai_candidate_distinct_count(array $row, array $payload, array $evidence): int {
  $fromPayload = (int) ($payload['evidence_conversation_count'] ?? 0);
  if ($fromPayload > 0) return $fromPayload;
  $fromUsage = (int) ($row['usage_count'] ?? 0);
  if ($fromUsage > 0) return $fromUsage;
  return ai_knowledge_distinct_conversation_count($evidence);
}

function ai_candidate_conversation_href(array $row, array $latest): string {
  $accountSlug = trim((string) ($row['account_slug'] ?? ''));
  $publicUid = trim((string) ($row['conversation_public_uid'] ?? ''));
  $publicId = trim((string) ($row['conversation_public_id'] ?? ''));
  $conversationId = $publicUid !== '' ? $publicUid : ($publicId !== '' ? $publicId : (string) ((int) ($latest['conversation_id'] ?? $row['source_conversation_id'] ?? 0)));
  if ($conversationId === '' || $conversationId === '0') return '';
  return account_url('conversation_debug.php', ['id' => $conversationId], $accountSlug !== '' ? $accountSlug : null);
}

$whereParts = ["k.source IN ('seller_reply_candidate', 'seller_reply')", "k.is_approved = 0"];
$params = [];
if ($selectedAccountId > 0) {
  $whereParts[] = 'k.account_id = ?';
  $params[] = $selectedAccountId;
} elseif (!is_super_admin()) {
  $whereParts[] = 'k.account_id = ?';
  $params[] = current_account_id();
}
if ($statusFilter === 'candidate') {
  $whereParts[] = "k.source = 'seller_reply_candidate'";
} elseif ($statusFilter === 'ready') {
  $whereParts[] = "k.source = 'seller_reply'";
}
if ($sourceFilter !== 'all') {
  $whereParts[] = 'k.analysis_json LIKE ?';
  $params[] = '%"source_channel":"' . $sourceFilter . '"%';
}
if ($search !== '') {
  $whereParts[] = '(k.title LIKE ? OR k.response_text LIKE ? OR k.raw_response_text LIKE ? OR k.category LIKE ?)';
  $like = '%' . $search . '%';
  array_push($params, $like, $like, $like, $like);
}
$where = 'WHERE ' . implode(' AND ', $whereParts);

$stmt = $pdo->prepare("
  SELECT k.*,
         a.name AS account_name,
         a.slug AS account_slug,
         c.public_id AS conversation_public_id,
         c.public_uid AS conversation_public_uid,
         u.username AS created_by_username
  FROM {$table} k
  LEFT JOIN {$accountsTable} a ON a.id = k.account_id
  LEFT JOIN {$conversationsTable} c ON c.id = k.source_conversation_id
  LEFT JOIN {$usersTable} u ON u.id = k.created_by
  {$where}
  ORDER BY
    CASE WHEN k.source = 'seller_reply' THEN 0 ELSE 1 END,
    k.updated_at DESC,
    k.id DESC
  LIMIT 200
");
$stmt->execute($params);
$items = $stmt->fetchAll() ?: [];

$totals = [
  'all' => count($items),
  'candidate' => 0,
  'ready' => 0,
  'crm' => 0,
  'official' => 0,
];
foreach ($items as $item) {
  $payload = ai_candidate_payload($item);
  $sourceChannel = (string) ($payload['source_channel'] ?? '');
  if ((string) ($item['source'] ?? '') === 'seller_reply') $totals['ready']++;
  else $totals['candidate']++;
  if ($sourceChannel === 'crm') $totals['crm']++;
  if ($sourceChannel === 'canal_oficial') $totals['official']++;
}

$navParams = [
  'status' => $statusFilter !== 'all' ? $statusFilter : null,
  'source_channel' => $sourceFilter !== 'all' ? $sourceFilter : null,
  'q' => $search !== '' ? $search : null,
];
$pageTitle = 'Log de aprendizaje IA - Pixels Studio';
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($pageTitle) ?></title>
  <link rel="stylesheet" href="/css/app.css">
  <style>
    .learning-kpi-grid{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:14px;margin:26px 0;}
    .learning-kpi{background:#fff;border:1px solid #dce9f7;border-radius:24px;padding:20px;box-shadow:0 18px 45px rgba(5,14,33,.06);}
    .learning-kpi strong{display:block;font-size:34px;line-height:1;color:#060d1d;}
    .learning-kpi span{display:block;margin-top:9px;text-transform:uppercase;letter-spacing:.1em;font-weight:950;color:#68748b;font-size:12px;}
    .learning-panel{background:#fff;border:1px solid #dce9f7;border-radius:26px;padding:20px;box-shadow:0 18px 45px rgba(5,14,33,.06);}
    .learning-filters{display:grid;grid-template-columns:minmax(240px,1.2fr) minmax(180px,.7fr) minmax(180px,.7fr) auto;gap:14px;align-items:end;margin-bottom:22px;}
    .learning-field{display:grid;gap:8px;}
    .learning-field label{font-size:12px;text-transform:uppercase;letter-spacing:.1em;color:#68748b;font-weight:950;}
    .learning-input,.learning-select{width:100%;border:1px solid #d8e6f4;border-radius:16px;background:#fff;color:#060d1d;font:inherit;font-weight:850;padding:14px 15px;}
    .learning-select{appearance:auto;}
    .learning-submit,.learning-link{border:0;border-radius:999px;background:#060d1d;color:#fff;padding:15px 20px;font-weight:950;text-decoration:none;display:inline-flex;justify-content:center;align-items:center;min-height:52px;cursor:pointer;}
    .learning-submit.unify{background:#8738ff;box-shadow:0 14px 35px rgba(135,56,255,.22);}
    .learning-submit.unify:hover{background:#060d1d;color:#fff;}
    .learning-link.secondary{background:#eef8ff;color:#007fa6;border:1px solid #cbefff;}
    .learning-unify{display:flex;align-items:center;justify-content:space-between;gap:18px;margin-bottom:18px;}
    .learning-unify h2{margin:0 0 6px;color:#060d1d;font-size:22px;line-height:1.1;}
    .learning-unify p{margin:0;color:#68748b;font-weight:850;line-height:1.45;max-width:760px;}
    .learning-alert{margin:0 0 18px;border:1px solid #c7f1d7;background:#effcf4;color:#176c38;border-radius:20px;padding:15px 18px;font-weight:900;line-height:1.45;}
    .learning-alert.error{border-color:#ffc4c4;background:#fff0f0;color:#b42336;}
    .learning-log{display:grid;gap:14px;}
    .learning-entry{position:relative;background:#fff;border:1px solid #dce9f7;border-radius:26px;padding:20px 20px 20px 28px;box-shadow:0 18px 45px rgba(5,14,33,.06);overflow:hidden;}
    .learning-entry::before{content:"";position:absolute;left:0;top:0;bottom:0;width:6px;background:#19c7dd;}
    .learning-entry.ready::before{background:#8738ff;}
    .learning-entry-head{display:flex;justify-content:space-between;gap:18px;align-items:flex-start;margin-bottom:16px;}
    .learning-title{display:flex;gap:12px;align-items:flex-start;min-width:0;}
    .learning-id{background:#060d1d;color:#fff;border-radius:16px;padding:11px 12px;font-weight:950;line-height:1;flex:0 0 auto;}
    .learning-title h2{margin:0;color:#060d1d;font-size:22px;line-height:1.08;}
    .learning-meta{display:flex;flex-wrap:wrap;gap:8px;margin-top:8px;}
    .learning-pill{display:inline-flex;align-items:center;border-radius:999px;padding:8px 11px;font-size:12px;font-weight:950;background:#f6f9fd;border:1px solid #dce9f7;color:#68748b;}
    .learning-pill.ready{background:#f5efff;border-color:#d8c4ff;color:#6e2de2;}
    .learning-pill.pending{background:#fff8e8;border-color:#ffd98b;color:#a76d00;}
    .learning-pill.channel{background:#e9fbff;border-color:#bdf4ff;color:#007fa6;}
    .learning-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(280px,.55fr);gap:16px;}
    .learning-block{border:1px solid #e2edf8;background:#f8fbff;border-radius:18px;padding:16px;}
    .learning-block strong{display:block;color:#060d1d;font-size:15px;margin-bottom:8px;}
    .learning-block p{margin:0;color:#68748b;font-weight:800;line-height:1.45;}
    .learning-evidence{display:grid;gap:10px;}
    .learning-progress{height:10px;border-radius:999px;background:#e9eef6;overflow:hidden;margin-top:10px;}
    .learning-progress span{display:block;height:100%;border-radius:inherit;background:#8738ff;min-width:8px;}
    .learning-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:16px;}
    .learning-empty{padding:32px;border:1px dashed #cfe2f4;border-radius:24px;background:#fff;color:#68748b;font-weight:900;line-height:1.4;}
    @media (max-width:1180px){.learning-kpi-grid{grid-template-columns:repeat(2,minmax(0,1fr));}.learning-filters{grid-template-columns:1fr 1fr}.learning-submit{width:100%;}.learning-grid{grid-template-columns:1fr;}.learning-unify{align-items:stretch;flex-direction:column;}}
    @media (max-width:720px){.learning-kpi-grid,.learning-filters{grid-template-columns:1fr}.learning-entry-head{flex-direction:column}.learning-panel,.learning-entry{border-radius:20px}.learning-title h2{font-size:19px;}}
  </style>
</head>
<body class="dashboard-page config-page">
  <main class="dashboard-shell">
    <header class="dashboard-header">
      <div>
        <p class="eyebrow">Pixels Studio</p>
        <h1>Log de aprendizaje IA</h1>
        <p class="dashboard-subtitle">Audita las respuestas reales que el CRM está observando antes de convertirlas en conocimiento reutilizable para la IA.</p>
      </div>
      <?php nav_render_config_top_nav($pdo, 'ai_knowledge_candidates.php', $selectedAccountId, $navParams, $navParams); ?>
    </header>

    <section class="learning-kpi-grid" aria-label="Resumen de aprendizaje IA">
      <article class="learning-kpi"><strong><?= (int) $totals['all'] ?></strong><span>En revisión</span></article>
      <article class="learning-kpi"><strong><?= (int) $totals['candidate'] ?></strong><span>Observación</span></article>
      <article class="learning-kpi"><strong><?= (int) $totals['ready'] ?></strong><span>Listas</span></article>
      <article class="learning-kpi"><strong><?= (int) $totals['crm'] ?></strong><span>Desde CRM</span></article>
      <article class="learning-kpi"><strong><?= (int) $threshold ?></strong><span>Mínimo leads</span></article>
    </section>

    <div class="admin-layout">
      <?php nav_render_admin_side_nav('ai_knowledge_candidates'); ?>

      <section class="admin-content">
        <section class="learning-panel learning-unify">
          <div>
            <h2>Unificar respuestas repetidas</h2>
            <p>Agrupa candidatos que hablan del mismo tema, fusiona su evidencia y mueve a listas para revisar los que ya cumplen <?= (int) $threshold ?> conversaciones distintas.</p>
          </div>
          <form method="post" action="<?= h($currentAction) ?>" onsubmit="return confirm('Se unificarán los candidatos repetidos y se eliminarán duplicados. ¿Continuamos?');">
            <input type="hidden" name="action" value="unify_repeated">
            <input type="hidden" name="account_id" value="<?= (int) $selectedAccountId ?>">
            <button class="learning-submit unify" type="submit">Unificar repetidos</button>
          </form>
        </section>

        <?php if (is_array($unifyResult)): ?>
          <div class="learning-alert">
            Unificación completada: <?= (int) ($unifyResult['processed'] ?? 0) ?> candidatos revisados,
            <?= (int) ($unifyResult['groups_unified'] ?? 0) ?> grupos unidos,
            <?= (int) ($unifyResult['duplicates_removed'] ?? 0) ?> duplicados eliminados y
            <?= (int) ($unifyResult['promoted'] ?? 0) ?> temas enviados al siguiente paso.
          </div>
        <?php elseif ($unifyError !== ''): ?>
          <div class="learning-alert error"><?= h($unifyError) ?></div>
        <?php endif; ?>

        <form class="learning-panel learning-filters" method="get" action="<?= h($currentAction) ?>">
          <div class="learning-field">
            <label for="q">Buscar</label>
            <input class="learning-input" id="q" name="q" value="<?= h($search) ?>" placeholder="Título, respuesta, categoría">
          </div>
          <div class="learning-field">
            <label for="status">Estado</label>
            <select class="learning-select" id="status" name="status">
              <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>Todos</option>
              <option value="candidate" <?= $statusFilter === 'candidate' ? 'selected' : '' ?>>En observación</option>
              <option value="ready" <?= $statusFilter === 'ready' ? 'selected' : '' ?>>Listas para revisar</option>
            </select>
          </div>
          <div class="learning-field">
            <label for="source_channel">Origen</label>
            <select class="learning-select" id="source_channel" name="source_channel">
              <option value="all" <?= $sourceFilter === 'all' ? 'selected' : '' ?>>Todos</option>
              <option value="crm" <?= $sourceFilter === 'crm' ? 'selected' : '' ?>>CRM</option>
              <option value="canal_oficial" <?= $sourceFilter === 'canal_oficial' ? 'selected' : '' ?>>Canal oficial</option>
            </select>
          </div>
          <button class="learning-submit" type="submit">Filtrar</button>
        </form>

        <div class="learning-log">
          <?php if (!$items): ?>
            <div class="learning-empty">No hay respuestas candidatas con estos filtros. Cuando el equipo repita una respuesta útil en leads distintos, aparecerá aquí con su evidencia antes de entrar al banco.</div>
          <?php endif; ?>

          <?php foreach ($items as $item): ?>
            <?php
              $payload = ai_candidate_payload($item);
              $analysis = is_array($payload['analysis'] ?? null) ? $payload['analysis'] : [];
              $evidence = ai_candidate_evidence($payload);
              $latest = ai_candidate_latest_evidence($evidence);
              $distinctCount = ai_candidate_distinct_count($item, $payload, $evidence);
              $isReady = (string) ($item['source'] ?? '') === 'seller_reply';
              $sourceChannel = (string) ($payload['source_channel'] ?? ($latest['source_channel'] ?? ''));
              $progress = $threshold > 0 ? min(100, (int) round(($distinctCount / $threshold) * 100)) : 100;
              $href = ai_candidate_conversation_href($item, $latest);
              $reason = trim((string) ($payload['reason'] ?? ($analysis['reason'] ?? '')));
              $canonicalTopic = trim((string) ($payload['canonical_topic'] ?? ($analysis['canonical_topic'] ?? ($item['title'] ?? ''))));
              $contextKey = trim((string) ($payload['context_key'] ?? ($analysis['context_key'] ?? '')));
              $topicSource = trim((string) ($payload['topic_source'] ?? 'model'));
              $topicAliases = $payload['topic_aliases'] ?? ($analysis['aliases'] ?? []);
              if (!is_array($topicAliases)) $topicAliases = [];
              $topicAliases = array_values(array_filter(array_map(static fn($alias) => trim((string) $alias), $topicAliases)));
            ?>
            <article class="learning-entry <?= $isReady ? 'ready' : 'candidate' ?>">
              <div class="learning-entry-head">
                <div class="learning-title">
                  <span class="learning-id">#<?= (int) $item['id'] ?></span>
                  <div>
                    <h2><?= h($canonicalTopic !== '' ? $canonicalTopic : (string) ($item['title'] ?: 'Conocimiento candidato')) ?></h2>
                    <div class="learning-meta">
                      <span class="learning-pill <?= $isReady ? 'ready' : 'pending' ?>"><?= $isReady ? 'Lista para revisar' : 'En observación' ?></span>
                      <span class="learning-pill"><?= h((string) ($item['account_name'] ?? 'Cuenta')) ?></span>
                      <span class="learning-pill channel"><?= h(ai_candidate_source_label($sourceChannel)) ?></span>
                      <?php if (trim((string) ($item['category'] ?? '')) !== ''): ?><span class="learning-pill"><?= h((string) $item['category']) ?></span><?php endif; ?>
                      <?php if ($topicSource !== ''): ?><span class="learning-pill">Normalizado por <?= h($topicSource === 'rule' ? 'regla' : ($topicSource === 'existing' ? 'tópico existente' : 'modelo')) ?></span><?php endif; ?>
                      <?php if ((float) ($item['confidence'] ?? 0) > 0): ?><span class="learning-pill"><?= h(number_format((float) $item['confidence'], 0, ',', '.')) ?>% confianza</span><?php endif; ?>
                    </div>
                  </div>
                </div>
                <div class="learning-pill"><?= h(ai_candidate_date($item['updated_at'] ?? $item['created_at'] ?? '')) ?></div>
              </div>

              <div class="learning-grid">
                <div class="learning-block">
                  <strong>Conocimiento propuesto</strong>
                  <p><?= nl2br(h((string) ($item['response_text'] ?? ''))) ?></p>
                  <?php if ($contextKey !== '' || $topicAliases): ?>
                    <div style="height:12px"></div>
                    <strong>Tópico normalizado</strong>
                    <p>
                      <?= h($canonicalTopic !== '' ? $canonicalTopic : 'Sin nombre') ?>
                      <?php if ($contextKey !== ''): ?> · <?= h($contextKey) ?><?php endif; ?>
                      <?php if ($topicAliases): ?><br>Alias: <?= h(implode(', ', $topicAliases)) ?><?php endif; ?>
                    </p>
                  <?php endif; ?>
                  <?php if ($reason !== ''): ?>
                    <div style="height:12px"></div>
                    <strong>Motivo detectado</strong>
                    <p><?= h($reason) ?></p>
                  <?php endif; ?>
                </div>
                <div class="learning-evidence">
                  <div class="learning-block">
                    <strong>Evidencia acumulada</strong>
                    <p><?= (int) $distinctCount ?> de <?= (int) $threshold ?> conversaciones distintas.</p>
                    <div class="learning-progress" aria-hidden="true"><span style="width:<?= (int) $progress ?>%"></span></div>
                  </div>
                  <div class="learning-block">
                    <strong>Última respuesta tomada en cuenta</strong>
                    <p><?= h(ai_candidate_clean_excerpt((string) ($latest['reply'] ?? $item['raw_response_text'] ?? ''), 260)) ?></p>
                    <p style="margin-top:10px"><?= h(trim((string) ($latest['operator_username'] ?? 'Sin operador'))) ?> · <?= h(ai_candidate_date($latest['captured_at'] ?? $item['updated_at'] ?? '')) ?></p>
                  </div>
                </div>
              </div>

              <div class="learning-actions">
                <?php if ($href !== ''): ?><a class="learning-link" href="<?= h($href) ?>">Abrir conversación</a><?php endif; ?>
                <a class="learning-link secondary" href="<?= h(account_url('ai_knowledge.php', [], trim((string) ($item['account_slug'] ?? '')) ?: null)) ?>">Ir al banco IA</a>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      </section>
    </div>
  </main>
  <script src="/js/navigation.js"></script>
</body>
</html>
