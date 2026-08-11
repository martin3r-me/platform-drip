<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Beleg-Status je Eingang — die Gegenrichtung zur Belege-Worklist.
     *
     * Nicht jeder Eingang hat eine Rechnung: Finanzamt-Erstattungen, BAFA-
     * Zuschüsse, Ausleihungen innerhalb der Gruppe und die 0,00-€-Rechnungs-
     * abschlüsse der Bank sind vollständig belegfrei. Ohne eigenen Status
     * stünden die dauerhaft als „nicht zugeordnet" in der Liste und würden die
     * echten Lücken zudecken.
     *
     *   open        — Eingang wartet auf einen Beleg (die eigentliche Worklist)
     *   matched     — vollständig durch Belege gedeckt
     *   partial     — teilweise gedeckt (Rest offen)
     *   no_invoice  — bewusst ohne Beleg (Steuern, Zuschüsse, Ausleihungen, …)
     */
    public function up(): void
    {
        Schema::table('drip_bank_transactions', function (Blueprint $table) {
            $table->string('invoice_status')->default('open')->after('category_skipped');
            $table->index(['team_id', 'invoice_status']);
        });
    }

    public function down(): void
    {
        Schema::table('drip_bank_transactions', function (Blueprint $table) {
            $table->dropIndex(['team_id', 'invoice_status']);
            $table->dropColumn('invoice_status');
        });
    }
};
