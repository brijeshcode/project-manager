<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $t) {
            $t->string('type')->default('company');
            $t->string('client_code')->nullable()->unique();
            $t->string('status')->default('active');
            $t->string('website')->nullable();
            $t->string('city')->nullable();
            $t->string('state')->nullable();
            $t->string('postal_code')->nullable();
            $t->string('country')->nullable();
            $t->text('notes')->nullable();
        });

        // Back-fill existing rows in id order.
        foreach (DB::table('clients')->orderBy('id')->pluck('id') as $i => $id) {
            DB::table('clients')->where('id', $id)->update(['client_code' => sprintf('CLI-%04d', $i + 1)]);
        }
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $t) {
            $t->dropUnique(['client_code']);
            $t->dropColumn(['type', 'client_code', 'status', 'website', 'city', 'state', 'postal_code', 'country', 'notes']);
        });
    }
};
