@extends('layouts.app')
@section('title', 'Statement of Account')

@section('content')
@php
    $mid = trim((string) ($profile->middlename ?? ''));
    $fullName = trim($profile->firstname . ' ' . ($mid !== '' ? $mid[0] . '. ' : '') . $profile->surname);
@endphp

<x-page-header accent="green" eyebrow="Financial Record" title="Statement of Account"
               :subtitle="$fullName . ' · ' . $profile->grade_name . ' ' . $profile->section_name . ' · S.Y. ' . $profile->school_year">
    <x-slot:actions>
        <x-badge :color="$color">{{ $soa['payStatus'] }}</x-badge>
        @if ($soa['officialSoaId'] > 0 && !$soa['officialSoaPaid'])
        <a href="{{ route('soa.print') }}" target="_blank" class="rounded-xl bg-green-600 hover:bg-green-700 text-white text-sm font-bold px-4 py-2.5 focus:outline-none focus-visible:ring-2 focus-visible:ring-green-500">🖨 Print SOA</a>
        @elseif ($soa['officialSoaPaid'])
        <span class="rounded-xl bg-emerald-50 dark:bg-emerald-500/10 border border-emerald-300 dark:border-emerald-500/30 text-emerald-700 dark:text-emerald-300 text-sm font-semibold px-4 py-2.5">✓ SOA fully paid</span>
        @else
        <span class="rounded-xl bg-slate-100 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 text-slate-400 dark:text-slate-500 text-sm font-semibold px-4 py-2.5 cursor-not-allowed">🖨 Official SOA not available yet</span>
        @endif
    </x-slot:actions>
</x-page-header>

@if ($soa['promissoryNotes'])
<div class="mb-6 rounded-2xl bg-rose-50 dark:bg-rose-500/10 border border-rose-300 dark:border-rose-500/30 px-5 py-4">
    <div class="flex items-start gap-3">
        <span class="text-xl leading-none" aria-hidden="true">⚠️</span>
        <div class="flex-1">
            <p class="font-bold text-rose-800 dark:text-rose-300">This student has an unpaid promissory note that must be settled.</p>
            <p class="text-sm text-rose-700 dark:text-rose-400 mt-0.5">Total under promissory arrangement: <strong>₱{{ number_format($soa['promissoryTotal'], 2) }}</strong> — included in your outstanding balance.</p>
            <div class="mt-3 space-y-1.5">
                @foreach ($soa['promissoryNotes'] as $pn)
                <div class="flex flex-wrap items-center gap-x-3 text-xs bg-white/70 dark:bg-slate-800/70 rounded-lg px-3 py-2 border border-rose-200 dark:border-rose-500/20">
                    <span class="font-mono font-bold text-slate-700 dark:text-slate-200">{{ $pn->promissory_no }}</span>
                    <span class="font-semibold text-slate-700 dark:text-slate-200">₱{{ number_format((float) $pn->promissory_amount, 2) }}</span>
                    <span class="text-slate-500 dark:text-slate-400">promised by {{ \Carbon\Carbon::parse($pn->promised_payment_date)->format('M j, Y') }}</span>
                    <x-badge :color="$pn->status === 'Overdue' ? 'rose' : 'amber'">{{ $pn->status }}</x-badge>
                </div>
                @endforeach
            </div>
        </div>
    </div>
</div>
@endif

@if ($soa['backAccounts'])
<div class="mb-6 rounded-2xl bg-amber-50 dark:bg-amber-500/10 border border-amber-300 dark:border-amber-500/30 px-5 py-4">
    <div class="flex items-start gap-3">
        <span class="text-xl leading-none" aria-hidden="true">⚠️</span>
        <div class="flex-1">
            <p class="font-bold text-amber-900 dark:text-amber-300">You have an unpaid back account from a previous school year.</p>
            <p class="text-sm text-amber-800 dark:text-amber-400 mt-0.5">Total outstanding back account: <strong>₱{{ number_format($soa['backAccountTotal'], 2) }}</strong> — this is <em>separate</em> from this year's balance below.</p>
            <div class="mt-3 space-y-1.5">
                @foreach ($soa['backAccounts'] as $ba)
                <div class="flex flex-wrap items-center gap-x-3 text-xs bg-white/70 dark:bg-slate-800/70 rounded-lg px-3 py-2 border border-amber-200 dark:border-amber-500/20">
                    <span class="font-bold text-slate-700 dark:text-slate-200">S.Y. {{ $ba->school_year }}</span>
                    <span class="font-semibold text-slate-700 dark:text-slate-200">₱{{ number_format((float) $ba->balance, 2) }}</span>
                    <x-badge color="amber">{{ $ba->status }}</x-badge>
                </div>
                @endforeach
            </div>
        </div>
    </div>
</div>
@endif

@if (!$soa['assessment'])
<div class="rounded-3xl bg-white dark:bg-slate-800/60 border border-amber-300 dark:border-amber-500/30 shadow-panel p-8 text-center">
    <h2 class="font-extrabold text-amber-700 dark:text-amber-400 mb-1">No assessment on file yet</h2>
    <p class="text-sm text-slate-600 dark:text-slate-400">Your Statement of Account has not been generated. Please visit the Cashier's office or check back soon.</p>
</div>
@else

@if ($soa['officialSoaId'] === 0)
<div class="mb-6 rounded-2xl bg-green-50 dark:bg-green-500/10 border border-green-200 dark:border-green-500/30 px-5 py-4 text-sm text-green-800 dark:text-green-300 flex items-start gap-3">
    <span class="text-lg leading-none" aria-hidden="true">ℹ️</span>
    <p>Your <strong>official printable SOA</strong> has not been generated by the Cashier yet. Your fee breakdown and payment history below are up to date.</p>
</div>
@endif

<div class="grid lg:grid-cols-3 gap-6">
    <x-card class="lg:col-span-2">
        <h2 class="font-extrabold text-lg mb-4 text-slate-900 dark:text-white">Assessment Breakdown</h2>

        <div class="mb-5">
            <p class="text-xs uppercase tracking-wide text-slate-400 dark:text-slate-500 font-semibold mb-2">Monthly Fees <span class="normal-case">(× {{ $soa['installmentCount'] }} months)</span></p>
            <div class="rounded-2xl border border-slate-100 dark:border-slate-700 divide-y divide-slate-100 dark:divide-slate-700/60">
                @foreach ($soa['monthly'] as $label => $amt)
                <div class="flex justify-between px-4 py-2.5 text-sm">
                    <span class="text-slate-600 dark:text-slate-300">{{ $label }}</span>
                    <span class="font-semibold text-slate-800 dark:text-slate-100">₱{{ number_format((float) $amt, 2) }} <span class="text-slate-400 dark:text-slate-500 font-normal">/mo</span></span>
                </div>
                @endforeach
                <div class="flex justify-between px-4 py-2.5 text-sm bg-slate-50 dark:bg-slate-700/40">
                    <span class="font-semibold text-slate-700 dark:text-slate-200">Monthly total</span>
                    <span class="font-bold text-slate-800 dark:text-slate-100">₱{{ number_format($soa['monthlyTotal'], 2) }} /mo</span>
                </div>
                <div class="flex justify-between px-4 py-2.5 text-sm bg-green-50/60 dark:bg-green-500/10">
                    <span class="font-semibold text-green-800 dark:text-green-300">Installment subtotal ({{ $soa['installmentCount'] }} months)</span>
                    <span class="font-bold text-green-800 dark:text-green-300">₱{{ number_format($soa['installmentBase'], 2) }}</span>
                </div>
            </div>
        </div>

        <div class="mb-5">
            <p class="text-xs uppercase tracking-wide text-slate-400 dark:text-slate-500 font-semibold mb-2">Enrollment Fees</p>
            <div class="rounded-2xl border border-slate-100 dark:border-slate-700 divide-y divide-slate-100 dark:divide-slate-700/60">
                @forelse ($soa['enrollFees'] as $f)
                <div class="flex justify-between px-4 py-2.5 text-sm">
                    <span class="text-slate-600 dark:text-slate-300">{{ $f['label'] }}</span>
                    <span class="font-semibold text-slate-800 dark:text-slate-100">₱{{ number_format($f['amount'], 2) }}</span>
                </div>
                @empty
                <div class="px-4 py-2.5 text-sm text-slate-400 dark:text-slate-500">None recorded.</div>
                @endforelse
                <div class="flex justify-between px-4 py-2.5 text-sm bg-slate-50 dark:bg-slate-700/40">
                    <span class="font-semibold text-slate-700 dark:text-slate-200">Enrollment fees subtotal</span>
                    <span class="font-bold text-slate-800 dark:text-slate-100">₱{{ number_format($soa['enrollFeesTotal'], 2) }}</span>
                </div>
            </div>
        </div>

        <div class="rounded-2xl bg-green-600 text-white px-5 py-4 flex justify-between items-center">
            <span class="font-bold">TOTAL ASSESSMENT</span>
            <span class="text-2xl font-extrabold">₱{{ number_format($soa['netAssessed'], 2) }}</span>
        </div>
    </x-card>

    <x-card class="h-fit">
        <h2 class="font-extrabold text-lg mb-4 text-slate-900 dark:text-white">Summary</h2>
        <div class="space-y-3">
            <div class="flex justify-between text-sm"><span class="text-slate-500 dark:text-slate-400">Total Assessment</span><span class="font-bold text-slate-800 dark:text-slate-100">₱{{ number_format($soa['netAssessed'], 2) }}</span></div>
            <div class="flex justify-between text-sm"><span class="text-slate-500 dark:text-slate-400">Payments Made</span><span class="font-bold text-emerald-600 dark:text-emerald-400">− ₱{{ number_format($soa['totalPaid'], 2) }}</span></div>
            <div class="border-t border-slate-200 dark:border-slate-700 pt-3 flex justify-between"><span class="font-bold text-slate-800 dark:text-slate-100">Remaining Balance</span><span class="text-xl font-extrabold {{ $soa['balance'] > 0 ? 'text-rose-600 dark:text-rose-400' : 'text-emerald-600 dark:text-emerald-400' }}">₱{{ number_format(max(0,$soa['balance']), 2) }}</span></div>
        </div>
        <div class="mt-5 rounded-2xl bg-{{ $color }}-50 dark:bg-{{ $color }}-500/10 border border-{{ $color }}-200 dark:border-{{ $color }}-500/30 px-4 py-3 text-center">
            <p class="text-xs uppercase tracking-wide text-{{ $color }}-600 dark:text-{{ $color }}-400 font-semibold">Payment Status</p>
            <p class="text-lg font-extrabold text-{{ $color }}-800 dark:text-{{ $color }}-300 mt-0.5">{{ $soa['payStatus'] }}</p>
        </div>
    </x-card>
</div>

<x-card class="mt-6">
    <h2 class="font-extrabold text-lg mb-4 text-slate-900 dark:text-white">Payment History</h2>
    @if (!$soa['payments'])
    <div class="rounded-xl bg-slate-50 dark:bg-slate-700/40 border border-slate-200 dark:border-slate-700 text-slate-500 dark:text-slate-400 text-sm px-4 py-8 text-center">No payments recorded yet.</div>
    @else
    <div class="overflow-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-xs uppercase tracking-wide text-slate-400 dark:text-slate-500 border-b border-slate-200 dark:border-slate-700">
                    <th scope="col" class="py-2 pr-3">OR Number</th><th scope="col" class="py-2 pr-3">Date</th><th scope="col" class="py-2 pr-3">Method</th>
                    <th scope="col" class="py-2 pr-3 text-right">Amount Paid</th><th scope="col" class="py-2 pr-3 text-right">Running Balance</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($soa['payments'] as $p)
                <tr class="border-b border-slate-100 dark:border-slate-700/50">
                    <td class="py-2.5 pr-3 font-medium text-slate-700 dark:text-slate-200">{{ $p['or_number'] }}</td>
                    <td class="py-2.5 pr-3 text-slate-500 dark:text-slate-400">{{ \Carbon\Carbon::parse($p['paid_at'])->format('M j, Y') }}</td>
                    <td class="py-2.5 pr-3 text-slate-600 dark:text-slate-300">{{ $p['method'] }}</td>
                    <td class="py-2.5 pr-3 text-right font-semibold text-emerald-600 dark:text-emerald-400">₱{{ number_format($p['amount'], 2) }}</td>
                    <td class="py-2.5 pr-3 text-right font-semibold text-slate-700 dark:text-slate-200">{{ $p['running'] < 0 ? '−₱' . number_format(abs($p['running']),2) : '₱' . number_format($p['running'], 2) }}</td>
                </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr class="bg-slate-50 dark:bg-slate-700/40">
                    <td colspan="3" class="py-2.5 pr-3 font-bold text-right text-slate-700 dark:text-slate-200">Total Paid</td>
                    <td class="py-2.5 pr-3 text-right font-extrabold text-emerald-700 dark:text-emerald-400">₱{{ number_format($soa['totalPaid'], 2) }}</td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
    </div>
    @endif
</x-card>
@endif
@endsection
