<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="{{ $group->name }}" icon="heroicon-o-banknotes" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Drip', 'href' => route('drip.dashboard'), 'icon' => 'chart-bar'],
            ['label' => $group->name],
            ['label' => 'Transaktionen'],
        ]">
            <x-slot name="left">
                <span class="text-sm text-[color:var(--nx-muted)]">{{ $totalCount }} Transaktionen</span>
            </x-slot>
        </x-ui-page-actionbar>
    </x-slot>

    <x-slot name="sidebar">
        @include('drip::partials.inner-sidebar')
    </x-slot>

    <x-ui-page-container width="contained">

        {{-- Summary --}}
        <x-nx-stat-grid cols="3">
            <x-nx-card>
                <div class="text-xs font-medium uppercase tracking-wide text-[color:var(--nx-muted)]">Einnahmen</div>
                <div class="mt-2 text-xl font-semibold tabular-nums text-green-600">
                    +{{ number_format($totalIncome, 2, ',', '.') }} &euro;
                </div>
            </x-nx-card>
            <x-nx-card>
                <div class="text-xs font-medium uppercase tracking-wide text-[color:var(--nx-muted)]">Ausgaben</div>
                <div class="mt-2 text-xl font-semibold tabular-nums text-red-600">
                    -{{ number_format($totalExpenses, 2, ',', '.') }} &euro;
                </div>
            </x-nx-card>
            <x-nx-card>
                <div class="text-xs font-medium uppercase tracking-wide text-[color:var(--nx-muted)]">Saldo</div>
                <div class="mt-2 text-xl font-semibold tabular-nums {{ $totalBalance >= 0 ? 'text-green-600' : 'text-red-600' }}">
                    {{ $totalBalance >= 0 ? '+' : '' }}{{ number_format($totalBalance, 2, ',', '.') }} &euro;
                </div>
            </x-nx-card>
        </x-nx-stat-grid>

        {{-- Transactions Table --}}
        @php
            $rowCatOptions = collect($categories)->map(fn ($cat) => [
                'value' => $cat->id,
                'label' => ($cat->parent_id ? '└ ' : '') . $cat->name,
            ])->all();
        @endphp
        <x-nx-card flush>
            @if ($transactions->count() > 0)
                <x-nx-table>
                    <x-nx-table-header>
                        <x-nx-table-header-cell sortable sortField="booked_at" :currentSort="$sortBy" :sortDirection="$sortDirection">Datum</x-nx-table-header-cell>
                        <x-nx-table-header-cell>Richtung</x-nx-table-header-cell>
                        <x-nx-table-header-cell sortable sortField="amount" :currentSort="$sortBy" :sortDirection="$sortDirection">Betrag</x-nx-table-header-cell>
                        <x-nx-table-header-cell>Gegenpartei</x-nx-table-header-cell>
                        <x-nx-table-header-cell>Verwendungszweck</x-nx-table-header-cell>
                        <x-nx-table-header-cell>Kategorie</x-nx-table-header-cell>
                        <x-nx-table-header-cell>Konto</x-nx-table-header-cell>
                    </x-nx-table-header>
                    <x-nx-table-body>
                        @foreach ($transactions as $transaction)
                            <x-nx-table-row :clickable="true" :href="route('drip.transactions.show', $transaction)">
                                <x-nx-table-cell>
                                    <span class="whitespace-nowrap text-[color:var(--nx-text)]">{{ $transaction->booked_at?->format('d.m.Y') ?? '-' }}</span>
                                </x-nx-table-cell>
                                <x-nx-table-cell>
                                    <x-nx-badge :variant="$transaction->direction === 'credit' ? 'success' : 'danger'">
                                        {{ $transaction->direction === 'credit' ? 'Einnahme' : 'Ausgabe' }}
                                    </x-nx-badge>
                                </x-nx-table-cell>
                                <x-nx-table-cell>
                                    <span class="font-medium tabular-nums {{ $transaction->direction === 'credit' ? 'text-green-600' : 'text-red-600' }}">
                                        {{ $transaction->direction === 'credit' ? '+' : '-' }}{{ number_format($transaction->amount, 2, ',', '.') }} {{ $transaction->currency }}
                                    </span>
                                </x-nx-table-cell>
                                <x-nx-table-cell>
                                    <div class="font-medium text-[color:var(--nx-text)]">
                                        {{ $transaction->counterparty_name ?? ($transaction->direction === 'debit' ? $transaction->creditor_name : $transaction->debtor_name) ?? '-' }}
                                    </div>
                                    @php
                                        $displayIban = $transaction->counterparty_iban ?? ($transaction->direction === 'debit' ? $transaction->creditor_account_iban : $transaction->debtor_account_iban);
                                    @endphp
                                    @if($displayIban)
                                        <div class="mt-0.5 font-mono text-[11px] text-[color:var(--nx-faint)]">
                                            {{ Str::limit($displayIban, 22) }}
                                        </div>
                                    @endif
                                </x-nx-table-cell>
                                <x-nx-table-cell>
                                    <div class="max-w-xs truncate text-[color:var(--nx-muted)]">
                                        {{ $transaction->remittance_information ?? $transaction->reference ?? '-' }}
                                    </div>
                                </x-nx-table-cell>
                                <x-nx-table-cell onclick="event.stopPropagation()">
                                    <x-nx-input-select
                                        size="sm"
                                        :options="$rowCatOptions"
                                        nullable
                                        nullLabel="—"
                                        :value="$transaction->category_id"
                                        wire:change="updateCategory({{ $transaction->id }}, $event.target.value)" />
                                </x-nx-table-cell>
                                <x-nx-table-cell>
                                    <span class="text-[color:var(--nx-muted)]">{{ $transaction->bankAccount->name }}</span>
                                </x-nx-table-cell>
                            </x-nx-table-row>
                        @endforeach
                    </x-nx-table-body>
                </x-nx-table>

                @if ($hasMore)
                    <div class="border-t border-[color:var(--nx-line)] p-4 text-center">
                        <x-nx-button variant="secondary" wire:click="loadMore">
                            @svg('heroicon-o-arrow-down', 'w-4 h-4')
                            Weitere laden
                            <span class="text-[color:var(--nx-faint)]">({{ $transactions->count() }} von {{ $totalCount }})</span>
                        </x-nx-button>
                    </div>
                @endif
            @else
                <x-nx-empty icon="heroicon-o-banknotes">
                    @if ($search)
                        Keine Transaktionen gefunden f&uuml;r &ldquo;{{ $search }}&rdquo;
                    @else
                        Diese Gruppe hat noch keine Transaktionen.
                    @endif
                </x-nx-empty>
            @endif
        </x-nx-card>

    </x-ui-page-container>

    <x-slot name="activity">
        <x-ui-page-sidebar title="Filter" icon="heroicon-o-funnel" width="w-80" side="right" :defaultOpen="true" storeKey="activityOpen">
            @php
                $filterCatOptions = collect($categories)->map(fn ($cat) => [
                    'value' => $cat->id,
                    'label' => ($cat->parent_id ? '└ ' : '') . $cat->name,
                ])->prepend(['value' => 'none', 'label' => 'Ohne Kategorie'])->all();
            @endphp
            <div class="space-y-4 p-4">
                <x-nx-input-text
                    label="Suche"
                    placeholder="Transaktionen durchsuchen..."
                    wire:model.live.debounce.300ms="search" />

                <x-nx-input-select
                    label="Kategorie"
                    :options="$filterCatOptions"
                    nullable
                    nullLabel="Alle"
                    wire:model.live="categoryFilter" />

                <x-nx-input-select
                    label="Richtung"
                    :options="[['value' => 'credit', 'label' => 'Einnahmen'], ['value' => 'debit', 'label' => 'Ausgaben']]"
                    nullable
                    nullLabel="Alle"
                    wire:model.live="direction" />
            </div>
        </x-ui-page-sidebar>
    </x-slot>

</x-ui-page>
