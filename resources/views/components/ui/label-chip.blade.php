{{-- Label chip: its icon (or a colour dot) and name. $label is a Label. --}}
@props(['label'])
@php $c = preg_match('/^#[0-9a-fA-F]{6}$/', (string) $label->color) ? $label->color : '#6b7280'; @endphp
<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset ring-slate-200 dark:ring-gray-600']) }} data-label="{{ $label->name }}">
    @if (filled($label->icon))
        <span style="color: {{ $c }}" class="inline-flex"><x-ui.glyph :value="$label->icon" class="h-3 w-3" /></span>
    @else
        <span class="h-2 w-2 rounded-full" style="background: {{ $c }}"></span>
    @endif
    {{ $label->name }}
</span>
