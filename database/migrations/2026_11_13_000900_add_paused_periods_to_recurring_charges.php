<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('recurring_charges', function (Blueprint $t) {
            $t->json('paused_periods')->nullable()->after('skipped_periods'); // periods passed over while paused ('Y-m' or 'Y')
        });
    }

    public function down(): void
    {
        Schema::table('recurring_charges', fn (Blueprint $t) => $t->dropColumn('paused_periods'));
    }
};
