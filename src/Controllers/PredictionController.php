<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Core\Mailer;
use App\Core\PdfGenerator;
use App\Core\Session;
use App\Core\Setting;
use App\Core\View;
use App\Services\PredictionResolver;

final class PredictionController extends Controller
{
    public function create(): void
    {
        $user = $this->requireAuth();
        if (Setting::get('predictions_open', '1') !== '1') {
            Session::flash('error', 'Predictions are closed.');
            $this->redirect('/dashboard');
        }
        $this->render('prediction/create.twig');
    }

    public function store(): void
    {
        $user = $this->requireAuth();
        $this->requireCsrf();
        if (Setting::get('predictions_open', '1') !== '1') {
            Session::flash('error', 'Predictions are closed.');
            $this->redirect('/dashboard');
        }
        $label = trim((string) $this->input('label', 'My prediction'));
        if ($label === '') $label = 'My prediction';

        $id = Database::insert('forms', [
            'user_id'    => (int) $user['id'],
            'label'      => $label,
            'status'     => 'draft',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $this->redirect('/predictions/' . $id);
    }

    public function edit(string $id): void
    {
        $form = $this->loadOwnForm((int) $id);

        $groups = Database::fetchAll('SELECT * FROM team_groups ORDER BY sort_order');
        $matchesByGroup = [];
        foreach ($groups as $g) {
            $matchesByGroup[$g['code']] = Database::fetchAll(
                'SELECT m.id, m.match_number, m.kickoff_at, m.venue,
                        h.id AS home_id, h.name AS home_name, h.flag_emoji AS home_flag,
                        a.id AS away_id, a.name AS away_name, a.flag_emoji AS away_flag,
                        p.home_goals, p.away_goals
                   FROM matches m
              LEFT JOIN teams h ON h.id = m.home_team_id
              LEFT JOIN teams a ON a.id = m.away_team_id
              LEFT JOIN predictions p ON p.match_id = m.id AND p.form_id = ? AND p.stage = "group"
                  WHERE m.stage = "group" AND m.group_id = ?
               ORDER BY m.match_number', [$form['id'], $g['id']]
            );
        }

        $teams = Database::fetchAll('SELECT t.*, g.code AS group_code FROM teams t LEFT JOIN team_groups g ON g.id = t.group_id ORDER BY g.sort_order, t.name');
        $players = Database::fetchAll('SELECT p.*, t.name AS team_name FROM players p LEFT JOIN teams t ON t.id = p.team_id ORDER BY p.name');

        $resolved = PredictionResolver::resolve((int) $form['id']);

        $teamsById = [];
        foreach ($teams as $t) {
            $teamsById[(int) $t['id']] = [
                'id'         => (int) $t['id'],
                'name'       => $t['name'],
                'flag_emoji' => $t['flag_emoji'],
                'group'      => $t['group_code'] ?? null,
            ];
        }

        // For submitted entries that aren't paid yet, build the SEPA payment QR
        // so the user can re-scan it any time without digging through their inbox.
        $qrDataUri = '';
        $qrReference = '';
        if ($form['status'] === 'submitted' && empty($form['paid_at'])) {
            $iban = (string) Setting::get('payment_iban', '');
            if ($iban !== '') {
                $beneficiary = (string) (Setting::get('payment_recipient_name')
                    ?: Setting::get('payment_recipient', 'Pool'));
                $amount      = (string) Setting::get('payment_amount', '10.00');
                $qrReference = \App\Core\QrCodeService::buildReference(
                    (string) ($user['name'] ?? ''),
                    (string) $form['label']
                );
                try {
                    $payload = \App\Core\QrCodeService::buildEpcPayload($iban, $beneficiary, $amount, $qrReference);
                    $qrDataUri = \App\Core\QrCodeService::pngDataUri($payload, 320);
                } catch (\Throwable $e) {
                    error_log('[QrCode] ' . $e->getMessage());
                }
            }
        }

        $this->render('prediction/edit.twig', [
            'form'             => $form,
            'groups'           => $groups,
            'matches_by_group' => $matchesByGroup,
            'teams'            => $teams,
            'teams_by_id'      => $teamsById,
            'players'          => $players,
            'resolved'         => $resolved,
            'deadline'         => Setting::get('predictions_deadline'),
            'qr_data_uri'      => $qrDataUri,
            'qr_reference'     => $qrReference,
        ]);
    }

    public function save(string $id): void
    {
        $form = $this->loadOwnForm((int) $id);
        $this->requireCsrf();
        if ($form['status'] === 'submitted') {
            Session::flash('error', 'Entry has already been submitted.');
            $this->redirect('/predictions/' . $form['id']);
        }
        $this->persistAll((int) $form['id'], $_POST);
        Session::flash('success', 'Saved as draft.');
        $this->redirect('/predictions/' . $form['id']);
    }

    public function submit(string $id): void
    {
        $form = $this->loadOwnForm((int) $id);
        $this->requireCsrf();
        if ($form['status'] === 'submitted') {
            Session::flash('error', 'Entry has already been submitted.');
            $this->redirect('/predictions/' . $form['id']);
        }
        if (Setting::get('predictions_open', '1') !== '1') {
            Session::flash('error', 'Predictions are closed.');
            $this->redirect('/predictions/' . $form['id']);
        }
        $this->persistAll((int) $form['id'], $_POST);

        // Convert any free-text topscorer into a real player row
        $this->resolveCustomTopscorer((int) $form['id']);

        // Completeness validation
        $missing = $this->validateComplete((int) $form['id']);
        if (!empty($missing)) {
            foreach ($missing as $m) Session::flash('error', $m);
            $this->redirect('/predictions/' . $form['id']);
        }

        Database::update('forms', [
            'status'       => 'submitted',
            'submitted_at' => date('Y-m-d H:i:s'),
        ], ['id' => $form['id']]);

        $pdfPath = $this->buildPdf((int) $form['id']);
        Database::update('forms', ['pdf_path' => $pdfPath], ['id' => $form['id']]);

        $this->sendSubmissionEmails((int) $form['id'], $pdfPath);

        Session::flash('success', sprintf(
            'Prediction submitted! Don\'t forget to pay %s %s to %s.',
            Setting::get('payment_amount', '10.00'),
            Setting::get('payment_currency', 'EUR'),
            Setting::get('payment_recipient', 'Jonah')
        ));
        $this->redirect('/predictions/' . $form['id']);
    }

    public function pdf(string $id): void
    {
        $form = $this->loadOwnForm((int) $id);
        // Always rebuild so layout/template changes take effect immediately.
        // For submitted forms the underlying data is locked, so this is safe.
        $path = $this->buildPdf((int) $form['id']);
        if ($path !== ($form['pdf_path'] ?? null)) {
            Database::update('forms', ['pdf_path' => $path], ['id' => $form['id']]);
        }
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="wc2026-prediction-' . $form['id'] . '.pdf"');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        readfile($path);
    }

    public function delete(string $id): void
    {
        $form = $this->loadOwnForm((int) $id);
        $this->requireCsrf();
        if ($form['status'] === 'submitted' && !empty($form['paid_at'])) {
            Session::flash('error', 'A submitted and paid entry can no longer be deleted.');
            $this->redirect('/dashboard');
        }
        // Best-effort: also remove the cached PDF
        if (!empty($form['pdf_path']) && is_file($form['pdf_path'])) {
            @unlink($form['pdf_path']);
        }
        Database::delete('forms', ['id' => $form['id']]);
        Session::flash('success', 'Entry deleted.');
        $this->redirect('/dashboard');
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    private function loadOwnForm(int $id): array
    {
        $user = $this->requireAuth();
        $form = Database::fetch('SELECT * FROM forms WHERE id = ? AND user_id = ?', [$id, (int) $user['id']]);
        if (!$form) {
            http_response_code(404);
            echo View::render('errors/404.twig');
            exit;
        }
        return $form;
    }

    private function persistAll(int $formId, array $post): void
    {
        Database::beginTransaction();
        try {
            // Group-stage scores
            $scores = $post['score'] ?? [];
            if (is_array($scores)) {
                foreach ($scores as $matchId => $vals) {
                    $matchId = (int) $matchId;
                    $home = $vals['home'] ?? null;
                    $away = $vals['away'] ?? null;
                    $home = ($home === '' || $home === null) ? null : max(0, (int) $home);
                    $away = ($away === '' || $away === null) ? null : max(0, (int) $away);
                    $this->upsertGroupPrediction($formId, $matchId, $home, $away);
                }
            }

            // Knockout slot picks (R32-XX, R16-XX, QF-XX, SF-XX, F-01)
            $slots = $post['slot'] ?? [];
            if (is_array($slots)) {
                foreach ($slots as $slotCode => $teamId) {
                    $slotCode = trim((string) $slotCode);
                    if ($slotCode === '') continue;
                    $teamId = ($teamId === '' || $teamId === null) ? null : (int) $teamId;
                    $stage = self::stageFromSlot($slotCode);
                    $this->upsertSlotPrediction($formId, $slotCode, $stage, $teamId);
                }
            }

            // Winner + topscorer + tiebreak: only patch fields that were sent.
            $patch = ['updated_at' => date('Y-m-d H:i:s')];

            if (array_key_exists('winner_team_id', $post)) {
                $w = $post['winner_team_id'];
                $patch['winner_team_id'] = ($w === '' || $w === null) ? null : (int) $w;
            }
            if (array_key_exists('topscorer_player_id', $post)) {
                $t = $post['topscorer_player_id'];
                $patch['topscorer_player_id'] = ($t === '' || $t === null) ? null : (int) $t;
            }
            if (array_key_exists('topscorer_custom_name', $post)) {
                $c = trim((string) $post['topscorer_custom_name']);
                $patch['topscorer_custom_name'] = $c === '' ? null : mb_substr($c, 0, 128);
            }
            if (array_key_exists('tiebreaker_value', $post)) {
                $tb = $post['tiebreaker_value'];
                $patch['tiebreaker_value'] = ($tb === '' || $tb === null) ? null : max(0, (int) $tb);
            }
            $label = trim((string)($post['label'] ?? ''));
            if ($label !== '') $patch['label'] = $label;

            Database::update('forms', $patch, ['id' => $formId]);

            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
    }

    private function resolveCustomTopscorer(int $formId): void
    {
        $form = Database::fetch('SELECT topscorer_player_id, topscorer_custom_name FROM forms WHERE id = ?', [$formId]);
        if (!$form) return;
        if (!empty($form['topscorer_player_id'])) {
            // Already linked to a real player; drop any leftover custom name
            if (!empty($form['topscorer_custom_name'])) {
                Database::update('forms', ['topscorer_custom_name' => null], ['id' => $formId]);
            }
            return;
        }
        $name = trim((string)($form['topscorer_custom_name'] ?? ''));
        if ($name === '') return;
        $existing = (int) Database::fetchColumn('SELECT id FROM players WHERE LOWER(name) = LOWER(?) LIMIT 1', [$name]);
        $playerId = $existing ?: Database::insert('players', ['name' => $name, 'team_id' => null]);
        Database::update('forms', [
            'topscorer_player_id'   => $playerId,
            'topscorer_custom_name' => null,
        ], ['id' => $formId]);
    }

    public static function stageFromSlot(string $slot): string
    {
        return match (true) {
            str_starts_with($slot, 'R32') => 'r32',
            str_starts_with($slot, 'R16') => 'r16',
            str_starts_with($slot, 'QF')  => 'qf',
            str_starts_with($slot, 'SF')  => 'sf',
            str_starts_with($slot, 'F')   => 'final',
            default                       => 'group',
        };
    }

    private function upsertGroupPrediction(int $formId, int $matchId, ?int $home, ?int $away): void
    {
        $row = Database::fetch(
            'SELECT id FROM predictions WHERE form_id = ? AND match_id = ? AND stage = "group" AND slot_code = ""',
            [$formId, $matchId]
        );
        if ($row) {
            Database::update('predictions', ['home_goals' => $home, 'away_goals' => $away], ['id' => $row['id']]);
        } else {
            Database::insert('predictions', [
                'form_id' => $formId, 'match_id' => $matchId,
                'home_goals' => $home, 'away_goals' => $away,
                'stage' => 'group', 'slot_code' => '',
            ]);
        }
    }

    private function upsertSlotPrediction(int $formId, string $slot, string $stage, ?int $teamId): void
    {
        $row = Database::fetch(
            'SELECT id FROM predictions WHERE form_id = ? AND slot_code = ?',
            [$formId, $slot]
        );
        if ($row) {
            Database::update('predictions', ['team_id' => $teamId, 'stage' => $stage], ['id' => $row['id']]);
        } else {
            Database::insert('predictions', [
                'form_id' => $formId, 'match_id' => null,
                'home_goals' => null, 'away_goals' => null,
                'stage' => $stage, 'slot_code' => $slot, 'team_id' => $teamId,
            ]);
        }
    }

    /** @return list<string> missing-field messages */
    private function validateComplete(int $formId): array
    {
        $missing = [];
        $unfilled = (int) Database::fetchColumn(
            'SELECT COUNT(*) FROM matches m
              LEFT JOIN predictions p ON p.match_id = m.id AND p.form_id = ?
              WHERE m.stage = "group" AND (p.home_goals IS NULL OR p.away_goals IS NULL)',
            [$formId]
        );
        if ($unfilled > 0) {
            $missing[] = "You still have {$unfilled} group matches without a score.";
        }
        foreach (['R32' => 16, 'R16' => 8, 'QF' => 4, 'SF' => 2, 'F' => 1] as $prefix => $count) {
            $filled = (int) Database::fetchColumn(
                'SELECT COUNT(*) FROM predictions WHERE form_id = ? AND slot_code LIKE ? AND team_id IS NOT NULL',
                [$formId, $prefix . '%']
            );
            if ($filled < $count) {
                $missing[] = "Knockout round {$prefix}: {$filled}/{$count} filled in.";
            }
        }
        $form = Database::fetch('SELECT topscorer_player_id, tiebreaker_value FROM forms WHERE id = ?', [$formId]);
        if (empty($form['topscorer_player_id'])) {
            $missing[] = 'Pick a top scorer.';
        }
        if ($form['tiebreaker_value'] === null) {
            $missing[] = 'Fill in the tiebreaker (numeric value).';
        }
        return $missing;
    }

    public function buildPdf(int $formId): string
    {
        $form = Database::fetch(
            'SELECT f.*, u.name AS user_name, u.email AS user_email FROM forms f JOIN users u ON u.id = f.user_id WHERE f.id = ?',
            [$formId]
        );

        // Teams by id
        $teams = [];
        foreach (Database::fetchAll('SELECT id, name, iso3, flag_emoji FROM teams') as $t) {
            $teams[(int)$t['id']] = $t;
        }
        $winner   = $form['winner_team_id'] ? $teams[(int)$form['winner_team_id']] ?? null : null;
        $topscorer = $form['topscorer_player_id']
            ? Database::fetch('SELECT p.name, t.name AS team_name FROM players p LEFT JOIN teams t ON t.id = p.team_id WHERE p.id = ?', [(int)$form['topscorer_player_id']])
            : null;

        // All matches in chronological order, with predicted scores / slot picks.
        $rawMatches = Database::fetchAll(
            'SELECT m.id, m.stage, m.match_number, m.kickoff_at, m.venue,
                    m.home_team_id, m.away_team_id,
                    g.code AS group_code,
                    h.name AS home_name, h.iso3 AS home_iso,
                    a.name AS away_name, a.iso3 AS away_iso,
                    p.home_goals AS pred_home, p.away_goals AS pred_away
               FROM matches m
          LEFT JOIN team_groups g ON g.id = m.group_id
          LEFT JOIN teams h ON h.id = m.home_team_id
          LEFT JOIN teams a ON a.id = m.away_team_id
          LEFT JOIN predictions p ON p.match_id = m.id AND p.form_id = ? AND p.stage = "group"
           ORDER BY (m.kickoff_at IS NULL), m.kickoff_at, m.match_number',
            [$formId]
        );

        // Knockout slot picks (R32-01, …, F-01)
        $slotPicks = [];
        foreach (Database::fetchAll(
            'SELECT slot_code, team_id FROM predictions WHERE form_id = ? AND team_id IS NOT NULL AND slot_code <> ""',
            [$formId]
        ) as $r) {
            $slotPicks[$r['slot_code']] = (int) $r['team_id'];
        }

        // Build display rows: each row already knows its predicted result.
        $rows = [];
        $stageSlot = ['r32' => 'R32', 'r16' => 'R16', 'qf' => 'QF', 'sf' => 'SF', 'final' => 'F'];
        $stageNum  = ['r32' => 0,    'r16' => 0,    'qf' => 0,   'sf' => 0,   'final' => 0];
        foreach ($rawMatches as $m) {
            $row = [
                'stage'    => $m['stage'],
                'kickoff'  => $m['kickoff_at'],
                'venue'    => $m['venue'],
                'group'    => $m['group_code'],
                'home'     => $m['home_name'] ? ['name' => $m['home_name'], 'iso' => $m['home_iso']] : null,
                'away'     => $m['away_name'] ? ['name' => $m['away_name'], 'iso' => $m['away_iso']] : null,
                'pred_home'=> $m['pred_home'],
                'pred_away'=> $m['pred_away'],
                'slot'     => null,
                'pick'     => null,
            ];
            if ($m['stage'] !== 'group') {
                $stageNum[$m['stage']]++;
                $slot = sprintf('%s-%02d', $stageSlot[$m['stage']], $stageNum[$m['stage']]);
                $row['slot'] = $slot;
                $teamId = $slotPicks[$slot] ?? null;
                if ($teamId && isset($teams[$teamId])) {
                    $row['pick'] = $teams[$teamId];
                }
            }
            $rows[] = $row;
        }

        $html = View::render('prediction/pdf.twig', [
            'form'      => $form,
            'rows'      => $rows,
            'winner'    => $winner,
            'topscorer' => $topscorer,
            'now'       => date('Y-m-d H:i'),
            'settings'  => \App\Core\Setting::all(),
            'filename'  => "wc2026-prediction-{$formId}.pdf",
        ]);

        $dir = \App\Core\Config::basePath('storage/pdfs');
        if (!is_dir($dir)) mkdir($dir, 0775, true);
        $path = $dir . "/form-{$formId}.pdf";
        PdfGenerator::fromHtml($html, $path, "World Cup 2026 prediction – {$form['label']}");
        return $path;
    }

    private function sendSubmissionEmails(int $formId, string $pdfPath): void
    {
        $form = Database::fetch(
            'SELECT f.*, u.name AS user_name, u.email AS user_email FROM forms f JOIN users u ON u.id = f.user_id WHERE f.id = ?',
            [$formId]
        );
        $settings = \App\Core\Setting::all();
        $iban = (string)($settings['payment_iban'] ?? '');
        $beneficiary = (string)($settings['payment_recipient_name'] ?? $settings['payment_recipient'] ?? 'Pool');
        $amount = (string)($settings['payment_amount'] ?? '10.00');
        $reference = \App\Core\QrCodeService::buildReference($form['user_name'], $form['label']);

        // Generate the EPC QR PNG only if an IBAN is configured.
        $qrPath = '';
        if ($iban !== '') {
            try {
                $payload = \App\Core\QrCodeService::buildEpcPayload($iban, $beneficiary, $amount, $reference);
                $qrPath  = \App\Core\Config::basePath("storage/qrcodes/form-{$formId}.png");
                \App\Core\QrCodeService::writePng($payload, $qrPath);
            } catch (\Throwable $e) {
                error_log('[QrCode] ' . $e->getMessage());
                $qrPath = '';
            }
        }

        $vars = [
            'user_name'            => $form['user_name'],
            'user_email'           => $form['user_email'],
            'form_label'           => $form['label'],
            'submitted_at'         => $form['submitted_at'],
            'payment_amount'       => $amount,
            'payment_currency'     => $settings['payment_currency']     ?? 'EUR',
            'payment_recipient'    => $settings['payment_recipient']    ?? 'Jonah',
            'payment_iban'         => $iban,
            'payment_reference'    => $reference,
            'payment_instructions' => $settings['payment_instructions'] ?? '',
            'qr_image'             => $qrPath !== ''
                ? '<img src="cid:wk2026qr" alt="SEPA QR code" style="width:240px;height:240px;display:block;margin:12px 0;border:1px solid #e2e8f0;border-radius:8px;padding:8px;background:#fff">'
                : '',
        ];

        $userTpl  = Database::fetch('SELECT * FROM email_templates WHERE `key` = "submission_user"');
        $adminTpl = Database::fetch('SELECT * FROM email_templates WHERE `key` = "submission_admin"');

        if ($userTpl) {
            $inline = $qrPath !== ''
                ? [['path' => $qrPath, 'cid' => 'wk2026qr', 'name' => "wc2026-payment-{$formId}.png"]]
                : [];
            Mailer::send(
                $form['user_email'],
                $this->renderTemplate($userTpl['subject'], $vars),
                $this->renderTemplate($userTpl['body_html'], $vars),
                [$pdfPath],
                $form['user_name'],
                null, null,
                $inline
            );
        }
        if ($adminTpl) {
            $adminTo = $settings['admin_mail_to'] ?? \App\Core\Config::get('MAIL_ADMIN_ADDRESS', 'wk2026@psb.ugent.be');
            Mailer::send(
                (string) $adminTo,
                $this->renderTemplate($adminTpl['subject'], $vars),
                $this->renderTemplate($adminTpl['body_html'], $vars),
                [$pdfPath],
                null,
                $form['user_email'],   // Reply-To = the submitter
                $form['user_name']
            );
        }
    }

    private function renderTemplate(string $template, array $vars): string
    {
        return preg_replace_callback('/\{\{\s*([a-z_][a-z0-9_]*)\s*\}\}/i', function ($m) use ($vars) {
            return (string) ($vars[$m[1]] ?? '');
        }, $template);
    }
}
