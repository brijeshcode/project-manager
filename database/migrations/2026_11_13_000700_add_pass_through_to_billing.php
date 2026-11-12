<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // pass-through: a cost passed on to the client (server, hosting); billed and paid as usual, but not the owner's income
        Schema::table('billable_lines', fn (Blueprint $t) => $t->boolean('pass_through')->default(false)->after('amount_cents'));
        Schema::table('recurring_charges', fn (Blueprint $t) => $t->boolean('pass_through')->default(false)->after('amount_cents'));
    }

    public function down(): void
    {
        Schema::table('billable_lines', fn (Blueprint $t) => $t->dropColumn('pass_through'));
        Schema::table('recurring_charges', fn (Blueprint $t) => $t->dropColumn('pass_through'));
    }
};
