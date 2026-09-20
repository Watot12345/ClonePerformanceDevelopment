<?php

require_once __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
// 1. Read .env file or system environment
require_once __DIR__ . '/config/config.php';
$env = function_exists('loadEnv') ? loadEnv() : [];

/**
 * Send Email function
 */
function sendMail($to, $subject, $body)
{
    global $env;
    $mail = new PHPMailer(true);

    try {
        // SMTP Settings
        $mail->isSMTP();
        $mail->Host       = $env['SMTP_HOST'] ?? 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = $env['SMTP_USER'];
        $mail->Password   = $env['SMTP_PASS'];
        
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
        $mail->setFrom($env['SMTP_USER'], $env['MAIL_FROM_NAME'] ?? 'HR3 System');
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
