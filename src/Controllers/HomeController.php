<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;

final class HomeController extends Controller
{
    public function index(): void
    {
        if (Auth::check()) {
            $this->redirect('/dashboard');
        }
        $this->render('home/index.twig');
    }
}
