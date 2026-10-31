<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $t) {
            $t->id();
            $t->string('number')->unique();
            $t->foreignId('client_id')->constrained()->cascadeOnDelete();
            $t->string('status')->default('sent'); // sent | void; paid is derived from payments
            $t->date('issued_on');
            $t->date('due_on')->nullable();
            $t->char('currency', 3);
            $t->bigInteger('subtotal_cents');
            $t->decimal('tax_percent', 5, 2)->default(0);
            $t->bigInteger('tax_cents');
            $t->bigInteger('total_cents');
            $t->foreignId('bank_account_id')->nullable()->constrained()->nullOnDelete();
            $t->text('bank_snapshot')->nullable(); // encrypted copy of the bank details at creation
            $t->decimal('inr_rate', 10, 4)->nullable();
            $t->text('terms')->nullable();
            $t->timestamp('emailed_at')->nullable();
            $t->timestamps();
            $t->index(['client_id', 'status']);
        });

        // Plain indexes only: existing rows/tests reference invoice ids without an FK.
        Schema::table('billable_lines', fn (Blueprint $t) => $t->index('invoice_id'));
        Schema::table('payments', fn (Blueprint $t) => $t->index('invoice_id'));
    }

    public function down(): void
    {
        Schema::table('payments', fn (Blueprint $t) => $t->dropIndex(['invoice_id']));
        Schema::table('billable_lines', fn (Blueprint $t) => $t->dropIndex(['invoice_id']));
        Schema::dropIfExists('invoices');
    }
};
