<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            // Nullable string: 'frontend'|'backend'|null (unsectioned = visible to all scopes).
            // Mirrors AccessScope values so access_scope filtering is a direct string match.
            $table->string('section')->nullable()->after('position');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn('section');
        });
    }
};
