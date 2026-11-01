<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_documents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $t->string('path');
            $t->string('original_name');
            $t->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_documents');
    }
};
