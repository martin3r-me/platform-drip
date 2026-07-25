<?php

namespace Platform\Drip\Livewire;

use Illuminate\Support\Carbon;
use Livewire\Component;
use Platform\Core\Models\Team;
use Platform\Drip\Models\DripInvoice;
use Platform\Drip\Services\InvoiceMatchService;
use Platform\Drip\Services\InvoiceSyncService;

class Invoices extends Component
{
    /** all | open | matched */
    public string $filter = 'all';

    public ?string $syncResult = null;

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

        $this->syncResult = "{$sync['synced']} Rechnungen gespiegelt · {$match['matched']} neu abgeglichen.";
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

        $groups = $invoices
            ->groupBy(fn ($i) => $i->month_key ?? '0000-00')
            ->map(fn ($rows, $key) => [
                'key' => $key,
                'label' => $key !== '0000-00'
                    ? Carbon::createFromFormat('Y-m', $key)->translatedFormat('F Y')
                    : 'ohne Datum',
                'invoices' => $rows,
                'total' => $rows->sum('amount_gross_cents') / 100,
                'matched' => $rows->where('match_status', 'matched')->sum('amount_gross_cents') / 100,
                'open' => $rows->where('match_status', 'open')->sum('amount_gross_cents') / 100,
            ])
            ->sortByDesc('key')
            ->values();

        $total = $invoices->sum('amount_gross_cents') / 100;
        $matchedCount = $invoices->where('match_status', 'matched')->count();
        $openSum = $invoices->where('match_status', 'open')->sum('amount_gross_cents') / 100;

        return view('drip::livewire.invoices', [
            'groups' => $groups,
            'invoiceCount' => $invoices->count(),
            'total' => $total,
            'matchedCount' => $matchedCount,
            'openSum' => $openSum,
        ])->layout('platform::layouts.app');
    }
}
