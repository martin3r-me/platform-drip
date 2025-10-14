<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('drip_bank_transaction_recurring_pattern')) {
            Schema::create('drip_bank_transaction_recurring_pattern', function (Blueprint $table) {
                $table->unsignedBigInteger('bank_transaction_id');
                $table->unsignedBigInteger('recurring_pattern_id');
                $table->timestamps();

                $table->primary(['bank_transaction_id', 'recurring_pattern_id'], 'pk_txn_recurring');
                $table->foreign('bank_transaction_id', 'fk_dbt_rp_txn')
                    ->references('id')->on('drip_bank_transactions')->onDelete('cascade');
                $table->foreign('recurring_pattern_id', 'fk_dbt_rp_pat')
                    ->references('id')->on('drip_recurring_patterns')->onDelete('cascade');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('drip_bank_transaction_recurring_pattern');
    }
};


