<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', fn (Blueprint $t) => $t->renameColumn('inr_rate', 'exchange_rate'));
        Schema::table('invoices', fn (Blueprint $t) => $t->decimal('exchange_rate', 18, 6)->nullable()->change());
    }

    public function down(): void
    {
        Schema::table('invoices', fn (Blueprint $t) => $t->decimal('exchange_rate', 10, 4)->nullable()->change());
        Schema::table('invoices', fn (Blueprint $t) => $t->renameColumn('exchange_rate', 'inr_rate'));
    }
};
