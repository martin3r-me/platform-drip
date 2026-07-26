<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="Offene Belege" icon="heroicon-o-receipt-percent" />
    </x-slot>

    <x-slot name="sidebar">
        @include('drip::partials.inner-sidebar')
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Drip', 'href' => route('drip.dashboard'), 'icon' => 'chart-bar'],
            ['label' => 'Offene Belege', 'href' => route('drip.invoices'), 'icon' => 'receipt-percent'],
        ]" />
    </x-slot>

    <x-ui-page-container width="contained">

        {{-- Kopf: Filter + Sync --}}
        <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-1">
                @foreach(['open' => 'Ohne TX', 'matched' => 'Zugeordnet', 'all' => 'Alle'] as $key => $label)
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
                    <span wire:loading.remove wire:target="sync">@svg('heroicon-o-arrow-path', 'w-4 h-4') Abgleichen</span>
                    <span wire:loading wire:target="sync">Läuft…</span>
                </x-nx-button>
            </div>
        </div>

        {{-- KPIs: die offene Worklist --}}
        <div class="mb-8 grid grid-cols-1 gap-4 sm:grid-cols-2">
            <x-nx-stat label="Belege ohne TX" icon="heroicon-o-clock" accent="var(--nx-warning)"
                       value="{{ $openCount }}" />
            <x-nx-stat label="Offener Betrag" icon="heroicon-o-banknotes" accent="var(--nx-danger)"
                       value="{{ number_format($openSum, 2, ',', '.') }} €" />
        </div>

        @if($sections->isEmpty())
            <x-nx-card>
                <div class="py-10 text-center">
                    <p class="text-sm text-[color:var(--nx-muted)]">
                        @if($filter === 'open')
                            Keine offenen Belege — alles hat eine Transaktion. 🎉
                        @else
                            Keine Belege in dieser Ansicht.
                        @endif
                    </p>
                    <p class="mt-1 text-[11px] text-[color:var(--nx-faint)]">
                        „Abgleichen" holt die Belege aus easybill und ordnet sie den Bank-Transaktionen zu.
                    </p>
                </div>
            </x-nx-card>
        @endif

        {{-- Sektionen nach Richtung: Ausgang (Forderungen) / Eingang (Verbindlichkeiten) --}}
        <div class="flex flex-col gap-6">
            @foreach($sections as $section)
                <x-nx-card class="overflow-hidden">
                    <div class="mb-3 flex items-baseline justify-between gap-2">
                        <h2 class="text-sm font-semibold text-[color:var(--nx-text)]">
                            {{ $section['label'] }}
                            <span class="ml-1 text-[11px] font-normal text-[color:var(--nx-faint)]">({{ $section['invoices']->count() }})</span>
                        </h2>
                        <span class="text-[11px] text-[color:var(--nx-faint)]">
                            {{ number_format($section['sum'], 2, ',', '.') }} €
                        </span>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-left text-[11px] uppercase tracking-wide text-[color:var(--nx-faint)]">
                                    <th class="py-1 pr-3 font-medium">Nr</th>
                                    <th class="py-1 pr-3 font-medium">Datum</th>
                                    <th class="py-1 pr-3 font-medium">Partei</th>
                                    <th class="py-1 pr-3 text-right font-medium">Brutto</th>
                                    <th class="py-1 pr-3 font-medium">TX</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($section['invoices'] as $inv)
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
                                                    <x-nx-badge variant="success" dot>zugeordnet</x-nx-badge>
                                                </a>
                                            @else
                                                <x-nx-badge variant="warning">ohne TX</x-nx-badge>
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
