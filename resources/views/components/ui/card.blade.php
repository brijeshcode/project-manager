@props(['padding' => 'p-5'])

<div {{ $attributes->merge(['class' => "card $padding"]) }}>
    {{ $slot }}
</div>
