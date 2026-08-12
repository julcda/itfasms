@extends('admin.layout')
@section('title', 'Backups')
@section('heading', 'Database Backups')
@section('subheading', 'Create and download full backups of the portal database')

@section('content')
@php
    $card = 'rounded-2xl border border-slate-200/70 dark:border-slate-700/60 bg-white/80 dark:bg-slate-800/50 shadow-panel';
    $fmt = fn ($b) => $b >= 1073741824 ? number_format($b/1073741824, 2).' GB' : ($b >= 1048576 ? number_format($b/1048576, 1).' MB' : number_format(max(1,$b)/1024, 0).' KB');
@endphp

<div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-5">
    <div class="{{ $card }} p-5 lg:col-span-2">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div>
                <h2 class="font-bold text-lg">Create a new backup</h2>
                <p class="text-sm text-slate-500 mt-1">Runs <span class="font-mono">mysqldump</span> of <span class="font-semibold">{{ $system['db_name'] }}</span> and stores it privately in <span class="font-mono text-xs">storage/app/backups</span>.</p>
            </div>
            <form method="POST" action="{{ route('admin.backups.run') }}"
                  onsubmit="this.querySelector('button').disabled=true; this.querySelector('button').innerText='Backing up…';">
                @csrf
                <button class="whitespace-nowrap rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-bold px-5 py-3 text-sm shadow-lg flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v12m0 0l-4-4m4 4l4-4M4 20h16"/></svg>
                    Back up now
                </button>
            </form>
        </div>
        @if ($system['mysqldump'] === 'not found')
            <div class="mt-4 rounded-xl border border-rose-300 bg-rose-50 dark:bg-rose-500/10 dark:border-rose-500/40 px-4 py-3 text-sm text-rose-700 dark:text-rose-300">
                ⚠ <span class="font-mono">mysqldump</span> was not found on this server. Set <span class="font-mono">MYSQLDUMP_BIN</span> in <span class="font-mono">.env</span> to its full path to enable backups.
            </div>
        @endif
    </div>

    <div class="{{ $card }} p-5">
        <h2 class="font-bold mb-3">At a glance</h2>
        <dl class="space-y-2.5 text-sm">
            <div class="flex justify-between"><dt class="text-slate-500">Database size</dt><dd class="font-semibold">{{ $fmt($system['db_size']) }}</dd></div>
            <div class="flex justify-between"><dt class="text-slate-500">Backups stored</dt><dd class="font-semibold">{{ $system['backup_count'] }}</dd></div>
            <div class="flex justify-between"><dt class="text-slate-500">Free disk</dt><dd class="font-semibold">{{ $fmt($system['disk_free']) }}</dd></div>
        </dl>
    </div>
</div>

<div class="{{ $card }} overflow-hidden">
    <div class="p-4 border-b border-slate-100 dark:border-slate-700/60"><h2 class="font-bold">Stored backups</h2></div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-[11px] uppercase tracking-wide text-slate-500 border-b border-slate-200 dark:border-slate-700">
                    <th class="px-4 py-3">File</th>
                    <th class="px-4 py-3">Size</th>
                    <th class="px-4 py-3 hidden sm:table-cell">Created</th>
                    <th class="px-4 py-3 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-700/60">
                @forelse ($backups as $b)
                    <tr>
                        <td class="px-4 py-3 font-mono text-xs break-all">{{ $b['name'] }}</td>
                        <td class="px-4 py-3 whitespace-nowrap">{{ $fmt($b['size']) }}</td>
                        <td class="px-4 py-3 hidden sm:table-cell text-slate-500 whitespace-nowrap">{{ \Illuminate\Support\Carbon::createFromTimestamp($b['modified'])->format('M j, Y g:i A') }}</td>
                        <td class="px-4 py-3">
                            <div class="flex items-center justify-end gap-2">
                                <a href="{{ route('admin.backups.download', $b['name']) }}" class="text-xs font-semibold rounded-lg border border-slate-300 dark:border-slate-600 hover:bg-slate-50 dark:hover:bg-slate-700 px-2.5 py-1.5">Download</a>
                                <form method="POST" action="{{ route('admin.backups.destroy', $b['name']) }}" onsubmit="return confirm('Delete this backup file permanently?');">
                                    @csrf @method('DELETE')
                                    <button class="text-xs font-semibold rounded-lg border border-rose-300 text-rose-700 hover:bg-rose-50 dark:border-rose-500/40 dark:text-rose-300 dark:hover:bg-rose-500/10 px-2.5 py-1.5">Delete</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-12 text-center text-slate-400">No backups yet. Click “Back up now” to create your first one.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<p class="text-xs text-slate-400 mt-4">Tip: backups contain student PII — download and store them somewhere secure, and delete old copies you no longer need.</p>
@endsection
