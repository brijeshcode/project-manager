{{-- <x-icon name="pencil" class="h-4 w-4" />: Lucide SVG (see resources/svg/LICENSE-icons.txt), strokes use currentColor. A missing icon renders an empty box, never an error. --}}
@props(['name'])
@php
    $svg = \App\Support\Icon::svg($name)
        ?? '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" data-missing-icon="'.e($name).'"><rect x="4" y="4" width="16" height="16" rx="2" stroke-dasharray="3 3"/></svg>';
    // default size only when the caller gave none, so h-5 never fights h-4 in the stylesheet
    $size = preg_match('/(^|\s)h-/', (string) $attributes->get('class')) ? '' : 'h-4 w-4 ';
    $attrs = $attributes->merge(['class' => $size.'shrink-0', 'aria-hidden' => 'true', 'focusable' => 'false'])->toHtml();
@endphp
{!! preg_replace('/^<svg /', '<svg '.$attrs.' ', $svg, 1) !!}
