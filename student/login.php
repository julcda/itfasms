<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/student_auth.php';

// Already logged in? Go straight to the portal.
if (is_student_logged_in()) {
    redirect_to(app_url('student/index.php'));
}

$db = db();
$sy = student_active_sy($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        flash_set('error', 'Security token mismatch. Please try again.');
        redirect_to(app_url('student/login.php'));
    }
    $lrn      = trim((string) ($_POST['lrn'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    $result = student_login($db, $lrn, $password);
    if ($result['ok']) {
        redirect_to(app_url('student/index.php'));
    }
    flash_set('error', $result['error'] ?? 'Login failed.');
    redirect_to(app_url('student/login.php'));
}

$flash = flash_get();
$csrf  = csrf_token();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Student Portal | ITFA</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: { extend: {
                fontFamily: {
                    sans: ['Inter', 'ui-sans-serif', 'system-ui'],
                    display: ['Poppins', 'ui-sans-serif', 'system-ui']
                },
                colors: {
                    forest: { 50:'#f0f7f2',100:'#dcedde',300:'#86c294',400:'#4aa06a',500:'#2e8b57',600:'#1f7a45',700:'#166534',800:'#0f4d28',900:'#0a3a1e',950:'#052815' },
                    gold:   { 300:'#fde047',400:'#facc15',500:'#eab308',600:'#ca8a04' }
                }
            } }
        };
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Poppins:wght@600;700;800;900&display=swap" rel="stylesheet">
    <style>
        .hero-bg {
            background:
                radial-gradient(ellipse 80% 60% at 60% -10%, rgba(250,204,21,0.16) 0%, transparent 60%),
                radial-gradient(ellipse 50% 40% at 10% 90%, rgba(46,139,87,0.30) 0%, transparent 55%),
                linear-gradient(145deg,#0a3a1e 0%,#0f4d28 35%,#0a3a1e 70%,#052815 100%);
        }
        .grid-overlay {
            background-image:
                linear-gradient(rgba(250,204,21,0.045) 1px, transparent 1px),
                linear-gradient(90deg, rgba(250,204,21,0.045) 1px, transparent 1px);
            background-size: 48px 48px;
        }
        .card-glass { background: rgba(255,255,255,0.97); backdrop-filter: blur(24px); -webkit-backdrop-filter: blur(24px); }
        .input-field {
            width:100%; padding:0.65rem 1rem; border:1.5px solid #d5e3d9; border-radius:0.75rem;
            font-size:0.9rem; color:#0f2a1a; background:#f7faf8; outline:none;
            transition:border-color .2s, box-shadow .2s, background .2s;
        }
        .input-field:focus { border-color:#166534; background:#fff; box-shadow:0 0 0 3px rgba(22,101,52,0.12); }
        .input-field::placeholder { color:#9bb3a3; }
        .btn-primary {
            width:100%; padding:0.75rem 1.5rem; border:none; border-radius:0.75rem; cursor:pointer;
            background:linear-gradient(135deg,#166534 0%,#1f7a45 55%,#2e8b57 100%);
            color:#fff; font-weight:700; font-size:0.95rem; letter-spacing:0.01em;
            box-shadow:0 4px 16px rgba(15,77,40,0.35); transition:transform .15s, box-shadow .15s, filter .15s;
        }
        .btn-primary:hover { transform:translateY(-1px); box-shadow:0 8px 24px rgba(15,77,40,0.45); filter:brightness(1.06); }
        .btn-primary:active { transform:translateY(0); box-shadow:0 4px 12px rgba(15,77,40,0.3); }
        .btn-primary:disabled { opacity:.7; transform:none; }
        .module-card {
            background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.11);
            border-radius: 1rem; padding: 1.1rem;
            transition: background .2s, border-color .2s, transform .2s;
        }
        .module-card:hover { background: rgba(250,204,21,0.07); border-color: rgba(250,204,21,0.30); transform: translateY(-2px); }
        .stat-badge {
            display:inline-flex; align-items:center; gap:0.4rem;
            background:rgba(255,255,255,0.07); border:1px solid rgba(250,204,21,0.22);
            border-radius:9999px; padding:0.35rem 0.85rem; font-size:0.78rem; font-weight:600; color:rgba(255,255,255,0.78);
        }
        .reveal { animation: fadeUp 0.55s ease both; }
        .reveal-1{animation-delay:.05s}.reveal-2{animation-delay:.15s}.reveal-3{animation-delay:.25s}.reveal-4{animation-delay:.35s}.reveal-5{animation-delay:.45s}
        @keyframes fadeUp { from{opacity:0;transform:translateY(18px)} to{opacity:1;transform:translateY(0)} }
        .toggle-pw { position:absolute; right:0.85rem; top:50%; transform:translateY(-50%); background:none; border:none; cursor:pointer; color:#9bb3a3; padding:0.2rem; transition:color .15s; }
        .toggle-pw:hover { color:#166534; }
        .orb-1 { width:320px;height:320px;background:radial-gradient(circle,rgba(250,204,21,0.13) 0%,transparent 70%);border-radius:50%;position:absolute;top:-80px;right:-80px;pointer-events:none; }
        .orb-2 { width:240px;height:240px;background:radial-gradient(circle,rgba(46,139,87,0.20) 0%,transparent 70%);border-radius:50%;position:absolute;bottom:40px;left:-60px;pointer-events:none;animation:float 7s ease-in-out infinite; }
        @keyframes float { 0%,100%{transform:translateY(0)} 50%{transform:translateY(-14px)} }
        @keyframes spin { to { transform: rotate(360deg); } }
    </style>
</head>
<body class="min-h-screen hero-bg text-slate-100 font-sans antialiased">
<div class="grid-overlay min-h-screen flex items-stretch">
<div class="min-h-screen w-full grid lg:grid-cols-[1fr_480px]">

    <!-- ── LEFT PANEL ─────────────────────────────────────── -->
    <section class="relative hidden lg:flex flex-col justify-between p-12 xl:p-16 overflow-hidden border-r border-gold-400/10">
        <div class="orb-1"></div>
        <div class="orb-2"></div>

        <!-- Top: branding -->
        <div class="relative z-10">
            <div class="flex items-center gap-4 mb-8">
                <div class="w-14 h-14 rounded-2xl bg-white flex items-center justify-center shadow-xl shadow-forest-950/50 shrink-0 ring-2 ring-gold-400/40">
                    <img src="<?= h(app_url('itfalogo.png')) ?>" alt="ITFA" class="w-10 h-10 object-contain">
                </div>
                <div>
                    <p class="text-[0.65rem] uppercase tracking-[0.25em] text-gold-400 font-semibold leading-none mb-1">ITFA &mdash; Student Portal</p>
                    <p class="text-base font-extrabold text-white leading-tight">School Management System</p>
                </div>
            </div>

            <!-- School name highlight -->
            <div class="mb-7 rounded-2xl border border-gold-400/15 bg-white/[0.04] p-5 backdrop-blur-sm">
                <p class="text-[0.6rem] uppercase tracking-[0.28em] text-gold-400/80 font-semibold mb-2">Ibn Taimiyah Foundation Academy, Inc.</p>
                <h1 class="font-display text-xl xl:text-2xl font-black leading-tight tracking-tight text-white">
                    Your academic records,<br>
                    <span class="text-gold-400">in one place.</span>
                </h1>
                <div class="mt-3 flex items-center gap-2">
                    <span class="block w-8 h-[2px] rounded-full bg-gradient-to-r from-forest-500 to-gold-400"></span>
                    <p class="text-sm italic text-forest-100/80 font-medium leading-snug">
                        &ldquo;Molding the youth through Dunya-Akhirat Education&rdquo;
                    </p>
                </div>
            </div>

            <div class="space-y-4">
                <div class="flex items-center gap-2">
                    <span class="stat-badge">
                        <span class="w-1.5 h-1.5 rounded-full bg-forest-400"></span>
                        Portal Online
                    </span>
                    <span class="stat-badge">S.Y. <?= h($sy['label'] ?: '—') ?></span>
                </div>
                <p class="text-forest-100/65 text-sm leading-relaxed max-w-sm">
                    View your grades, statement of account, and certificates &mdash; anytime, from any device.
                </p>
            </div>
        </div>

        <!-- Middle: what students can access -->
        <div class="relative z-10 space-y-2.5">
            <p class="text-[0.7rem] uppercase tracking-[0.22em] text-gold-400/60 font-semibold mb-3">In your portal</p>
            <div class="grid grid-cols-2 gap-2.5">
                <?php
                $items = [
                    ['Grades',       'M12 14l9-5-9-5-9 5 9 5zm0 0l6.16-3.42a12 12 0 01.84 4.42c0 1.1-.9 2-2 2H7c-1.1 0-2-.9-2-2 0-1.55.3-3.04.84-4.42L12 14z'],
                    ['Certificates', 'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z'],
                    ['Statement of Account', 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z'],
                    ['Account Settings', 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065zM15 12a3 3 0 11-6 0 3 3 0 016 0z'],
                ];
                foreach ($items as $it): ?>
                <div class="module-card">
                    <div class="w-8 h-8 rounded-lg bg-gold-400/12 flex items-center justify-center mb-2.5">
                        <svg class="w-4 h-4 text-gold-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="<?= $it[1] ?>"/></svg>
                    </div>
                    <p class="text-sm font-semibold text-white leading-tight"><?= h($it[0]) ?></p>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Bottom -->
        <div class="relative z-10">
            <p class="text-xs text-forest-100/45">&copy; <?= date('Y') ?> ITFA &mdash; All rights reserved.</p>
        </div>
    </section>

    <!-- ── RIGHT PANEL (Login) ────────────────────────────── -->
    <section class="relative flex items-center justify-center p-6 sm:p-10 bg-forest-950/45">
        <div class="w-full max-w-sm">

            <!-- Mobile logo + name -->
            <div class="flex lg:hidden flex-col gap-4 mb-7 reveal reveal-1">
                <div class="flex items-center gap-3">
                    <div class="w-11 h-11 rounded-xl bg-white flex items-center justify-center shadow-lg shadow-forest-950/40 shrink-0 ring-2 ring-gold-400/40">
                        <img src="<?= h(app_url('itfalogo.png')) ?>" alt="ITFA" class="w-7 h-7 object-contain">
                    </div>
                    <div>
                        <p class="text-[0.6rem] uppercase tracking-[0.22em] text-gold-400 font-semibold leading-none mb-0.5">ITFA &mdash; Student Portal</p>
                        <p class="font-display text-sm font-bold text-white leading-snug">School Management System</p>
                    </div>
                </div>
            </div>

            <!-- Login card -->
            <div class="card-glass rounded-2xl shadow-2xl shadow-forest-950/50 border border-white/10 overflow-hidden reveal reveal-2">
                <div class="h-1 w-full bg-gradient-to-r from-forest-700 via-gold-400 to-forest-600"></div>

                <div class="p-7 sm:p-8">
                    <div class="mb-6 reveal reveal-3">
                        <div class="flex items-center gap-2 mb-3">
                            <span class="inline-flex items-center gap-1.5 text-[0.68rem] font-semibold uppercase tracking-[0.18em] text-forest-700 bg-forest-50 border border-forest-100 rounded-full px-2.5 py-0.5">
                                <span class="w-1.5 h-1.5 rounded-full bg-forest-600"></span>
                                Student Sign-in
                            </span>
                        </div>
                        <h2 class="font-display text-2xl font-black text-forest-900 leading-tight">Welcome back</h2>
                        <p class="text-forest-800/60 text-sm mt-1.5">Sign in with your LRN to continue.</p>
                    </div>

                    <?php if ($flash): ?>
                    <div class="mb-5 rounded-xl border px-4 py-3 text-sm flex items-start gap-2.5 reveal reveal-3
                        <?= $flash['type']==='success' ? 'bg-forest-50 border-forest-200 text-forest-800' : 'bg-rose-50 border-rose-200 text-rose-800' ?>">
                        <?php if ($flash['type']==='success'): ?>
                        <svg class="w-4 h-4 mt-0.5 shrink-0 text-forest-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        <?php else: ?>
                        <svg class="w-4 h-4 mt-0.5 shrink-0 text-rose-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                        <?php endif; ?>
                        <span><?= h($flash['message']) ?></span>
                    </div>
                    <?php endif; ?>

                    <form method="POST" action="login.php" class="space-y-4" id="loginForm">
                        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">

                        <div class="reveal reveal-4">
                            <label class="block text-sm font-semibold text-forest-900 mb-1.5" for="lrn">LRN Number</label>
                            <div class="relative">
                                <span class="absolute left-3 top-1/2 -translate-y-1/2 text-forest-400 pointer-events-none">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10 6H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V8a2 2 0 00-2-2h-5m-4 0V5a2 2 0 114 0v1m-4 0h4"/></svg>
                                </span>
                                <input type="text" id="lrn" name="lrn" required autofocus inputmode="numeric"
                                       placeholder="Enter your LRN" class="input-field pl-9">
                            </div>
                        </div>

                        <div class="reveal reveal-4">
                            <label class="block text-sm font-semibold text-forest-900 mb-1.5" for="password">Password</label>
                            <div class="relative">
                                <span class="absolute left-3 top-1/2 -translate-y-1/2 text-forest-400 pointer-events-none">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                                </span>
                                <input type="password" id="password" name="password" required
                                       placeholder="Enter your password" class="input-field pl-9 pr-11">
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
                                <span id="btnText">Sign In</span>
                            </button>
                        </div>
                    </form>

                    <p class="text-xs text-forest-800/45 mt-4 reveal reveal-5">
                        First time signing in? Your default password is
                        <span class="font-mono font-semibold text-forest-700">password</span> &mdash; you&rsquo;ll be asked to change it.
                    </p>
                </div>
            </div>

            <p class="text-center text-forest-100/50 text-xs mt-5 reveal reveal-5">
                Staff or teacher? <a href="<?= h(app_url('login.php')) ?>" class="text-gold-300 font-semibold hover:text-gold-400">Staff login</a>
            </p>
        </div>
    </section>

</div>
</div>

<script>
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
        btn.disabled = true;
        btn.style.opacity = '0.85';
        document.getElementById('btnText').innerHTML =
            `<span style="display:inline-flex;align-items:center;gap:0.5rem;justify-content:center">
                <svg style="width:1rem;height:1rem;animation:spin .8s linear infinite" fill="none" viewBox="0 0 24 24">
                    <circle style="opacity:.3" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3"/>
                    <path style="opacity:.9" d="M4 12a8 8 0 018-8" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                </svg> Signing in…</span>`;
    });
</script>
</body>
</html>
