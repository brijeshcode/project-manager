<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('resource_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resource_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('label');
            $table->text('value')->nullable();
            $table->string('original_name')->nullable();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('resource_item_user', function (Blueprint $table) {
            $table->foreignId('resource_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['resource_item_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_item_user');
        Schema::dropIfExists('resource_items');
        Schema::dropIfExists('resources');
    }
};
