@extends('admin.layout')
@section('title', 'Maintenance')
@section('heading', 'System & Maintenance')
@section('subheading', 'Health of the portal and housekeeping tools')

@section('content')
@php
    $card = 'rounded-2xl border border-slate-200/70 dark:border-slate-700/60 bg-white/80 dark:bg-slate-800/50 shadow-panel';
    $fmt = fn ($b) => $b >= 1073741824 ? number_format($b/1073741824, 2).' GB' : ($b >= 1048576 ? number_format($b/1048576, 1).' MB' : number_format(max(1,$b)/1024, 0).' KB');
    $diskUsedPct = $system['disk_total'] > 0 ? (int) round(($system['disk_total'] - $system['disk_free']) / $system['disk_total'] * 100) : 0;
@endphp

<div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
    {{-- System info --}}
    <div class="{{ $card }} p-5">
        <h2 class="font-bold mb-4">System Information</h2>
        <dl class="grid grid-cols-2 gap-y-3 text-sm">
            <dt class="text-slate-500">PHP</dt><dd class="font-semibold text-right font-mono">{{ $system['php_version'] }}</dd>
            <dt class="text-slate-500">Laravel</dt><dd class="font-semibold text-right font-mono">{{ $system['laravel_version'] }}</dd>
            <dt class="text-slate-500">Environment</dt>
            <dd class="text-right"><span class="text-xs font-bold px-2 py-0.5 rounded {{ $system['app_env'] === 'production' ? 'bg-emerald-100 text-emerald-700' : 'bg-gold-100 text-gold-700' }}">{{ $system['app_env'] }}</span></dd>
            <dt class="text-slate-500">Debug mode</dt>
            <dd class="text-right"><span class="text-xs font-bold px-2 py-0.5 rounded {{ $system['debug'] ? 'bg-rose-100 text-rose-700' : 'bg-emerald-100 text-emerald-700' }}">{{ $system['debug'] ? 'ON' : 'off' }}</span></dd>
            <dt class="text-slate-500">Timezone</dt><dd class="font-semibold text-right">{{ $system['timezone'] }}</dd>
            <dt class="text-slate-500">Database</dt><dd class="font-semibold text-right font-mono truncate">{{ $system['db_name'] }}</dd>
            <dt class="text-slate-500">DB size</dt><dd class="font-semibold text-right">{{ $fmt($system['db_size']) }}</dd>
            <dt class="text-slate-500">mysqldump</dt>
            <dd class="text-right"><span class="text-xs {{ $system['mysqldump'] === 'not found' ? 'text-rose-600' : 'text-emerald-600' }}">{{ $system['mysqldump'] === 'not found' ? 'not found' : 'available' }}</span></dd>
        </dl>
        @if ($system['debug'] && $system['app_env'] === 'production')
            <div class="mt-4 rounded-xl border border-rose-300 bg-rose-50 dark:bg-rose-500/10 dark:border-rose-500/40 px-4 py-3 text-sm text-rose-700 dark:text-rose-300">
                ⚠ Debug mode is ON in production. Set <span class="font-mono">APP_DEBUG=false</span> in <span class="font-mono">.env</span> to avoid leaking errors to students.
            </div>
        @endif
    </div>

    {{-- Disk + housekeeping --}}
    <div class="space-y-4">
        <div class="{{ $card }} p-5">
            <h2 class="font-bold mb-3">Disk Usage</h2>
            <div class="flex justify-between text-sm mb-1">
                <span class="text-slate-500">{{ $fmt($system['disk_total'] - $system['disk_free']) }} used</span>
                <span class="font-bold">{{ $diskUsedPct }}%</span>
            </div>
            <div class="h-2.5 rounded-full bg-slate-200 dark:bg-slate-700 overflow-hidden">
                <div class="h-full {{ $diskUsedPct > 90 ? 'bg-rose-500' : ($diskUsedPct > 75 ? 'bg-gold-500' : 'bg-emerald-500') }}" style="width: {{ $diskUsedPct }}%"></div>
            </div>
            <p class="text-xs text-slate-400 mt-2">{{ $fmt($system['disk_free']) }} free of {{ $fmt($system['disk_total']) }}</p>
        </div>

        <div class="{{ $card }} p-5">
            <h2 class="font-bold mb-1">Clear Caches</h2>
            <p class="text-sm text-slate-500 mb-4">Use after uploading updated views or config. Safe to run anytime.</p>
            <div class="flex flex-wrap gap-2">
                @foreach (['views' => 'Compiled views', 'config' => 'Config cache', 'all' => 'Everything'] as $what => $label)
                    <form method="POST" action="{{ route('admin.maintenance.clear-cache') }}">
                        @csrf
                        <input type="hidden" name="what" value="{{ $what }}">
                        <button class="rounded-lg border border-slate-300 dark:border-slate-600 hover:bg-slate-50 dark:hover:bg-slate-700 text-sm font-semibold px-3.5 py-2">{{ $label }}</button>
                    </form>
                @endforeach
            </div>
        </div>
    </div>
</div>
@endsection
