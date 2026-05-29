<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Generates EPC QR codes (a.k.a. SEPA / Girocode) so Belgian / European
 * banking apps can prefill a credit-transfer screen from a single scan.
 *
 * EPC069-12 payload format:
 *   BCD
 *   002              <- version
 *   1                <- character set UTF-8
 *   SCT              <- SEPA credit transfer
 *   {BIC or empty}
 *   {Beneficiary name}      (max 70 chars)
 *   {IBAN}                  (spaces stripped)
 *   EUR{amount}             (e.g. EUR10.00)
 *   {Purpose code or empty}
 *   {Structured reference or empty}
 *   {Unstructured remittance info or empty} (max 140 chars)
 *
 * https://www.europeanpaymentscouncil.eu/document-library/guidance-documents/quick-response-code-guidelines-enable-data-capture-initiation
 */
final class QrCodeService
{
    public static function buildEpcPayload(
        string $iban,
        string $beneficiary,
        float|string $amount,
        string $reference,
        string $bic = ''
    ): string {
        $iban = strtoupper(preg_replace('/\s+/', '', $iban));
        $beneficiary = self::clean($beneficiary, 70);
        $reference   = self::clean($reference, 140);
        $amount      = number_format((float)$amount, 2, '.', '');

        return implode("\n", [
            'BCD',
            '002',
            '1',
            'SCT',
            $bic,
            $beneficiary,
            $iban,
            'EUR' . $amount,
            '',           // purpose
            '',           // structured reference
            $reference,   // unstructured (free text)
        ]);
    }

    /**
     * Write a PNG of the given payload to $outputPath. Requires endroid/qr-code
     * (composer require endroid/qr-code:^5.0). Returns the path on success.
     */
    public static function writePng(string $payload, string $outputPath, int $size = 320): string
    {
        if (!class_exists(\Endroid\QrCode\QrCode::class)) {
            throw new \RuntimeException(
                'endroid/qr-code is not installed. Run `composer require endroid/qr-code:^5.0`.'
            );
        }
        $qrCode = new \Endroid\QrCode\QrCode($payload);
        $qrCode->setSize($size);
        $qrCode->setMargin(8);
        $qrCode->setEncoding(new \Endroid\QrCode\Encoding\Encoding('UTF-8'));
        $qrCode->setErrorCorrectionLevel(\Endroid\QrCode\ErrorCorrectionLevel::Medium);

        $writer = new \Endroid\QrCode\Writer\PngWriter();
        $result = $writer->write($qrCode);

        $dir = dirname($outputPath);
        if (!is_dir($dir)) mkdir($dir, 0775, true);
        $result->saveToFile($outputPath);
        return $outputPath;
    }

    /** Returns a sanitized data URI (data:image/png;base64,…) ready for inline <img>. */
    public static function pngDataUri(string $payload, int $size = 320): string
    {
        if (!class_exists(\Endroid\QrCode\QrCode::class)) {
            return '';
        }
        $qrCode = new \Endroid\QrCode\QrCode($payload);
        $qrCode->setSize($size);
        $qrCode->setMargin(8);
        $qrCode->setEncoding(new \Endroid\QrCode\Encoding\Encoding('UTF-8'));
        $qrCode->setErrorCorrectionLevel(\Endroid\QrCode\ErrorCorrectionLevel::Medium);
        $writer = new \Endroid\QrCode\Writer\PngWriter();
        return $writer->write($qrCode)->getDataUri();
    }

    /** Build a clean human-readable reference: "USERNAME - FORMNAME", capped. */
    public static function buildReference(string $userName, string $formLabel): string
    {
        $ref = trim($userName) . ' - ' . trim($formLabel);
        return self::clean($ref, 140);
    }

    private static function clean(string $s, int $max): string
    {
        $s = preg_replace('/\s+/', ' ', $s) ?? $s;
        $s = trim($s);
        if (mb_strlen($s) > $max) {
            $s = mb_substr($s, 0, $max);
        }
        return $s;
    }
}
