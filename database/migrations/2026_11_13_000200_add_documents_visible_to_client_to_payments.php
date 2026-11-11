<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // the client always sees the payment; its documents only when this is on
        Schema::table('payments', fn (Blueprint $t) => $t->boolean('documents_visible_to_client')->default(false)->after('notes'));
    }

    public function down(): void
    {
        Schema::table('payments', fn (Blueprint $t) => $t->dropColumn('documents_visible_to_client'));
    }
};
