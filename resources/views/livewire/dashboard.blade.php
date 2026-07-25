<x-ui-page>
    @include('drip::partials.styles')
    <x-slot name="navbar">
        <x-ui-page-navbar title="Dashboard" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Drip', 'href' => route('drip.dashboard'), 'icon' => 'chart-bar'],
        ]" />
    </x-slot>

    <x-ui-page-container>

        {{-- Alerts --}}
        @if(count($alerts) > 0)
            <div class="flex flex-wrap items-center gap-2 mb-6">
                @foreach($alerts as $alert)
                    @php
                        $colors = match($alert['type']) {
                            'danger' => 'bg-red-50 text-red-700 border-red-200',
                            'warning' => 'bg-yellow-50 text-yellow-700 border-yellow-200',
                            'info' => 'bg-gray-50 text-gray-600 border-gray-200',
                            'primary' => 'bg-blue-50 text-blue-700 border-blue-200',
                            default => 'bg-gray-50 text-gray-600 border-gray-200',
                        };
                        $iconColor = match($alert['type']) {
                            'danger' => 'text-red-500',
                            'warning' => 'text-yellow-500',
                            'info' => 'text-gray-400',
                            'primary' => 'text-blue-500',
                            default => 'text-gray-400',
                        };
                    @endphp
                    <a href="{{ $alert['link'] }}" wire:navigate
                       class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border text-[12px] font-medium transition-colors hover:opacity-80 {{ $colors }}">
                        @svg('heroicon-o-' . $alert['icon'], 'w-3.5 h-3.5 ' . $iconColor)
                        {{ $alert['message'] }}
                    </a>
                @endforeach
            </div>
        @endif

        {{-- Controls: Period Type + Month Dropdown + Comparison Mode --}}
        <div class="flex items-center gap-4 mb-6 flex-wrap">
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

        {{-- Stat Cards (4-spaltig): Kontostand + Ausgaben + Einnahmen + Netto --}}
        @if(!empty($comparison))
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                {{-- Kontostand --}}
                <div class="bg-white rounded-2xl shadow-sm p-6">
                    <div class="text-[11px] font-medium text-gray-500 uppercase tracking-wide">Kontostand</div>
                    <div class="mt-1 text-2xl font-bold tabular-nums text-gray-900">
                        {{ number_format($totalBalance, 2, ',', '.') }} &euro;
                    </div>
                </div>

                {{-- Ausgaben --}}
                <div class="bg-white rounded-2xl shadow-sm p-6">
                    <div class="text-[11px] font-medium text-gray-500 uppercase tracking-wide">Ausgaben</div>
                    <div class="mt-1 text-2xl font-bold tabular-nums text-red-600">
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
                    <div class="mt-1 text-2xl font-bold tabular-nums text-green-600">
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
                    <div class="mt-1 text-2xl font-bold tabular-nums {{ $comparison['net_current'] >= 0 ? 'text-green-600' : 'text-red-600' }}">
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
            <div class="bg-white rounded-2xl shadow-sm p-6 mb-8 overflow-hidden" wire:key="trend-{{ $selectedMonth }}-{{ $periodType }}">
                <h2 class="text-lg font-bold text-gray-900 mb-4">Trend (6 {{ match($periodType) { 'quarter' => 'Quartale', 'year' => 'Jahre', default => 'Monate' } }})</h2>
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

        {{-- 3-spaltig: Donut + Top Kategorien + Top Counterparties --}}
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8 mb-8" wire:key="details-{{ $selectedMonth }}-{{ $periodType }}">
            {{-- Donut Chart --}}
            <div class="bg-white rounded-2xl shadow-sm p-6 overflow-hidden">
                <h2 class="text-lg font-bold text-gray-900 mb-4">
                    Kategorie-Anteile
                    <span class="text-[11px] font-normal text-gray-400 ml-1">Ausgaben</span>
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

            {{-- Top Categories --}}
            <div class="bg-white rounded-2xl shadow-sm p-6 overflow-hidden">
                <h2 class="text-lg font-bold text-gray-900 mb-4">
                    Top Kategorien
                    <span class="text-[11px] font-normal text-gray-400 ml-1">Ausgaben</span>
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
                                @php $maxAmount = collect($topCategories)->max('amount'); @endphp
                                <div class="h-1.5 bg-gray-100 rounded-full overflow-hidden">
                                    <div class="h-1.5 rounded-full" style="width: {{ $maxAmount > 0 ? round($cat['amount'] / $maxAmount * 100) : 0 }}%; background-color: {{ $cat['color'] }}"></div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <p class="text-[13px] text-gray-400 py-4 text-center">Keine Daten fuer diesen Zeitraum</p>
                @endif
            </div>

            {{-- Top Counterparties --}}
            <div class="bg-white rounded-2xl shadow-sm p-6 overflow-hidden">
                <h2 class="text-lg font-bold text-gray-900 mb-4">
                    Top Zahlungsempfaenger
                    <span class="text-[11px] font-normal text-gray-400 ml-1">Ausgaben</span>
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
                    <p class="text-[13px] text-gray-400 py-4 text-center">Keine Daten fuer diesen Zeitraum</p>
                @endif
            </div>
        </div>

        {{-- Category Drilldown (conditional) --}}
        @if($selectedCategoryId)
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-8" wire:key="cat-detail-{{ $selectedCategoryId }}">
                {{-- Category Trend --}}
                @if(count($categoryTrend) > 0)
                    <div class="bg-white rounded-2xl shadow-sm p-6 overflow-hidden">
                        <div class="flex items-center justify-between mb-4">
                            <h2 class="text-lg font-bold text-gray-900">Kategorie-Trend</h2>
                            <button wire:click="selectCategory(null)" class="text-[11px] text-gray-400 hover:text-gray-600">Schliessen</button>
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
                    </div>
                @endif

                {{-- Category Transactions --}}
                <div class="bg-white rounded-2xl shadow-sm overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-100">
                        <div class="flex items-center justify-between">
                            <h2 class="text-lg font-bold text-gray-900">Transaktionen</h2>
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
                                    <span class="text-[12px] font-medium tabular-nums shrink-0 text-red-600">
                                        -{{ number_format($ct['amount'], 2, ',', '.') }} &euro;
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

        {{-- Recent Transactions + Groups --}}
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
            {{-- Letzte Transaktionen --}}
            <div class="bg-white rounded-2xl shadow-sm overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-100">
                    <h2 class="text-lg font-bold text-gray-900">Letzte Transaktionen</h2>
                </div>
                <div class="divide-y divide-gray-100">
                    @forelse(($recentTransactions ?? []) as $t)
                        <a href="{{ route('drip.transactions.show', $t) }}" wire:navigate
                           class="flex items-center justify-between px-6 py-3 hover:bg-gray-50/50 transition-colors">
                            <div class="flex-1 min-w-0 mr-3">
                                <div class="text-[13px] text-gray-900 truncate">
                                    {{ $t->counterparty_name ?? ($t->direction === 'debit' ? $t->creditor_name : $t->debtor_name) ?? ($t->remittance_information ?? $t->reference ?? '-') }}
                                </div>
                                <div class="text-[11px] text-gray-500 truncate mt-0.5">
                                    {{ $t->booked_at?->format('d.m.Y') ?? '-' }}
                                    @if($t->remittance_information)
                                        &middot; {{ Str::limit($t->remittance_information, 40) }}
                                    @endif
                                </div>
                            </div>
                            <div class="text-[13px] font-medium tabular-nums shrink-0 {{ $t->direction === 'credit' ? 'text-green-600' : 'text-red-600' }}">
                                {{ $t->direction === 'credit' ? '+' : '-' }}{{ number_format($t->amount, 2, ',', '.') }} {{ $t->currency }}
                            </div>
                        </a>
                    @empty
                        <div class="px-6 py-12 text-center">
                            <div class="text-gray-400 mb-2">
                                @svg('heroicon-o-banknotes', 'w-8 h-8 mx-auto')
                            </div>
                            <p class="text-[13px] text-gray-500">Keine Transaktionen vorhanden</p>
                        </div>
                    @endforelse
                </div>
            </div>

            {{-- Kontogruppen --}}
            <div class="bg-white rounded-2xl shadow-sm overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-100">
                    <h2 class="text-lg font-bold text-gray-900">Kontogruppen</h2>
                </div>
                <div class="divide-y divide-gray-100">
                    @forelse(($groups ?? []) as $g)
                        <div class="flex items-center justify-between px-6 py-3 hover:bg-gray-50/50 transition-colors">
                            <div class="flex items-center gap-2">
                                <div class="w-2.5 h-2.5 rounded-full shrink-0" style="background-color: {{ $g->color ?? '#6B7280' }}"></div>
                                <span class="text-[13px] text-gray-900">{{ $g->name }}</span>
                                <span class="text-[11px] text-gray-400">{{ $g->bank_accounts_count ?? 0 }} Konten</span>
                            </div>
                            <a href="{{ route('drip.groups.show', $g) }}" wire:navigate
                               class="inline-flex items-center gap-1 px-2 py-1 rounded-md text-[11px] font-medium text-gray-600 hover:text-gray-900 hover:bg-gray-100 transition-colors">
                                @svg('heroicon-o-banknotes', 'w-3.5 h-3.5')
                                Transaktionen
                            </a>
                        </div>
                    @empty
                        <div class="px-6 py-12 text-center">
                            <div class="text-gray-400 mb-2">
                                @svg('heroicon-o-folder', 'w-8 h-8 mx-auto')
                            </div>
                            <p class="text-[13px] text-gray-500">Keine Gruppen vorhanden</p>
                        </div>
                    @endforelse
                </div>
            </div>
        </div>

    </x-ui-page-container>

    <x-slot name="activity">
        <x-ui-page-sidebar title="Info" width="w-80" side="right" :defaultOpen="true" storeKey="activityOpen">
            <div class="p-4 space-y-5">
                {{-- Letzter Sync --}}
                <div>
                    <div class="text-[11px] font-medium text-gray-500 uppercase tracking-wide mb-1">Letzter Sync</div>
                    <div class="text-[13px] text-gray-900">
                        @if($lastSyncAt)
                            {{ \Carbon\Carbon::parse($lastSyncAt)->diffForHumans() }}
                        @else
                            Noch nicht synchronisiert
                        @endif
                    </div>
                </div>

                {{-- Schnellzugriff --}}
                <div>
                    <div class="text-[11px] font-medium text-gray-500 uppercase tracking-wide mb-2">Schnellaktionen</div>
                    <div class="space-y-1.5">
                        <a href="{{ route('drip.banks') }}" wire:navigate
                           class="flex items-center gap-2 px-3 py-2 rounded-md text-[13px] text-gray-700 hover:bg-gray-100 transition-colors">
                            @svg('heroicon-o-building-library', 'w-4 h-4 text-gray-400')
                            Banken verwalten
                        </a>
                    </div>
                </div>

                {{-- Konteninfo --}}
                <div>
                    <div class="text-[11px] font-medium text-gray-500 uppercase tracking-wide mb-2">Statistiken</div>
                    <div class="space-y-1.5 text-[13px]">
                        <div class="flex items-center justify-between">
                            <span class="text-gray-500">Gruppen</span>
                            <span class="font-medium text-gray-900">{{ $groupsCount }}</span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-gray-500">Konten</span>
                            <span class="font-medium text-gray-900">{{ $accountsCount }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </x-ui-page-sidebar>
    </x-slot>

</x-ui-page>
