<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('currency_id')->constrained('currencies');
            $table->string('name');
            $table->decimal('buffer_percent', 5, 2)->default(0);
            $table->text('aim')->nullable();
            foreach (['initial_budget', 'addons_amount', 'final_budget'] as $col) {
                $table->decimal($col, 14, 2)->default(0);
                $table->decimal($col.'_local', 14, 2)->nullable();
            }
            $table->decimal('exchange_rate', 18, 6)->nullable();   // project currency -> local
            $table->date('start_date')->nullable();
            $table->date('deadline')->nullable();     // manual deadline; changes are logged
            $table->date('completed_at')->nullable();
            $table->string('status')->default('draft');
            $table->timestamps();
        });

        Schema::create('project_deadline_changes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained();
            $t->date('old_deadline')->nullable();
            $t->date('new_deadline');
            $t->text('reason')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_deadline_changes');
        Schema::dropIfExists('projects');
    }
};
