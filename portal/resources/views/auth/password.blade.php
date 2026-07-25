<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Change Password | ITFA Student Portal</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { theme: { extend: { fontFamily: { sans: ['Manrope','ui-sans-serif','system-ui'] } } } };</script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body class="min-h-screen font-sans text-slate-800 antialiased flex items-center justify-center p-4"
      style="background:linear-gradient(135deg,#0a3a1e 0%,#052815 100%)">
    <div class="w-full max-w-md">
        <div class="bg-white rounded-3xl shadow-2xl p-7">
            <h1 class="text-xl font-extrabold">Change your password</h1>
            <p class="text-sm text-slate-500 mt-1">
                @if ($forced) For your security, please replace the default password before continuing. @else Update your login password. @endif
            </p>

            @if (session('error'))
            <div class="mt-4 rounded-xl bg-rose-50 border border-rose-300 text-rose-800 text-sm px-4 py-3">{{ session('error') }}</div>
            @endif

            <form method="POST" action="{{ route('password.update') }}" class="space-y-4 mt-5">
                @csrf
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-1.5">New Password</label>
                    <input type="password" name="new_password" required minlength="6" placeholder="At least 6 characters"
                           class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-1.5">Confirm New Password</label>
                    <input type="password" name="confirm_password" required minlength="6"
                           class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500">
                </div>
                <button class="w-full rounded-xl bg-green-700 hover:bg-green-800 text-white text-sm font-bold py-3">Save Password</button>
            </form>
            @unless ($forced)
            <a href="{{ route('dashboard') }}" class="block text-center text-sm text-slate-500 hover:text-slate-700 mt-4">← Back to dashboard</a>
            @endunless
        </div>
    </div>
</body>
</html>
