{{-- A type/label icon: a known icon name renders the SVG, anything else is a pasted emoji printed as escaped text (never raw HTML). --}}
@props(['value' => null])
@if (\App\Support\Icon::exists($value))
    <x-icon :name="$value" {{ $attributes }} />
@elseif (filled($value))
    <span aria-hidden="true" {{ $attributes->merge(['class' => 'leading-none']) }}>{{ mb_substr((string) $value, 0, 16) }}</span>
@endif
