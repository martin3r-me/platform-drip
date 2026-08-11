<?php

namespace Platform\Drip\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Platform\Core\Models\Team;
use Platform\Drip\Models\BankTransaction;
use Platform\Drip\Models\DripInvoice;

/**
 * Gleicht Ausgangsrechnungen gegen die tatsächlichen Bank-Eingänge ab.
 *
 * Der Abgleich läuft transaktionsgetrieben (nicht rechnungsgetrieben), weil ein
 * Eingang mehrere Rechnungen begleichen kann — der Regelfall, nicht die Ausnahme:
 * eine Überweisung über 571,20 € deckt 14 Rechnungen ab. Rechnungsgetrieben
 * würde jede dieser Rechnungen dieselbe Transaktion beanspruchen.
 *
 * Match-Kette mit absteigender Sicherheit:
 *   1. Belegnummer(n) im Verwendungszweck, Summe trifft den Betrag → 'number'      (high)
 *   2. Belegnummer(n) gefunden, Summe weicht ab                    → 'number'      (medium, Vorschlag)
 *   3. Betrag + Gegenpartei-Name + Datum nah                       → 'amount_party'(medium)
 *   4. Betrag + Datum nah                                          → 'amount'      (low)
 *
 * Die Nummernerkennung selbst steckt im InvoiceReferenceParser und kennt bewusst
 * keinen Nummernkreis — validiert wird ausschließlich gegen das Belegbuch.
 *
 * Bank-Beträge sind verschlüsselt (kein SQL-SUM/WHERE) → Vergleich in PHP.
 */
class InvoiceMatchService
{
    private const AMOUNT_EPSILON_CENTS = 1;   // Rundungstoleranz
    private const DATE_WINDOW_DAYS = 120;     // Zahlung darf so weit vom Belegdatum liegen

    public function __construct(
        protected InvoiceReferenceParser $parser,
    ) {}

    /**
     * Gleicht alle offenen Rechnungen eines Teams ab.
     *
     * @return array{matched:int, checked:int, allocations:int, no_invoice:int}
     */
    public function matchForTeam(Team $team): array
    {
        $teamId = (int) $team->id;

        $invoices = $this->openInvoices($teamId);
        $credits = $this->candidateCredits($teamId);

        $allocations = 0;

        // ── Durchgang 1: Nummern im Verwendungszweck (deckt Sammelzahlungen ab)
        foreach ($credits as $tx) {
            $allocations += $this->allocateByNumbers($tx, $invoices);
        }

        // ── Durchgang 2: Betrags-/Namensheuristik für den Rest
        foreach ($credits as $tx) {
            if ($this->remainingCents($tx) <= 0) {
                continue;
            }
            $allocations += $this->allocateByHeuristic($tx, $invoices);
        }

        // ── Status nachziehen (Belege wie Transaktionen)
        foreach ($invoices as $invoice) {
            $this->refreshInvoiceStatus($invoice);
        }

        $noInvoice = 0;
        foreach ($credits as $tx) {
            $noInvoice += $this->refreshTransactionStatus($tx) === BankTransaction::INVOICE_STATUS_NO_INVOICE ? 1 : 0;
        }

        return [
            'matched' => $invoices->filter(fn ($i) => $i->isMatched())->count(),
            'checked' => $invoices->count(),
            'allocations' => $allocations,
            'no_invoice' => $noInvoice,
        ];
    }

    /**
     * Live-Automatch für EINEN neuen Eingang beim Transaktions-Import.
     *
     * @return Collection<int,DripInvoice> die zugeordneten Belege
     */
    public function matchTransaction(BankTransaction $tx): Collection
    {
        if ($tx->direction !== 'credit' || $tx->is_disregarded) {
            return collect();
        }

        $invoices = $this->openInvoices((int) $tx->team_id);

        $this->allocateByNumbers($tx, $invoices);
        if ($this->remainingCents($tx) > 0) {
            $this->allocateByHeuristic($tx, $invoices);
        }

        $touched = $invoices->filter(fn ($i) => $i->wasRecentlyAllocated ?? false);
        foreach ($touched as $invoice) {
            $this->refreshInvoiceStatus($invoice);
        }

        $this->refreshTransactionStatus($tx);

        return $touched->values();
    }

    // ────────────────────────────────────────────────────────────────────
    // Zuordnung
    // ────────────────────────────────────────────────────────────────────

    /**
     * Belegnummern aus dem Verwendungszweck lösen und zuordnen.
     *
     * Zwei Wege, damit die Erkennung unabhängig vom Nummernschema bleibt:
     *   a) Kandidaten aus dem Text → Lookup im Belegbuch (schnell, Sammelzahlung)
     *   b) für Belege mit „untypischer" Nummer: Nummer direkt im Text suchen
     */
    private function allocateByNumbers(BankTransaction $tx, Collection $invoices): int
    {
        $remaining = $this->remainingCents($tx);
        if ($remaining <= 0) {
            return 0;
        }

        $hits = $this->resolveInvoicesFromReference($tx, $invoices);
        if ($hits->isEmpty()) {
            return 0;
        }

        // Trifft die Summe der gefundenen Belege den Eingang exakt, ist die
        // Zuordnung sicher — auch (gerade) bei 14 Rechnungen in einer Zahlung.
        $sumOpen = $hits->sum(fn (DripInvoice $i) => $this->invoiceOpenCents($i));
        $exact = abs($sumOpen - $remaining) <= self::AMOUNT_EPSILON_CENTS;

        $count = 0;
        foreach ($hits->sortBy('document_date') as $invoice) {
            $remaining = $this->remainingCents($tx);
            if ($remaining <= 0) {
                break;
            }

            $apply = min($this->invoiceOpenCents($invoice), $remaining);
            if ($apply <= 0) {
                continue;
            }

            $this->allocate($invoice, $tx, $apply, 'number', $exact ? 'high' : 'medium');
            $count++;
        }

        return $count;
    }

    /** Betrag + Gegenpartei / Betrag + Datum — nur für Eingänge ohne Nummer. */
    private function allocateByHeuristic(BankTransaction $tx, Collection $invoices): int
    {
        $remaining = $this->remainingCents($tx);
        if ($remaining <= 0) {
            return 0;
        }

        $open = $invoices->filter(fn (DripInvoice $i) => $this->invoiceOpenCents($i) > 0);

        $exact = $open->filter(fn (DripInvoice $i) => abs($this->invoiceOpenCents($i) - $remaining) <= self::AMOUNT_EPSILON_CENTS);
        if ($exact->isEmpty()) {
            return 0;
        }

        $byParty = $exact->first(fn (DripInvoice $i) =>
            $this->nameMatches($tx->counterparty_name, $i->customer_name)
            && $this->dateNear($tx->booked_at, $i->document_date)
        );

        if ($byParty) {
            $this->allocate($byParty, $tx, $this->invoiceOpenCents($byParty), 'amount_party', 'medium');
            return 1;
        }

        $byAmount = $exact->first(fn (DripInvoice $i) => $this->dateNear($tx->booked_at, $i->document_date));
        if ($byAmount) {
            $this->allocate($byAmount, $tx, $this->invoiceOpenCents($byAmount), 'amount', 'low');
            return 1;
        }

        return 0;
    }

    /**
     * Löst die im Verwendungszweck genannten Belege auf.
     *
     * @return Collection<int,DripInvoice>
     */
    private function resolveInvoicesFromReference(BankTransaction $tx, Collection $invoices): Collection
    {
        $parsed = $this->parser->parse($tx->reference);
        $tokens = array_merge($parsed['numbers'], $parsed['marked']);

        $hits = collect();

        if ($tokens) {
            $wanted = array_flip(array_map(fn ($t) => $this->normalizeNumber($t), $tokens));
            $hits = $invoices->filter(fn (DripInvoice $i) => $i->number && isset($wanted[$this->normalizeNumber($i->number)]));
        }

        // Rückwärtsweg: Belege, deren Nummer nicht ins Ziffernschema passt
        // (Buchstaben, Bindestriche) — direkt im Text suchen.
        $unusual = $invoices->filter(fn (DripInvoice $i) => $i->number && !preg_match('/^\d{5,12}$/', (string) $i->number));
        if ($unusual->isNotEmpty()) {
            $extra = $unusual->filter(fn (DripInvoice $i) => $this->parser->containsNumber($tx->reference, $i->number));
            $hits = $hits->merge($extra);
        }

        return $hits
            ->unique('id')
            ->filter(fn (DripInvoice $i) => $this->invoiceOpenCents($i) > 0)
            ->values();
    }

    /** Schreibt eine Zuordnung in die Pivot und aktualisiert die In-Memory-Caches. */
    private function allocate(DripInvoice $invoice, BankTransaction $tx, int $cents, string $type, string $confidence): void
    {
        // Caches VOR dem Schreiben füllen: sonst liest ein noch kaltes Modell die
        // gerade geschriebene Zeile aus der DB und zählt sie beim Nachziehen
        // doppelt (trifft den Live-Hook, der die Pivot nicht vorlädt).
        $invoice->allocatedCents();
        $tx->allocatedCents();

        // Additiv, nicht überschreibend — eine Rechnung kann in Raten von
        // derselben Transaktion bedient werden.
        $existing = (int) ($invoice->transactions()
            ->where('bank_transaction_id', $tx->id)
            ->value('amount_applied_cents') ?? 0);

        $invoice->transactions()->syncWithoutDetaching([
            $tx->id => [
                'team_id' => $invoice->team_id,
                'amount_applied_cents' => $existing + $cents,
                'match_type' => $type,
                'confidence' => $confidence,
                'updated_at' => now(),
            ],
        ]);

        $invoice->addAllocationCache((int) $tx->id, $cents, $type);
        $tx->addAllocationCache($cents);
        $invoice->wasRecentlyAllocated = true;
    }

    // ────────────────────────────────────────────────────────────────────
    // Status
    // ────────────────────────────────────────────────────────────────────

    private function refreshInvoiceStatus(DripInvoice $invoice): void
    {
        $allocated = $invoice->allocatedCents();
        $gross = abs((int) $invoice->amount_gross_cents);

        $status = match (true) {
            $allocated <= 0 => 'open',
            $allocated + self::AMOUNT_EPSILON_CENTS >= $gross => 'matched',
            default => 'partial',
        };

        $primary = $invoice->primaryTransactionId();

        if (
            $invoice->match_status === $status
            && (int) $invoice->matched_transaction_id === (int) $primary
        ) {
            return;
        }

        $invoice->forceFill([
            'match_status' => $status,
            'matched_transaction_id' => $primary,
            'match_confidence' => $invoice->bestConfidence(),
            'matched_at' => $status === 'open' ? null : ($invoice->matched_at ?? now()),
        ])->save();
    }

    /**
     * Setzt den Beleg-Status eines Eingangs. Belegfreie Eingänge (Finanzamt,
     * Zuschüsse, gruppeninterne Ausleihungen, 0,00-€-Rechnungsabschlüsse) werden
     * als solche erkannt, statt dauerhaft als Lücke in der Worklist zu stehen.
     */
    private function refreshTransactionStatus(BankTransaction $tx): string
    {
        // Eine manuelle Einstufung gewinnt immer.
        if ($tx->invoice_status === BankTransaction::INVOICE_STATUS_NO_INVOICE && $tx->allocatedCents() <= 0) {
            return BankTransaction::INVOICE_STATUS_NO_INVOICE;
        }

        $allocated = $tx->allocatedCents();
        $total = $this->txAmountCents($tx);

        $status = match (true) {
            $allocated > 0 && $allocated + self::AMOUNT_EPSILON_CENTS >= $total => BankTransaction::INVOICE_STATUS_MATCHED,
            $allocated > 0 => BankTransaction::INVOICE_STATUS_PARTIAL,
            $this->expectsNoInvoice($tx) => BankTransaction::INVOICE_STATUS_NO_INVOICE,
            default => BankTransaction::INVOICE_STATUS_OPEN,
        };

        if ($tx->invoice_status !== $status) {
            $tx->forceFill(['invoice_status' => $status])->save();
        }

        return $status;
    }

    /**
     * Eingänge, zu denen es systematisch keine Ausgangsrechnung gibt.
     * Konservativ: im Zweifel bleibt der Eingang offen und wandert in die
     * Worklist — lieber einmal zu viel hinschauen als eine Lücke verstecken.
     */
    private function expectsNoInvoice(BankTransaction $tx): bool
    {
        if ($this->txAmountCents($tx) === 0) {
            return true;                       // Rechnungsabschluss der Bank
        }

        if ($tx->is_internal_transfer) {
            return true;                       // Umbuchung zwischen eigenen Konten
        }

        $categories = array_map(
            fn ($c) => mb_strtolower(trim((string) $c)),
            (array) config('drip.invoice_matching.no_invoice_categories', [])
        );

        $category = mb_strtolower((string) ($tx->category?->name ?? ''));

        return $category !== '' && in_array($category, $categories, true);
    }

    // ────────────────────────────────────────────────────────────────────
    // Datenzugriff / Helfer
    // ────────────────────────────────────────────────────────────────────

    /**
     * Alle nicht vollständig bezahlten Ausgangsrechnungen eines Teams.
     * Stornierte Belege fallen raus: ein STORNO trägt dieselbe Nummer wie die
     * Rechnung, die es aufhebt — ohne die Bereinigung würde eine Zahlung auf
     * eine längst stornierte Rechnung gebucht.
     *
     * @return Collection<int,DripInvoice>
     */
    private function openInvoices(int $teamId): Collection
    {
        $invoices = DripInvoice::forTeam($teamId)
            ->invoices()
            ->direction('outgoing')
            ->where('amount_gross_cents', '>', 0)
            ->with('transactions')
            ->orderBy('document_date')
            ->get();

        $stornos = DripInvoice::forTeam($teamId)
            ->whereIn('type', ['STORNO', 'CREDIT'])
            ->get();

        return $invoices
            ->reject(fn (DripInvoice $i) => $this->isCancelled($i, $stornos))
            ->values();
    }

    /**
     * Ist dieser Beleg storniert?
     *
     * Die Nummer allein reicht nicht: im Bestand gibt es 4100106 doppelt — einmal
     * BHG.BROICHCATERING (4.522,00 €, gültig) und einmal Culinaria (44,63 €,
     * storniert). Ein Storno hebt daher nur den Beleg auf, zu dem Betrag UND
     * Kunde passen; die gleichnamige gültige Rechnung bleibt im Rennen.
     */
    private function isCancelled(DripInvoice $invoice, Collection $stornos): bool
    {
        if (!$invoice->number) {
            return false;
        }

        $number = $this->normalizeNumber($invoice->number);
        $gross = abs((int) $invoice->amount_gross_cents);

        return $stornos->contains(function (DripInvoice $s) use ($invoice, $number, $gross) {
            if (!$s->number || $this->normalizeNumber($s->number) !== $number) {
                return false;
            }

            if (abs((int) $s->amount_gross_cents) !== $gross) {
                return false;
            }

            // Kunde muss passen, sofern auf beiden Seiten gepflegt.
            if ($s->customer_external_id && $invoice->customer_external_id) {
                return (int) $s->customer_external_id === (int) $invoice->customer_external_id;
            }

            return true;
        });
    }

    /** @return Collection<int,BankTransaction> */
    private function candidateCredits(int $teamId): Collection
    {
        return BankTransaction::where('team_id', $teamId)
            ->where('direction', 'credit')
            ->counted()
            ->with(['invoices', 'category'])
            ->orderBy('booked_at')
            ->get();
    }

    /** Noch nicht auf Belege verteilter Teil des Eingangs, in Cent. */
    private function remainingCents(BankTransaction $tx): int
    {
        return max(0, $this->txAmountCents($tx) - $tx->allocatedCents());
    }

    private function txAmountCents(BankTransaction $tx): int
    {
        return (int) round(abs((float) $tx->amount) * 100);
    }

    private function invoiceOpenCents(DripInvoice $invoice): int
    {
        return max(0, abs((int) $invoice->amount_gross_cents) - $invoice->allocatedCents());
    }

    /** Nummernvergleich ohne Trennzeichen und Groß-/Kleinschreibung. */
    private function normalizeNumber(string $number): string
    {
        return preg_replace('/[^A-Z0-9]/u', '', mb_strtoupper($number)) ?: '';
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
