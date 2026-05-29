<?php
declare(strict_types=1);

/**
 * Add the payment_recipient_name setting and replace the default
 * submission_user email template with a QR-enabled version. Existing
 * admin-edited templates are left untouched (use --force to overwrite).
 *
 *   php migrations/migrate_add_qr_payment.php          # safe
 *   php migrations/migrate_add_qr_payment.php --force  # overwrite the template too
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Core\Database;

Config::load(dirname(__DIR__));
$force = in_array('--force', $argv, true);

$nameExists = (int) Database::fetchColumn(
    'SELECT COUNT(*) FROM settings WHERE `key` = ?',
    ['payment_recipient_name']
);
if (!$nameExists) {
    Database::insert('settings', [
        'key'        => 'payment_recipient_name',
        'value'      => '',
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
    echo "✓ Added setting payment_recipient_name (empty by default — falls back to payment_recipient).\n";
} else {
    echo "✓ Setting payment_recipient_name already exists.\n";
}

$oldEnglishSubject = 'Your World Cup 2026 prediction has been received';
$newBody = "<p>Hi {{user_name}},</p><p>Thanks for submitting your World Cup 2026 prediction <strong>\"{{form_label}}\"</strong>. Attached you'll find a PDF with your final picks.</p><p><strong>How to pay:</strong> Scan the QR code below with your banking app — it pre-fills the amount, IBAN and reference. Or transfer manually:</p><ul><li>Amount: <strong>{{payment_amount}} {{payment_currency}}</strong></li><li>To: <strong>{{payment_recipient}}</strong></li><li>IBAN: <strong>{{payment_iban}}</strong></li><li>Reference: <strong>{{payment_reference}}</strong></li></ul>{{qr_image}}<p>{{payment_instructions}}</p><p>Good luck — may the best predictor win!<br/>– World Cup 2026 Pool</p>";

$tpl = Database::fetch('SELECT * FROM email_templates WHERE `key` = ?', ['submission_user']);
if ($tpl) {
    $subjMatches = $tpl['subject'] === $oldEnglishSubject;
    $isDefault   = $subjMatches && strpos((string)$tpl['body_html'], '{{qr_image}}') === false;
    if ($isDefault || $force) {
        Database::query(
            'UPDATE email_templates SET subject = ?, body_html = ?, updated_at = NOW() WHERE id = ?',
            [$oldEnglishSubject, $newBody, (int)$tpl['id']]
        );
        echo "✓ submission_user template upgraded with QR placeholder.\n";
    } else {
        echo "ℹ submission_user template looks admin-edited — left untouched. Use --force to overwrite,\n";
        echo "  or paste {{qr_image}} and {{payment_reference}} manually in /admin/email-templates.\n";
    }
} else {
    echo "⚠ submission_user template not found — skipping.\n";
}

// Ensure the storage dir exists and is writable.
$dir = dirname(__DIR__) . '/storage/qrcodes';
if (!is_dir($dir)) {
    mkdir($dir, 0775, true);
    echo "✓ Created {$dir}\n";
}
