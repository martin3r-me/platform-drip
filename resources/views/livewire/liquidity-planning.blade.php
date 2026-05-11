<x-ui-page>
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

        {{-- Horizon selector + computed_at --}}
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

        {{-- Current Balance Card --}}
        <div class="bg-white rounded-2xl shadow-sm p-6 mb-8">
            <div class="text-[11px] font-medium text-gray-500 uppercase tracking-wide mb-1">Aktueller Kontostand</div>
            <div class="text-3xl font-bold tabular-nums {{ $plan['current_balance'] >= 0 ? 'text-gray-900' : 'text-red-600' }}">
                {{ number_format($plan['current_balance'], 2, ',', '.') }} &euro;
            </div>
        </div>

        {{-- Daily Balance Curve — Area Chart --}}
        @if(count($plan['daily_forecast']) > 1)
            @php
                $dailyData = $plan['daily_forecast'];
                $balances = array_column($dailyData, 'balance');
                $minBal = min($balances);
                $maxBal = max($balances);
            @endphp
            <div class="bg-white rounded-2xl shadow-sm p-6 mb-8 overflow-hidden" wire:key="balance-curve-{{ $monthsAhead }}">
                <div class="flex items-center justify-between mb-3">
                    <h3 class="text-xl font-bold text-gray-900">Kontoverlauf-Prognose</h3>
                    <div class="flex items-center gap-3 text-[11px] text-gray-400">
                        <span>Min: {{ number_format($minBal, 0, ',', '.') }} &euro;</span>
                        <span>Max: {{ number_format($maxBal, 0, ',', '.') }} &euro;</span>
                    </div>
                </div>
                <div wire:ignore x-data="{
                    chart: null,
                    init() {
                        const data = {{ Js::from(collect($dailyData)->map(fn($d) => ['x' => $d['date'], 'y' => round($d['balance'], 2)])->values()) }};
                        this.chart = new ApexCharts(this.$refs.el, {
                            chart: { type: 'area', height: 300, toolbar: { show: true, tools: { download: false, selection: true, zoom: true, zoomin: true, zoomout: true, pan: true, reset: true } }, fontFamily: 'inherit', zoom: { enabled: true } },
                            series: [{ name: 'Kontostand', data: data }],
                            colors: ['#3B82F6'],
                            stroke: { curve: 'smooth', width: 2 },
                            fill: { type: 'gradient', gradient: { shadeIntensity: 1, opacityFrom: 0.35, opacityTo: 0.05, stops: [0, 100] } },
                            xaxis: { type: 'datetime', labels: { style: { fontSize: '11px', colors: '#6B7280' }, datetimeFormatter: { month: 'MMM', day: 'dd. MMM' } } },
                            yaxis: { labels: { style: { fontSize: '11px', colors: '#6B7280' }, formatter: v => new Intl.NumberFormat('de-DE').format(Math.round(v)) + ' \u20AC' } },
                            tooltip: { x: { format: 'dd.MM.yyyy' }, y: { formatter: v => new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(v) } },
                            annotations: { yaxis: [{ y: 0, borderColor: '#EF4444', strokeDashArray: 4, opacity: 0.5, label: { text: '0 \u20AC', style: { color: '#EF4444', fontSize: '10px', background: 'transparent' } } }] },
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

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">

            {{-- Left: Monthly forecast table --}}
            <div class="lg:col-span-2">
                <div class="bg-white rounded-2xl shadow-sm overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-100">
                        <h3 class="text-xl font-bold text-gray-900">Monatliche Prognose</h3>
                    </div>

                    @if(count($plan['monthly_summary']) > 0)
                        {{-- Monthly Summary — Grouped Bar Chart --}}
                        <div class="px-6 py-4 border-b border-gray-100" wire:key="monthly-bars-{{ $monthsAhead }}">
                            <div wire:ignore x-data="{
                                chart: null,
                                init() {
                                    this.chart = new ApexCharts(this.$refs.el, {
                                        chart: { type: 'bar', height: 180, toolbar: { show: false }, fontFamily: 'inherit' },
                                        series: [
                                            { name: 'Einnahmen', data: {{ json_encode(array_column($plan['monthly_summary'], 'credits')) }} },
                                            { name: 'Ausgaben', data: {{ json_encode(array_column($plan['monthly_summary'], 'debits')) }} }
                                        ],
                                        colors: ['#4ADE80', '#F87171'],
                                        plotOptions: { bar: { columnWidth: '55%', borderRadius: 2 } },
                                        xaxis: { categories: {{ json_encode(array_map(fn($ms) => Str::limit($ms['month'], 7), $plan['monthly_summary'])) }}, labels: { style: { fontSize: '10px', colors: '#9CA3AF' } } },
                                        yaxis: { labels: { style: { fontSize: '10px', colors: '#9CA3AF' }, formatter: v => new Intl.NumberFormat('de-DE').format(Math.round(v)) } },
                                        tooltip: { y: { formatter: v => new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(v) } },
                                        dataLabels: { enabled: false },
                                        legend: { fontSize: '10px', labels: { colors: '#9CA3AF' } },
                                        grid: { borderColor: '#F3F4F6' }
                                    });
                                    this.chart.render();
                                },
                                destroy() { this.chart?.destroy(); }
                            }">
                                <div x-ref="el"></div>
                            </div>
                        </div>

                        {{-- Table --}}
                        <table class="w-full text-[13px]">
                            <thead>
                                <tr class="border-b border-gray-100">
                                    <th class="px-6 py-3 text-left font-medium text-gray-500">Monat</th>
                                    <th class="px-6 py-3 text-right font-medium text-green-600">Einnahmen</th>
                                    <th class="px-6 py-3 text-right font-medium text-red-600">Ausgaben</th>
                                    <th class="px-6 py-3 text-right font-medium text-gray-500">Netto</th>
                                    <th class="px-6 py-3 text-right font-medium text-gray-700">Endstand</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($plan['monthly_summary'] as $ms)
                                    <tr class="border-b border-gray-50 last:border-b-0">
                                        <td class="px-6 py-3 font-medium text-gray-700">{{ $ms['month'] }}</td>
                                        <td class="px-6 py-3 text-right tabular-nums text-green-600">+{{ number_format($ms['credits'], 2, ',', '.') }}</td>
                                        <td class="px-6 py-3 text-right tabular-nums text-red-600">-{{ number_format($ms['debits'], 2, ',', '.') }}</td>
                                        <td class="px-6 py-3 text-right tabular-nums {{ $ms['net'] >= 0 ? 'text-green-600' : 'text-red-600' }}">
                                            {{ $ms['net'] >= 0 ? '+' : '' }}{{ number_format($ms['net'], 2, ',', '.') }}
                                        </td>
                                        <td class="px-6 py-3 text-right tabular-nums font-medium {{ $ms['end_balance'] >= 0 ? 'text-gray-900' : 'text-red-600' }}">
                                            {{ number_format($ms['end_balance'], 2, ',', '.') }} &euro;
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @else
                        <div class="px-6 py-12 text-center">
                            <p class="text-[13px] text-gray-500">Keine Prognosedaten vorhanden.</p>
                            <p class="text-[11px] text-gray-400 mt-1">Fuehre <code class="bg-gray-100 px-1 rounded">drip:compute-liquidity</code> aus, um die Prognose zu berechnen.</p>
                        </div>
                    @endif
                </div>
            </div>

            {{-- Right: Upcoming items --}}
            <div class="lg:col-span-1">
                <div class="bg-white rounded-2xl shadow-sm overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-100">
                        <h3 class="text-xl font-bold text-gray-900">Naechste geplante Posten</h3>
                    </div>

                    @if(count($plan['upcoming_items']) > 0)
                        <div class="divide-y divide-gray-50">
                            @foreach($plan['upcoming_items'] as $item)
                                <div class="px-6 py-3">
                                    <div class="flex items-center justify-between mb-0.5">
                                        <span class="text-[13px] font-medium text-gray-900 truncate mr-2">{{ $item['name'] }}</span>
                                        <span class="text-[13px] tabular-nums font-medium shrink-0 {{ $item['direction'] === 'credit' ? 'text-green-600' : 'text-red-600' }}">
                                            {{ $item['direction'] === 'credit' ? '+' : '-' }}{{ number_format($item['amount'], 2, ',', '.') }} &euro;
                                        </span>
                                    </div>
                                    <div class="flex items-center gap-2 text-[11px] text-gray-400">
                                        <span>{{ \Illuminate\Support\Carbon::parse($item['date'])->format('d.m.Y') }}</span>
                                        @if($item['category'])
                                            <span>{{ $item['category'] }}</span>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <div class="px-6 py-12 text-center">
                            <p class="text-[13px] text-gray-500">Keine geplanten Posten.</p>
                            <p class="text-[11px] text-gray-400 mt-1">Erstelle Budgets, um Prognosen zu sehen.</p>
                        </div>
                    @endif
                </div>
            </div>

        </div>
    </x-ui-page-container>
</x-ui-page>
