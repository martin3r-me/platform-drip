<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Zuordnung Beleg ↔ Bank-Transaktion als n:m.
     *
     * 1:1 reicht nicht: die Mehrheit der echten Eingänge sind Sammelzahlungen
     * (eine Überweisung über 571,20 € begleicht 14 Rechnungen, eine über
     * 7.197,55 € elf). Umgekehrt kann eine Rechnung in Raten bezahlt werden.
     * `amount_applied_cents` hält fest, welcher Teil des Eingangs auf welchen
     * Beleg entfällt — die Summe je Beleg ergibt den Bezahlstatus.
     *
     * `drip_invoices.matched_transaction_id` bleibt als „führende" Transaktion
     * erhalten, damit bestehende Views nicht brechen.
     */
    public function up(): void
    {
        Schema::create('drip_invoice_transaction', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('drip_invoice_id')->constrained('drip_invoices')->cascadeOnDelete();
            $table->foreignId('bank_transaction_id')->constrained('drip_bank_transactions')->cascadeOnDelete();

            // Auf diesen Beleg entfallender Anteil des Eingangs (immer positiv).
            $table->bigInteger('amount_applied_cents')->default(0);

            // number | number_sum | amount_party | amount | manual
            $table->string('match_type')->default('number');
            // high | medium | low
            $table->string('confidence')->default('high');

            // Automatisch zugeordnet, aber noch nicht bestätigt → Vorschlag.
            $table->boolean('is_confirmed')->default(false);
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();

            $table->timestamps();

            $table->unique(['drip_invoice_id', 'bank_transaction_id'], 'drip_inv_tx_unique');
            $table->index(['team_id', 'bank_transaction_id']);
        });

        // Bestehende 1:1-Zuordnungen in die Pivot überführen, damit der
        // Bezahlstatus nicht auf null zurückfällt.
        $this->backfillExistingMatches();
    }

    private function backfillExistingMatches(): void
    {
        $rows = DB::table('drip_invoices')
            ->whereNotNull('matched_transaction_id')
            ->whereNull('deleted_at')
            ->get(['id', 'team_id', 'matched_transaction_id', 'amount_gross_cents', 'match_confidence']);

        $now = now();

        foreach ($rows as $row) {
            DB::table('drip_invoice_transaction')->insertOrIgnore([
                'team_id' => $row->team_id,
                'drip_invoice_id' => $row->id,
                'bank_transaction_id' => $row->matched_transaction_id,
                'amount_applied_cents' => abs((int) $row->amount_gross_cents),
                'match_type' => $row->match_confidence ?: 'amount',
                'confidence' => $row->match_confidence === 'number' ? 'high' : 'medium',
                'is_confirmed' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('drip_invoice_transaction');
    }
};
