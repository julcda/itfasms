<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AdminService;
use Illuminate\Http\Request;

class MonitoringController extends Controller
{
    public function __construct(private AdminService $admin) {}

    public function index(Request $request)
    {
        return view('admin.monitoring', [
            'log'      => $this->admin->loginLog($request->only(['search', 'event'])),
            'filters'  => $request->only(['search', 'event']),
            'adminLog' => $this->admin->adminLog(40),
        ]);
    }
}
