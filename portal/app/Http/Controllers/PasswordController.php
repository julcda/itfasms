<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Portal;
use Illuminate\Http\Request;

class PasswordController extends Controller
{
    public function __construct(private Portal $portal) {}

    public function show(Request $request)
    {
        $account = $this->portal->getAccount((int) $request->session()->get('student')['enrollment_id']);
        return view('auth.password', ['forced' => (int) ($account->must_change_password ?? 0) === 1]);
    }

    public function update(Request $request)
    {
        $student = $request->session()->get('student');
        $new     = (string) $request->input('new_password', '');
        $confirm = (string) $request->input('confirm_password', '');

        if (strlen($new) < 6) {
            return back()->with('error', 'Password must be at least 6 characters.');
        }
        if ($new !== $confirm) {
            return back()->with('error', 'The passwords do not match.');
        }
        if (strtolower($new) === strtolower(Portal::STUDENT_DEFAULT_PW)) {
            return back()->with('error', 'Please choose a password other than the default.');
        }

        $this->portal->updatePassword((int) $student['account_id'], $new);
        $student['must_change'] = false;
        $request->session()->put('student', $student);

        return redirect()->route('dashboard')->with('success', 'Your password has been updated.');
    }
}
