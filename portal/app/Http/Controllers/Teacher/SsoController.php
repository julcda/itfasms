<?php

declare(strict_types=1);

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Models\Classroom\SsoTicket;
use App\Models\Legacy\TeacherModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SsoController extends Controller
{
    /**
     * Consume a one-time ticket minted by teacher/classroom.php and establish
     * a Teacher session. The ticket row (not a signature) IS the credential —
     * random, single-use, 60-second-lived — the same trust model as a
     * password-reset token.
     */
    public function consume(Request $request, string $ticket)
    {
        $ticket = DB::transaction(function () use ($ticket) {
            $row = SsoTicket::where('ticket', $ticket)
                ->whereNull('used_at')
                ->where('expires_at', '>', now())
                ->lockForUpdate()
                ->first();
            if ($row) {
                $row->update(['used_at' => now()]);
            }
            return $row;
        });

        if (!$ticket) {
            return view('teacher.sso_expired');
        }

        $teacher = TeacherModel::find($ticket->teacher_id);
        if (!$teacher || (string) $teacher->status !== 'Active') {
            return view('teacher.sso_expired');
        }

        $request->session()->regenerate();
        $request->session()->put('teacher', [
            'teacher_id' => (int) $teacher->Teacher_id,
            'user_id'    => (int) $teacher->user_id,
            'name'       => $teacher->displayName(),
        ]);

        $target = $ticket->redirect_path ?: '/teacher';
        if (!str_starts_with($target, '/') || str_starts_with($target, '//')) {
            $target = '/teacher'; // never an open redirect
        }

        return redirect($target);
    }
}
