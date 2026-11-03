{{-- Board column badge coloured by its stage. $column is a BoardColumn (or null). --}}
@props(['column' => null])
@if ($column)
    <x-ui.badge :color="\App\Support\Look::stageColor($column->stage)" :attributes="$attributes" data-stage="{{ $column->stage }}">{{ $column->name }} <span class="opacity-60">&middot; {{ str_replace('_', ' ', $column->stage) }}</span></x-ui.badge>
@endif
