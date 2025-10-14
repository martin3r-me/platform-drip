<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drip_bank_transactions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->unsignedBigInteger('bank_account_id')->index();
            $table->unsignedBigInteger('category_id')->nullable()->index();
            $table->unsignedBigInteger('recurring_pattern_id')->nullable()->index();

            $table->string('transaction_id')->unique();
            $table->date('booking_date')->nullable();
            $table->dateTime('booking_date_time')->nullable();
            $table->date('value_date')->nullable();
            $table->dateTime('value_date_time')->nullable();
            $table->date('booked_at')->index();

            $table->decimal('amount', 15, 2)->nullable();
            $table->string('currency', 10)->nullable();

            $table->string('remittance_information')->nullable();
            $table->string('remittance_information_structured')->nullable();
            $table->json('remittance_information_structured_array')->nullable();
            $table->string('remittance_information_unstructured')->nullable();
            $table->json('remittance_information_unstructured_array')->nullable();

            $table->string('debtor_name')->nullable();
            $table->string('creditor_name')->nullable();
            $table->string('debtor_account_iban')->nullable();
            $table->string('creditor_account_iban')->nullable();
            $table->string('debtor_agent')->nullable();
            $table->string('creditor_agent')->nullable();

            $table->string('transaction_type')->nullable();
            $table->string('bank_transaction_code')->nullable();
            $table->string('proprietary_bank_transaction_code')->nullable();
            $table->string('internal_transaction_id')->nullable();
            $table->string('entry_reference')->nullable();
            $table->string('end_to_end_id')->nullable();
            $table->string('mandate_id')->nullable();

            $table->string('merchant_category_code')->nullable();
            $table->string('check_id')->nullable();
            $table->string('creditor_id')->nullable();
            $table->string('purpose_code')->nullable();
            $table->string('ultimate_creditor')->nullable();
            $table->string('ultimate_debtor')->nullable();

            $table->json('currency_exchange')->nullable();
            $table->json('balance_after_transaction')->nullable();
            $table->json('additional_data_structured')->nullable();
            $table->string('additional_information')->nullable();
            $table->string('additional_information_structured')->nullable();

            // Legacy fields for compatibility
            $table->string('counterparty_name')->nullable();
            $table->text('counterparty_iban')->nullable();
            $table->char('counterparty_iban_hash', 64)->nullable()->index();
            $table->longText('reference')->nullable();
            $table->char('reference_hash', 64)->nullable()->index();
            $table->string('direction', 8)->default('credit')->index(); // credit|debit
            $table->string('status', 16)->default('booked')->index(); // booked|pending
            $table->json('metadata')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('team_id')->references('id')->on('teams')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('bank_account_id')->references('id')->on('drip_bank_accounts')->onDelete('cascade');
            $table->foreign('category_id')->references('id')->on('drip_bank_transaction_categories')->nullOnDelete();
            $table->index(['team_id', 'booked_at']);
            $table->index(['team_id', 'amount']);
            $table->index(['team_id', 'direction']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drip_bank_transactions');
    }
};


