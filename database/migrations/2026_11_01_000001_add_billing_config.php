<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $t) {
            $t->string('billing_mode')->default('fixed');
        });

        Schema::table('modules', function (Blueprint $t) {
            $t->decimal('budget_hours', 8, 2)->nullable();
            $t->decimal('hourly_rate', 14, 2)->nullable();
            $t->decimal('budget_amount', 14, 2)->nullable();
            $t->decimal('exchange_rate', 18, 6)->nullable();   // project currency -> local
            $t->decimal('hourly_rate_local', 14, 2)->nullable();
            $t->decimal('budget_amount_local', 14, 2)->nullable();
            $t->date('deadline')->nullable();
        });

        Schema::create('module_budget_changes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('module_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained();
            $t->string('field');
            $t->string('old')->nullable();
            $t->string('new')->nullable();
            $t->text('reason')->nullable();
            $t->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_budget_changes');
        Schema::table('modules', fn (Blueprint $t) => $t->dropColumn(['budget_hours', 'hourly_rate', 'budget_amount', 'deadline']));
        Schema::table('clients', fn (Blueprint $t) => $t->dropColumn('billing_mode'));
    }
};
