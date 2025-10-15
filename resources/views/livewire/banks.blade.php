<x-ui-page-container>
    <x-slot name="navbar">
        <x-ui-page-navbar title="Banken & Konten" subtitle="Verwalte Bankverbindungen, Gruppen und Konten">
            <x-slot name="actions">
                <x-ui-button variant="success" size="sm" wire:click="openGroupModal">
                    @svg('heroicon-o-plus', 'w-4 h-4')
                    <span class="ml-2">Gruppe hinzufügen</span>
                </x-ui-button>
            </x-slot>
        </x-ui-page-navbar>
    </x-slot>

    {{-- GoCardless Banken --}}
    <x-ui-panel title="Banken verbinden" subtitle="Verbinde deine Bank über GoCardless" class="mb-8">
        <div class="flex items-center justify-between mb-4">
            <div></div>
            @if (empty($gocardlessInstitutions))
                <x-ui-button variant="success" size="sm" wire:click="loadGoCardlessInstitutions" wire:loading.attr="disabled" wire:target="loadGoCardlessInstitutions">
                    <span wire:loading.remove wire:target="loadGoCardlessInstitutions">Banken laden</span>
                    <span wire:loading wire:target="loadGoCardlessInstitutions" class="flex items-center">
                        <svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-current" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        Lade Banken...
                    </span>
                </x-ui-button>
            @endif
        </div>
        
        @if (session('error'))
            <div class="bg-red-100 text-red-800 p-4 rounded mb-4">
                {{ session('error') }}
            </div>
        @endif

        @if (session('success'))
            <div class="bg-green-100 text-green-800 p-4 rounded mb-4">
                {{ session('success') }}
            </div>
        @endif

        @if (!empty($gocardlessInstitutions))
            <div class="mb-4">
                <x-ui-input-text name="institution_search" label="Bank suchen" placeholder="Bank suchen..." wire:model.live="search" />
            </div>

            @if (count($filteredInstitutions))
                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
                    @foreach ($filteredInstitutions as $bank)
                        <div class="bg-white border border-[var(--ui-border)] rounded-lg p-4 hover:shadow-sm transition-shadow duration-200">
                            <div class="flex items-center space-x-3 mb-4">
                                <div class="w-12 h-12 bg-gray-100 rounded-lg flex items-center justify-center overflow-hidden">
                                    @if($bank['logo'])
                                        <img src="{{ $bank['logo'] }}" alt="{{ $bank['name'] }}" class="w-10 h-10 object-contain">
                                    @else
                                        <div class="w-8 h-8 bg-blue-500 rounded-full flex items-center justify-center">
                                            <span class="text-white font-bold text-sm">{{ substr($bank['name'], 0, 1) }}</span>
                                        </div>
                                    @endif
                                </div>
                                <div class="flex-1 min-w-0">
                                    <h3 class="font-semibold text-gray-900 truncate">{{ $bank['name'] }}</h3>
                                    <p class="text-sm text-gray-500">{{ $bank['countries'][0] ?? 'DE' }}</p>
                                </div>
                            </div>
                            <x-ui-button class="w-full justify-center" size="sm" variant="primary" wire:click="connectBank('{{ $bank['id'] }}')" wire:loading.attr="disabled" wire:target="connectBank">
                                <span wire:loading.remove wire:target="connectBank">Jetzt verbinden</span>
                                <span wire:loading wire:target="connectBank" class="flex items-center justify-center">
                                    <svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-current" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                    </svg>
                                    Verbinde...
                                </span>
                            </x-ui-button>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="text-center py-8">
                    <div class="text-gray-400 mb-2">
                        <svg class="mx-auto h-12 w-12" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                        </svg>
                    </div>
                    <p class="text-gray-500">Keine passenden Banken gefunden.</p>
                </div>
            @endif
        @else
            <div class="text-center py-12 bg-gray-50 rounded-lg">
                <div class="text-gray-400 mb-4">
                    <svg class="mx-auto h-16 w-16" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                    </svg>
                </div>
                <h3 class="text-lg font-medium text-gray-900 mb-2">Banken verbinden</h3>
                <p class="text-gray-500 mb-4">Lade verfügbare Banken, um deine Konten zu verbinden.</p>
            </div>
        @endif
    </x-ui-panel>

    {{-- Kontogruppen --}}
    <div class="space-y-6">
        @forelse ($groups as $group)
            <x-ui-panel class="p-6">
                <div class="flex items-center justify-between mb-4">
                    <div class="flex-1">
                        <h3 class="text-lg font-semibold text-gray-900">{{ $group->name }}</h3>
                        <p class="text-sm text-gray-600">{{ $group->accounts->count() }} Konten</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <x-ui-button :href="route('drip.groups.show', $group)" wire:navigate size="sm" variant="success">
                            @svg('heroicon-o-banknotes', 'w-4 h-4')
                            <span class="ml-1">Transaktionen</span>
                        </x-ui-button>
                        <x-ui-button size="sm" variant="primary" wire:click="openAccountModal({{ $group->id }})">
                            @svg('heroicon-o-plus', 'w-4 h-4')
                            <span class="ml-1">Konto hinzufügen</span>
                        </x-ui-button>
                    </div>
                </div>
                
                @if ($group->accounts->count() > 0)
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                        @foreach ($group->accounts as $account)
                            <div class="border border-gray-200 rounded-lg p-4 hover:shadow-md transition-shadow">
                                <div class="flex items-start justify-between">
                                    <div class="flex-1">
                                        <h4 class="font-medium text-gray-900">{{ $account->name }}</h4>
                                        <p class="text-sm text-gray-600 mt-1">{{ $account->institution?->name ?? 'Unbekannte Bank' }}</p>
                                        <p class="text-xs text-gray-500 mt-1">{{ $account->currency }} • {{ $account->iban ? '****' . substr($account->iban, -4) : 'Keine IBAN' }}</p>
                                        
                                        {{-- Balances --}}
                                        @if($account->balances->count() > 0)
                                            <div class="mt-3 space-y-1">
                                                @foreach($account->balances->take(3) as $balance)
                                                    <div class="flex items-center justify-between text-xs">
                                                        <span class="text-gray-500">{{ ucfirst($balance->balance_type) }}:</span>
                                                        <span class="font-medium {{ $balance->amount >= 0 ? 'text-green-600' : 'text-red-600' }}">
                                                            {{ number_format($balance->amount, 2, ',', '.') }} {{ $balance->currency ?? $account->currency }}
                                                        </span>
                                                    </div>
                                                @endforeach
                                                @if($account->balances->count() > 3)
                                                    <div class="text-xs text-gray-400">
                                                        +{{ $account->balances->count() - 3 }} weitere
                                                    </div>
                                                @endif
                                            </div>
                                        @else
                                            <div class="mt-2 text-xs text-gray-400">
                                                Keine Salden verfügbar
                                            </div>
                                        @endif
                                    </div>
                                    <div class="flex items-center gap-1">
                                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                            Verbunden
                                        </span>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="text-center py-8 bg-gray-50 rounded-lg">
                        <div class="text-gray-400 mb-2">
                            @svg('heroicon-o-credit-card', 'w-8 h-8 mx-auto')
                        </div>
                        <p class="text-gray-500 text-sm">Keine Konten in dieser Gruppe</p>
                        <x-ui-button size="xs" variant="primary-link" wire:click="openAccountModal({{ $group->id }})">
                            Erstes Konto hinzufügen
                        </x-ui-button>
                    </div>
                @endif
            </x-ui-panel>
        @empty
            <x-ui-panel class="p-12 text-center">
                <div class="text-gray-400 mb-4">
                    @svg('heroicon-o-folder', 'w-16 h-16 mx-auto')
                </div>
                <h3 class="text-lg font-medium text-gray-900 mb-2">Keine Kontogruppen</h3>
                <p class="text-gray-500 mb-6">Erstelle eine Kontogruppe, um deine Bankkonten zu organisieren.</p>
                <x-ui-button variant="success" wire:click="openGroupModal">
                    @svg('heroicon-o-plus', 'w-4 h-4')
                    <span class="ml-2">Erste Gruppe erstellen</span>
                </x-ui-button>
            </x-ui-panel>
        @endforelse
    </div>

    {{-- Konten ohne Gruppe --}}
    @if ($accounts->whereNull('group_id')->count() > 0)
        <x-ui-panel class="p-6">
            <div class="flex items-center justify-between mb-4">
                <div>
                    <h3 class="text-lg font-semibold text-gray-900">Konten ohne Gruppe</h3>
                    <p class="text-sm text-gray-600">{{ $accounts->whereNull('group_id')->count() }} Konten warten auf Zuordnung</p>
                </div>
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                @foreach ($accounts->whereNull('group_id') as $account)
                    <div class="border border-gray-200 rounded-lg p-4 hover:shadow-md transition-shadow">
                        <div class="flex items-start justify-between">
                            <div class="flex-1">
                                <h4 class="font-medium text-gray-900">{{ $account->name }}</h4>
                                <p class="text-sm text-gray-600 mt-1">{{ $account->institution?->name ?? 'Unbekannte Bank' }}</p>
                                <p class="text-xs text-gray-500 mt-1">{{ $account->currency }} • {{ $account->iban ? '****' . substr($account->iban, -4) : 'Keine IBAN' }}</p>
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">
                                    Ohne Gruppe
                                </span>
                                <x-ui-button size="xs" variant="primary" wire:click="assignAccountToGroup({{ $account->id }})">
                                    @svg('heroicon-o-plus', 'w-3 h-3')
                                    <span class="ml-1">Zuordnen</span>
                                </x-ui-button>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </x-ui-panel>
    @endif

    <x-ui-modal model="showInstitutionModal">
        <x-slot:title>Bank hinzufügen</x-slot:title>
        <div class="space-y-4">
            <x-ui-input-text name="institution_name" label="Name" wire:model.defer="institutionForm.name" />
            <x-ui-input-text name="institution_country" label="Land (ISO-2)" wire:model.defer="institutionForm.country" />
            <x-ui-input-text name="institution_external_id" label="Externe ID" wire:model.defer="institutionForm.external_id" />
        </div>
        <x-slot:footer>
            <x-ui-button variant="primary" wire:click="saveInstitution">Speichern</x-ui-button>
        </x-slot:footer>
    </x-ui-modal>

    <x-ui-modal model="showGroupModal">
        <x-slot:title>Gruppe hinzufügen</x-slot:title>
        <div class="space-y-4">
            <x-ui-input-text name="group_name" label="Name" wire:model.defer="groupForm.name" />
            <x-ui-input-text name="group_color" label="Farbe" wire:model.defer="groupForm.color" />
        </div>
        <x-slot:footer>
            <x-ui-button variant="success" wire:click="saveGroup">Speichern</x-ui-button>
        </x-slot:footer>
    </x-ui-modal>

    <x-ui-modal model="showAccountModal">
        <x-slot:title>Konto hinzufügen</x-slot:title>
        <div class="space-y-4">
            <x-ui-input-text name="account_name" label="Name" wire:model.defer="accountForm.name" />
            <x-ui-input-text name="account_iban" label="IBAN" wire:model.defer="accountForm.iban" />
            <x-ui-input-text name="account_currency" label="Währung" wire:model.defer="accountForm.currency" />
            <x-ui-input-select name="account_institution_id" label="Bank" wire:model.defer="accountForm.institution_id">
                <option value="">—</option>
                @foreach ($institutions as $i)
                    <option value="{{ $i->id }}">{{ $i->name }}</option>
                @endforeach
            </x-ui-input-select>
            <x-ui-input-select name="account_group_id" label="Gruppe" wire:model.defer="accountForm.group_id">
                <option value="">—</option>
                @foreach ($groups as $g)
                    <option value="{{ $g->id }}">{{ $g->name }}</option>
                @endforeach
            </x-ui-input-select>
        </div>
        <x-slot:footer>
            <x-ui-button variant="primary" wire:click="saveAccount">Speichern</x-ui-button>
        </x-slot:footer>
    </x-ui-modal>

    {{-- Gruppenauswahl Modal --}}
    <x-ui-modal model="showGroupSelectionModal">
        <x-slot:title>Konto einer Gruppe zuordnen</x-slot:title>
        <div class="space-y-4">
            <p class="text-sm text-gray-600">Wähle eine Gruppe für das Konto aus:</p>
            <div class="grid grid-cols-1 gap-3">
                @foreach ($groups as $group)
                    <button 
                        type="button" 
                        wire:click="assignToGroup({{ $group->id }})"
                        class="flex items-center justify-between p-4 border border-gray-200 rounded-lg hover:bg-gray-50 hover:border-gray-300 transition-colors"
                    >
                        <div class="flex items-center">
                            <div class="w-3 h-3 rounded-full mr-3" style="background-color: {{ $group->color ?? '#6B7280' }}"></div>
                            <div>
                                <h4 class="font-medium text-gray-900">{{ $group->name }}</h4>
                                <p class="text-sm text-gray-600">{{ $group->accounts->count() }} Konten</p>
                            </div>
                        </div>
                        <div class="text-gray-400">
                            @svg('heroicon-o-arrow-right', 'w-5 h-5')
                        </div>
                    </button>
                @endforeach
            </div>
        </div>
        <x-slot:footer>
            <x-ui-button variant="secondary-outline" wire:click="$set('showGroupSelectionModal', false)">Abbrechen</x-ui-button>
        </x-slot:footer>
    </x-ui-modal>
    
</x-ui-page-container>


