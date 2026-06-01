<x-ui-page>
    @include('drip::partials.styles')
    <x-slot name="navbar">
        <x-ui-page-navbar title="Liquiditaetsplanung" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Drip', 'href' => route('drip.dashboard'), 'icon' => 'chart-bar'],
            ['label' => 'Liquiditaet'],
        ]" />
    </x-slot>

    <x-ui-page-container>

        {{-- Header: Zeitraum-Selector + computed_at --}}
        <div class="flex items-center justify-between mb-4">
            <div class="text-[11px] text-gray-400">
                @if($plan['computed_at'])
                    Berechnet: {{ \Illuminate\Support\Carbon::parse($plan['computed_at'])->translatedFormat('d.m.Y H:i') }}
                @else
                    <span class="text-yellow-600">Noch nicht berechnet. Bitte <code>drip:compute-liquidity</code> ausfuehren.</span>
                @endif
            </div>
            <div class="flex items-center gap-2">
                <span class="text-[13px] text-gray-500">Zeitraum:</span>
                @foreach([3, 6, 12] as $m)
                    <button wire:click="setMonthsAhead({{ $m }})"
                            class="px-2.5 py-1 rounded-md text-[12px] font-medium transition-colors {{ $monthsAhead === $m ? 'bg-blue-100 text-blue-700' : 'bg-gray-50 text-gray-600 hover:bg-gray-100' }}">
                        {{ $m }} Monate
                    </button>
                @endforeach
            </div>
        </div>

        {{-- Negative balance warning --}}
        @php
            $negativeDay = collect($plan['daily_forecast'])->first(fn ($d) => $d['balance'] < 0);
        @endphp
        @if($negativeDay)
            <div class="bg-red-50 border border-red-200 rounded-xl px-4 py-3 mb-6 flex items-center gap-2">
                @svg('heroicon-o-exclamation-triangle', 'w-4 h-4 text-red-500 shrink-0')
                <span class="text-[12px] font-medium text-red-700">
                    Negativsaldo ab {{ \Illuminate\Support\Carbon::parse($negativeDay['date'])->format('d.m.Y') }} prognostiziert
                    ({{ number_format($negativeDay['balance'], 2, ',', '.') }} &euro;)
                </span>
            </div>
        @endif

        {{-- Compact current balance --}}
        <div class="flex items-center gap-3 mb-6 px-1">
            <span class="text-[11px] font-medium text-gray-500 uppercase tracking-wide">Kontostand</span>
            <span class="text-2xl font-bold tabular-nums {{ $plan['current_balance'] >= 0 ? 'text-gray-900' : 'text-red-600' }}">
                {{ number_format($plan['current_balance'], 2, ',', '.') }} &euro;
            </span>
        </div>

        {{-- Daily Balance Curve — Actual vs. Forecast Overlay --}}
        @if(count($plan['daily_forecast']) > 1)
            @php
                $dailyData = $plan['daily_forecast'];
                $historicalData = $plan['historical_balances'] ?? [];
                $allBalances = array_merge(
                    array_column($dailyData, 'balance'),
                    array_column($historicalData, 'balance')
                );
                $minBal = !empty($allBalances) ? min($allBalances) : 0;
                $maxBal = !empty($allBalances) ? max($allBalances) : 0;
                $todayStr = now()->format('Y-m-d');
            @endphp
            <div class="bg-white rounded-2xl shadow-sm p-6 mb-8 overflow-hidden" wire:key="balance-curve-{{ $monthsAhead }}">
                <div class="flex items-center justify-between mb-3">
                    <h3 class="text-lg font-bold text-gray-900">Kontoverlauf</h3>
                    <div class="flex items-center gap-4 text-[11px] text-gray-400">
                        <span class="flex items-center gap-1">
                            <span class="inline-block w-4 h-0.5 bg-blue-600 rounded"></span> Ist
                        </span>
                        <span class="flex items-center gap-1">
                            <span class="inline-block w-4 h-0.5 bg-blue-300 rounded" style="border-top: 2px dashed #93C5FD;"></span> Prognose
                        </span>
                        <span>Min: {{ number_format($minBal, 0, ',', '.') }} &euro;</span>
                        <span>Max: {{ number_format($maxBal, 0, ',', '.') }} &euro;</span>
                    </div>
                </div>
                <div wire:ignore x-data="{
                    chart: null,
                    init() {
                        const forecastData = {{ Js::from(collect($dailyData)->map(fn($d) => ['x' => $d['date'], 'y' => round($d['balance'], 2)])->values()) }};
                        const actualData = {{ Js::from(collect($historicalData)->map(fn($d) => ['x' => $d['date'], 'y' => round($d['balance'], 2)])->values()) }};
                        const today = '{{ $todayStr }}';

                        this.chart = new ApexCharts(this.$refs.el, {
                            chart: { type: 'line', height: 220, toolbar: { show: true, tools: { download: false, selection: true, zoom: true, zoomin: true, zoomout: true, pan: true, reset: true } }, fontFamily: 'inherit', zoom: { enabled: true } },
                            series: [
                                { name: 'Ist-Saldo', data: actualData },
                                { name: 'Prognose', data: forecastData }
                            ],
                            colors: ['#3B82F6', '#93C5FD'],
                            stroke: { curve: 'smooth', width: [3, 2], dashArray: [0, 5] },
                            fill: {
                                type: ['solid', 'gradient'],
                                gradient: { shadeIntensity: 1, opacityFrom: 0.25, opacityTo: 0.05, stops: [0, 100] }
                            },
                            xaxis: { type: 'datetime', labels: { style: { fontSize: '11px', colors: '#6B7280' }, datetimeFormatter: { month: 'MMM', day: 'dd. MMM' } } },
                            yaxis: { labels: { style: { fontSize: '11px', colors: '#6B7280' }, formatter: v => new Intl.NumberFormat('de-DE').format(Math.round(v)) + ' \u20AC' } },
                            tooltip: { x: { format: 'dd.MM.yyyy' }, y: { formatter: v => v !== null ? new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(v) : '-' } },
                            annotations: {
                                xaxis: [{
                                    x: new Date(today).getTime(),
                                    borderColor: '#6B7280',
                                    strokeDashArray: 4,
                                    label: { text: 'Heute', orientation: 'vertical', style: { color: '#6B7280', fontSize: '10px', background: '#F9FAFB', padding: { left: 4, right: 4, top: 2, bottom: 2 } } }
                                }],
                                yaxis: [{ y: 0, borderColor: '#EF4444', strokeDashArray: 4, opacity: 0.5, label: { text: '0 \u20AC', style: { color: '#EF4444', fontSize: '10px', background: 'transparent' } } }]
                            },
                            dataLabels: { enabled: false },
                            grid: { borderColor: '#F3F4F6' },
                            legend: { fontSize: '11px', labels: { colors: '#6B7280' } }
                        });
                        this.chart.render();
                    },
                    destroy() { this.chart?.destroy(); }
                }">
                    <div x-ref="el"></div>
                </div>
            </div>
        @endif

        {{-- Monthly Cashflow Cards --}}
        @if(count($monthlyDetail) > 0)
            <div class="space-y-4">
                @foreach($monthlyDetail as $monthKey => $month)
                    @php $isFirst = $loop->first; @endphp
                    <div class="bg-white rounded-2xl shadow-sm overflow-hidden" x-data="{ open: {{ $isFirst ? 'true' : 'false' }} }">
                        {{-- Month Header --}}
                        <button @click="open = !open" class="w-full px-6 py-4 flex items-center justify-between hover:bg-gray-50 transition-colors">
                            <div class="flex items-center gap-4">
                                <h3 class="text-lg font-bold text-gray-900">{{ $month['label'] }}</h3>
                                @if($month['end_balance'] !== null)
                                    <span class="text-[12px] text-gray-400">Endstand: <span class="font-medium {{ $month['end_balance'] >= 0 ? 'text-gray-600' : 'text-red-600' }}">{{ number_format($month['end_balance'], 2, ',', '.') }} &euro;</span></span>
                                @endif
                            </div>
                            <div class="flex items-center gap-4">
                                <span class="text-[14px] tabular-nums font-semibold {{ $month['net'] >= 0 ? 'text-green-600' : 'text-red-600' }}">
                                    {{ $month['net'] >= 0 ? '+' : '' }}{{ number_format($month['net'], 2, ',', '.') }} &euro;
                                </span>
                                <svg :class="{ 'rotate-180': open }" class="w-4 h-4 text-gray-400 transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" /></svg>
                            </div>
                        </button>

                        {{-- Expanded content --}}
                        <div x-show="open" x-collapse>
                            @php
                                $credits = collect($month['items'])->where('direction', 'credit')->sortByDesc('amount')->values();
                                $debits = collect($month['items'])->where('direction', 'debit')->sortByDesc('amount')->values();
                            @endphp

                            {{-- Credits section --}}
                            @if($credits->isNotEmpty())
                                <div class="border-t border-gray-100">
                                    <div class="px-6 py-2.5 flex items-center justify-between bg-green-50/50">
                                        <span class="text-[11px] font-semibold text-green-700 uppercase tracking-wide">Einnahmen</span>
                                        <span class="text-[13px] tabular-nums font-semibold text-green-600">+{{ number_format($month['credits'], 2, ',', '.') }} &euro;</span>
                                    </div>
                                    <div class="divide-y divide-gray-50">
                                        @foreach($credits as $item)
                                            @include('drip::livewire.partials.liquidity-item', ['item' => $item])
                                        @endforeach
                                    </div>
                                </div>
                            @endif

                            {{-- Debits section --}}
                            @if($debits->isNotEmpty())
                                <div class="border-t border-gray-100">
                                    <div class="px-6 py-2.5 flex items-center justify-between bg-red-50/50">
                                        <span class="text-[11px] font-semibold text-red-700 uppercase tracking-wide">Ausgaben</span>
                                        <span class="text-[13px] tabular-nums font-semibold text-red-600">-{{ number_format($month['debits'], 2, ',', '.') }} &euro;</span>
                                    </div>
                                    <div class="divide-y divide-gray-50">
                                        @foreach($debits as $item)
                                            @include('drip::livewire.partials.liquidity-item', ['item' => $item])
                                        @endforeach
                                    </div>
                                </div>
                            @endif

                            {{-- Empty state --}}
                            @if($credits->isEmpty() && $debits->isEmpty())
                                <div class="border-t border-gray-100 px-6 py-8 text-center">
                                    <p class="text-[13px] text-gray-400">Keine Posten in diesem Monat.</p>
                                </div>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            <div class="bg-white rounded-2xl shadow-sm px-6 py-12 text-center">
                <p class="text-[13px] text-gray-500">Keine Prognosedaten vorhanden.</p>
                <p class="text-[11px] text-gray-400 mt-1">Fuehre <code class="bg-gray-100 px-1 rounded">drip:compute-liquidity</code> aus, um die Prognose zu berechnen.</p>
            </div>
        @endif

    </x-ui-page-container>
</x-ui-page>
