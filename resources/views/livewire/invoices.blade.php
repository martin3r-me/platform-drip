<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="Belege" icon="heroicon-o-receipt-percent" />
    </x-slot>

    <x-slot name="sidebar">
        @include('drip::partials.inner-sidebar')
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Drip', 'href' => route('drip.dashboard'), 'icon' => 'chart-bar'],
            ['label' => 'Belege', 'href' => route('drip.invoices'), 'icon' => 'receipt-percent'],
        ]" />
    </x-slot>

    <x-ui-page-container width="contained">

        {{-- Kopf: Sync + Filter --}}
        <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-1">
                @foreach(['all' => 'Alle', 'open' => 'Offen', 'matched' => 'Bezahlt'] as $key => $label)
                    <x-nx-button
                        :variant="$filter === $key ? 'secondary' : 'ghost'"
                        size="sm"
                        wire:click="$set('filter', '{{ $key }}')">{{ $label }}</x-nx-button>
                @endforeach
            </div>
            <div class="flex items-center gap-2">
                @if($syncResult)
                    <span class="text-[11px] text-[color:var(--nx-faint)]">{{ $syncResult }}</span>
                @endif
                <x-nx-button variant="ghost" size="sm" wire:click="sync" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="sync">@svg('heroicon-o-arrow-path', 'w-4 h-4') Aus easybill abgleichen</span>
                    <span wire:loading wire:target="sync">Läuft…</span>
                </x-nx-button>
            </div>
        </div>

        {{-- KPIs --}}
        <div class="mb-8 grid grid-cols-1 gap-4 sm:grid-cols-3">
            <x-nx-stat label="Gestellt (Summe)" icon="heroicon-o-document-text"
                       value="{{ number_format($total, 2, ',', '.') }} €" />
            <x-nx-stat label="Bezahlt (abgeglichen)" icon="heroicon-o-check-circle" accent="var(--nx-success)"
                       value="{{ $matchedCount }} / {{ $invoiceCount }}" />
            <x-nx-stat label="Offen (Summe)" icon="heroicon-o-clock" accent="var(--nx-danger)"
                       value="{{ number_format($openSum, 2, ',', '.') }} €" />
        </div>

        @if($invoiceCount === 0)
            <x-nx-card>
                <div class="py-10 text-center">
                    <p class="text-sm text-[color:var(--nx-muted)]">Noch keine Belege gespiegelt.</p>
                    <p class="mt-1 text-[11px] text-[color:var(--nx-faint)]">
                        „Aus easybill abgleichen" holt die Ausgangsrechnungen und matcht sie gegen die Bank-Eingänge.
                    </p>
                </div>
            </x-nx-card>
        @endif

        {{-- Belege nach Monaten --}}
        <div class="flex flex-col gap-6">
            @foreach($groups as $group)
                <x-nx-card class="overflow-hidden">
                    <div class="mb-3 flex items-baseline justify-between gap-2">
                        <h2 class="text-sm font-semibold text-[color:var(--nx-text)]">{{ $group['label'] }}</h2>
                        <span class="text-[11px] text-[color:var(--nx-faint)]">
                            {{ number_format($group['total'], 2, ',', '.') }} € gestellt ·
                            <span class="text-[color:var(--nx-success)]">{{ number_format($group['matched'], 2, ',', '.') }} € bezahlt</span> ·
                            {{ number_format($group['open'], 2, ',', '.') }} € offen
                        </span>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-left text-[11px] uppercase tracking-wide text-[color:var(--nx-faint)]">
                                    <th class="py-1 pr-3 font-medium">Nr</th>
                                    <th class="py-1 pr-3 font-medium">Datum</th>
                                    <th class="py-1 pr-3 font-medium">Kunde</th>
                                    <th class="py-1 pr-3 text-right font-medium">Brutto</th>
                                    <th class="py-1 pr-3 font-medium">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($group['invoices'] as $inv)
                                    <tr class="border-t border-[color:var(--nx-line)]">
                                        <td class="py-2 pr-3 font-mono text-[12px]">{{ $inv->number ?? '—' }}</td>
                                        <td class="py-2 pr-3 whitespace-nowrap text-[color:var(--nx-muted)]">
                                            {{ $inv->document_date?->format('d.m.Y') ?? '—' }}
                                        </td>
                                        <td class="py-2 pr-3">{{ $inv->customer_name ?? '—' }}</td>
                                        <td class="py-2 pr-3 text-right font-medium whitespace-nowrap">
                                            {{ number_format($inv->amount_gross, 2, ',', '.') }} €
                                        </td>
                                        <td class="py-2 pr-3">
                                            @if($inv->isMatched())
                                                <a href="{{ $inv->matched_transaction_id ? route('drip.transactions.show', $inv->matched_transaction_id) : '#' }}"
                                                   wire:navigate class="inline-flex items-center gap-1">
                                                    <x-nx-badge variant="success" dot>bezahlt</x-nx-badge>
                                                    <span class="text-[10px] text-[color:var(--nx-faint)]">
                                                        {{ match($inv->match_confidence) {
                                                            'number' => 'Nr',
                                                            'amount_party' => 'Betrag+Partei',
                                                            'amount' => 'Betrag?',
                                                            default => '',
                                                        } }}
                                                    </span>
                                                </a>
                                            @else
                                                <x-nx-badge variant="warning">offen</x-nx-badge>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </x-nx-card>
            @endforeach
        </div>

    </x-ui-page-container>
</x-ui-page>
