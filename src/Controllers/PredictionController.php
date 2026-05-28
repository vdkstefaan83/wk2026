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
            Session::flash('error', 'Voorspellingen zijn gesloten.');
            $this->redirect('/dashboard');
        }
        $this->render('prediction/create.twig');
    }

    public function store(): void
    {
        $user = $this->requireAuth();
        $this->requireCsrf();
        if (Setting::get('predictions_open', '1') !== '1') {
            Session::flash('error', 'Voorspellingen zijn gesloten.');
            $this->redirect('/dashboard');
        }
        $label = trim((string) $this->input('label', 'Mijn voorspelling'));
        if ($label === '') $label = 'Mijn voorspelling';

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
        if ($form['status'] === 'submitted') {
            Session::flash('info', 'Dit formulier is reeds verzonden.');
        }

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

        $this->render('prediction/edit.twig', [
            'form'             => $form,
            'groups'           => $groups,
            'matches_by_group' => $matchesByGroup,
            'teams'            => $teams,
            'teams_by_id'      => $teamsById,
            'players'          => $players,
            'resolved'         => $resolved,
            'deadline'         => Setting::get('predictions_deadline'),
        ]);
    }

    public function save(string $id): void
    {
        $form = $this->loadOwnForm((int) $id);
        $this->requireCsrf();
        if ($form['status'] === 'submitted') {
            Session::flash('error', 'Formulier is al verzonden.');
            $this->redirect('/predictions/' . $form['id']);
        }
        $this->persistAll((int) $form['id'], $_POST);
        Session::flash('success', 'Tussentijds bewaard.');
        $this->redirect('/predictions/' . $form['id']);
    }

    public function submit(string $id): void
    {
        $form = $this->loadOwnForm((int) $id);
        $this->requireCsrf();
        if ($form['status'] === 'submitted') {
            Session::flash('error', 'Formulier is al verzonden.');
            $this->redirect('/predictions/' . $form['id']);
        }
        if (Setting::get('predictions_open', '1') !== '1') {
            Session::flash('error', 'Voorspellingen zijn gesloten.');
            $this->redirect('/predictions/' . $form['id']);
        }
        $this->persistAll((int) $form['id'], $_POST);

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
            'Voorspelling verzonden! Vergeet niet %s %s aan %s te betalen.',
            Setting::get('payment_amount', '10.00'),
            Setting::get('payment_currency', 'EUR'),
            Setting::get('payment_recipient', 'Jonah')
        ));
        $this->redirect('/predictions/' . $form['id']);
    }

    public function pdf(string $id): void
    {
        $form = $this->loadOwnForm((int) $id);
        if (empty($form['pdf_path']) || !is_file($form['pdf_path'])) {
            $form['pdf_path'] = $this->buildPdf((int) $form['id']);
            Database::update('forms', ['pdf_path' => $form['pdf_path']], ['id' => $form['id']]);
        }
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="wk2026-voorspelling-' . $form['id'] . '.pdf"');
        readfile($form['pdf_path']);
    }

    public function delete(string $id): void
    {
        $form = $this->loadOwnForm((int) $id);
        $this->requireCsrf();
        if ($form['status'] === 'submitted') {
            Session::flash('error', 'Een verzonden formulier kan niet meer verwijderd worden.');
            $this->redirect('/dashboard');
        }
        Database::delete('forms', ['id' => $form['id']]);
        Session::flash('success', 'Formulier verwijderd.');
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

            // Winner + topscorer
            $winner = $post['winner_team_id'] ?? null;
            $top    = $post['topscorer_player_id'] ?? null;
            $label  = trim((string)($post['label'] ?? ''));
            $patch = [];
            $patch['winner_team_id']      = ($winner === '' || $winner === null) ? null : (int) $winner;
            $patch['topscorer_player_id'] = ($top === '' || $top === null) ? null : (int) $top;
            if ($label !== '') $patch['label'] = $label;
            $patch['updated_at'] = date('Y-m-d H:i:s');
            Database::update('forms', $patch, ['id' => $formId]);

            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
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
            $missing[] = "Je hebt nog {$unfilled} groepswedstrijden niet ingevuld.";
        }
        foreach (['R32' => 16, 'R16' => 8, 'QF' => 4, 'SF' => 2, 'F' => 1] as $prefix => $count) {
            $filled = (int) Database::fetchColumn(
                'SELECT COUNT(*) FROM predictions WHERE form_id = ? AND slot_code LIKE ? AND team_id IS NOT NULL',
                [$formId, $prefix . '%']
            );
            if ($filled < $count) {
                $missing[] = "Knock-out ronde {$prefix}: {$filled}/{$count} ingevuld.";
            }
        }
        $form = Database::fetch('SELECT winner_team_id, topscorer_player_id FROM forms WHERE id = ?', [$formId]);
        if (empty($form['winner_team_id']))      $missing[] = 'Kies de wereldkampioen.';
        if (empty($form['topscorer_player_id'])) $missing[] = 'Kies een topscorer.';
        return $missing;
    }

    private function buildPdf(int $formId): string
    {
        $form = Database::fetch(
            'SELECT f.*, u.name AS user_name, u.email AS user_email FROM forms f JOIN users u ON u.id = f.user_id WHERE f.id = ?',
            [$formId]
        );
        $resolved = PredictionResolver::resolve($formId);

        $teams = [];
        foreach (Database::fetchAll('SELECT id, name, flag_emoji FROM teams') as $t) {
            $teams[(int)$t['id']] = $t;
        }
        $winner   = $form['winner_team_id'] ? $teams[(int)$form['winner_team_id']] ?? null : null;
        $topscorer= $form['topscorer_player_id']
            ? Database::fetch('SELECT p.name, t.name AS team_name FROM players p LEFT JOIN teams t ON t.id = p.team_id WHERE p.id = ?', [(int)$form['topscorer_player_id']])
            : null;

        $html = View::render('prediction/pdf.twig', [
            'form'      => $form,
            'resolved'  => $resolved,
            'teams_by_id' => $teams,
            'winner'    => $winner,
            'topscorer' => $topscorer,
            'now'       => date('Y-m-d H:i'),
            'settings'  => \App\Core\Setting::all(),
        ]);

        $dir = \App\Core\Config::basePath('storage/pdfs');
        if (!is_dir($dir)) mkdir($dir, 0775, true);
        $path = $dir . "/form-{$formId}.pdf";
        PdfGenerator::fromHtml($html, $path, "WK2026 voorspelling – {$form['label']}");
        return $path;
    }

    private function sendSubmissionEmails(int $formId, string $pdfPath): void
    {
        $form = Database::fetch(
            'SELECT f.*, u.name AS user_name, u.email AS user_email FROM forms f JOIN users u ON u.id = f.user_id WHERE f.id = ?',
            [$formId]
        );
        $settings = \App\Core\Setting::all();
        $vars = [
            'user_name'            => $form['user_name'],
            'user_email'           => $form['user_email'],
            'form_label'           => $form['label'],
            'submitted_at'         => $form['submitted_at'],
            'payment_amount'       => $settings['payment_amount']       ?? '10.00',
            'payment_currency'     => $settings['payment_currency']     ?? 'EUR',
            'payment_recipient'    => $settings['payment_recipient']    ?? 'Jonah',
            'payment_instructions' => $settings['payment_instructions'] ?? '',
        ];

        $userTpl  = Database::fetch('SELECT * FROM email_templates WHERE `key` = "submission_user"');
        $adminTpl = Database::fetch('SELECT * FROM email_templates WHERE `key` = "submission_admin"');

        if ($userTpl) {
            Mailer::send(
                $form['user_email'],
                $this->renderTemplate($userTpl['subject'], $vars),
                $this->renderTemplate($userTpl['body_html'], $vars),
                [$pdfPath],
                $form['user_name']
            );
        }
        if ($adminTpl) {
            $adminTo = $settings['admin_mail_to'] ?? \App\Core\Config::get('MAIL_ADMIN_ADDRESS', 'wk2026@psb.ugent.be');
            Mailer::send(
                (string) $adminTo,
                $this->renderTemplate($adminTpl['subject'], $vars),
                $this->renderTemplate($adminTpl['body_html'], $vars),
                [$pdfPath]
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
