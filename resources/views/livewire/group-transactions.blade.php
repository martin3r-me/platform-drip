<x-ui-page-container>
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

    {{-- Transaktionen Liste --}}
    <x-ui-panel>
        @if ($transactions->count() > 0)
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer hover:bg-gray-100" wire:click="sortBy('booked_at')">
                                <div class="flex items-center gap-1">
                                    Datum
                                    @if ($sortBy === 'booked_at')
                                        @svg($sortDirection === 'asc' ? 'heroicon-o-chevron-up' : 'heroicon-o-chevron-down', 'w-4 h-4')
                                    @endif
                                </div>
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer hover:bg-gray-100" wire:click="sortBy('amount')">
                                <div class="flex items-center gap-1">
                                    Betrag
                                    @if ($sortBy === 'amount')
                                        @svg($sortDirection === 'asc' ? 'heroicon-o-chevron-up' : 'heroicon-o-chevron-down', 'w-4 h-4')
                                    @endif
                                </div>
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Beschreibung
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Gegenpartei
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Konto
                            </th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        @foreach ($transactions as $transaction)
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    {{ $transaction->booked_at?->format('d.m.Y') ?? '-' }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                    <div class="flex items-center gap-2">
                                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium {{ $transaction->direction === 'credit' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' }}">
                                            {{ $transaction->direction === 'credit' ? '+' : '-' }}
                                        </span>
                                        <span class="font-medium {{ $transaction->direction === 'credit' ? 'text-green-600' : 'text-red-600' }}">
                                            {{ number_format($transaction->amount, 2, ',', '.') }} {{ $transaction->currency }}
                                        </span>
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-900">
                                    <div class="max-w-xs truncate">
                                        {{ $transaction->remittance_information ?? $transaction->reference ?? '-' }}
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-900">
                                    <div>
                                        <div class="font-medium">
                                            {{ $transaction->debtor_name ?? $transaction->creditor_name ?? $transaction->counterparty_name ?? '-' }}
                                        </div>
                                        @if($transaction->additional_information)
                                            <div class="text-xs text-gray-500 mt-1">
                                                {{ $transaction->additional_information }}
                                            </div>
                                        @endif
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-500">
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
                <div class="text-gray-400 mb-4">
                    @svg('heroicon-o-banknotes', 'w-16 h-16 mx-auto')
                </div>
                <h3 class="text-lg font-medium text-gray-900 mb-2">Keine Transaktionen</h3>
                <p class="text-gray-500">
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
