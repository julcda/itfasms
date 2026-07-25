{{-- Shared design foundation for the whole portal. Included in <head> of every layout + login. --}}
<meta name="csrf-token" content="{{ csrf_token() }}">
<x-theme-script />
<script src="https://cdn.tailwindcss.com"></script>
<script>
tailwind.config = {
    darkMode: 'class',
    theme: {
        extend: {
            fontFamily: {
                sans: ['Manrope', 'ui-sans-serif', 'system-ui', 'sans-serif'],
                display: ['"Plus Jakarta Sans"', 'Manrope', 'ui-sans-serif', 'system-ui'],
            },
            colors: {
                gold: { 50:'#fdf9ec', 100:'#faf0cd', 200:'#f4df97', 300:'#eec861', 400:'#e9b23a', 500:'#e0951f', 600:'#c67318', 700:'#a5531a', 800:'#87421c', 900:'#71371b' },
            },
            boxShadow: {
                panel: '0 20px 45px -24px rgba(4,82,45,0.28)',
                lift: '0 28px 60px -28px rgba(3,60,32,0.42)',
                glow: '0 0 0 1px rgba(16,185,129,0.18), 0 12px 30px -12px rgba(16,185,129,0.35)',
            },
            keyframes: {
                floaty: { '0%,100%': { transform: 'translateY(0)' }, '50%': { transform: 'translateY(-10px)' } },
                enter: { from: { opacity: 0, transform: 'translateY(10px)' }, to: { opacity: 1, transform: 'translateY(0)' } },
                shimmer: { '100%': { transform: 'translateX(100%)' } },
            },
            animation: {
                floaty: 'floaty 7s ease-in-out infinite',
                enter: 'enter .5s cubic-bezier(.2,.7,.2,1) both',
            },
        },
    },
};
</script>
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap" rel="stylesheet">
<style>
    [x-cloak]{display:none!important}
    .text-balance{text-wrap:balance}
    html{scroll-behavior:smooth}
    body{-webkit-font-smoothing:antialiased}
    ::selection{background:rgba(16,185,129,.22)}

    /* Layered ambient background — soft brand mesh, theme-aware, fixed behind content */
    .app-bg{position:relative}
    .app-bg::before{
        content:'';position:fixed;inset:0;z-index:-1;pointer-events:none;
        background:
            radial-gradient(60rem 40rem at 12% -8%, rgba(16,185,129,.14), transparent 60%),
            radial-gradient(48rem 34rem at 108% 10%, rgba(224,149,31,.10), transparent 55%),
            radial-gradient(50rem 40rem at 50% 120%, rgba(16,185,129,.08), transparent 60%),
            #f3f6f4;
    }
    .dark .app-bg::before{
        background:
            radial-gradient(60rem 40rem at 12% -8%, rgba(16,185,129,.16), transparent 60%),
            radial-gradient(48rem 34rem at 108% 10%, rgba(224,149,31,.10), transparent 55%),
            radial-gradient(50rem 40rem at 50% 120%, rgba(16,185,129,.10), transparent 60%),
            #060b10;
    }

    .glass{background:rgba(255,255,255,.72);backdrop-filter:blur(14px) saturate(1.3);}
    .dark .glass{background:rgba(20,30,40,.6);}

    .gradient-text{background:linear-gradient(100deg,#0a9150,#16b45e 45%,#e0951f);-webkit-background-clip:text;background-clip:text;color:transparent;}
    .dark .gradient-text{background:linear-gradient(100deg,#4ade80,#34d399 45%,#eec861);-webkit-background-clip:text;background-clip:text;color:transparent;}

    /* Interactive lift used by cards/links */
    .lift{transition:transform .28s cubic-bezier(.2,.7,.2,1), box-shadow .28s, border-color .28s}
    .lift:hover{transform:translateY(-3px)}

    /* Skeleton shimmer sweep */
    .shimmer{position:relative;overflow:hidden}
    .shimmer::after{content:'';position:absolute;inset:0;transform:translateX(-100%);
        background:linear-gradient(90deg,transparent,rgba(255,255,255,.5),transparent);animation:shimmer 1.6s infinite}
    .dark .shimmer::after{background:linear-gradient(90deg,transparent,rgba(255,255,255,.06),transparent)}

    /* Refined scrollbar */
    *{scrollbar-width:thin;scrollbar-color:rgba(16,185,129,.35) transparent}
    *::-webkit-scrollbar{width:10px;height:10px}
    *::-webkit-scrollbar-thumb{background:rgba(16,185,129,.3);border-radius:99px;border:3px solid transparent;background-clip:content-box}
    *::-webkit-scrollbar-thumb:hover{background:rgba(16,185,129,.5);background-clip:content-box}

    @media (prefers-reduced-motion: reduce){
        *,*::before,*::after{animation-duration:.001ms!important;transition-duration:.001ms!important}
        html{scroll-behavior:auto}
    }
</style>
