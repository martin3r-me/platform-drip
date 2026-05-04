<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="{{ $group->name }}" subtitle="{{ $transactions->total() }} Transaktionen">
            {{-- Simple Breadcrumbs (wie im Planner) --}}
            <div class="flex items-center space-x-2 text-sm">
                <a href="{{ route('drip.banks') }}" class="text-[var(--ui-secondary)] hover:text-[var(--ui-primary)] flex items-center gap-1">
                    @svg('heroicon-o-building-library', 'w-4 h-4')
                    Banken
                </a>
                <span class="text-[var(--ui-muted)]">›</span>
                <span class="text-[var(--ui-muted)] flex items-center gap-1">
                    @svg('heroicon-o-banknotes', 'w-4 h-4')
                    {{ $group->name }}
                </span>
            </div>
            <x-slot name="actions">
                <x-ui-input-text name="search" placeholder="Transaktionen durchsuchen..." wire:model.live.debounce.300ms="search" />
            </x-slot>
        </x-ui-page-navbar>
    </x-slot>

    <x-ui-page-container>

    {{-- Transaktionen Liste --}}
    <x-ui-panel>
        @if ($transactions->count() > 0)
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y border border-[var(--ui-border)]/40">
                    <thead class="bg-[var(--ui-muted-5)]">
                        <tr>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-[var(--ui-muted)] uppercase tracking-wider cursor-pointer hover:bg-[var(--ui-muted-5)]" wire:click="sortBy('booked_at')">
                                <div class="flex items-center gap-1">
                                    Datum
                                    @if ($sortBy === 'booked_at')
                                        @svg($sortDirection === 'asc' ? 'heroicon-o-chevron-up' : 'heroicon-o-chevron-down', 'w-4 h-4')
                                    @endif
                                </div>
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-[var(--ui-muted)] uppercase tracking-wider">
                                Richtung
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-[var(--ui-muted)] uppercase tracking-wider cursor-pointer hover:bg-[var(--ui-muted-5)]" wire:click="sortBy('amount')">
                                <div class="flex items-center gap-1">
                                    Betrag
                                    @if ($sortBy === 'amount')
                                        @svg($sortDirection === 'asc' ? 'heroicon-o-chevron-up' : 'heroicon-o-chevron-down', 'w-4 h-4')
                                    @endif
                                </div>
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-[var(--ui-muted)] uppercase tracking-wider">
                                Gegenpartei
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-[var(--ui-muted)] uppercase tracking-wider">
                                Verwendungszweck
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-[var(--ui-muted)] uppercase tracking-wider">
                                Kategorie
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-[var(--ui-muted)] uppercase tracking-wider">
                                Konto
                            </th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y border-t border-[var(--ui-border)]/40">
                        @foreach ($transactions as $transaction)
                            <tr class="hover:bg-[var(--ui-muted-5)]/50 cursor-pointer" onclick="window.location='{{ route('drip.transactions.show', $transaction) }}'">
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-[var(--ui-secondary)]">
                                    {{ $transaction->booked_at?->format('d.m.Y') ?? '-' }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium {{ $transaction->direction === 'credit' ? 'bg-[var(--ui-success-soft)] text-[var(--ui-success)]' : 'bg-red-100 text-red-800' }}">
                                        {{ $transaction->direction === 'credit' ? 'Einnahme' : 'Ausgabe' }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                    <span class="font-medium {{ $transaction->direction === 'credit' ? 'text-[var(--ui-success)]' : 'text-red-600' }}">
                                        {{ $transaction->direction === 'credit' ? '+' : '-' }}{{ number_format($transaction->amount, 2, ',', '.') }} {{ $transaction->currency }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-sm text-[var(--ui-secondary)]">
                                    <div>
                                        <div class="font-medium">
                                            {{ $transaction->counterparty_name ?? ($transaction->direction === 'debit' ? $transaction->creditor_name : $transaction->debtor_name) ?? '-' }}
                                        </div>
                                        @php
                                            $displayIban = $transaction->counterparty_iban ?? ($transaction->direction === 'debit' ? $transaction->creditor_account_iban : $transaction->debtor_account_iban);
                                        @endphp
                                        @if($displayIban)
                                            <div class="text-xs text-[var(--ui-muted)] font-mono mt-0.5">
                                                {{ Str::limit($displayIban, 22) }}
                                            </div>
                                        @endif
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-sm text-[var(--ui-secondary)]">
                                    <div class="max-w-xs truncate">
                                        {{ $transaction->remittance_information ?? $transaction->reference ?? '-' }}
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-sm text-[var(--ui-muted)]">
                                    {{ $transaction->category->name ?? '-' }}
                                </td>
                                <td class="px-6 py-4 text-sm text-[var(--ui-muted)]">
                                    {{ $transaction->bankAccount->name }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- Pagination --}}
            @if ($transactions->hasPages())
                <div class="px-6 py-4 border-t border-gray-200">
                    {{ $transactions->links() }}
                </div>
            @endif
        @else
            <div class="text-center py-12">
                <div class="text-[var(--ui-muted)] mb-4">
                    @svg('heroicon-o-banknotes', 'w-16 h-16 mx-auto')
                </div>
                <h3 class="text-lg font-semibold text-[var(--ui-secondary)] mb-2">Keine Transaktionen</h3>
                <p class="text-[var(--ui-muted)]">
                    @if ($search)
                        Keine Transaktionen gefunden für "{{ $search }}"
                    @else
                        Diese Gruppe hat noch keine Transaktionen.
                    @endif
                </p>
            </div>
        @endif
    </x-ui-panel>

    </x-ui-page-container>

    <x-slot name="sidebar">
        <x-ui-page-sidebar title="Navigation" width="w-80" side="left" :defaultOpen="true" storeKey="sidebarOpen">
            <div class="p-6 space-y-4">
                <x-ui-button variant="secondary-outline" size="sm" :href="route('drip.banks')" wire:navigate class="w-full">
                    @svg('heroicon-o-building-library', 'w-4 h-4')
                    <span class="ml-2">Zur Banken-Übersicht</span>
                </x-ui-button>
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    <x-slot name="activity">
        <x-ui-page-sidebar title="Filter" width="w-80" side="right" :defaultOpen="true" storeKey="activityOpen">
            <div class="p-6 space-y-4">
                <x-ui-input-text name="searchRight" label="Suche" placeholder="Transaktionen durchsuchen" wire:model.live.debounce.300ms="search" />

                {{-- Direction Filter --}}
                <div>
                    <label class="block text-xs font-medium text-[var(--ui-muted)] uppercase tracking-wider mb-2">Richtung</label>
                    <div class="space-y-2">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="radio" wire:model.live="direction" value="" class="text-[var(--ui-primary)]">
                            <span class="text-sm text-[var(--ui-secondary)]">Alle</span>
                        </label>
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="radio" wire:model.live="direction" value="credit" class="text-[var(--ui-success)]">
                            <span class="text-sm text-[var(--ui-secondary)]">Einnahmen</span>
                        </label>
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="radio" wire:model.live="direction" value="debit" class="text-red-600">
                            <span class="text-sm text-[var(--ui-secondary)]">Ausgaben</span>
                        </label>
                    </div>
                </div>
            </div>
        </x-ui-page-sidebar>
    </x-slot>

</x-ui-page>
