<?php
declare(strict_types=1);

require_once __DIR__ . '/auth/require_auth.php';
require_once __DIR__ . '/config/navigation.php';
require_once __DIR__ . '/config/ai_knowledge.php';

require_permission('manage_integrations');

accounts_ensure_schema($pdo);
ai_knowledge_ensure_schema($pdo);

$table = ai_knowledge_table();
$accountsTable = accounts_table();
$accountOptions = nav_fetch_account_options($pdo);
$requestAccountId = accounts_request_account_id($pdo);
$selectedAccountId = is_super_admin() ? $requestAccountId : current_account_id();
$messages = [];
$errors = [];

function ai_knowledge_can_manage_account(int $accountId): bool {
  return $accountId > 0 && (is_super_admin() || $accountId === current_account_id());
}

function ai_knowledge_fetch_item(PDO $pdo, string $table, int $id): ?array {
  $stmt = $pdo->prepare("SELECT * FROM {$table} WHERE id=? LIMIT 1");
  $stmt->execute([$id]);
  $item = $stmt->fetch();
  return $item ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $csrf = (string) ($_POST['csrf'] ?? '');
  if (!$csrf || !isset($_SESSION['csrf']) || !hash_equals((string) $_SESSION['csrf'], $csrf)) {
    $errors[] = 'Token de seguridad inválido.';
  } else {
    try {
      if (isset($_POST['create_response_submit'])) {
        $accountId = is_super_admin() ? max(0, (int) ($_POST['account_id'] ?? 0)) : current_account_id();
        $title = ai_knowledge_clean_text($_POST['title'] ?? '', 140);
        $response = ai_knowledge_clean_text($_POST['response_text'] ?? '', 2000);
        if (!ai_knowledge_can_manage_account($accountId)) {
          $errors[] = 'Selecciona una cuenta válida para guardar la respuesta.';
        } elseif ($response === '') {
          $errors[] = 'La respuesta no puede estar vacía.';
        } else {
          $hash = ai_knowledge_hash($response);
          if ($title === '') $title = ai_knowledge_title_from_text($response);
          $approved = isset($_POST['is_approved']) ? 1 : 0;
          $active = isset($_POST['is_active']) ? 1 : 0;
          $approvedBy = $approved ? ((int) ($_SESSION['user_id'] ?? 0) ?: null) : null;
          $approvedAt = $approved ? date('Y-m-d H:i:s') : null;

          $stmt = $pdo->prepare("
            INSERT INTO {$table}
              (account_id, title, response_text, response_hash, category, source, is_approved, is_active, created_by, approved_by, approved_at)
            VALUES
              (?, ?, ?, ?, 'manual', 'manual', ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
              title = VALUES(title),
              response_text = VALUES(response_text),
              is_approved = VALUES(is_approved),
              is_active = VALUES(is_active),
              approved_by = VALUES(approved_by),
              approved_at = VALUES(approved_at),
              updated_at = NOW()
          ");
          $stmt->execute([
            $accountId,
            $title,
            $response,
            $hash,
            $approved,
            $active,
            (int) ($_SESSION['user_id'] ?? 0) ?: null,
            $approvedBy,
            $approvedAt,
          ]);
          $messages[] = 'Conocimiento guardado.';
        }
      } elseif (isset($_POST['update_response_submit'])) {
        $itemId = max(0, (int) ($_POST['item_id'] ?? 0));
        $item = $itemId > 0 ? ai_knowledge_fetch_item($pdo, $table, $itemId) : null;
        if (!$item || !ai_knowledge_can_manage_account((int) $item['account_id'])) {
          $errors[] = 'No se encontró la respuesta o no tienes permiso para editarla.';
        } else {
          $title = ai_knowledge_clean_text($_POST['title'] ?? '', 140);
          $response = ai_knowledge_clean_text($_POST['response_text'] ?? '', 2000);
          if ($response === '') {
            $errors[] = 'La respuesta no puede estar vacía.';
          } else {
            if ($title === '') $title = ai_knowledge_title_from_text($response);
            $approved = isset($_POST['is_approved']) ? 1 : 0;
            $active = isset($_POST['is_active']) ? 1 : 0;
            $wasApproved = (int) ($item['is_approved'] ?? 0) === 1;
            $approvedBy = $approved ? ($wasApproved ? ($item['approved_by'] ?: null) : ((int) ($_SESSION['user_id'] ?? 0) ?: null)) : null;
            $approvedAt = $approved ? ($wasApproved ? ($item['approved_at'] ?: date('Y-m-d H:i:s')) : date('Y-m-d H:i:s')) : null;

            $stmt = $pdo->prepare("
              UPDATE {$table}
              SET title=?,
                  response_text=?,
                  response_hash=?,
                  is_approved=?,
                  is_active=?,
                  approved_by=?,
                  approved_at=?,
                  updated_at=NOW()
              WHERE id=?
            ");
            $stmt->execute([
              $title,
              $response,
              ai_knowledge_hash($response),
              $approved,
              $active,
              $approvedBy,
              $approvedAt,
              $itemId,
            ]);
            $messages[] = 'Conocimiento actualizado.';
          }
        }
      } elseif (isset($_POST['delete_response_submit'])) {
        $itemId = max(0, (int) ($_POST['item_id'] ?? 0));
        $item = $itemId > 0 ? ai_knowledge_fetch_item($pdo, $table, $itemId) : null;
        if (!$item || !ai_knowledge_can_manage_account((int) $item['account_id'])) {
          $errors[] = 'No se encontró la respuesta o no tienes permiso para eliminarla.';
        } else {
          $stmt = $pdo->prepare("DELETE FROM {$table} WHERE id=?");
          $stmt->execute([$itemId]);
          $messages[] = 'Conocimiento eliminado.';
        }
      }
    } catch (Throwable $e) {
      ai_knowledge_log_error('Error gestionando respuestas IA', ['error' => $e->getMessage()]);
      $errors[] = 'No se pudo completar la operación.';
    }
  }
}

$where = '';
$params = [];
if ($selectedAccountId > 0) {
  $where = 'WHERE k.account_id = ?';
  $params[] = $selectedAccountId;
} elseif (!is_super_admin()) {
  $where = 'WHERE k.account_id = ?';
  $params[] = current_account_id();
}

$stmt = $pdo->prepare("
  SELECT k.*, a.name AS account_name, a.slug AS account_slug
  FROM {$table} k
  LEFT JOIN {$accountsTable} a ON a.id = k.account_id
  {$where}
  ORDER BY k.is_approved ASC, k.is_active DESC, k.updated_at DESC, k.id DESC
");
$stmt->execute($params);
$items = $stmt->fetchAll() ?: [];

$totals = ['all' => count($items), 'pending' => 0, 'approved' => 0, 'active' => 0];
foreach ($items as $item) {
  if ((int) $item['is_approved'] === 1) $totals['approved']++;
  else $totals['pending']++;
  if ((int) $item['is_active'] === 1) $totals['active']++;
}

$currentAction = (string) ($_SERVER['REQUEST_URI'] ?? '/ai_knowledge.php');
$pageTitle = 'Banco de conocimiento IA - Pixels Studio';
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($pageTitle) ?></title>
  <link rel="stylesheet" href="/css/app.css">
  <style>
    .ai-kpi-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin:26px 0;}
    .ai-kpi{background:#fff;border:1px solid #dce9f7;border-radius:18px;padding:18px;box-shadow:0 18px 45px rgba(5,14,33,.06);}
    .ai-kpi strong{display:block;font-size:32px;line-height:1;color:#060d1d;}
    .ai-kpi span{display:block;margin-top:8px;text-transform:uppercase;letter-spacing:.08em;font-weight:900;color:#68748b;font-size:12px;}
    .ai-main-grid{display:grid;grid-template-columns:360px minmax(0,1fr);gap:20px;align-items:start;}
    .ai-panel,.ai-response-card{background:#fff;border:1px solid #dce9f7;border-radius:22px;box-shadow:0 18px 45px rgba(5,14,33,.06);}
    .ai-panel{padding:22px;position:sticky;top:18px;}
    .ai-panel h2,.ai-list h2{margin:0 0 8px;color:#060d1d;}
    .ai-panel p{margin:0 0 18px;color:#68748b;font-weight:800;line-height:1.35;}
    .ai-form-row{display:grid;gap:8px;margin-bottom:14px;}
    .ai-form-row label,.ai-card-label{font-weight:900;text-transform:uppercase;letter-spacing:.08em;color:#68748b;font-size:12px;}
    .ai-input,.ai-select,.ai-textarea{width:100%;border:1px solid #d8e6f4;border-radius:14px;background:#fff;padding:13px 14px;font:inherit;font-weight:800;color:#060d1d;}
    .ai-select{appearance:auto;}
    .ai-textarea{min-height:150px;resize:vertical;line-height:1.45;}
    .ai-original-reply{border:1px solid #e1eaf5;background:#f7faff;border-radius:16px;padding:14px;color:#68748b;font-weight:800;line-height:1.45;}
    .ai-check-row{display:flex;gap:12px;flex-wrap:wrap;align-items:center;margin:14px 0;}
    .ai-check{display:inline-flex;align-items:center;gap:8px;font-weight:900;color:#060d1d;}
    .ai-check input{width:20px;height:20px;accent-color:#8738ff;}
    .ai-toggle{display:inline-flex;align-items:center;gap:10px;font-weight:900;color:#060d1d;}
    .ai-toggle input{position:absolute;opacity:0;pointer-events:none;}
    .ai-toggle-control{width:50px;height:28px;border-radius:999px;background:#e7edf6;position:relative;border:1px solid #d6e4f2;transition:.18s ease;}
    .ai-toggle-control::after{content:"";position:absolute;width:20px;height:20px;border-radius:50%;top:3px;left:4px;background:#68748b;transition:.18s ease;}
    .ai-toggle input:checked + .ai-toggle-control{background:#060d1d;border-color:#060d1d;}
    .ai-toggle input:checked + .ai-toggle-control::after{left:24px;background:#19c7dd;}
    .ai-submit,.ai-secondary,.ai-danger{border:0;border-radius:999px;padding:14px 18px;font-weight:950;cursor:pointer;text-decoration:none;display:inline-flex;justify-content:center;align-items:center;}
    .ai-submit{background:#060d1d;color:#fff;width:100%;}
    .ai-secondary{background:#eef8ff;color:#007fa6;border:1px solid #cbefff;}
    .ai-danger{background:#fff1f3;color:#bd1832;border:1px solid #ffc8d0;}
    .ai-list{display:grid;gap:14px;}
    .ai-response-card{padding:18px;}
    .ai-card-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;margin-bottom:14px;}
    .ai-card-title{display:flex;gap:10px;align-items:flex-start;min-width:0;}
    .ai-card-title strong{display:block;font-size:20px;color:#060d1d;line-height:1.1;}
    .ai-card-title span{display:block;color:#68748b;font-weight:800;margin-top:4px;}
    .ai-id{flex:0 0 auto;background:#060d1d;color:#fff;border-radius:14px;padding:10px 12px;font-weight:950;}
    .ai-pill-row{display:flex;gap:8px;flex-wrap:wrap;}
    .ai-pill{display:inline-flex;align-items:center;border-radius:999px;padding:8px 11px;font-weight:950;font-size:12px;border:1px solid #dce9f7;color:#68748b;background:#f6f9fd;}
    .ai-pill.ok{color:#138945;background:#effcf3;border-color:#a9efbf;}
    .ai-pill.on{color:#007fa6;background:#e9fbff;border-color:#bdf4ff;}
    .ai-pill.pending{color:#a76d00;background:#fff8e8;border-color:#ffd98b;}
    .ai-card-actions{display:flex;gap:10px;align-items:center;margin-top:14px;}
    .ai-card-actions .ai-submit{width:auto;min-width:140px;}
    .ai-empty{padding:30px;background:#fff;border:1px dashed #cfe2f4;border-radius:22px;color:#68748b;font-weight:900;}
    .ai-alert{border-radius:16px;padding:14px 16px;margin:12px 0;font-weight:900;}
    .ai-alert.ok{background:#effcf3;color:#138945;border:1px solid #a9efbf;}
    .ai-alert.error{background:#fff1f3;color:#bd1832;border:1px solid #ffc8d0;}
    @media (max-width:1100px){.ai-main-grid{grid-template-columns:1fr}.ai-panel{position:static}.ai-kpi-grid{grid-template-columns:repeat(2,minmax(0,1fr));}}
    @media (max-width:720px){.ai-kpi-grid{grid-template-columns:1fr}.ai-card-head,.ai-card-actions{flex-direction:column;align-items:stretch}.ai-card-actions .ai-submit{width:100%;}.ai-response-card,.ai-panel{border-radius:18px;}}
  </style>
</head>
<body class="dashboard-page config-page">
  <main class="dashboard-shell">
    <header class="dashboard-header">
      <div>
        <p class="eyebrow">Pixels Studio</p>
        <h1>Banco de conocimiento IA</h1>
        <p class="dashboard-subtitle">Aprueba y activa conocimiento aprendido de respuestas reales del equipo para que la IA venda con el criterio de cada cuenta.</p>
      </div>
      <?php nav_render_config_top_nav($pdo, 'ai_knowledge.php', $selectedAccountId); ?>
    </header>

    <?php foreach ($messages as $message): ?><div class="ai-alert ok"><?= h($message) ?></div><?php endforeach; ?>
    <?php foreach ($errors as $error): ?><div class="ai-alert error"><?= h($error) ?></div><?php endforeach; ?>

    <section class="ai-kpi-grid" aria-label="Resumen de respuestas IA">
      <article class="ai-kpi"><strong><?= (int) $totals['all'] ?></strong><span>Total</span></article>
      <article class="ai-kpi"><strong><?= (int) $totals['pending'] ?></strong><span>Pendientes</span></article>
      <article class="ai-kpi"><strong><?= (int) $totals['approved'] ?></strong><span>Aprobadas</span></article>
      <article class="ai-kpi"><strong><?= (int) $totals['active'] ?></strong><span>Activas</span></article>
    </section>

    <div class="admin-layout">
      <?php nav_render_admin_side_nav('ai_knowledge'); ?>

      <section class="admin-content ai-list ai-main-grid">
        <aside class="ai-panel">
          <h2>Agregar conocimiento manual</h2>
          <p>Carga reglas, argumentos o respuestas base que la IA pueda reutilizar como referencia interna.</p>
          <form method="post" action="<?= h($currentAction) ?>">
            <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf'] ?? '') ?>">
            <?php if (is_super_admin()): ?>
              <div class="ai-form-row">
                <label for="account_id">Cuenta</label>
                <select class="ai-select" id="account_id" name="account_id" required>
                  <option value="">Selecciona una cuenta</option>
                  <?php foreach ($accountOptions as $account): ?>
                    <option value="<?= (int) $account['id'] ?>" <?= $selectedAccountId === (int) $account['id'] ? 'selected' : '' ?>><?= h((string) $account['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            <?php endif; ?>
            <div class="ai-form-row">
              <label for="title">Título interno</label>
              <input class="ai-input" id="title" name="title" placeholder="Ej: Cotización de disponibilidad">
            </div>
            <div class="ai-form-row">
              <label for="response_text">Conocimiento</label>
              <textarea class="ai-textarea" id="response_text" name="response_text" placeholder="Ej: Cuando pregunten por disponibilidad, confirma el producto, pide cantidad y ofrece reservar mientras se valida stock."></textarea>
            </div>
            <div class="ai-check-row">
              <label class="ai-check"><input type="checkbox" name="is_approved" value="1"> Aprobada</label>
              <label class="ai-toggle">
                <input type="checkbox" name="is_active" value="1">
                <span class="ai-toggle-control"></span>
                Activa
              </label>
            </div>
            <button class="ai-submit" type="submit" name="create_response_submit" value="1">Guardar conocimiento</button>
          </form>
        </aside>

        <div>
          <h2>Conocimiento por revisar</h2>
          <?php if (!$items): ?>
            <div class="ai-empty">Todavía no hay conocimiento para revisar. Cuando el equipo responda mensajes útiles, la IA analizará esas respuestas y las traerá aquí como pendientes.</div>
          <?php endif; ?>

          <?php foreach ($items as $item): ?>
            <article class="ai-response-card">
              <div class="ai-card-head">
                <div class="ai-card-title">
                  <span class="ai-id">#<?= (int) $item['id'] ?></span>
                  <div>
                    <strong><?= h((string) $item['title']) ?></strong>
                    <span><?= h((string) ($item['account_name'] ?? 'Cuenta')) ?> · <?= h((string) ($item['source'] ?? '')) ?></span>
                  </div>
                </div>
                <div class="ai-pill-row">
                  <?php if ((int) $item['is_approved'] === 1): ?><span class="ai-pill ok">Aprobada</span><?php else: ?><span class="ai-pill pending">Pendiente</span><?php endif; ?>
                  <?php if ((int) $item['is_active'] === 1): ?><span class="ai-pill on">Activa</span><?php else: ?><span class="ai-pill">Pausada</span><?php endif; ?>
                  <?php if (!empty($item['category'])): ?><span class="ai-pill"><?= h((string) $item['category']) ?></span><?php endif; ?>
                  <?php if ((float) ($item['confidence'] ?? 0) > 0): ?><span class="ai-pill"><?= h(number_format((float) $item['confidence'], 0, ',', '.')) ?>% confianza</span><?php endif; ?>
                </div>
              </div>
              <form method="post" action="<?= h($currentAction) ?>">
                <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf'] ?? '') ?>">
                <input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">
                <div class="ai-form-row">
                  <label class="ai-card-label" for="title_<?= (int) $item['id'] ?>">Título</label>
                  <input class="ai-input" id="title_<?= (int) $item['id'] ?>" name="title" value="<?= h((string) $item['title']) ?>">
                </div>
                <div class="ai-form-row">
                  <label class="ai-card-label" for="response_<?= (int) $item['id'] ?>">Conocimiento</label>
                  <textarea class="ai-textarea" id="response_<?= (int) $item['id'] ?>" name="response_text"><?= h((string) $item['response_text']) ?></textarea>
                </div>
                <?php if ((string) ($item['source'] ?? '') === 'seller_reply' && trim((string) ($item['raw_response_text'] ?? '')) !== ''): ?>
                  <div class="ai-form-row">
                    <span class="ai-card-label">Respuesta original del vendedor</span>
                    <div class="ai-original-reply"><?= nl2br(h((string) $item['raw_response_text'])) ?></div>
                  </div>
                <?php endif; ?>
                <div class="ai-check-row">
                  <label class="ai-check"><input type="checkbox" name="is_approved" value="1" <?= (int) $item['is_approved'] === 1 ? 'checked' : '' ?>> Aprobada</label>
                  <label class="ai-toggle">
                    <input type="checkbox" name="is_active" value="1" <?= (int) $item['is_active'] === 1 ? 'checked' : '' ?>>
                    <span class="ai-toggle-control"></span>
                    Activa
                  </label>
                </div>
                <div class="ai-card-actions">
                  <button class="ai-submit" type="submit" name="update_response_submit" value="1">Guardar</button>
                  <button class="ai-danger" type="submit" name="delete_response_submit" value="1" onclick="return confirm('¿Eliminar este conocimiento de la biblioteca IA?');">Eliminar</button>
                </div>
              </form>
            </article>
          <?php endforeach; ?>
        </div>
      </section>
    </div>
  </main>
  <script src="/js/navigation.js"></script>
</body>
</html>
