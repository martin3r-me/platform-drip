<?php

namespace Platform\Drip\Livewire;

use Livewire\Component;
use Platform\Drip\Models\BankAccount;
use Platform\Drip\Models\BankAccountBalance;
use Platform\Drip\Models\BankAccountGroup;
use Platform\Drip\Models\BankTransaction;
use Platform\Drip\Models\BankTransactionCategory;
use Platform\Drip\Models\CashflowSnapshot;
use Platform\Drip\Services\CashflowSnapshotService;
use Illuminate\Support\Carbon;

class Dashboard extends Component
{
    public int $groupsCount = 0;
    public int $accountsCount = 0;
    public $lastSyncAt = null;

    public float $totalBalance = 0;

    public array $alerts = [];

    public $groups = [];
    public $recentTransactions = [];

    // Analytics properties (merged from CashflowAnalytics)
    public string $selectedMonth = '';
    public string $periodType = 'month';
    public string $comparisonMode = 'previous';
    public ?int $selectedCategoryId = null;

    public array $availableMonths = [];
    public array $topCategories = [];
    public array $topCounterparties = [];
    public array $comparison = [];
    public array $trend = [];
    public array $liquidityTrend = [];
    public array $categoryTrend = [];
    public array $categoryTransactions = [];

    public function mount(): void
    {
        $user = auth()->user();
        $teamId = $user?->current_team_id;

        if (!$teamId) {
            $this->groups = collect();
            $this->recentTransactions = collect();
            return;
        }

        $this->selectedMonth = now()->format('Y-m');

        $this->groupsCount = (int) BankAccountGroup::where('team_id', $teamId)->count();
        $this->accountsCount = (int) BankAccount::where('team_id', $teamId)->count();

        // Total balance: sum latest balance per account
        $this->totalBalance = BankAccountBalance::where('team_id', $teamId)
            ->get()
            ->groupBy('bank_account_id')
            ->map(fn ($balances) => $balances->sortByDesc('retrieved_at')->first())
            ->sum(fn ($b) => (float) ($b->amount ?? $b->balance ?? 0));

        // Alerts (Cashflow: offene Kategorisierung etc.)
        $this->alerts = $this->loadAlerts($teamId);

        $this->lastSyncAt = BankAccount::where('team_id', $teamId)
            ->max('last_transactions_synced_at');

        $this->groups = BankAccountGroup::withCount('bankAccounts')
            ->where('team_id', $teamId)
            ->orderBy('name')
            ->limit(8)
            ->get();

        $this->recentTransactions = BankTransaction::with(['bankAccount'])
            ->where('team_id', $teamId)
            ->orderByDesc('booked_at')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        // Analytics data
        $this->loadAvailableMonths();
        $this->loadAnalyticsData();
    }

    // ── Analytics methods (merged from CashflowAnalytics) ──

    public function updatedSelectedMonth(): void
    {
        $this->selectedCategoryId = null;
        $this->loadAnalyticsData();
    }

    public function updatedPeriodType(): void
    {
        $this->selectedCategoryId = null;
        $this->loadAnalyticsData();
    }

    public function updatedComparisonMode(): void
    {
        $teamId = auth()->user()?->current_team_id;
        if ($teamId) {
            $this->comparison = $this->loadComparison($teamId);
        }
    }

    public function selectCategory(?int $categoryId): void
    {
        $this->selectedCategoryId = $this->selectedCategoryId === $categoryId ? null : $categoryId;

        $teamId = auth()->user()?->current_team_id;
        if (!$teamId) {
            return;
        }

        if ($this->selectedCategoryId) {
            $this->loadCategoryTrend($teamId);
            $this->loadCategoryTransactions($teamId);
        } else {
            $this->categoryTrend = [];
            $this->categoryTransactions = [];
        }
    }

    protected function loadAnalyticsData(): void
    {
        $teamId = auth()->user()?->current_team_id;
        if (!$teamId) {
            return;
        }

        $this->topCategories = $this->loadTopCategories($teamId);
        $this->topCounterparties = $this->loadTopCounterparties($teamId);
        $this->comparison = $this->loadComparison($teamId);
        $this->trend = $this->loadTrend($teamId);
        $this->liquidityTrend = $this->buildLiquidityTrend();
    }

    /**
     * Liquiditäts-Verlauf: Kontostand je Periodenende, rekonstruiert aus dem
     * aktuellen Gesamtsaldo und den Netto-Flüssen. Ausgehend vom Ist-Saldo
     * (= Ende der letzten Periode) wird Periode für Periode das Netto
     * herausgerechnet, um den Saldo am Ende jeder früheren Periode zu erhalten.
     *
     * @return array<int, array{label:string, balance:float}>
     */
    protected function buildLiquidityTrend(): array
    {
        if (empty($this->trend)) {
            return [];
        }

        $running = (float) $this->totalBalance;
        $out = [];

        for ($i = count($this->trend) - 1; $i >= 0; $i--) {
            $out[$i] = [
                'label' => $this->trend[$i]['label'] ?? '',
                'balance' => round($running, 2),
            ];
            $running -= (float) ($this->trend[$i]['net'] ?? 0);
        }

        ksort($out);

        return array_values($out);
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

    protected function getPeriodMonthKeys(): array
    {
        $currentDate = Carbon::createFromFormat('Y-m', $this->selectedMonth);

        return match ($this->periodType) {
            'quarter' => collect(range(0, 2))->map(fn ($i) => $currentDate->copy()->startOfQuarter()->addMonths($i)->format('Y-m'))->toArray(),
            'year' => collect(range(0, 11))->map(fn ($i) => $currentDate->copy()->startOfYear()->addMonths($i)->format('Y-m'))->toArray(),
            default => [$this->selectedMonth],
        };
    }

    protected function getPeriodLabel(): string
    {
        $currentDate = Carbon::createFromFormat('Y-m', $this->selectedMonth);

        return match ($this->periodType) {
            'quarter' => 'Q' . $currentDate->quarter . ' ' . $currentDate->year,
            'year' => (string) $currentDate->year,
            default => $currentDate->translatedFormat('F Y'),
        };
    }

    protected function loadTopCategories(int $teamId): array
    {
        $periodKeys = $this->getPeriodMonthKeys();

        if (count($periodKeys) === 1) {
            $rows = CashflowSnapshot::forTeam($teamId)
                ->monthly()
                ->teamWide()
                ->where('direction', 'debit')
                ->where('period_key', $periodKeys[0])
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

        return app(CashflowSnapshotService::class)->topForRange($teamId, 'category', $periodKeys, 'debit');
    }

    protected function loadTopCounterparties(int $teamId): array
    {
        $periodKeys = $this->getPeriodMonthKeys();

        if (count($periodKeys) === 1) {
            $rows = CashflowSnapshot::forTeam($teamId)
                ->monthly()
                ->teamWide()
                ->where('direction', 'debit')
                ->where('period_key', $periodKeys[0])
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

        return app(CashflowSnapshotService::class)->topForRange($teamId, 'counterparty', $periodKeys, 'debit');
    }

    protected function loadComparison(int $teamId): array
    {
        $currentDate = Carbon::createFromFormat('Y-m', $this->selectedMonth);
        $currentKeys = $this->getPeriodMonthKeys();

        $prevKeys = match ($this->periodType) {
            'quarter' => collect(range(0, 2))->map(fn ($i) => $currentDate->copy()->startOfQuarter()->subMonths(3)->addMonths($i)->format('Y-m'))->toArray(),
            'year' => collect(range(0, 11))->map(fn ($i) => $currentDate->copy()->startOfYear()->subYear()->addMonths($i)->format('Y-m'))->toArray(),
            default => [$currentDate->copy()->subMonth()->format('Y-m')],
        };

        $allKeys = array_merge($currentKeys, $prevKeys);

        $snapshots = CashflowSnapshot::forTeam($teamId)
            ->monthly()
            ->teamWide()
            ->where('category_id', CashflowSnapshot::SENTINEL_ALL)
            ->where('counterparty_hash', CashflowSnapshot::SENTINEL_HASH_ALL)
            ->whereIn('period_key', $allKeys)
            ->get();

        $indexed = $snapshots->groupBy(fn ($s) => $s->period_key . '|' . $s->direction);

        $sumFor = function (array $keys, string $dir) use ($indexed) {
            $sum = 0;
            foreach ($keys as $pk) {
                $sum += (float) ($indexed->get($pk . '|' . $dir)?->first()?->total_amount ?? 0);
            }
            return $sum;
        };

        $currentDebit = $sumFor($currentKeys, 'debit');
        $currentCredit = $sumFor($currentKeys, 'credit');

        if ($this->comparisonMode === 'average') {
            $avgKeys = [];
            for ($i = 1; $i <= 6; $i++) {
                $avgKeys[] = match ($this->periodType) {
                    'quarter' => collect(range(0, 2))->map(fn ($m) => $currentDate->copy()->startOfQuarter()->subMonths($i * 3)->addMonths($m)->format('Y-m'))->toArray(),
                    'year' => collect(range(0, 11))->map(fn ($m) => $currentDate->copy()->startOfYear()->subYears($i)->addMonths($m)->format('Y-m'))->toArray(),
                    default => [$currentDate->copy()->subMonths($i)->format('Y-m')],
                };
            }

            $allAvgKeys = collect($avgKeys)->flatten()->unique()->all();
            $avgSnapshots = CashflowSnapshot::forTeam($teamId)
                ->monthly()
                ->teamWide()
                ->where('category_id', CashflowSnapshot::SENTINEL_ALL)
                ->where('counterparty_hash', CashflowSnapshot::SENTINEL_HASH_ALL)
                ->whereIn('period_key', $allAvgKeys)
                ->get();

            $avgIndexed = $avgSnapshots->groupBy(fn ($s) => $s->period_key . '|' . $s->direction);

            $prevDebit = 0;
            $prevCredit = 0;
            $count = 0;
            foreach ($avgKeys as $periodSet) {
                $d = 0;
                $c = 0;
                foreach ($periodSet as $pk) {
                    $d += (float) ($avgIndexed->get($pk . '|debit')?->first()?->total_amount ?? 0);
                    $c += (float) ($avgIndexed->get($pk . '|credit')?->first()?->total_amount ?? 0);
                }
                if ($d > 0 || $c > 0) {
                    $prevDebit += $d;
                    $prevCredit += $c;
                    $count++;
                }
            }
            $prevDebit = $count > 0 ? $prevDebit / $count : 0;
            $prevCredit = $count > 0 ? $prevCredit / $count : 0;
            $prevLabel = 'Ø ' . $count . ' Perioden';
        } elseif ($this->comparisonMode === 'none') {
            $prevDebit = 0;
            $prevCredit = 0;
            $prevLabel = '';
        } else {
            $prevDebit = $sumFor($prevKeys, 'debit');
            $prevCredit = $sumFor($prevKeys, 'credit');
            $prevLabel = match ($this->periodType) {
                'quarter' => 'Q' . $currentDate->copy()->subMonths(3)->quarter . ' ' . $currentDate->copy()->subMonths(3)->year,
                'year' => (string) ($currentDate->year - 1),
                default => $currentDate->copy()->subMonth()->translatedFormat('M Y'),
            };
        }

        $debitDelta = $currentDebit - $prevDebit;
        $creditDelta = $currentCredit - $prevCredit;

        return [
            'current_label' => $this->getPeriodLabel(),
            'prev_label' => $prevLabel,
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
        if ($this->periodType === 'month') {
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
                $debit = (float) ($indexed->get($pk . '|debit')?->first()?->total_amount ?? 0);
                $credit = (float) ($indexed->get($pk . '|credit')?->first()?->total_amount ?? 0);
                return [
                    'period' => $pk,
                    'label' => $date->translatedFormat('M'),
                    'debit' => $debit,
                    'credit' => $credit,
                    'net' => $credit - $debit,
                ];
            })->toArray();
        }

        $currentDate = Carbon::createFromFormat('Y-m', $this->selectedMonth);
        $periodGroups = [];

        for ($i = 5; $i >= 0; $i--) {
            if ($this->periodType === 'quarter') {
                $base = $currentDate->copy()->startOfQuarter()->subMonths($i * 3);
                $label = 'Q' . $base->quarter . ' ' . $base->year;
                $keys = collect(range(0, 2))->map(fn ($m) => $base->copy()->addMonths($m)->format('Y-m'))->toArray();
            } else {
                $base = $currentDate->copy()->startOfYear()->subYears($i);
                $label = (string) $base->year;
                $keys = collect(range(0, 11))->map(fn ($m) => $base->copy()->addMonths($m)->format('Y-m'))->toArray();
            }
            $periodGroups[$label] = $keys;
        }

        return app(CashflowSnapshotService::class)->trendForRange($teamId, $periodGroups);
    }

    protected function loadCategoryTrend(int $teamId): void
    {
        if (!$this->selectedCategoryId) {
            $this->categoryTrend = [];
            return;
        }

        $monthKeys = collect(range(0, 5))->map(fn ($i) => now()->subMonths(5 - $i)->format('Y-m'));

        $snapshots = CashflowSnapshot::forTeam($teamId)
            ->monthly()
            ->teamWide()
            ->where('category_id', $this->selectedCategoryId)
            ->where('counterparty_hash', CashflowSnapshot::SENTINEL_HASH_ALL)
            ->where('direction', 'debit')
            ->whereIn('period_key', $monthKeys->all())
            ->get()
            ->keyBy('period_key');

        $this->categoryTrend = $monthKeys->map(function (string $pk) use ($snapshots) {
            $date = Carbon::createFromFormat('Y-m', $pk);
            return [
                'period' => $pk,
                'label' => $date->translatedFormat('M'),
                'amount' => (float) ($snapshots[$pk]?->total_amount ?? 0),
            ];
        })->toArray();
    }

    protected function loadCategoryTransactions(int $teamId): void
    {
        if (!$this->selectedCategoryId) {
            $this->categoryTransactions = [];
            return;
        }

        $periodKeys = $this->getPeriodMonthKeys();
        $firstMonth = Carbon::createFromFormat('Y-m', min($periodKeys))->startOfMonth();
        $lastMonth = Carbon::createFromFormat('Y-m', max($periodKeys))->endOfMonth();

        $this->categoryTransactions = BankTransaction::where('team_id', $teamId)
            ->where('category_id', $this->selectedCategoryId)
            ->where('direction', 'debit')
            ->where(function ($q) use ($firstMonth, $lastMonth) {
                $q->where(function ($inner) use ($firstMonth, $lastMonth) {
                    $inner->whereNotNull('booked_at')
                        ->whereBetween('booked_at', [$firstMonth, $lastMonth]);
                })->orWhere(function ($or) use ($firstMonth, $lastMonth) {
                    $or->whereNull('booked_at')
                        ->whereBetween('created_at', [$firstMonth, $lastMonth]);
                });
            })
            ->orderByDesc('booked_at')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get(['id', 'counterparty_name', 'amount', 'direction', 'booked_at', 'created_at', 'remittance_information'])
            ->map(fn ($t) => [
                'id' => $t->id,
                'counterparty' => $t->counterparty_name ?? '(unbekannt)',
                'amount' => abs((float) $t->amount),
                'date' => ($t->booked_at ?? $t->created_at)?->format('d.m.Y'),
                'reference' => $t->remittance_information ? \Illuminate\Support\Str::limit($t->remittance_information, 60) : null,
            ])
            ->toArray();
    }

    protected function loadAlerts(int $teamId): array
    {
        $alerts = [];

        $uncategorized = BankTransaction::where('team_id', $teamId)
            ->needsCategory()
            ->where(function ($q) {
                $q->where(function ($inner) {
                    $inner->whereNotNull('booked_at')
                        ->where('booked_at', '>=', now()->subDays(30));
                })->orWhere(function ($or) {
                    $or->whereNull('booked_at')
                        ->where('created_at', '>=', now()->subDays(30));
                });
            })
            ->count();

        if ($uncategorized > 0) {
            $alerts[] = [
                'type' => 'info',
                'icon' => 'tag',
                'message' => $uncategorized . ' unkategorisierte ' . ($uncategorized === 1 ? 'Transaktion' : 'Transaktionen'),
                'link' => route('drip.categories'),
            ];
        }

        return $alerts;
    }

    public function render()
    {
        return view('drip::livewire.dashboard')->layout('platform::layouts.app');
    }
}
