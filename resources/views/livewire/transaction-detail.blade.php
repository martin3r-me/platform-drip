<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="Transaktion" subtitle="{{ $transaction->counterparty_name ?? ($transaction->direction === 'debit' ? $transaction->creditor_name : $transaction->debtor_name) ?? $transaction->transaction_id }}">
            <div class="flex items-center space-x-2 text-sm">
                <a href="{{ route('drip.banks') }}" class="text-[var(--ui-secondary)] hover:text-[var(--ui-primary)] flex items-center gap-1">
                    @svg('heroicon-o-building-library', 'w-4 h-4')
                    Banken
                </a>
                <span class="text-[var(--ui-muted)]">›</span>
                @if($transaction->bankAccount?->group)
                    <a href="{{ route('drip.groups.show', $transaction->bankAccount->group) }}" class="text-[var(--ui-secondary)] hover:text-[var(--ui-primary)] flex items-center gap-1">
                        @svg('heroicon-o-banknotes', 'w-4 h-4')
                        {{ $transaction->bankAccount->group->name }}
                    </a>
                    <span class="text-[var(--ui-muted)]">›</span>
                @endif
                <span class="text-[var(--ui-muted)]">
                    {{ Str::limit($transaction->transaction_id ?? $transaction->uuid, 12) }}
                </span>
            </div>
        </x-ui-page-navbar>
    </x-slot>

    <x-ui-page-container>

        {{-- Header: Amount + Direction + Date --}}
        <x-ui-panel>
            <div class="p-6 flex items-center justify-between">
                <div class="flex items-center gap-4">
                    <span class="text-3xl font-bold {{ $transaction->direction === 'credit' ? 'text-[var(--ui-success)]' : 'text-red-600' }}">
                        {{ $transaction->direction === 'credit' ? '+' : '-' }}{{ number_format($transaction->amount, 2, ',', '.') }} {{ $transaction->currency }}
                    </span>
                    <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium {{ $transaction->direction === 'credit' ? 'bg-[var(--ui-success-soft)] text-[var(--ui-success)]' : 'bg-red-100 text-red-800' }}">
                        {{ $transaction->direction === 'credit' ? 'Einnahme' : 'Ausgabe' }}
                    </span>
                </div>
                <div class="text-sm text-[var(--ui-muted)]">
                    {{ $transaction->booked_at?->format('d.m.Y') ?? '-' }}
                </div>
            </div>
        </x-ui-panel>

        {{-- Gegenpartei --}}
        <x-ui-panel class="mt-4">
            <div class="p-6">
                <h3 class="text-sm font-medium text-[var(--ui-muted)] uppercase tracking-wider mb-4">Gegenpartei</h3>
                @php
                    $cpName = $transaction->counterparty_name
                        ?? ($transaction->direction === 'debit' ? $transaction->creditor_name : $transaction->debtor_name);
                    $cpIban = $transaction->counterparty_iban;
                    $cpAgent = $transaction->direction === 'debit'
                        ? $transaction->creditor_agent
                        : $transaction->debtor_agent;
                @endphp
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    @if($cpName)
                        <div>
                            <dt class="text-xs text-[var(--ui-muted)]">Name</dt>
                            <dd class="text-sm font-medium text-[var(--ui-primary)]">{{ $cpName }}</dd>
                        </div>
                    @endif
                    @if($cpIban)
                        <div>
                            <dt class="text-xs text-[var(--ui-muted)]">IBAN</dt>
                            <dd class="text-sm font-mono text-[var(--ui-secondary)]">{{ $cpIban }}</dd>
                        </div>
                    @endif
                    @if($cpAgent)
                        <div>
                            <dt class="text-xs text-[var(--ui-muted)]">BIC</dt>
                            <dd class="text-sm font-mono text-[var(--ui-secondary)]">{{ $cpAgent }}</dd>
                        </div>
                    @endif
                </div>
            </div>
        </x-ui-panel>

        {{-- Verwendungszweck --}}
        @if($transaction->reference || $transaction->remittance_information || $transaction->remittance_information_structured || $transaction->remittance_information_unstructured)
            <x-ui-panel class="mt-4">
                <div class="p-6">
                    <h3 class="text-sm font-medium text-[var(--ui-muted)] uppercase tracking-wider mb-4">Verwendungszweck</h3>
                    <div class="space-y-3">
                        @if($transaction->reference)
                            <div>
                                <dt class="text-xs text-[var(--ui-muted)]">Referenz</dt>
                                <dd class="text-sm text-[var(--ui-secondary)]">{{ $transaction->reference }}</dd>
                            </div>
                        @endif
                        @if($transaction->remittance_information)
                            <div>
                                <dt class="text-xs text-[var(--ui-muted)]">Verwendungszweck</dt>
                                <dd class="text-sm text-[var(--ui-secondary)]">{{ $transaction->remittance_information }}</dd>
                            </div>
                        @endif
                        @if($transaction->remittance_information_structured)
                            <div>
                                <dt class="text-xs text-[var(--ui-muted)]">Strukturiert</dt>
                                <dd class="text-sm text-[var(--ui-secondary)]">{{ $transaction->remittance_information_structured }}</dd>
                            </div>
                        @endif
                        @if($transaction->remittance_information_unstructured)
                            <div>
                                <dt class="text-xs text-[var(--ui-muted)]">Unstrukturiert</dt>
                                <dd class="text-sm text-[var(--ui-secondary)]">{{ $transaction->remittance_information_unstructured }}</dd>
                            </div>
                        @endif
                    </div>
                </div>
            </x-ui-panel>
        @endif

        {{-- Zusatzinformationen --}}
        @if($transaction->additional_information || $transaction->additional_information_structured || $transaction->purpose_code || $transaction->end_to_end_id || $transaction->mandate_id || $transaction->merchant_category_code || $transaction->creditor_id)
            <x-ui-panel class="mt-4">
                <div class="p-6">
                    <h3 class="text-sm font-medium text-[var(--ui-muted)] uppercase tracking-wider mb-4">Zusatzinformationen</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        @if($transaction->additional_information)
                            <div class="md:col-span-2">
                                <dt class="text-xs text-[var(--ui-muted)]">Zusätzliche Informationen</dt>
                                <dd class="text-sm text-[var(--ui-secondary)]">{{ $transaction->additional_information }}</dd>
                            </div>
                        @endif
                        @if($transaction->additional_information_structured)
                            <div class="md:col-span-2">
                                <dt class="text-xs text-[var(--ui-muted)]">Strukturierte Zusatzinfo</dt>
                                <dd class="text-sm text-[var(--ui-secondary)]">{{ $transaction->additional_information_structured }}</dd>
                            </div>
                        @endif
                        @if($transaction->purpose_code)
                            <div>
                                <dt class="text-xs text-[var(--ui-muted)]">Purpose Code</dt>
                                <dd class="text-sm font-mono text-[var(--ui-secondary)]">{{ $transaction->purpose_code }}</dd>
                            </div>
                        @endif
                        @if($transaction->end_to_end_id)
                            <div>
                                <dt class="text-xs text-[var(--ui-muted)]">End-to-End ID</dt>
                                <dd class="text-sm font-mono text-[var(--ui-secondary)]">{{ $transaction->end_to_end_id }}</dd>
                            </div>
                        @endif
                        @if($transaction->mandate_id)
                            <div>
                                <dt class="text-xs text-[var(--ui-muted)]">Mandatsreferenz</dt>
                                <dd class="text-sm font-mono text-[var(--ui-secondary)]">{{ $transaction->mandate_id }}</dd>
                            </div>
                        @endif
                        @if($transaction->merchant_category_code)
                            <div>
                                <dt class="text-xs text-[var(--ui-muted)]">Merchant Category Code</dt>
                                <dd class="text-sm font-mono text-[var(--ui-secondary)]">{{ $transaction->merchant_category_code }}</dd>
                            </div>
                        @endif
                        @if($transaction->creditor_id)
                            <div>
                                <dt class="text-xs text-[var(--ui-muted)]">Gläubiger-ID</dt>
                                <dd class="text-sm font-mono text-[var(--ui-secondary)]">{{ $transaction->creditor_id }}</dd>
                            </div>
                        @endif
                    </div>
                </div>
            </x-ui-panel>
        @endif

        {{-- Technische Details --}}
        @if($transaction->transaction_id || $transaction->internal_transaction_id || $transaction->entry_reference || $transaction->bank_transaction_code || $transaction->proprietary_bank_transaction_code)
            <x-ui-panel class="mt-4">
                <div class="p-6">
                    <h3 class="text-sm font-medium text-[var(--ui-muted)] uppercase tracking-wider mb-4">Technische Details</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        @if($transaction->transaction_id)
                            <div>
                                <dt class="text-xs text-[var(--ui-muted)]">Transaction ID</dt>
                                <dd class="text-sm font-mono text-[var(--ui-secondary)]">{{ $transaction->transaction_id }}</dd>
                            </div>
                        @endif
                        @if($transaction->internal_transaction_id)
                            <div>
                                <dt class="text-xs text-[var(--ui-muted)]">Internal Transaction ID</dt>
                                <dd class="text-sm font-mono text-[var(--ui-secondary)]">{{ $transaction->internal_transaction_id }}</dd>
                            </div>
                        @endif
                        @if($transaction->entry_reference)
                            <div>
                                <dt class="text-xs text-[var(--ui-muted)]">Entry Reference</dt>
                                <dd class="text-sm font-mono text-[var(--ui-secondary)]">{{ $transaction->entry_reference }}</dd>
                            </div>
                        @endif
                        @if($transaction->bank_transaction_code)
                            <div>
                                <dt class="text-xs text-[var(--ui-muted)]">Bank Transaction Code</dt>
                                <dd class="text-sm font-mono text-[var(--ui-secondary)]">{{ $transaction->bank_transaction_code }}</dd>
                            </div>
                        @endif
                        @if($transaction->proprietary_bank_transaction_code)
                            <div>
                                <dt class="text-xs text-[var(--ui-muted)]">Proprietary Code</dt>
                                <dd class="text-sm font-mono text-[var(--ui-secondary)]">{{ $transaction->proprietary_bank_transaction_code }}</dd>
                            </div>
                        @endif
                    </div>
                </div>
            </x-ui-panel>
        @endif

    </x-ui-page-container>

    <x-slot name="activity">
        <x-ui-page-sidebar title="Details" width="w-80" side="right" :defaultOpen="true" storeKey="activityOpen">
            <div class="p-6 space-y-5">
                {{-- Konto --}}
                <div>
                    <dt class="text-xs text-[var(--ui-muted)] uppercase tracking-wider">Konto</dt>
                    <dd class="text-sm font-medium text-[var(--ui-primary)] mt-1">{{ $transaction->bankAccount->name ?? '-' }}</dd>
                </div>

                {{-- Kategorie --}}
                <div>
                    <dt class="text-xs text-[var(--ui-muted)] uppercase tracking-wider">Kategorie</dt>
                    <dd class="text-sm text-[var(--ui-secondary)] mt-1">{{ $transaction->category->name ?? '-' }}</dd>
                </div>

                {{-- Status --}}
                <div>
                    <dt class="text-xs text-[var(--ui-muted)] uppercase tracking-wider">Status</dt>
                    <dd class="text-sm text-[var(--ui-secondary)] mt-1">{{ $transaction->status ?? '-' }}</dd>
                </div>

                {{-- Währung --}}
                <div>
                    <dt class="text-xs text-[var(--ui-muted)] uppercase tracking-wider">Währung</dt>
                    <dd class="text-sm text-[var(--ui-secondary)] mt-1">{{ $transaction->currency ?? '-' }}</dd>
                </div>

                <hr class="border-[var(--ui-border)]">

                {{-- Buchungsdatum --}}
                <div>
                    <dt class="text-xs text-[var(--ui-muted)] uppercase tracking-wider">Buchungsdatum</dt>
                    <dd class="text-sm text-[var(--ui-secondary)] mt-1">{{ $transaction->booking_date?->format('d.m.Y') ?? $transaction->booked_at?->format('d.m.Y') ?? '-' }}</dd>
                </div>

                {{-- Wertstellungsdatum --}}
                <div>
                    <dt class="text-xs text-[var(--ui-muted)] uppercase tracking-wider">Wertstellungsdatum</dt>
                    <dd class="text-sm text-[var(--ui-secondary)] mt-1">{{ $transaction->value_date?->format('d.m.Y') ?? '-' }}</dd>
                </div>

                <hr class="border-[var(--ui-border)]">

                {{-- Erstellt --}}
                <div>
                    <dt class="text-xs text-[var(--ui-muted)] uppercase tracking-wider">Erstellt</dt>
                    <dd class="text-sm text-[var(--ui-secondary)] mt-1">{{ $transaction->created_at?->format('d.m.Y H:i') ?? '-' }}</dd>
                </div>

                {{-- Aktualisiert --}}
                <div>
                    <dt class="text-xs text-[var(--ui-muted)] uppercase tracking-wider">Aktualisiert</dt>
                    <dd class="text-sm text-[var(--ui-secondary)] mt-1">{{ $transaction->updated_at?->format('d.m.Y H:i') ?? '-' }}</dd>
                </div>
            </div>
        </x-ui-page-sidebar>
    </x-slot>

</x-ui-page>
