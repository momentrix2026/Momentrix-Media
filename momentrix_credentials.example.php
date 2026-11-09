<?php
/**
 * Momentrix Media — Production Hostinger Credentials Template
 * 
 * INSTRUCTIONS FOR HOSTINGER DEPLOYMENT:
 * 1. Upload this file to your Hostinger server OUTSIDE the public_html directory:
 *    Target Path: /home/uXXXXXXX/domains/momentrixmedia.com/momentrix_credentials.php
 *    (One level above public_html)
 * 2. Rename it to `momentrix_credentials.php`.
 * 3. Replace 'CHANGE_TO_YOUR_HOSTINGER_EMAIL_PASSWORD' with your actual Hostinger email account password.
 * 4. Storing this outside public_html ensures credentials are completely inaccessible via web browsers.
 */

return [
    'SMTP_HOST'     => 'smtp.hostinger.com',
    'SMTP_PORT'     => 587,
    'SMTP_SECURE'   => 'tls', // 'tls' on port 587, or 'ssl' on port 465
    'SMTP_USER'     => 'hello@momentrixmedia.com',
    'SMTP_PASS'     => 'CHANGE_TO_YOUR_HOSTINGER_EMAIL_PASSWORD',
    'COMPANY_EMAIL' => 'hello@momentrixmedia.com',
    'EMAIL_FROM'    => '"Momentrix Media" <hello@momentrixmedia.com>',
];

