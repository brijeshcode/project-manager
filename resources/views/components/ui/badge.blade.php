@props(['color' => 'slate'])

<span {{ $attributes->merge(['class' => "badge badge-$color"]) }}>
    {{ $slot }}
</span>
