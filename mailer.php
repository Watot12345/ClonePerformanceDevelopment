<?php

require_once __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
// 1. Read .env file or system environment
require_once __DIR__ . '/config/config.php';
$env = function_exists('loadEnv') ? loadEnv() : [];

/**
 * Send Email function
 * Prioritizes Brevo REST API (HTTPS port 443) when API key is present
 * to bypass cloud firewall restrictions (e.g. Railway SMTP port 587 blocking),
 * and falls back to PHPMailer SMTP if Brevo is not configured.
 */
function sendMail($to, $subject, $body)
{
    global $env;
    if (empty($env)) {
        $env = function_exists('loadEnv') ? loadEnv() : [];
    }

    $brevoKey = trim($env['BREVO_API_KEY'] ?? $env['brevo'] ?? '');
    $fromEmail = trim($env['MAIL_FROM_ADDRESS'] ?? $env['SMTP_USER'] ?? 'hresources771@gmail.com');
    $fromName  = trim($env['MAIL_FROM_NAME'] ?? 'HR3 System');

    // 1. If Brevo API key is configured, use Brevo REST API over HTTPS (port 443)
    if (!empty($brevoKey)) {
        $brevoRes = sendMailViaBrevoApi($brevoKey, $to, $subject, $body, $fromEmail, $fromName);
        if ($brevoRes['success']) {
            return $brevoRes;
        }

        // If Brevo failed, log the error
        error_log('Brevo API dispatch error: ' . ($brevoRes['message'] ?? 'Unknown error'));

        // If no SMTP credentials exist, return Brevo's explicit failure message
        if (empty($env['SMTP_PASS'])) {
            return $brevoRes;
        }
    }

    // 2. Fallback to PHPMailer SMTP
    $mail = new PHPMailer(true);

    try {
        // SMTP Settings
        $mail->isSMTP();
        $mail->Host       = $env['SMTP_HOST'] ?? 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = $env['SMTP_USER'] ?? '';
        $mail->Password   = $env['SMTP_PASS'] ?? '';
        
        $port = (int)($env['SMTP_PORT'] ?? 587);
        $secure = strtolower(trim($env['SMTP_SECURE'] ?? 'tls'));
        
        if ($port === 465 || $secure === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            $mail->Port       = 465;
        } else {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = $port;
        }

        // Strict timeouts: prevent Railway edge proxy 502 timeout
        $mail->Timeout       = 5; // Socket timeout in seconds
        $mail->SMTPKeepAlive = false;
        $mail->SMTPOptions   = [
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true
            ]
        ];

        // Sender & Recipient
        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($to);

        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;

        $mail->send();
        return ['success' => true, 'message' => 'Email sent successfully!'];
    } catch (Exception $e) {
        return ['success' => false, 'message' => $mail->ErrorInfo];
    }
}

/**
 * Dispatch transactional email via Brevo REST API v3 over HTTPS (port 443)
 */
function sendMailViaBrevoApi($apiKey, $to, $subject, $body, $fromEmail, $fromName)
{
    $payload = [
        'sender' => [
            'name'  => $fromName,
            'email' => $fromEmail
        ],
        'to' => [
            ['email' => $to]
        ],
        'subject'     => $subject,
        'htmlContent' => $body
    ];

    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'accept: application/json',
            'api-key: ' . $apiKey,
            'content-type: application/json'
        ],
        CURLOPT_TIMEOUT        => 10
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        return ['success' => false, 'message' => 'Brevo cURL Error: ' . $curlErr];
    }

    if ($httpCode >= 200 && $httpCode < 300) {
        return ['success' => true, 'message' => 'Email sent successfully!'];
    }

    $decoded = json_decode($response, true);
    $errorMsg = $decoded['message'] ?? $decoded['error'] ?? "Brevo HTTP {$httpCode}: {$response}";
    return ['success' => false, 'message' => 'Brevo API Error: ' . $errorMsg];
}

