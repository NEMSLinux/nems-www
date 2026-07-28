<?php
// Integrate NEMS Multi-User SSO for third party apps
// July 28, 2026 - Robbie Ferguson

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

// Initialize session & enforce idle timeout + IP/UA hijacking guards
nems_secure_session_start();

// Must be logged in
if (empty($_SESSION['user'])) {
    http_response_code(401);
    exit;
}

// Read minimum role required by Apache (defaults to viewer if unset)
$required_role = $_SERVER['HTTP_X_NEMS_REQUIRED_ROLE'] ?? 'viewer';

if (!nems_has_role($required_role)) {
    http_response_code(403);
    exit;
}

// Access granted: Send 200 OK and pass username back to Apache
header('HTTP/1.1 200 OK');
header('X-Remote-User: ' . $_SESSION['user']);
exit;
