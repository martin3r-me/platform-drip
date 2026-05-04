<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // drip_bank_accounts: initial_balance_hash fehlt
        Schema::table('drip_bank_accounts', function (Blueprint $table) {
            $table->char('initial_balance_hash', 64)->nullable()->index()->after('initial_balance');
        });

        // drip_bank_transactions: 14 fehlende Hash-Spalten
        Schema::table('drip_bank_transactions', function (Blueprint $table) {
            $table->char('amount_hash', 64)->nullable()->index()->after('amount');
            $table->char('balance_after_transaction_hash', 64)->nullable()->after('balance_after_transaction');
            $table->char('debtor_account_iban_hash', 64)->nullable()->index()->after('debtor_account_iban');
            $table->char('creditor_account_iban_hash', 64)->nullable()->index()->after('creditor_account_iban');
            $table->char('counterparty_name_hash', 64)->nullable()->index()->after('counterparty_name');
            $table->char('debtor_name_hash', 64)->nullable()->index()->after('debtor_name');
            $table->char('creditor_name_hash', 64)->nullable()->index()->after('creditor_name');
            $table->char('ultimate_creditor_hash', 64)->nullable()->after('ultimate_creditor');
            $table->char('ultimate_debtor_hash', 64)->nullable()->after('ultimate_debtor');
            $table->char('remittance_information_hash', 64)->nullable()->after('remittance_information');
            $table->char('remittance_information_structured_hash', 64)->nullable()->after('remittance_information_structured');
            $table->char('remittance_information_unstructured_hash', 64)->nullable()->after('remittance_information_unstructured');
            $table->char('additional_information_hash', 64)->nullable()->after('additional_information');
            $table->char('additional_information_structured_hash', 64)->nullable()->after('additional_information_structured');
        });
    }

    public function down(): void
    {
        Schema::table('drip_bank_accounts', function (Blueprint $table) {
            $table->dropColumn('initial_balance_hash');
        });

        Schema::table('drip_bank_transactions', function (Blueprint $table) {
            $table->dropColumn([
                'amount_hash',
                'balance_after_transaction_hash',
                'debtor_account_iban_hash',
                'creditor_account_iban_hash',
                'counterparty_name_hash',
                'debtor_name_hash',
                'creditor_name_hash',
                'ultimate_creditor_hash',
                'ultimate_debtor_hash',
                'remittance_information_hash',
                'remittance_information_structured_hash',
                'remittance_information_unstructured_hash',
                'additional_information_hash',
                'additional_information_structured_hash',
            ]);
        });
    }
};
