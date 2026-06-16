<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Setting;

final class HomeController extends Controller
{
    public function index(): void
    {
        if (Auth::check()) {
            $this->redirect('/dashboard');
        }
        $this->render('home/index.twig');
    }

    public function leaderboardForm(string $id): void
    {
        $form = Database::fetch(
            'SELECT id, status, paid_at FROM forms WHERE id = ?',
            [(int) $id]
        );
        if (!$form || $form['status'] !== 'submitted' || empty($form['paid_at'])) {
            // Only show breakdowns for paid submitted entries — same data the
            // public leaderboard already exposes.
            http_response_code(404);
            echo \App\Core\View::render('errors/404.twig');
            return;
        }
        $data = \App\Services\ScoreBreakdownService::forForm((int) $id);
        $me = \App\Core\Auth::user();
        $this->render('home/leaderboard_form.twig', [
            'data'       => $data,
            'my_user_id' => $me ? (int) $me['id'] : 0,
        ]);
    }

    public function leaderboard(): void
    {
        $correct = Setting::get('tiebreaker_correct_value', '');
        if ($correct === '' || $correct === null) {
            $rows = Database::fetchAll(
                'SELECT f.id, f.user_id, f.label, f.score,
                        u.name AS user_name,
                        winner.name AS winner_team,
                        scorer.name AS topscorer_name
                   FROM forms f
                   JOIN users u ON u.id = f.user_id
              LEFT JOIN teams   winner ON winner.id = f.winner_team_id
              LEFT JOIN players scorer ON scorer.id = f.topscorer_player_id
                  WHERE f.status = "submitted" AND f.paid_at IS NOT NULL
               ORDER BY f.score DESC, u.name'
            );
        } else {
            $rows = Database::fetchAll(
                'SELECT f.id, f.user_id, f.label, f.score,
                        u.name AS user_name,
                        winner.name AS winner_team,
                        scorer.name AS topscorer_name,
                        ABS(f.tiebreaker_value - ?) AS tiebreak_diff
                   FROM forms f
                   JOIN users u ON u.id = f.user_id
              LEFT JOIN teams   winner ON winner.id = f.winner_team_id
              LEFT JOIN players scorer ON scorer.id = f.topscorer_player_id
                  WHERE f.status = "submitted" AND f.paid_at IS NOT NULL
               ORDER BY f.score DESC,
                        (tiebreak_diff IS NULL) ASC,
                        tiebreak_diff ASC,
                        u.name',
                [(int) $correct]
            );
        }
        $rank = 0;
        foreach ($rows as &$r) { $r['rank'] = ++$rank; }
        unset($r);

        $me = Auth::user();
        $this->render('home/leaderboard.twig', [
            'rows'             => $rows,
            'correct_tiebreak' => $correct,
            'my_user_id'       => $me ? (int) $me['id'] : 0,
        ]);
    }
}
