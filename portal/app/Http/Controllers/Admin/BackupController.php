<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AdminService;
use Illuminate\Http\Request;

class BackupController extends Controller
{
    public function __construct(private AdminService $admin) {}

    public function index()
    {
        return view('admin.backups', [
            'backups' => $this->admin->backups(),
            'system'  => $this->admin->systemInfo(),
        ]);
    }

    public function run(Request $request)
    {
        $res = $this->admin->runBackup();
        if (!$res['ok']) {
            return back()->with('error', $res['error']);
        }
        $this->admin->logAdmin($request->attributes->get('admin'), 'backup-created', $res['file']);
        return back()->with('success', "Database backup created: {$res['file']}");
    }

    public function download(string $name)
    {
        $path = $this->admin->backupPath($name);
        abort_unless($path, 404);
        return response()->download($path);
    }

    public function destroy(Request $request, string $name)
    {
        if ($this->admin->deleteBackup($name)) {
            $this->admin->logAdmin($request->attributes->get('admin'), 'backup-deleted', $name);
            return back()->with('success', "Deleted backup: {$name}");
        }
        return back()->with('error', 'Could not delete that backup.');
    }
}
