<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Portal;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(private Portal $portal) {}

    public function show(Request $request)
    {
        if ($request->session()->has('student')) {
            return redirect()->route('dashboard');
        }
        return view('auth.login');
    }

    public function login(Request $request)
    {
        $res = $this->portal->attemptLogin(
            (string) $request->input('lrn', ''),
            (string) $request->input('password', '')
        );

        if (!$res['ok']) {
            return back()->with('error', $res['error'])->withInput($request->only('lrn'));
        }

        $request->session()->regenerate();
        $request->session()->put('student', $res['student']);

        return $res['student']['must_change']
            ? redirect()->route('password.change')
            : redirect()->route('dashboard');
    }

    public function logout(Request $request)
    {
        if ($s = $request->session()->get('student')) {
            app(\App\Services\AdminService::class)->recordLogin(
                'logout', (int) ($s['account_id'] ?? 0) ?: null,
                (int) ($s['enrollment_id'] ?? 0) ?: null, (string) ($s['lrn'] ?? '') ?: null
            );
        }
        $request->session()->forget('student');
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('login')->with('success', 'You have been logged out.');
    }
}
