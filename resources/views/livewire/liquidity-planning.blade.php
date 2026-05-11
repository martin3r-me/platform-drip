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

        {{-- Horizon selector --}}
        <div class="flex items-center justify-end gap-2 mb-4">
            <span class="text-[13px] text-gray-500">Zeitraum:</span>
            @foreach([3, 6, 12] as $m)
                <button wire:click="setMonthsAhead({{ $m }})"
                        class="px-2.5 py-1 rounded-md text-[12px] font-medium transition-colors {{ $monthsAhead === $m ? 'bg-blue-100 text-blue-700' : 'bg-gray-50 text-gray-600 hover:bg-gray-100' }}">
                    {{ $m }} Monate
                </button>
            @endforeach
        </div>

        {{-- Current Balance Card --}}
        <div class="bg-white rounded-lg border border-gray-200 p-6 mb-6">
            <div class="text-[11px] font-medium text-gray-500 uppercase tracking-wide mb-1">Aktueller Kontostand</div>
            <div class="text-3xl font-bold tabular-nums {{ $plan['current_balance'] >= 0 ? 'text-gray-900' : 'text-red-600' }}">
                {{ number_format($plan['current_balance'], 2, ',', '.') }} &euro;
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

            {{-- Left: Monthly forecast table --}}
            <div class="lg:col-span-2">
                <div class="bg-white rounded-lg border border-gray-200">
                    <div class="px-4 py-3 border-b border-gray-100">
                        <h3 class="text-sm font-semibold text-gray-900">Monatliche Prognose</h3>
                    </div>

                    {{-- Chart bars --}}
                    <div class="px-4 py-4 border-b border-gray-100">
                        <div class="flex items-end gap-2 h-32">
                            @php
                                $maxVal = max(1, max(array_column($plan['monthly_summary'], 'credits')), max(array_column($plan['monthly_summary'], 'debits')));
                            @endphp
                            @foreach($plan['monthly_summary'] as $ms)
                                <div class="flex-1 flex flex-col items-center gap-1">
                                    <div class="w-full flex gap-0.5 justify-center items-end h-24">
                                        <div class="w-1/3 bg-green-400 rounded-t" style="height: {{ max(2, $ms['credits'] / $maxVal * 100) }}%"></div>
                                        <div class="w-1/3 bg-red-400 rounded-t" style="height: {{ max(2, $ms['debits'] / $maxVal * 100) }}%"></div>
                                    </div>
                                    <span class="text-[10px] text-gray-400 truncate w-full text-center">{{ Str::limit($ms['month'], 7) }}</span>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    {{-- Table --}}
                    <table class="w-full text-[13px]">
                        <thead>
                            <tr class="border-b border-gray-100">
                                <th class="px-4 py-2 text-left font-medium text-gray-500">Monat</th>
                                <th class="px-4 py-2 text-right font-medium text-green-600">Einnahmen</th>
                                <th class="px-4 py-2 text-right font-medium text-red-600">Ausgaben</th>
                                <th class="px-4 py-2 text-right font-medium text-gray-500">Netto</th>
                                <th class="px-4 py-2 text-right font-medium text-gray-700">Endstand</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($plan['monthly_summary'] as $ms)
                                <tr class="border-b border-gray-50 last:border-b-0">
                                    <td class="px-4 py-2 font-medium text-gray-700">{{ $ms['month'] }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums text-green-600">+{{ number_format($ms['credits'], 2, ',', '.') }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums text-red-600">-{{ number_format($ms['debits'], 2, ',', '.') }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums {{ $ms['net'] >= 0 ? 'text-green-600' : 'text-red-600' }}">
                                        {{ $ms['net'] >= 0 ? '+' : '' }}{{ number_format($ms['net'], 2, ',', '.') }}
                                    </td>
                                    <td class="px-4 py-2 text-right tabular-nums font-medium {{ $ms['end_balance'] >= 0 ? 'text-gray-900' : 'text-red-600' }}">
                                        {{ number_format($ms['end_balance'], 2, ',', '.') }} &euro;
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Right: Upcoming items --}}
            <div class="lg:col-span-1">
                <div class="bg-white rounded-lg border border-gray-200">
                    <div class="px-4 py-3 border-b border-gray-100">
                        <h3 class="text-sm font-semibold text-gray-900">Naechste geplante Posten</h3>
                    </div>

                    @if(count($plan['upcoming_items']) > 0)
                        <div class="divide-y divide-gray-50">
                            @foreach($plan['upcoming_items'] as $item)
                                <div class="px-4 py-2.5">
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
                        <div class="px-4 py-8 text-center">
                            <p class="text-[13px] text-gray-500">Keine geplanten Posten.</p>
                            <p class="text-[11px] text-gray-400 mt-1">Erstelle Budgets, um Prognosen zu sehen.</p>
                        </div>
                    @endif
                </div>
            </div>

        </div>
    </x-ui-page-container>
</x-ui-page>
