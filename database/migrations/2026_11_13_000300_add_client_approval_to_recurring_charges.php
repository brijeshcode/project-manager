<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // client_state: null | awaiting (client must approve before it starts) | cancel_requested | ended
        Schema::table('recurring_charges', function (Blueprint $t) {
            $t->string('client_state', 20)->nullable()->after('active');
            $t->text('client_note')->nullable()->after('client_state');
            $t->timestamp('client_approved_at')->nullable()->after('client_note');
            $t->text('cancel_note')->nullable()->after('client_approved_at');
            $t->timestamp('cancel_requested_at')->nullable()->after('cancel_note');
            $t->timestamp('ended_at')->nullable()->after('cancel_requested_at');
        });
    }

    public function down(): void
    {
        Schema::table('recurring_charges', fn (Blueprint $t) => $t->dropColumn(['client_state', 'client_note', 'client_approved_at', 'cancel_note', 'cancel_requested_at', 'ended_at']));
    }
};
