<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drip_bank_accounts', function (Blueprint $table) {
            $table->timestamp('last_details_synced_at')->nullable()->after('closed_at');
            $table->timestamp('last_transactions_synced_at')->nullable()->after('last_details_synced_at');
        });
    }

    public function down(): void
    {
        Schema::table('drip_bank_accounts', function (Blueprint $table) {
            $table->dropColumn(['last_details_synced_at', 'last_transactions_synced_at']);
        });
    }
};
