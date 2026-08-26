<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Eingangsbelege (easybill /incoming-documents) haben UUID-IDs statt Integer.
     * Der bisherige Fremdschlüssel `external_id` (unsignedBigInteger) fasst diese
     * nicht — daher: external_id nullable machen und einen String-Ref `external_ref`
     * ergänzen, der die UUID hält (bei Ausgangsbelegen null). Eindeutig bleibt der
     * Beleg über [team_id, provider, external_ref].
     */
    public function up(): void
    {
        Schema::table('drip_invoices', function (Blueprint $table) {
            // Ausgangsbelege: numerische easybill-Doc-ID. Eingangsbelege: null.
            $table->unsignedBigInteger('external_id')->nullable()->change();

            // Eingangsbelege: easybill-UUID (z.B. "01a03966-520f-…"). Outgoing: null.
            $table->string('external_ref')->nullable()->after('external_id');

            $table->unique(['team_id', 'provider', 'external_ref']);
        });
    }

    public function down(): void
    {
        Schema::table('drip_invoices', function (Blueprint $table) {
            $table->dropUnique(['team_id', 'provider', 'external_ref']);
            $table->dropColumn('external_ref');
            // external_id bewusst nullable belassen — ein Rückbau auf NOT NULL
            // würde an bestehenden Eingangsbelegen scheitern.
        });
    }
};
