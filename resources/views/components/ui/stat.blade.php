@props(['label', 'value' => null, 'hint' => null, 'icon' => null, 'color' => 'blue', 'lines' => null, 'compact' => false])

@php
    $tint = [
        'green' => 'bg-green-100 text-green-600 dark:bg-green-900/30 dark:text-green-400',
        'yellow' => 'bg-yellow-100 text-yellow-600 dark:bg-yellow-900/30 dark:text-yellow-400',
        'blue' => 'bg-blue-100 text-blue-600 dark:bg-blue-900/30 dark:text-blue-400',
        'purple' => 'bg-purple-100 text-purple-600 dark:bg-purple-900/30 dark:text-purple-400',
        'slate' => 'bg-slate-100 text-slate-600 dark:bg-gray-700/50 dark:text-gray-300',
    ][$color] ?? 'bg-blue-100 text-blue-600 dark:bg-blue-900/30 dark:text-blue-400';
@endphp

<div class="card {{ $compact ? 'p-3' : 'p-5' }}">
    <div class="flex items-center {{ $compact ? 'gap-3' : 'gap-4' }}">
        @if ($icon)
            <div class="flex {{ $compact ? 'h-9 w-9' : 'h-12 w-12' }} shrink-0 items-center justify-center rounded-lg {{ $tint }}">
                <x-nav.icon :name="$icon" class="{{ $compact ? 'h-5 w-5' : 'h-6 w-6' }}" />
            </div>
        @endif
        <div class="min-w-0 flex-1">
            <p class="{{ $compact ? 'text-xs' : 'text-sm' }} font-medium text-gray-500 dark:text-gray-400">{{ $label }}</p>
            @if ($lines)
                <div class="mt-1 space-y-0.5">
                    @foreach ($lines as $line)
                        <p class="flex items-baseline justify-between gap-2 {{ $compact ? 'text-xs' : 'text-sm' }}">
                            <span class="text-gray-500 dark:text-gray-400">{{ $line['label'] }}</span>
                            <span class="{{ $compact ? 'text-sm' : 'text-lg' }} font-bold tabular-nums text-gray-900 dark:text-white">{{ $line['value'] }}</span>
                        </p>
                    @endforeach
                </div>
            @else
                <p class="{{ $compact ? 'mt-0.5 text-lg' : 'mt-1 text-2xl' }} font-bold tabular-nums text-gray-900 dark:text-white">{{ $value }}</p>
            @endif
            @if ($hint)
                <p class="{{ $compact ? 'mt-0.5' : 'mt-1' }} text-xs text-gray-400 dark:text-gray-500">{{ $hint }}</p>
            @endif
        </div>
    </div>
</div>
