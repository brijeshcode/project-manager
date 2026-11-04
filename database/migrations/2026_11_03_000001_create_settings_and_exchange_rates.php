<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $t) {
            $t->id();
            $t->string('key')->unique();
            $t->text('value')->nullable();
            $t->string('group')->default('general')->index();
            $t->timestamps();
        });

        Schema::create('exchange_rates', function (Blueprint $t) {
            $t->id();
            $t->string('currency', 3)->index();
            $t->decimal('rate', 18, 6);
            $t->boolean('is_active')->default(true);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::table('payments', function (Blueprint $t) {
            $t->decimal('exchange_rate', 18, 6)->nullable();
            $t->bigInteger('local_amount_cents')->nullable();
            $t->string('local_currency', 3)->nullable();
        });

        // Default local currency is INR: existing INR-client payments convert at 1.
        DB::statement("UPDATE payments SET exchange_rate = 1, local_amount_cents = amount_cents, local_currency = 'INR' WHERE client_id IN (SELECT id FROM clients WHERE currency_id = (SELECT id FROM currencies WHERE code = 'INR'))");
    }

    public function down(): void
    {
        Schema::table('payments', fn (Blueprint $t) => $t->dropColumn(['exchange_rate', 'local_amount_cents', 'local_currency']));
        Schema::dropIfExists('exchange_rates');
        Schema::dropIfExists('settings');
    }
};
