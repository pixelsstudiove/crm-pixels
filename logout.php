<?php
// logout.php
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $ok = isset($_POST['csrf'], $_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $_POST['csrf']);
  if ($ok) {
    session_unset();
    session_destroy();
  }
}
header('Location: /login.php');
exit;
