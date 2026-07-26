<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="Posteingang" icon="heroicon-o-inbox" />
    </x-slot>

    <x-slot name="sidebar">
        @include('drip::partials.inner-sidebar')
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Drip', 'href' => route('drip.dashboard'), 'icon' => 'chart-bar'],
            ['label' => 'Posteingang', 'href' => route('drip.inbox'), 'icon' => 'inbox'],
        ]" />
    </x-slot>

    <x-ui-page-container width="contained">

        {{-- Umschalter: Posteingang / Geparkt --}}
        <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-1">
                <x-nx-button :variant="!$showSkipped ? 'secondary' : 'ghost'" size="sm" wire:click="$set('showSkipped', false)">
                    Posteingang <span class="ml-1 text-[11px] text-[color:var(--nx-faint)]">{{ $inboxCount }}</span>
                </x-nx-button>
                <x-nx-button :variant="$showSkipped ? 'secondary' : 'ghost'" size="sm" wire:click="$set('showSkipped', true)">
                    Bewusst offen <span class="ml-1 text-[11px] text-[color:var(--nx-faint)]">{{ $skippedCount }}</span>
                </x-nx-button>
            </div>
            @if($result)
                <span class="text-[11px] text-[color:var(--nx-faint)]">{{ $result }}</span>
            @endif
        </div>

        {{-- Mitlernen-Feedback --}}
        @if($learnResult)
            <div class="mb-4">
                <x-nx-callout variant="success" icon="heroicon-o-check-circle">{{ $learnResult }}</x-nx-callout>
            </div>
        @endif
        @if($learnSuggestion)
            <div class="mb-4">
                <x-nx-callout variant="info" icon="heroicon-o-sparkles" title="Gleiche Gegenpartei zuordnen?">
                    <span class="font-medium">{{ $learnSuggestion['count'] }}</span> weitere unkategorisierte Transaktion(en) von
                    <span class="font-medium">„{{ \Illuminate\Support\Str::limit($learnSuggestion['counterparty'], 40) }}"</span>
                    (gleiche Richtung) könnten ebenfalls <span class="font-medium">{{ $learnSuggestion['category_name'] }}</span> sein.
                    <x-slot name="action">
                        <div class="flex items-center gap-2">
                            <x-nx-button variant="primary" size="sm" wire:click="applyLearnToAll">Alle zuordnen</x-nx-button>
                            <x-nx-button variant="secondary" size="sm" wire:click="applyLearnAndRemember">+ Regel merken</x-nx-button>
                            <x-nx-button variant="ghost" size="sm" wire:click="dismissLearn">Verwerfen</x-nx-button>
                        </div>
                    </x-slot>
                </x-nx-callout>
            </div>
        @endif

        @if($transactions->isEmpty())
            <x-nx-card>
                <div class="py-10 text-center">
                    <p class="text-sm text-[color:var(--nx-muted)]">
                        @if($showSkipped)
                            Keine bewusst geparkten Transaktionen.
                        @else
                            Posteingang leer — alles gesichtet. 🎉
                        @endif
                    </p>
                </div>
            </x-nx-card>
        @else
            <x-nx-card class="overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-[11px] uppercase tracking-wide text-[color:var(--nx-faint)]">
                                <th class="py-1 pr-3 font-medium">Datum</th>
                                <th class="py-1 pr-3 font-medium">Gegenpartei</th>
                                <th class="py-1 pr-3 text-right font-medium">Betrag</th>
                                <th class="py-1 pr-3 font-medium">Konto</th>
                                <th class="py-1 pr-3 font-medium">{{ $showSkipped ? 'Aktion' : 'Kategorie / Aktion' }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($transactions as $t)
                                <tr class="border-t border-[color:var(--nx-line)]" wire:key="inbox-{{ $t->id }}">
                                    <td class="py-2 pr-3 whitespace-nowrap text-[color:var(--nx-muted)]">
                                        {{ ($t->booked_at ?? $t->created_at)?->format('d.m.Y') }}
                                    </td>
                                    <td class="py-2 pr-3">
                                        <a href="{{ route('drip.transactions.show', $t->id) }}" wire:navigate class="hover:underline">
                                            {{ $t->counterparty_name ?? '—' }}
                                        </a>
                                    </td>
                                    <td class="py-2 pr-3 text-right font-medium whitespace-nowrap {{ $t->direction === 'credit' ? 'text-[color:var(--nx-success)]' : '' }}">
                                        {{ $t->direction === 'credit' ? '+' : '−' }}{{ number_format(abs((float) $t->amount), 2, ',', '.') }} €
                                    </td>
                                    <td class="py-2 pr-3 text-[color:var(--nx-faint)] text-[12px]">{{ $t->bankAccount?->name ?? '—' }}</td>
                                    <td class="py-2 pr-3">
                                        @if($showSkipped)
                                            <x-nx-button variant="ghost" size="sm" wire:click="unskip({{ $t->id }})">
                                                @svg('heroicon-o-arrow-uturn-left', 'w-4 h-4') In Posteingang
                                            </x-nx-button>
                                        @else
                                            <div class="flex items-center gap-2">
                                                <div class="min-w-[14rem]">
                                                    <x-nx-input-select size="sm" :options="$categoryOptions" nullable nullLabel="Kategorie wählen…"
                                                        :value="$t->category_id" wire:change="assign({{ $t->id }}, $event.target.value)" />
                                                </div>
                                                <x-nx-button variant="ghost" size="sm" wire:click="skip({{ $t->id }})" title="Bewusst ohne Kategorie">
                                                    @svg('heroicon-o-minus-circle', 'w-4 h-4') offen lassen
                                                </x-nx-button>
                                            </div>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if($transactions->count() >= $perPage)
                    <div class="mt-4 text-center">
                        <x-nx-button variant="ghost" size="sm" wire:click="loadMore">Mehr laden</x-nx-button>
                    </div>
                @endif
            </x-nx-card>
        @endif

    </x-ui-page-container>
</x-ui-page>
