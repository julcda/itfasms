@extends('layouts.app')
@section('title', 'Account Management')

@section('content')
@php
    $mid = trim((string) ($profile->middlename ?? ''));
    $fullName = trim($profile->firstname . ' ' . ($mid !== '' ? $mid . ' ' : '') . $profile->surname);
    $initials = strtoupper(substr($profile->firstname ?? 'S',0,1) . substr($profile->surname ?? '',0,1));
@endphp

<x-page-header accent="green" eyebrow="Student Portal" title="Account Management"
               subtitle="Your personal details are managed by the Registrar. You can update your photo and password here." />

<div class="grid lg:grid-cols-2 gap-6">
    <x-card>
        <h2 class="font-extrabold text-lg mb-4 text-slate-900 dark:text-white">Profile</h2>
        <div class="flex items-center gap-4 mb-5">
            @if ($photoUrl)
            <img src="{{ $photoUrl }}" alt="Your profile photo" class="w-20 h-20 rounded-2xl object-cover border-2 border-green-200 dark:border-slate-600">
            @else
            <div class="w-20 h-20 rounded-2xl bg-green-700 text-white flex items-center justify-center text-2xl font-extrabold" aria-hidden="true">{{ $initials }}</div>
            @endif
            <form method="POST" action="{{ route('account.photo') }}" enctype="multipart/form-data" class="flex-1">
                @csrf
                <label for="photo" class="block text-xs font-semibold text-slate-500 dark:text-slate-400 mb-1">Profile Photo (JPG/PNG/WEBP, ≤3MB)</label>
                <input id="photo" type="file" name="photo" accept="image/*" required class="block w-full text-sm text-slate-600 dark:text-slate-300 file:mr-3 file:py-2 file:px-3 file:rounded-lg file:border-0 file:bg-green-50 dark:file:bg-green-500/15 file:text-green-700 dark:file:text-green-400 file:font-semibold">
                <button class="mt-2 rounded-lg bg-green-700 hover:bg-green-800 text-white text-xs font-bold px-4 py-2 focus:outline-none focus-visible:ring-2 focus-visible:ring-green-500">Upload Photo</button>
            </form>
        </div>
        <dl class="text-sm divide-y divide-slate-100 dark:divide-slate-700/50">
            @php
            $ro = [
                'Full Name'      => $fullName,
                'LRN'            => $profile->lrn ?: '—',
                'Grade & Section'=> trim(($profile->grade_name ?: '') . ' ' . ($profile->section_name ?: '')) ?: '—',
                'Department'     => $profile->Department ?: '—',
                'School Year'    => $profile->school_year ?: '—',
                'Sex'            => $profile->sex ?: '—',
                'Contact'        => $profile->contact ?: '—',
                'Email'          => $profile->email ?: '—',
                'Student Type'   => $profile->student_type ?: '—',
            ];
            @endphp
            @foreach ($ro as $k => $v)
            <div class="flex justify-between py-2"><dt class="text-slate-500 dark:text-slate-400">{{ $k }}</dt><dd class="font-semibold text-right text-slate-800 dark:text-slate-100">{{ $v ?: '—' }}</dd></div>
            @endforeach
        </dl>
        <p class="text-xs text-slate-400 dark:text-slate-500 mt-3">To correct your name, LRN, or other details, please contact the Registrar or your class adviser.</p>
    </x-card>

    <div class="space-y-6">
        <a href="{{ route('password.change') }}" class="block bg-white dark:bg-slate-800/60 rounded-3xl border border-green-100 dark:border-slate-700 shadow-panel p-5 hover:border-green-300 dark:hover:border-green-500 transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-green-500">
            <div class="flex items-center gap-3">
                <span class="w-9 h-9 rounded-lg bg-green-50 dark:bg-green-500/15 flex items-center justify-center shrink-0">
                    <svg class="w-5 h-5 text-green-700 dark:text-green-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-12V7a4 4 0 00-8 0v4h8z"/></svg>
                </span>
                <div><p class="font-bold text-sm text-slate-800 dark:text-slate-100">Change Password</p><p class="text-xs text-slate-400 dark:text-slate-500">Update your login password.</p></div>
                <svg class="w-4 h-4 text-slate-400 dark:text-slate-500 ml-auto" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
            </div>
        </a>

        <x-card>
            <h2 class="font-extrabold text-lg mb-2 text-slate-900 dark:text-white">Account Security</h2>
            <p class="text-sm text-slate-500 dark:text-slate-400">Last login:
                <span class="font-semibold text-slate-700 dark:text-slate-200">{{ $account && $account->last_login ? \Carbon\Carbon::parse($account->last_login)->format('M j, Y g:i A') : '—' }}</span>
            </p>
            <p class="text-xs text-slate-400 dark:text-slate-500 mt-2">Keep your password private. If you suspect someone else knows it, change it immediately.</p>
        </x-card>
    </div>
</div>
@endsection
