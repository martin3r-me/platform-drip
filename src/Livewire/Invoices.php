<?php

namespace Platform\Drip\Livewire;

use Livewire\Component;
use Platform\Core\Models\Team;
use Platform\Drip\Models\BankTransaction;
use Platform\Drip\Models\DripInvoice;
use Platform\Drip\Services\InvoiceMatchService;
use Platform\Drip\Services\InvoiceSyncService;

/**
 * „Offene Belege" — beide Richtungen der Lücke:
 *
 *   Belege ohne Zahlung  → Rechnung gestellt, Geld fehlt (Forderungsliste)
 *   Zahlung ohne Beleg   → Geld da, Rechnung fehlt (oder es gibt bewusst keine)
 *
 * Der zweite Fall ist kein Fehler: Finanzamt-Erstattungen, BAFA-Zuschüsse und
 * gruppeninterne Ausleihungen haben systematisch keine Ausgangsrechnung. Sie
 * lassen sich als „belegfrei" abhaken, damit sie die echten Lücken nicht zudecken.
 */
class Invoices extends Component
{
    /** open (= ohne Zahlung) | overdue | matched | all */
    public string $filter = 'open';

    /** Gegenrichtung: Eingänge ohne Beleg ein-/ausblenden. */
    public bool $showUnmatchedCredits = true;

    public ?string $syncResult = null;

    private const DIRECTIONS = [
        'outgoing' => 'Ausgang · Forderungen',
        'incoming' => 'Eingang · Verbindlichkeiten',
    ];

    public function updatedFilter(): void
    {
        // reiner Re-Render
    }

    /** Manueller Anstoß: easybill spiegeln + gegen Bank-Eingänge abgleichen. */
    public function sync(InvoiceSyncService $syncService, InvoiceMatchService $matchService): void
    {
        $team = Team::find($this->teamId());
        if (!$team) {
            return;
        }

        $sync = $syncService->syncForTeam($team);
        $match = $matchService->matchForTeam($team);

        $this->syncResult = "{$sync['synced']} Belege gespiegelt · {$match['allocations']} Zuordnungen · {$match['matched']} bezahlt.";
    }

    /**
     * Einen Eingang bewusst als belegfrei abhaken (Finanzamt, Zuschuss, …)
     * bzw. die Einstufung zurücknehmen.
     */
    public function toggleNoInvoice(int $transactionId): void
    {
        $tx = BankTransaction::where('team_id', $this->teamId())->find($transactionId);
        if (!$tx) {
            return;
        }

        $tx->forceFill([
            'invoice_status' => $tx->invoice_status === BankTransaction::INVOICE_STATUS_NO_INVOICE
                ? BankTransaction::INVOICE_STATUS_OPEN
                : BankTransaction::INVOICE_STATUS_NO_INVOICE,
        ])->save();
    }

    public function render()
    {
        $teamId = $this->teamId();

        $query = DripInvoice::forTeam($teamId)->invoices()->with(['transactions', 'matchedTransaction']);

        match ($this->filter) {
            'open' => $query->unpaid(),
            'overdue' => $query->overdue(),
            'matched' => $query->matched(),
            default => $query,
        };

        $invoices = $query->orderByDesc('document_date')->orderByDesc('number')->get();

        // Nach Richtung gruppieren (Ausgang / Eingang), leere Sektionen weglassen.
        $byDirection = $invoices->groupBy(fn ($i) => $i->direction ?: 'outgoing');

        $sections = collect(self::DIRECTIONS)
            ->map(fn ($label, $key) => [
                'key' => $key,
                'label' => $label,
                'invoices' => ($byDirection[$key] ?? collect())->values(),
                'sum' => ($byDirection[$key] ?? collect())->sum('amount_gross_cents') / 100,
            ])
            ->filter(fn ($s) => $s['invoices']->isNotEmpty())
            ->values();

        // Kopf-KPIs immer über den GESAMTEN unbezahlten Bestand, unabhängig vom Filter.
        $unpaid = DripInvoice::forTeam($teamId)->invoices()->unpaid()->get();
        $overdue = $unpaid->filter(fn ($i) => $i->due_date && $i->due_date->isPast());

        // Gegenrichtung: Eingänge, die auf einen Beleg warten.
        $creditsAwaiting = $this->showUnmatchedCredits
            ? BankTransaction::forTeam($teamId)->awaitingInvoice()->with('invoices')->orderByDesc('booked_at')->get()
            : collect();

        return view('drip::livewire.invoices', [
            'sections' => $sections,
            'invoiceCount' => $invoices->count(),
            'openCount' => $unpaid->count(),
            'openSum' => $unpaid->sum(fn ($i) => $i->openCents()) / 100,
            'overdueCount' => $overdue->count(),
            'overdueSum' => $overdue->sum(fn ($i) => $i->openCents()) / 100,
            'creditsAwaiting' => $creditsAwaiting,
            'creditsAwaitingSum' => $creditsAwaiting->sum(fn ($t) => $t->unallocatedCents()) / 100,
        ])->layout('platform::layouts.app');
    }

    private function teamId(): int
    {
        return (int) auth()->user()->current_team_id;
    }
}
