<div {{ $attributes->merge(['class' => 'card overflow-hidden']) }}>
    <div class="overflow-x-auto">{{ $slot }}</div>
    @isset($footer)
        <div class="border-t border-gray-200 px-4 py-3 dark:border-gray-700">{{ $footer }}</div>
    @endisset
</div>
