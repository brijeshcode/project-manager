<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('author_id')->constrained('users')->cascadeOnDelete();
            $table->morphs('commentable');
            $table->text('body');
            $table->foreignId('edited_by')->nullable()->constrained('users')->nullOnDelete(); // last editor, null = never edited
            $table->foreignId('parent_id')->nullable()->constrained('comments')->cascadeOnDelete(); // replies
            $table->timestamps();
        });

        // who changed what, on any subject (module now; tasks/subtasks later)
        Schema::create('activities', function (Blueprint $table) {
            $table->id();
            $table->morphs('subject');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event');
            $table->string('field')->nullable();
            $table->text('old')->nullable();
            $table->text('new')->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        // private files (local disk) behind an authorised download route
        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->morphs('attachable');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('path');
            $table->string('name');
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
        Schema::dropIfExists('activities');
        Schema::dropIfExists('comments');
    }
};
