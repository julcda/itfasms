<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AdminService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

class MaintenanceController extends Controller
{
    public function __construct(private AdminService $admin) {}

    public function index()
    {
        return view('admin.maintenance', [
            'system' => $this->admin->systemInfo(),
        ]);
    }

    public function clearCache(Request $request)
    {
        $data = $request->validate(['what' => ['required', 'in:views,config,all']]);
        $done = [];

        try {
            if (in_array($data['what'], ['views', 'all'], true)) {
                Artisan::call('view:clear');
                $done[] = 'compiled views';
            }
            if (in_array($data['what'], ['config', 'all'], true)) {
                Artisan::call('config:clear');
                $done[] = 'config cache';
            }
        } catch (\Throwable $e) {
            return back()->with('error', 'Could not clear cache: ' . $e->getMessage());
        }

        $this->admin->logAdmin($request->attributes->get('admin'), 'clear-cache', implode(', ', $done));
        return back()->with('success', 'Cleared: ' . implode(', ', $done) . '.');
    }
}
