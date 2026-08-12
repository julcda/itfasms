<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AdminService;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(private AdminService $admin) {}

    public function show(Request $request)
    {
        if ($request->session()->has('admin')) {
            return redirect()->route('admin.dashboard');
        }
        return view('admin.login');
    }

    public function login(Request $request)
    {
        $res = $this->admin->authenticate(
            (string) $request->input('username', ''),
            (string) $request->input('password', '')
        );

        if (!$res['ok']) {
            return back()->with('error', $res['error'])->withInput($request->only('username'));
        }

        $request->session()->regenerate();
        $request->session()->put('admin', $res['admin']);
        $this->admin->logAdmin($res['admin'], 'sign-in', null, 'Maintenance console login');

        return redirect()->route('admin.dashboard');
    }

    public function logout(Request $request)
    {
        if ($admin = $request->session()->get('admin')) {
            $this->admin->logAdmin($admin, 'sign-out');
        }
        $request->session()->forget('admin');
        $request->session()->regenerateToken();
        return redirect()->route('admin.login')->with('success', 'Signed out of the maintenance console.');
    }
}
