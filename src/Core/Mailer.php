<?php
declare(strict_types=1);

namespace App\Core;

use PHPMailer\PHPMailer\PHPMailer;

final class Mailer
{
    public static function send(string $to, string $subject, string $htmlBody, array $attachments = [], ?string $toName = null): bool
    {
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host       = (string) Config::get('MAIL_HOST', 'localhost');
            $mail->Port       = (int) Config::get('MAIL_PORT', 587);
            $user = (string) Config::get('MAIL_USERNAME', '');
            if ($user !== '') {
                $mail->SMTPAuth = true;
                $mail->Username = $user;
                $mail->Password = (string) Config::get('MAIL_PASSWORD', '');
            }
            $enc = strtolower((string) Config::get('MAIL_ENCRYPTION', 'tls'));
            if ($enc === 'ssl') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($enc === 'tls') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            } else {
                $mail->SMTPAutoTLS = false;
            }
            $mail->CharSet  = 'UTF-8';
            $mail->Encoding = 'base64';
            $mail->setFrom(
                (string) Config::get('MAIL_FROM_ADDRESS', 'no-reply@example.com'),
                (string) Config::get('MAIL_FROM_NAME', 'WK2026 Pool')
            );
            $mail->addAddress($to, $toName ?? $to);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $htmlBody;
            $mail->AltBody = strip_tags(preg_replace('/<br\s*\/?>/i', "\n", $htmlBody) ?: $htmlBody);
            foreach ($attachments as $att) {
                if (is_string($att) && is_file($att)) {
                    $mail->addAttachment($att);
                } elseif (is_array($att) && !empty($att['path']) && is_file($att['path'])) {
                    $mail->addAttachment($att['path'], $att['name'] ?? basename($att['path']));
                }
            }
            return $mail->send();
        } catch (\Throwable $e) {
            error_log('[Mailer] ' . $e->getMessage());
            return false;
        }
    }
}
