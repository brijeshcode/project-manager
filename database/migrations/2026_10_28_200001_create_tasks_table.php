<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('module_id')->constrained()->cascadeOnDelete();
            $table->foreignId('creator_id')->constrained('users')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status')->default('draft');          // TaskStatus enum
            $table->boolean('is_idea')->default(false);
            $table->unsignedInteger('priority')->default(0);
            $table->float('estimate_hours')->default(0.0);
            $table->string('scope')->default('in');              // TaskScope enum
            $table->decimal('price', 12, 2)->nullable();
            $table->decimal('exchange_rate', 18, 6)->nullable();   // project currency -> local
            $table->decimal('local_price', 12, 2)->nullable();
            $table->boolean('client_visible')->default(false);
            $table->boolean('testing_enabled')->default(false);
            $table->string('testing_add_type')->nullable();      // 'percent'|'fixed'
            $table->decimal('testing_add_value', 12, 2)->nullable();
            $table->foreignId('board_column_id')->nullable()->constrained('board_columns')->nullOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->date('deadline')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
