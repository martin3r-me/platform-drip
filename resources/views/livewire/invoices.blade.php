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
                @foreach(['open' => 'Ohne Zahlung', 'overdue' => 'Überfällig', 'matched' => 'Bezahlt', 'all' => 'Alle'] as $key => $label)
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
                {{-- Primäraktion der Seite: eigene Fläche statt ghost, damit sie als
                     Button lesbar ist. Die Spans müssen inline-flex sein — das SVG ist
                     block-level und würde den Text sonst in die zweite Zeile drücken. --}}
                <x-nx-button variant="primary" size="md" wire:click="sync" wire:loading.attr="disabled">
                    <span class="inline-flex items-center gap-1.5" wire:loading.remove wire:target="sync">
                        @svg('heroicon-o-arrow-path', 'w-4 h-4 shrink-0') Abgleichen
                    </span>
                    <span class="inline-flex items-center gap-1.5" wire:loading wire:target="sync">
                        @svg('heroicon-o-arrow-path', 'w-4 h-4 shrink-0 animate-spin') Läuft…
                    </span>
                </x-nx-button>
            </div>
        </div>

        {{-- KPIs: die offene Worklist (immer über den Gesamtbestand, nicht über den Filter) --}}
        <div class="mb-8 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <x-nx-stat label="Ohne Zahlungseingang" icon="heroicon-o-clock" accent="var(--nx-warning)"
                       value="{{ $openCount }}" />
            <x-nx-stat label="Offener Betrag" icon="heroicon-o-banknotes" accent="var(--nx-danger)"
                       value="{{ number_format($openSum, 2, ',', '.') }} €" />
            <x-nx-stat label="Davon überfällig" icon="heroicon-o-exclamation-triangle" accent="var(--nx-danger)"
                       value="{{ $overdueCount }} · {{ number_format($overdueSum, 2, ',', '.') }} €" />
            <x-nx-stat label="Eingänge ohne Beleg" icon="heroicon-o-question-mark-circle" accent="var(--nx-warning)"
                       value="{{ $creditsAwaiting->count() }} · {{ number_format($creditsAwaitingSum, 2, ',', '.') }} €" />
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
                                    <th class="py-1 pr-3 font-medium">Fällig</th>
                                    <th class="py-1 pr-3 font-medium">Partei</th>
                                    <th class="py-1 pr-3 text-right font-medium">Brutto</th>
                                    <th class="py-1 pr-3 text-right font-medium">Offen</th>
                                    <th class="py-1 pr-3 font-medium">Zahlung</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($section['invoices'] as $inv)
                                    @php($overdue = $inv->due_date && $inv->due_date->isPast() && $inv->openCents() > 0)
                                    <tr class="border-t border-[color:var(--nx-line)]">
                                        <td class="py-2 pr-3 font-mono text-[12px]">{{ $inv->number ?? '—' }}</td>
                                        <td class="py-2 pr-3 whitespace-nowrap text-[color:var(--nx-muted)]">
                                            {{ $inv->document_date?->format('d.m.Y') ?? '—' }}
                                        </td>
                                        <td class="py-2 pr-3 whitespace-nowrap {{ $overdue ? 'text-[color:var(--nx-danger)] font-medium' : 'text-[color:var(--nx-muted)]' }}">
                                            {{ $inv->due_date?->format('d.m.Y') ?? '—' }}
                                            @if($overdue)
                                                <span class="text-[11px]">({{ $inv->due_date->diffInDays(now()) }} T)</span>
                                            @endif
                                        </td>
                                        <td class="py-2 pr-3">{{ $inv->customer_name ?? '—' }}</td>
                                        <td class="py-2 pr-3 text-right font-medium whitespace-nowrap">
                                            {{ number_format($inv->amount_gross, 2, ',', '.') }} €
                                        </td>
                                        <td class="py-2 pr-3 text-right whitespace-nowrap {{ $inv->openCents() > 0 ? 'text-[color:var(--nx-danger)]' : 'text-[color:var(--nx-faint)]' }}">
                                            {{ number_format($inv->open_amount, 2, ',', '.') }} €
                                        </td>
                                        <td class="py-2 pr-3">
                                            @forelse($inv->transactions as $tx)
                                                <a href="{{ route('drip.transactions.show', $tx->id) }}"
                                                   wire:navigate class="mr-1 inline-flex items-center gap-1"
                                                   title="{{ $tx->booked_at?->format('d.m.Y') }} · {{ number_format($tx->pivot->amount_applied_cents / 100, 2, ',', '.') }} € · {{ $tx->pivot->match_type }}">
                                                    <x-nx-badge :variant="$tx->pivot->confidence === 'high' ? 'success' : 'warning'" dot>
                                                        {{ $tx->booked_at?->format('d.m.') }}
                                                        @if($inv->transactions->count() > 1 || $inv->openCents() > 0)
                                                            · {{ number_format($tx->pivot->amount_applied_cents / 100, 2, ',', '.') }} €
                                                        @endif
                                                    </x-nx-badge>
                                                </a>
                                            @empty
                                                <x-nx-badge variant="warning">offen</x-nx-badge>
                                            @endforelse
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </x-nx-card>
            @endforeach

            {{-- Gegenrichtung: Geld da, Beleg fehlt. Nicht jeder Eingang HAT eine
                 Rechnung (Finanzamt, BAFA, Ausleihungen) — die lassen sich hier
                 als belegfrei abhaken, damit sie die echten Lücken nicht zudecken. --}}
            @if($creditsAwaiting->isNotEmpty())
                <x-nx-card class="overflow-hidden">
                    <div class="mb-3 flex items-baseline justify-between gap-2">
                        <h2 class="text-sm font-semibold text-[color:var(--nx-text)]">
                            Eingänge ohne Beleg
                            <span class="ml-1 text-[11px] font-normal text-[color:var(--nx-faint)]">({{ $creditsAwaiting->count() }})</span>
                        </h2>
                        <span class="text-[11px] text-[color:var(--nx-faint)]">
                            {{ number_format($creditsAwaitingSum, 2, ',', '.') }} € nicht zugeordnet
                        </span>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-left text-[11px] uppercase tracking-wide text-[color:var(--nx-faint)]">
                                    <th class="py-1 pr-3 font-medium">Datum</th>
                                    <th class="py-1 pr-3 font-medium">Zahler</th>
                                    <th class="py-1 pr-3 font-medium">Verwendungszweck</th>
                                    <th class="py-1 pr-3 text-right font-medium">Betrag</th>
                                    <th class="py-1 pr-3 text-right font-medium">Offen</th>
                                    <th class="py-1 pr-3 font-medium"></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($creditsAwaiting as $tx)
                                    <tr class="border-t border-[color:var(--nx-line)]">
                                        <td class="py-2 pr-3 whitespace-nowrap text-[color:var(--nx-muted)]">
                                            {{ $tx->booked_at?->format('d.m.Y') ?? '—' }}
                                        </td>
                                        <td class="py-2 pr-3">{{ $tx->counterparty_name ?? '—' }}</td>
                                        <td class="py-2 pr-3 max-w-[22rem] truncate text-[12px] text-[color:var(--nx-muted)]"
                                            title="{{ $tx->reference }}">{{ $tx->reference ?? '—' }}</td>
                                        <td class="py-2 pr-3 text-right font-medium whitespace-nowrap">
                                            {{ number_format(abs((float) $tx->amount), 2, ',', '.') }} €
                                        </td>
                                        <td class="py-2 pr-3 text-right whitespace-nowrap text-[color:var(--nx-danger)]">
                                            {{ number_format($tx->unallocatedCents() / 100, 2, ',', '.') }} €
                                        </td>
                                        <td class="py-2 pr-3 whitespace-nowrap">
                                            <div class="flex items-center gap-2">
                                                @if($tx->invoice_status === \Platform\Drip\Models\BankTransaction::INVOICE_STATUS_PARTIAL)
                                                    <x-nx-badge variant="warning">teilweise</x-nx-badge>
                                                @endif
                                                <x-nx-button variant="secondary" size="sm"
                                                             wire:click="toggleNoInvoice({{ $tx->id }})"
                                                             title="Kein Beleg zu erwarten (Finanzamt, Zuschuss, Ausleihung …)">
                                                    <span class="inline-flex items-center gap-1.5">
                                                        @svg('heroicon-o-check', 'w-4 h-4 shrink-0') belegfrei
                                                    </span>
                                                </x-nx-button>
                                                <a href="{{ route('drip.transactions.show', $tx->id) }}" wire:navigate
                                                   class="text-[11px] text-[color:var(--nx-faint)] underline">öffnen</a>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </x-nx-card>
            @endif
        </div>

    </x-ui-page-container>
</x-ui-page>
