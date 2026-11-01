<?php

use Illuminate\Support\Facades\Blade;

it('stat with lines renders every line and no single value', function () {
    $html = Blade::render('<x-ui.stat label="Revenue" value="BIGVALUE" icon="document" color="green" :lines="$l" />', [
        'l' => [['label' => 'USD', 'value' => '$1,200'], ['label' => 'EUR', 'value' => '€900']],
    ]);
    expect($html)->toContain('Revenue', 'USD', '$1,200', 'EUR', '€900')->not->toContain('BIGVALUE');
});

it('stat with single value renders value and hint', function () {
    $html = Blade::render('<x-ui.stat label="Projects" value="42" hint="across clients" />');
    expect($html)->toContain('Projects', '42', 'across clients');
});

it('table-card wraps slot in overflow-x-auto and renders footer', function () {
    $html = Blade::render('<x-ui.table-card><x-slot:footer>FOOT</x-slot:footer><table>ROWS</table></x-ui.table-card>');
    expect($html)->toMatch('/overflow-x-auto[^>]*>\s*<table>ROWS/')->toContain('FOOT');
});

it('filter-card renders its slot', function () {
    expect(Blade::render('<x-ui.filter-card><input name="q"></x-ui.filter-card>'))->toContain('<input name="q">', 'card');
});

it('toast renders status region and flashed message', function () {
    session()->flash('toast', 'Saved');
    $html = Blade::render('<x-ui.toast />');
    expect($html)->toContain('role="status"', 'aria-live="polite"', 'Saved');
});

it('empty-state renders title, message and action', function () {
    $html = Blade::render('<x-ui.empty-state title="Nothing" message="Add one"><x-slot:action>GO</x-slot:action></x-ui.empty-state>');
    expect($html)->toContain('Nothing', 'Add one', 'GO');
});
