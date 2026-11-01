@props(['color' => 'slate'])

@php
    // Literal class names so Tailwind's scanner keeps them.
    $cls = ['slate' => 'badge-slate', 'brand' => 'badge-brand', 'green' => 'badge-green', 'amber' => 'badge-amber', 'rose' => 'badge-rose'][$color] ?? 'badge-slate';
@endphp
<span {{ $attributes->merge(['class' => "badge $cls"]) }}>
    {{ $slot }}
</span>
