<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drip_bank_accounts', function (Blueprint $table) {
            $table->string('provider', 20)->default('manual')->after('external_id');
        });

        // Bestehende Accounts mit institution_id → gocardless
        DB::table('drip_bank_accounts')
            ->whereNotNull('institution_id')
            ->update(['provider' => 'gocardless']);

        Schema::table('drip_bank_accounts', function (Blueprint $table) {
            $table->index(['team_id', 'provider', 'external_id'], 'drip_ba_team_provider_external');
        });
    }

    public function down(): void
    {
        Schema::table('drip_bank_accounts', function (Blueprint $table) {
            $table->dropIndex('drip_ba_team_provider_external');
            $table->dropColumn('provider');
        });
    }
};
