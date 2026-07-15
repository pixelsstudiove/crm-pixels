<?php
// logout.php
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $ok = isset($_POST['csrf'], $_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $_POST['csrf']);
  if ($ok) {
    $sessionName = session_name();
    session_unset();
    session_destroy();
    setcookie($sessionName, '', [
      'expires' => time() - 3600,
      'path' => '/',
      'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
      'httponly' => true,
      'samesite' => 'Lax',
    ]);
    setcookie('pixels_remember_session', '', [
      'expires' => time() - 3600,
      'path' => '/',
      'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
      'httponly' => true,
      'samesite' => 'Lax',
    ]);
  }
}
header('Location: /login.php');
exit;
