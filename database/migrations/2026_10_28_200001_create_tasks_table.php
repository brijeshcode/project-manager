<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_types', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('color', 7)->default('#6b7280');
            $table->string('icon', 40)->nullable();   // a curated icon name or a pasted emoji
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_system')->default(false);
            $table->timestamps();
        });

        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('module_id')->constrained()->cascadeOnDelete();
            $table->foreignId('creator_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('task_type_id')->nullable()->constrained('task_types')->nullOnDelete();
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
            $table->date('started_at')->nullable();     // first time in an active-stage column (auto, editable)
            $table->date('completed_at')->nullable();   // while in a done-stage column (auto, editable)
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
        Schema::dropIfExists('task_types');
    }
};
