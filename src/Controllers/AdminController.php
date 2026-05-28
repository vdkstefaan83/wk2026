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
        ];
        foreach ($fields as $f) {
            if (array_key_exists($f, $_POST)) {
                Setting::set($f, (string)$_POST[$f]);
            }
        }
        Session::flash('success', 'Instellingen bewaard.');
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
        if (!$tpl) { Session::flash('error', 'Template niet gevonden.'); $this->redirect('/admin/email-templates'); }
        $this->render('admin/email_template_edit.twig', ['template' => $tpl]);
    }

    public function saveEmailTemplate(string $key): void
    {
        $this->requireAdmin();
        $this->requireCsrf();
        $subject = (string) ($_POST['subject'] ?? '');
        $body    = (string) ($_POST['body_html'] ?? '');
        $tpl = Database::fetch('SELECT id FROM email_templates WHERE `key` = ?', [$key]);
        if (!$tpl) { Session::flash('error', 'Template niet gevonden.'); $this->redirect('/admin/email-templates'); }
        Database::update('email_templates', [
            'subject'    => $subject,
            'body_html'  => $body,
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['id' => $tpl['id']]);
        Session::flash('success', 'Template bewaard.');
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
        Session::flash('success', 'Teams bewaard.');
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
                    'actual_home_goals' => isset($row['actual_home_goals']) && $row['actual_home_goals'] !== '' ? (int)$row['actual_home_goals'] : null,
                    'actual_away_goals' => isset($row['actual_away_goals']) && $row['actual_away_goals'] !== '' ? (int)$row['actual_away_goals'] : null,
                ];
                Database::update('matches', $patch, ['id' => (int)$id]);
            }
        }
        Session::flash('success', 'Wedstrijden bewaard.');
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
        Session::flash('success', 'Spelers bewaard.');
        $this->redirect('/admin/players');
    }

    // -------- Forms / payment tracking --------

    public function forms(): void
    {
        $this->requireAdmin();
        $forms = Database::fetchAll(
            'SELECT f.*, u.name AS user_name, u.email AS user_email
               FROM forms f
               JOIN users u ON u.id = f.user_id
              ORDER BY f.created_at DESC'
        );
        $this->render('admin/forms.twig', compact('forms'));
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
        Session::flash('success', 'Betaalstatus bijgewerkt.');
        $this->redirect('/admin/forms');
    }

    public function leaderboard(): void
    {
        $this->requireAdmin();
        $rows = Database::fetchAll(
            'SELECT f.id, f.label, f.score, f.paid_at, u.name AS user_name, u.email AS user_email
               FROM forms f
               JOIN users u ON u.id = f.user_id
              WHERE f.status = "submitted"
              ORDER BY f.score DESC, u.name'
        );
        $this->render('admin/leaderboard.twig', ['rows' => $rows]);
    }

    public function recompute(): void
    {
        $this->requireAdmin();
        $this->requireCsrf();
        ScoringService::recomputeAll();
        Session::flash('success', 'Scores opnieuw berekend.');
        $this->redirect('/admin/leaderboard');
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
                'Sync klaar: %d wedstrijd(en) bijgewerkt%s%s.',
                $r['updated'],
                $r['finals_recomputed'] ? ', scores herberekend' : '',
                $r['topscorer'] ? ', topscorer: ' . $r['topscorer']['player'] . ' (' . $r['topscorer']['goals'] . ')' : ''
            );
            Session::flash('success', $msg);
            foreach ($r['errors'] as $e) {
                Session::flash('error', 'Sync waarschuwing: ' . $e);
            }
        } catch (\Throwable $e) {
            Session::flash('error', 'Sync mislukt: ' . $e->getMessage());
        }
        $this->redirect('/admin/matches');
    }
}
