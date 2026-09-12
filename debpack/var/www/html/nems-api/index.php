<?php
header('Content-Type: application/json');

// Global variable required by LiveStatusClient::verify_post_request()
$request_method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/**
 * Validates request origin against loopback and RFC 1918 private subnets:
 * 127.0.0.0/8, 10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16
 */
function is_local_ip(?string $ip): bool {
    if (!$ip) return false;
    if ($ip === '::1' || $ip === '::ffff:127.0.0.1') return true;
    $ip_long = ip2long($ip);
    if ($ip_long === false) return false;

    return ($ip_long >= ip2long('127.0.0.0') && $ip_long <= ip2long('127.255.255.255')) ||
           ($ip_long >= ip2long('10.0.0.0') && $ip_long <= ip2long('10.255.255.255')) ||
           ($ip_long >= ip2long('172.16.0.0') && $ip_long <= ip2long('172.31.255.255')) ||
           ($ip_long >= ip2long('192.168.0.0') && $ip_long <= ip2long('192.168.255.255'));
}

if (!is_local_ip($_SERVER['REMOTE_ADDR'] ?? '')) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'content' => ['error' => 'Forbidden: Access limited to local subnets']
    ]);
    exit();
}

require_once "livestatus_client.php";

ini_set('memory_limit', '256M');

// Extract URI action path
$uri_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path_parts = array_values(array_filter(explode('/', $uri_path), function($part) {
    return $part !== '' && $part !== 'nems-api' && $part !== 'index.php';
}));

$action = $path_parts[0] ?? 'state';

// Parse incoming request payloads (JSON body or URL query params)
$raw_body = file_get_contents('php://input');
$json_body = json_decode($raw_body, true) ?? [];
$args = array_merge($_GET, $json_body);

$client = new LiveStatusClient('/usr/local/nagios/var/rw/live.sock');
$client->pretty_print = true;

$response = ['success' => true];

try {
    switch ($action) {

        // Route NEMS AI endpoint query if package file is present
        // This is separate because nems-ai is not installed by default
        // and only gets installed if a user specifically requests to do so
        case 'nems-ai':
            $ai_file = '/usr/local/share/nems/nems-ai/api.php';
            if (!file_exists($ai_file)) {
                echo json_encode([
                    'success' => false,
                    'content' => [
                        'ai_installed' => false,
                        'message' => 'NEMS AI package is not installed'
                    ]
                ]);
                exit();
            }
            require_once $ai_file;
            exit();

        // GET /nems-api/state (Returns full host & service state tree)
        case 'state':
            if ($request_method !== 'GET') {
                http_response_code(405);
                throw new LiveStatusException("Method Not Allowed. Use GET for /state.", 405);
            }
            $response['content'] = [
                'hosts'    => $client->getQuery('hosts', $_GET),
                'services' => $client->getQuery('services', $_GET)
            ];
            break;

        // POST /nems-api/schedule_check
        case 'schedule_check':
            $client->scheduleCheck($args);
            $response['content'] = 'Check scheduled successfully';
            break;

        // POST /nems-api/acknowledge_problem
        case 'acknowledge_problem':
            $client->acknowledgeProblem($args);
            $response['content'] = 'Problem acknowledged successfully';
            break;

        // POST /nems-api/schedule_downtime
        case 'schedule_downtime':
            $client->scheduleDowntime($args);
            $response['content'] = 'Downtime scheduled successfully';
            break;

        // POST /nems-api/cancel_downtime
        case 'cancel_downtime':
            $client->cancelDowntime($args);
            $response['content'] = 'Downtime cancelled successfully';
            break;

        // POST /nems-api/enable_notifications
        case 'enable_notifications':
            $client->enableNotifications($args);
            $response['content'] = 'Notifications enabled successfully';
            break;

        // POST /nems-api/disable_notifications
        case 'disable_notifications':
            $client->disableNotifications($args);
            $response['content'] = 'Notifications disabled successfully';
            break;

        // GET /nems-api/<table_name> (Direct MK Livestatus queries)
        default:
            if ($request_method !== 'GET') {
                http_response_code(405);
                throw new LiveStatusException("Method Not Allowed for query endpoint '$action'. Use GET.", 405);
            }
            $response['content'] = $client->getQuery($action, $_GET);
            break;
    }

} catch (LiveStatusException $e) {
    $response['success'] = false;
    $response['content'] = [
        'code'    => $e->getCode(),
        'message' => $e->getMessage()
    ];
    $status_code = is_numeric($e->getCode()) && $e->getCode() >= 400 && $e->getCode() < 600 ? (int)$e->getCode() : 500;
    http_response_code($status_code);
} catch (Exception $e) {
    $response['success'] = false;
    $response['content'] = [
        'code'    => 500,
        'message' => $e->getMessage()
    ];
    http_response_code(500);
}

echo json_encode($response);
?>
