<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="Transaktion" icon="heroicon-o-document-text" />
    </x-slot>

    <x-slot name="sidebar">
        @include('drip::partials.inner-sidebar')
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="array_filter([
            ['label' => 'Drip', 'href' => route('drip.dashboard'), 'icon' => 'chart-bar'],
            $transaction->bankAccount?->group ? ['label' => $transaction->bankAccount->group->name, 'href' => route('drip.groups.show', $transaction->bankAccount->group)] : null,
            ['label' => Str::limit($transaction->counterparty_name ?? $transaction->transaction_id ?? $transaction->uuid, 20)],
        ])" />
    </x-slot>

    <x-ui-page-container width="contained">

        {{-- Header: Amount + Direction + Date --}}
        <x-nx-card>
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-4">
                    <span class="text-3xl font-bold tabular-nums {{ $transaction->direction === 'credit' ? 'text-green-600' : 'text-red-600' }}">
                        {{ $transaction->direction === 'credit' ? '+' : '-' }}{{ number_format(abs((float) $transaction->amount), 2, ',', '.') }} {{ $transaction->currency }}
                    </span>
                    <x-nx-badge :variant="$transaction->direction === 'credit' ? 'success' : 'danger'" dot>
                        {{ $transaction->direction === 'credit' ? 'Einnahme' : 'Ausgabe' }}
                    </x-nx-badge>
                </div>
                <div class="text-sm text-[color:var(--nx-muted)]">
                    {{ $transaction->booked_at?->format('d.m.Y') ?? '-' }}
                </div>
            </div>
        </x-nx-card>

        {{-- Übersicht (Konto, Kategorie, Status, Daten) --}}
        <x-nx-section title="Übersicht" icon="heroicon-o-information-circle">
            <x-nx-card>
                <x-nx-property-row icon="heroicon-o-building-library" label="Konto">
                    {{ $transaction->bankAccount->name ?? '-' }}
                </x-nx-property-row>
                <x-nx-property-row icon="heroicon-o-tag" label="Kategorie">
                    <x-nx-input-select
                        wire:model.live="categoryId"
                        :value="$categoryId"
                        size="sm"
                        nullable
                        nullLabel="— Keine —"
                        :options="$categories->map(fn ($cat) => ['value' => $cat->id, 'label' => ($cat->parent_id ? '  └ ' : '') . $cat->name])->all()"
                    />
                </x-nx-property-row>
                <x-nx-property-row icon="heroicon-o-check-circle" label="Status">
                    {{ $transaction->status ?? '-' }}
                </x-nx-property-row>
                <x-nx-property-row icon="heroicon-o-banknotes" label="Währung">
                    {{ $transaction->currency ?? '-' }}
                </x-nx-property-row>
                <x-nx-property-row icon="heroicon-o-calendar-days" label="Buchungsdatum">
                    {{ $transaction->booking_date?->format('d.m.Y') ?? $transaction->booked_at?->format('d.m.Y') ?? '-' }}
                </x-nx-property-row>
                <x-nx-property-row icon="heroicon-o-calendar" label="Wertstellungsdatum">
                    {{ $transaction->value_date?->format('d.m.Y') ?? '-' }}
                </x-nx-property-row>
                <x-nx-property-row icon="heroicon-o-clock" label="Erstellt">
                    {{ $transaction->created_at?->format('d.m.Y H:i') ?? '-' }}
                </x-nx-property-row>
                <x-nx-property-row icon="heroicon-o-clock" label="Aktualisiert">
                    {{ $transaction->updated_at?->format('d.m.Y H:i') ?? '-' }}
                </x-nx-property-row>
            </x-nx-card>
        </x-nx-section>

        {{-- Gegenpartei --}}
        @php
            $cpName = $transaction->counterparty_name
                ?? ($transaction->direction === 'debit' ? $transaction->creditor_name : $transaction->debtor_name);
            $cpIban = $transaction->counterparty_iban;
            $cpAgent = $transaction->direction === 'debit'
                ? $transaction->creditor_agent
                : $transaction->debtor_agent;
        @endphp
        @if($cpName || $cpIban || $cpAgent)
            <x-nx-section title="Gegenpartei" icon="heroicon-o-user">
                <x-nx-card>
                    @if($cpName)
                        <x-nx-property-row icon="heroicon-o-identification" label="Name">{{ $cpName }}</x-nx-property-row>
                    @endif
                    @if($cpIban)
                        <x-nx-property-row icon="heroicon-o-credit-card" label="IBAN"><span class="font-mono">{{ $cpIban }}</span></x-nx-property-row>
                    @endif
                    @if($cpAgent)
                        <x-nx-property-row icon="heroicon-o-building-office" label="BIC"><span class="font-mono">{{ $cpAgent }}</span></x-nx-property-row>
                    @endif
                </x-nx-card>
            </x-nx-section>
        @endif

        {{-- Verwendungszweck --}}
        @if($transaction->reference || $transaction->remittance_information || $transaction->remittance_information_structured || $transaction->remittance_information_unstructured)
            <x-nx-section title="Verwendungszweck" icon="heroicon-o-chat-bubble-bottom-center-text">
                <x-nx-card>
                    @if($transaction->reference)
                        <x-nx-property-row label="Referenz">{{ $transaction->reference }}</x-nx-property-row>
                    @endif
                    @if($transaction->remittance_information)
                        <x-nx-property-row label="Verwendungszweck">{{ $transaction->remittance_information }}</x-nx-property-row>
                    @endif
                    @if($transaction->remittance_information_structured)
                        <x-nx-property-row label="Strukturiert">{{ $transaction->remittance_information_structured }}</x-nx-property-row>
                    @endif
                    @if($transaction->remittance_information_unstructured)
                        <x-nx-property-row label="Unstrukturiert">{{ $transaction->remittance_information_unstructured }}</x-nx-property-row>
                    @endif
                </x-nx-card>
            </x-nx-section>
        @endif

        {{-- Zusatzinformationen --}}
        @if($transaction->additional_information || $transaction->additional_information_structured || $transaction->purpose_code || $transaction->end_to_end_id || $transaction->mandate_id || $transaction->merchant_category_code || $transaction->creditor_id)
            <x-nx-section title="Zusatzinformationen" icon="heroicon-o-document-magnifying-glass">
                <x-nx-card>
                    @if($transaction->additional_information)
                        <x-nx-property-row label="Zusätzliche Informationen">{{ $transaction->additional_information }}</x-nx-property-row>
                    @endif
                    @if($transaction->additional_information_structured)
                        <x-nx-property-row label="Strukturierte Zusatzinfo">{{ $transaction->additional_information_structured }}</x-nx-property-row>
                    @endif
                    @if($transaction->purpose_code)
                        <x-nx-property-row label="Purpose Code"><span class="font-mono">{{ $transaction->purpose_code }}</span></x-nx-property-row>
                    @endif
                    @if($transaction->end_to_end_id)
                        <x-nx-property-row label="End-to-End ID"><span class="font-mono">{{ $transaction->end_to_end_id }}</span></x-nx-property-row>
                    @endif
                    @if($transaction->mandate_id)
                        <x-nx-property-row label="Mandatsreferenz"><span class="font-mono">{{ $transaction->mandate_id }}</span></x-nx-property-row>
                    @endif
                    @if($transaction->merchant_category_code)
                        <x-nx-property-row label="Merchant Category Code"><span class="font-mono">{{ $transaction->merchant_category_code }}</span></x-nx-property-row>
                    @endif
                    @if($transaction->creditor_id)
                        <x-nx-property-row label="Gläubiger-ID"><span class="font-mono">{{ $transaction->creditor_id }}</span></x-nx-property-row>
                    @endif
                </x-nx-card>
            </x-nx-section>
        @endif

        {{-- Technische Details --}}
        @if($transaction->transaction_id || $transaction->internal_transaction_id || $transaction->entry_reference || $transaction->bank_transaction_code || $transaction->proprietary_bank_transaction_code)
            <x-nx-section title="Technische Details" icon="heroicon-o-cog-6-tooth">
                <x-nx-card>
                    @if($transaction->transaction_id)
                        <x-nx-property-row label="Transaction ID"><span class="font-mono">{{ $transaction->transaction_id }}</span></x-nx-property-row>
                    @endif
                    @if($transaction->internal_transaction_id)
                        <x-nx-property-row label="Internal Transaction ID"><span class="font-mono">{{ $transaction->internal_transaction_id }}</span></x-nx-property-row>
                    @endif
                    @if($transaction->entry_reference)
                        <x-nx-property-row label="Entry Reference"><span class="font-mono">{{ $transaction->entry_reference }}</span></x-nx-property-row>
                    @endif
                    @if($transaction->bank_transaction_code)
                        <x-nx-property-row label="Bank Transaction Code"><span class="font-mono">{{ $transaction->bank_transaction_code }}</span></x-nx-property-row>
                    @endif
                    @if($transaction->proprietary_bank_transaction_code)
                        <x-nx-property-row label="Proprietary Code"><span class="font-mono">{{ $transaction->proprietary_bank_transaction_code }}</span></x-nx-property-row>
                    @endif
                </x-nx-card>
            </x-nx-section>
        @endif

    </x-ui-page-container>
</x-ui-page>
