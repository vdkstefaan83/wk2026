<?php
declare(strict_types=1);

namespace App\Core;

use PHPMailer\PHPMailer\PHPMailer;

final class Mailer
{
    private static string $lastError = '';

    public static function lastError(): string
    {
        return self::$lastError;
    }

    /** Returns true on success, false on failure. Use lastError() to inspect failures. */
    public static function send(string $to, string $subject, string $htmlBody, array $attachments = [], ?string $toName = null, ?string $replyToEmail = null, ?string $replyToName = null, array $inlineImages = []): bool
    {
        try {
            self::doSend($to, $subject, $htmlBody, $attachments, $toName, $replyToEmail, $replyToName, $inlineImages);
            self::$lastError = '';
            return true;
        } catch (\Throwable $e) {
            self::$lastError = $e->getMessage();
            error_log('[Mailer] ' . $e->getMessage());
            return false;
        }
    }

    /** Same as send() but throws on failure — handy when the caller wants to surface the exact error. */
    public static function sendOrThrow(string $to, string $subject, string $htmlBody, array $attachments = [], ?string $toName = null, ?string $replyToEmail = null, ?string $replyToName = null, array $inlineImages = []): void
    {
        self::doSend($to, $subject, $htmlBody, $attachments, $toName, $replyToEmail, $replyToName, $inlineImages);
    }

    private static function doSend(string $to, string $subject, string $htmlBody, array $attachments, ?string $toName, ?string $replyToEmail = null, ?string $replyToName = null, array $inlineImages = []): void
    {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = (string) Config::get('MAIL_HOST', 'localhost');
        $mail->Port = (int)    Config::get('MAIL_PORT', 587);
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
        if ($replyToEmail !== null && $replyToEmail !== '') {
            $mail->addReplyTo($replyToEmail, $replyToName ?? $replyToEmail);
        }
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
        foreach ($inlineImages as $img) {
            if (is_array($img) && !empty($img['path']) && !empty($img['cid']) && is_file($img['path'])) {
                $mail->addEmbeddedImage(
                    $img['path'],
                    $img['cid'],
                    $img['name'] ?? basename($img['path']),
                    PHPMailer::ENCODING_BASE64,
                    $img['type'] ?? 'image/png'
                );
            }
        }
        $mail->send();
    }
}
