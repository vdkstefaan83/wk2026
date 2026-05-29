<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Core\Session;
use App\Core\Setting;
use App\Services\MatchSyncService;
use App\Services\ScoringService;

final class AdminController extends Controller
{
    public function dashboard(): void
    {
        $this->requireAdmin();
        $stats = [
            'users'         => (int) Database::fetchColumn('SELECT COUNT(*) FROM users'),
            'forms_total'   => (int) Database::fetchColumn('SELECT COUNT(*) FROM forms'),
            'forms_submitted'=> (int) Database::fetchColumn('SELECT COUNT(*) FROM forms WHERE status = "submitted"'),
            'forms_paid'    => (int) Database::fetchColumn('SELECT COUNT(*) FROM forms WHERE paid_at IS NOT NULL'),
        ];
        $this->render('admin/dashboard.twig', ['stats' => $stats]);
    }

    // -------- Settings --------

    public function settings(): void
    {
        $this->requireAdmin();
        $this->render('admin/settings.twig', ['settings' => Setting::all()]);
    }

    public function saveSettings(): void
    {
        $this->requireAdmin();
        $this->requireCsrf();
        $fields = [
            'auth_provider','registration_open','predictions_open','predictions_deadline',
            'payment_amount','payment_currency','payment_recipient','payment_iban',
            'payment_instructions','admin_mail_to',
            'tiebreaker_question','tiebreaker_correct_value',
        ];
        foreach ($fields as $f) {
            if (array_key_exists($f, $_POST)) {
                Setting::set($f, (string)$_POST[$f]);
            }
        }
        Session::flash('success', 'Settings saved.');
        $this->redirect('/admin/settings');
    }

    // -------- Email templates --------

    public function emailTemplates(): void
    {
        $this->requireAdmin();
        $tpls = Database::fetchAll('SELECT * FROM email_templates ORDER BY `key`');
        $this->render('admin/email_templates.twig', ['templates' => $tpls]);
    }

    public function editEmailTemplate(string $key): void
    {
        $this->requireAdmin();
        $tpl = Database::fetch('SELECT * FROM email_templates WHERE `key` = ?', [$key]);
        if (!$tpl) { Session::flash('error', 'Template not found.'); $this->redirect('/admin/email-templates'); }
        $this->render('admin/email_template_edit.twig', ['template' => $tpl]);
    }

    public function saveEmailTemplate(string $key): void
    {
        $this->requireAdmin();
        $this->requireCsrf();
        $subject = (string) ($_POST['subject'] ?? '');
        $body    = (string) ($_POST['body_html'] ?? '');
        $tpl = Database::fetch('SELECT id FROM email_templates WHERE `key` = ?', [$key]);
        if (!$tpl) { Session::flash('error', 'Template not found.'); $this->redirect('/admin/email-templates'); }
        Database::update('email_templates', [
            'subject'    => $subject,
            'body_html'  => $body,
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['id' => $tpl['id']]);
        Session::flash('success', 'Template saved.');
        $this->redirect('/admin/email-templates/' . $key);
    }

    // -------- Teams --------

    public function teams(): void
    {
        $this->requireAdmin();
        $groups = Database::fetchAll('SELECT * FROM team_groups ORDER BY sort_order');
        $teams  = Database::fetchAll('SELECT * FROM teams ORDER BY name');
        $this->render('admin/teams.twig', compact('groups','teams'));
    }

    public function saveTeams(): void
    {
        $this->requireAdmin();
        $this->requireCsrf();
        $teams = $_POST['team'] ?? [];
        if (is_array($teams)) {
            foreach ($teams as $id => $row) {
                Database::update('teams', [
                    'name'       => (string)($row['name'] ?? ''),
                    'iso3'       => (string)($row['iso3'] ?? ''),
                    'flag_emoji' => (string)($row['flag_emoji'] ?? ''),
                    'group_id'   => $row['group_id'] !== '' ? (int)$row['group_id'] : null,
                ], ['id' => (int)$id]);
            }
        }
        Session::flash('success', 'Teams saved.');
        $this->redirect('/admin/teams');
    }

    // -------- Matches (incl. actual results) --------

    public function matches(): void
    {
        $this->requireAdmin();
        $stage = (string)($this->input('stage', 'group'));
        $matches = Database::fetchAll(
            'SELECT m.*, g.code AS group_code,
                    h.name AS home_name, h.flag_emoji AS home_flag,
                    a.name AS away_name, a.flag_emoji AS away_flag
               FROM matches m
          LEFT JOIN team_groups g ON g.id = m.group_id
          LEFT JOIN teams h ON h.id = m.home_team_id
          LEFT JOIN teams a ON a.id = m.away_team_id
              WHERE m.stage = ?
           ORDER BY m.match_number', [$stage]
        );
        $teams = Database::fetchAll('SELECT id, name, flag_emoji FROM teams ORDER BY name');
        $this->render('admin/matches.twig', compact('matches','teams','stage'));
    }

    public function saveMatches(): void
    {
        $this->requireAdmin();
        $this->requireCsrf();
        $rows = $_POST['match'] ?? [];
        if (is_array($rows)) {
            foreach ($rows as $id => $row) {
                $patch = [
                    'home_team_id' => isset($row['home_team_id']) && $row['home_team_id'] !== '' ? (int)$row['home_team_id'] : null,
                    'away_team_id' => isset($row['away_team_id']) && $row['away_team_id'] !== '' ? (int)$row['away_team_id'] : null,
                    'kickoff_at'   => $row['kickoff_at'] ?: null,
                    'venue'        => $row['venue'] ?? null,
                    'actual_home_goals' => isset($row['actual_home_goals']) && $row['actual_home_goals'] !== '' ? max(0, (int)$row['actual_home_goals']) : null,
                    'actual_away_goals' => isset($row['actual_away_goals']) && $row['actual_away_goals'] !== '' ? max(0, (int)$row['actual_away_goals']) : null,
                ];
                Database::update('matches', $patch, ['id' => (int)$id]);
            }
        }
        Session::flash('success', 'Matches saved.');
        $stage = (string)($this->input('stage', 'group'));
        $this->redirect('/admin/matches?stage=' . urlencode($stage));
    }

    // -------- Players (for topscorer choice) --------

    public function players(): void
    {
        $this->requireAdmin();
        $players = Database::fetchAll('SELECT p.*, t.name AS team_name FROM players p LEFT JOIN teams t ON t.id = p.team_id ORDER BY t.name, p.name');
        $teams = Database::fetchAll('SELECT id, name FROM teams ORDER BY name');
        $this->render('admin/players.twig', compact('players','teams'));
    }

    public function savePlayers(): void
    {
        $this->requireAdmin();
        $this->requireCsrf();
        if (!empty($_POST['delete'])) {
            Database::delete('players', ['id' => (int)$_POST['delete']]);
        }
        if (!empty($_POST['new']['name'])) {
            Database::insert('players', [
                'name' => trim((string)$_POST['new']['name']),
                'team_id' => $_POST['new']['team_id'] !== '' ? (int)$_POST['new']['team_id'] : null,
            ]);
        }
        $rows = $_POST['player'] ?? [];
        if (is_array($rows)) {
            foreach ($rows as $id => $row) {
                Database::update('players', [
                    'name'    => (string)($row['name'] ?? ''),
                    'team_id' => $row['team_id'] !== '' ? (int)$row['team_id'] : null,
                ], ['id' => (int)$id]);
            }
        }
        Session::flash('success', 'Players saved.');
        $this->redirect('/admin/players');
    }

    // -------- Forms / payment tracking --------

    public function forms(): void
    {
        $this->requireAdmin();
        $filter = (string) ($_GET['filter'] ?? 'all');
        $where = '';
        if ($filter === 'paid')        $where = ' WHERE f.paid_at IS NOT NULL';
        elseif ($filter === 'unpaid')  $where = ' WHERE f.status = "submitted" AND f.paid_at IS NULL';
        elseif ($filter === 'draft')   $where = ' WHERE f.status = "draft"';
        elseif ($filter === 'submitted') $where = ' WHERE f.status = "submitted"';

        $forms = Database::fetchAll(
            'SELECT f.*, u.name AS user_name, u.email AS user_email
               FROM forms f
               JOIN users u ON u.id = f.user_id'
            . $where
            . ' ORDER BY f.status = "draft" ASC, f.paid_at IS NULL ASC, f.submitted_at DESC, f.created_at DESC'
        );

        $counts = Database::fetch(
            'SELECT
                COUNT(*) AS total,
                SUM(status = "draft") AS draft,
                SUM(status = "submitted") AS submitted,
                SUM(status = "submitted" AND paid_at IS NOT NULL) AS paid,
                SUM(status = "submitted" AND paid_at IS NULL) AS unpaid,
                COALESCE(SUM(paid_amount), 0) AS revenue
              FROM forms'
        );

        $this->render('admin/forms.twig', [
            'forms'  => $forms,
            'counts' => $counts,
            'filter' => $filter,
        ]);
    }

    public function formPdf(string $id): void
    {
        $this->requireAdmin();
        $form = Database::fetch('SELECT id, label FROM forms WHERE id = ?', [(int) $id]);
        if (!$form) {
            http_response_code(404);
            echo \App\Core\View::render('errors/404.twig');
            return;
        }
        $path = (new \App\Controllers\PredictionController())->buildPdf((int) $form['id']);
        if ($path !== '') {
            Database::update('forms', ['pdf_path' => $path], ['id' => (int) $form['id']]);
        }
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="wc2026-prediction-' . (int) $form['id'] . '.pdf"');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        readfile($path);
    }

    public function deleteForm(string $id): void
    {
        $this->requireAdmin();
        $this->requireCsrf();
        $form = Database::fetch('SELECT id, pdf_path, label FROM forms WHERE id = ?', [(int) $id]);
        if (!$form) {
            Session::flash('error', 'Entry not found.');
            $this->redirect('/admin/forms');
        }
        if (!empty($form['pdf_path']) && is_file($form['pdf_path'])) {
            @unlink($form['pdf_path']);
        }
        Database::delete('forms', ['id' => (int) $form['id']]);
        Session::flash('success', 'Entry "' . $form['label'] . '" deleted.');
        $this->redirect('/admin/forms');
    }

    public function markPaid(string $id): void
    {
        $this->requireAdmin();
        $this->requireCsrf();
        $paid    = !empty($_POST['paid']);
        $amount  = $_POST['amount'] ?? Setting::get('payment_amount', '10.00');
        $note    = (string)($_POST['note'] ?? '');
        Database::update('forms', [
            'paid_at'    => $paid ? date('Y-m-d H:i:s') : null,
            'paid_amount'=> $paid ? (float)$amount : null,
            'paid_note'  => $note,
        ], ['id' => (int)$id]);
        Session::flash('success', 'Payment status updated.');
        $this->redirect('/admin/forms');
    }

    public function leaderboard(): void
    {
        $this->requireAdmin();
        $correct = Setting::get('tiebreaker_correct_value', '');
        $showAll = !empty($_GET['all']);
        $paidFilter = $showAll ? '' : ' AND f.paid_at IS NOT NULL';

        if ($correct === '' || $correct === null) {
            $rows = Database::fetchAll(
                'SELECT f.id, f.label, f.score, f.tiebreaker_value, f.paid_at,
                        u.name AS user_name, u.email AS user_email,
                        NULL AS tiebreak_diff
                   FROM forms f
                   JOIN users u ON u.id = f.user_id
                  WHERE f.status = "submitted"' . $paidFilter . '
               ORDER BY f.score DESC, u.name'
            );
        } else {
            $rows = Database::fetchAll(
                'SELECT f.id, f.label, f.score, f.tiebreaker_value, f.paid_at,
                        u.name AS user_name, u.email AS user_email,
                        ABS(f.tiebreaker_value - ?) AS tiebreak_diff
                   FROM forms f
                   JOIN users u ON u.id = f.user_id
                  WHERE f.status = "submitted"' . $paidFilter . '
               ORDER BY f.score DESC,
                        (tiebreak_diff IS NULL) ASC,
                        tiebreak_diff ASC,
                        u.name',
                [(int) $correct]
            );
        }
        $counts = [
            'paid'   => (int) Database::fetchColumn('SELECT COUNT(*) FROM forms WHERE status = "submitted" AND paid_at IS NOT NULL'),
            'unpaid' => (int) Database::fetchColumn('SELECT COUNT(*) FROM forms WHERE status = "submitted" AND paid_at IS NULL'),
        ];
        $this->render('admin/leaderboard.twig', [
            'rows'             => $rows,
            'correct_tiebreak' => $correct,
            'show_all'         => $showAll,
            'counts'           => $counts,
        ]);
    }

    public function recompute(): void
    {
        $this->requireAdmin();
        $this->requireCsrf();
        ScoringService::recomputeAll();
        Session::flash('success', 'Scores recalculated.');
        $this->redirect('/admin/leaderboard');
    }

    // -------- Users --------

    public function users(): void
    {
        $this->requireAdmin();
        $users = Database::fetchAll(
            'SELECT u.*,
                    (SELECT COUNT(*) FROM forms f WHERE f.user_id = u.id) AS form_count,
                    (SELECT COUNT(*) FROM forms f WHERE f.user_id = u.id AND f.status = "submitted") AS submitted_count
               FROM users u
              ORDER BY u.is_admin DESC, u.name'
        );
        $this->render('admin/users.twig', ['users' => $users]);
    }

    public function toggleAdmin(string $id): void
    {
        $current = $this->requireAdmin();
        $this->requireCsrf();
        $target = Database::fetch('SELECT id, name, is_admin FROM users WHERE id = ?', [(int) $id]);
        if (!$target) {
            Session::flash('error', 'User not found.');
            $this->redirect('/admin/users');
        }
        if ((int) $target['id'] === (int) $current['id'] && (int) $target['is_admin'] === 1) {
            Session::flash('error', 'You cannot remove your own admin rights.');
            $this->redirect('/admin/users');
        }
        $new = (int) $target['is_admin'] === 1 ? 0 : 1;
        Database::update('users', ['is_admin' => $new], ['id' => $target['id']]);
        Session::flash('success', sprintf(
            '%s is %s admin.',
            $target['name'],
            $new ? 'now' : 'no longer'
        ));
        $this->redirect('/admin/users');
    }

    public function debugSync(): void
    {
        $this->requireAdmin();
        $this->requireCsrf();
        try {
            $provider = \App\Services\MatchSyncService::pickProvider();
            $reflClass = new \ReflectionClass($provider);
            $providerFile = $reflClass->getFileName();
            $configured = $provider->isConfigured() ? 'yes' : 'no';
            $fixtures = $provider->fixtures();
            $count = count($fixtures);
            $first = $fixtures[0] ?? null;
            $debug = sprintf(
                "Provider: <b>%s</b> (configured: %s)<br>"
                . "File: <code>%s</code><br>"
                . "Fixtures returned: <b>%d</b><br>"
                . "First normalized row: <code>%s</code>",
                $provider->name(),
                $configured,
                $providerFile,
                $count,
                htmlspecialchars(json_encode($first, JSON_UNESCAPED_UNICODE))
            );
            Session::flash('info', $debug);
        } catch (\Throwable $e) {
            Session::flash('error', 'Debug sync failed: ' . $e->getMessage());
        }
        $this->redirect('/admin/matches');
    }

    public function syncMatches(): void
    {
        $this->requireAdmin();
        $this->requireCsrf();
        $force = !empty($_POST['topscorer']);
        try {
            $svc = new MatchSyncService();
            $r = $svc->sync($force);
            $msg = sprintf(
                'Sync complete via %s: %d match(es) updated%s%s.',
                $r['provider'] ?? '?',
                $r['updated'],
                $r['finals_recomputed'] ? ', scores recalculated' : '',
                $r['topscorer'] ? ', top scorer: ' . $r['topscorer']['player'] . ' (' . $r['topscorer']['goals'] . ')' : ''
            );
            Session::flash('success', $msg);
            foreach ($r['errors'] as $e) {
                Session::flash('error', 'Sync warning: ' . $e);
            }
        } catch (\Throwable $e) {
            Session::flash('error', 'Sync failed: ' . $e->getMessage());
        }
        $this->redirect('/admin/matches');
    }
}
