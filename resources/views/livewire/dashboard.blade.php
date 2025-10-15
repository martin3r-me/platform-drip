<x-ui-page-container>
    <x-slot name="navbar">
        <x-ui-page-navbar title="Drip Dashboard" subtitle="Überblick über Bankdaten und Gruppen">
        </x-ui-page-navbar>
    </x-slot>

    <x-ui-detail-stats-grid class="mb-8">
        <x-ui-detail-stat label="Kontogruppen" value="{{ $groupsCount ?? 0 }}" icon="heroicon-o-folder" variant="primary" />
        <x-ui-detail-stat label="Konten" value="{{ $accountsCount ?? 0 }}" icon="heroicon-o-credit-card" variant="secondary" />
        <x-ui-detail-stat label="Transaktionen (30T)" value="{{ $transactions30d ?? 0 }}" icon="heroicon-o-banknotes" variant="success" />
        <x-ui-detail-stat label="Letzter Sync" value="{{ $lastSyncAt ?? '—' }}" icon="heroicon-o-arrow-path" variant="warning" />
    </x-ui-detail-stats-grid>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <x-ui-panel title="Kontogruppen" subtitle="Schnellzugriff auf Gruppen">
            <div class="space-y-2">
                @forelse(($groups ?? []) as $g)
                    <div class="flex items-center justify-between py-2 px-3 rounded-lg bg-[var(--ui-muted-5)] border border-[var(--ui-border)]/40">
                        <div class="flex items-center gap-2">
                            <div class="w-3 h-3 rounded-full" style="background-color: {{ $g->color ?? '#6B7280' }}"></div>
                            <span class="text-sm text-[var(--ui-secondary)]">{{ $g->name }}</span>
                        </div>
                        <x-ui-button :href="route('drip.groups.show', $g)" wire:navigate size="xs" variant="secondary-outline">
                            @svg('heroicon-o-banknotes', 'w-4 h-4')
                            <span class="ml-1">Transaktionen</span>
                        </x-ui-button>
                    </div>
                @empty
                    <div class="text-sm text-[var(--ui-muted)]">Keine Gruppen</div>
                @endforelse
            </div>
        </x-ui-panel>

        <x-ui-panel title="Letzte Transaktionen" subtitle="Zuletzt gebuchte Bewegungen">
            <div class="space-y-3">
                @forelse(($recentTransactions ?? []) as $t)
                    <div class="flex items-center justify-between py-2 px-3 rounded-lg border border-[var(--ui-border)]/40">
                        <div class="text-sm text-[var(--ui-secondary)] truncate max-w-[60%]">
                            {{ $t->remittance_information ?? $t->reference ?? '-' }}
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium {{ $t->direction === 'credit' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' }}">
                                {{ $t->direction === 'credit' ? '+' : '-' }}
                            </span>
                            <span class="text-sm font-medium {{ $t->direction === 'credit' ? 'text-green-600' : 'text-red-600' }}">
                                {{ number_format($t->amount, 2, ',', '.') }} {{ $t->currency }}
                            </span>
                        </div>
                    </div>
                @empty
                    <div class="text-sm text-[var(--ui-muted)]">Keine Transaktionen</div>
                @endforelse
            </div>
        </x-ui-panel>
    </div>

</x-ui-page-container>