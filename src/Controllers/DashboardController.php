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
        $this->render('dashboard/index.twig', ['forms' => $forms]);
    }
}
