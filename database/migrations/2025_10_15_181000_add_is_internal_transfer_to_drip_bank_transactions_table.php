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
        Schema::table('drip_bank_transactions', function (Blueprint $table) {
            if (Schema::hasColumn('drip_bank_transactions', 'is_internal_transfer')) {
                $table->dropColumn('is_internal_transfer');
            }
            if (Schema::hasColumn('drip_bank_transactions', 'transaction_type_simple')) {
                $table->dropIndex(['transaction_type_simple']);
                $table->dropColumn('transaction_type_simple');
            }
            if (Schema::hasColumn('drip_bank_transactions', 'counterparty_name')) {
                $table->dropIndex(['counterparty_name']);
                $table->dropColumn('counterparty_name');
            }
            if (Schema::hasColumn('drip_bank_transactions', 'group_id')) {
                $table->dropIndex(['group_id']);
                $table->dropColumn('group_id');
            }
        });

        if (Schema::hasTable('drip_bank_transactions')) {
            Schema::table('drip_bank_transactions', function (Blueprint $table) {
                $table->dropIndex('drip_tx_team_group_booked_idx');
            });
        }
    }
};


