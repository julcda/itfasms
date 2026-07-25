@extends('layouts.app')
@section('title', $quiz->title)

@section('content')
@php $secondsLeft = $deadline ? max(0, now()->diffInSeconds($deadline, false)) : null; @endphp

<div x-data="quizTimer({{ $secondsLeft === null ? 'null' : (int) $secondsLeft }})" @beforeunload.window="">
    <x-page-header accent="green" eyebrow="Quiz in progress" :title="$quiz->title">
        <x-slot:actions>
            @if ($deadline)
            <div class="rounded-xl px-4 py-2 font-mono font-extrabold text-lg" :class="left < 60 ? 'bg-rose-100 text-rose-700 dark:bg-rose-500/15 dark:text-rose-300' : 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300'">
                <span x-text="display"></span>
            </div>
            @endif
        </x-slot:actions>
    </x-page-header>

    <form method="POST" action="{{ route('student.attempts.submit', $attempt->id) }}" id="quizForm" x-ref="form">
        @csrf
        <input type="hidden" name="auto_submitted" x-model="auto">

        @foreach ($view as $idx => $item)
        @php $q = $item['q']; @endphp
        <x-card class="mb-4">
            <div class="flex items-center gap-2 mb-3">
                <span class="w-7 h-7 rounded-full bg-emerald-600 text-white text-xs font-bold flex items-center justify-center">{{ $idx + 1 }}</span>
                <span class="text-xs text-slate-400">{{ rtrim(rtrim(number_format($q->points,1),'0'),'.') }} pts</span>
            </div>
            <p class="font-semibold text-slate-800 dark:text-slate-100 mb-3">{{ $q->question_text }}</p>

            @switch($q->type)
                @case('mcq')
                @case('true_false')
                    <div class="space-y-2">
                        @foreach ($item['choices'] as $c)
                        <label class="flex items-center gap-3 rounded-xl border border-slate-200 dark:border-slate-700 px-4 py-2.5 cursor-pointer hover:border-emerald-400 has-[:checked]:border-emerald-500 has-[:checked]:bg-emerald-50 dark:has-[:checked]:bg-emerald-500/10">
                            <input type="radio" name="q[{{ $q->id }}]" value="{{ $c->id }}" class="accent-emerald-600">
                            <span class="text-sm text-slate-700 dark:text-slate-200">{{ $c->choice_text }}</span>
                        </label>
                        @endforeach
                    </div>
                    @break
                @case('multi_select')
                    <div class="space-y-2">
                        @foreach ($item['choices'] as $c)
                        <label class="flex items-center gap-3 rounded-xl border border-slate-200 dark:border-slate-700 px-4 py-2.5 cursor-pointer hover:border-emerald-400 has-[:checked]:border-emerald-500 has-[:checked]:bg-emerald-50 dark:has-[:checked]:bg-emerald-500/10">
                            <input type="checkbox" name="q[{{ $q->id }}][]" value="{{ $c->id }}" class="accent-emerald-600">
                            <span class="text-sm text-slate-700 dark:text-slate-200">{{ $c->choice_text }}</span>
                        </label>
                        @endforeach
                    </div>
                    <p class="text-xs text-slate-400 mt-1">Select all that apply.</p>
                    @break
                @case('identification')
                @case('short_answer')
                @case('fill_blank')
                    <input type="text" name="q[{{ $q->id }}]" placeholder="Your answer" class="w-full max-w-md rounded-xl border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 px-4 py-2.5 text-sm">
                    @break
                @case('essay')
                    <textarea name="q[{{ $q->id }}]" rows="5" placeholder="Write your answer…" class="w-full rounded-xl border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 px-4 py-2.5 text-sm"></textarea>
                    @break
                @case('matching')
                    <div class="space-y-2">
                        @foreach ($q->meta['pairs'] ?? [] as $i => $pair)
                        <div class="flex items-center gap-3">
                            <span class="text-sm font-medium text-slate-700 dark:text-slate-200 w-40 shrink-0">{{ $pair['left'] }}</span>
                            <span class="text-slate-400">→</span>
                            <select name="match[{{ $q->id }}][{{ $i }}]" class="rounded-xl border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 px-3 py-2 text-sm">
                                <option value="">— choose —</option>
                                @foreach ($item['rights'] as $r)<option value="{{ $r }}">{{ $r }}</option>@endforeach
                            </select>
                        </div>
                        @endforeach
                    </div>
                    @break
                @case('ordering')
                    <p class="text-xs text-slate-400 mb-2">Assign the correct position to each item.</p>
                    <div class="space-y-2">
                        @foreach ($item['items'] as $it)
                        <div class="flex items-center gap-3">
                            <select name="order[{{ $q->id }}][{{ $it['i'] }}]" class="rounded-xl border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 px-3 py-2 text-sm w-20">
                                <option value="">#</option>
                                @for ($n = 1; $n <= count($item['items']); $n++)<option value="{{ $n }}">{{ $n }}</option>@endfor
                            </select>
                            <span class="text-sm text-slate-700 dark:text-slate-200">{{ $it['text'] }}</span>
                        </div>
                        @endforeach
                    </div>
                    @break
            @endswitch
        </x-card>
        @endforeach

        <div class="flex items-center justify-between gap-3 sticky bottom-4">
            <p class="text-xs text-slate-500 dark:text-slate-400">Review your answers before submitting.</p>
            <button type="button" @click="submitNow()" class="rounded-xl bg-green-600 hover:bg-green-700 text-white text-sm font-bold px-8 py-3 shadow-lg">Submit Quiz</button>
        </div>
    </form>
</div>

<script>
function quizTimer(seconds) {
    return {
        left: seconds, auto: '0', display: '',
        init() {
            if (this.left === null) return;
            this.render();
            this._t = setInterval(() => {
                this.left--; this.render();
                if (this.left <= 0) { clearInterval(this._t); this.auto = '1'; this.$nextTick(() => this.$refs.form.submit()); }
            }, 1000);
        },
        render() {
            if (this.left === null) return;
            const m = Math.floor(this.left / 60), s = this.left % 60;
            this.display = m + ':' + String(s).padStart(2, '0');
        },
        submitNow() { if (confirm('Submit your quiz? You cannot change answers afterward.')) this.$refs.form.submit(); },
    };
}
</script>
@endsection
