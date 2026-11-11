<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('board_columns', fn (Blueprint $t) => $t->boolean('client_movable')->default(false));
    }

    public function down(): void
    {
        Schema::table('board_columns', fn (Blueprint $t) => $t->dropColumn('client_movable'));
    }
};
