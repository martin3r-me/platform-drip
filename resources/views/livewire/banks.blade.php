<div class="space-y-8">
    {{-- Header --}}
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Banken & Konten</h1>
            <p class="text-sm text-gray-600 mt-1">Verwalte deine Bankverbindungen und Kontogruppen</p>
        </div>
        <div class="flex items-center gap-3">
            <button type="button" wire:click="openGroupModal" class="inline-flex items-center px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2 transition-colors">
                @svg('heroicon-o-plus', 'w-4 h-4 mr-2')
                Gruppe hinzufügen
            </button>
        </div>
    </div>

    {{-- GoCardless Banken --}}
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
        <div class="flex items-center justify-between mb-6">
            <div>
                <h2 class="text-lg font-semibold text-gray-900">Banken verbinden</h2>
                <p class="text-sm text-gray-600 mt-1">Verbinde deine Bank über GoCardless</p>
            </div>
            @if (empty($gocardlessInstitutions))
                <button 
                    wire:click="loadGoCardlessInstitutions"
                    :disabled="$wire.loadingInstitutions"
                    class="inline-flex items-center px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700 disabled:opacity-50 disabled:cursor-not-allowed"
                >
                    <span wire:loading.remove wire:target="loadGoCardlessInstitutions">Banken laden</span>
                    <span wire:loading wire:target="loadGoCardlessInstitutions" class="flex items-center">
                        <svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        Lade Banken...
                    </span>
                </button>
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
                <input
                    type="text"
                    wire:model.live="search"
                    placeholder="Bank suchen..."
                    class="w-full p-3 border border-gray-300 rounded-lg shadow-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                >
            </div>

            @if (count($filteredInstitutions))
                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
                    @foreach ($filteredInstitutions as $bank)
                        <div class="bg-white shadow-lg rounded-lg p-4 hover:shadow-xl transition-shadow duration-200">
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
                            <button 
                                wire:click="connectBank('{{ $bank['id'] }}')"
                                :disabled="$wire.connectingBank"
                                class="w-full bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors duration-200"
                            >
                                <span wire:loading.remove wire:target="connectBank">Jetzt verbinden</span>
                                <span wire:loading wire:target="connectBank" class="flex items-center justify-center">
                                    <svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
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
    </div>

    {{-- Kontogruppen --}}
    <div class="space-y-6">
        @forelse ($groups as $group)
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                <div class="flex items-center justify-between mb-4">
                    <div>
                        <h3 class="text-lg font-semibold text-gray-900">{{ $group->name }}</h3>
                        <p class="text-sm text-gray-600">{{ $group->accounts->count() }} Konten</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <button type="button" wire:click="openAccountModal({{ $group->id }})" class="inline-flex items-center px-3 py-1.5 bg-blue-600 text-white text-sm rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                            @svg('heroicon-o-plus', 'w-4 h-4 mr-1')
                            Konto hinzufügen
                        </button>
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
                        <button type="button" wire:click="openAccountModal({{ $group->id }})" class="mt-2 text-blue-600 hover:text-blue-700 text-sm font-medium">
                            Erstes Konto hinzufügen
                        </button>
                    </div>
                @endif
            </div>
        @empty
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-12 text-center">
                <div class="text-gray-400 mb-4">
                    @svg('heroicon-o-folder', 'w-16 h-16 mx-auto')
                </div>
                <h3 class="text-lg font-medium text-gray-900 mb-2">Keine Kontogruppen</h3>
                <p class="text-gray-500 mb-6">Erstelle eine Kontogruppe, um deine Bankkonten zu organisieren.</p>
                <button type="button" wire:click="openGroupModal" class="inline-flex items-center px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2">
                    @svg('heroicon-o-plus', 'w-4 h-4 mr-2')
                    Erste Gruppe erstellen
                </button>
            </div>
        @endforelse
    </div>

    <x-ui-modal model="showInstitutionModal">
        <x-slot:title>Bank hinzufügen</x-slot:title>
        <div class="space-y-4">
            <x-ui-input-text name="institution_name" label="Name" wire:model.defer="institutionForm.name" />
            <x-ui-input-text name="institution_country" label="Land (ISO-2)" wire:model.defer="institutionForm.country" />
            <x-ui-input-text name="institution_external_id" label="Externe ID" wire:model.defer="institutionForm.external_id" />
        </div>
        <x-slot:footer>
            <button type="button" wire:click="saveInstitution" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                Speichern
            </button>
        </x-slot:footer>
    </x-ui-modal>

    <x-ui-modal model="showGroupModal">
        <x-slot:title>Gruppe hinzufügen</x-slot:title>
        <div class="space-y-4">
            <x-ui-input-text name="group_name" label="Name" wire:model.defer="groupForm.name" />
            <x-ui-input-text name="group_color" label="Farbe" wire:model.defer="groupForm.color" />
        </div>
        <x-slot:footer>
            <button type="button" wire:click="saveGroup" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500">
                Speichern
            </button>
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
            <button type="button" wire:click="saveAccount" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-purple-600 hover:bg-purple-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-purple-500">
                Speichern
            </button>
        </x-slot:footer>
    </x-ui-modal>
</div>


