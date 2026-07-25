<?php

namespace Platform\Drip\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Platform\Core\Models\Team;
use Platform\Drip\Models\BankTransaction;
use Platform\Drip\Models\DripInvoice;

/**
 * Gleicht offene Ausgangsrechnungen gegen die tatsächlichen Bank-Eingänge ab.
 *
 * Match-Kette mit absteigender Sicherheit:
 *   1. Rechnungsnummer im Verwendungszweck (+ Betrag)  → 'number'        (hoch)
 *   2. Betrag + Gegenpartei-Name + Datum nah           → 'amount_party'  (mittel)
 *   3. Betrag + Datum nah                              → 'amount'        (niedrig)
 *
 * Bank-Beträge sind verschlüsselt (kein SQL-SUM/WHERE) → Vergleich in PHP.
 */
class InvoiceMatchService
{
    private const AMOUNT_EPSILON = 0.005;   // Cent-Toleranz
    private const DATE_WINDOW_DAYS = 120;   // Zahlung darf so weit vom Belegdatum liegen

    /**
     * Gleicht alle offenen Rechnungen eines Teams ab.
     *
     * @return array{matched:int, checked:int}
     */
    public function matchForTeam(Team $team): array
    {
        $teamId = (int) $team->id;

        $openInvoices = DripInvoice::forTeam($teamId)
            ->invoices()
            ->open()
            ->where('amount_gross_cents', '>', 0)
            ->orderBy('document_date')
            ->get();

        if ($openInvoices->isEmpty()) {
            return ['matched' => 0, 'checked' => 0];
        }

        // Bereits einer Rechnung zugeordnete Transaktionen ausschließen.
        $usedTxIds = DripInvoice::forTeam($teamId)
            ->whereNotNull('matched_transaction_id')
            ->pluck('matched_transaction_id')
            ->map(fn ($id) => (int) $id)
            ->all();
        $usedTxIds = array_flip($usedTxIds);

        // Kandidaten: gezählte Eingänge (credit), Beträge in PHP entschlüsselt.
        $credits = BankTransaction::where('team_id', $teamId)
            ->where('direction', 'credit')
            ->counted()
            ->get()
            ->reject(fn ($tx) => isset($usedTxIds[(int) $tx->id]));

        $matched = 0;

        foreach ($openInvoices as $invoice) {
            $hit = $this->findTransactionForInvoice($invoice, $credits);
            if (!$hit) {
                continue;
            }

            [$tx, $confidence] = $hit;

            $invoice->update([
                'match_status' => 'matched',
                'matched_transaction_id' => (int) $tx->id,
                'match_confidence' => $confidence,
                'matched_at' => now(),
            ]);

            // Transaktion aus dem Kandidaten-Pool entfernen (keine Doppelzuordnung).
            $credits = $credits->reject(fn ($c) => (int) $c->id === (int) $tx->id);
            $matched++;
        }

        return ['matched' => $matched, 'checked' => $openInvoices->count()];
    }

    /**
     * Versucht, für EINEN neuen Eingang eine offene Rechnung zu finden und zu
     * verknüpfen (Live-Automatch beim Transaktions-Import).
     */
    public function matchTransaction(BankTransaction $tx): ?DripInvoice
    {
        if ($tx->direction !== 'credit' || $tx->is_disregarded) {
            return null;
        }

        $openInvoices = DripInvoice::forTeam((int) $tx->team_id)
            ->invoices()
            ->open()
            ->where('amount_gross_cents', '>', 0)
            ->whereNull('matched_transaction_id')
            ->get();

        $hit = $this->findInvoiceForTransaction($tx, $openInvoices);
        if (!$hit) {
            return null;
        }

        [$invoice, $confidence] = $hit;

        $invoice->update([
            'match_status' => 'matched',
            'matched_transaction_id' => (int) $tx->id,
            'match_confidence' => $confidence,
            'matched_at' => now(),
        ]);

        return $invoice;
    }

    /**
     * Bestes Transaktions-Match für eine Rechnung aus dem Kandidaten-Pool.
     *
     * @return array{0:BankTransaction,1:string}|null
     */
    private function findTransactionForInvoice(DripInvoice $invoice, Collection $credits): ?array
    {
        $gross = $invoice->amount_gross;

        $amountMatches = $credits->filter(fn ($tx) => $this->amountMatches($tx, $gross));
        if ($amountMatches->isEmpty()) {
            return null;
        }

        // 1. Nummer im Verwendungszweck
        if ($invoice->number) {
            $byNumber = $amountMatches->first(fn ($tx) => $this->referenceHasNumber($tx->reference, $invoice->number));
            if ($byNumber) {
                return [$byNumber, 'number'];
            }
        }

        // 2. Betrag + Gegenpartei-Name + Datum nah
        $byParty = $amountMatches->first(fn ($tx) =>
            $this->nameMatches($tx->counterparty_name, $invoice->customer_name)
            && $this->dateNear($tx->booked_at, $invoice->document_date)
        );
        if ($byParty) {
            return [$byParty, 'amount_party'];
        }

        // 3. Betrag + Datum nah
        $byAmount = $amountMatches->first(fn ($tx) => $this->dateNear($tx->booked_at, $invoice->document_date));
        if ($byAmount) {
            return [$byAmount, 'amount'];
        }

        return null;
    }

    /**
     * Beste offene Rechnung für eine Transaktion (Live-Automatch).
     *
     * @return array{0:DripInvoice,1:string}|null
     */
    private function findInvoiceForTransaction(BankTransaction $tx, Collection $openInvoices): ?array
    {
        $amountMatches = $openInvoices->filter(fn ($inv) => $this->amountMatches($tx, $inv->amount_gross));
        if ($amountMatches->isEmpty()) {
            return null;
        }

        if ($tx->reference) {
            $byNumber = $amountMatches->first(fn ($inv) => $inv->number && $this->referenceHasNumber($tx->reference, $inv->number));
            if ($byNumber) {
                return [$byNumber, 'number'];
            }
        }

        $byParty = $amountMatches->first(fn ($inv) =>
            $this->nameMatches($tx->counterparty_name, $inv->customer_name)
            && $this->dateNear($tx->booked_at, $inv->document_date)
        );
        if ($byParty) {
            return [$byParty, 'amount_party'];
        }

        $byAmount = $amountMatches->first(fn ($inv) => $this->dateNear($tx->booked_at, $inv->document_date));
        if ($byAmount) {
            return [$byAmount, 'amount'];
        }

        return null;
    }

    private function amountMatches(BankTransaction $tx, float $grossEur): bool
    {
        return abs(abs((float) $tx->amount) - $grossEur) < self::AMOUNT_EPSILON;
    }

    /** Rechnungsnummer als eigenständige Ziffernfolge im Verwendungszweck (nicht Teil einer längeren Zahl). */
    private function referenceHasNumber(?string $reference, ?string $number): bool
    {
        if (!$reference || !$number) {
            return false;
        }

        return (bool) preg_match('/(?<!\d)' . preg_quote($number, '/') . '(?!\d)/', $reference);
    }

    private function nameMatches(?string $a, ?string $b): bool
    {
        $na = $this->normalizeName($a);
        $nb = $this->normalizeName($b);

        if ($na === '' || $nb === '' || strlen($na) < 5 || strlen($nb) < 5) {
            return false;
        }

        return $na === $nb || str_contains($na, $nb) || str_contains($nb, $na);
    }

    private function normalizeName(?string $s): string
    {
        $s = mb_strtoupper((string) $s);
        $s = preg_replace('/\b(GMBH|MBH|AG|KG|UG|CO|OHG|GBR|LTD|LLC|SE|INC)\b/u', '', $s);
        $s = preg_replace('/[^A-Z0-9]/u', '', $s);

        return trim((string) $s);
    }

    private function dateNear(?Carbon $txDate, ?Carbon $docDate): bool
    {
        if (!$txDate || !$docDate) {
            return false;
        }

        return abs($txDate->diffInDays($docDate)) <= self::DATE_WINDOW_DAYS;
    }
}
