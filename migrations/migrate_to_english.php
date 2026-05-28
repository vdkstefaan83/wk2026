<?php
declare(strict_types=1);

/**
 * One-shot migration: translate Dutch DB content to English.
 *
 *   php migrations/migrate_to_english.php          # safe: leaves admin-edited rows alone
 *   php migrations/migrate_to_english.php --force  # overwrite admin-edited settings/templates too
 *
 * Translates:
 *   - team names + group names (always, since names are reference data)
 *   - settings: tiebreaker_question, payment_instructions (only if still default Dutch)
 *   - email_templates submission_user / submission_admin (only if still default Dutch,
 *     unless --force is passed)
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Core\Database;

Config::load(dirname(__DIR__));
$force = in_array('--force', $argv, true);

// ---------- Teams ----------
$teamMap = [
    'Mexico'                 => 'Mexico',
    'Zuid-Afrika'            => 'South Africa',
    'Zuid-Korea'             => 'South Korea',
    'Tsjechië'               => 'Czech Republic',
    'Canada'                 => 'Canada',
    'Bosnië en Herzegovina'  => 'Bosnia and Herzegovina',
    'Qatar'                  => 'Qatar',
    'Zwitserland'            => 'Switzerland',
    'Brazilië'               => 'Brazil',
    'Marokko'                => 'Morocco',
    'Haïti'                  => 'Haiti',
    'Schotland'              => 'Scotland',
    'Verenigde Staten'       => 'United States',
    'Paraguay'               => 'Paraguay',
    'Australië'              => 'Australia',
    'Turkije'                => 'Turkey',
    'Duitsland'              => 'Germany',
    'Curaçao'                => 'Curaçao',
    'Ivoorkust'              => 'Ivory Coast',
    'Ecuador'                => 'Ecuador',
    'Nederland'              => 'Netherlands',
    'Japan'                  => 'Japan',
    'Zweden'                 => 'Sweden',
    'Tunesië'                => 'Tunisia',
    'België'                 => 'Belgium',
    'Egypte'                 => 'Egypt',
    'Iran'                   => 'Iran',
    'Nieuw-Zeeland'          => 'New Zealand',
    'Spanje'                 => 'Spain',
    'Kaapverdië'             => 'Cape Verde',
    'Saudi-Arabië'           => 'Saudi Arabia',
    'Saoedi-Arabië'          => 'Saudi Arabia',
    'Uruguay'                => 'Uruguay',
    'Frankrijk'              => 'France',
    'Senegal'                => 'Senegal',
    'Irak'                   => 'Iraq',
    'Noorwegen'              => 'Norway',
    'Argentinië'             => 'Argentina',
    'Algerije'               => 'Algeria',
    'Oostenrijk'             => 'Austria',
    'Jordanië'               => 'Jordan',
    'Portugal'               => 'Portugal',
    'DR Congo'               => 'DR Congo',
    'Oezbekistan'            => 'Uzbekistan',
    'Colombia'               => 'Colombia',
    'Engeland'               => 'England',
    'Kroatië'                => 'Croatia',
    'Ghana'                  => 'Ghana',
    'Panama'                 => 'Panama',
];

echo "→ Translating team names…\n";
$renamed = 0;
foreach ($teamMap as $nl => $en) {
    if ($nl === $en) continue;
    $count = Database::query('UPDATE teams SET name = ? WHERE name = ?', [$en, $nl])->rowCount();
    if ($count > 0) {
        echo "  · {$nl} → {$en}\n";
        $renamed += $count;
    }
}
echo "✓ {$renamed} teams renamed.\n";

// ---------- Team groups (code stays, but the name column may say "Groep X") ----------
echo "→ Translating group names…\n";
$g = Database::query("UPDATE team_groups SET name = REPLACE(name, 'Groep', 'Group') WHERE name LIKE 'Groep %'")->rowCount();
echo "✓ {$g} group rows updated.\n";

// ---------- Settings ----------
$settingMap = [
    'tiebreaker_question' => [
        'old' => 'Hoeveel doelpunten worden er in totaal gemaakt tijdens het toernooi?',
        'new' => 'How many goals will be scored in the entire tournament?',
    ],
    'payment_instructions' => [
        'old' => 'Gelieve het bedrag in cash aan Jonah te overhandigen.',
        'new' => 'Please hand the payment to Jonah in cash.',
    ],
];
echo "→ Translating settings…\n";
foreach ($settingMap as $key => $pair) {
    $current = (string) Database::fetchColumn('SELECT `value` FROM settings WHERE `key` = ?', [$key]);
    if ($current === '') continue;
    if ($current === $pair['old'] || $force) {
        Database::query('UPDATE settings SET `value` = ?, updated_at = NOW() WHERE `key` = ?', [$pair['new'], $key]);
        echo "  · {$key} updated\n";
    } else {
        echo "  · {$key} skipped (admin-edited; use --force to overwrite)\n";
    }
}

// ---------- Email templates ----------
$templates = [
    'submission_user' => [
        'subject' => 'Your World Cup 2026 prediction has been received',
        'body'    => "<p>Hi {{user_name}},</p><p>Thanks for submitting your World Cup 2026 prediction <strong>\"{{form_label}}\"</strong>. Attached you'll find a PDF with your final picks.</p><p><strong>Payment:</strong> Please pay <strong>{{payment_amount}} {{payment_currency}}</strong> to <strong>{{payment_recipient}}</strong> to confirm your entry.</p><p>{{payment_instructions}}</p><p>Good luck — may the best predictor win!<br/>– World Cup 2026 Pool</p>",
        'old_subject' => 'Jouw WK2026 voorspelling is ontvangen',
    ],
    'submission_admin' => [
        'subject' => 'New World Cup 2026 prediction: {{user_name}} – {{form_label}}',
        'body'    => '<p>A new prediction has been submitted.</p><ul><li><strong>User:</strong> {{user_name}} ({{user_email}})</li><li><strong>Form:</strong> {{form_label}}</li><li><strong>Submitted at:</strong> {{submitted_at}}</li></ul><p>The PDF is attached.</p>',
        'old_subject' => 'Nieuwe WK2026 voorspelling: {{user_name}} – {{form_label}}',
    ],
];
echo "→ Translating email templates…\n";
foreach ($templates as $key => $tpl) {
    $row = Database::fetch('SELECT id, subject FROM email_templates WHERE `key` = ?', [$key]);
    if (!$row) continue;
    if ($row['subject'] === $tpl['old_subject'] || $force) {
        Database::query(
            'UPDATE email_templates SET subject = ?, body_html = ?, updated_at = NOW() WHERE id = ?',
            [$tpl['subject'], $tpl['body'], (int)$row['id']]
        );
        echo "  · {$key} updated\n";
    } else {
        echo "  · {$key} skipped (admin-edited; use --force to overwrite)\n";
    }
}

echo "✓ Done.\n";
