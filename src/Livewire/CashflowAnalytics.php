<?php

namespace Platform\Drip\Livewire;

use Livewire\Component;
use Illuminate\Support\Carbon;
use Platform\Drip\Models\BankTransaction;
use Platform\Drip\Models\BankTransactionCategory;
use Platform\Drip\Models\CashflowSnapshot;
use Platform\Drip\Services\CashflowSnapshotService;

class CashflowAnalytics extends Component
{
    public string $selectedMonth = '';
    public string $direction = 'debit';

    public array $availableMonths = [];
    public array $topCategories = [];
    public array $topCounterparties = [];
    public array $comparison = [];
    public array $trend = [];

    public function mount(): void
    {
        $this->selectedMonth = now()->format('Y-m');
        $this->loadAvailableMonths();
        $this->loadData();
    }

    public function updatedSelectedMonth(): void
    {
        $this->loadData();
    }

    public function updatedDirection(): void
    {
        $this->loadData();
    }

    protected function loadAvailableMonths(): void
    {
        $teamId = auth()->user()?->current_team_id;
        if (!$teamId) {
            return;
        }

        $months = CashflowSnapshot::forTeam($teamId)
            ->monthly()
            ->select('period_key')
            ->distinct()
            ->orderByDesc('period_key')
            ->limit(24)
            ->pluck('period_key')
            ->map(function (string $pk) {
                $date = Carbon::createFromFormat('Y-m', $pk);
                return ['value' => $pk, 'label' => $date->translatedFormat('F Y')];
            })
            ->toArray();

        $this->availableMonths = $months;
    }

    protected function loadData(): void
    {
        $teamId = auth()->user()?->current_team_id;
        if (!$teamId) {
            return;
        }

        $this->topCategories = $this->loadTopCategories($teamId);
        $this->topCounterparties = $this->loadTopCounterparties($teamId);
        $this->comparison = $this->loadComparison($teamId);
        $this->trend = $this->loadTrend($teamId);
    }

    protected function loadTopCategories(int $teamId): array
    {
        $rows = CashflowSnapshot::forTeam($teamId)
            ->monthly()
            ->teamWide()
            ->where('direction', $this->direction)
            ->where('period_key', $this->selectedMonth)
            ->where('category_id', '!=', CashflowSnapshot::SENTINEL_ALL)
            ->where('counterparty_hash', CashflowSnapshot::SENTINEL_HASH_ALL)
            ->where('total_amount', '>', 0)
            ->orderByDesc('total_amount')
            ->limit(15)
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $categoryIds = $rows->pluck('category_id')->filter()->unique();
        $categories = BankTransactionCategory::whereIn('id', $categoryIds)
            ->get(['id', 'name', 'color'])
            ->keyBy('id');

        $total = $rows->sum('total_amount');

        return $rows->map(fn ($row) => [
            'category_id' => (int) $row->category_id,
            'name' => $categories[$row->category_id]?->name ?? 'Ohne Kategorie',
            'color' => $categories[$row->category_id]?->color ?? '#9CA3AF',
            'amount' => (float) $row->total_amount,
            'count' => (int) $row->transaction_count,
            'percent' => $total > 0 ? round((float) $row->total_amount / $total * 100, 1) : 0,
        ])->toArray();
    }

    protected function loadTopCounterparties(int $teamId): array
    {
        $rows = CashflowSnapshot::forTeam($teamId)
            ->monthly()
            ->teamWide()
            ->where('direction', $this->direction)
            ->where('period_key', $this->selectedMonth)
            ->where('counterparty_hash', '!=', CashflowSnapshot::SENTINEL_HASH_ALL)
            ->where('category_id', CashflowSnapshot::SENTINEL_ALL)
            ->where('total_amount', '>', 0)
            ->orderByDesc('total_amount')
            ->limit(15)
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $hashes = $rows->pluck('counterparty_hash')->unique()->all();
        $names = [];
        foreach ($hashes as $hash) {
            $tx = BankTransaction::where('team_id', $teamId)
                ->where('counterparty_name_hash', $hash)
                ->whereNotNull('counterparty_name')
                ->first(['counterparty_name']);
            $names[$hash] = $tx?->counterparty_name ?? '(unbekannt)';
        }

        $total = $rows->sum('total_amount');

        return $rows->map(fn ($row) => [
            'hash' => $row->counterparty_hash,
            'name' => $names[$row->counterparty_hash] ?? '(unbekannt)',
            'amount' => (float) $row->total_amount,
            'count' => (int) $row->transaction_count,
            'percent' => $total > 0 ? round((float) $row->total_amount / $total * 100, 1) : 0,
        ])->toArray();
    }

    protected function loadComparison(int $teamId): array
    {
        $currentDate = Carbon::createFromFormat('Y-m', $this->selectedMonth);
        $prevMonth = $currentDate->copy()->subMonth()->format('Y-m');

        // Total row for both months
        $snapshots = CashflowSnapshot::forTeam($teamId)
            ->monthly()
            ->teamWide()
            ->where('category_id', CashflowSnapshot::SENTINEL_ALL)
            ->where('counterparty_hash', CashflowSnapshot::SENTINEL_HASH_ALL)
            ->whereIn('period_key', [$this->selectedMonth, $prevMonth])
            ->get();

        $indexed = $snapshots->groupBy(fn ($s) => $s->period_key . '|' . $s->direction);

        $currentDebit = (float) ($indexed[$this->selectedMonth . '|debit']->first()?->total_amount ?? 0);
        $prevDebit = (float) ($indexed[$prevMonth . '|debit']->first()?->total_amount ?? 0);
        $currentCredit = (float) ($indexed[$this->selectedMonth . '|credit']->first()?->total_amount ?? 0);
        $prevCredit = (float) ($indexed[$prevMonth . '|credit']->first()?->total_amount ?? 0);

        $debitDelta = $currentDebit - $prevDebit;
        $creditDelta = $currentCredit - $prevCredit;

        return [
            'current_month' => $this->selectedMonth,
            'prev_month' => $prevMonth,
            'current_month_label' => $currentDate->translatedFormat('M Y'),
            'prev_month_label' => $currentDate->copy()->subMonth()->translatedFormat('M Y'),
            'debit_current' => $currentDebit,
            'debit_prev' => $prevDebit,
            'debit_delta' => $debitDelta,
            'debit_delta_pct' => $prevDebit > 0 ? round($debitDelta / $prevDebit * 100, 1) : 0,
            'credit_current' => $currentCredit,
            'credit_prev' => $prevCredit,
            'credit_delta' => $creditDelta,
            'credit_delta_pct' => $prevCredit > 0 ? round($creditDelta / $prevCredit * 100, 1) : 0,
            'net_current' => $currentCredit - $currentDebit,
            'net_prev' => $prevCredit - $prevDebit,
        ];
    }

    protected function loadTrend(int $teamId): array
    {
        $monthKeys = collect(range(0, 5))->map(fn ($i) => now()->subMonths(5 - $i)->format('Y-m'));

        $snapshots = CashflowSnapshot::forTeam($teamId)
            ->monthly()
            ->teamWide()
            ->where('category_id', CashflowSnapshot::SENTINEL_ALL)
            ->where('counterparty_hash', CashflowSnapshot::SENTINEL_HASH_ALL)
            ->whereIn('period_key', $monthKeys->all())
            ->get();

        $indexed = $snapshots->groupBy(fn ($s) => $s->period_key . '|' . $s->direction);

        return $monthKeys->map(function (string $pk) use ($indexed) {
            $date = Carbon::createFromFormat('Y-m', $pk);
            $debit = (float) ($indexed[$pk . '|debit']->first()?->total_amount ?? 0);
            $credit = (float) ($indexed[$pk . '|credit']->first()?->total_amount ?? 0);
            return [
                'period' => $pk,
                'label' => $date->translatedFormat('M'),
                'debit' => $debit,
                'credit' => $credit,
                'net' => $credit - $debit,
            ];
        })->toArray();
    }

    public function render()
    {
        return view('drip::livewire.cashflow-analytics')->layout('platform::layouts.app');
    }
}
