<?php
// config/navigation.php
declare(strict_types=1);

function nav_user_label(): string {
  return (string) ($_SESSION['username'] ?? 'Usuario');
}

function nav_role_label(): string {
  return role_label(current_user_role());
}

function nav_render_account_switch(PDO $pdo, array $accountOptions, int $selectedAccountId, string $script, array $allParams = [], array $accountParams = []): void {
  if (!is_super_admin()) return;
  ?>
  <label class="account-switch app-account-switch" aria-label="Cuentas">
    <span class="nav-control-label">Cuentas</span>
    <select onchange="if (this.value) window.location.href = this.value">
      <option value="<?= h(account_url($script, $allParams, '')) ?>" <?= $selectedAccountId <= 0 ? 'selected' : '' ?>>Todas las cuentas</option>
      <?php foreach ($accountOptions as $account): ?>
        <?php $accountSlug = trim((string) ($account['slug'] ?? accounts_slug_for_id($pdo, (int) ($account['id'] ?? 0)))); ?>
        <option value="<?= h(account_url($script, $accountParams, $accountSlug !== '' ? $accountSlug : null)) ?>" <?= $selectedAccountId === (int) ($account['id'] ?? 0) ? 'selected' : '' ?>>
          <?= h((string) ($account['name'] ?? 'Cuenta')) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </label>
  <?php
}

function nav_render_view_menu(string $activeView): void {
  $items = [];
  if ($activeView === 'dashboard' && can('view_conversations')) {
    $items[] = ['label' => 'Ir al Inbox', 'href' => account_url('inbox.php')];
  } elseif ($activeView === 'inbox' && can('view_dashboard')) {
    $items[] = ['label' => 'Ir al embudo', 'href' => account_url('dashboard.php')];
  }
  if (!$items) return;
  ?>
  <div class="menu-dropdown" data-menu>
    <button class="menu-trigger" type="button" data-menu-trigger aria-expanded="false">Cambiar vista</button>
    <div class="menu-panel" role="menu">
      <?php foreach ($items as $item): ?>
        <a class="menu-item" href="<?= h($item['href']) ?>"><?= h($item['label']) ?></a>
      <?php endforeach; ?>
    </div>
  </div>
  <?php
}

function nav_render_user_menu(bool $includeProfileModal = true): void {
  ?>
  <div class="menu-dropdown" data-menu>
    <button class="menu-trigger" type="button" data-menu-trigger aria-expanded="false"><?= h(nav_user_label()) ?></button>
    <div class="menu-panel menu-panel-wide" role="menu">
      <span class="menu-meta"><?= h(nav_role_label()) ?></span>
      <span class="menu-section-title">Perfil</span>
      <?php if ($includeProfileModal): ?>
        <button type="button" class="menu-item menu-item-nested" data-modal-open="profileModal">Cambiar contraseña</button>
      <?php endif; ?>
      <span class="menu-section-title">Configuración</span>
      <?php if (can('manage_accounts')): ?><a class="menu-item menu-item-nested" href="/accounts.php">Gestión de cuentas</a><?php endif; ?>
      <?php if (can('manage_users')): ?><a class="menu-item menu-item-nested" href="/users.php">Gestión de usuarios</a><?php endif; ?>
      <?php if (can('manage_integrations')): ?><a class="menu-item menu-item-nested" href="<?= h(account_url('channels.php')) ?>">Gestión de canales</a><?php endif; ?>
      <?php if (can('manage_integrations')): ?><a class="menu-item menu-item-nested" href="<?= h(account_url('webhook_logs.php')) ?>">Ver eventos</a><?php endif; ?>
      <form class="menu-form" action="/logout.php" method="post">
        <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf'] ?? '') ?>">
        <button class="menu-item menu-item-danger" type="submit">Cerrar sesión</button>
      </form>
    </div>
  </div>
  <?php
}

function nav_admin_items(): array {
  $items = [];
  if (can('manage_accounts')) $items[] = ['key' => 'accounts', 'label' => 'Gestión de cuentas', 'href' => '/accounts.php'];
  if (can('manage_users')) $items[] = ['key' => 'users', 'label' => 'Gestión de usuarios', 'href' => '/users.php'];
  if (can('manage_integrations')) $items[] = ['key' => 'channels', 'label' => 'Gestión de canales', 'href' => account_url('channels.php')];
  if (can('manage_integrations')) $items[] = ['key' => 'events', 'label' => 'Ver eventos', 'href' => account_url('webhook_logs.php')];
  if (can('view_dashboard')) $items[] = ['key' => 'dashboard', 'label' => 'Embudo comercial', 'href' => account_url('dashboard.php')];
  if (can('view_conversations')) $items[] = ['key' => 'inbox', 'label' => 'Inbox', 'href' => account_url('inbox.php')];
  return $items;
}

function nav_render_admin_side_nav(string $active): void {
  $items = nav_admin_items();
  if (!$items) return;
  ?>
  <aside class="admin-side-nav" aria-label="Navegación de configuración">
    <span class="admin-side-title">Configuración</span>
    <?php foreach ($items as $item): ?>
      <a class="admin-side-link <?= $active === $item['key'] ? 'is-active' : '' ?>" href="<?= h($item['href']) ?>">
        <?= h($item['label']) ?>
      </a>
    <?php endforeach; ?>
  </aside>
  <?php
}
