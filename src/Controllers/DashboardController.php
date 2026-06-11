<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;

final class DashboardController extends Controller
{
    public function index(): void
    {
        $user = $this->requireAuth();
        $forms = Database::fetchAll(
            'SELECT * FROM forms WHERE user_id = ? ORDER BY created_at DESC',
            [(int) $user['id']]
        );

        // Public leaderboard — paid entries only, sorted by score (and tiebreaker if known).
        $correct = \App\Core\Setting::get('tiebreaker_correct_value', '');
        if ($correct === '' || $correct === null) {
            $leaderboard = \App\Core\Database::fetchAll(
                'SELECT f.id, f.user_id, f.label, f.score,
                        u.name AS user_name
                   FROM forms f
                   JOIN users u ON u.id = f.user_id
                  WHERE f.status = "submitted" AND f.paid_at IS NOT NULL
               ORDER BY f.score DESC, u.name'
            );
        } else {
            $leaderboard = \App\Core\Database::fetchAll(
                'SELECT f.id, f.user_id, f.label, f.score,
                        u.name AS user_name,
                        ABS(f.tiebreaker_value - ?) AS tiebreak_diff
                   FROM forms f
                   JOIN users u ON u.id = f.user_id
                  WHERE f.status = "submitted" AND f.paid_at IS NOT NULL
               ORDER BY f.score DESC,
                        (tiebreak_diff IS NULL) ASC,
                        tiebreak_diff ASC,
                        u.name',
                [(int) $correct]
            );
        }
        // Add rank index
        $rank = 0;
        foreach ($leaderboard as &$r) $r['rank'] = ++$rank;
        unset($r);

        $this->render('dashboard/index.twig', [
            'forms'           => $forms,
            'leaderboard'     => $leaderboard,
            'user_id'         => (int) $user['id'],
            'deadline_passed' => \App\Controllers\PredictionController::predictionsClosed(),
        ]);
    }
}
