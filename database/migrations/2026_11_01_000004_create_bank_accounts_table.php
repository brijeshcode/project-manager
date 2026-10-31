<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('bank_accounts', function (Blueprint $t) {
            $t->id();
            $t->string('label');
            $t->string('account_name');
            $t->string('bank_name');
            $t->text('account_number');
            $t->text('ifsc_swift');
            $t->string('email')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_accounts');
    }
};
