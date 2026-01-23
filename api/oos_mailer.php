<?php
// api/oos_mailer.php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * Send OOS summary email
 *
 * @param string       $to   Main recipient
 * @param string       $subject
 * @param string       $body Plain-text body
 * @param string[]     $cc   Optional CC recipients
 *
 * @return array { success: bool, error: ?string }
 */
function sendOosSummaryEmail(
    string $to,
    string $subject,
    string $body,
    array $cc = []
): array {

    // =========================
    // PHPMailer autoload
    // =========================
    $autoload = __DIR__ . '/vendor/autoload.php';
    if (!file_exists($autoload)) {
        return [
            'success' => false,
            'error'   => 'Missing vendor/autoload.php. Run: composer require phpmailer/phpmailer'
        ];
    }
    require_once $autoload;

    try {
        $mail = new PHPMailer(true);

        // =========================
        // BREVO SMTP CONFIG
        // =========================
        $mail->isSMTP();
        $mail->Host       = 'smtp-relay.brevo.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'a0b9af001@smtp-brevo.com'; // Brevo SMTP login
        $mail->Password   = 'ySvkqhmg697fxIBr';         // Brevo SMTP key
        $mail->Port       = 587;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;

        // =========================
        // HEADERS
        // =========================
        $fromEmail = 'jperilla@servertechsolutions.com'; // MUST be verified in Brevo
        $fromName  = 'Price Matrix - OOS Notification';

        $mail->setFrom($fromEmail, $fromName);
        $mail->Sender = $fromEmail; // important for SMTP envelope

        // TO
        $mail->addAddress($to);

        // CC (validated)
        foreach ($cc as $email) {
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $mail->addCC($email);
            }
        }

        // =========================
        // CONTENT (PLAIN TEXT)
        // =========================
        $mail->isHTML(false);
        $mail->Subject = $subject;

        // Ensure proper email line breaks (CRLF)
        $body = str_replace("\n", "\r\n", $body);

        $mail->Body    = $body;
        $mail->AltBody = $body;

        // =========================
        // SEND
        // =========================
        $mail->send();

        return [
            'success' => true,
            'error'   => null
        ];

    } catch (Throwable $e) {
        return [
            'success' => false,
            'error'   => $e->getMessage()
        ];
    }
}
