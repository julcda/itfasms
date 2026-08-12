<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign in | ITFA Super Admin</title>
    @include('partials.head')
</head>
<body class="min-h-screen font-sans text-slate-100 flex items-center justify-center p-5"
      style="background:radial-gradient(60rem 40rem at 15% -10%, rgba(224,149,31,.14), transparent 60%), linear-gradient(165deg,#0b1220 0%,#0f1a2b 55%,#0a1017 100%)">

    <div class="w-full max-w-md" x-data="{ show: false }">
        <div class="flex items-center gap-3 mb-8 justify-center">
            <div class="w-14 h-14 rounded-2xl bg-white/10 ring-1 ring-gold-400/30 flex items-center justify-center">
                <img src="/itfalogo.png" alt="ITFA logo" class="w-10 h-10 object-contain" onerror="this.style.display='none'">
            </div>
            <div>
                <p class="text-[11px] uppercase tracking-[0.24em] text-gold-300 font-bold">ITFA Student Portal</p>
                <p class="text-xl font-extrabold font-display leading-tight">Super Admin Console</p>
            </div>
        </div>

        <div class="rounded-3xl border border-white/10 bg-white/[0.04] backdrop-blur-sm p-7 shadow-2xl">
            <h1 class="text-lg font-bold mb-1">Maintenance sign-in</h1>
            <p class="text-sm text-slate-400 mb-6">Use your ITFA staff (Super Admin) username &amp; password.</p>

            @if (session('error'))
                <div class="mb-4 rounded-xl border border-rose-500/40 bg-rose-500/10 px-4 py-3 text-sm text-rose-200">{{ session('error') }}</div>
            @endif
            @if (session('success'))
                <div class="mb-4 rounded-xl border border-emerald-500/40 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-200">{{ session('success') }}</div>
            @endif

            <form method="POST" action="{{ route('admin.login.attempt') }}" class="space-y-4">
                @csrf
                <div>
                    <label for="username" class="block text-xs font-semibold uppercase tracking-wide text-slate-300 mb-1.5">Username</label>
                    <input id="username" name="username" type="text" value="{{ old('username') }}" required autofocus autocomplete="username"
                           class="w-full rounded-xl bg-white/[0.06] border border-white/15 focus:border-gold-400 focus:ring-2 focus:ring-gold-400/40 px-4 py-3 text-sm text-white placeholder-slate-500 outline-none transition"
                           placeholder="e.g. superadmin">
                </div>
                <div>
                    <label for="password" class="block text-xs font-semibold uppercase tracking-wide text-slate-300 mb-1.5">Password</label>
                    <div class="relative">
                        <input id="password" name="password" :type="show ? 'text' : 'password'" required autocomplete="current-password"
                               class="w-full rounded-xl bg-white/[0.06] border border-white/15 focus:border-gold-400 focus:ring-2 focus:ring-gold-400/40 px-4 py-3 pr-11 text-sm text-white placeholder-slate-500 outline-none transition"
                               placeholder="Your password">
                        <button type="button" @click="show = !show" class="absolute inset-y-0 right-0 px-3 text-slate-400 hover:text-slate-200" aria-label="Toggle password">
                            <svg x-show="!show" class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                            <svg x-show="show" x-cloak class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/></svg>
                        </button>
                    </div>
                </div>

                <button type="submit"
                        class="w-full rounded-xl bg-gradient-to-r from-gold-500 to-gold-600 hover:from-gold-400 hover:to-gold-500 text-white font-bold py-3 text-sm shadow-lg transition flex items-center justify-center gap-2">
                    Enter Console
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13 7l5 5m0 0l-5 5m5-5H6"/></svg>
                </button>
            </form>
        </div>

        <p class="text-center text-xs text-slate-500 mt-6">
            Restricted area · authorised ITFA maintenance staff only ·
            <a href="{{ route('login') }}" class="text-gold-300 hover:underline">Student login →</a>
        </p>
    </div>
</body>
</html>
