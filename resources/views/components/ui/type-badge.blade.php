{{-- Task type badge: icon + name tinted with the type's colour. $type is a TaskType (or null). --}}
@props(['type' => null])
@if ($type)
    @php $c = preg_match('/^#[0-9a-fA-F]{6}$/', (string) $type->color) ? $type->color : '#6b7280'; @endphp
    <span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1 whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-medium']) }} style="color: {{ $c }}; background: {{ $c }}1f; box-shadow: inset 0 0 0 1px {{ $c }}40" data-type="{{ $type->name }}">
        <x-ui.glyph :value="$type->icon" class="h-3.5 w-3.5" />{{ $type->name }}
    </span>
@endif
