<?php

declare(strict_types=1);

namespace App\Http\Controllers\Teacher;

use App\Models\Classroom\DiscussionLike;
use App\Models\Classroom\DiscussionReply;
use App\Models\Classroom\DiscussionThread;
use App\Support\ClassroomNames;
use App\Support\Uploads;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;

class DiscussionController extends TeacherController
{
    public function index(Request $request, int $classId)
    {
        $class = $this->ownedClass($request, $classId);
        $threads = DiscussionThread::with(['replies' => fn ($q) => $q->orderBy('id'), 'replies.likes'])
            ->withCount('replies')
            ->where('class_id', $classId)
            ->orderByDesc('is_pinned')->orderByDesc('id')->get();
        $names = ClassroomNames::resolve($threads);

        return view('teacher.discussion', [
            'class' => $class, 'threads' => $threads, 'names' => $names,
            'viewerRole' => 'teacher', 'viewerId' => (int) $this->teacher($request)->Teacher_id,
        ]);
    }

    public function storeThread(Request $request, int $classId)
    {
        $class = $this->ownedClass($request, $classId);
        $data = $request->validate(['title' => ['required', 'string', 'max:255'], 'body' => ['required', 'string', 'max:5000']]);
        DiscussionThread::create([
            'class_id' => $classId, 'title' => $data['title'], 'body' => $data['body'],
            'image_path' => $this->image($request), 'author_role' => 'teacher',
            'author_id' => $this->teacher($request)->Teacher_id,
        ]);
        return back()->with('success', 'Discussion posted.');
    }

    public function reply(Request $request, int $threadId)
    {
        $thread = DiscussionThread::findOrFail($threadId);
        $this->ownedClass($request, (int) $thread->class_id);
        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);
        $thread->replies()->create([
            'body' => $data['body'], 'image_path' => $this->image($request),
            'author_role' => 'teacher', 'author_id' => $this->teacher($request)->Teacher_id,
        ]);
        return back()->with('success', 'Reply posted.');
    }

    public function togglePin(Request $request, int $threadId)
    {
        $thread = DiscussionThread::findOrFail($threadId);
        $this->ownedClass($request, (int) $thread->class_id);
        $thread->update(['is_pinned' => !$thread->is_pinned]);
        return back()->with('success', $thread->is_pinned ? 'Pinned.' : 'Unpinned.');
    }

    public function destroyThread(Request $request, int $threadId)
    {
        $thread = DiscussionThread::findOrFail($threadId);
        $this->ownedClass($request, (int) $thread->class_id);
        Uploads::delete($thread->image_path);
        foreach ($thread->replies as $r) { Uploads::delete($r->image_path); }
        $thread->delete();
        return back()->with('success', 'Thread deleted.');
    }

    public function destroyReply(Request $request, int $replyId)
    {
        $reply = DiscussionReply::with('thread')->findOrFail($replyId);
        $this->ownedClass($request, (int) $reply->thread->class_id);
        Uploads::delete($reply->image_path);
        $reply->delete();
        return back()->with('success', 'Reply deleted.');
    }

    public function like(Request $request, int $replyId)
    {
        $reply = DiscussionReply::with('thread')->findOrFail($replyId);
        $this->ownedClass($request, (int) $reply->thread->class_id);
        $this->toggleLike($replyId, 'teacher', (int) $this->teacher($request)->Teacher_id);
        return back();
    }

    private function toggleLike(int $replyId, string $role, int $id): void
    {
        $existing = DiscussionLike::where('reply_id', $replyId)->where('author_role', $role)->where('author_id', $id)->first();
        if ($existing) { $existing->delete(); } else { DiscussionLike::create(['reply_id' => $replyId, 'author_role' => $role, 'author_id' => $id]); }
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
