<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AdminService;
use App\Services\Portal;
use Illuminate\Http\Request;

class StudentAccountController extends Controller
{
    public function __construct(private AdminService $admin) {}

    public function index(Request $request)
    {
        $filters = $request->only(['search', 'status', 'grade', 'section', 'login', 'sort']);

        return view('admin.students', [
            'accounts' => $this->admin->accounts($filters),
            'options'  => $this->admin->filterOptions(),
            'filters'  => $filters,
        ]);
    }

    public function resetPassword(Request $request, int $id)
    {
        $account = $this->admin->findAccount($id);
        if (!$account) {
            return back()->with('error', 'Account not found.');
        }

        $this->admin->resetPassword($id);
        $this->admin->logAdmin($request->attributes->get('admin'), 'reset-password',
            $account->lrn, ($account->name ?: 'Student') . ' — reset to default');

        return back()->with('success', "Password for {$account->name} (LRN {$account->lrn}) was reset to \"" . Portal::STUDENT_DEFAULT_PW . '". They will be asked to change it on next login.');
    }

    public function toggleStatus(Request $request, int $id)
    {
        $account = $this->admin->findAccount($id);
        if (!$account) {
            return back()->with('error', 'Account not found.');
        }

        $new = ($account->status === 'Active') ? 'Inactive' : 'Active';
        $this->admin->setStatus($id, $new);
        $this->admin->logAdmin($request->attributes->get('admin'), 'set-status',
            $account->lrn, ($account->name ?: 'Student') . " → {$new}");

        return back()->with('success', "{$account->name}'s portal access is now {$new}.");
    }

    public function bulkReset(Request $request)
    {
        $data = $request->validate([
            'grade'   => ['nullable', 'string', 'max:80'],
            'section' => ['nullable', 'string', 'max:80'],
            'confirm' => ['required', 'in:RESET'],
        ]);

        $grade   = $data['grade'] ?: null;
        $section = $data['section'] ?: null;
        if (!$grade && !$section) {
            return back()->with('error', 'Choose at least a grade or a section for a bulk reset — this is a safety guard.');
        }

        $count = $this->admin->bulkReset($grade, $section);
        $scope = trim(($grade ?: 'All grades') . ($section ? " · {$section}" : ''));
        $this->admin->logAdmin($request->attributes->get('admin'), 'bulk-reset-password',
            $scope, "{$count} account(s) reset to default");

        return back()->with('success', "{$count} account(s) under [{$scope}] were reset to the default password.");
    }
}
