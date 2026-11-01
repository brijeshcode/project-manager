@props(['title', 'message' => null, 'name' => 'folder'])

<div class="card flex flex-col items-center justify-center px-6 py-16 text-center">
    <div class="mb-4 text-gray-300 dark:text-gray-600">
        @isset($icon)
            {{ $icon }}
        @else
            <x-nav.icon :name="$name" class="h-14 w-14" />
        @endisset
    </div>
    <h3 class="text-lg font-semibold text-gray-900 dark:text-white">{{ $title }}</h3>
    @if ($message)
        <p class="mt-1 max-w-sm text-sm text-gray-500 dark:text-gray-400">{{ $message }}</p>
    @endif
    @isset($action)
        <div class="mt-5">{{ $action }}</div>
    @endisset
</div>
