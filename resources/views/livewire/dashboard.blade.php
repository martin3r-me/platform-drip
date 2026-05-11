<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="Dashboard" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Drip', 'href' => route('drip.dashboard'), 'icon' => 'chart-bar'],
        ]" />
    </x-slot>

    <x-ui-page-container>

        {{-- Stat Cards --}}
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
            {{-- Kontostand --}}
            <div class="bg-white rounded-lg border border-gray-200 p-4">
                <div class="text-[11px] font-medium text-gray-500 uppercase tracking-wide">Kontostand</div>
                <div class="mt-1 text-2xl font-bold tabular-nums text-gray-900">
                    {{ number_format($totalBalance, 2, ',', '.') }} &euro;
                </div>
            </div>

            {{-- Einnahmen 30T --}}
            <div class="bg-white rounded-lg border border-gray-200 p-4">
                <div class="text-[11px] font-medium text-gray-500 uppercase tracking-wide">Einnahmen (30T)</div>
                <div class="mt-1 text-2xl font-bold tabular-nums text-green-600">
                    +{{ number_format($income30d, 2, ',', '.') }} &euro;
                </div>
                @if($incomePrev30d > 0)
                    @php $incomeChange = $incomePrev30d > 0 ? round(($income30d - $incomePrev30d) / $incomePrev30d * 100, 1) : 0; @endphp
                    <div class="mt-1 text-[11px] {{ $incomeChange >= 0 ? 'text-green-600' : 'text-red-500' }}">
                        {{ $incomeChange >= 0 ? '+' : '' }}{{ $incomeChange }}% vs. Vormonat
                    </div>
                @endif
            </div>

            {{-- Ausgaben 30T --}}
            <div class="bg-white rounded-lg border border-gray-200 p-4">
                <div class="text-[11px] font-medium text-gray-500 uppercase tracking-wide">Ausgaben (30T)</div>
                <div class="mt-1 text-2xl font-bold tabular-nums text-red-600">
                    -{{ number_format($expenses30d, 2, ',', '.') }} &euro;
                </div>
                @if($expensesPrev30d > 0)
                    @php $expenseChange = $expensesPrev30d > 0 ? round(($expenses30d - $expensesPrev30d) / $expensesPrev30d * 100, 1) : 0; @endphp
                    <div class="mt-1 text-[11px] {{ $expenseChange <= 0 ? 'text-green-600' : 'text-red-500' }}">
                        {{ $expenseChange >= 0 ? '+' : '' }}{{ $expenseChange }}% vs. Vormonat
                    </div>
                @endif
            </div>

            {{-- Transaktionen 30T --}}
            <div class="bg-white rounded-lg border border-gray-200 p-4">
                <div class="text-[11px] font-medium text-gray-500 uppercase tracking-wide">Transaktionen (30T)</div>
                <div class="mt-1 text-2xl font-bold tabular-nums text-gray-900">
                    {{ $transactions30d }}
                </div>
            </div>
        </div>

        {{-- Cashflow Balken (6 Monate) --}}
        @if(count($monthlyFlow) > 0)
            @php
                $maxVal = max(1, collect($monthlyFlow)->max('income'), collect($monthlyFlow)->max('expenses'));
            @endphp
            <div class="bg-white rounded-lg border border-gray-200 p-4 mb-6">
                <h2 class="text-sm font-semibold text-gray-900 mb-4">Cashflow (6 Monate)</h2>
                <div class="space-y-3">
                    @foreach($monthlyFlow as $m)
                        <div class="flex items-center gap-3">
                            <div class="w-16 text-[11px] font-medium text-gray-500 shrink-0">{{ $m['month_short'] }}</div>
                            <div class="flex-1 space-y-1">
                                {{-- Einnahmen --}}
                                <div class="flex items-center gap-2">
                                    <div class="h-3 rounded-sm bg-green-500" style="width: {{ $maxVal > 0 ? round($m['income'] / $maxVal * 100, 1) : 0 }}%"></div>
                                    <span class="text-[11px] tabular-nums text-gray-500">+{{ number_format($m['income'], 0, ',', '.') }}</span>
                                </div>
                                {{-- Ausgaben --}}
                                <div class="flex items-center gap-2">
                                    <div class="h-3 rounded-sm bg-red-400" style="width: {{ $maxVal > 0 ? round($m['expenses'] / $maxVal * 100, 1) : 0 }}%"></div>
                                    <span class="text-[11px] tabular-nums text-gray-500">-{{ number_format($m['expenses'], 0, ',', '.') }}</span>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
                <div class="flex items-center gap-4 mt-3 pt-3 border-t border-gray-100">
                    <div class="flex items-center gap-1.5">
                        <div class="w-3 h-3 rounded-sm bg-green-500"></div>
                        <span class="text-[11px] text-gray-500">Einnahmen</span>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <div class="w-3 h-3 rounded-sm bg-red-400"></div>
                        <span class="text-[11px] text-gray-500">Ausgaben</span>
                    </div>
                </div>
            </div>
        @endif

        {{-- Ausgaben nach Kategorie (30T) --}}
        @if(count($categoryBreakdown) > 0)
            @php
                $maxCatAmount = max(1, collect($categoryBreakdown)->max('amount'));
            @endphp
            <div class="bg-white rounded-lg border border-gray-200 p-4 mb-6">
                <h2 class="text-sm font-semibold text-gray-900 mb-4">Ausgaben nach Kategorie (30T)</h2>
                <div class="space-y-2">
                    @foreach($categoryBreakdown as $cat)
                        <div class="flex items-center gap-3">
                            <div class="w-2.5 h-2.5 rounded-full shrink-0" style="background-color: {{ $cat['color'] }}"></div>
                            <div class="w-32 text-[13px] text-gray-700 truncate shrink-0">{{ $cat['name'] }}</div>
                            <div class="flex-1">
                                <div class="h-3 rounded-sm" style="width: {{ round($cat['amount'] / $maxCatAmount * 100, 1) }}%; background-color: {{ $cat['color'] }}"></div>
                            </div>
                            <div class="text-[13px] font-medium tabular-nums text-gray-900 shrink-0 w-24 text-right">
                                {{ number_format($cat['amount'], 2, ',', '.') }} &euro;
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Budget-Status --}}
        @if(count($budgetOverview) > 0)
            <div class="bg-white rounded-lg border border-gray-200 p-4 mb-6">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-sm font-semibold text-gray-900">Budget-Status</h2>
                    <a href="{{ route('drip.budgets') }}" wire:navigate class="text-[11px] text-blue-600 hover:text-blue-700">
                        Alle Budgets
                        @if($budgetSuggestionsCount > 0)
                            <span class="ml-1 inline-flex items-center justify-center px-1.5 py-0.5 rounded-full text-[10px] font-medium bg-blue-100 text-blue-700">{{ $budgetSuggestionsCount }} {{ $budgetSuggestionsCount === 1 ? 'Vorschlag' : 'Vorschlaege' }}</span>
                        @endif
                    </a>
                </div>
                <div class="space-y-2.5">
                    @foreach($budgetOverview as $b)
                        @php
                            $barColor = $b['percent'] <= 100 ? 'bg-green-500' : ($b['percent'] <= 120 ? 'bg-yellow-500' : 'bg-red-500');
                            $barWidth = min($b['percent'], 100);
                        @endphp
                        <div class="flex items-center gap-3">
                            <div class="w-2.5 h-2.5 rounded-full shrink-0" style="background-color: {{ $b['category_color'] }}"></div>
                            <div class="w-28 text-[13px] text-gray-700 truncate shrink-0">{{ $b['name'] }}</div>
                            <div class="flex-1 h-2 bg-gray-100 rounded-full overflow-hidden">
                                <div class="{{ $barColor }} h-2 rounded-full" style="width: {{ $barWidth }}%"></div>
                            </div>
                            <div class="text-[12px] font-medium tabular-nums text-gray-600 shrink-0 w-32 text-right">
                                {{ number_format($b['actual'], 0, ',', '.') }} / {{ number_format($b['budget'], 0, ',', '.') }} &euro;
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            {{-- Letzte Transaktionen --}}
            <div class="bg-white rounded-lg border border-gray-200">
                <div class="px-4 py-3 border-b border-gray-100">
                    <h2 class="text-sm font-semibold text-gray-900">Letzte Transaktionen</h2>
                </div>
                <div class="divide-y divide-gray-100">
                    @forelse(($recentTransactions ?? []) as $t)
                        <a href="{{ route('drip.transactions.show', $t) }}" wire:navigate
                           class="flex items-center justify-between px-4 py-2.5 hover:bg-blue-50/50 transition-colors">
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
                        <div class="px-4 py-8 text-center">
                            <div class="text-gray-400 mb-2">
                                @svg('heroicon-o-banknotes', 'w-8 h-8 mx-auto')
                            </div>
                            <p class="text-[13px] text-gray-500">Keine Transaktionen vorhanden</p>
                        </div>
                    @endforelse
                </div>
            </div>

            {{-- Kontogruppen --}}
            <div class="bg-white rounded-lg border border-gray-200">
                <div class="px-4 py-3 border-b border-gray-100">
                    <h2 class="text-sm font-semibold text-gray-900">Kontogruppen</h2>
                </div>
                <div class="divide-y divide-gray-100">
                    @forelse(($groups ?? []) as $g)
                        <div class="flex items-center justify-between px-4 py-2.5 hover:bg-blue-50/50 transition-colors">
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
                        <div class="px-4 py-8 text-center">
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
                        <div class="flex items-center justify-between">
                            <span class="text-gray-500">Transaktionen (30T)</span>
                            <span class="font-medium text-gray-900">{{ $transactions30d }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </x-ui-page-sidebar>
    </x-slot>

</x-ui-page>
