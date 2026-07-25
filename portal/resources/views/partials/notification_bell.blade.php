{{-- Reusable notification bell. Pass $indexUrl and $readUrl (JSON endpoints). --}}
<div x-data="notifBell({{ json_encode($indexUrl) }}, {{ json_encode($readUrl) }})" x-init="load(); setInterval(load, 20000)" class="relative">
    <button @click="open = !open; if (open) markRead()" :aria-expanded="open" aria-label="Notifications"
            class="relative w-10 h-10 rounded-xl bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-700 shadow-sm flex items-center justify-center transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500">
        <svg class="w-5 h-5 text-slate-600 dark:text-slate-300" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.9 9.9 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
        <span x-show="unread > 0" x-cloak x-text="unread > 9 ? '9+' : unread" class="absolute -top-1 -right-1 min-w-[18px] h-[18px] px-1 rounded-full bg-rose-500 text-white text-[10px] font-extrabold flex items-center justify-center"></span>
    </button>
    <div x-show="open" @click.outside="open = false" x-cloak x-transition.opacity
         class="absolute right-0 mt-2 w-80 max-h-96 overflow-y-auto bg-white dark:bg-slate-800 rounded-2xl shadow-2xl border border-slate-100 dark:border-slate-700 z-50">
        <div class="px-4 py-3 border-b border-slate-100 dark:border-slate-700 font-bold text-sm text-slate-700 dark:text-slate-200">Notifications</div>
        {{-- Loading skeleton (first fetch) --}}
        <template x-if="loading">
            <div class="p-4 space-y-3">
                <template x-for="i in 3" :key="i">
                    <div class="animate-pulse space-y-2">
                        <div class="h-3 rounded bg-slate-200 dark:bg-slate-700 w-3/4"></div>
                        <div class="h-2.5 rounded bg-slate-100 dark:bg-slate-700/60 w-1/2"></div>
                    </div>
                </template>
            </div>
        </template>
        <template x-if="!loading && items.length === 0">
            <p class="px-4 py-8 text-center text-sm text-slate-400 dark:text-slate-500">You're all caught up.</p>
        </template>
        <template x-for="n in items" :key="n.id">
            <a :href="n.link || '#'" class="block px-4 py-3 border-b border-slate-50 dark:border-slate-700/50 hover:bg-slate-50 dark:hover:bg-slate-700/40 transition-colors" :class="!n.read ? 'bg-emerald-50/50 dark:bg-emerald-500/10' : ''">
                <p class="text-sm font-semibold text-slate-800 dark:text-slate-100" x-text="n.title"></p>
                <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5 line-clamp-2" x-text="n.body"></p>
                <p class="text-[10px] text-slate-400 dark:text-slate-500 mt-1" x-text="n.time"></p>
            </a>
        </template>
    </div>
</div>
<script>
function notifBell(indexUrl, readUrl) {
    return {
        open: false, unread: 0, items: [], loading: true, lastSeenId: null,
        async load() {
            try {
                const r = await fetch(indexUrl, { headers: { 'Accept': 'application/json' } });
                const d = await r.json();
                // Pop a toast for genuinely new notifications (skip the very first load).
                const newestId = d.items.length ? d.items[0].id : null;
                if (this.lastSeenId !== null && newestId && newestId !== this.lastSeenId && d.unread > this.unread) {
                    const fresh = d.items.find(n => n.id === newestId && !n.read);
                    if (fresh && window.toast) window.toast('info', fresh.body || '', fresh.title);
                }
                this.lastSeenId = newestId;
                this.unread = d.unread; this.items = d.items;
            } catch (e) { /* silent — non-critical */ }
            finally { this.loading = false; }
        },
        async markRead() {
            if (this.unread === 0) return;
            this.unread = 0;
            this.items = this.items.map(n => ({ ...n, read: true }));
            try {
                await fetch(readUrl, { method: 'POST', headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'Accept': 'application/json' } });
            } catch (e) { /* silent */ }
        },
    };
}
</script>
