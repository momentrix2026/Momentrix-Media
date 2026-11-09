<?php
/**
 * Momentrix Media — Unified Mailer & API Helper (PHP)
 * Provides authenticated SMTP transport via PHPMailer, secure credential resolution,
 * rate limiting, and response helpers for Hostinger Web Hosting.
 */

declare(strict_types=1);

// Prevent direct browser execution of helper file
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

// Load PHPMailer classes
require_once __DIR__ . '/lib/PHPMailer/Exception.php';
require_once __DIR__ . '/lib/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/lib/PHPMailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * Send a standardized JSON response and exit.
 */
function sendJsonResponse(int $statusCode, array $payload): void {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * File-based transient rate limiter for shared hosting.
 * Allows $maxRequests per $windowSeconds per client IP.
 */
function checkRateLimit(string $action, int $maxRequests = 5, int $windowSeconds = 60): void {
    // Strictly use REMOTE_ADDR for direct Hostinger hosting without Cloudflare.
    // Untrusted client-supplied forwarding headers (X-Forwarded-For, CF-Connecting-IP) are ignored.
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

    $hash = hash('sha256', $action . '_' . $ip);
    $tempDir = sys_get_temp_dir();
    $rateFile = $tempDir . DIRECTORY_SEPARATOR . 'mm_rl_' . $hash . '.json';

    $now = time();
    $data = ['count' => 0, 'expires' => $now + $windowSeconds];

    if (file_exists($rateFile)) {
        $content = @file_get_contents($rateFile);
        if ($content) {
            $parsed = @json_decode($content, true);
            if (is_array($parsed) && isset($parsed['expires']) && $parsed['expires'] > $now) {
                $data = $parsed;
            }
        }
    }

    $data['count']++;

    if ($data['count'] > $maxRequests) {
        sendJsonResponse(429, [
            'error' => 'Too many requests. Please wait a moment before trying again.'
        ]);
    }

    @file_put_contents($rateFile, json_encode($data), LOCK_EX);
}

/**
 * Load SMTP credentials securely.
 * Priority:
 * 1. External file outside web root (/home/u.../domains/momentrixmedia.com/momentrix_credentials.php)
 * 2. Parent directory credential file
 * 3. Local development config (config.local.php)
 * 4. Environment variables
 * 5. Development backend/.env
 */
function loadSmtpConfig(): array {
    $defaults = [
        'SMTP_HOST'     => 'smtp.hostinger.com',
        'SMTP_PORT'     => 587,
        'SMTP_SECURE'   => 'tls',
        'SMTP_USER'     => '',
        'SMTP_PASS'     => '',
        'COMPANY_EMAIL' => 'hello@momentrixmedia.com',
        'EMAIL_FROM'    => '"Momentrix Media" <hello@momentrixmedia.com>',
        'isMock'        => true,
    ];

    // Explicit mock flag for testing or development without hitting live SMTP
    if (getenv('MOCK_MAIL') === 'true' || ($_SERVER['MOCK_MAIL'] ?? '') === 'true') {
        $defaults['isMock'] = true;
        return $defaults;
    }

    $candidatePaths = [];

    // Check outside public_html in Hostinger user structure
    if (!empty($_SERVER['DOCUMENT_ROOT'])) {
        $candidatePaths[] = dirname($_SERVER['DOCUMENT_ROOT']) . '/momentrix_credentials.php';
        $candidatePaths[] = dirname($_SERVER['DOCUMENT_ROOT'], 2) . '/momentrix_credentials.php';
    }

    $candidatePaths[] = dirname(__DIR__, 2) . '/momentrix_credentials.php';
    $candidatePaths[] = __DIR__ . '/config.local.php';

    foreach ($candidatePaths as $path) {
        if ($path && file_exists($path) && is_readable($path)) {
            $loaded = include $path;
            if (is_array($loaded)) {
                $merged = array_merge($defaults, $loaded);
                $merged['isMock'] = empty($merged['SMTP_USER']) 
                    || $merged['SMTP_USER'] === 'your-email@gmail.com'
                    || empty($merged['SMTP_PASS']);
                return $merged;
            }
        }
    }

    // Check system environment variables
    $envUser = getenv('SMTP_USER');
    if ($envUser) {
        $defaults['SMTP_USER']     = (string)$envUser;
        $defaults['SMTP_PASS']     = (string)(getenv('SMTP_PASS') ?: '');
        $defaults['SMTP_HOST']     = (string)(getenv('SMTP_HOST') ?: 'smtp.hostinger.com');
        $defaults['SMTP_PORT']     = (int)(getenv('SMTP_PORT') ?: 587);
        $defaults['SMTP_SECURE']   = (string)(getenv('SMTP_SECURE') ?: 'tls');
        $defaults['COMPANY_EMAIL'] = (string)(getenv('COMPANY_EMAIL') ?: 'hello@momentrixmedia.com');
        $defaults['EMAIL_FROM']    = (string)(getenv('EMAIL_FROM') ?: '"Momentrix Media" <hello@momentrixmedia.com>');
        $defaults['isMock']        = empty($defaults['SMTP_USER']) || empty($defaults['SMTP_PASS']);
        return $defaults;
    }

    // Local development fallback: parse backend/.env if running locally
    $backendEnv = dirname(__DIR__, 2) . '/backend/.env';
    if (file_exists($backendEnv) && is_readable($backendEnv)) {
        $lines = file($backendEnv, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) continue;
            if (strpos($line, '=') !== false) {
                [$k, $v] = explode('=', $line, 2);
                $k = trim($k);
                $v = trim($v, " \t\n\r\0\x0B\"'");
                if (isset($defaults[$k])) {
                    $defaults[$k] = ($k === 'SMTP_PORT') ? (int)$v : $v;
                }
            }
        }
        $defaults['isMock'] = empty($defaults['SMTP_USER']) 
            || $defaults['SMTP_USER'] === 'your-email@gmail.com'
            || empty($defaults['SMTP_PASS']);
    }

    return $defaults;
}

/**
 * Send email helper function matching backend/utils/mailer.js interface.
 *
 * @param array $mailOptions [
 *   'from'        => string,
 *   'to'          => string,
 *   'subject'     => string,
 *   'text'        => string,
 *   'html'        => string (optional),
 *   'attachments' => array of ['filename' => string, 'content' => string (binary)]
 * ]
 * @return array ['success' => bool, 'mock' => bool, 'messageId' => string]
 * @throws Exception
 */
function sendMailHelper(array $mailOptions): array {
    $config = loadSmtpConfig();

    if ($config['isMock']) {
        // Mock mode for local testing or when credentials are not yet configured
        error_log("[MOCK EMAIL] To: {$mailOptions['to']} | Subject: {$mailOptions['subject']}");
        return [
            'success'   => true,
            'mock'      => true,
            'messageId' => 'mock-id-' . substr(md5(uniqid('', true)), 0, 9)
        ];
    }

    $mail = new PHPMailer(true);

    try {
        // Server configuration
        $mail->isSMTP();
        $mail->Host       = (string)$config['SMTP_HOST'];
        $mail->SMTPAuth   = true;
        $mail->Username   = (string)$config['SMTP_USER'];
        $mail->Password   = (string)$config['SMTP_PASS'];
        $mail->Port       = (int)$config['SMTP_PORT'];
        $mail->CharSet    = 'UTF-8';
        $mail->Timeout    = 8; // 8-second timeout for snappy API responses

        // SMTPSecure configuration
        $secure = strtolower((string)$config['SMTP_SECURE']);
        if ($secure === 'ssl' || $mail->Port === 465) {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($secure === 'false' || $secure === 'none' || $secure === '') {
            $mail->SMTPSecure = '';
            $mail->SMTPAutoTLS = false;
        } else {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        }

        // Sender parsing (e.g. '"Momentrix Media" <hello@momentrixmedia.com>')
        $fromRaw = $mailOptions['from'] ?? $config['EMAIL_FROM'];
        if (preg_match('/^(?:"?([^"]*)"?\s)?<?([^>]+)>?$/', $fromRaw, $matches)) {
            $fromName = trim($matches[1]);
            $fromEmail = trim($matches[2]);
            $mail->setFrom($fromEmail, $fromName ?: 'Momentrix Media');
        } else {
            $mail->setFrom($config['COMPANY_EMAIL'], 'Momentrix Media');
        }

        // Recipient
        $mail->addAddress($mailOptions['to']);

        // Subject
        $mail->Subject = $mailOptions['subject'];

        // Body
        if (!empty($mailOptions['html'])) {
            $mail->isHTML(true);
            $mail->Body    = $mailOptions['html'];
            $mail->AltBody = $mailOptions['text'] ?? strip_tags($mailOptions['html']);
        } else {
            $mail->isHTML(false);
            $mail->Body = $mailOptions['text'] ?? '';
        }

        // Attachments (e.g. base64 decoded resume bytes)
        if (!empty($mailOptions['attachments']) && is_array($mailOptions['attachments'])) {
            foreach ($mailOptions['attachments'] as $att) {
                if (isset($att['content'], $att['filename'])) {
                    $mail->addStringAttachment(
                        $att['content'],
                        $att['filename'],
                        'base64',
                        $att['type'] ?? 'application/octet-stream'
                    );
                }
            }
        }

        $mail->send();

        return [
            'success'   => true,
            'mock'      => false,
            'messageId' => $mail->getLastMessageID() ?: uniqid('msg_', true)
        ];
    } catch (Exception $e) {
        error_log("[SMTP ERROR] " . $mail->ErrorInfo);
        throw new \RuntimeException($mail->ErrorInfo);
    }
}
