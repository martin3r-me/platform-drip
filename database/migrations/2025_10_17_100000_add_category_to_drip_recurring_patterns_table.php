<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drip_recurring_patterns', function (Blueprint $table) {
            $table->string('frequency', 16)->nullable()->change();

            $table->unsignedBigInteger('bank_transaction_category_id')->nullable()->after('defaults');
            $table->foreign('bank_transaction_category_id')
                ->references('id')
                ->on('drip_bank_transaction_categories')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('drip_recurring_patterns', function (Blueprint $table) {
            $table->dropForeign(['bank_transaction_category_id']);
            $table->dropColumn('bank_transaction_category_id');

            $table->string('frequency', 16)->nullable(false)->change();
        });
    }
};
