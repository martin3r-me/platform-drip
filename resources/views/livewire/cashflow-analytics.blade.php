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
        <div class="flex items-center gap-4 mb-8">
            <div>
                <select wire:model.live="selectedMonth"
                        class="px-3 py-1.5 rounded-md border border-gray-200 text-[13px] text-gray-900 focus:outline-none focus:ring-1 focus:ring-blue-500 focus:border-blue-500">
                    @foreach($availableMonths as $m)
                        <option value="{{ $m['value'] }}">{{ $m['label'] }}</option>
                    @endforeach
                </select>
            </div>
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
        </div>

        {{-- Monatsvergleich --}}
        @if(!empty($comparison))
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-6 mb-8">
                <div class="bg-white rounded-2xl shadow-sm p-6">
                    <div class="text-[11px] font-medium text-gray-500 uppercase tracking-wide">Ausgaben</div>
                    <div class="mt-1 text-xl font-bold tabular-nums text-red-600">
                        {{ number_format($comparison['debit_current'], 2, ',', '.') }} &euro;
                    </div>
                    @if($comparison['debit_prev'] > 0)
                        <div class="mt-1 text-[11px] {{ $comparison['debit_delta'] <= 0 ? 'text-green-600' : 'text-red-500' }}">
                            {{ $comparison['debit_delta'] >= 0 ? '+' : '' }}{{ number_format($comparison['debit_delta'], 0, ',', '.') }} &euro;
                            ({{ $comparison['debit_delta_pct'] >= 0 ? '+' : '' }}{{ $comparison['debit_delta_pct'] }}%)
                            vs. {{ $comparison['prev_month_label'] }}
                        </div>
                    @endif
                </div>
                <div class="bg-white rounded-2xl shadow-sm p-6">
                    <div class="text-[11px] font-medium text-gray-500 uppercase tracking-wide">Einnahmen</div>
                    <div class="mt-1 text-xl font-bold tabular-nums text-green-600">
                        {{ number_format($comparison['credit_current'], 2, ',', '.') }} &euro;
                    </div>
                    @if($comparison['credit_prev'] > 0)
                        <div class="mt-1 text-[11px] {{ $comparison['credit_delta'] >= 0 ? 'text-green-600' : 'text-red-500' }}">
                            {{ $comparison['credit_delta'] >= 0 ? '+' : '' }}{{ number_format($comparison['credit_delta'], 0, ',', '.') }} &euro;
                            ({{ $comparison['credit_delta_pct'] >= 0 ? '+' : '' }}{{ $comparison['credit_delta_pct'] }}%)
                            vs. {{ $comparison['prev_month_label'] }}
                        </div>
                    @endif
                </div>
                <div class="bg-white rounded-2xl shadow-sm p-6">
                    <div class="text-[11px] font-medium text-gray-500 uppercase tracking-wide">Netto</div>
                    <div class="mt-1 text-xl font-bold tabular-nums {{ $comparison['net_current'] >= 0 ? 'text-green-600' : 'text-red-600' }}">
                        {{ $comparison['net_current'] >= 0 ? '+' : '' }}{{ number_format($comparison['net_current'], 2, ',', '.') }} &euro;
                    </div>
                    @if($comparison['net_prev'] != 0)
                        <div class="mt-1 text-[11px] text-gray-500">
                            Vormonat: {{ $comparison['net_prev'] >= 0 ? '+' : '' }}{{ number_format($comparison['net_prev'], 0, ',', '.') }} &euro;
                        </div>
                    @endif
                </div>
            </div>
        @endif

        {{-- Trend (6 Monate) — Area Chart --}}
        @if(count($trend) > 0)
            <div class="bg-white rounded-2xl shadow-sm p-6 mb-8 overflow-hidden" wire:key="trend-{{ $selectedMonth }}-{{ $direction }}">
                <h2 class="text-xl font-bold text-gray-900 mb-4">Trend (6 Monate)</h2>
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

        {{-- Top Kategorien + Counterparties --}}
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-8" wire:key="details-{{ $selectedMonth }}-{{ $direction }}">
            {{-- Top Kategorien — Horizontal Bar Chart --}}
            <div class="bg-white rounded-2xl shadow-sm p-6">
                <h2 class="text-xl font-bold text-gray-900 mb-4">
                    Top Kategorien
                    <span class="text-[11px] font-normal text-gray-400 ml-1">{{ $direction === 'debit' ? 'Ausgaben' : 'Einnahmen' }}</span>
                </h2>
                @if(count($topCategories) > 0)
                    <div wire:ignore x-data="{
                        chart: null,
                        init() {
                            this.chart = new ApexCharts(this.$refs.el, {
                                chart: { type: 'bar', height: {{ max(180, count($topCategories) * 28) }}, toolbar: { show: false }, fontFamily: 'inherit' },
                                series: [{ name: 'Betrag', data: {{ json_encode(collect($topCategories)->pluck('amount')->values()) }} }],
                                colors: {{ json_encode(collect($topCategories)->pluck('color')->values()) }},
                                plotOptions: { bar: { horizontal: true, borderRadius: 3, barHeight: '60%', distributed: true } },
                                xaxis: { categories: {{ json_encode(collect($topCategories)->pluck('name')->values()) }}, labels: { style: { fontSize: '11px', colors: '#6B7280' }, formatter: v => new Intl.NumberFormat('de-DE').format(Math.round(v)) } },
                                yaxis: { labels: { style: { fontSize: '11px', colors: '#374151' }, maxWidth: 120 } },
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
                    <p class="text-[13px] text-gray-400 py-4 text-center">Keine Daten fuer diesen Monat</p>
                @endif
            </div>

            {{-- Top Counterparties — Horizontal Bar Chart --}}
            <div class="bg-white rounded-2xl shadow-sm p-6">
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
                    <p class="text-[13px] text-gray-400 py-4 text-center">Keine Daten fuer diesen Monat</p>
                @endif
            </div>
        </div>

    </x-ui-page-container>
</x-ui-page>
