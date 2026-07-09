<?php
// auth/require_auth.php
declare(strict_types=1);
require_once __DIR__ . '/../config/db.php';

if (!function_exists('is_logged_in')) {
  function is_logged_in(): bool {
    return isset($_SESSION['user_id']) && is_numeric($_SESSION['user_id']);
  }
}

if (empty($_SESSION['csrf'])) {
  $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

if (!is_logged_in()) {
  header('Location: login.php'); // sin barra
  exit;
}
