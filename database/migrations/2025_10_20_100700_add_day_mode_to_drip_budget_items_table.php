<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drip_budget_items', function (Blueprint $table) {
            $table->string('day_mode', 20)->default('fixed')->after('day_of_month');
        });
    }

    public function down(): void
    {
        Schema::table('drip_budget_items', function (Blueprint $table) {
            $table->dropColumn('day_mode');
        });
    }
};
