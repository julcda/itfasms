<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Legacy\TeacherModel;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guard for every Teacher-side Classroom page. There is deliberately NO
 * Laravel login screen for teachers — access is only ever reached via the
 * native teacher module's SSO handoff (teacher/classroom.php). If the
 * session here is missing or the teacher record is no longer valid, we send
 * them back to the native launch page, which transparently re-mints a ticket
 * as long as their NATIVE session is still alive — no password re-entry.
 */
class TeacherAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $sess = $request->session()->get('teacher');
        if (!$sess) {
            return $this->bounceToNative($request);
        }

        $teacher = TeacherModel::find((int) $sess['teacher_id']);
        if (!$teacher || (string) $teacher->status !== 'Active') {
            $request->session()->forget('teacher');
            return $this->bounceToNative($request);
        }

        $request->attributes->set('teacher', $teacher);

        return $next($request);
    }

    private function bounceToNative(Request $request): Response
    {
        $base = rtrim((string) config('portal.app_base_url'), '/');
        $to   = urlencode($request->path() === '/' ? '/teacher' : '/' . ltrim($request->path(), '/'));
        return redirect()->away($base . '/teacher/classroom.php?to=' . $to);
    }
}
