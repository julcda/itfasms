{{-- Global toast hub. Fire from anywhere with: window.toast('success'|'error'|'info', 'message', title?) --}}
<div x-data="toastHub()" @toast.window="push($event.detail)"
     class="fixed top-4 right-4 z-[60] w-80 max-w-[calc(100vw-2rem)] space-y-2 pointer-events-none"
     aria-live="polite" aria-atomic="false">
    <template x-for="t in items" :key="t.id">
        <div x-show="t.show" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 translate-x-4"
             x-transition:enter-end="opacity-100 translate-x-0" x-transition:leave="transition ease-in duration-150"
             x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
             role="status"
             class="pointer-events-auto flex items-start gap-3 rounded-2xl border shadow-lg px-4 py-3 bg-white dark:bg-slate-800"
             :class="{
                'border-emerald-300 dark:border-emerald-500/40': t.type==='success',
                'border-rose-300 dark:border-rose-500/40': t.type==='error',
                'border-sky-300 dark:border-sky-500/40': t.type==='info',
             }">
            <span class="text-lg leading-none mt-0.5" aria-hidden="true"
                  x-text="t.type==='success' ? '✓' : (t.type==='error' ? '⚠' : '🔔')"
                  :class="{ 'text-emerald-600': t.type==='success', 'text-rose-600': t.type==='error', 'text-sky-600': t.type==='info' }"></span>
            <div class="flex-1 min-w-0">
                <p x-show="t.title" x-text="t.title" class="text-sm font-bold text-slate-800 dark:text-slate-100"></p>
                <p x-text="t.message" class="text-sm text-slate-600 dark:text-slate-300"></p>
            </div>
            <button @click="dismiss(t.id)" class="text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 text-sm leading-none" aria-label="Dismiss">✕</button>
        </div>
    </template>
</div>
<script>
function toastHub() {
    return {
        items: [],
        push(d) {
            const id = Date.now() + Math.random();
            const t = { id, show: true, type: d.type || 'info', title: d.title || '', message: d.message || '' };
            this.items.push(t);
            setTimeout(() => this.dismiss(id), d.timeout || 4500);
        },
        dismiss(id) {
            const t = this.items.find(x => x.id === id);
            if (t) { t.show = false; setTimeout(() => { this.items = this.items.filter(x => x.id !== id); }, 200); }
        },
    };
}
window.toast = function (type, message, title) {
    window.dispatchEvent(new CustomEvent('toast', { detail: { type: type, message: message, title: title } }));
};
@if (session('success'))
document.addEventListener('alpine:initialized', () => window.toast('success', @json(session('success'))));
@elseif (session('error'))
document.addEventListener('alpine:initialized', () => window.toast('error', @json(session('error'))));
@endif
</script>
