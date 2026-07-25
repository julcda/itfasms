@if ($a->attachments->isNotEmpty())
<div class="flex flex-wrap gap-2 mt-2">
    @foreach ($a->attachments as $att)
        @if ($att->type === 'link')
            <a href="{{ $att->url }}" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 text-xs font-semibold rounded-lg bg-sky-50 dark:bg-sky-500/15 text-sky-700 dark:text-sky-400 px-2.5 py-1.5">🔗 {{ \Illuminate\Support\Str::limit($att->url, 40) }}</a>
        @elseif ($att->type === 'image')
            <a href="{{ \App\Support\Uploads::url($att->file_path) }}" target="_blank" class="block"><img src="{{ \App\Support\Uploads::url($att->file_path) }}" alt="{{ $att->file_name }}" loading="lazy" class="h-24 rounded-lg object-cover"></a>
        @else
            <a href="{{ \App\Support\Uploads::url($att->file_path) }}" target="_blank" class="inline-flex items-center gap-1.5 text-xs font-semibold rounded-lg bg-slate-100 dark:bg-slate-700 text-slate-700 dark:text-slate-200 px-2.5 py-1.5">📎 {{ $att->file_name }}</a>
        @endif
    @endforeach
</div>
@endif
