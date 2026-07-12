<?php
// accounts.php
declare(strict_types=1);
require_once __DIR__ . '/auth/require_auth.php';
require_permission('manage_accounts');

$accountsTable = accounts_table();
$errors = [];
$notice = '';

function account_slug_from_name(string $name): string {
  $slug = strtolower(trim($name));
  $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
  $slug = trim($slug, '-');
  return $slug !== '' ? mb_substr($slug, 0, 80) : 'cuenta';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $csrf = (string) ($_POST['csrf'] ?? '');
  if (!$csrf || !isset($_SESSION['csrf']) || !hash_equals((string) $_SESSION['csrf'], $csrf)) {
    $errors[] = 'CSRF inválido. Recarga la página.';
  } else {
    $name = trim((string) ($_POST['name'] ?? ''));
    $slug = account_slug_from_name((string) ($_POST['slug'] ?? $name));
    if (mb_strlen($name) < 3 || mb_strlen($name) > 160) $errors[] = 'El nombre debe tener entre 3 y 160 caracteres.';
    if (!preg_match('/^[a-z0-9-]{3,80}$/', $slug)) $errors[] = 'El slug debe tener entre 3 y 80 caracteres, solo minúsculas, números y guiones.';

    if (!$errors) {
      try {
        $stmt = $pdo->prepare("INSERT INTO {$accountsTable} (name, slug, status) VALUES (?, ?, 'active')");
        $stmt->execute([$name, $slug]);
        $notice = 'Cuenta creada correctamente.';
      } catch (Throwable $e) {
        $errors[] = 'No se pudo crear la cuenta. Verifica que el slug no esté repetido.';
      }
    }
  }
}

$accounts = [];
try {
  $stmt = $pdo->query("SELECT * FROM {$accountsTable} ORDER BY id ASC");
  $accounts = $stmt ? $stmt->fetchAll() : [];
} catch (Throwable $e) {
  $errors[] = 'No se pudo cargar la lista de cuentas.';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover" />
  <title>Cuentas - Pixels Studio</title>
  <link rel="stylesheet" href="css/app.css?v=<?= (int) @filemtime(__DIR__ . '/css/app.css') ?>">
  <style>
    :root { --container-w:min(96vw, 1080px); }
    .accounts-header { display:flex; align-items:flex-start; justify-content:space-between; gap:16px; flex-wrap:wrap; margin-bottom:16px; }
    .accounts-actions { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
    .accounts-link { display:inline-flex; align-items:center; justify-content:center; min-height:40px; padding:0 14px; border:1px solid var(--line); border-radius:10px; color:#007ea8; background:var(--surface-soft); font-weight:850; text-decoration:none; }
    .accounts-grid { display:grid; grid-template-columns:minmax(260px, 340px) 1fr; gap:16px; align-items:start; }
    .accounts-box { border:1px solid rgba(0,212,255,.16); border-radius:16px; background:#fff; padding:16px; box-shadow:0 8px 22px rgba(0, 76, 110, .07); }
    .accounts-box h2 { margin:0 0 12px; color:var(--brand-ink); font-size:1.1rem; }
    .accounts-form { display:grid; gap:12px; }
    .accounts-form input { width:100%; height:42px; padding:0 12px; border:1px solid var(--line); border-radius:10px; color:var(--brand-ink); background:#fff; outline:none; font:inherit; }
    .accounts-table-wrap { overflow:auto; border:1px solid rgba(0,212,255,.14); border-radius:16px; }
    .accounts-table { width:100%; border-collapse:collapse; font-size:.94rem; }
    .accounts-table th { background:#071120; color:#eafaff; text-align:left; padding:12px; white-space:nowrap; }
    .accounts-table td { padding:12px; border-bottom:1px solid rgba(0,68,99,.10); background:#fbfdff; }
    .notice { display:block; margin-bottom:14px; }
    @media (max-width: 860px) { .accounts-grid { grid-template-columns:1fr; } }
  </style>
</head>
<body class="dashboard-page">
  <main class="dashboard-shell">
    <section class="form-card dashboard-card">
      <div class="panel">
        <header class="accounts-header">
          <div>
            <p class="eyebrow"><?= h(app_config('brand.name', 'Pixels Studio')) ?></p>
            <h1 class="title">Cuentas</h1>
            <p class="subtitle">Crea las cuentas cliente que luego tendrán sus propios usuarios, canales e inbox.</p>
          </div>
          <div class="accounts-actions">
            <a class="accounts-link" href="users.php">Usuarios</a>
            <a class="accounts-link" href="dashboard.php">Dashboard</a>
          </div>
        </header>

        <?php if ($notice !== ''): ?><div class="form-alert alert-info notice"><?= h($notice) ?></div><?php endif; ?>
        <?php foreach ($errors as $error): ?><div class="form-alert alert-error notice"><?= h($error) ?></div><?php endforeach; ?>

        <div class="accounts-grid">
          <section class="accounts-box">
            <h2>Nueva cuenta</h2>
            <form class="accounts-form" method="post" action="accounts.php" autocomplete="off">
              <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf'] ?? '') ?>">
              <label class="field">
                <span class="field-label">Nombre</span>
                <input type="text" name="name" maxlength="160" required>
              </label>
              <label class="field">
                <span class="field-label">Slug</span>
                <input type="text" name="slug" maxlength="80" placeholder="cliente-demo">
              </label>
              <button class="btn" type="submit">Crear cuenta</button>
            </form>
          </section>

          <section class="accounts-box">
            <h2>Cuentas actuales</h2>
            <div class="accounts-table-wrap">
              <table class="accounts-table">
                <thead>
                  <tr>
                    <th>ID</th>
                    <th>Nombre</th>
                    <th>Slug</th>
                    <th>Estado</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if ($accounts): foreach ($accounts as $account): ?>
                    <tr>
                      <td>#<?= (int) $account['id'] ?></td>
                      <td><strong><?= h((string) $account['name']) ?></strong></td>
                      <td><?= h((string) $account['slug']) ?></td>
                      <td><?= h((string) $account['status']) ?></td>
                    </tr>
                  <?php endforeach; else: ?>
                    <tr><td colspan="4">Sin cuentas registradas.</td></tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </section>
        </div>
      </div>
    </section>
    <div class="credit">Desarrollado por <strong><?= h(app_config('brand.developer', 'Pixels Studio')) ?></strong></div>
  </main>
</body>
</html>
