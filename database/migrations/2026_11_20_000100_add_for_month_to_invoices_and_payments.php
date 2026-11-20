<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        foreach (['invoices', 'payments'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->string('for_month', 7)->nullable(); // 'Y-m' the invoice bills / the payment pays for; optional
            });
        }
    }

    public function down(): void
    {
        foreach (['invoices', 'payments'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn('for_month'));
        }
    }
};
