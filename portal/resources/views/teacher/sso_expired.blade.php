<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Link Expired | ITFA Classroom</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { theme: { extend: { fontFamily: { sans: ['Manrope','ui-sans-serif','system-ui'] } } } };</script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body class="min-h-screen font-sans text-slate-800 antialiased flex items-center justify-center p-4"
      style="background:linear-gradient(135deg,#0f4d28 0%,#052815 100%)">
    <div class="w-full max-w-md bg-white rounded-3xl shadow-2xl p-8 text-center">
        <p class="text-5xl mb-3">⏳</p>
        <h1 class="text-xl font-extrabold text-slate-800">This link has expired</h1>
        <p class="text-sm text-slate-500 mt-2">Classroom links are single-use and only valid for a minute. Please return to your Teacher Dashboard and click <b>Classroom</b> again.</p>
        <a href="{{ rtrim(config('portal.app_base_url'), '/') }}/teacher/index.php"
           class="mt-6 inline-block rounded-xl bg-green-700 hover:bg-green-800 text-white text-sm font-bold px-6 py-3">
            ← Back to Teacher Dashboard
        </a>
    </div>
</body>
</html>
