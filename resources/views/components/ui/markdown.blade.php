@props(['text' => ''])
<div {{ $attributes->merge(['class' => 'md']) }}>{!! \App\Support\Markdown::render($text) !!}</div>
