<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // every money row points here by currency_id; rows are created on first use of a code
        Schema::create('currencies', function (Blueprint $table) {
            $table->id();
            $table->char('code', 3)->unique();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('currency_id')->constrained('currencies');
            // Contact details (nullable)
            $table->string('contact_name')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone')->nullable();
            $table->text('address')->nullable();
            // Billing (non-sensitive, plain)
            $table->string('tax_number')->nullable();
            $table->text('billing_address')->nullable();
            $table->string('payment_terms')->nullable();
            // Bank (sensitive — encrypted at rest, must be text)
            $table->text('bank_name')->nullable();
            $table->text('bank_account_number')->nullable();
            $table->text('bank_swift')->nullable();
            $table->timestamps();
        });

        // Add FK from users.client_id → clients.id (nullable, nullOnDelete)
        // The column was added in Task 2 without a FK constraint.
        Schema::table('users', function (Blueprint $table) {
            $table->foreign('client_id')->references('id')->on('clients')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['client_id']);
        });

        Schema::dropIfExists('clients');
        Schema::dropIfExists('currencies');
    }
};
