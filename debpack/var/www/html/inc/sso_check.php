<?php
// Integrate NEMS Multi-User SSO for third party apps
// July 28, 2026 - Robbie Ferguson

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
nems_secure_session_start();

if (empty($_SESSION['user'])) {
  http_response_code(401);
  exit;
}

// User is valid: send 200 OK and pass username to Apache via response header
header('HTTP/1.1 200 OK');
header('X-Remote-User: ' . $_SESSION['user']);
exit;
