<x-ui-page>
    @include('drip::partials.styles')
    <x-slot name="navbar">
        <x-ui-page-navbar title="Transaktion" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="array_filter([
            ['label' => 'Drip', 'href' => route('drip.dashboard'), 'icon' => 'chart-bar'],
            $transaction->bankAccount?->group ? ['label' => $transaction->bankAccount->group->name, 'href' => route('drip.groups.show', $transaction->bankAccount->group)] : null,
            ['label' => Str::limit($transaction->counterparty_name ?? $transaction->transaction_id ?? $transaction->uuid, 20)],
        ])" />
    </x-slot>

    <x-ui-page-container>

        {{-- Header: Amount + Direction + Date --}}
        <div class="bg-white rounded-2xl shadow-sm p-6 mb-6">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-4">
                    <span class="text-3xl font-bold tabular-nums {{ $transaction->direction === 'credit' ? 'text-green-600' : 'text-red-600' }}">
                        {{ $transaction->direction === 'credit' ? '+' : '-' }}{{ number_format($transaction->amount, 2, ',', '.') }} {{ $transaction->currency }}
                    </span>
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-medium {{ $transaction->direction === 'credit' ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700' }}">
                        {{ $transaction->direction === 'credit' ? 'Einnahme' : 'Ausgabe' }}
                    </span>
                </div>
                <div class="text-[13px] text-gray-500">
                    {{ $transaction->booked_at?->format('d.m.Y') ?? '-' }}
                </div>
            </div>
        </div>

        {{-- Gegenpartei --}}
        <div class="bg-white rounded-2xl shadow-sm p-6 mb-6">
            <h3 class="text-[11px] font-medium text-gray-500 uppercase tracking-wide mb-4">Gegenpartei</h3>
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
                        <dt class="text-[11px] text-gray-400">Name</dt>
                        <dd class="text-[13px] font-medium text-gray-900 mt-0.5">{{ $cpName }}</dd>
                    </div>
                @endif
                @if($cpIban)
                    <div>
                        <dt class="text-[11px] text-gray-400">IBAN</dt>
                        <dd class="text-[13px] font-mono text-gray-700 mt-0.5">{{ $cpIban }}</dd>
                    </div>
                @endif
                @if($cpAgent)
                    <div>
                        <dt class="text-[11px] text-gray-400">BIC</dt>
                        <dd class="text-[13px] font-mono text-gray-700 mt-0.5">{{ $cpAgent }}</dd>
                    </div>
                @endif
            </div>
        </div>

        {{-- Verwendungszweck --}}
        @if($transaction->reference || $transaction->remittance_information || $transaction->remittance_information_structured || $transaction->remittance_information_unstructured)
            <div class="bg-white rounded-2xl shadow-sm p-6 mb-6">
                <h3 class="text-[11px] font-medium text-gray-500 uppercase tracking-wide mb-4">Verwendungszweck</h3>
                <div class="space-y-3">
                    @if($transaction->reference)
                        <div>
                            <dt class="text-[11px] text-gray-400">Referenz</dt>
                            <dd class="text-[13px] text-gray-700 mt-0.5">{{ $transaction->reference }}</dd>
                        </div>
                    @endif
                    @if($transaction->remittance_information)
                        <div>
                            <dt class="text-[11px] text-gray-400">Verwendungszweck</dt>
                            <dd class="text-[13px] text-gray-700 mt-0.5">{{ $transaction->remittance_information }}</dd>
                        </div>
                    @endif
                    @if($transaction->remittance_information_structured)
                        <div>
                            <dt class="text-[11px] text-gray-400">Strukturiert</dt>
                            <dd class="text-[13px] text-gray-700 mt-0.5">{{ $transaction->remittance_information_structured }}</dd>
                        </div>
                    @endif
                    @if($transaction->remittance_information_unstructured)
                        <div>
                            <dt class="text-[11px] text-gray-400">Unstrukturiert</dt>
                            <dd class="text-[13px] text-gray-700 mt-0.5">{{ $transaction->remittance_information_unstructured }}</dd>
                        </div>
                    @endif
                </div>
            </div>
        @endif

        {{-- Zusatzinformationen --}}
        @if($transaction->additional_information || $transaction->additional_information_structured || $transaction->purpose_code || $transaction->end_to_end_id || $transaction->mandate_id || $transaction->merchant_category_code || $transaction->creditor_id)
            <div class="bg-white rounded-2xl shadow-sm p-6 mb-6">
                <h3 class="text-[11px] font-medium text-gray-500 uppercase tracking-wide mb-4">Zusatzinformationen</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    @if($transaction->additional_information)
                        <div class="md:col-span-2">
                            <dt class="text-[11px] text-gray-400">Zusätzliche Informationen</dt>
                            <dd class="text-[13px] text-gray-700 mt-0.5">{{ $transaction->additional_information }}</dd>
                        </div>
                    @endif
                    @if($transaction->additional_information_structured)
                        <div class="md:col-span-2">
                            <dt class="text-[11px] text-gray-400">Strukturierte Zusatzinfo</dt>
                            <dd class="text-[13px] text-gray-700 mt-0.5">{{ $transaction->additional_information_structured }}</dd>
                        </div>
                    @endif
                    @if($transaction->purpose_code)
                        <div>
                            <dt class="text-[11px] text-gray-400">Purpose Code</dt>
                            <dd class="text-[13px] font-mono text-gray-700 mt-0.5">{{ $transaction->purpose_code }}</dd>
                        </div>
                    @endif
                    @if($transaction->end_to_end_id)
                        <div>
                            <dt class="text-[11px] text-gray-400">End-to-End ID</dt>
                            <dd class="text-[13px] font-mono text-gray-700 mt-0.5">{{ $transaction->end_to_end_id }}</dd>
                        </div>
                    @endif
                    @if($transaction->mandate_id)
                        <div>
                            <dt class="text-[11px] text-gray-400">Mandatsreferenz</dt>
                            <dd class="text-[13px] font-mono text-gray-700 mt-0.5">{{ $transaction->mandate_id }}</dd>
                        </div>
                    @endif
                    @if($transaction->merchant_category_code)
                        <div>
                            <dt class="text-[11px] text-gray-400">Merchant Category Code</dt>
                            <dd class="text-[13px] font-mono text-gray-700 mt-0.5">{{ $transaction->merchant_category_code }}</dd>
                        </div>
                    @endif
                    @if($transaction->creditor_id)
                        <div>
                            <dt class="text-[11px] text-gray-400">Gläubiger-ID</dt>
                            <dd class="text-[13px] font-mono text-gray-700 mt-0.5">{{ $transaction->creditor_id }}</dd>
                        </div>
                    @endif
                </div>
            </div>
        @endif

        {{-- Technische Details --}}
        @if($transaction->transaction_id || $transaction->internal_transaction_id || $transaction->entry_reference || $transaction->bank_transaction_code || $transaction->proprietary_bank_transaction_code)
            <div class="bg-white rounded-2xl shadow-sm p-6">
                <h3 class="text-[11px] font-medium text-gray-500 uppercase tracking-wide mb-4">Technische Details</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    @if($transaction->transaction_id)
                        <div>
                            <dt class="text-[11px] text-gray-400">Transaction ID</dt>
                            <dd class="text-[13px] font-mono text-gray-700 mt-0.5">{{ $transaction->transaction_id }}</dd>
                        </div>
                    @endif
                    @if($transaction->internal_transaction_id)
                        <div>
                            <dt class="text-[11px] text-gray-400">Internal Transaction ID</dt>
                            <dd class="text-[13px] font-mono text-gray-700 mt-0.5">{{ $transaction->internal_transaction_id }}</dd>
                        </div>
                    @endif
                    @if($transaction->entry_reference)
                        <div>
                            <dt class="text-[11px] text-gray-400">Entry Reference</dt>
                            <dd class="text-[13px] font-mono text-gray-700 mt-0.5">{{ $transaction->entry_reference }}</dd>
                        </div>
                    @endif
                    @if($transaction->bank_transaction_code)
                        <div>
                            <dt class="text-[11px] text-gray-400">Bank Transaction Code</dt>
                            <dd class="text-[13px] font-mono text-gray-700 mt-0.5">{{ $transaction->bank_transaction_code }}</dd>
                        </div>
                    @endif
                    @if($transaction->proprietary_bank_transaction_code)
                        <div>
                            <dt class="text-[11px] text-gray-400">Proprietary Code</dt>
                            <dd class="text-[13px] font-mono text-gray-700 mt-0.5">{{ $transaction->proprietary_bank_transaction_code }}</dd>
                        </div>
                    @endif
                </div>
            </div>
        @endif

    </x-ui-page-container>

    <x-slot name="activity">
        <x-ui-page-sidebar title="Details" width="w-80" side="right" :defaultOpen="true" storeKey="activityOpen">
            <div class="p-4 space-y-4">
                <div>
                    <dt class="text-[11px] text-gray-400 uppercase tracking-wide">Konto</dt>
                    <dd class="text-[13px] font-medium text-gray-900 mt-0.5">{{ $transaction->bankAccount->name ?? '-' }}</dd>
                </div>

                <div>
                    <dt class="text-[11px] text-gray-400 uppercase tracking-wide mb-1">Kategorie</dt>
                    <dd>
                        <select wire:model.live="categoryId"
                                class="w-full px-2 py-1 rounded-md border border-gray-200 text-[13px] text-gray-900 focus:outline-none focus:ring-1 focus:ring-blue-500 focus:border-blue-500">
                            <option value="">— Keine —</option>
                            @foreach ($categories as $cat)
                                <option value="{{ $cat->id }}">
                                    {{ $cat->parent_id ? '  └ ' : '' }}{{ $cat->name }}
                                </option>
                            @endforeach
                        </select>
                    </dd>
                </div>

                <div>
                    <dt class="text-[11px] text-gray-400 uppercase tracking-wide">Status</dt>
                    <dd class="text-[13px] text-gray-700 mt-0.5">{{ $transaction->status ?? '-' }}</dd>
                </div>

                <div>
                    <dt class="text-[11px] text-gray-400 uppercase tracking-wide">Währung</dt>
                    <dd class="text-[13px] text-gray-700 mt-0.5">{{ $transaction->currency ?? '-' }}</dd>
                </div>

                <hr class="border-gray-200">

                <div>
                    <button wire:click="createBudgetFromTransaction"
                            class="w-full inline-flex items-center justify-center gap-1.5 px-3 py-1.5 rounded-md border border-gray-200 text-[13px] font-medium text-gray-700 hover:bg-gray-50 transition-colors">
                        @svg('heroicon-o-calculator', 'w-4 h-4')
                        Budget erstellen
                    </button>
                </div>

                <hr class="border-gray-200">

                <div>
                    <dt class="text-[11px] text-gray-400 uppercase tracking-wide">Buchungsdatum</dt>
                    <dd class="text-[13px] text-gray-700 mt-0.5">{{ $transaction->booking_date?->format('d.m.Y') ?? $transaction->booked_at?->format('d.m.Y') ?? '-' }}</dd>
                </div>

                <div>
                    <dt class="text-[11px] text-gray-400 uppercase tracking-wide">Wertstellungsdatum</dt>
                    <dd class="text-[13px] text-gray-700 mt-0.5">{{ $transaction->value_date?->format('d.m.Y') ?? '-' }}</dd>
                </div>

                <hr class="border-gray-200">

                <div>
                    <dt class="text-[11px] text-gray-400 uppercase tracking-wide">Erstellt</dt>
                    <dd class="text-[13px] text-gray-700 mt-0.5">{{ $transaction->created_at?->format('d.m.Y H:i') ?? '-' }}</dd>
                </div>

                <div>
                    <dt class="text-[11px] text-gray-400 uppercase tracking-wide">Aktualisiert</dt>
                    <dd class="text-[13px] text-gray-700 mt-0.5">{{ $transaction->updated_at?->format('d.m.Y H:i') ?? '-' }}</dd>
                </div>
            </div>
        </x-ui-page-sidebar>
    </x-slot>

</x-ui-page>
