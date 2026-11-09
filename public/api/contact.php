<?php
/**
 * Momentrix Media — Contact Form & Audit Request API Handler (PHP)
 * Endpoint: POST /api/contact
 */

declare(strict_types=1);

require_once __DIR__ . '/mailer.php';

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJsonResponse(405, ['error' => 'Method not allowed.']);
}

// Rate limiting: max 5 requests per 60 seconds per IP
checkRateLimit('contact', 5, 60);

// Read JSON input
$rawBody = file_get_contents('php://input');
$body = json_decode($rawBody, true);

if (!is_array($body)) {
    sendJsonResponse(400, ['error' => 'Invalid JSON payload.']);
}

$firstName  = trim((string)($body['firstName'] ?? ''));
$lastName   = trim((string)($body['lastName'] ?? ''));
$email      = trim((string)($body['email'] ?? ''));
$phone      = trim((string)($body['phone'] ?? ''));
$service    = trim((string)($body['service'] ?? ''));
$message    = trim((string)($body['message'] ?? ''));
$websiteUrl = trim((string)($body['websiteUrl'] ?? ''));
$hp         = trim((string)($body['hp'] ?? ''));

// Bot honeypot protection: if hidden field is filled, silently ignore
if ($hp !== '') {
    sendJsonResponse(200, ['success' => true, 'message' => 'Message sent successfully!']);
}

// Validate email
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    sendJsonResponse(400, ['error' => 'Please provide a valid email address.']);
}

// Support both general contact forms and quick website audit requests
$isAuditRequest = ($websiteUrl !== '' && $message === '');
$resolvedFirstName = $firstName !== '' ? $firstName : ($isAuditRequest ? 'Website Audit' : '');
$resolvedLastName  = $lastName !== '' ? $lastName : ($isAuditRequest ? 'Requester' : '');
$resolvedPhone     = $phone !== '' ? $phone : ($isAuditRequest ? 'Not Provided' : '');
$resolvedMessage   = $message !== '' ? $message : ($isAuditRequest ? "Please generate a free audit report for website: {$websiteUrl}" : '');
$resolvedService   = $service !== '' ? $service : ($isAuditRequest ? 'Website & SEO Audit' : 'General Inquiry');

if ($resolvedFirstName === '' || $resolvedMessage === '') {
    sendJsonResponse(400, ['error' => 'Please fill in all required fields.']);
}

// Phone validation if provided (minimum 8 digits)
if ($phone !== '') {
    $digitsOnly = preg_replace('/\D/', '', $phone);
    if (strlen($digitsOnly) < 8) {
        sendJsonResponse(400, ['error' => 'Please provide a valid contact number (at least 8 digits).']);
    }
}

$config = loadSmtpConfig();
$companyEmail = $config['COMPANY_EMAIL'];
$emailFrom    = $config['EMAIL_FROM'];

// Format Indian Standard Time
$dt = new DateTime('now', new DateTimeZone('Asia/Kolkata'));
$formattedDate = $dt->format('d/m/Y, h:i:s A') . ' IST';
$currentYear = $dt->format('Y');

try {
    // 1. Send notification to the company
    $safeFirstName = htmlspecialchars($resolvedFirstName, ENT_QUOTES, 'UTF-8');
    $safeLastName  = htmlspecialchars($resolvedLastName, ENT_QUOTES, 'UTF-8');
    $safeEmail     = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
    $safePhone     = htmlspecialchars($resolvedPhone, ENT_QUOTES, 'UTF-8');
    $safeService   = htmlspecialchars($resolvedService, ENT_QUOTES, 'UTF-8');
    $safeWebsite   = htmlspecialchars($websiteUrl ?: 'N/A', ENT_QUOTES, 'UTF-8');
    $safeMessage   = nl2br(htmlspecialchars($resolvedMessage, ENT_QUOTES, 'UTF-8'));

    $companyMailOptions = [
        'from'    => $emailFrom,
        'to'      => $companyEmail,
        'subject' => "New Inquiry - {$resolvedService}",
        'text'    => "New contact submission:\n\nName: {$resolvedFirstName} {$resolvedLastName}\nEmail: {$email}\nPhone: {$resolvedPhone}\nService: {$resolvedService}\nWebsite: " . ($websiteUrl ?: 'N/A') . "\nMessage: {$resolvedMessage}\n\nDate: {$formattedDate}",
        'html'    => "
        <div style=\"font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; border: 1px solid #ddd; padding: 20px; border-radius: 8px;\">
          <h2 style=\"color: #16a34a; border-bottom: 2px solid #f0fdf4; padding-bottom: 10px;\">New Contact Inquiry</h2>
          <p>You have received a new message from the contact form:</p>
          <table style=\"width: 100%; border-collapse: collapse; margin-top: 15px; margin-bottom: 20px;\">
            <tr>
              <td style=\"padding: 8px; border: 1px solid #eee; font-weight: bold; width: 150px;\">Name:</td>
              <td style=\"padding: 8px; border: 1px solid #eee;\">{$safeFirstName} {$safeLastName}</td>
            </tr>
            <tr>
              <td style=\"padding: 8px; border: 1px solid #eee; font-weight: bold;\">Email:</td>
              <td style=\"padding: 8px; border: 1px solid #eee;\"><a href=\"mailto:{$safeEmail}\">{$safeEmail}</a></td>
            </tr>
            <tr>
              <td style=\"padding: 8px; border: 1px solid #eee; font-weight: bold;\">Phone:</td>
              <td style=\"padding: 8px; border: 1px solid #eee;\"><a href=\"tel:{$safePhone}\">{$safePhone}</a></td>
            </tr>
            <tr>
              <td style=\"padding: 8px; border: 1px solid #eee; font-weight: bold;\">Service Interested:</td>
              <td style=\"padding: 8px; border: 1px solid #eee;\">{$safeService}</td>
            </tr>
            <tr>
              <td style=\"padding: 8px; border: 1px solid #eee; font-weight: bold;\">Website:</td>
              <td style=\"padding: 8px; border: 1px solid #eee;\">{$safeWebsite}</td>
            </tr>
            <tr>
              <td style=\"padding: 8px; border: 1px solid #eee; font-weight: bold;\">Message:</td>
              <td style=\"padding: 8px; border: 1px solid #eee; white-space: pre-wrap;\">{$safeMessage}</td>
            </tr>
            <tr>
              <td style=\"padding: 8px; border: 1px solid #eee; font-weight: bold;\">Date:</td>
              <td style=\"padding: 8px; border: 1px solid #eee;\">{$formattedDate}</td>
            </tr>
          </table>
        </div>
        "
    ];

    // 2. Send auto-reply to the customer
    $userMailOptions = [
        'from'    => $emailFrom,
        'to'      => $email,
        'subject' => 'Thank you for contacting Momentrix Media',
        'text'    => "Hi {$resolvedFirstName},\n\nThank you for reaching out to Momentrix Media. We have received your message regarding \"{$resolvedService}\" and our team will get back to you within 24 hours.\n\nBest regards,\nThe Momentrix Team",
        'html'    => "
        <!DOCTYPE html>
        <html>
        <head>
          <meta charset=\"utf-8\">
          <style>
            body { font-family: -apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, Helvetica, Arial, sans-serif; background-color: #f8fafc; color: #334155; margin: 0; padding: 0; }
            .container { max-width: 600px; margin: 40px auto; background-color: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06); border: 1px solid #e2e8f0; }
            .header { background-color: #0f172a; padding: 32px; text-align: center; }
            .header h1 { color: #ffffff; margin: 0; font-size: 24px; font-weight: 800; letter-spacing: -0.5px; }
            .content { padding: 40px 32px; }
            .content h2 { color: #0f172a; font-size: 20px; font-weight: 700; margin-top: 0; margin-bottom: 16px; }
            .content p { font-size: 15px; line-height: 1.6; margin-top: 0; margin-bottom: 24px; }
            .badge { display: inline-block; background-color: #dcfce7; color: #16a34a; font-weight: 700; font-size: 11px; padding: 6px 12px; border-radius: 9999px; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 16px; }
            .summary-box { background-color: #f1f5f9; border-radius: 12px; padding: 20px; margin-bottom: 32px; border-left: 4px solid #16a34a; }
            .summary-box h3 { margin-top: 0; margin-bottom: 12px; font-size: 14px; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.5px; }
            .summary-item { font-size: 13px; margin-bottom: 8px; line-height: 1.5; }
            .summary-item strong { color: #0f172a; }
            .footer { background-color: #f1f5f9; padding: 24px 32px; text-align: center; border-top: 1px solid #e2e8f0; font-size: 12px; color: #64748b; }
          </style>
        </head>
        <body>
          <div class=\"container\">
            <div class=\"header\">
              <h1>MOMENTRIX MEDIA</h1>
            </div>
            <div class=\"content\">
              <span class=\"badge\">Inquiry Received</span>
              <h2>Thank You for Contacting Us!</h2>
              <p>Hi {$safeFirstName},</p>
              <p>We appreciate you reaching out. This email confirms that we have successfully received your inquiry.</p>
              <p>Our digital transformation team will review your message and we'll get back to you within 24 hours to address your request or schedule a consultation call.</p>
              
              <div class=\"summary-box\">
                <h3>Your Message Details</h3>
                <div class=\"summary-item\"><strong>Phone Number:</strong> {$safePhone}</div>
                <div class=\"summary-item\"><strong>Service of Interest:</strong> {$safeService}</div>
                <div class=\"summary-item\"><strong>Submitted Message:</strong></div>
                <div style=\"font-size: 13px; font-style: italic; color: #475569; margin-top: 6px; white-space: pre-wrap;\">\"{$safeMessage}\"</div>
              </div>
              
              <p>If you need immediate assistance or want to update any details, feel free to reply directly to this email.</p>
            </div>
            <div class=\"footer\">
              <p>© {$currentYear} Momentrix Media. Ground floor, 1-12, 25, 3rd Line, Chandramouli Nagar, Guntur, AP 522007</p>
              <p>You received this email because you submitted a contact inquiry on our website.</p>
            </div>
          </div>
        </body>
        </html>
        "
    ];

    sendMailHelper($companyMailOptions);
    sendMailHelper($userMailOptions);

    sendJsonResponse(200, [
        'success' => true,
        'message' => 'Message sent successfully!'
    ]);
} catch (\Throwable $e) {
    error_log("[CONTACT API ERROR] " . $e->getMessage());
    sendJsonResponse(500, [
        'error' => 'Failed to send message. Please try again later or email us directly at hello@momentrixmedia.com'
    ]);
}

