<x-ui-page>
    @include('drip::partials.styles')
    <x-slot name="navbar">
        <x-ui-page-navbar title="Cashflow-Analyse" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Drip', 'href' => route('drip.dashboard'), 'icon' => 'chart-bar'],
            ['label' => 'Cashflow'],
        ]" />
    </x-slot>

    <x-ui-page-container>

        {{-- Controls --}}
        <div class="flex items-center gap-4 mb-8 flex-wrap">
            {{-- Period Type Switcher --}}
            <div class="flex items-center gap-1 bg-gray-100 rounded-md p-0.5">
                @foreach(['month' => 'Monat', 'quarter' => 'Quartal', 'year' => 'Jahr'] as $type => $label)
                    <button wire:click="$set('periodType', '{{ $type }}')"
                            class="px-3 py-1 rounded text-[12px] font-medium transition-colors {{ $periodType === $type ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-700' }}">
                        {{ $label }}
                    </button>
                @endforeach
            </div>

            {{-- Month Selector --}}
            <div>
                <select wire:model.live="selectedMonth"
                        class="px-3 py-1.5 rounded-md border border-gray-200 text-[13px] text-gray-900 focus:outline-none focus:ring-1 focus:ring-blue-500 focus:border-blue-500">
                    @foreach($availableMonths as $m)
                        <option value="{{ $m['value'] }}">{{ $m['label'] }}</option>
                    @endforeach
                </select>
            </div>

            {{-- Direction Toggle --}}
            <div class="flex items-center gap-1 bg-gray-100 rounded-md p-0.5">
                <button wire:click="$set('direction', 'debit')"
                        class="px-3 py-1 rounded text-[12px] font-medium transition-colors {{ $direction === 'debit' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-700' }}">
                    Ausgaben
                </button>
                <button wire:click="$set('direction', 'credit')"
                        class="px-3 py-1 rounded text-[12px] font-medium transition-colors {{ $direction === 'credit' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-700' }}">
                    Einnahmen
                </button>
            </div>

            {{-- Comparison Mode --}}
            <div class="flex items-center gap-1 bg-gray-100 rounded-md p-0.5">
                @foreach(['previous' => 'vs. Vorperiode', 'average' => 'vs. Durchschnitt', 'none' => 'Ohne'] as $mode => $label)
                    <button wire:click="$set('comparisonMode', '{{ $mode }}')"
                            class="px-3 py-1 rounded text-[12px] font-medium transition-colors {{ $comparisonMode === $mode ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-700' }}">
                        {{ $label }}
                    </button>
                @endforeach
            </div>
        </div>

        {{-- Comparison Cards --}}
        @if(!empty($comparison))
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-6 mb-8">
                {{-- Ausgaben --}}
                <div class="bg-white rounded-2xl shadow-sm p-6">
                    <div class="text-[11px] font-medium text-gray-500 uppercase tracking-wide">Ausgaben</div>
                    <div class="mt-1 text-xl font-bold tabular-nums text-red-600">
                        {{ number_format($comparison['debit_current'], 2, ',', '.') }} &euro;
                    </div>
                    @if($comparisonMode !== 'none' && $comparison['debit_prev'] > 0)
                        <div class="mt-1 text-[11px] {{ $comparison['debit_delta'] <= 0 ? 'text-green-600' : 'text-red-500' }}">
                            {{ $comparison['debit_delta'] >= 0 ? '+' : '' }}{{ number_format($comparison['debit_delta'], 0, ',', '.') }} &euro;
                            ({{ $comparison['debit_delta_pct'] >= 0 ? '+' : '' }}{{ $comparison['debit_delta_pct'] }}%)
                            vs. {{ $comparison['prev_label'] }}
                        </div>
                    @endif
                </div>
                {{-- Einnahmen --}}
                <div class="bg-white rounded-2xl shadow-sm p-6">
                    <div class="text-[11px] font-medium text-gray-500 uppercase tracking-wide">Einnahmen</div>
                    <div class="mt-1 text-xl font-bold tabular-nums text-green-600">
                        {{ number_format($comparison['credit_current'], 2, ',', '.') }} &euro;
                    </div>
                    @if($comparisonMode !== 'none' && $comparison['credit_prev'] > 0)
                        <div class="mt-1 text-[11px] {{ $comparison['credit_delta'] >= 0 ? 'text-green-600' : 'text-red-500' }}">
                            {{ $comparison['credit_delta'] >= 0 ? '+' : '' }}{{ number_format($comparison['credit_delta'], 0, ',', '.') }} &euro;
                            ({{ $comparison['credit_delta_pct'] >= 0 ? '+' : '' }}{{ $comparison['credit_delta_pct'] }}%)
                            vs. {{ $comparison['prev_label'] }}
                        </div>
                    @endif
                </div>
                {{-- Netto --}}
                <div class="bg-white rounded-2xl shadow-sm p-6">
                    <div class="text-[11px] font-medium text-gray-500 uppercase tracking-wide">Netto</div>
                    <div class="mt-1 text-xl font-bold tabular-nums {{ $comparison['net_current'] >= 0 ? 'text-green-600' : 'text-red-600' }}">
                        {{ $comparison['net_current'] >= 0 ? '+' : '' }}{{ number_format($comparison['net_current'], 2, ',', '.') }} &euro;
                    </div>
                    @if($comparisonMode !== 'none' && ($comparison['net_prev'] ?? 0) != 0)
                        @php
                            $netDelta = $comparison['net_current'] - $comparison['net_prev'];
                        @endphp
                        <div class="mt-1 text-[11px] {{ $netDelta >= 0 ? 'text-green-600' : 'text-red-500' }}">
                            {{ $netDelta >= 0 ? '+' : '' }}{{ number_format($netDelta, 0, ',', '.') }} &euro;
                            vs. {{ $comparison['prev_label'] }}
                        </div>
                    @endif
                </div>
            </div>
        @endif

        {{-- Trend Chart --}}
        @if(count($trend) > 0)
            <div class="bg-white rounded-2xl shadow-sm p-6 mb-8 overflow-hidden" wire:key="trend-{{ $selectedMonth }}-{{ $direction }}-{{ $periodType }}">
                <h2 class="text-xl font-bold text-gray-900 mb-4">Trend (6 {{ match($periodType) { 'quarter' => 'Quartale', 'year' => 'Jahre', default => 'Monate' } }})</h2>
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
            </div>
        @endif

        {{-- Donut Chart + Top Categories + Counterparties --}}
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8 mb-8" wire:key="details-{{ $selectedMonth }}-{{ $direction }}-{{ $periodType }}">
            {{-- Donut Chart --}}
            <div class="bg-white rounded-2xl shadow-sm p-6 overflow-hidden">
                <h2 class="text-xl font-bold text-gray-900 mb-4">
                    Kategorie-Anteile
                    <span class="text-[11px] font-normal text-gray-400 ml-1">{{ $direction === 'debit' ? 'Ausgaben' : 'Einnahmen' }}</span>
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
                    <p class="text-[13px] text-gray-400 py-4 text-center">Keine Daten fuer diesen Zeitraum</p>
                @endif
            </div>

            {{-- Top Categories — Horizontal Bar Chart with Budget Bars --}}
            <div class="bg-white rounded-2xl shadow-sm p-6 overflow-hidden">
                <h2 class="text-xl font-bold text-gray-900 mb-4">
                    Top Kategorien
                    <span class="text-[11px] font-normal text-gray-400 ml-1">{{ $direction === 'debit' ? 'Ausgaben' : 'Einnahmen' }}</span>
                </h2>
                @if(count($topCategories) > 0)
                    <div class="space-y-2">
                        @foreach($topCategories as $cat)
                            <div wire:click="selectCategory({{ $cat['category_id'] }})"
                                 class="cursor-pointer rounded-lg p-2 transition-colors {{ $selectedCategoryId === $cat['category_id'] ? 'bg-blue-50 ring-1 ring-blue-200' : 'hover:bg-gray-50' }}">
                                <div class="flex items-center justify-between mb-1">
                                    <div class="flex items-center gap-2 min-w-0">
                                        <div class="w-2.5 h-2.5 rounded-full shrink-0" style="background-color: {{ $cat['color'] }}"></div>
                                        <span class="text-[12px] font-medium text-gray-900 truncate">{{ $cat['name'] }}</span>
                                        <span class="text-[10px] text-gray-400 shrink-0">{{ $cat['percent'] }}%</span>
                                    </div>
                                    <span class="text-[12px] tabular-nums font-medium text-gray-700 shrink-0">{{ number_format($cat['amount'], 0, ',', '.') }} &euro;</span>
                                </div>
                                {{-- Amount bar --}}
                                @php $maxAmount = collect($topCategories)->max('amount'); @endphp
                                <div class="h-1.5 bg-gray-100 rounded-full overflow-hidden">
                                    <div class="h-1.5 rounded-full" style="width: {{ $maxAmount > 0 ? round($cat['amount'] / $maxAmount * 100) : 0 }}%; background-color: {{ $cat['color'] }}"></div>
                                </div>
                                {{-- Budget bar (if budget exists for this category) --}}
                                @if(isset($categoryBudgets[$cat['category_id']]))
                                    @php
                                        $cb = $categoryBudgets[$cat['category_id']];
                                        $budgetBarColor = $cb['percent'] <= 100 ? 'bg-green-400' : ($cb['percent'] <= 120 ? 'bg-yellow-400' : 'bg-red-400');
                                    @endphp
                                    <div class="flex items-center gap-2 mt-1">
                                        <div class="flex-1 h-1 bg-gray-100 rounded-full overflow-hidden">
                                            <div class="{{ $budgetBarColor }} h-1 rounded-full" style="width: {{ min($cb['percent'], 100) }}%"></div>
                                        </div>
                                        <span class="text-[9px] tabular-nums text-gray-400 shrink-0">Budget: {{ $cb['percent'] }}%</span>
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @else
                    <p class="text-[13px] text-gray-400 py-4 text-center">Keine Daten fuer diesen Zeitraum</p>
                @endif
            </div>

            {{-- Top Counterparties — Horizontal Bar Chart --}}
            <div class="bg-white rounded-2xl shadow-sm p-6 overflow-hidden">
                <h2 class="text-xl font-bold text-gray-900 mb-4">
                    Top {{ $direction === 'debit' ? 'Zahlungsempfaenger' : 'Einzahler' }}
                    <span class="text-[11px] font-normal text-gray-400 ml-1">{{ $direction === 'debit' ? 'Ausgaben' : 'Einnahmen' }}</span>
                </h2>
                @if(count($topCounterparties) > 0)
                    <div wire:ignore x-data="{
                        chart: null,
                        init() {
                            this.chart = new ApexCharts(this.$refs.el, {
                                chart: { type: 'bar', height: {{ max(180, count($topCounterparties) * 28) }}, toolbar: { show: false }, fontFamily: 'inherit' },
                                series: [{ name: 'Betrag', data: {{ json_encode(collect($topCounterparties)->pluck('amount')->values()) }} }],
                                colors: ['{{ $direction === 'debit' ? '#F87171' : '#4ADE80' }}'],
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
                    <p class="text-[13px] text-gray-400 py-4 text-center">Keine Daten fuer diesen Zeitraum</p>
                @endif
            </div>
        </div>

        {{-- Category Detail Panel (Trend + Transactions) --}}
        @if($selectedCategoryId)
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-8" wire:key="cat-detail-{{ $selectedCategoryId }}">
                {{-- Category Trend (6 months area chart) --}}
                @if(count($categoryTrend) > 0)
                    <div class="bg-white rounded-2xl shadow-sm p-6 overflow-hidden">
                        <div class="flex items-center justify-between mb-4">
                            <h2 class="text-xl font-bold text-gray-900">Kategorie-Trend</h2>
                            <button wire:click="selectCategory(null)" class="text-[11px] text-gray-400 hover:text-gray-600">Schliessen</button>
                        </div>
                        <div wire:ignore x-data="{
                            chart: null,
                            init() {
                                this.chart = new ApexCharts(this.$refs.el, {
                                    chart: { type: 'area', height: 200, toolbar: { show: false }, fontFamily: 'inherit', sparkline: { enabled: false } },
                                    series: [{ name: 'Betrag', data: {{ json_encode(collect($categoryTrend)->pluck('amount')->values()) }} }],
                                    colors: ['{{ $direction === 'debit' ? '#F87171' : '#4ADE80' }}'],
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
                    </div>
                @endif

                {{-- Category Transactions (Drill-Down) --}}
                <div class="bg-white rounded-2xl shadow-sm overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-100">
                        <div class="flex items-center justify-between">
                            <h2 class="text-xl font-bold text-gray-900">Transaktionen</h2>
                            <span class="text-[11px] text-gray-400">{{ count($categoryTransactions) }} Eintraege</span>
                        </div>
                    </div>
                    @if(count($categoryTransactions) > 0)
                        <div class="divide-y divide-gray-50 max-h-[400px] overflow-y-auto">
                            @foreach($categoryTransactions as $ct)
                                <a href="{{ route('drip.transactions.show', $ct['id']) }}" wire:navigate
                                   class="flex items-center justify-between px-6 py-2.5 hover:bg-gray-50/50 transition-colors">
                                    <div class="flex-1 min-w-0 mr-3">
                                        <div class="text-[12px] text-gray-900 truncate">{{ $ct['counterparty'] }}</div>
                                        <div class="text-[10px] text-gray-400 truncate">
                                            {{ $ct['date'] }}
                                            @if($ct['reference'])
                                                &middot; {{ $ct['reference'] }}
                                            @endif
                                        </div>
                                    </div>
                                    <span class="text-[12px] font-medium tabular-nums shrink-0 {{ $direction === 'credit' ? 'text-green-600' : 'text-red-600' }}">
                                        {{ $direction === 'credit' ? '+' : '-' }}{{ number_format($ct['amount'], 2, ',', '.') }} &euro;
                                    </span>
                                </a>
                            @endforeach
                        </div>
                    @else
                        <div class="px-6 py-8 text-center">
                            <p class="text-[13px] text-gray-400">Keine Transaktionen in diesem Zeitraum</p>
                        </div>
                    @endif
                </div>
            </div>
        @endif

    </x-ui-page-container>
</x-ui-page>
