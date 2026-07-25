{{-- Expects: $threads, $names, $viewerRole, $viewerId, $isTeacher (bool) --}}
@php
    $author = fn($role,$id) => $names[$role.':'.$id] ?? 'Unknown';
    $uploads = fn($p) => \App\Support\Uploads::url($p);
@endphp
@forelse ($threads as $thread)
<x-card class="mb-4 {{ $thread->is_pinned ? 'ring-2 ring-gold-400/40' : '' }}">
    <div class="flex items-start gap-3">
        <div class="w-9 h-9 rounded-full flex items-center justify-center text-xs font-bold text-white shrink-0 {{ $thread->author_role === 'teacher' ? 'bg-emerald-600' : 'bg-sky-500' }}" aria-hidden="true">
            {{ strtoupper(substr($author($thread->author_role,$thread->author_id),0,1)) }}
        </div>
        <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2 flex-wrap">
                <p class="font-bold text-slate-900 dark:text-white">{{ $author($thread->author_role, $thread->author_id) }}</p>
                @if ($thread->author_role === 'teacher')<x-badge color="emerald">Teacher</x-badge>@endif
                @if ($thread->is_pinned)<x-badge color="amber">📌 Pinned</x-badge>@endif
                <span class="text-xs text-slate-400 dark:text-slate-500">{{ $thread->created_at->diffForHumans() }}</span>
                @if ($isTeacher)
                <span class="ml-auto flex items-center gap-2">
                    <form method="POST" action="{{ route('teacher.discussions.pin', $thread->id) }}">@csrf<button class="text-xs text-slate-400 hover:text-gold-500">{{ $thread->is_pinned ? 'Unpin' : 'Pin' }}</button></form>
                    <form method="POST" action="{{ route('teacher.discussions.threads.destroy', $thread->id) }}" onsubmit="return confirm('Delete this thread?')">@csrf @method('DELETE')<button class="text-xs text-rose-400 hover:text-rose-600">Delete</button></form>
                </span>
                @endif
            </div>
            <p class="font-semibold text-slate-800 dark:text-slate-100 mt-1">{{ $thread->title }}</p>
            <p class="text-sm text-slate-700 dark:text-slate-300 mt-1 whitespace-pre-line">{{ $thread->body }}</p>
            @if ($thread->image_path)<a href="{{ $uploads($thread->image_path) }}" target="_blank"><img src="{{ $uploads($thread->image_path) }}" alt="" loading="lazy" class="mt-2 max-h-56 rounded-xl"></a>@endif

            {{-- Replies --}}
            <div class="mt-4 space-y-3 border-l-2 border-slate-100 dark:border-slate-700 pl-4">
                @foreach ($thread->replies as $reply)
                @php $liked = $reply->likes->contains(fn($l) => $l->author_role === $viewerRole && (int)$l->author_id === (int)$viewerId); @endphp
                <div class="flex items-start gap-2.5">
                    <div class="w-7 h-7 rounded-full flex items-center justify-center text-[10px] font-bold text-white shrink-0 {{ $reply->author_role === 'teacher' ? 'bg-emerald-600' : 'bg-sky-500' }}" aria-hidden="true">{{ strtoupper(substr($author($reply->author_role,$reply->author_id),0,1)) }}</div>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm"><span class="font-bold text-slate-800 dark:text-slate-100">{{ $author($reply->author_role,$reply->author_id) }}</span>@if($reply->author_role==='teacher')<span class="text-[10px] font-bold text-emerald-600 dark:text-emerald-400 ml-1">TEACHER</span>@endif <span class="text-xs text-slate-400 dark:text-slate-500 ml-1">{{ $reply->created_at->diffForHumans() }}</span></p>
                        <p class="text-sm text-slate-700 dark:text-slate-300 whitespace-pre-line">{{ $reply->body }}</p>
                        @if ($reply->image_path)<a href="{{ $uploads($reply->image_path) }}" target="_blank"><img src="{{ $uploads($reply->image_path) }}" alt="" loading="lazy" class="mt-1.5 max-h-44 rounded-lg"></a>@endif
                        <div class="flex items-center gap-3 mt-1">
                            <form method="POST" action="{{ $isTeacher ? route('teacher.discussions.like', $reply->id) : route('student.discussions.like', $reply->id) }}">@csrf
                                <button class="inline-flex items-center gap-1 text-xs font-semibold {{ $liked ? 'text-rose-500' : 'text-slate-400 hover:text-rose-500' }}">
                                    {{ $liked ? '❤' : '♡' }} <span>{{ $reply->likes->count() ?: '' }}</span> Like
                                </button>
                            </form>
                            @if ($isTeacher)
                            <form method="POST" action="{{ route('teacher.discussions.replies.destroy', $reply->id) }}" onsubmit="return confirm('Delete this reply?')">@csrf @method('DELETE')<button class="text-xs text-slate-400 hover:text-rose-500">Delete</button></form>
                            @endif
                        </div>
                    </div>
                </div>
                @endforeach

                {{-- Reply form --}}
                <form method="POST" action="{{ $isTeacher ? route('teacher.discussions.reply', $thread->id) : route('student.discussions.reply', $thread->id) }}" enctype="multipart/form-data" class="flex items-center gap-2 pt-1">
                    @csrf
                    <input type="text" name="body" required placeholder="Write a reply…" class="flex-1 rounded-xl border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 px-3 py-2 text-sm">
                    <label class="cursor-pointer text-slate-400 hover:text-emerald-600" title="Attach image">📷<input type="file" name="image" accept="image/*" class="hidden"></label>
                    <button class="rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold px-3 py-2">Reply</button>
                </form>
            </div>
        </div>
    </div>
</x-card>
@empty
    <x-empty-state icon="💬" title="No discussions yet" message="Start the first thread below — ask a question or share an idea." />
@endforelse
