<?php
/**
 * Momentrix Media — API Health Check (PHP)
 * Endpoint: GET /api/health
 */

declare(strict_types=1);

require_once __DIR__ . '/mailer.php';

$config = loadSmtpConfig();

sendJsonResponse(200, [
    'status'      => 'UP',
    'message'     => 'Momentrix API Server is running on Native PHP',
    'emailMode'   => $config['isMock'] ? 'MOCK' : 'SMTP',
    'phpVersion'  => PHP_VERSION,
    'postMax'     => ini_get('post_max_size'),
    'uploadMax'   => ini_get('upload_max_filesize'),
    'memoryLimit' => ini_get('memory_limit'),
    'serverTime'  => (new DateTime('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d H:i:s T'),
]);

