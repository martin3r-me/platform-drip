<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * „Bewusst ohne Kategorie": die Transaktion wurde gesichtet und die
     * Entscheidung getroffen, sie NICHT zu kategorisieren. Abgrenzung zu
     * is_disregarded: disregarded = zählt gar nicht (Storno); category_skipped
     * = zählt normal im Cashflow, ist nur bewusst kategorielos. Trennt den
     * echten Triage-Backlog (Posteingang) von „schon entschieden".
     */
    public function up(): void
    {
        Schema::table('drip_bank_transactions', function (Blueprint $table) {
            $table->boolean('category_skipped')->default(false)->after('category_id');
            $table->index(['team_id', 'category_id', 'category_skipped', 'is_disregarded'], 'drip_tx_triage_idx');
        });
    }

    public function down(): void
    {
        Schema::table('drip_bank_transactions', function (Blueprint $table) {
            $table->dropIndex('drip_tx_triage_idx');
            $table->dropColumn('category_skipped');
        });
    }
};
