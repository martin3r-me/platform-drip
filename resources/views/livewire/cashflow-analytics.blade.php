<x-ui-page>
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
        <div class="flex items-center gap-4 mb-6">
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
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
                <div class="bg-white rounded-lg border border-gray-200 p-4">
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
                <div class="bg-white rounded-lg border border-gray-200 p-4">
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
                <div class="bg-white rounded-lg border border-gray-200 p-4">
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

        {{-- Trend (6 Monate) --}}
        @if(count($trend) > 0)
            @php
                $maxTrend = max(1, collect($trend)->max('debit'), collect($trend)->max('credit'));
            @endphp
            <div class="bg-white rounded-lg border border-gray-200 p-4 mb-6">
                <h2 class="text-sm font-semibold text-gray-900 mb-4">Trend (6 Monate)</h2>
                <div class="space-y-3">
                    @foreach($trend as $t)
                        @php $isSelected = $t['period'] === $selectedMonth; @endphp
                        <div class="flex items-center gap-3 {{ $isSelected ? 'bg-blue-50/50 -mx-2 px-2 py-1 rounded' : '' }}">
                            <div class="w-10 text-[11px] font-medium {{ $isSelected ? 'text-blue-700' : 'text-gray-500' }} shrink-0">{{ $t['label'] }}</div>
                            <div class="flex-1 space-y-1">
                                <div class="flex items-center gap-2">
                                    <div class="h-2.5 rounded-sm bg-green-500" style="width: {{ $maxTrend > 0 ? round($t['credit'] / $maxTrend * 100, 1) : 0 }}%"></div>
                                    <span class="text-[11px] tabular-nums text-gray-500">+{{ number_format($t['credit'], 0, ',', '.') }}</span>
                                </div>
                                <div class="flex items-center gap-2">
                                    <div class="h-2.5 rounded-sm bg-red-400" style="width: {{ $maxTrend > 0 ? round($t['debit'] / $maxTrend * 100, 1) : 0 }}%"></div>
                                    <span class="text-[11px] tabular-nums text-gray-500">-{{ number_format($t['debit'], 0, ',', '.') }}</span>
                                </div>
                            </div>
                            <div class="text-[11px] font-medium tabular-nums shrink-0 w-20 text-right {{ $t['net'] >= 0 ? 'text-green-600' : 'text-red-500' }}">
                                {{ $t['net'] >= 0 ? '+' : '' }}{{ number_format($t['net'], 0, ',', '.') }}
                            </div>
                        </div>
                    @endforeach
                </div>
                <div class="flex items-center gap-4 mt-3 pt-3 border-t border-gray-100">
                    <div class="flex items-center gap-1.5">
                        <div class="w-3 h-2.5 rounded-sm bg-green-500"></div>
                        <span class="text-[11px] text-gray-500">Einnahmen</span>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <div class="w-3 h-2.5 rounded-sm bg-red-400"></div>
                        <span class="text-[11px] text-gray-500">Ausgaben</span>
                    </div>
                </div>
            </div>
        @endif

        {{-- Top Kategorien + Counterparties --}}
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
            {{-- Top Kategorien --}}
            <div class="bg-white rounded-lg border border-gray-200 p-4">
                <h2 class="text-sm font-semibold text-gray-900 mb-4">
                    Top Kategorien
                    <span class="text-[11px] font-normal text-gray-400 ml-1">{{ $direction === 'debit' ? 'Ausgaben' : 'Einnahmen' }}</span>
                </h2>
                @if(count($topCategories) > 0)
                    @php $maxCat = max(1, collect($topCategories)->max('amount')); @endphp
                    <div class="space-y-2">
                        @foreach($topCategories as $cat)
                            <div class="flex items-center gap-3">
                                <div class="w-2.5 h-2.5 rounded-full shrink-0" style="background-color: {{ $cat['color'] }}"></div>
                                <div class="w-28 text-[13px] text-gray-700 truncate shrink-0">{{ $cat['name'] }}</div>
                                <div class="flex-1">
                                    <div class="h-3 rounded-sm" style="width: {{ round($cat['amount'] / $maxCat * 100, 1) }}%; background-color: {{ $cat['color'] }}"></div>
                                </div>
                                <div class="text-[12px] tabular-nums text-gray-500 shrink-0 w-10 text-right">{{ $cat['percent'] }}%</div>
                                <div class="text-[13px] font-medium tabular-nums text-gray-900 shrink-0 w-24 text-right">
                                    {{ number_format($cat['amount'], 2, ',', '.') }} &euro;
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <p class="text-[13px] text-gray-400 py-4 text-center">Keine Daten fuer diesen Monat</p>
                @endif
            </div>

            {{-- Top Counterparties --}}
            <div class="bg-white rounded-lg border border-gray-200 p-4">
                <h2 class="text-sm font-semibold text-gray-900 mb-4">
                    Top {{ $direction === 'debit' ? 'Zahlungsempfaenger' : 'Einzahler' }}
                    <span class="text-[11px] font-normal text-gray-400 ml-1">{{ $direction === 'debit' ? 'Ausgaben' : 'Einnahmen' }}</span>
                </h2>
                @if(count($topCounterparties) > 0)
                    @php $maxCp = max(1, collect($topCounterparties)->max('amount')); @endphp
                    <div class="space-y-2">
                        @foreach($topCounterparties as $cp)
                            <div class="flex items-center gap-3">
                                <div class="w-2.5 h-2.5 rounded-full shrink-0 bg-gray-400"></div>
                                <div class="w-28 text-[13px] text-gray-700 truncate shrink-0" title="{{ $cp['name'] }}">{{ $cp['name'] }}</div>
                                <div class="flex-1">
                                    <div class="h-3 rounded-sm {{ $direction === 'debit' ? 'bg-red-300' : 'bg-green-300' }}" style="width: {{ round($cp['amount'] / $maxCp * 100, 1) }}%"></div>
                                </div>
                                <div class="text-[12px] tabular-nums text-gray-500 shrink-0 w-10 text-right">{{ $cp['percent'] }}%</div>
                                <div class="text-[13px] font-medium tabular-nums text-gray-900 shrink-0 w-24 text-right">
                                    {{ number_format($cp['amount'], 2, ',', '.') }} &euro;
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <p class="text-[13px] text-gray-400 py-4 text-center">Keine Daten fuer diesen Monat</p>
                @endif
            </div>
        </div>

    </x-ui-page-container>
</x-ui-page>
