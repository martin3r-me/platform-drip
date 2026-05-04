<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // drip_bank_accounts: bban, bic → text + hash; initial_balance → text
        Schema::table('drip_bank_accounts', function (Blueprint $table) {
            $table->text('bban')->nullable()->change();
            $table->char('bban_hash', 64)->nullable()->index()->after('bban');
            $table->text('bic')->nullable()->change();
            $table->char('bic_hash', 64)->nullable()->index()->after('bic');
            $table->text('initial_balance')->nullable()->change();
        });

        // drip_bank_transactions: verschlüsselte string-Felder → text + fehlende hash-Spalten
        Schema::table('drip_bank_transactions', function (Blueprint $table) {
            // Composite-Index auf amount droppen vor Typänderung
            $table->dropIndex(['team_id', 'amount']);

            // Spaltentypen für verschlüsselte Felder: string → text
            $table->text('amount')->nullable()->change();
            $table->text('remittance_information')->nullable()->change();
            $table->text('remittance_information_structured')->nullable()->change();
            $table->text('remittance_information_unstructured')->nullable()->change();
            $table->text('debtor_name')->nullable()->change();
            $table->text('creditor_name')->nullable()->change();
            $table->text('debtor_account_iban')->nullable()->change();
            $table->text('creditor_account_iban')->nullable()->change();
            $table->text('debtor_agent')->nullable()->change();
            $table->text('creditor_agent')->nullable()->change();
            $table->text('counterparty_name')->nullable()->change();
            $table->text('ultimate_creditor')->nullable()->change();
            $table->text('ultimate_debtor')->nullable()->change();
            $table->text('additional_information')->nullable()->change();
            $table->text('additional_information_structured')->nullable()->change();
            $table->longText('balance_after_transaction')->nullable()->change();

            // Fehlende Hash-Spalten
            $table->char('debtor_agent_hash', 64)->nullable()->index()->after('debtor_agent');
            $table->char('creditor_agent_hash', 64)->nullable()->index()->after('creditor_agent');
        });

        // drip_requisitions: reference, accounts → text + hash-Spalten
        Schema::table('drip_requisitions', function (Blueprint $table) {
            // Index auf reference droppen vor Typänderung zu text
            $table->dropIndex(['reference']);

            $table->text('reference')->nullable()->change();
            $table->char('reference_hash', 64)->nullable()->index()->after('reference');
            $table->longText('accounts')->nullable()->change();
            $table->char('accounts_hash', 64)->nullable()->index()->after('accounts');
        });

        // drip_recurring_patterns: matchers, defaults → longText + hash-Spalten
        Schema::table('drip_recurring_patterns', function (Blueprint $table) {
            $table->longText('matchers')->nullable()->change();
            $table->char('matchers_hash', 64)->nullable()->index()->after('matchers');
            $table->longText('defaults')->nullable()->change();
            $table->char('defaults_hash', 64)->nullable()->index()->after('defaults');
        });
    }

    public function down(): void
    {
        Schema::table('drip_bank_accounts', function (Blueprint $table) {
            $table->dropIndex(['bban_hash']);
            $table->dropColumn('bban_hash');
            $table->dropIndex(['bic_hash']);
            $table->dropColumn('bic_hash');
            $table->string('bban')->nullable()->change();
            $table->string('bic')->nullable()->change();
            $table->decimal('initial_balance', 16, 4)->default(0)->change();
        });

        Schema::table('drip_bank_transactions', function (Blueprint $table) {
            $table->dropIndex(['debtor_agent_hash']);
            $table->dropColumn('debtor_agent_hash');
            $table->dropIndex(['creditor_agent_hash']);
            $table->dropColumn('creditor_agent_hash');
            $table->decimal('amount', 15, 2)->nullable()->change();
            $table->index(['team_id', 'amount']);
            $table->string('remittance_information')->nullable()->change();
            $table->string('remittance_information_structured')->nullable()->change();
            $table->string('remittance_information_unstructured')->nullable()->change();
            $table->string('debtor_name')->nullable()->change();
            $table->string('creditor_name')->nullable()->change();
            $table->string('debtor_account_iban')->nullable()->change();
            $table->string('creditor_account_iban')->nullable()->change();
            $table->string('debtor_agent')->nullable()->change();
            $table->string('creditor_agent')->nullable()->change();
            $table->string('counterparty_name')->nullable()->change();
            $table->string('ultimate_creditor')->nullable()->change();
            $table->string('ultimate_debtor')->nullable()->change();
            $table->string('additional_information')->nullable()->change();
            $table->string('additional_information_structured')->nullable()->change();
            $table->json('balance_after_transaction')->nullable()->change();
        });

        Schema::table('drip_requisitions', function (Blueprint $table) {
            $table->dropIndex(['reference_hash']);
            $table->dropColumn('reference_hash');
            $table->dropIndex(['accounts_hash']);
            $table->dropColumn('accounts_hash');
            $table->string('reference')->nullable()->index()->change();
            $table->json('accounts')->nullable()->change();
        });

        Schema::table('drip_recurring_patterns', function (Blueprint $table) {
            $table->dropIndex(['matchers_hash']);
            $table->dropColumn('matchers_hash');
            $table->dropIndex(['defaults_hash']);
            $table->dropColumn('defaults_hash');
            $table->json('matchers')->nullable()->change();
            $table->json('defaults')->nullable()->change();
        });
    }
};
