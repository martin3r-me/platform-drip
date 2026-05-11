<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drip_budget_items', function (Blueprint $table) {
            $table->string('status', 20)->default('active')->after('notes');
            $table->string('source_type', 20)->nullable()->after('status');
            $table->string('source_counterparty')->nullable()->after('source_type');
            $table->string('source_iban')->nullable()->after('source_counterparty');
            $table->smallInteger('source_month_count')->nullable()->after('source_iban');
            $table->decimal('source_avg_amount', 15, 2)->nullable()->after('source_month_count');
            $table->timestamp('suggested_at')->nullable()->after('source_avg_amount');
            $table->timestamp('confirmed_at')->nullable()->after('suggested_at');
            $table->timestamp('dismissed_at')->nullable()->after('confirmed_at');

            $table->index(['team_id', 'status']);
        });

        // Data migration: existing items → status=active, source_type=manual
        DB::table('drip_budget_items')
            ->whereNull('deleted_at')
            ->update([
                'status' => 'active',
                'source_type' => 'manual',
            ]);
    }

    public function down(): void
    {
        Schema::table('drip_budget_items', function (Blueprint $table) {
            $table->dropIndex(['team_id', 'status']);
            $table->dropColumn([
                'status', 'source_type', 'source_counterparty', 'source_iban',
                'source_month_count', 'source_avg_amount',
                'suggested_at', 'confirmed_at', 'dismissed_at',
            ]);
        });
    }
};
