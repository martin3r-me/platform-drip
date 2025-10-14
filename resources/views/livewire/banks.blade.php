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

    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <div class="col-span-1">
            <h2 class="font-medium mb-2">Banken</h2>
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


