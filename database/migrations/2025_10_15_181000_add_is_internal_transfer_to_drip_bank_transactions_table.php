<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drip_bank_transactions', function (Blueprint $table) {
            if (!Schema::hasColumn('drip_bank_transactions', 'is_internal_transfer')) {
                $table->boolean('is_internal_transfer')->default(false)->after('direction')->index();
            }

            // Optional: group_id spiegeln für schnelle Gruppenfilter
            if (!Schema::hasColumn('drip_bank_transactions', 'group_id')) {
                $table->unsignedBigInteger('group_id')->nullable()->after('bank_account_id')->index();
            }

            // Konsolidierter Gegenparteiname
            if (!Schema::hasColumn('drip_bank_transactions', 'counterparty_name')) {
                $table->string('counterparty_name', 255)->nullable()->after('creditor_name')->index();
            }

            // Vereinfachter Typ (income/expense/transfer)
            if (!Schema::hasColumn('drip_bank_transactions', 'transaction_type_simple')) {
                $table->string('transaction_type_simple', 32)->nullable()->after('transaction_type')->index();
            }
        });

        // Zusammengesetzter Index für häufige KPI-Queries (falls group_id soeben hinzugefügt wurde)
        if (Schema::hasColumn('drip_bank_transactions', 'group_id') && Schema::hasColumn('drip_bank_transactions', 'booked_at')) {
            Schema::table('drip_bank_transactions', function (Blueprint $table) {
                // Index-Name kurz halten
                $table->index(['team_id', 'group_id', 'booked_at'], 'drip_tx_team_group_booked_idx');
            });
        }
    }

    public function down(): void
    {
        // Drop composite index first (it references group_id which we'll drop below)
        if (Schema::hasColumn('drip_bank_transactions', 'group_id')) {
            try {
                Schema::table('drip_bank_transactions', function (Blueprint $table) {
                    $table->dropIndex('drip_tx_team_group_booked_idx');
                });
            } catch (\Throwable) {
                // Index may not exist
            }
        }

        // Drop columns (MySQL auto-drops single-column indexes when the column is dropped)
        Schema::table('drip_bank_transactions', function (Blueprint $table) {
            $columns = ['is_internal_transfer', 'transaction_type_simple', 'counterparty_name', 'group_id'];

            foreach ($columns as $column) {
                if (Schema::hasColumn('drip_bank_transactions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};


