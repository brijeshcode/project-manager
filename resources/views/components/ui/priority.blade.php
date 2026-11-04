{{-- Priority badge: Low grey, Medium amber, High red. Works for tasks and modules. :dot="true" = just a coloured dot with the name as tooltip. --}}
@props(['value' => 0, 'dot' => false])
@php [$label, $color] = \App\Support\Look::priority((int) $value); @endphp
@if ($dot)
    @php $bg = ['slate' => 'bg-slate-400', 'amber' => 'bg-amber-400', 'rose' => 'bg-rose-500'][$color] ?? 'bg-slate-400'; @endphp
    <span {{ $attributes->merge(['class' => "inline-block h-2 w-2 shrink-0 rounded-full $bg"]) }} data-priority="{{ (int) $value }}" data-tip="{{ $label }} priority" aria-label="{{ $label }} priority" role="img"></span>
@else
    <x-ui.badge :color="$color" :attributes="$attributes" data-priority="{{ (int) $value }}">{{ $label }}</x-ui.badge>
@endif
