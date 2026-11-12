<?php

use App\Models\BillableLine;
use App\Models\RecurringCharge;
use App\Services\LocalCurrency;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration {
    public function up(): void
    {
        // rows in a foreign currency frozen at rate 1 (the old "no rate known" fallback) had local = amount; clear them
        $local = LocalCurrency::code();
        foreach ([BillableLine::class => 'lineCurrency', RecurringCharge::class => 'currency'] as $model => $currency) {
            $ids = $model::with(['project', 'client'])->where('exchange_rate', 1)->get()
                ->filter(fn ($r) => ($c = $r->{$currency}()) !== null && strtoupper($c) !== $local)->modelKeys();
            $model::whereKey($ids)->update(['exchange_rate' => null, 'local_amount_cents' => null]);
        }
    }

    public function down(): void {}
};
