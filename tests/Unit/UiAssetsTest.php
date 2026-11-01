<?php

it('reapplies dark mode after livewire navigate swaps html', function () {
    expect(file_get_contents(base_path('resources/js/theme.js')))
        ->toContain('livewire:navigating')->toContain('onSwap');
});

it('keeps literal badge class names for the tailwind scanner', function () {
    $src = file_get_contents(base_path('resources/views/components/ui/badge.blade.php'));
    foreach (['slate', 'brand', 'green', 'amber', 'rose'] as $c) {
        expect($src)->toContain("'badge-$c'");
    }
});
