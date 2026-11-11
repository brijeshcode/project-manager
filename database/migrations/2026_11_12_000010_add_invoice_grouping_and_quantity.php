<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // lines of one invoice sharing a label print as a single row ("Sprint 1 (Oct 22 – Nov 4): completed FLE-1, FLE-2")
        Schema::table('billable_lines', fn (Blueprint $t) => $t->string('invoice_label', 500)->nullable()->after('invoice_id'));
        Schema::table('invoices', function (Blueprint $t) {
            $t->boolean('show_quantity')->default(false)->after('terms');
        });
    }

    public function down(): void
    {
        Schema::table('billable_lines', fn (Blueprint $t) => $t->dropColumn('invoice_label'));
        Schema::table('invoices', fn (Blueprint $t) => $t->dropColumn('show_quantity'));
    }
};
