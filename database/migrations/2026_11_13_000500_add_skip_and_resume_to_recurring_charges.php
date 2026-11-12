<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('recurring_charges', function (Blueprint $t) {
            $t->json('skipped_periods')->nullable()->after('due_day'); // periods passed over without billing ('Y-m' or 'Y')
            $t->date('resume_on')->nullable()->after('active');        // paused until this date; the daily run resumes it
        });
    }

    public function down(): void
    {
        Schema::table('recurring_charges', fn (Blueprint $t) => $t->dropColumn(['skipped_periods', 'resume_on']));
    }
};
