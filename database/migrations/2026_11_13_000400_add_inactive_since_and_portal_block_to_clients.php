<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $t) {
            $t->date('inactive_since')->nullable()->after('status'); // set when the client stops being active
            $t->timestamp('portal_blocked_at')->nullable()->after('inactive_since'); // set = no portal sign-in for any of its logins
        });
        // best guess for clients already inactive or archived
        DB::table('clients')->where('status', '!=', 'active')->update(['inactive_since' => DB::raw('DATE(updated_at)')]);
    }

    public function down(): void
    {
        Schema::table('clients', fn (Blueprint $t) => $t->dropColumn(['inactive_since', 'portal_blocked_at']));
    }
};
