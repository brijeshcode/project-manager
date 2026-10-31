<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billable_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('client_id')->constrained()->cascadeOnDelete();
            $t->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $t->nullableMorphs('source');
            $t->unsignedBigInteger('recurring_charge_id')->nullable(); // FK added when recurring_charges exists (Task 4)
            $t->string('period')->nullable();
            $t->string('description');
            $t->decimal('quantity', 8, 2)->default(1);
            $t->integer('unit_price_cents');
            $t->integer('amount_cents');
            $t->unsignedBigInteger('invoice_id')->nullable();
            $t->timestamps();
            $t->unique(['recurring_charge_id', 'period']); // multiple NULL pairs allowed
        });

        Schema::create('payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('client_id')->constrained()->cascadeOnDelete();
            $t->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $t->unsignedBigInteger('invoice_id')->nullable();
            $t->integer('amount_cents');
            $t->date('paid_on');
            $t->string('type');
            $t->string('method')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('billable_lines');
    }
};
