<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurring_charges', function (Blueprint $t) {
            $t->id();
            $t->foreignId('client_id')->constrained()->cascadeOnDelete();
            $t->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $t->string('description');
            $t->integer('amount_cents');
            $t->decimal('exchange_rate', 18, 6)->nullable();   // charge currency (project or client) -> local
            $t->bigInteger('local_amount_cents')->nullable();
            $t->string('frequency');
            $t->date('next_due_on');
            $t->unsignedTinyInteger('due_day'); // anchor day so Jan 31 -> Feb 28 -> Mar 31 does not drift
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recurring_charges');
    }
};
