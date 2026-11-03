{{-- Rendered markdown. `toggle` ('description' | 'comment:ID') makes task-list checkboxes clickable via the host's toggleTask(). --}}
@props(['text' => '', 'toggle' => null])
<div {{ $attributes->merge(['class' => 'md']) }}
     @if ($toggle) @change="if ($event.target.dataset.mdTask !== undefined) $wire.toggleTask(@js($toggle), +$event.target.dataset.mdTask, $event.target.checked)" @endif>{!! \App\Support\Markdown::render($text, (bool) $toggle) !!}</div>
