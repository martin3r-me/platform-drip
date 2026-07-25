<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drip_recurring_patterns', function (Blueprint $table) {
            // Höhere Priorität gewinnt bei mehreren passenden Regeln (deterministisch).
            $table->integer('priority')->default(0)->index()->after('bank_transaction_category_id');
            // Regel deaktivierbar, ohne sie zu löschen.
            $table->boolean('is_active')->default(true)->index()->after('priority');
        });
    }

    public function down(): void
    {
        Schema::table('drip_recurring_patterns', function (Blueprint $table) {
            $table->dropColumn(['priority', 'is_active']);
        });
    }
};
