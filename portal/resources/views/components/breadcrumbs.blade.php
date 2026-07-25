@props(['items' => []])
{{-- $items: array of ['label' => ..., 'url' => ...|null]. Last item = current page. --}}
<nav aria-label="Breadcrumb" class="mb-3">
    <ol class="flex flex-wrap items-center gap-1.5 text-sm">
        @foreach ($items as $i => $item)
            @php $isLast = $i === count($items) - 1; @endphp
            <li class="flex items-center gap-1.5">
                @if (!$isLast && !empty($item['url']))
                    <a href="{{ $item['url'] }}" class="text-slate-500 dark:text-slate-400 hover:text-emerald-700 dark:hover:text-emerald-400 hover:underline transition-colors">{{ $item['label'] }}</a>
                @else
                    <span @class(['font-semibold text-slate-800 dark:text-slate-100' => $isLast, 'text-slate-500 dark:text-slate-400' => !$isLast]) @if($isLast) aria-current="page" @endif>{{ $item['label'] }}</span>
                @endif
                @unless ($isLast)
                    <svg class="w-3.5 h-3.5 text-slate-300 dark:text-slate-600" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                @endunless
            </li>
        @endforeach
    </ol>
</nav>
