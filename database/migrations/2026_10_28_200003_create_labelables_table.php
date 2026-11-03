<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // polymorphic: tasks (and modules, subtasks) share the labels table
        Schema::create('labelables', function (Blueprint $table) {
            $table->foreignId('label_id')->constrained()->cascadeOnDelete();
            $table->morphs('labelable');
            $table->primary(['label_id', 'labelable_type', 'labelable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('labelables');
    }
};
