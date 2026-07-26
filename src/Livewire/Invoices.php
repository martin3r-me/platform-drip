<?php

namespace Platform\Drip\Livewire;

use Livewire\Component;
use Platform\Core\Models\Team;
use Platform\Drip\Models\DripInvoice;
use Platform\Drip\Services\InvoiceMatchService;
use Platform\Drip\Services\InvoiceSyncService;

/**
 * „Offene Belege" — Belege, die noch keine Bank-Transaktion haben.
 * Provider- und richtungsagnostisch: Ausgangsbelege (Forderungen) und
 * Eingangsbelege (Verbindlichkeiten) in einer Sicht. MOSS-Karten fallen
 * systematisch raus (die Karte IST die Transaktion).
 */
class Invoices extends Component
{
    /** open (= ohne TX) | matched (= zugeordnet) | all */
    public string $filter = 'open';

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
        $team = Team::find((int) auth()->user()->current_team_id);
        if (!$team) {
            return;
        }

        $sync = $syncService->syncForTeam($team);
        $match = $matchService->matchForTeam($team);

        $this->syncResult = "{$sync['synced']} Belege gespiegelt · {$match['matched']} neu zugeordnet.";
    }

    public function render()
    {
        $teamId = (int) auth()->user()->current_team_id;

        $query = DripInvoice::forTeam($teamId)->invoices()->with('matchedTransaction');
        if ($this->filter === 'open') {
            $query->open();
        } elseif ($this->filter === 'matched') {
            $query->matched();
        }

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

        // Kopf-KPIs beziehen sich auf „offen" (die eigentliche Worklist).
        $openInvoices = $invoices->where('match_status', 'open');

        return view('drip::livewire.invoices', [
            'sections' => $sections,
            'invoiceCount' => $invoices->count(),
            'openCount' => $openInvoices->count(),
            'openSum' => $openInvoices->sum('amount_gross_cents') / 100,
        ])->layout('platform::layouts.app');
    }
}
