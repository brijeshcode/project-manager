{{-- A deadline date coloured by Look::deadline (yellow 0-3 days, red when late, plain when done/far). $date, $done, $format. --}}
@props(['date' => null, 'done' => false, 'format' => 'M j'])
@php
    $d = \App\Support\Look::deadline($date, $done);
    $cls = ['late' => 'font-medium text-rose-600 dark:text-rose-400', 'soon' => 'font-medium text-yellow-600 dark:text-yellow-400'][$d['state']] ?? 'text-slate-500';
    $hint = match (true) { $d['days'] === null => '', $done => 'Done', $d['days'] < 0 => abs($d['days']).' '.\Illuminate\Support\Str::plural('day', abs($d['days'])).' overdue', $d['days'] === 0 => 'Due today', $d['days'] === 1 => 'Due tomorrow', default => 'Due in '.$d['days'].' days' };
@endphp
@if ($date)
    <span {{ $attributes->merge(['class' => $cls]) }} data-state="{{ $d['state'] }}" data-tip="{{ $hint }}">{{ $date->format($format) }}</span>
@endif
