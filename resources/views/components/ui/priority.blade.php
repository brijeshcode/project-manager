{{-- Priority badge: Low grey, Medium amber, High red. Works for tasks now and modules later. --}}
@props(['value' => 0])
@php([$label, $color] = \App\Support\Look::priority((int) $value))
<x-ui.badge :color="$color" :attributes="$attributes" data-priority="{{ (int) $value }}">{{ $label }}</x-ui.badge>
