<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $t) {
            $t->json('portal_features')->nullable()->after('portal_blocked_at'); // opt-in portal pages/figures (Client::PORTAL_FEATURES); null = all hidden
        });
    }

    public function down(): void
    {
        Schema::table('clients', fn (Blueprint $t) => $t->dropColumn('portal_features'));
    }
};
