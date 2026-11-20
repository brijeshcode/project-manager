@props(['sticky' => false])
{{-- sticky: scrolls inside the card so the header row stays visible on long tables --}}
<div {{ $attributes->merge(['class' => 'card overflow-hidden']) }}>
    <div @class(['table-scroll' => $sticky, 'overflow-x-auto' => ! $sticky])>{{ $slot }}</div>
    @isset($footer)
        <div class="border-t border-gray-200 px-4 py-3 dark:border-gray-700">{{ $footer }}</div>
    @endisset
</div>
