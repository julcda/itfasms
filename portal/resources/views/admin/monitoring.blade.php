@extends('admin.layout')
@section('title', 'Access Monitoring')
@section('heading', 'Access Monitoring')
@section('subheading', 'Who is accessing the portal, and every admin action')

@section('content')
@php $card = 'rounded-2xl border border-slate-200/70 dark:border-slate-700/60 bg-white/80 dark:bg-slate-800/50 shadow-panel'; @endphp

<div class="grid grid-cols-1 xl:grid-cols-3 gap-4">
    {{-- ── Login log ────────────────────────────────────────────────────── --}}
    <div class="xl:col-span-2 {{ $card }}">
        <div class="p-4 border-b border-slate-100 dark:border-slate-700/60">
            <h2 class="font-bold mb-3">Portal Login Activity</h2>
            <form method="GET" action="{{ route('admin.monitoring') }}" class="flex flex-wrap gap-2">
                <input name="search" value="{{ $filters['search'] ?? '' }}" placeholder="Name, LRN or IP…"
                       class="flex-1 min-w-[160px] rounded-lg border border-slate-300 dark:border-slate-600 dark:bg-slate-900/40 px-3 py-2 text-sm">
                <select name="event" class="rounded-lg border border-slate-300 dark:border-slate-600 dark:bg-slate-900/40 px-2 py-2 text-sm">
                    @foreach (['' => 'All events', 'login' => 'Logins', 'logout' => 'Logouts', 'failed' => 'Failed'] as $k => $v)
                        <option value="{{ $k }}" @selected(($filters['event'] ?? '') === $k)>{{ $v }}</option>
                    @endforeach
                </select>
                <button class="rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold px-4 py-2">Search</button>
            </form>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-[11px] uppercase tracking-wide text-slate-500 border-b border-slate-200 dark:border-slate-700">
                        <th class="px-4 py-3">Student</th>
                        <th class="px-4 py-3">Event</th>
                        <th class="px-4 py-3 hidden sm:table-cell">IP</th>
                        <th class="px-4 py-3 text-right">When</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-700/60">
                    @forelse ($log as $r)
                        <tr>
                            <td class="px-4 py-2.5">
                                <p class="font-medium">{{ $r->name ?: '—' }}</p>
                                <p class="text-[11px] text-slate-400 font-mono">{{ $r->lrn }}</p>
                            </td>
                            <td class="px-4 py-2.5">
                                <span class="text-xs font-semibold px-2 py-0.5 rounded-full {{ $r->event === 'login' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-400/15 dark:text-emerald-300' : ($r->event === 'failed' ? 'bg-rose-100 text-rose-700 dark:bg-rose-400/15 dark:text-rose-300' : 'bg-slate-200 text-slate-600 dark:bg-slate-700 dark:text-slate-300') }}">{{ ucfirst($r->event) }}</span>
                            </td>
                            <td class="px-4 py-2.5 hidden sm:table-cell font-mono text-xs text-slate-500">{{ $r->ip_address }}</td>
                            <td class="px-4 py-2.5 text-right text-slate-500 whitespace-nowrap">{{ \Illuminate\Support\Carbon::parse($r->created_at)->format('M j, g:i A') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-12 text-center text-slate-400">No access recorded yet. Activity appears here as students log in.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-4">{{ $log->links() }}</div>
    </div>

    {{-- ── Admin audit trail ────────────────────────────────────────────── --}}
    <div class="{{ $card }} p-4">
        <h2 class="font-bold mb-3">Admin Audit Trail</h2>
        <div class="space-y-2 max-h-[640px] overflow-y-auto pr-1">
            @forelse ($adminLog as $a)
                <div class="rounded-xl border border-slate-100 dark:border-slate-700/60 p-3">
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-xs font-bold px-2 py-0.5 rounded-md bg-gold-100 text-gold-700 dark:bg-gold-400/15 dark:text-gold-300">{{ $a->action }}</span>
                        <span class="text-[11px] text-slate-400 whitespace-nowrap">{{ \Illuminate\Support\Carbon::parse($a->created_at)->format('M j, g:i A') }}</span>
                    </div>
                    @if ($a->details || $a->target)
                        <p class="text-sm mt-1.5">{{ $a->details ?: $a->target }}</p>
                    @endif
                    <p class="text-[11px] text-slate-400 mt-1">{{ $a->admin_name }} · {{ $a->ip_address }}</p>
                </div>
            @empty
                <p class="text-sm text-slate-400 py-8 text-center">No admin actions logged yet.</p>
            @endforelse
        </div>
    </div>
</div>
@endsection
