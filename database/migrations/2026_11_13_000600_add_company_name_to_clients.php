<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // the legal party invoices are billed to, when it differs from the client's name
        Schema::table('clients', fn (Blueprint $t) => $t->string('company_name')->nullable()->after('name'));
    }

    public function down(): void
    {
        Schema::table('clients', fn (Blueprint $t) => $t->dropColumn('company_name'));
    }
};
