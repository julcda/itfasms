<?php

declare(strict_types=1);

namespace App\Http\Controllers\Teacher;

use App\Models\Classroom\Announcement;
use App\Support\Uploads;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;

class AnnouncementController extends TeacherController
{
    public function index(Request $request, int $classId)
    {
        $class = $this->ownedClass($request, $classId);
        $announcements = Announcement::with('attachments')->where('class_id', $classId)->orderByDesc('id')->get();
        return view('teacher.announcements.index', compact('class', 'announcements'));
    }

    public function store(Request $request, int $classId)
    {
        $class = $this->ownedClass($request, $classId);
        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'body'  => ['required', 'string', 'max:5000'],
            'link'  => ['nullable', 'url', 'max:500'],
        ]);

        $announcement = Announcement::create([
            'class_id' => $classId, 'title' => $data['title'] ?? null, 'body' => $data['body'],
            'created_by' => $this->teacher($request)->user_id,
        ]);

        if (!empty($data['link'])) {
            $announcement->attachments()->create(['type' => 'link', 'url' => $data['link']]);
        }
        foreach ((array) $request->file('attachments', []) as $file) {
            if (!$file) { continue; }
            try {
                $meta = Uploads::store($file, 'classroom_announcements', array_merge(Uploads::DOC_EXT, Uploads::IMAGE_EXT), 15 * 1024 * 1024);
                $isImg = in_array(strtolower((string) $file->getClientOriginalExtension()), Uploads::IMAGE_EXT, true);
                $announcement->attachments()->create(['type' => $isImg ? 'image' : 'file', 'file_path' => $meta['file_path'], 'file_name' => $meta['file_name']]);
            } catch (\RuntimeException $e) {
                throw new HttpResponseException(back()->with('error', $e->getMessage()));
            }
        }

        $this->notifyClass($classId, 'announcement', $announcement->title ?: 'New announcement', mb_strimwidth($announcement->body, 0, 120, '…'), route('student.classes.announcements', $classId), ['announcement_id' => $announcement->id]);

        return back()->with('success', 'Announcement posted.');
    }

    public function destroy(Request $request, int $id)
    {
        $a = Announcement::findOrFail($id);
        $this->ownedClass($request, (int) $a->class_id);
        foreach ($a->attachments as $att) { Uploads::delete($att->file_path); }
        $a->delete();
        return back()->with('success', 'Announcement deleted.');
    }
}
