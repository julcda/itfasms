<?php

declare(strict_types=1);

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\Classroom\NotificationRead;
use App\Services\Portal;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function __construct(private Portal $portal) {}

    public function index(Request $request)
    {
        $sy = $this->portal->activeSy();
        [$profile] = $this->profile($request, $this->portal);
        $studentInfoId = $this->portal->studentInfoIdByLrn((string) $profile->lrn, $sy['id']);

        $rows = NotificationRead::with('notification')
            ->where('recipient_role', 'student')
            ->where('recipient_id', $studentInfoId)
            ->orderByDesc('id')
            ->limit(30)
            ->get();

        return response()->json([
            'unread' => $rows->whereNull('read_at')->count(),
            'items'  => $rows->map(fn (NotificationRead $r) => [
                'id'    => $r->id,
                'title' => $r->notification->title,
                'body'  => $r->notification->body,
                'link'  => $r->notification->link,
                'time'  => $r->notification->created_at?->diffForHumans(),
                'read'  => $r->read_at !== null,
            ]),
        ]);
    }

    public function markRead(Request $request)
    {
        $sy = $this->portal->activeSy();
        [$profile] = $this->profile($request, $this->portal);
        $studentInfoId = $this->portal->studentInfoIdByLrn((string) $profile->lrn, $sy['id']);

        NotificationRead::where('recipient_role', 'student')
            ->where('recipient_id', $studentInfoId)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['ok' => true]);
    }
}
