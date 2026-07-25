<?php

declare(strict_types=1);

namespace App\Http\Controllers\Student;

use App\Models\Classroom\Announcement;
use Illuminate\Http\Request;

class AnnouncementController extends StudentLmsController
{
    public function classIndex(Request $request, int $classId)
    {
        $sid = $this->studentInfoId($request);
        $class = $this->enrolledClass($sid, $classId);
        [$profile, $photoUrl] = $this->profile($request, $this->portal);
        $announcements = Announcement::with('attachments')->where('class_id', $classId)->orderByDesc('id')->get();

        return view('classroom.announcements', compact('class', 'announcements', 'profile', 'photoUrl'));
    }
}
