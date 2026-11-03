{{-- items: [[label, url|null], ...]; the last item is the current page. --}}
@props(['items'])
<nav aria-label="Breadcrumb" class="mb-6 flex flex-wrap items-center gap-1.5 text-sm text-slate-500 dark:text-gray-400" data-test="breadcrumb">
    @foreach ($items as [$label, $url])
        @if (! $loop->first) <span class="text-slate-300 dark:text-gray-600">/</span> @endif
        @if ($url && ! $loop->last)
            <a href="{{ $url }}" wire:navigate class="font-medium hover:text-slate-700 dark:hover:text-gray-200">{{ $label }}</a>
        @else
            <span class="max-w-xs truncate text-slate-700 dark:text-gray-200" @if ($loop->last) aria-current="page" @endif>{{ $label }}</span>
        @endif
    @endforeach
</nav>
