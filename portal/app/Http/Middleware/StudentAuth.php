<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Portal;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guard for every authenticated portal page. Mirrors require_student_login():
 *  - a session must exist,
 *  - the account must still be Active (re-checked live),
 *  - a pending password change forces the change-password page.
 */
class StudentAuth
{
    public function __construct(private Portal $portal) {}

    public function handle(Request $request, Closure $next, ?string $mode = null): Response
    {
        $sess = $request->session()->get('student');
        if (!$sess) {
            $request->session()->flash('error', 'Please log in to access the student portal.');
            return redirect()->route('login');
        }

        $account = $this->portal->getAccount((int) $sess['enrollment_id']);
        if (!$account || ($account->status ?? '') !== 'Active') {
            $request->session()->forget('student');
            $request->session()->flash('error', 'Your session is no longer valid. Please log in again.');
            return redirect()->route('login');
        }

        // Force the default password to be replaced first, except on the change page.
        if ($mode !== 'allow-pw-change'
            && (int) $account->must_change_password === 1
            && !$request->routeIs('password.*')) {
            return redirect()->route('password.change');
        }

        $request->attributes->set('student', $sess);
        $request->attributes->set('account', $account);

        return $next($request);
    }
}
