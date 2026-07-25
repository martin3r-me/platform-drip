<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="Dashboard" icon="heroicon-o-chart-bar" />
    </x-slot>

    <x-slot name="sidebar">
        @include('drip::partials.inner-sidebar')
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Drip', 'href' => route('drip.dashboard'), 'icon' => 'chart-bar'],
        ]" />
    </x-slot>

    <x-ui-page-container width="contained">

        @if($lastSyncAt)
            <p class="text-xs text-[color:var(--nx-faint)]">
                Letzter Sync: {{ \Carbon\Carbon::parse($lastSyncAt)->diffForHumans() }}
                · {{ $groupsCount }} {{ $groupsCount === 1 ? 'Gruppe' : 'Gruppen' }}
                · {{ $accountsCount }} {{ $accountsCount === 1 ? 'Konto' : 'Konten' }}
            </p>
        @endif

        {{-- Alerts --}}
        @if(count($alerts) > 0)
            <div class="flex flex-wrap items-center gap-2">
                @foreach($alerts as $alert)
                    @php
                        $alertVariant = match($alert['type']) {
                            'danger' => 'danger',
                            'warning' => 'warning',
                            'primary' => 'accent',
                            'info' => 'info',
                            default => 'neutral',
                        };
                    @endphp
                    <x-nx-badge :href="$alert['link']" wire:navigate :variant="$alertVariant">
                        @svg('heroicon-o-' . $alert['icon'], 'w-3.5 h-3.5')
                        {{ $alert['message'] }}
                    </x-nx-badge>
                @endforeach
            </div>
        @endif

        {{-- Controls: Period Type + Month Dropdown + Comparison Mode --}}
        <div class="flex flex-wrap items-center gap-4">
            <div class="flex items-center gap-1">
                @foreach(['month' => 'Monat', 'quarter' => 'Quartal', 'year' => 'Jahr'] as $type => $label)
                    <x-nx-button size="sm" :variant="$periodType === $type ? 'primary' : 'ghost'"
                                 wire:click="$set('periodType', '{{ $type }}')">{{ $label }}</x-nx-button>
                @endforeach
            </div>

            <div class="w-48">
                <x-nx-input-select wire:model.live="selectedMonth" :options="$availableMonths"
                                   optionValue="value" optionLabel="label" size="sm" />
            </div>

            <div class="flex items-center gap-1">
                @foreach(['previous' => 'vs. Vorperiode', 'average' => 'vs. Durchschnitt', 'none' => 'Ohne'] as $mode => $label)
                    <x-nx-button size="sm" :variant="$comparisonMode === $mode ? 'primary' : 'ghost'"
                                 wire:click="$set('comparisonMode', '{{ $mode }}')">{{ $label }}</x-nx-button>
                @endforeach
            </div>
        </div>

        {{-- Stat Cards: Kontostand + Ausgaben + Einnahmen + Netto --}}
        @if(!empty($comparison))
            @php
                $expHint = ($comparisonMode !== 'none' && $comparison['debit_prev'] > 0)
                    ? ($comparison['debit_delta'] >= 0 ? '+' : '') . number_format($comparison['debit_delta'], 0, ',', '.') . ' € (' . ($comparison['debit_delta_pct'] >= 0 ? '+' : '') . $comparison['debit_delta_pct'] . '%) vs. ' . $comparison['prev_label']
                    : null;
                $incHint = ($comparisonMode !== 'none' && $comparison['credit_prev'] > 0)
                    ? ($comparison['credit_delta'] >= 0 ? '+' : '') . number_format($comparison['credit_delta'], 0, ',', '.') . ' € (' . ($comparison['credit_delta_pct'] >= 0 ? '+' : '') . $comparison['credit_delta_pct'] . '%) vs. ' . $comparison['prev_label']
                    : null;
                $netDelta = $comparison['net_current'] - ($comparison['net_prev'] ?? 0);
                $netHint = ($comparisonMode !== 'none' && ($comparison['net_prev'] ?? 0) != 0)
                    ? ($netDelta >= 0 ? '+' : '') . number_format($netDelta, 0, ',', '.') . ' € vs. ' . $comparison['prev_label']
                    : null;
            @endphp
            <x-nx-stat-grid cols="4">
                <x-nx-stat label="Kontostand" icon="heroicon-o-banknotes"
                           value="{{ number_format($totalBalance, 2, ',', '.') }} €" />
                <x-nx-stat label="Ausgaben" icon="heroicon-o-arrow-trending-down" accent="var(--nx-danger)"
                           value="{{ number_format($comparison['debit_current'], 2, ',', '.') }} €"
                           :hint="$expHint" />
                <x-nx-stat label="Einnahmen" icon="heroicon-o-arrow-trending-up" accent="var(--nx-success)"
                           value="{{ number_format($comparison['credit_current'], 2, ',', '.') }} €"
                           :hint="$incHint" />
                <x-nx-stat label="Netto" icon="heroicon-o-scale"
                           accent="{{ $comparison['net_current'] >= 0 ? 'var(--nx-success)' : 'var(--nx-danger)' }}"
                           value="{{ $comparison['net_current'] >= 0 ? '+' : '' }}{{ number_format($comparison['net_current'], 2, ',', '.') }} €"
                           :hint="$netHint" />
            </x-nx-stat-grid>
        @endif

        {{-- Liquiditäts-Verlauf: Kontostand über Zeit (rekonstruiert aus Saldo + Netto) --}}
        @if(count($liquidityTrend) > 0)
            <x-nx-card class="overflow-hidden" wire:key="liquidity-{{ $selectedMonth }}-{{ $periodType }}">
                <div class="mb-4 flex items-baseline justify-between gap-2">
                    <h2 class="text-sm font-semibold text-[color:var(--nx-text)]">Liquiditäts-Verlauf</h2>
                    <span class="text-[11px] text-[color:var(--nx-faint)]">Kontostand je Periodenende · rekonstruiert aus Saldo &amp; Netto-Flüssen</span>
                </div>
                <div wire:ignore x-data="{
                    chart: null,
                    init() {
                        this.chart = new ApexCharts(this.$refs.el, {
                            chart: { type: 'area', height: 260, toolbar: { show: false }, fontFamily: 'inherit' },
                            series: [
                                { name: 'Liquidität', type: 'area', data: {{ json_encode(collect($liquidityTrend)->pluck('balance')->values()) }} }
                            ],
                            colors: ['#0EA5E9'],
                            stroke: { curve: 'smooth', width: 2.5 },
                            fill: { type: 'gradient', gradient: { opacityFrom: 0.35, opacityTo: 0.05 } },
                            markers: { size: 3, strokeWidth: 0 },
                            xaxis: { categories: {{ json_encode(collect($liquidityTrend)->pluck('label')->values()) }}, labels: { style: { fontSize: '11px', colors: '#6B7280' } } },
                            yaxis: { labels: { style: { fontSize: '11px', colors: '#6B7280' }, formatter: v => new Intl.NumberFormat('de-DE').format(Math.round(v)) } },
                            tooltip: { y: { formatter: v => new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(v) } },
                            dataLabels: { enabled: false },
                            legend: { show: false },
                            grid: { borderColor: '#F3F4F6' }
                        });
                        this.chart.render();
                    },
                    destroy() { this.chart?.destroy(); }
                }">
                    <div x-ref="el"></div>
                </div>
            </x-nx-card>
        @endif

        {{-- Trend Chart --}}
        @if(count($trend) > 0)
            <x-nx-card class="overflow-hidden" wire:key="trend-{{ $selectedMonth }}-{{ $periodType }}">
                <h2 class="mb-4 text-sm font-semibold text-[color:var(--nx-text)]">Trend (6 {{ match($periodType) { 'quarter' => 'Quartale', 'year' => 'Jahre', default => 'Monate' } }})</h2>
                <div wire:ignore x-data="{
                    chart: null,
                    init() {
                        this.chart = new ApexCharts(this.$refs.el, {
                            chart: { type: 'area', height: 260, toolbar: { show: false }, fontFamily: 'inherit' },
                            series: [
                                { name: 'Einnahmen', type: 'area', data: {{ json_encode(collect($trend)->pluck('credit')->values()) }} },
                                { name: 'Ausgaben', type: 'area', data: {{ json_encode(collect($trend)->pluck('debit')->values()) }} },
                                { name: 'Netto', type: 'line', data: {{ json_encode(collect($trend)->pluck('net')->values()) }} }
                            ],
                            colors: ['#22C55E', '#F87171', '#3B82F6'],
                            stroke: { curve: 'smooth', width: [2, 2, 2.5] },
                            fill: { type: ['gradient', 'gradient', 'solid'], gradient: { opacityFrom: 0.35, opacityTo: 0.05 } },
                            xaxis: { categories: {{ json_encode(collect($trend)->pluck('label')->values()) }}, labels: { style: { fontSize: '11px', colors: '#6B7280' } } },
                            yaxis: { labels: { style: { fontSize: '11px', colors: '#6B7280' }, formatter: v => new Intl.NumberFormat('de-DE').format(Math.round(v)) } },
                            tooltip: { y: { formatter: v => new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(v) } },
                            dataLabels: { enabled: false },
                            legend: { fontSize: '11px', labels: { colors: '#6B7280' } },
                            grid: { borderColor: '#F3F4F6' }
                        });
                        this.chart.render();
                    },
                    destroy() { this.chart?.destroy(); }
                }">
                    <div x-ref="el"></div>
                </div>
            </x-nx-card>
        @endif

        {{-- 3-spaltig: Donut + Top Kategorien + Top Counterparties --}}
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8" wire:key="details-{{ $selectedMonth }}-{{ $periodType }}">
            {{-- Donut Chart --}}
            <x-nx-card class="overflow-hidden">
                <h2 class="mb-4 text-sm font-semibold text-[color:var(--nx-text)]">
                    Kategorie-Anteile
                    <span class="ml-1 text-xs font-normal text-[color:var(--nx-faint)]">Ausgaben</span>
                </h2>
                @if(count($topCategories) > 0)
                    <div wire:ignore x-data="{
                        chart: null,
                        init() {
                            this.chart = new ApexCharts(this.$refs.el, {
                                chart: {
                                    type: 'donut', height: 320, fontFamily: 'inherit',
                                    events: {
                                        dataPointSelection: (e, chart, opts) => {
                                            const catId = {{ json_encode(collect($topCategories)->pluck('category_id')->values()) }}[opts.dataPointIndex];
                                            if (catId) @this.selectCategory(catId);
                                        }
                                    }
                                },
                                series: {{ json_encode(collect($topCategories)->pluck('amount')->values()) }},
                                labels: {{ json_encode(collect($topCategories)->pluck('name')->values()) }},
                                colors: {{ json_encode(collect($topCategories)->pluck('color')->values()) }},
                                legend: { position: 'bottom', fontSize: '10px', labels: { colors: '#6B7280' } },
                                tooltip: { y: { formatter: v => new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(v) } },
                                dataLabels: { enabled: true, formatter: (val) => Math.round(val) + '%', style: { fontSize: '10px' } },
                                plotOptions: { pie: { donut: { size: '55%', labels: { show: true, name: { fontSize: '11px' }, value: { fontSize: '14px', formatter: v => new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(v) }, total: { show: true, label: 'Gesamt', formatter: w => new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(w.globals.seriesTotals.reduce((a, b) => a + b, 0)) } } } } },
                                stroke: { width: 2, colors: ['#fff'] }
                            });
                            this.chart.render();
                        },
                        destroy() { this.chart?.destroy(); }
                    }">
                        <div x-ref="el"></div>
                    </div>
                @else
                    <x-nx-empty icon="heroicon-o-chart-pie">Keine Daten fuer diesen Zeitraum</x-nx-empty>
                @endif
            </x-nx-card>

            {{-- Top Categories --}}
            <x-nx-card class="overflow-hidden">
                <h2 class="mb-4 text-sm font-semibold text-[color:var(--nx-text)]">
                    Top Kategorien
                    <span class="ml-1 text-xs font-normal text-[color:var(--nx-faint)]">Ausgaben</span>
                </h2>
                @if(count($topCategories) > 0)
                    <div class="space-y-2">
                        @foreach($topCategories as $cat)
                            <div wire:click="selectCategory({{ $cat['category_id'] }})"
                                 class="cursor-pointer rounded-lg p-2 transition-colors {{ $selectedCategoryId === $cat['category_id'] ? 'bg-[color:var(--nx-accent-soft)] ring-1 ring-[color:var(--nx-line)]' : 'hover:bg-[color:var(--nx-hover)]' }}">
                                <div class="flex items-center justify-between mb-1">
                                    <div class="flex items-center gap-2 min-w-0">
                                        <div class="w-2.5 h-2.5 rounded-full shrink-0" style="background-color: {{ $cat['color'] }}"></div>
                                        <span class="text-[12px] font-medium text-[color:var(--nx-text)] truncate">{{ $cat['name'] }}</span>
                                        <span class="text-[10px] text-[color:var(--nx-faint)] shrink-0">{{ $cat['percent'] }}%</span>
                                    </div>
                                    <span class="text-[12px] tabular-nums font-medium text-[color:var(--nx-muted)] shrink-0">{{ number_format($cat['amount'], 0, ',', '.') }} &euro;</span>
                                </div>
                                @php $maxAmount = collect($topCategories)->max('amount'); @endphp
                                <div class="h-1.5 bg-[color:var(--nx-accent-soft)] rounded-full overflow-hidden">
                                    <div class="h-1.5 rounded-full" style="width: {{ $maxAmount > 0 ? round($cat['amount'] / $maxAmount * 100) : 0 }}%; background-color: {{ $cat['color'] }}"></div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <x-nx-empty icon="heroicon-o-tag">Keine Daten fuer diesen Zeitraum</x-nx-empty>
                @endif
            </x-nx-card>

            {{-- Top Counterparties --}}
            <x-nx-card class="overflow-hidden">
                <h2 class="mb-4 text-sm font-semibold text-[color:var(--nx-text)]">
                    Top Zahlungsempfaenger
                    <span class="ml-1 text-xs font-normal text-[color:var(--nx-faint)]">Ausgaben</span>
                </h2>
                @if(count($topCounterparties) > 0)
                    <div wire:ignore x-data="{
                        chart: null,
                        init() {
                            this.chart = new ApexCharts(this.$refs.el, {
                                chart: { type: 'bar', height: {{ max(180, count($topCounterparties) * 28) }}, toolbar: { show: false }, fontFamily: 'inherit' },
                                series: [{ name: 'Betrag', data: {{ json_encode(collect($topCounterparties)->pluck('amount')->values()) }} }],
                                colors: ['#F87171'],
                                plotOptions: { bar: { horizontal: true, borderRadius: 3, barHeight: '60%' } },
                                xaxis: { categories: {{ json_encode(collect($topCounterparties)->pluck('name')->map(fn($n) => Str::limit($n, 22))->values()) }}, labels: { style: { fontSize: '11px', colors: '#6B7280' }, formatter: v => new Intl.NumberFormat('de-DE').format(Math.round(v)) } },
                                yaxis: { labels: { style: { fontSize: '11px', colors: '#374151' }, maxWidth: 130 } },
                                tooltip: { y: { formatter: v => new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(v) } },
                                dataLabels: { enabled: false },
                                legend: { show: false },
                                grid: { borderColor: '#F3F4F6' }
                            });
                            this.chart.render();
                        },
                        destroy() { this.chart?.destroy(); }
                    }">
                        <div x-ref="el"></div>
                    </div>
                @else
                    <x-nx-empty icon="heroicon-o-users">Keine Daten fuer diesen Zeitraum</x-nx-empty>
                @endif
            </x-nx-card>
        </div>

        {{-- Category Drilldown (conditional) --}}
        @if($selectedCategoryId)
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8" wire:key="cat-detail-{{ $selectedCategoryId }}">
                {{-- Category Trend --}}
                @if(count($categoryTrend) > 0)
                    <x-nx-card class="overflow-hidden">
                        <div class="flex items-center justify-between mb-4">
                            <h2 class="text-sm font-semibold text-[color:var(--nx-text)]">Kategorie-Trend</h2>
                            <x-nx-button variant="ghost" size="sm" wire:click="selectCategory(null)">Schliessen</x-nx-button>
                        </div>
                        <div wire:ignore x-data="{
                            chart: null,
                            init() {
                                this.chart = new ApexCharts(this.$refs.el, {
                                    chart: { type: 'area', height: 200, toolbar: { show: false }, fontFamily: 'inherit' },
                                    series: [{ name: 'Betrag', data: {{ json_encode(collect($categoryTrend)->pluck('amount')->values()) }} }],
                                    colors: ['#F87171'],
                                    stroke: { curve: 'smooth', width: 2 },
                                    fill: { type: 'gradient', gradient: { opacityFrom: 0.3, opacityTo: 0.05 } },
                                    xaxis: { categories: {{ json_encode(collect($categoryTrend)->pluck('label')->values()) }}, labels: { style: { fontSize: '11px', colors: '#6B7280' } } },
                                    yaxis: { labels: { style: { fontSize: '11px', colors: '#6B7280' }, formatter: v => new Intl.NumberFormat('de-DE').format(Math.round(v)) } },
                                    tooltip: { y: { formatter: v => new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(v) } },
                                    dataLabels: { enabled: false },
                                    grid: { borderColor: '#F3F4F6' }
                                });
                                this.chart.render();
                            },
                            destroy() { this.chart?.destroy(); }
                        }">
                            <div x-ref="el"></div>
                        </div>
                    </x-nx-card>
                @endif

                {{-- Category Transactions --}}
                <x-nx-card flush class="overflow-hidden">
                    <div class="flex items-center justify-between border-b border-[color:var(--nx-line)] px-4 py-3">
                        <h2 class="text-sm font-semibold text-[color:var(--nx-text)]">Transaktionen</h2>
                        <span class="text-xs text-[color:var(--nx-faint)]">{{ count($categoryTransactions) }} Eintraege</span>
                    </div>
                    @if(count($categoryTransactions) > 0)
                        <div class="divide-y divide-[color:var(--nx-line)] max-h-[400px] overflow-y-auto">
                            @foreach($categoryTransactions as $ct)
                                <x-nx-list-item :href="route('drip.transactions.show', $ct['id'])"
                                                :title="$ct['counterparty']"
                                                :subtitle="$ct['date'] . ($ct['reference'] ? ' · ' . $ct['reference'] : '')">
                                    <x-slot name="trailing">
                                        <span class="text-[12px] font-medium tabular-nums text-red-600">-{{ number_format($ct['amount'], 2, ',', '.') }} &euro;</span>
                                    </x-slot>
                                </x-nx-list-item>
                            @endforeach
                        </div>
                    @else
                        <x-nx-empty icon="heroicon-o-banknotes">Keine Transaktionen in diesem Zeitraum</x-nx-empty>
                    @endif
                </x-nx-card>
            </div>
        @endif

        {{-- Recent Transactions + Groups --}}
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
            {{-- Letzte Transaktionen --}}
            <x-nx-card flush class="overflow-hidden">
                <div class="border-b border-[color:var(--nx-line)] px-4 py-3">
                    <h2 class="text-sm font-semibold text-[color:var(--nx-text)]">Letzte Transaktionen</h2>
                </div>
                <div class="divide-y divide-[color:var(--nx-line)]">
                    @forelse(($recentTransactions ?? []) as $t)
                        <x-nx-list-item :href="route('drip.transactions.show', $t)"
                                        :title="$t->counterparty_name ?? ($t->direction === 'debit' ? $t->creditor_name : $t->debtor_name) ?? ($t->remittance_information ?? $t->reference ?? '-')"
                                        :subtitle="($t->booked_at?->format('d.m.Y') ?? '-') . ($t->remittance_information ? ' · ' . Str::limit($t->remittance_information, 40) : '')">
                            <x-slot name="trailing">
                                <span class="text-[13px] font-medium tabular-nums {{ $t->direction === 'credit' ? 'text-green-600' : 'text-red-600' }}">
                                    {{ $t->direction === 'credit' ? '+' : '-' }}{{ number_format(abs((float) $t->amount), 2, ',', '.') }} {{ $t->currency }}
                                </span>
                            </x-slot>
                        </x-nx-list-item>
                    @empty
                        <x-nx-empty icon="heroicon-o-banknotes">Keine Transaktionen vorhanden</x-nx-empty>
                    @endforelse
                </div>
            </x-nx-card>

            {{-- Kontogruppen --}}
            <x-nx-card flush class="overflow-hidden">
                <div class="border-b border-[color:var(--nx-line)] px-4 py-3">
                    <h2 class="text-sm font-semibold text-[color:var(--nx-text)]">Kontogruppen</h2>
                </div>
                <div class="divide-y divide-[color:var(--nx-line)]">
                    @forelse(($groups ?? []) as $g)
                        <x-nx-list-item :title="$g->name" :meta="($g->bank_accounts_count ?? 0) . ' Konten'">
                            <x-slot name="leading">
                                <span class="w-2.5 h-2.5 rounded-full shrink-0 block" style="background-color: {{ $g->color ?? 'var(--nx-muted)' }}"></span>
                            </x-slot>
                            <x-slot name="trailing">
                                <x-nx-button variant="ghost" size="sm" :href="route('drip.groups.show', $g)">
                                    @svg('heroicon-o-banknotes', 'w-3.5 h-3.5') Transaktionen
                                </x-nx-button>
                            </x-slot>
                        </x-nx-list-item>
                    @empty
                        <x-nx-empty icon="heroicon-o-folder">Keine Gruppen vorhanden</x-nx-empty>
                    @endforelse
                </div>
            </x-nx-card>
        </div>

    </x-ui-page-container>
</x-ui-page>
