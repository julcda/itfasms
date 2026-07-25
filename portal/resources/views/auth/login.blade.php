<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>IBN TAIMIYAH FOUNDATION ACADEMY, INC. — Student Portal</title>
    @include('partials.head')
    <style>
        .brand-glow{filter:drop-shadow(0 0 24px rgba(16,185,129,.45))}
        .ring-pulse::before{content:'';position:absolute;inset:-10px;border-radius:9999px;border:1.5px solid rgba(224,149,31,.35);animation:ringpulse 3.2s ease-out infinite}
        .ring-pulse::after{content:'';position:absolute;inset:-10px;border-radius:9999px;border:1.5px solid rgba(16,185,129,.35);animation:ringpulse 3.2s ease-out infinite 1.6s}
        @keyframes ringpulse{0%{transform:scale(1);opacity:.8}100%{transform:scale(1.5);opacity:0}}
        .title-shine{background:linear-gradient(100deg,#eafff3 15%,#7ff0b4 40%,#eec861 60%,#eafff3 85%);background-size:200% auto;-webkit-background-clip:text;background-clip:text;color:transparent;animation:shine 6s linear infinite}
        @keyframes shine{to{background-position:200% center}}
        @media (prefers-reduced-motion: reduce){.title-shine{animation:none}.ring-pulse::before,.ring-pulse::after{animation:none;opacity:0}}
    </style>
</head>
<body class="min-h-screen font-sans text-white antialiased overflow-x-hidden" style="background:radial-gradient(80rem 60rem at 20% -10%,#12613a 0%,#0a3a1e 45%,#04200f 100%)">

    {{-- Interactive constellation canvas --}}
    <canvas id="net" class="fixed inset-0 w-full h-full -z-0" aria-hidden="true"></canvas>
    {{-- Islamic geometric watermark --}}
    <div class="fixed inset-0 -z-0 opacity-[0.05] pointer-events-none" aria-hidden="true"
         style="background-image:url('data:image/svg+xml;utf8,<svg xmlns=%22http://www.w3.org/2000/svg%22 width=%22120%22 height=%22120%22 viewBox=%220 0 120 120%22><g fill=%22none%22 stroke=%22%23ffffff%22 stroke-width=%221%22><path d=%22M60 5l15 40 40 15-40 15-15 40-15-40-40-15 40-15z%22/><circle cx=%2260%22 cy=%2260%22 r=%2228%22/></g></svg>');background-size:120px 120px"></div>

    <main class="relative z-10 min-h-screen flex flex-col items-center justify-center px-5 py-10">
        <div class="w-full max-w-lg text-center animate-enter">

            {{-- Logo --}}
            <div class="relative inline-flex mb-6">
                <div class="ring-pulse relative w-32 h-32 sm:w-40 sm:h-40 rounded-full bg-white/8 ring-1 ring-white/20 backdrop-blur flex items-center justify-center animate-floaty brand-glow">
                    <img src="{{ rtrim(config('portal.app_base_url'), '/') }}/itfalogo.png" alt="ITFA logo"
                         class="w-24 h-24 sm:w-32 sm:h-32 object-contain" onerror="this.style.display='none'">
                </div>
            </div>

            {{-- Academy name — the highlight --}}
            <p class="text-[11px] sm:text-xs uppercase tracking-[0.28em] text-gold-300 font-bold mb-2">Ministry of Basic, Higher and Technical Education (MBHTE)</p>
            <h1 class="font-display font-extrabold leading-[1.05] text-balance">
                <span class="block text-3xl sm:text-5xl title-shine">IBN TAIMIYAH</span>
                <span class="block text-xl sm:text-3xl mt-1 text-white/95 tracking-tight">FOUNDATION ACADEMY, <span class="text-gold-300">INC.</span></span>
            </h1>
            <div class="mx-auto mt-4 h-px w-40 bg-gradient-to-r from-transparent via-gold-400/70 to-transparent"></div>

            {{-- Rotating tagline --}}
            <div class="h-6 mt-4" x-data="{ i:0, show:true, lines:['“Molding the youth through Dunya–Akhirat Education”','One portal for lessons, grades, and records','Your online classroom, always within reach'] }"
                 x-init="setInterval(()=>{ show=false; setTimeout(()=>{ i=(i+1)%lines.length; show=true }, 350) }, 4000)">
                <p x-show="show" x-transition:enter="transition ease-out duration-500" x-transition:enter-start="opacity-0 translate-y-1" x-transition:leave="transition ease-in duration-300" x-transition:leave-end="opacity-0"
                   class="text-sm sm:text-base text-emerald-100/80" x-text="lines[i]"></p>
            </div>

            {{-- Sign-in card --}}
            <div class="glass mt-8 rounded-3xl border border-white/15 shadow-2xl p-6 sm:p-8 text-left">
                <div class="flex items-center justify-between mb-5">
                    <div>
                        <h2 class="text-lg font-extrabold font-display text-white">Student Portal</h2>
                        <p class="text-xs text-emerald-100/60 mt-0.5">Sign in with your LRN to continue</p>
                    </div>
                    <span class="text-[10px] uppercase tracking-wide bg-gold-400/20 text-gold-200 px-2 py-1 rounded-lg font-bold ring-1 ring-gold-300/20">Secure</span>
                </div>

                @if (session('error'))
                <div role="alert" class="mb-4 rounded-2xl bg-rose-500/15 border border-rose-400/30 text-rose-100 text-sm px-4 py-3">{{ session('error') }}</div>
                @endif
                @if (session('success'))
                <div role="status" class="mb-4 rounded-2xl bg-emerald-500/15 border border-emerald-400/30 text-emerald-100 text-sm px-4 py-3">{{ session('success') }}</div>
                @endif

                <form method="POST" action="{{ route('login.attempt') }}" x-data="{ show:false, busy:false }" @submit="busy=true" class="space-y-4">
                    @csrf
                    <div>
                        <label for="lrn" class="block text-xs font-semibold text-emerald-100/80 mb-1.5">Learner Reference No. (LRN)</label>
                        <div class="relative group">
                            <svg class="w-5 h-5 text-emerald-200/50 group-focus-within:text-gold-300 transition-colors absolute left-3.5 top-1/2 -translate-y-1/2" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M10 6H5a2 2 0 00-2 2v10a2 2 0 002 2h14a2 2 0 002-2V8a2 2 0 00-2-2h-5m-4 0V4a2 2 0 114 0v2m-4 0h4"/></svg>
                            <input id="lrn" type="text" name="lrn" value="{{ old('lrn') }}" required autofocus inputmode="numeric" placeholder="12-digit LRN"
                                   class="w-full rounded-2xl border border-white/15 bg-white/5 text-white placeholder-white/30 pl-11 pr-4 py-3 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-gold-400/70 focus:border-transparent transition">
                        </div>
                    </div>
                    <div>
                        <label for="password" class="block text-xs font-semibold text-emerald-100/80 mb-1.5">Password</label>
                        <div class="relative group">
                            <svg class="w-5 h-5 text-emerald-200/50 group-focus-within:text-gold-300 transition-colors absolute left-3.5 top-1/2 -translate-y-1/2" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-12V7a4 4 0 00-8 0v4h8z"/></svg>
                            <input id="password" :type="show ? 'text' : 'password'" name="password" required placeholder="Your password"
                                   class="w-full rounded-2xl border border-white/15 bg-white/5 text-white placeholder-white/30 pl-11 pr-11 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-gold-400/70 focus:border-transparent transition">
                            <button type="button" @click="show=!show" class="absolute right-3 top-1/2 -translate-y-1/2 text-emerald-200/50 hover:text-white" :aria-label="show ? 'Hide password' : 'Show password'">
                                <svg x-show="!show" class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                <svg x-show="show" x-cloak class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59M21 21l-3.59-3.59"/></svg>
                            </button>
                        </div>
                        <p class="text-[11px] text-emerald-100/50 mt-2">First time here? Use the default password <b class="text-emerald-100/80">password</b> — you'll be asked to change it.</p>
                    </div>
                    <button type="submit" :disabled="busy"
                            class="w-full rounded-2xl bg-gradient-to-r from-gold-500 to-gold-600 hover:from-gold-400 hover:to-gold-500 text-[#04200f] text-sm font-extrabold py-3.5 shadow-lg disabled:opacity-70 transition-all inline-flex items-center justify-center gap-2 focus:outline-none focus-visible:ring-2 focus-visible:ring-white/70">
                        <svg x-show="busy" x-cloak class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/></svg>
                        <span x-text="busy ? 'Signing in…' : 'Sign In'">Sign In</span>
                        <svg x-show="!busy" class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M13 7l5 5-5 5M6 12h12"/></svg>
                    </button>
                </form>

                <div class="flex flex-wrap justify-center gap-2 mt-6">
                    @foreach (['📚 Classroom', '🎓 Grades', '🧾 SOA', '📜 Certificates'] as $chip)
                    <span class="text-xs font-semibold bg-white/5 ring-1 ring-white/10 rounded-full px-3 py-1.5 text-emerald-100/80">{{ $chip }}</span>
                    @endforeach
                </div>
            </div>

            <p class="text-emerald-200/40 text-xs mt-7">Crossing Simuay, Sultan Kudarat, Maguindanao del Norte · © {{ date('Y') }}</p>
        </div>
    </main>

    <script>
    (function () {
        var canvas = document.getElementById('net');
        if (!canvas || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
        var ctx = canvas.getContext('2d'), w, h, dpr = Math.min(window.devicePixelRatio || 1, 2);
        var nodes = [], mouse = { x: -999, y: -999 };
        var COLORS = ['rgba(110,231,183,', 'rgba(238,200,97,', 'rgba(52,211,153,'];

        function resize() {
            w = canvas.width = innerWidth * dpr; h = canvas.height = innerHeight * dpr;
            canvas.style.width = innerWidth + 'px'; canvas.style.height = innerHeight + 'px';
            var count = Math.min(90, Math.floor(innerWidth * innerHeight / 16000));
            nodes = [];
            for (var i = 0; i < count; i++) {
                nodes.push({
                    x: Math.random() * w, y: Math.random() * h,
                    vx: (Math.random() - 0.5) * 0.4 * dpr, vy: (Math.random() - 0.5) * 0.4 * dpr,
                    r: (Math.random() * 1.6 + 0.8) * dpr, c: COLORS[i % COLORS.length]
                });
            }
        }
        function step() {
            ctx.clearRect(0, 0, w, h);
            var maxD = 130 * dpr, mMax = 180 * dpr;
            for (var i = 0; i < nodes.length; i++) {
                var n = nodes[i];
                n.x += n.vx; n.y += n.vy;
                if (n.x < 0 || n.x > w) n.vx *= -1;
                if (n.y < 0 || n.y > h) n.vy *= -1;
                // gentle pull toward mouse
                var mdx = mouse.x - n.x, mdy = mouse.y - n.y, md = Math.hypot(mdx, mdy);
                if (md < mMax) {
                    n.x += mdx / md * 0.5; n.y += mdy / md * 0.5;
                    ctx.beginPath(); ctx.moveTo(n.x, n.y); ctx.lineTo(mouse.x, mouse.y);
                    ctx.strokeStyle = 'rgba(238,200,97,' + (0.18 * (1 - md / mMax)) + ')';
                    ctx.lineWidth = dpr; ctx.stroke();
                }
                ctx.beginPath(); ctx.arc(n.x, n.y, n.r, 0, 6.283);
                ctx.fillStyle = n.c + '0.8)'; ctx.fill();
                for (var j = i + 1; j < nodes.length; j++) {
                    var m = nodes[j], dx = n.x - m.x, dy = n.y - m.y, d = Math.hypot(dx, dy);
                    if (d < maxD) {
                        ctx.beginPath(); ctx.moveTo(n.x, n.y); ctx.lineTo(m.x, m.y);
                        ctx.strokeStyle = 'rgba(110,231,183,' + (0.14 * (1 - d / maxD)) + ')';
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
    </script>
</body>
</html>
