<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="{{ $group->name }}" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Drip', 'href' => route('drip.dashboard'), 'icon' => 'chart-bar'],
            ['label' => $group->name],
            ['label' => 'Transaktionen'],
        ]">
            <x-slot name="left">
                <span class="text-[13px] text-gray-500">{{ $transactions->total() }} Transaktionen</span>
            </x-slot>
        </x-ui-page-actionbar>
    </x-slot>

    <x-ui-page-container>

        {{-- Summary Cards --}}
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-6 mb-8">
            <div class="bg-white rounded-2xl shadow-sm p-6">
                <div class="text-[11px] font-medium text-gray-500 uppercase tracking-wide">Einnahmen</div>
                <div class="mt-1 text-xl font-bold tabular-nums text-green-600">
                    +{{ number_format($totalIncome, 2, ',', '.') }} &euro;
                </div>
            </div>
            <div class="bg-white rounded-2xl shadow-sm p-6">
                <div class="text-[11px] font-medium text-gray-500 uppercase tracking-wide">Ausgaben</div>
                <div class="mt-1 text-xl font-bold tabular-nums text-red-600">
                    -{{ number_format($totalExpenses, 2, ',', '.') }} &euro;
                </div>
            </div>
            <div class="bg-white rounded-2xl shadow-sm p-6">
                <div class="text-[11px] font-medium text-gray-500 uppercase tracking-wide">Saldo</div>
                <div class="mt-1 text-xl font-bold tabular-nums {{ $totalBalance >= 0 ? 'text-green-600' : 'text-red-600' }}">
                    {{ $totalBalance >= 0 ? '+' : '' }}{{ number_format($totalBalance, 2, ',', '.') }} &euro;
                </div>
            </div>
        </div>

        {{-- Transactions Table --}}
        <div class="bg-white rounded-2xl shadow-sm overflow-hidden">
            @if ($transactions->count() > 0)
                <div class="overflow-x-auto">
                    <table class="min-w-full">
                        <thead>
                            <tr class="bg-gray-50">
                                <th scope="col" class="px-6 py-3 text-left text-[11px] font-medium text-gray-500 uppercase tracking-wider cursor-pointer hover:text-gray-900" wire:click="sortBy('booked_at')">
                                    <div class="flex items-center gap-1">
                                        Datum
                                        @if ($sortBy === 'booked_at')
                                            @svg($sortDirection === 'asc' ? 'heroicon-o-chevron-up' : 'heroicon-o-chevron-down', 'w-3 h-3')
                                        @endif
                                    </div>
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-[11px] font-medium text-gray-500 uppercase tracking-wider">
                                    Richtung
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-[11px] font-medium text-gray-500 uppercase tracking-wider cursor-pointer hover:text-gray-900" wire:click="sortBy('amount')">
                                    <div class="flex items-center gap-1">
                                        Betrag
                                        @if ($sortBy === 'amount')
                                            @svg($sortDirection === 'asc' ? 'heroicon-o-chevron-up' : 'heroicon-o-chevron-down', 'w-3 h-3')
                                        @endif
                                    </div>
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-[11px] font-medium text-gray-500 uppercase tracking-wider">
                                    Gegenpartei
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-[11px] font-medium text-gray-500 uppercase tracking-wider">
                                    Verwendungszweck
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-[11px] font-medium text-gray-500 uppercase tracking-wider">
                                    Kategorie
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-[11px] font-medium text-gray-500 uppercase tracking-wider">
                                    Konto
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($transactions as $transaction)
                                <tr class="hover:bg-blue-50/50 cursor-pointer transition-colors" onclick="window.location='{{ route('drip.transactions.show', $transaction) }}'">
                                    <td class="px-6 py-3.5 whitespace-nowrap text-[13px] text-gray-900">
                                        {{ $transaction->booked_at?->format('d.m.Y') ?? '-' }}
                                    </td>
                                    <td class="px-6 py-3.5 whitespace-nowrap">
                                        <span class="inline-flex items-center px-1.5 py-0.5 rounded-full text-[11px] font-medium {{ $transaction->direction === 'credit' ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700' }}">
                                            {{ $transaction->direction === 'credit' ? 'Einnahme' : 'Ausgabe' }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-3.5 whitespace-nowrap text-[13px]">
                                        <span class="font-medium tabular-nums {{ $transaction->direction === 'credit' ? 'text-green-600' : 'text-red-600' }}">
                                            {{ $transaction->direction === 'credit' ? '+' : '-' }}{{ number_format($transaction->amount, 2, ',', '.') }} {{ $transaction->currency }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-3.5 text-[13px] text-gray-900">
                                        <div>
                                            <div class="font-medium">
                                                {{ $transaction->counterparty_name ?? ($transaction->direction === 'debit' ? $transaction->creditor_name : $transaction->debtor_name) ?? '-' }}
                                            </div>
                                            @php
                                                $displayIban = $transaction->counterparty_iban ?? ($transaction->direction === 'debit' ? $transaction->creditor_account_iban : $transaction->debtor_account_iban);
                                            @endphp
                                            @if($displayIban)
                                                <div class="text-[11px] text-gray-400 font-mono mt-0.5">
                                                    {{ Str::limit($displayIban, 22) }}
                                                </div>
                                            @endif
                                        </div>
                                    </td>
                                    <td class="px-6 py-3.5 text-[13px] text-gray-500">
                                        <div class="max-w-xs truncate">
                                            {{ $transaction->remittance_information ?? $transaction->reference ?? '-' }}
                                        </div>
                                    </td>
                                    <td class="px-6 py-3.5 text-[13px] text-gray-500" onclick="event.stopPropagation()">
                                        <select wire:change="updateCategory({{ $transaction->id }}, $event.target.value)"
                                                class="px-1.5 py-0.5 rounded border border-transparent hover:border-gray-200 focus:border-blue-500 text-[13px] bg-transparent focus:outline-none focus:ring-1 focus:ring-blue-500 cursor-pointer {{ $transaction->category ? 'text-gray-900' : 'text-gray-400' }}">
                                            <option value="">—</option>
                                            @foreach ($categories as $cat)
                                                <option value="{{ $cat->id }}" @selected($transaction->category_id === $cat->id)>
                                                    {{ $cat->parent_id ? '└ ' : '' }}{{ $cat->name }}
                                                </option>
                                            @endforeach
                                        </select>
                                    </td>
                                    <td class="px-6 py-3.5 text-[13px] text-gray-500">
                                        {{ $transaction->bankAccount->name }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if ($transactions->hasPages())
                    <div class="px-6 py-4 border-t border-gray-100">
                        {{ $transactions->links() }}
                    </div>
                @endif
            @else
                <div class="text-center py-16">
                    <div class="text-gray-400 mb-3">
                        @svg('heroicon-o-banknotes', 'w-12 h-12 mx-auto')
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 mb-1">Keine Transaktionen</h3>
                    <p class="text-[13px] text-gray-500">
                        @if ($search)
                            Keine Transaktionen gefunden f&uuml;r &ldquo;{{ $search }}&rdquo;
                        @else
                            Diese Gruppe hat noch keine Transaktionen.
                        @endif
                    </p>
                </div>
            @endif
        </div>

    </x-ui-page-container>

    <x-slot name="activity">
        <x-ui-page-sidebar title="Filter" width="w-80" side="right" :defaultOpen="true" storeKey="activityOpen">
            <div class="p-4 space-y-4">
                <div>
                    <label class="block text-[11px] font-medium text-gray-500 uppercase tracking-wide mb-1.5">Suche</label>
                    <input type="text" wire:model.live.debounce.300ms="search" placeholder="Transaktionen durchsuchen..."
                           class="w-full px-3 py-1.5 rounded-md border border-gray-200 text-[13px] text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-1 focus:ring-blue-500 focus:border-blue-500">
                </div>

                <div>
                    <label class="block text-[11px] font-medium text-gray-500 uppercase tracking-wide mb-1.5">Kategorie</label>
                    <select wire:model.live="categoryFilter"
                            class="w-full px-3 py-1.5 rounded-md border border-gray-200 text-[13px] text-gray-900 focus:outline-none focus:ring-1 focus:ring-blue-500 focus:border-blue-500">
                        <option value="">Alle</option>
                        <option value="none">Ohne Kategorie</option>
                        @foreach ($categories as $cat)
                            <option value="{{ $cat->id }}">
                                {{ $cat->parent_id ? '└ ' : '' }}{{ $cat->name }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="block text-[11px] font-medium text-gray-500 uppercase tracking-wide mb-1.5">Richtung</label>
                    <div class="space-y-1.5">
                        <label class="flex items-center gap-2 cursor-pointer px-2 py-1 rounded-md hover:bg-gray-50">
                            <input type="radio" wire:model.live="direction" value="" class="text-blue-600 focus:ring-blue-500">
                            <span class="text-[13px] text-gray-700">Alle</span>
                        </label>
                        <label class="flex items-center gap-2 cursor-pointer px-2 py-1 rounded-md hover:bg-gray-50">
                            <input type="radio" wire:model.live="direction" value="credit" class="text-green-600 focus:ring-green-500">
                            <span class="text-[13px] text-gray-700">Einnahmen</span>
                        </label>
                        <label class="flex items-center gap-2 cursor-pointer px-2 py-1 rounded-md hover:bg-gray-50">
                            <input type="radio" wire:model.live="direction" value="debit" class="text-red-600 focus:ring-red-500">
                            <span class="text-[13px] text-gray-700">Ausgaben</span>
                        </label>
                    </div>
                </div>
            </div>
        </x-ui-page-sidebar>
    </x-slot>

</x-ui-page>
