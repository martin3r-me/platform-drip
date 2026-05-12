<x-ui-page>
    @include('drip::partials.styles')
    <x-slot name="navbar">
        <x-ui-page-navbar title="Banken & Konten" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Drip', 'href' => route('drip.dashboard'), 'icon' => 'chart-bar'],
            ['label' => 'Banken & Konten'],
        ]">
            <button type="button" wire:click="openGroupModal"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md bg-green-600 text-white text-[13px] font-medium hover:bg-green-700 transition-colors">
                @svg('heroicon-o-plus', 'w-4 h-4')
                Gruppe hinzufügen
            </button>
        </x-ui-page-actionbar>
    </x-slot>

    <x-ui-page-container>

    {{-- GoCardless Banken --}}
    <div class="bg-white rounded-2xl shadow-sm p-6 mb-8">
        <div class="flex items-center justify-between mb-4">
            <div>
                <h2 class="text-xl font-bold text-gray-900">Banken verbinden</h2>
                <p class="text-[13px] text-gray-500 mt-0.5">Verbinde deine Bank über GoCardless</p>
            </div>
            @if (empty($gocardlessInstitutions))
                <button type="button" wire:click="loadGoCardlessInstitutions" wire:loading.attr="disabled" wire:target="loadGoCardlessInstitutions"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md bg-green-600 text-white text-[13px] font-medium hover:bg-green-700 transition-colors disabled:opacity-50">
                    <span wire:loading.remove wire:target="loadGoCardlessInstitutions">Banken laden</span>
                    <span wire:loading wire:target="loadGoCardlessInstitutions" class="flex items-center">
                        <svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-current" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        Lade Banken...
                    </span>
                </button>
            @endif
        </div>

        @if (session('error'))
            <div class="p-3 rounded-md mb-4 bg-red-50 text-red-700 border border-red-200 text-[13px]">
                {{ session('error') }}
            </div>
        @endif

        @if (session('success'))
            <div class="p-3 rounded-md mb-4 bg-green-50 text-green-700 border border-green-200 text-[13px]">
                {{ session('success') }}
            </div>
        @endif

        @if (!empty($gocardlessInstitutions))
            <div class="mb-4">
                <input type="text" wire:model.live="search" placeholder="Bank suchen..."
                       class="w-full px-3 py-1.5 rounded-md border border-gray-200 text-[13px] text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-1 focus:ring-blue-500 focus:border-blue-500">
            </div>

            @if (count($filteredInstitutions))
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
                    @foreach ($filteredInstitutions as $bank)
                        <div class="bg-white rounded-2xl shadow-sm p-4 hover:shadow-sm transition-shadow">
                            <div class="flex items-center space-x-3 mb-4">
                                <div class="w-12 h-12 bg-gray-50 rounded-lg flex items-center justify-center overflow-hidden">
                                    @if($bank['logo'])
                                        <img src="{{ $bank['logo'] }}" alt="{{ $bank['name'] }}" class="w-10 h-10 object-contain">
                                    @else
                                        <div class="w-8 h-8 bg-blue-600 rounded-full flex items-center justify-center">
                                            <span class="text-white font-bold text-sm">{{ substr($bank['name'], 0, 1) }}</span>
                                        </div>
                                    @endif
                                </div>
                                <div class="flex-1 min-w-0">
                                    <h3 class="text-[13px] font-semibold text-gray-900 truncate">{{ $bank['name'] }}</h3>
                                    <p class="text-[11px] text-gray-500">{{ $bank['countries'][0] ?? 'DE' }}</p>
                                </div>
                            </div>
                            <button type="button" wire:click="connectBank('{{ $bank['id'] }}')" wire:loading.attr="disabled" wire:target="connectBank"
                                    class="w-full inline-flex items-center justify-center gap-1.5 px-3 py-1.5 rounded-md bg-blue-600 text-white text-[13px] font-medium hover:bg-blue-700 transition-colors disabled:opacity-50">
                                <span wire:loading.remove wire:target="connectBank">Jetzt verbinden</span>
                                <span wire:loading wire:target="connectBank" class="flex items-center justify-center">
                                    <svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-current" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                    </svg>
                                    Verbinde...
                                </span>
                            </button>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="text-center py-8">
                    <div class="text-gray-400 mb-2">
                        @svg('heroicon-o-magnifying-glass', 'w-10 h-10 mx-auto')
                    </div>
                    <p class="text-[13px] text-gray-500">Keine passenden Banken gefunden.</p>
                </div>
            @endif
        @else
            <div class="text-center py-12 bg-gray-50 rounded-2xl">
                <div class="text-gray-400 mb-4">
                    @svg('heroicon-o-building-library', 'w-12 h-12 mx-auto')
                </div>
                <h3 class="text-xl font-bold text-gray-900 mb-1">Banken verbinden</h3>
                <p class="text-[13px] text-gray-500">Lade verfügbare Banken, um deine Konten zu verbinden.</p>
            </div>
        @endif
    </div>

    {{-- Kontogruppen --}}
    <div class="space-y-8">
        @forelse ($groups as $group)
            <div class="bg-white rounded-2xl shadow-sm p-6">
                <div class="flex items-center justify-between mb-4">
                    <div class="flex-1">
                        <h3 class="text-xl font-bold text-gray-900">{{ $group->name }}</h3>
                        <p class="text-[13px] text-gray-500 mt-0.5">{{ $group->accounts->count() }} Konten</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <a href="{{ route('drip.groups.show', $group) }}" wire:navigate
                           class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md bg-green-600 text-white text-[13px] font-medium hover:bg-green-700 transition-colors">
                            @svg('heroicon-o-banknotes', 'w-4 h-4')
                            Transaktionen
                        </a>
                        <button type="button" wire:click="openAccountModal({{ $group->id }})"
                                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md bg-blue-600 text-white text-[13px] font-medium hover:bg-blue-700 transition-colors">
                            @svg('heroicon-o-plus', 'w-4 h-4')
                            Konto hinzufügen
                        </button>
                    </div>
                </div>

                @if ($group->accounts->count() > 0)
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                        @foreach ($group->accounts as $account)
                            <div class="rounded-2xl shadow-sm p-4 hover:shadow-md transition-shadow bg-white">
                                <div class="flex items-start justify-between">
                                    <div class="flex-1">
                                        <h4 class="text-[13px] font-medium text-gray-900">{{ $account->name }}</h4>
                                        <p class="text-[11px] text-gray-500 mt-1">{{ $account->institution?->name ?? 'Unbekannte Bank' }}</p>
                                        <p class="text-[11px] text-gray-400 mt-0.5">{{ $account->currency }} &middot; {{ $account->iban ? '****' . substr($account->iban, -4) : 'Keine IBAN' }}</p>

                                        @if($account->balances->count() > 0)
                                            <div class="mt-3 space-y-1">
                                                @foreach($account->balances->take(3) as $balance)
                                                    <div class="flex items-center justify-between text-[11px]">
                                                        <span class="text-gray-400">{{ ucfirst($balance->balance_type) }}:</span>
                                                        <span class="font-medium tabular-nums {{ $balance->amount >= 0 ? 'text-green-600' : 'text-red-600' }}">
                                                            {{ number_format($balance->amount, 2, ',', '.') }} {{ $balance->currency ?? $account->currency }}
                                                        </span>
                                                    </div>
                                                @endforeach
                                                @if($account->balances->count() > 3)
                                                    <div class="text-[11px] text-gray-400">
                                                        +{{ $account->balances->count() - 3 }} weitere
                                                    </div>
                                                @endif
                                            </div>
                                        @else
                                            <div class="mt-2 text-[11px] text-gray-400">
                                                Keine Salden verfügbar
                                            </div>
                                        @endif
                                    </div>
                                    <span class="inline-flex items-center px-1.5 py-0.5 rounded-full text-[11px] font-medium bg-green-50 text-green-700">
                                        Verbunden
                                    </span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="text-center py-8 bg-gray-50 rounded-2xl">
                        <div class="text-gray-400 mb-2">
                            @svg('heroicon-o-credit-card', 'w-8 h-8 mx-auto')
                        </div>
                        <p class="text-[13px] text-gray-500">Keine Konten in dieser Gruppe</p>
                        <button type="button" wire:click="openAccountModal({{ $group->id }})" class="mt-2 text-[13px] text-blue-600 hover:text-blue-700 font-medium">
                            Erstes Konto hinzufügen
                        </button>
                    </div>
                @endif
            </div>
        @empty
            <div class="bg-white rounded-2xl shadow-sm p-12 text-center">
                <div class="text-gray-400 mb-4">
                    @svg('heroicon-o-folder', 'w-12 h-12 mx-auto')
                </div>
                <h3 class="text-xl font-bold text-gray-900 mb-1">Keine Kontogruppen</h3>
                <p class="text-[13px] text-gray-500 mb-4">Erstelle eine Kontogruppe, um deine Bankkonten zu organisieren.</p>
                <button type="button" wire:click="openGroupModal"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md bg-green-600 text-white text-[13px] font-medium hover:bg-green-700 transition-colors">
                    @svg('heroicon-o-plus', 'w-4 h-4')
                    Erste Gruppe erstellen
                </button>
            </div>
        @endforelse
    </div>

    {{-- Konten ohne Gruppe --}}
    @if ($accounts->whereNull('group_id')->count() > 0)
        <div class="bg-white rounded-2xl shadow-sm p-6 mt-8">
            <div class="flex items-center justify-between mb-4">
                <div>
                    <h3 class="text-xl font-bold text-gray-900">Konten ohne Gruppe</h3>
                    <p class="text-[13px] text-gray-500 mt-0.5">{{ $accounts->whereNull('group_id')->count() }} Konten warten auf Zuordnung</p>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                @foreach ($accounts->whereNull('group_id') as $account)
                    <div class="rounded-2xl shadow-sm p-4 hover:shadow-md transition-shadow bg-white">
                        <div class="flex items-start justify-between">
                            <div class="flex-1">
                                <h4 class="text-[13px] font-medium text-gray-900">{{ $account->name }}</h4>
                                <p class="text-[11px] text-gray-500 mt-1">{{ $account->institution?->name ?? 'Unbekannte Bank' }}</p>
                                <p class="text-[11px] text-gray-400 mt-0.5">{{ $account->currency }} &middot; {{ $account->iban ? '****' . substr($account->iban, -4) : 'Keine IBAN' }}</p>
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="inline-flex items-center px-1.5 py-0.5 rounded-full text-[11px] font-medium bg-yellow-50 text-yellow-700">
                                    Ohne Gruppe
                                </span>
                                <button type="button" wire:click="assignAccountToGroup({{ $account->id }})"
                                        class="inline-flex items-center gap-1 px-2 py-1 rounded-md bg-blue-600 text-white text-[11px] font-medium hover:bg-blue-700 transition-colors">
                                    @svg('heroicon-o-plus', 'w-3 h-3')
                                    Zuordnen
                                </button>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
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
            <p class="text-[13px] text-gray-500">Wähle eine Gruppe für das Konto aus:</p>
            <div class="grid grid-cols-1 gap-3">
                @foreach ($groups as $group)
                    <button
                        type="button"
                        wire:click="assignToGroup({{ $group->id }})"
                        class="flex items-center justify-between p-4 rounded-2xl shadow-sm hover:bg-gray-50/50 hover:border-gray-300 transition-colors"
                    >
                        <div class="flex items-center">
                            <div class="w-3 h-3 rounded-full mr-3" style="background-color: {{ $group->color ?? '#6B7280' }}"></div>
                            <div class="text-left">
                                <h4 class="text-[13px] font-medium text-gray-900">{{ $group->name }}</h4>
                                <p class="text-[11px] text-gray-500">{{ $group->accounts->count() }} Konten</p>
                            </div>
                        </div>
                        <div class="text-gray-400">
                            @svg('heroicon-o-arrow-right', 'w-4 h-4')
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

    <x-slot name="activity">
        <x-ui-page-sidebar title="Aktionen" width="w-80" side="right" :defaultOpen="true" storeKey="activityOpen">
            <div class="p-4 space-y-5">
                <div>
                    <div class="text-[11px] font-medium text-gray-500 uppercase tracking-wide mb-2">Übersicht</div>
                    <div class="space-y-1.5 text-[13px]">
                        <div class="flex items-center justify-between">
                            <span class="text-gray-500">Gruppen</span>
                            <span class="font-medium text-gray-900">{{ $groups->count() }}</span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-gray-500">Konten</span>
                            <span class="font-medium text-gray-900">{{ $accounts->count() }}</span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-gray-500">Ohne Gruppe</span>
                            <span class="font-medium text-gray-900">{{ $accounts->whereNull('group_id')->count() }}</span>
                        </div>
                    </div>
                </div>

                <div>
                    <div class="text-[11px] font-medium text-gray-500 uppercase tracking-wide mb-2">Aktionen</div>
                    <div class="space-y-1.5">
                        <button type="button" wire:click="updateAll"
                                class="w-full flex items-center gap-2 px-3 py-2 rounded-md text-[13px] text-gray-700 hover:bg-gray-100 transition-colors border border-gray-200">
                            @svg('heroicon-o-arrow-path', 'w-4 h-4 text-gray-400')
                            Bankdaten aktualisieren
                        </button>
                    </div>
                </div>
            </div>
        </x-ui-page-sidebar>
    </x-slot>

</x-ui-page>
