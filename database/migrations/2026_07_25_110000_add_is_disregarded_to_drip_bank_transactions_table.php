<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drip_bank_transactions', function (Blueprint $table) {
            // Transaktion zählt NICHT im Cashflow (z. B. abgelehnte/gelöschte MOSS-Kartenzahlung).
            $table->boolean('is_disregarded')->default(false)->index()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('drip_bank_transactions', function (Blueprint $table) {
            $table->dropColumn('is_disregarded');
        });
    }
};
