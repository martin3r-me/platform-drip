<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Leichter Spiegel der Ausgangsrechnungen (easybill/lexoffice) für die
     * Belege-View und den Zahlungs-Abgleich. Drip besitzt die Rechnungen NICHT
     * — sie bleiben im Rechnungstool; hier steht nur so viel, wie zum Anzeigen
     * und Matchen nötig ist. Beträge als Integer-Cent (kein Float), damit exakte
     * Betrags-Treffer indexierbar sind.
     */
    public function up(): void
    {
        Schema::create('drip_invoices', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();

            // Herkunft (Provider-agnostisch: easybill, lexoffice, …)
            $table->string('provider')->default('easybill');
            $table->unsignedBigInteger('external_id');            // easybill document id
            $table->string('number')->nullable();                 // Rechnungsnummer (z.B. 4100105)
            $table->string('type')->default('INVOICE');           // INVOICE | STORNO | CREDIT | …
            $table->string('external_status')->nullable();        // DONE, …
            $table->boolean('is_draft')->default(false);

            // Kunde
            $table->unsignedBigInteger('customer_external_id')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_iban')->nullable();          // aus customer_snapshot, wenn gepflegt

            // Beträge in Cent (Brutto kann bei STORNO negativ sein)
            $table->bigInteger('amount_gross_cents')->default(0);
            $table->bigInteger('amount_net_cents')->nullable();
            $table->bigInteger('paid_amount_cents')->default(0);  // easybills EIGENER Bezahlstatus
            $table->string('currency', 3)->default('EUR');

            $table->date('document_date')->nullable();
            $table->date('due_date')->nullable();
            $table->timestamp('external_paid_at')->nullable();    // easybills paid_at

            // Drip-seitiger Abgleich gegen die tatsächlichen Bank-Eingänge
            $table->string('match_status')->default('open');      // open | matched | partial
            $table->foreignId('matched_transaction_id')->nullable()
                ->constrained('drip_bank_transactions')->nullOnDelete();
            $table->string('match_confidence')->nullable();       // number | amount_party | amount
            $table->timestamp('matched_at')->nullable();

            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['team_id', 'provider', 'external_id']);
            $table->index(['team_id', 'number']);
            $table->index(['team_id', 'match_status']);
            $table->index(['team_id', 'document_date']);
            $table->index(['team_id', 'amount_gross_cents']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drip_invoices');
    }
};
