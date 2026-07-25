<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="Banken & Konten" icon="heroicon-o-building-library" />
    </x-slot>

    <x-slot name="sidebar">
        @include('drip::partials.inner-sidebar')
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Drip', 'href' => route('drip.dashboard'), 'icon' => 'chart-bar'],
            ['label' => 'Banken & Konten'],
        ]">
            <x-nx-button variant="primary" wire:click="openGroupModal">
                @svg('heroicon-o-plus', 'w-4 h-4')
                Gruppe hinzufügen
            </x-nx-button>
        </x-ui-page-actionbar>
    </x-slot>

    <x-ui-page-container width="contained">

        {{-- Überblick --}}
        <x-nx-stat-grid cols="3">
            <x-nx-stat label="Gruppen" :value="(string) $groups->count()" icon="heroicon-o-folder" />
            <x-nx-stat label="Konten" :value="(string) $accounts->count()" icon="heroicon-o-credit-card" />
            <x-nx-stat label="Ohne Gruppe" :value="(string) $accounts->whereNull('group_id')->count()" icon="heroicon-o-exclamation-triangle" />
        </x-nx-stat-grid>

        {{-- Session-Feedback --}}
        @if (session('error'))
            <x-nx-callout variant="danger">{{ session('error') }}</x-nx-callout>
        @endif
        @if (session('success'))
            <x-nx-callout variant="success">{{ session('success') }}</x-nx-callout>
        @endif

        {{-- GoCardless Banken --}}
        <x-nx-section icon="heroicon-o-building-library" title="Banken verbinden" description="Verbinde deine Bank über GoCardless">
            @if (empty($gocardlessInstitutions))
                <x-slot name="action">
                    <x-nx-button variant="primary" wire:click="loadGoCardlessInstitutions" wire:loading.attr="disabled" wire:target="loadGoCardlessInstitutions">
                        <span wire:loading.remove wire:target="loadGoCardlessInstitutions">Banken laden</span>
                        <span wire:loading wire:target="loadGoCardlessInstitutions">Lade Banken…</span>
                    </x-nx-button>
                </x-slot>
            @endif

            @if (!empty($gocardlessInstitutions))
                <div class="mb-4">
                    <x-nx-input-text name="search" placeholder="Bank suchen…" wire:model.live="search" />
                </div>

                @if (count($filteredInstitutions))
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                        @foreach ($filteredInstitutions as $bank)
                            <x-nx-card>
                                <div class="flex items-center gap-3 mb-4">
                                    <div class="w-12 h-12 rounded-lg flex items-center justify-center overflow-hidden bg-[color:var(--nx-hover)]">
                                        @if($bank['logo'])
                                            <img src="{{ $bank['logo'] }}" alt="{{ $bank['name'] }}" class="w-10 h-10 object-contain">
                                        @else
                                            <span class="text-sm font-bold text-[color:var(--nx-text)]">{{ substr($bank['name'], 0, 1) }}</span>
                                        @endif
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <h3 class="text-sm font-semibold text-[color:var(--nx-text)] truncate">{{ $bank['name'] }}</h3>
                                        <p class="text-xs text-[color:var(--nx-muted)]">{{ $bank['countries'][0] ?? 'DE' }}</p>
                                    </div>
                                </div>
                                <x-nx-button variant="primary" class="w-full" wire:click="connectBank('{{ $bank['id'] }}')" wire:loading.attr="disabled" wire:target="connectBank">
                                    <span wire:loading.remove wire:target="connectBank">Jetzt verbinden</span>
                                    <span wire:loading wire:target="connectBank">Verbinde…</span>
                                </x-nx-button>
                            </x-nx-card>
                        @endforeach
                    </div>
                @else
                    <x-nx-empty icon="heroicon-o-magnifying-glass">Keine passenden Banken gefunden.</x-nx-empty>
                @endif
            @else
                <x-nx-empty icon="heroicon-o-building-library">Lade verfügbare Banken, um deine Konten zu verbinden.</x-nx-empty>
            @endif
        </x-nx-section>

        {{-- Kontogruppen --}}
        @forelse ($groups as $group)
            <x-nx-section icon="heroicon-o-folder" :title="$group->name" :hint="$group->accounts->count() . ' Konten'">
                <x-slot name="action">
                    <x-nx-button variant="secondary" :href="route('drip.groups.show', $group)" wire:navigate>
                        @svg('heroicon-o-banknotes', 'w-4 h-4')
                        Transaktionen
                    </x-nx-button>
                    <x-nx-button variant="primary" wire:click="openAccountModal({{ $group->id }})">
                        @svg('heroicon-o-plus', 'w-4 h-4')
                        Konto hinzufügen
                    </x-nx-button>
                </x-slot>

                @if ($group->accounts->count() > 0)
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                        @foreach ($group->accounts as $account)
                            <x-nx-card>
                                <div class="flex items-start justify-between gap-2">
                                    <div class="flex-1 min-w-0">
                                        <h4 class="text-sm font-medium text-[color:var(--nx-text)]">{{ $account->name }}</h4>
                                        <p class="text-xs text-[color:var(--nx-muted)] mt-1">{{ $account->institution?->name ?? 'Unbekannte Bank' }}</p>
                                        <p class="text-xs text-[color:var(--nx-faint)] mt-0.5">{{ $account->currency }} &middot; {{ $account->iban ? '****' . substr($account->iban, -4) : 'Keine IBAN' }}</p>

                                        @if($account->balances->count() > 0)
                                            <div class="mt-3 space-y-1">
                                                @foreach($account->balances->take(3) as $balance)
                                                    <div class="flex items-center justify-between text-xs">
                                                        <span class="text-[color:var(--nx-faint)]">{{ ucfirst($balance->balance_type) }}:</span>
                                                        <span class="font-medium tabular-nums {{ $balance->amount >= 0 ? 'text-green-600' : 'text-red-600' }}">
                                                            {{ number_format($balance->amount, 2, ',', '.') }} {{ $balance->currency ?? $account->currency }}
                                                        </span>
                                                    </div>
                                                @endforeach
                                                @if($account->balances->count() > 3)
                                                    <div class="text-xs text-[color:var(--nx-faint)]">+{{ $account->balances->count() - 3 }} weitere</div>
                                                @endif
                                            </div>
                                        @else
                                            <div class="mt-2 text-xs text-[color:var(--nx-faint)]">Keine Salden verfügbar</div>
                                        @endif
                                    </div>
                                    <x-nx-badge variant="success" dot>Verbunden</x-nx-badge>
                                </div>
                            </x-nx-card>
                        @endforeach
                    </div>
                @else
                    <x-nx-empty icon="heroicon-o-credit-card">
                        Keine Konten in dieser Gruppe
                        <x-slot name="action">
                            <x-nx-button variant="primary" wire:click="openAccountModal({{ $group->id }})">Erstes Konto hinzufügen</x-nx-button>
                        </x-slot>
                    </x-nx-empty>
                @endif
            </x-nx-section>
        @empty
            <x-nx-empty icon="heroicon-o-folder">
                Keine Kontogruppen — erstelle eine, um deine Bankkonten zu organisieren.
                <x-slot name="action">
                    <x-nx-button variant="primary" wire:click="openGroupModal">Erste Gruppe erstellen</x-nx-button>
                </x-slot>
            </x-nx-empty>
        @endforelse

        {{-- Konten ohne Gruppe --}}
        @if ($accounts->whereNull('group_id')->count() > 0)
            <x-nx-section icon="heroicon-o-exclamation-triangle" title="Konten ohne Gruppe" :hint="$accounts->whereNull('group_id')->count() . ' warten auf Zuordnung'">
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    @foreach ($accounts->whereNull('group_id') as $account)
                        <x-nx-card>
                            <div class="flex items-start justify-between gap-2">
                                <div class="flex-1 min-w-0">
                                    <h4 class="text-sm font-medium text-[color:var(--nx-text)]">{{ $account->name }}</h4>
                                    <p class="text-xs text-[color:var(--nx-muted)] mt-1">{{ $account->institution?->name ?? 'Unbekannte Bank' }}</p>
                                    <p class="text-xs text-[color:var(--nx-faint)] mt-0.5">{{ $account->currency }} &middot; {{ $account->iban ? '****' . substr($account->iban, -4) : 'Keine IBAN' }}</p>
                                </div>
                                <div class="flex flex-col items-end gap-2 shrink-0">
                                    <x-nx-badge variant="warning">Ohne Gruppe</x-nx-badge>
                                    <x-nx-button variant="primary" wire:click="assignAccountToGroup({{ $account->id }})">
                                        @svg('heroicon-o-plus', 'w-3 h-3')
                                        Zuordnen
                                    </x-nx-button>
                                </div>
                            </div>
                        </x-nx-card>
                    @endforeach
                </div>
            </x-nx-section>
        @endif

        {{-- Modals --}}
        <x-nx-modal model="showInstitutionModal" size="md">
            <x-slot name="header">Bank hinzufügen</x-slot>
            <div class="space-y-4">
                <x-nx-input-text name="institution_name" label="Name" wire:model.defer="institutionForm.name" />
                <x-nx-input-text name="institution_country" label="Land (ISO-2)" wire:model.defer="institutionForm.country" />
                <x-nx-input-text name="institution_external_id" label="Externe ID" wire:model.defer="institutionForm.external_id" />
            </div>
            <x-slot name="footer">
                <x-nx-button variant="primary" wire:click="saveInstitution">Speichern</x-nx-button>
            </x-slot>
        </x-nx-modal>

        <x-nx-modal model="showGroupModal" size="md">
            <x-slot name="header">Gruppe hinzufügen</x-slot>
            <div class="space-y-4">
                <x-nx-input-text name="group_name" label="Name" wire:model.defer="groupForm.name" />
                <x-nx-input-text name="group_color" label="Farbe" wire:model.defer="groupForm.color" />
            </div>
            <x-slot name="footer">
                <x-nx-button variant="primary" wire:click="saveGroup">Speichern</x-nx-button>
            </x-slot>
        </x-nx-modal>

        <x-nx-modal model="showAccountModal" size="md">
            <x-slot name="header">Konto hinzufügen</x-slot>
            <div class="space-y-4">
                <x-nx-input-text name="account_name" label="Name" wire:model.defer="accountForm.name" />
                <x-nx-input-text name="account_iban" label="IBAN" wire:model.defer="accountForm.iban" />
                <x-nx-input-text name="account_currency" label="Währung" wire:model.defer="accountForm.currency" />
                <x-nx-input-select name="account_institution_id" label="Bank" :options="$institutions" optionValue="id" optionLabel="name" nullable nullLabel="—" wire:model.defer="accountForm.institution_id" />
                <x-nx-input-select name="account_group_id" label="Gruppe" :options="$groups" optionValue="id" optionLabel="name" nullable nullLabel="—" wire:model.defer="accountForm.group_id" />
            </div>
            <x-slot name="footer">
                <x-nx-button variant="primary" wire:click="saveAccount">Speichern</x-nx-button>
            </x-slot>
        </x-nx-modal>

        <x-nx-modal model="showGroupSelectionModal" size="md">
            <x-slot name="header">Konto einer Gruppe zuordnen</x-slot>
            <div class="space-y-3">
                <p class="text-sm text-[color:var(--nx-muted)]">Wähle eine Gruppe für das Konto aus:</p>
                @foreach ($groups as $group)
                    <x-nx-button variant="secondary" class="w-full justify-between" wire:click="assignToGroup({{ $group->id }})">
                        <span class="flex items-center gap-3 min-w-0">
                            <span class="w-3 h-3 rounded-full shrink-0" style="background-color: {{ $group->color ?? 'var(--nx-muted)' }}"></span>
                            <span class="text-left min-w-0">
                                <span class="block text-sm font-medium text-[color:var(--nx-text)] truncate">{{ $group->name }}</span>
                                <span class="block text-xs text-[color:var(--nx-muted)]">{{ $group->accounts->count() }} Konten</span>
                            </span>
                        </span>
                        @svg('heroicon-o-arrow-right', 'w-4 h-4')
                    </x-nx-button>
                @endforeach
            </div>
            <x-slot name="footer">
                <x-nx-button variant="ghost" wire:click="$set('showGroupSelectionModal', false)">Abbrechen</x-nx-button>
            </x-slot>
        </x-nx-modal>

    </x-ui-page-container>
</x-ui-page>
