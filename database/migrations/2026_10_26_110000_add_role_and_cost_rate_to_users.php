<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // FK constraint added in Task 3 once the clients table exists.
            $table->foreignId('client_id')->nullable();
            $table->string('cost_rate_type')->nullable();
            $table->decimal('cost_rate_amount', 12, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['client_id', 'cost_rate_type', 'cost_rate_amount']);
        });
    }
};
