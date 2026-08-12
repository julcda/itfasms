<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AdminService;

class DashboardController extends Controller
{
    public function __construct(private AdminService $admin) {}

    public function index()
    {
        return view('admin.dashboard', [
            'stats'    => $this->admin->stats(),
            'trend'    => $this->admin->loginTrend(14),
            'recent'   => $this->admin->loginLog([], 8),
            'adminLog' => $this->admin->adminLog(8),
        ]);
    }
}
