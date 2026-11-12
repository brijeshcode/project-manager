@props(['color' => 'slate'])

@php
    // Literal class names so Tailwind's scanner keeps them.
    $cls = ['slate' => 'badge-slate', 'brand' => 'badge-brand', 'green' => 'badge-green', 'amber' => 'badge-amber', 'rose' => 'badge-rose'][$color] ?? 'badge-slate';
@endphp
{{-- one line, slot trimmed: a parent with whitespace-pre-line would otherwise show the newlines --}}
<span {{ $attributes->merge(['class' => "badge $cls"]) }}>{{ new \Illuminate\Support\HtmlString(trim($slot)) }}</span>
