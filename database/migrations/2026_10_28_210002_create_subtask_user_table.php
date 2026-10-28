<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subtask_user', function (Blueprint $table) {
            $table->foreignId('subtask_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['subtask_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subtask_user');
    }
};
