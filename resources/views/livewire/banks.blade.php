<div class="space-y-8">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold">Banken & Konten</h1>
        <div class="text-sm text-gray-500">Livewire Test: {{ now() }}</div>
        <div class="flex items-center gap-2">
            <button type="button" wire:click="openInstitutionModal" class="inline-flex items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                @svg('heroicon-o-plus', 'w-4 h-4 mr-2')
                Bank hinzufügen
            </button>
            <button type="button" wire:click="openGroupModal" class="inline-flex items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-md text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500">
                @svg('heroicon-o-plus', 'w-4 h-4 mr-2')
                Gruppe hinzufügen
            </button>
            <button type="button" wire:click="openAccountModal" class="inline-flex items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-md text-white bg-purple-600 hover:bg-purple-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-purple-500">
                @svg('heroicon-o-plus', 'w-4 h-4 mr-2')
                Konto hinzufügen
            </button>
        </div>
    </div>

    {{-- GoCardless Banken --}}
    <div class="mb-8">
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-xl font-semibold">Banken verbinden</h2>
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

    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <div class="col-span-1">
            <h2 class="font-medium mb-2">Verbundene Banken</h2>
            <div class="space-y-1">
                @forelse ($institutions as $i)
                    <div class="px-3 py-2 rounded border">{{ $i->name }} <span class="text-gray-500">({{ $i->country }})</span></div>
                @empty
                    <div class="text-gray-500">Keine Banken erfasst.</div>
                @endforelse
            </div>
        </div>
        <div class="col-span-1">
            <h2 class="font-medium mb-2">Gruppen</h2>
            <div class="space-y-1">
                @forelse ($groups as $g)
                    <div class="px-3 py-2 rounded border">{{ $g->name }}</div>
                @empty
                    <div class="text-gray-500">Keine Gruppen erfasst.</div>
                @endforelse
            </div>
        </div>
        <div class="col-span-1 md:col-span-1">
            <h2 class="font-medium mb-2">Konten</h2>
            <div class="space-y-1">
                @forelse ($accounts as $a)
                    <div class="px-3 py-2 rounded border">
                        <div class="font-medium">{{ $a->name }} <span class="text-gray-500">({{ $a->currency }})</span></div>
                        <div class="text-gray-500 text-sm">{{ $a->institution?->name }} · {{ $a->group?->name }}</div>
                    </div>
                @empty
                    <div class="text-gray-500">Noch keine Konten.</div>
                @endforelse
            </div>
        </div>
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


