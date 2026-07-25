<?php

declare(strict_types=1);

namespace App\Http\Controllers\Teacher;

use App\Models\Classroom\NotificationRead;
use Illuminate\Http\Request;

class NotificationController extends TeacherController
{
    public function index(Request $request)
    {
        $teacher = $this->teacher($request);
        $rows = NotificationRead::with('notification')
            ->where('recipient_role', 'teacher')
            ->where('recipient_id', $teacher->Teacher_id)
            ->orderByDesc('id')
            ->limit(30)
            ->get();

        return response()->json([
            'unread' => $rows->whereNull('read_at')->count(),
            'items'  => $rows->map(fn (NotificationRead $r) => [
                'id'      => $r->id,
                'title'   => $r->notification->title,
                'body'    => $r->notification->body,
                'link'    => $r->notification->link,
                'time'    => $r->notification->created_at?->diffForHumans(),
                'read'    => $r->read_at !== null,
            ]),
        ]);
    }

    public function markRead(Request $request)
    {
        $teacher = $this->teacher($request);
        NotificationRead::where('recipient_role', 'teacher')
            ->where('recipient_id', $teacher->Teacher_id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['ok' => true]);
    }
}
