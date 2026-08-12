<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guard for the Super Admin maintenance console (/admin). A separate session
 * key from students/teachers, so an admin session never collides with a
 * student one in the same browser.
 */
class AdminAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $admin = $request->session()->get('admin');
        if (!$admin || empty($admin['id'])) {
            $request->session()->flash('error', 'Please sign in to the maintenance console.');
            return redirect()->route('admin.login');
        }

        $request->attributes->set('admin', $admin);
        return $next($request);
    }
}
