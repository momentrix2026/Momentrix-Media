<?php
/**
 * Momentrix Media — Careers Application API Handler (PHP)
 * Endpoint: POST /api/careers
 */

declare(strict_types=1);

require_once __DIR__ . '/mailer.php';

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJsonResponse(405, ['error' => 'Method not allowed.']);
}

// Request size safeguard: check Content-Length header (max 10 MB payload)
$contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($contentLength > 10 * 1024 * 1024) {
    sendJsonResponse(413, ['error' => 'Request payload exceeds the maximum 10 MB limit.']);
}

// Rate limiting: max 5 requests per 60 seconds per IP
checkRateLimit('careers', 5, 60);

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
$jobTitle   = trim((string)($body['jobTitle'] ?? ''));
$resumeName = trim((string)($body['resumeName'] ?? ''));
$resumeData = (string)($body['resumeData'] ?? '');

// Validation
if ($firstName === '' || $lastName === '' || $email === '' || $phone === '' || $jobTitle === '') {
    sendJsonResponse(400, ['error' => 'Please fill in all required fields.']);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    sendJsonResponse(400, ['error' => 'Please provide a valid email address.']);
}

// Secure in-memory resume processing
$attachments = [];
$safeResumeName = '';

if ($resumeName !== '' && $resumeData !== '') {
    $extension = strtolower(pathinfo($resumeName, PATHINFO_EXTENSION));
    $allowedExtensions = ['pdf', 'doc', 'docx'];

    if (!in_array($extension, $allowedExtensions, true)) {
        sendJsonResponse(400, [
            'error' => 'Invalid file type. Only PDF and Word documents are permitted.'
        ]);
    }

    // Sanitize filename against path traversal or malicious chars
    $safeResumeName = preg_replace('/[^a-zA-Z0-9_\.-]/', '_', basename($resumeName));

    // Strip Data URL scheme (e.g. "data:application/pdf;base64,")
    $base64Content = $resumeData;
    if (str_starts_with($resumeData, 'data:')) {
        $commaPos = strpos($resumeData, ',');
        if ($commaPos !== false) {
            $base64Content = substr($resumeData, $commaPos + 1);
        }
    }

    // Decode in-memory (never touches server disk)
    $fileBytes = base64_decode($base64Content, true);
    if ($fileBytes === false || strlen($fileBytes) === 0) {
        sendJsonResponse(400, ['error' => 'Failed to process the uploaded resume file.']);
    }

    // Strict 5 MB file size limit
    if (strlen($fileBytes) > 5 * 1024 * 1024) {
        sendJsonResponse(400, [
            'error' => 'The uploaded resume file exceeds the 5 MB limit.'
        ]);
    }

    $mimeTypes = [
        'pdf'  => 'application/pdf',
        'doc'  => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];

    $attachments[] = [
        'filename' => $safeResumeName,
        'content'  => $fileBytes,
        'type'     => $mimeTypes[$extension] ?? 'application/octet-stream'
    ];
}

$config = loadSmtpConfig();
$companyEmail = $config['COMPANY_EMAIL'];
$emailFrom    = $config['EMAIL_FROM'];

$dt = new DateTime('now', new DateTimeZone('Asia/Kolkata'));
$formattedDate = $dt->format('d/m/Y, h:i:s A') . ' IST';
$currentYear = $dt->format('Y');

$safeFirstName = htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8');
$safeLastName  = htmlspecialchars($lastName, ENT_QUOTES, 'UTF-8');
$safeEmail     = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
$safePhone     = htmlspecialchars($phone, ENT_QUOTES, 'UTF-8');
$safeJobTitle  = htmlspecialchars($jobTitle, ENT_QUOTES, 'UTF-8');
$displayResume = htmlspecialchars($safeResumeName ?: 'None', ENT_QUOTES, 'UTF-8');

try {
    // 1. Send notification to the company with resume attachment
    $companyMailOptions = [
        'from'        => $emailFrom,
        'to'          => $companyEmail,
        'subject'     => "New Job Application - {$jobTitle}",
        'text'        => "New job application received:\n\nPosition: {$jobTitle}\nName: {$firstName} {$lastName}\nEmail: {$email}\nPhone: {$phone}\nResume Attachment: " . ($safeResumeName ?: 'None') . "\n\nDate: {$formattedDate}",
        'html'        => "
        <div style=\"font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; border: 1px solid #ddd; padding: 20px; border-radius: 8px;\">
          <h2 style=\"color: #9333ea; border-bottom: 2px solid #faf5ff; padding-bottom: 10px;\">New Job Application</h2>
          <p>You have received a new application for the following position:</p>
          <table style=\"width: 100%; border-collapse: collapse; margin-top: 15px; margin-bottom: 20px;\">
            <tr>
              <td style=\"padding: 8px; border: 1px solid #eee; font-weight: bold; width: 150px;\">Position:</td>
              <td style=\"padding: 8px; border: 1px solid #eee; font-weight: bold; color: #9333ea;\">{$safeJobTitle}</td>
            </tr>
            <tr>
              <td style=\"padding: 8px; border: 1px solid #eee; font-weight: bold;\">Name:</td>
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
              <td style=\"padding: 8px; border: 1px solid #eee; font-weight: bold;\">Resume Attachment:</td>
              <td style=\"padding: 8px; border: 1px solid #eee;\">{$displayResume}</td>
            </tr>
            <tr>
              <td style=\"padding: 8px; border: 1px solid #eee; font-weight: bold;\">Date:</td>
              <td style=\"padding: 8px; border: 1px solid #eee;\">{$formattedDate}</td>
            </tr>
          </table>
        </div>
        ",
        'attachments' => $attachments
    ];

    // 2. Send acknowledgement to the applicant
    $userMailOptions = [
        'from'    => $emailFrom,
        'to'      => $email,
        'subject' => "Application Received: {$jobTitle} - Momentrix Media",
        'text'    => "Hi {$firstName},\n\nThank you for applying for the \"{$jobTitle}\" position at Momentrix Media. We have received your application and resume. Our hiring team will review your qualifications and contact you within 3-5 business days if your profile matches our requirements.\n\nBest regards,\nThe Momentrix Careers Team",
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
            .badge { display: inline-block; background-color: #f3e8ff; color: #9333ea; font-weight: 700; font-size: 11px; padding: 6px 12px; border-radius: 9999px; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 16px; }
            .summary-box { background-color: #f8fafc; border-radius: 12px; padding: 20px; margin-bottom: 32px; border-left: 4px solid #9333ea; }
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
              <span class=\"badge\">Application Received</span>
              <h2>Thank You for Applying!</h2>
              <p>Hi {$safeFirstName},</p>
              <p>We appreciate your interest in joining Momentrix Media. This email confirms that we have successfully received your job application.</p>
              
              <div class=\"summary-box\">
                <h3>Application Details</h3>
                <div class=\"summary-item\"><strong>Applied Position:</strong> {$safeJobTitle}</div>
                <div class=\"summary-item\"><strong>Resume Name:</strong> {$displayResume}</div>
              </div>
              
              <p>Our hiring team will review your qualifications, portfolio, and experience against the requirements. If your profile matches our needs, we will reach out to you within 3-5 business days to schedule an initial screening interview.</p>
            </div>
            <div class=\"footer\">
              <p>© {$currentYear} Momentrix Media. Ground floor, 1-12, 25, 3rd Line, Chandramouli Nagar, Guntur, AP 522007</p>
              <p>You received this email because you applied for a job position on our careers portal.</p>
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
        'message' => 'Application submitted successfully!'
    ]);
} catch (\Throwable $e) {
    error_log("[CAREERS API ERROR] " . $e->getMessage());
    sendJsonResponse(500, [
        'error' => 'An error occurred while submitting your application. Please try again later.'
    ]);
}

