<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Richtung eines Belegs: outgoing = Ausgangsbeleg (wir stellen, Forderung),
     * incoming = Eingangsbeleg (Lieferantenrechnung, Verbindlichkeit). Macht die
     * „Offene Belege"-Sicht richtungsfähig — easybill ist outgoing, künftige
     * Eingangsrechnungs-Connector setzen incoming.
     */
    public function up(): void
    {
        Schema::table('drip_invoices', function (Blueprint $table) {
            $table->string('direction')->default('outgoing')->after('type');
            $table->index(['team_id', 'direction', 'match_status']);
        });
    }

    public function down(): void
    {
        Schema::table('drip_invoices', function (Blueprint $table) {
            $table->dropIndex(['team_id', 'direction', 'match_status']);
            $table->dropColumn('direction');
        });
    }
};
