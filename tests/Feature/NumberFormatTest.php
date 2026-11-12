<?php

use App\Livewire\Settings\System;
use App\Models\User;
use App\Support\Money;
use App\Support\Settings;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('formats international by default: 1,234,567.89 and K / M', function () {
    expect(Settings::numberFormat())->toBe('international')
        ->and(Money::format(123456789, 'INR'))->toBe('₹1,234,567.89')
        ->and(Money::number(-99999))->toBe('-999.99')
        ->and(Money::compact(123400))->toBe('1,234')
        ->and(Money::compact(1234500))->toBe('12.3K')
        ->and(Money::compact(99_995_000))->toBe('1M')
        ->and(Money::compact(180_000_000))->toBe('1.8M');
});

it('formats indian when set: 12,34,567.89 and K / L / Cr', function () {
    Settings::set('number_format', 'indian');

    expect(Money::format(123456789, 'INR'))->toBe('₹12,34,567.89')
        ->and(Money::format(-1234567800, 'USD'))->toBe('-$1,23,45,678.00')
        ->and(Money::number(99999))->toBe('999.99')
        ->and(Money::number(12345600, 0))->toBe('1,23,456')
        ->and(Money::compact(123400))->toBe('1,234')
        ->and(Money::compact(4550000))->toBe('45.5K')
        ->and(Money::compact(9_999_000))->toBe('1L')
        ->and(Money::compact(180_000_000))->toBe('18L')
        ->and(Money::compact(1_234_567_800))->toBe('1.2Cr')
        ->and(Money::compact(-180_000_000))->toBe('-18L');
});

it('charts follow the number format in ticks and bar labels', function () {
    Settings::set('number_format', 'indian');
    $html = Blade::render('<x-ui.line-chart :points="$p" currency="INR" type="bar" />', ['p' => [['label' => 'A', 'cents' => 180_000_000]]]);

    expect($html)->toContain('>18L<')->toContain('>20,00,000.00<')->toContain('<title>A: ₹18,00,000.00</title>');
});

it('system page saves the number format and rejects unknown ones', function () {
    $this->seed(RoleSeeder::class);
    $admin = User::factory()->create()->assignRole('super_admin');

    $this->actingAs($admin)->get(route('settings.system'))->assertOk()->assertSee('Number format');
    Livewire::actingAs($admin)->test(System::class)->assertSet('numberFormat', 'international')
        ->set('numberFormat', 'roman')->call('save')->assertHasErrors(['numberFormat'])
        ->set('numberFormat', 'indian')->call('save')->assertHasNoErrors();
    expect(Settings::numberFormat())->toBe('indian');
});
