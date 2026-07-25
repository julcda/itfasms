<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

if (is_logged_in()) {
    redirect_to(app_url(user_home_path(current_user())));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        flash_set('error', 'Security token mismatch. Please try again.');
        redirect_to(app_url('login.php'));
    }

    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    $user = authenticate_user($username, $password);
    if ($user === null) {
        flash_set('error', 'Invalid username or password.');
        redirect_to(app_url('login.php'));
    }

    login_user($user);
    flash_set('success', 'Welcome back, ' . $user['full_name'] . '.');
    redirect_to(app_url(user_home_path($user)));
}

$flash = flash_get();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>I-SMS | ITFA School Management System</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Inter', 'ui-sans-serif', 'system-ui'],
                        display: ['Poppins', 'ui-sans-serif', 'system-ui']
                    },
                    colors: {
                        forest: {
                            50:  '#f0f7f2',
                            100: '#dcedde',
                            300: '#86c294',
                            500: '#2e8b57',
                            600: '#1f7a45',
                            700: '#166534',
                            800: '#0f4d28',
                            900: '#0a3a1e',
                            950: '#052815'
                        },
                        gold: {
                            300: '#fde047',
                            400: '#facc15',
                            500: '#eab308',
                            600: '#ca8a04'
                        }
                    }
                }
            }
        };
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Poppins:wght@600;700;800;900&display=swap" rel="stylesheet">
    <style>
        .hero-bg {
            background:
                radial-gradient(ellipse 70% 55% at 75% -8%, rgba(250,204,21,0.20) 0%, transparent 55%),
                radial-gradient(ellipse 55% 45% at 5% 100%, rgba(46,139,87,0.35) 0%, transparent 55%),
                linear-gradient(150deg, #0a3a1e 0%, #0f4d28 40%, #0a3a1e 72%, #052815 100%);
        }
        .grid-overlay {
            background-image:
                linear-gradient(rgba(250,204,21,0.05) 1px, transparent 1px),
                linear-gradient(90deg, rgba(250,204,21,0.05) 1px, transparent 1px);
            background-size: 46px 46px;
        }
        .card-glass {
            background: rgba(255,255,255,0.98);
            backdrop-filter: blur(22px);
            -webkit-backdrop-filter: blur(22px);
        }
        .input-field {
            width: 100%;
            padding: 0.7rem 1rem;
            border: 1.5px solid #d5e3d9;
            border-radius: 0.75rem;
            font-size: 0.9rem;
            color: #0f2a1a;
            background: #f6faf7;
            transition: border-color 0.2s, box-shadow 0.2s, background 0.2s;
            outline: none;
        }
        .input-field:focus {
            border-color: #166534;
            background: #fff;
            box-shadow: 0 0 0 3px rgba(22,101,52,0.14);
        }
        .input-field::placeholder { color: #94ad9d; }
        .btn-primary {
            width: 100%;
            padding: 0.8rem 1.5rem;
            background: linear-gradient(135deg, #166534 0%, #1f7a45 55%, #2e8b57 100%);
            color: #fff;
            font-weight: 800;
            font-size: 0.95rem;
            border-radius: 0.75rem;
            border: none;
            cursor: pointer;
            transition: transform 0.15s, box-shadow 0.15s, filter 0.15s;
            box-shadow: 0 6px 18px rgba(15,77,40,0.4);
            letter-spacing: 0.02em;
        }
        .btn-primary:hover { transform: translateY(-1px); box-shadow: 0 10px 26px rgba(15,77,40,0.5); filter: brightness(1.07); }
        .btn-primary:active { transform: translateY(0); box-shadow: 0 4px 12px rgba(15,77,40,0.35); }
        .gold-underline { position: relative; }
        .gold-underline::after {
            content: ''; position: absolute; left: 0; bottom: -6px; width: 54px; height: 4px;
            border-radius: 999px; background: linear-gradient(90deg, #facc15, #eab308);
        }
        .module-card {
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.10);
            border-radius: 0.9rem;
            padding: 0.85rem 0.95rem;
            transition: background 0.2s, border-color 0.2s, transform 0.2s;
        }
        .module-card:hover { background: rgba(250,204,21,0.08); border-color: rgba(250,204,21,0.35); transform: translateY(-2px); }
        .stat-badge {
            display: inline-flex; align-items: center; gap: 0.4rem;
            background: rgba(255,255,255,0.07); border: 1px solid rgba(250,204,21,0.25);
            border-radius: 9999px; padding: 0.35rem 0.85rem; font-size: 0.76rem;
            font-weight: 600; color: rgba(255,255,255,0.82);
        }
        .reveal { animation: fadeUp 0.55s ease both; }
        .reveal-1 { animation-delay: 0.05s; } .reveal-2 { animation-delay: 0.15s; }
        .reveal-3 { animation-delay: 0.25s; } .reveal-4 { animation-delay: 0.35s; }
        .reveal-5 { animation-delay: 0.45s; }
        @keyframes fadeUp { from { opacity: 0; transform: translateY(18px); } to { opacity: 1; transform: translateY(0); } }
        .toggle-pw { position: absolute; right: 0.85rem; top: 50%; transform: translateY(-50%);
            background: none; border: none; cursor: pointer; color: #94ad9d; padding: 0.2rem; transition: color 0.15s; }
        .toggle-pw:hover { color: #166534; }
        .orb-gold { width: 300px; height: 300px; background: radial-gradient(circle, rgba(250,204,21,0.16) 0%, transparent 70%);
            border-radius: 50%; position: absolute; top: -70px; right: -70px; pointer-events: none; }
        .orb-green { width: 240px; height: 240px; background: radial-gradient(circle, rgba(46,139,87,0.22) 0%, transparent 70%);
            border-radius: 50%; position: absolute; bottom: 40px; left: -60px; pointer-events: none; animation: float 7s ease-in-out infinite; }
        @keyframes float { 0%,100% { transform: translateY(0); } 50% { transform: translateY(-14px); } }
        .brand-glow { filter: drop-shadow(0 0 22px rgba(46,139,87,0.5)); }
        .logo-badge { animation: float 7s ease-in-out infinite; }
        .ring-pulse::before, .ring-pulse::after { content:''; position:absolute; inset:-8px; border-radius:9999px; }
        .ring-pulse::before { border:1.5px solid rgba(250,204,21,0.4); animation: ringpulse 3.2s ease-out infinite; }
        .ring-pulse::after  { border:1.5px solid rgba(46,139,87,0.45); animation: ringpulse 3.2s ease-out infinite 1.6s; }
        @keyframes ringpulse { 0% { transform: scale(1); opacity:.85; } 100% { transform: scale(1.5); opacity:0; } }
        .title-shine {
            background: linear-gradient(100deg,#ffffff 15%,#86c294 38%,#facc15 58%,#ffffff 82%);
            background-size: 200% auto; -webkit-background-clip: text; background-clip: text; color: transparent;
            animation: shine 6s linear infinite;
        }
        @keyframes shine { to { background-position: 200% center; } }
        @media (prefers-reduced-motion: reduce) {
            .title-shine, .logo-badge, .orb-green { animation: none; }
            .ring-pulse::before, .ring-pulse::after { animation: none; opacity: 0; }
        }
    </style>
</head>
<body class="min-h-screen hero-bg text-white font-sans antialiased">
<canvas id="net" class="fixed inset-0 w-full h-full" style="z-index:0" aria-hidden="true"></canvas>
<div class="grid-overlay min-h-screen flex items-stretch relative" style="z-index:10">
<div class="min-h-screen w-full grid lg:grid-cols-[1fr_480px]">

    <!-- ── LEFT PANEL ─────────────────────────────────────── -->
    <section class="relative hidden lg:flex flex-col justify-between p-12 xl:p-16 overflow-hidden border-r border-gold-400/10">
        <div class="orb-gold"></div>
        <div class="orb-green"></div>

        <!-- Top: branding -->
        <div class="relative z-10">
            <div class="flex items-center gap-5 mb-9">
                <div class="ring-pulse logo-badge brand-glow relative w-24 h-24 xl:w-28 xl:h-28 rounded-3xl bg-white flex items-center justify-center shadow-xl shadow-forest-950/50 shrink-0 ring-2 ring-gold-400/50">
                    <img src="<?= h(app_url('itfalogo.png')) ?>" alt="ITFA" class="w-16 h-16 xl:w-20 xl:h-20 object-contain">
                </div>
                <div>
                    <p class="text-[0.65rem] uppercase tracking-[0.25em] text-gold-400 font-bold leading-none mb-1.5">ITFA &mdash; I-SMS</p>
                    <p class="text-lg font-extrabold text-white leading-tight">School Management System</p>
                </div>
            </div>

            <!-- School name highlight -->
            <div class="mb-8 rounded-2xl border border-gold-400/20 bg-white/[0.04] p-6 backdrop-blur-sm">
                <p class="text-[0.6rem] uppercase tracking-[0.24em] text-gold-400/90 font-bold mb-3 leading-relaxed">Ministry of Basic, Higher and Technical Education (MBHTE)</p>
                <h1 class="font-display text-3xl xl:text-4xl font-black leading-[1.05] tracking-tight gold-underline inline-block">
                    <span class="title-shine">IBN TAIMIYAH</span><br>
                    <span class="text-gold-400">FOUNDATION ACADEMY,</span> <span class="text-white">INC.</span>
                </h1>
                <p class="mt-6 text-sm italic text-forest-100/90 font-medium leading-snug">
                    &ldquo;Molding the youth through Dunya-Akhirat Education&rdquo;
                </p>
            </div>

            <div class="space-y-4">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="stat-badge">
                        <span class="w-1.5 h-1.5 rounded-full bg-gold-400"></span>
                        System Online
                    </span>
                    <span class="stat-badge">A.Y. 2026&ndash;2027</span>
                </div>
                <p class="text-forest-100/70 text-sm leading-relaxed max-w-sm">
                    One integrated platform for admission, enrollment, scheduling, examination,
                    faculty, and financial management.
                </p>
            </div>
        </div>

        <!-- Middle: module cards -->
        <div class="relative z-10 space-y-3 mt-8">
            <p class="text-[0.7rem] uppercase tracking-[0.22em] text-gold-400/70 font-bold mb-3">Modules</p>
            <div class="grid grid-cols-2 gap-3">
                <?php
                $modules = [
                    ['Admission',   'M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z'],
                    ['Enrollment',  'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2'],
                    ['Scheduling',  'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z'],
                    ['Examination', 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z'],
                    ['Faculty & Grades', 'M12 14l9-5-9-5-9 5 9 5zm0 0l6.16-3.422a12.083 12.083 0 01.665 6.479A11.952 11.952 0 0012 20.055a11.952 11.952 0 00-6.824-2.998 12.078 12.078 0 01.665-6.479L12 14z'],
                    ['Cashier & Finance', 'M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z'],
                ];
                foreach ($modules as $m): ?>
                <div class="module-card">
                    <div class="w-8 h-8 rounded-lg bg-gold-400/15 flex items-center justify-center mb-2.5">
                        <svg class="w-4 h-4 text-gold-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="<?= $m[1] ?>"/></svg>
                    </div>
                    <p class="text-sm font-semibold text-white leading-tight"><?= h($m[0]) ?></p>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Bottom: footer -->
        <div class="relative z-10">
            <p class="text-xs text-forest-100/50">&copy; <?= date('Y') ?> ITFA &mdash; All rights reserved.</p>
        </div>
    </section>

    <!-- ── RIGHT PANEL (Login Form) ───────────────────────── -->
    <section class="relative flex items-center justify-center p-6 sm:p-10 bg-forest-950/50">
        <div class="w-full max-w-sm">

            <!-- Mobile logo + school name -->
            <div class="flex lg:hidden flex-col items-center text-center gap-4 mb-7 reveal reveal-1">
                <div class="ring-pulse logo-badge brand-glow relative w-24 h-24 rounded-3xl bg-white flex items-center justify-center shadow-lg shadow-forest-950/40 shrink-0 ring-2 ring-gold-400/50">
                    <img src="<?= h(app_url('itfalogo.png')) ?>" alt="ITFA" class="w-16 h-16 object-contain">
                </div>
                <div>
                    <p class="text-[0.55rem] uppercase tracking-[0.2em] text-gold-400/90 font-bold leading-relaxed mb-1.5">Ministry of Basic, Higher and Technical Education (MBHTE)</p>
                    <p class="font-display text-lg font-black leading-tight">
                        <span class="title-shine">IBN TAIMIYAH</span> <span class="text-gold-400">FOUNDATION ACADEMY,</span> <span class="text-white">INC.</span>
                    </p>
                    <p class="text-xs italic text-forest-100/70 mt-1.5">&ldquo;Molding the youth through Dunya-Akhirat Education&rdquo;</p>
                </div>
            </div>

            <!-- Login card -->
            <div class="card-glass rounded-2xl shadow-2xl shadow-forest-950/50 border border-white/10 overflow-hidden reveal reveal-2">
                <div class="h-1.5 w-full bg-gradient-to-r from-forest-700 via-gold-400 to-forest-600"></div>

                <div class="p-7 sm:p-8">
                    <div class="mb-6 reveal reveal-3">
                        <div class="flex items-center gap-2 mb-3">
                            <span class="inline-flex items-center gap-1.5 text-[0.68rem] font-bold uppercase tracking-[0.18em] text-forest-700 bg-forest-50 border border-forest-100 rounded-full px-2.5 py-0.5">
                                <span class="w-1.5 h-1.5 rounded-full bg-forest-600"></span>
                                Secure Portal
                            </span>
                        </div>
                        <h2 class="font-display text-2xl font-black text-forest-900 leading-tight">Welcome back</h2>
                        <p class="text-forest-800/60 text-sm mt-1.5">Sign in with your staff credentials to continue.</p>
                    </div>

                    <!-- Flash message -->
                    <?php if ($flash): ?>
                    <div class="mb-5 rounded-xl border px-4 py-3 text-sm flex items-start gap-2.5 reveal reveal-3
                        <?= $flash['type'] === 'success'
                            ? 'bg-forest-50 border-forest-200 text-forest-800'
                            : 'bg-rose-50 border-rose-200 text-rose-800' ?>">
                        <?php if ($flash['type'] === 'success'): ?>
                        <svg class="w-4 h-4 mt-0.5 shrink-0 text-forest-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        <?php else: ?>
                        <svg class="w-4 h-4 mt-0.5 shrink-0 text-rose-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                        <?php endif; ?>
                        <span><?= h($flash['message']) ?></span>
                    </div>
                    <?php endif; ?>

                    <!-- Form -->
                    <form method="post" class="space-y-4" id="loginForm">
                        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">

                        <div class="reveal reveal-4">
                            <label class="block text-sm font-semibold text-forest-900 mb-1.5" for="username">Username</label>
                            <div class="relative">
                                <span class="absolute left-3 top-1/2 -translate-y-1/2 text-forest-400 pointer-events-none">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                                </span>
                                <input type="text" id="username" name="username" required autofocus placeholder="Enter your username" class="input-field pl-9">
                            </div>
                        </div>

                        <div class="reveal reveal-4">
                            <label class="block text-sm font-semibold text-forest-900 mb-1.5" for="password">Password</label>
                            <div class="relative">
                                <span class="absolute left-3 top-1/2 -translate-y-1/2 text-forest-400 pointer-events-none">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                                </span>
                                <input type="password" id="password" name="password" required placeholder="Enter your password" class="input-field pl-9 pr-11">
                                <button type="button" class="toggle-pw" id="togglePw" aria-label="Toggle password visibility" tabindex="-1">
                                    <svg id="eyeIcon" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                    </svg>
                                </button>
                            </div>
                        </div>

                        <div class="pt-1 reveal reveal-5">
                            <button type="submit" class="btn-primary" id="submitBtn">
                                <span id="btnLabel">Sign In</span>
                            </button>
                        </div>
                    </form>

                    <!-- Student portal link -->
                    <div class="mt-6 pt-5 border-t border-forest-100 reveal reveal-5">
                        <a href="<?= h(app_url('student/login.php')) ?>"
                           class="flex items-center justify-between gap-3 rounded-xl border border-gold-500/40 bg-gold-400/10 hover:bg-gold-400/20 px-4 py-3 transition-colors group">
                            <span class="flex items-center gap-3">
                                <span class="w-9 h-9 rounded-lg bg-forest-700 flex items-center justify-center shrink-0">
                                    <svg class="w-5 h-5 text-gold-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 14l9-5-9-5-9 5 9 5z"/><path stroke-linecap="round" stroke-linejoin="round" d="M12 14l6.16-3.422a12.083 12.083 0 01.665 6.479A11.952 11.952 0 0012 20.055a11.952 11.952 0 00-6.824-2.998 12.078 12.078 0 01.665-6.479L12 14z"/></svg>
                                </span>
                                <span>
                                    <span class="block text-sm font-bold text-forest-900 leading-tight">Student Portal</span>
                                    <span class="block text-xs text-forest-800/60">Log in with your LRN</span>
                                </span>
                            </span>
                            <svg class="w-4 h-4 text-forest-600 group-hover:translate-x-0.5 transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                        </a>
                    </div>
                </div>
            </div>

            <p class="text-center text-forest-100/50 text-xs mt-5 reveal reveal-5">
                &copy; <?= date('Y') ?> ITFA &mdash; I-SMS. For authorized personnel only.
            </p>
        </div>
    </section>

</div>
</div>

<script>
    // Interactive constellation background (mouse-reactive). Disabled under reduced-motion.
    (function () {
        var canvas = document.getElementById('net');
        if (!canvas || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
        var ctx = canvas.getContext('2d'), w, h, dpr = Math.min(window.devicePixelRatio || 1, 2);
        var nodes = [], mouse = { x: -999, y: -999 };
        var COLORS = ['rgba(134,194,148,', 'rgba(250,204,21,', 'rgba(94,181,124,'];
        function resize() {
            w = canvas.width = innerWidth * dpr; h = canvas.height = innerHeight * dpr;
            canvas.style.width = innerWidth + 'px'; canvas.style.height = innerHeight + 'px';
            var count = Math.min(85, Math.floor(innerWidth * innerHeight / 17000));
            nodes = [];
            for (var i = 0; i < count; i++) nodes.push({
                x: Math.random() * w, y: Math.random() * h,
                vx: (Math.random() - 0.5) * 0.4 * dpr, vy: (Math.random() - 0.5) * 0.4 * dpr,
                r: (Math.random() * 1.5 + 0.8) * dpr, c: COLORS[i % COLORS.length]
            });
        }
        function step() {
            ctx.clearRect(0, 0, w, h);
            var maxD = 130 * dpr, mMax = 175 * dpr;
            for (var i = 0; i < nodes.length; i++) {
                var n = nodes[i];
                n.x += n.vx; n.y += n.vy;
                if (n.x < 0 || n.x > w) n.vx *= -1;
                if (n.y < 0 || n.y > h) n.vy *= -1;
                var mdx = mouse.x - n.x, mdy = mouse.y - n.y, md = Math.hypot(mdx, mdy);
                if (md < mMax && md > 0) {
                    n.x += mdx / md * 0.5; n.y += mdy / md * 0.5;
                    ctx.beginPath(); ctx.moveTo(n.x, n.y); ctx.lineTo(mouse.x, mouse.y);
                    ctx.strokeStyle = 'rgba(250,204,21,' + (0.18 * (1 - md / mMax)) + ')';
                    ctx.lineWidth = dpr; ctx.stroke();
                }
                ctx.beginPath(); ctx.arc(n.x, n.y, n.r, 0, 6.283); ctx.fillStyle = n.c + '0.75)'; ctx.fill();
                for (var j = i + 1; j < nodes.length; j++) {
                    var m = nodes[j], dx = n.x - m.x, dy = n.y - m.y, d = Math.hypot(dx, dy);
                    if (d < maxD) {
                        ctx.beginPath(); ctx.moveTo(n.x, n.y); ctx.lineTo(m.x, m.y);
                        ctx.strokeStyle = 'rgba(134,194,148,' + (0.13 * (1 - d / maxD)) + ')';
                        ctx.lineWidth = dpr; ctx.stroke();
                    }
                }
            }
            requestAnimationFrame(step);
        }
        addEventListener('resize', resize);
        addEventListener('mousemove', function (e) { mouse.x = e.clientX * dpr; mouse.y = e.clientY * dpr; });
        addEventListener('mouseleave', function () { mouse.x = mouse.y = -999; });
        addEventListener('touchmove', function (e) { if (e.touches[0]) { mouse.x = e.touches[0].clientX * dpr; mouse.y = e.touches[0].clientY * dpr; } }, { passive: true });
        resize(); step();
    })();

    const togglePw = document.getElementById('togglePw');
    const pwField  = document.getElementById('password');
    const eyeIcon  = document.getElementById('eyeIcon');
    const eyeOffSvg = `<path stroke-linecap="round" stroke-linejoin="round" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/>`;
    const eyeOnSvg = `<path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>`;

    togglePw.addEventListener('click', () => {
        const show = pwField.type === 'password';
        pwField.type = show ? 'text' : 'password';
        eyeIcon.innerHTML = show ? eyeOffSvg : eyeOnSvg;
    });

    document.getElementById('loginForm').addEventListener('submit', function () {
        const btn = document.getElementById('submitBtn');
        const lbl = document.getElementById('btnLabel');
        btn.disabled = true;
        btn.style.opacity = '0.85';
        lbl.innerHTML = `<span style="display:inline-flex;align-items:center;gap:0.5rem;justify-content:center">
            <svg style="width:1rem;height:1rem;animation:spin 0.8s linear infinite" fill="none" viewBox="0 0 24 24">
                <circle style="opacity:0.3" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3"/>
                <path style="opacity:0.9" d="M4 12a8 8 0 018-8" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
            </svg> Signing in…</span>`;
    });
</script>
<style>@keyframes spin{to{transform:rotate(360deg)}}</style>
</body>
</html>
