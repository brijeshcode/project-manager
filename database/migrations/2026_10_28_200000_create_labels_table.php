<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('labels', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('color', 7)->default('#6b7280');
            $table->string('icon', 40)->nullable();   // a curated icon name or a pasted emoji
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('labels');
    }
};
