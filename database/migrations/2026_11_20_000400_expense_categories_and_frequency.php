<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private const CATEGORIES = ['server' => 'Server', 'domain' => 'Domain', 'developer' => 'Developer fee', 'software' => 'Software', 'rent' => 'Rent', 'electricity' => 'Electricity', 'other' => 'Other'];

    public function up(): void
    {
        // editable in Settings → Expense categories
        Schema::create('expense_categories', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->unsignedInteger('position')->default(0);
            $t->timestamps();
        });
        $ids = [];
        foreach (array_values(self::CATEGORIES) as $i => $name) {
            $ids[$name] = DB::table('expense_categories')->insertGetId(['name' => $name, 'position' => $i + 1, 'created_at' => now(), 'updated_at' => now()]);
        }

        Schema::table('expenses', function (Blueprint $t) {
            $t->foreignId('expense_category_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $t->string('frequency')->nullable()->after('project_id'); // monthly|quarterly|half_yearly|yearly; null = one-off
            $t->unsignedSmallInteger('remind_days')->default(7)->after('frequency'); // show on the dashboard this many days before it's due
            $t->decimal('exchange_rate', 18, 6)->nullable()->after('amount_cents'); // to the local currency; 1 when it is local
        });
        foreach (self::CATEGORIES as $value => $name) {
            DB::table('expenses')->where('category', $value)->update(['expense_category_id' => $ids[$name]]);
        }
        DB::table('expenses')->where('repeats_monthly', true)->update(['frequency' => 'monthly']);
        Schema::table('expenses', function (Blueprint $t) {
            $t->dropColumn(['category', 'repeats_monthly']);
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $t) {
            $t->string('category')->default('other');
            $t->boolean('repeats_monthly')->default(false);
        });
        DB::table('expenses')->whereNotNull('frequency')->update(['repeats_monthly' => true]);
        Schema::table('expenses', function (Blueprint $t) {
            $t->dropConstrainedForeignId('expense_category_id');
            $t->dropColumn(['frequency', 'remind_days', 'exchange_rate']);
        });
        Schema::dropIfExists('expense_categories');
    }
};
