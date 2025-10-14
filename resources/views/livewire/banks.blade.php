<div class="space-y-8">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold">Banken & Konten</h1>
        <div class="flex items-center gap-2">
            @button(['wire:click' => 'openInstitutionModal']) @svg('heroicon-o-plus', 'w-4 h-4') <span>Bank hinzufügen</span> @endbutton
            @button(['wire:click' => 'openGroupModal']) @svg('heroicon-o-plus', 'w-4 h-4') <span>Gruppe hinzufügen</span> @endbutton
            @button(['wire:click' => 'openAccountModal']) @svg('heroicon-o-plus', 'w-4 h-4') <span>Konto hinzufügen</span> @endbutton
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

    <x-ui-modal wire:model="showInstitutionModal">
        <x-slot:title>Bank hinzufügen</x-slot:title>
        <div class="space-y-4">
            <x-ui-input-text label="Name" wire:model.defer="institutionForm.name" />
            <x-ui-input-text label="Land (ISO-2)" wire:model.defer="institutionForm.country" />
            <x-ui-input-text label="Externe ID" wire:model.defer="institutionForm.external_id" />
        </div>
        <x-slot:footer>
            @button(['wire:click' => 'saveInstitution']) Speichern @endbutton
        </x-slot:footer>
    </x-ui-modal>

    <x-ui-modal wire:model="showGroupModal">
        <x-slot:title>Gruppe hinzufügen</x-slot:title>
        <div class="space-y-4">
            <x-ui-input-text label="Name" wire:model.defer="groupForm.name" />
            <x-ui-input-text label="Farbe" wire:model.defer="groupForm.color" />
        </div>
        <x-slot:footer>
            @button(['wire:click' => 'saveGroup']) Speichern @endbutton
        </x-slot:footer>
    </x-ui-modal>

    <x-ui-modal wire:model="showAccountModal">
        <x-slot:title>Konto hinzufügen</x-slot:title>
        <div class="space-y-4">
            <x-ui-input-text label="Name" wire:model.defer="accountForm.name" />
            <x-ui-input-text label="IBAN" wire:model.defer="accountForm.iban" />
            <x-ui-input-text label="Währung" wire:model.defer="accountForm.currency" />
            <x-ui-input-select label="Bank" wire:model.defer="accountForm.institution_id">
                <option value="">—</option>
                @foreach ($institutions as $i)
                    <option value="{{ $i->id }}">{{ $i->name }}</option>
                @endforeach
            </x-ui-input-select>
            <x-ui-input-select label="Gruppe" wire:model.defer="accountForm.group_id">
                <option value="">—</option>
                @foreach ($groups as $g)
                    <option value="{{ $g->id }}">{{ $g->name }}</option>
                @endforeach
            </x-ui-input-select>
        </div>
        <x-slot:footer>
            @button(['wire:click' => 'saveAccount']) Speichern @endbutton
        </x-slot:footer>
    </x-ui-modal>
</div>


