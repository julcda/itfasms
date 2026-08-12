@extends('admin.layout')
@section('title', 'Dashboard')
@section('heading', 'Maintenance Dashboard')
@section('subheading', 'Live overview of the student portal' . ($stats['sy'] ? ' · S.Y. ' . $stats['sy'] : ''))

@section('content')
@php
    $maxTrend = max(1, ...array_values($trend));
    $card = 'rounded-2xl border border-slate-200/70 dark:border-slate-700/60 bg-white/80 dark:bg-slate-800/50 p-5 shadow-panel';
@endphp

{{-- ── KPI grid ─────────────────────────────────────────────────────────── --}}
<div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4 mb-5">
    @foreach ([
        ['Portal Accounts', $stats['accounts'], 'Provisioned logins', 'text-emerald-600'],
        ['Active', $stats['active'], $stats['inactive'].' inactive', 'text-sky-600'],
        ['Logged in Today', $stats['active_today'], $stats['logins_today'].' total logins', 'text-gold-600'],
        ['Never Logged In', $stats['never_logged_in'], 'have not accessed yet', 'text-rose-600'],
    ] as [$label, $value, $sub, $color])
        <div class="{{ $card }}">
            <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ $label }}</p>
            <p class="text-3xl font-extrabold mt-1 {{ $color }}">{{ number_format((int) $value) }}</p>
            <p class="text-xs text-slate-400 mt-1">{{ $sub }}</p>
        </div>
    @endforeach
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-5">
    {{-- Login trend --}}
    <div class="{{ $card }} lg:col-span-2">
        <div class="flex items-center justify-between mb-4">
            <h2 class="font-bold">Portal Access — last 14 days</h2>
            <span class="text-xs text-slate-400">{{ number_format($stats['logins_7d']) }} logins this week</span>
        </div>
        <div class="flex items-end gap-1.5 h-36">
            @foreach ($trend as $day => $count)
                <div class="flex-1 flex flex-col items-center gap-1 group">
                    <div class="w-full rounded-t-md bg-gradient-to-t from-emerald-500 to-emerald-400 dark:from-emerald-600 dark:to-emerald-400 relative transition-all"
                         style="height: {{ max(3, (int) round($count / $maxTrend * 120)) }}px" title="{{ $day }}: {{ $count }}">
                        <span class="absolute -top-5 left-1/2 -translate-x-1/2 text-[10px] font-bold text-slate-500 opacity-0 group-hover:opacity-100">{{ $count }}</span>
                    </div>
                    <span class="text-[8px] text-slate-400 whitespace-nowrap">{{ \Illuminate\Support\Str::afterLast($day, ' ') }}</span>
                </div>
            @endforeach
        </div>
    </div>

    {{-- Health snapshot --}}
    <div class="{{ $card }}">
        <h2 class="font-bold mb-4">Coverage</h2>
        <div class="space-y-3.5">
            @php
                $enrolled = max(1, (int) $stats['enrolled']);
                $pct = min(100, (int) round($stats['accounts'] / $enrolled * 100));
            @endphp
            <div>
                <div class="flex justify-between text-xs mb-1"><span class="text-slate-500">Accounts vs enrolled</span><span class="font-bold">{{ $pct }}%</span></div>
                <div class="h-2 rounded-full bg-slate-200 dark:bg-slate-700 overflow-hidden"><div class="h-full bg-emerald-500" style="width: {{ $pct }}%"></div></div>
                <p class="text-[11px] text-slate-400 mt-1">{{ number_format($stats['not_provisioned']) }} enrolled students have not opened the portal yet.</p>
            </div>
            <div class="pt-2 border-t border-slate-100 dark:border-slate-700/60 space-y-2 text-sm">
                <div class="flex justify-between"><span class="text-slate-500">Must change password</span><span class="font-semibold">{{ number_format($stats['must_change']) }}</span></div>
                <div class="flex justify-between"><span class="text-slate-500">Last backup</span>
                    <span class="font-semibold">{{ $stats['last_backup'] ? \Illuminate\Support\Carbon::createFromTimestamp($stats['last_backup']['modified'])->diffForHumans() : '—' }}</span>
                </div>
            </div>
            <a href="{{ route('admin.backups') }}" class="block text-center text-xs font-semibold text-gold-600 hover:underline pt-1">Manage backups →</a>
        </div>
    </div>
</div>

{{-- ── Recent activity ──────────────────────────────────────────────────── --}}
<div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
    <div class="{{ $card }}">
        <div class="flex items-center justify-between mb-3">
            <h2 class="font-bold">Recent Portal Access</h2>
            <a href="{{ route('admin.monitoring') }}" class="text-xs font-semibold text-gold-600 hover:underline">View all →</a>
        </div>
        <div class="divide-y divide-slate-100 dark:divide-slate-700/60">
            @forelse ($recent as $r)
                <div class="flex items-center gap-3 py-2.5">
                    <span class="w-2 h-2 rounded-full flex-shrink-0 {{ $r->event === 'login' ? 'bg-emerald-500' : ($r->event === 'failed' ? 'bg-rose-500' : 'bg-slate-400') }}"></span>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium truncate">{{ $r->name ?: 'LRN '.$r->lrn }}</p>
                        <p class="text-[11px] text-slate-400 truncate">{{ ucfirst($r->event) }} · {{ $r->ip_address }}</p>
                    </div>
                    <span class="text-[11px] text-slate-400 whitespace-nowrap">{{ \Illuminate\Support\Carbon::parse($r->created_at)->diffForHumans(null, true) }} ago</span>
                </div>
            @empty
                <p class="text-sm text-slate-400 py-6 text-center">No portal access recorded yet.</p>
            @endforelse
        </div>
    </div>

    <div class="{{ $card }}">
        <div class="flex items-center justify-between mb-3">
            <h2 class="font-bold">Recent Admin Actions</h2>
            <a href="{{ route('admin.monitoring') }}" class="text-xs font-semibold text-gold-600 hover:underline">Audit trail →</a>
        </div>
        <div class="divide-y divide-slate-100 dark:divide-slate-700/60">
            @forelse ($adminLog as $a)
                <div class="flex items-center gap-3 py-2.5">
                    <span class="text-xs font-bold px-2 py-0.5 rounded-md bg-gold-100 text-gold-700 dark:bg-gold-400/15 dark:text-gold-300 whitespace-nowrap">{{ $a->action }}</span>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm truncate">{{ $a->details ?: $a->target ?: '—' }}</p>
                        <p class="text-[11px] text-slate-400 truncate">{{ $a->admin_name }}</p>
                    </div>
                    <span class="text-[11px] text-slate-400 whitespace-nowrap">{{ \Illuminate\Support\Carbon::parse($a->created_at)->diffForHumans(null, true) }} ago</span>
                </div>
            @empty
                <p class="text-sm text-slate-400 py-6 text-center">No admin actions logged yet.</p>
            @endforelse
        </div>
    </div>
</div>

<div class="mt-5 flex flex-wrap gap-3">
    <a href="{{ route('admin.students') }}" class="inline-flex items-center gap-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold px-4 py-2.5">Reset a student password</a>
    <a href="{{ route('admin.backups') }}" class="inline-flex items-center gap-2 rounded-xl bg-slate-800 hover:bg-slate-900 text-white text-sm font-semibold px-4 py-2.5 dark:bg-slate-700">Create a backup</a>
</div>
@endsection
