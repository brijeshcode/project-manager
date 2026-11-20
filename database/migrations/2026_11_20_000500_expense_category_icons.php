<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private const DEFAULTS = [
        'Server' => ['server', '#2563eb'], 'Domain' => ['globe', '#0891b2'], 'Developer fee' => ['code', '#7c3aed'],
        'Software' => ['layout-dashboard', '#db2777'], 'Rent' => ['landmark', '#d97706'], 'Electricity' => ['zap', '#ca8a04'], 'Other' => ['tag', '#6b7280'],
    ];

    public function up(): void
    {
        Schema::table('expense_categories', function (Blueprint $t) {
            $t->string('color', 7)->default('#6b7280')->after('name');
            $t->string('icon', 40)->nullable()->after('color'); // curated icon name or emoji, like labels
            $t->string('icon_path')->nullable()->after('icon'); // uploaded image on the public disk; wins over icon
        });
        foreach (self::DEFAULTS as $name => [$icon, $color]) {
            DB::table('expense_categories')->where('name', $name)->update(['icon' => $icon, 'color' => $color]);
        }
    }

    public function down(): void
    {
        Schema::table('expense_categories', fn (Blueprint $t) => $t->dropColumn(['color', 'icon', 'icon_path']));
    }
};
