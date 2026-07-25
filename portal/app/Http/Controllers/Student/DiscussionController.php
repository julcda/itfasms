<?php

declare(strict_types=1);

namespace App\Http\Controllers\Student;

use App\Models\Classroom\DiscussionLike;
use App\Models\Classroom\DiscussionReply;
use App\Models\Classroom\DiscussionThread;
use App\Support\ClassroomNames;
use App\Support\Uploads;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;

class DiscussionController extends StudentLmsController
{
    public function index(Request $request, int $classId)
    {
        $sid = $this->studentInfoId($request);
        $class = $this->enrolledClass($sid, $classId);
        [$profile, $photoUrl] = $this->profile($request, $this->portal);

        $threads = DiscussionThread::with(['replies' => fn ($q) => $q->orderBy('id'), 'replies.likes'])
            ->withCount('replies')
            ->where('class_id', $classId)
            ->orderByDesc('is_pinned')->orderByDesc('id')->get();
        $names = ClassroomNames::resolve($threads);

        return view('classroom.discussion', [
            'class' => $class, 'threads' => $threads, 'names' => $names, 'profile' => $profile, 'photoUrl' => $photoUrl,
            'viewerRole' => 'student', 'viewerId' => $sid,
        ]);
    }

    public function storeThread(Request $request, int $classId)
    {
        $sid = $this->studentInfoId($request);
        $this->enrolledClass($sid, $classId);
        $data = $request->validate(['title' => ['required', 'string', 'max:255'], 'body' => ['required', 'string', 'max:5000']]);
        DiscussionThread::create([
            'class_id' => $classId, 'title' => $data['title'], 'body' => $data['body'],
            'image_path' => $this->image($request), 'author_role' => 'student', 'author_id' => $sid,
        ]);
        return back()->with('success', 'Question posted.');
    }

    public function reply(Request $request, int $threadId)
    {
        $sid = $this->studentInfoId($request);
        $thread = DiscussionThread::findOrFail($threadId);
        $this->enrolledClass($sid, (int) $thread->class_id);
        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);
        $thread->replies()->create([
            'body' => $data['body'], 'image_path' => $this->image($request), 'author_role' => 'student', 'author_id' => $sid,
        ]);
        return back()->with('success', 'Reply posted.');
    }

    public function like(Request $request, int $replyId)
    {
        $sid = $this->studentInfoId($request);
        $reply = DiscussionReply::with('thread')->findOrFail($replyId);
        $this->enrolledClass($sid, (int) $reply->thread->class_id);
        $existing = DiscussionLike::where('reply_id', $replyId)->where('author_role', 'student')->where('author_id', $sid)->first();
        if ($existing) { $existing->delete(); } else { DiscussionLike::create(['reply_id' => $replyId, 'author_role' => 'student', 'author_id' => $sid]); }
        return back();
    }

    private function image(Request $request): ?string
    {
        $file = $request->file('image');
        if (!$file) { return null; }
        try {
            return Uploads::store($file, 'classroom_discussion', Uploads::IMAGE_EXT, 10 * 1024 * 1024)['file_path'];
        } catch (\RuntimeException $e) {
            throw new HttpResponseException(back()->with('error', $e->getMessage()));
        }
    }
}
