<?php
declare(strict_types=1);

namespace App\Core;

use Mpdf\Mpdf;

final class PdfGenerator
{
    public static function fromHtml(string $html, string $outputPath, string $title = 'WK2026 voorspelling'): string
    {
        $tmp = Config::basePath('storage/cache/mpdf');
        if (!is_dir($tmp)) {
            mkdir($tmp, 0775, true);
        }
        $mpdf = new Mpdf([
            'tempDir' => $tmp,
            'mode' => 'utf-8',
            'format' => 'A4',
            'margin_left' => 12,
            'margin_right' => 12,
            'margin_top' => 14,
            'margin_bottom' => 14,
        ]);
        $mpdf->SetTitle($title);
        $mpdf->SetAuthor('WK2026 Pool');
        $mpdf->WriteHTML($html);

        $dir = dirname($outputPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $mpdf->Output($outputPath, \Mpdf\Output\Destination::FILE);
        return $outputPath;
    }
}
