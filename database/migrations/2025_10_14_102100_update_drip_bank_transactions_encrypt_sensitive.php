<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drip_bank_transactions', function (Blueprint $table) {
            $table->text('counterparty_iban')->nullable()->change();
            $table->char('counterparty_iban_hash', 64)->nullable()->index();
            $table->longText('reference')->nullable()->change();
            $table->char('reference_hash', 64)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('drip_bank_transactions', function (Blueprint $table) {
            $table->string('counterparty_iban')->nullable()->change();
            $table->dropColumn('counterparty_iban_hash');
            $table->string('reference')->nullable()->change();
            $table->dropColumn('reference_hash');
        });
    }
};


