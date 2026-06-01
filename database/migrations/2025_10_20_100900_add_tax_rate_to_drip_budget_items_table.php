<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drip_budget_items', function (Blueprint $table) {
            $table->decimal('tax_rate', 5, 2)->nullable()->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('drip_budget_items', function (Blueprint $table) {
            $table->dropColumn('tax_rate');
        });
    }
};
