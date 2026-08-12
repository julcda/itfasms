@extends('admin.layout')
@section('title', 'Student Accounts')
@section('heading', 'Student Portal Accounts')
@section('subheading', number_format($accounts->total()) . ' account(s) match your filters')

@section('content')
@php $card = 'rounded-2xl border border-slate-200/70 dark:border-slate-700/60 bg-white/80 dark:bg-slate-800/50 shadow-panel'; @endphp

{{-- ── Filters ──────────────────────────────────────────────────────────── --}}
<form method="GET" action="{{ route('admin.students') }}" class="{{ $card }} p-4 mb-4">
    <div class="grid grid-cols-2 md:grid-cols-6 gap-3 items-end">
        <div class="col-span-2">
            <label class="block text-[11px] font-semibold uppercase tracking-wide text-slate-500 mb-1">Search name / LRN</label>
            <input name="search" value="{{ $filters['search'] ?? '' }}" placeholder="Type to search…"
                   class="w-full rounded-lg border border-slate-300 dark:border-slate-600 dark:bg-slate-900/40 px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-emerald-400/40">
        </div>
        <div>
            <label class="block text-[11px] font-semibold uppercase tracking-wide text-slate-500 mb-1">Grade</label>
            <select name="grade" class="w-full rounded-lg border border-slate-300 dark:border-slate-600 dark:bg-slate-900/40 px-2 py-2 text-sm">
                <option value="">All</option>
                @foreach ($options['grades'] as $g)
                    <option value="{{ $g }}" @selected(($filters['grade'] ?? '') === $g)>{{ $g }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-[11px] font-semibold uppercase tracking-wide text-slate-500 mb-1">Section</label>
            <select name="section" class="w-full rounded-lg border border-slate-300 dark:border-slate-600 dark:bg-slate-900/40 px-2 py-2 text-sm">
                <option value="">All</option>
                @foreach ($options['sections'] as $s)
                    <option value="{{ $s }}" @selected(($filters['section'] ?? '') === $s)>{{ $s }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-[11px] font-semibold uppercase tracking-wide text-slate-500 mb-1">Access</label>
            <select name="login" class="w-full rounded-lg border border-slate-300 dark:border-slate-600 dark:bg-slate-900/40 px-2 py-2 text-sm">
                @foreach (['' => 'Any', 'never' => 'Never logged in', 'today' => 'Logged in today', '7days' => 'Last 7 days', 'active' => 'Has logged in'] as $k => $v)
                    <option value="{{ $k }}" @selected(($filters['login'] ?? '') === $k)>{{ $v }}</option>
                @endforeach
            </select>
        </div>
        <div class="flex gap-2">
            <button class="flex-1 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold px-3 py-2">Filter</button>
            <a href="{{ route('admin.students') }}" class="rounded-lg border border-slate-300 dark:border-slate-600 text-sm px-3 py-2 hover:bg-slate-50 dark:hover:bg-slate-700">Clear</a>
        </div>
    </div>
</form>

{{-- ── Bulk reset ───────────────────────────────────────────────────────── --}}
<div x-data="{ open: false }" class="{{ $card }} p-4 mb-4">
    <button @click="open = !open" type="button" class="flex items-center gap-2 text-sm font-semibold text-slate-700 dark:text-slate-200">
        <svg class="w-4 h-4 transition-transform" :class="open && 'rotate-90'" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
        Bulk reset passwords by grade / section
    </button>
    <div x-show="open" x-cloak class="mt-3 pt-3 border-t border-slate-100 dark:border-slate-700/60">
        <form method="POST" action="{{ route('admin.students.bulk-reset') }}"
              onsubmit="return confirm('Reset ALL matching accounts to the default password? Students will be forced to set a new one on next login.');"
              class="grid grid-cols-2 md:grid-cols-4 gap-3 items-end">
            @csrf
            <div>
                <label class="block text-[11px] font-semibold uppercase tracking-wide text-slate-500 mb-1">Grade</label>
                <select name="grade" class="w-full rounded-lg border border-slate-300 dark:border-slate-600 dark:bg-slate-900/40 px-2 py-2 text-sm">
                    <option value="">— any —</option>
                    @foreach ($options['grades'] as $g)<option value="{{ $g }}">{{ $g }}</option>@endforeach
                </select>
            </div>
            <div>
                <label class="block text-[11px] font-semibold uppercase tracking-wide text-slate-500 mb-1">Section</label>
                <select name="section" class="w-full rounded-lg border border-slate-300 dark:border-slate-600 dark:bg-slate-900/40 px-2 py-2 text-sm">
                    <option value="">— any —</option>
                    @foreach ($options['sections'] as $s)<option value="{{ $s }}">{{ $s }}</option>@endforeach
                </select>
            </div>
            <input type="hidden" name="confirm" value="RESET">
            <div class="col-span-2 md:col-span-2 flex items-center gap-2">
                <button class="rounded-lg bg-rose-600 hover:bg-rose-700 text-white text-sm font-semibold px-4 py-2">Reset matching accounts</button>
                <span class="text-[11px] text-slate-400">Pick at least a grade or section.</span>
            </div>
        </form>
    </div>
</div>

{{-- ── Table ────────────────────────────────────────────────────────────── --}}
<div class="{{ $card }} overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-[11px] uppercase tracking-wide text-slate-500 border-b border-slate-200 dark:border-slate-700">
                    <th class="px-4 py-3">Student</th>
                    <th class="px-4 py-3 hidden sm:table-cell">Grade · Section</th>
                    <th class="px-4 py-3">Status</th>
                    <th class="px-4 py-3 hidden md:table-cell">Last login</th>
                    <th class="px-4 py-3 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-700/60">
                @forelse ($accounts as $a)
                    <tr class="hover:bg-slate-50/70 dark:hover:bg-slate-700/30">
                        <td class="px-4 py-3">
                            <p class="font-semibold">{{ $a->name ?: '—' }}</p>
                            <p class="text-[11px] text-slate-400 font-mono">LRN {{ $a->lrn }}</p>
                        </td>
                        <td class="px-4 py-3 hidden sm:table-cell">
                            <span class="text-slate-600 dark:text-slate-300">{{ $a->grade_name }}</span>
                            <span class="text-slate-400"> · {{ $a->section_name }}</span>
                        </td>
                        <td class="px-4 py-3">
                            <span class="inline-flex items-center gap-1 text-xs font-semibold px-2 py-0.5 rounded-full {{ $a->status === 'Active' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-400/15 dark:text-emerald-300' : 'bg-slate-200 text-slate-600 dark:bg-slate-700 dark:text-slate-300' }}">
                                {{ $a->status }}
                            </span>
                            @if ((int) $a->must_change_password === 1)
                                <span class="block text-[10px] text-gold-600 mt-1">must change pw</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 hidden md:table-cell text-slate-500">
                            {{ $a->last_login ? \Illuminate\Support\Carbon::parse($a->last_login)->format('M j, Y g:i A') : '— never —' }}
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center justify-end gap-2">
                                <form method="POST" action="{{ route('admin.students.reset', $a->id) }}"
                                      onsubmit="return confirm('Reset {{ addslashes($a->name ?: 'this student') }}\'s password to the default?');">
                                    @csrf
                                    <button class="text-xs font-semibold rounded-lg border border-emerald-300 text-emerald-700 hover:bg-emerald-50 dark:border-emerald-500/40 dark:text-emerald-300 dark:hover:bg-emerald-500/10 px-2.5 py-1.5">Reset PW</button>
                                </form>
                                <form method="POST" action="{{ route('admin.students.status', $a->id) }}"
                                      onsubmit="return confirm('{{ $a->status === 'Active' ? 'Deactivate' : 'Reactivate' }} portal access for {{ addslashes($a->name ?: 'this student') }}?');">
                                    @csrf
                                    <button class="text-xs font-semibold rounded-lg border px-2.5 py-1.5 {{ $a->status === 'Active' ? 'border-rose-300 text-rose-700 hover:bg-rose-50 dark:border-rose-500/40 dark:text-rose-300 dark:hover:bg-rose-500/10' : 'border-slate-300 text-slate-600 hover:bg-slate-50 dark:border-slate-600 dark:text-slate-300' }}">
                                        {{ $a->status === 'Active' ? 'Deactivate' : 'Activate' }}
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-12 text-center text-slate-400">No accounts match these filters.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="mt-4">{{ $accounts->links() }}</div>
@endsection
