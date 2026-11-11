<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['modules', 'tasks'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->string('request_status')->nullable(); // null = approved, pending | rejected = client request
                $t->text('request_note')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['modules', 'tasks'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn(['request_status', 'request_note']));
        }
    }
};
