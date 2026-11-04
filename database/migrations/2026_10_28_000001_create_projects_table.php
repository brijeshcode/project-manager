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
            $table->string('key', 5)->unique();                    // task code prefix (KEY-12)
            $table->unsignedInteger('task_counter')->default(0);   // last task number handed out
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
            $table->string('template')->default('software');   // board template copied at create (BoardTemplates)
            $table->timestamps();
        });

        Schema::create('board_columns', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->string('stage');                                // not_started|active|review|done
            $t->unsignedInteger('position')->default(0);
            $t->unsignedInteger('wip_limit')->nullable();       // soft limit: the count turns amber at it, red over it
            $t->timestamps();
        });

        Schema::create('sprints', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->text('goal')->nullable();
            $t->date('starts_on');
            $t->date('ends_on');
            $t->string('status')->default('planned');   // planned|active|completed
            $t->timestamp('completed_at')->nullable();
            // values (cents) frozen when the sprint completes: what was planned, finished, and carried over
            $t->unsignedBigInteger('planned_value_cents')->nullable();
            $t->unsignedBigInteger('done_value_cents')->nullable();
            $t->unsignedBigInteger('carried_value_cents')->nullable();
            $t->timestamps();
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
        Schema::dropIfExists('sprints');
        Schema::dropIfExists('board_columns');
        Schema::dropIfExists('projects');
    }
};
