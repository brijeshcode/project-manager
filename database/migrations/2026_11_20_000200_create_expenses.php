<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // money going out (server, domain, developer fee, rent, electricity); admin only, never shown to clients
        Schema::create('expenses', function (Blueprint $t) {
            $t->id();
            $t->string('category');
            $t->string('description');
            $t->string('payee')->nullable();
            $t->foreignId('currency_id')->constrained('currencies');
            $t->integer('amount_cents');
            $t->date('incurred_on');
            $t->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $t->boolean('repeats_monthly')->default(false); // the daily job adds next month's copy
            $t->foreignId('repeated_from_id')->nullable()->constrained('expenses')->nullOnDelete();
            $t->text('notes')->nullable();
            $t->timestamps();
        });

        Schema::create('expense_payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('expense_id')->constrained()->cascadeOnDelete();
            $t->date('paid_on');
            $t->integer('amount_cents'); // in the expense's currency
            $t->decimal('exchange_rate', 18, 6)->nullable();
            $t->bigInteger('local_amount_cents')->nullable();
            $t->string('local_currency', 3)->nullable();
            $t->string('method')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_payments');
        Schema::dropIfExists('expenses');
    }
};
