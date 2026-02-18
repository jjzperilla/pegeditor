<?php
// api/oos_mailer.php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * Send OOS summary email
 *
 * @param string   $to      Main recipient
 * @param string   $subject Email subject
 * @param string   $body    Body (plain text or HTML depending on $isHtml)
 * @param string[] $cc      Optional CC recipients
 * @param bool     $isHtml  If true, send as HTML
 *
 * @return array { success: bool, error: ?string }
 */
function sendOosSummaryuser(
    string $to,
    string $subject,
    string $body,
    array $cc = [],
    bool $isHtml = false
): array {

    // =========================
    // PHPMailer autoload
    // =========================
    $autoloadApi  = __DIR__ . '/vendor/autoload.php';
    $autoloadRoot = dirname(__DIR__) . '/vendor/autoload.php';

    if (file_exists($autoloadApi)) {
        require_once $autoloadApi;
    } elseif (file_exists($autoloadRoot)) {
        require_once $autoloadRoot;
    } else {
        return [
            'success' => false,
            'error'   => 'Missing vendor/autoload.php (run composer install)'
        ];
    }

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
        $fromName  = 'Price Matrix - Web App Notification';

        $mail->setFrom($fromEmail, $fromName);
        $mail->Sender = $fromEmail; // SMTP envelope

        // TO
        $mail->addAddress($to);

        // CC (validated)
        foreach ($cc as $email) {
            $email = trim((string)$email);
            if ($email !== '' && filter_var($email, \FILTER_VALIDATE_EMAIL)) {
                $mail->addCC($email);
            }
        }

        // =========================
        // CONTENT
        // =========================
        $mail->Subject = $subject;

        if ($isHtml) {
            $mail->isHTML(true);
            $mail->Body = $body;

            // Plain-text fallback
            $alt = strip_tags(str_replace(["<br>", "<br/>", "<br />"], "\n", $body));
            $mail->AltBody = str_replace("\n", "\r\n", $alt);
        } else {
            $mail->isHTML(false);
            $plain = str_replace("\n", "\r\n", $body);
            $mail->Body    = $plain;
            $mail->AltBody = $plain;
        }

        // SEND
        $mail->send();

        return ['success' => true, 'error' => null];

    } catch (Throwable $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}
