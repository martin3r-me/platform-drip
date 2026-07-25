<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="Kategorien" icon="heroicon-o-tag" />
    </x-slot>

    <x-slot name="sidebar">
        @include('drip::partials.inner-sidebar')
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Drip', 'href' => route('drip.dashboard'), 'icon' => 'chart-bar'],
            ['label' => 'Kategorien'],
        ]" />
    </x-slot>

    <x-ui-page-container width="contained">
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">

            {{-- Links: Kategorien-Baum --}}
            <div class="lg:col-span-2">
                <x-nx-section title="Kategorien" icon="heroicon-o-tag" :hint="$categories->count()">
                    @if ($categories->count() > 0)
                        <x-nx-card flush>
                            <ul class="divide-y divide-[color:var(--nx-line)]">
                                @foreach ($categories as $category)
                                    {{-- Root-Kategorie --}}
                                    <x-nx-list-item :title="$category->name" :meta="$category->transactions_count">
                                        <x-slot name="leading">
                                            <span class="block w-3 h-3 rounded-full" style="background-color: {{ $category->color ?? 'var(--nx-muted)' }}"></span>
                                        </x-slot>
                                        <x-slot name="trailing">
                                            <x-nx-button variant="ghost" size="sm" icon wire:click="edit({{ $category->id }})">
                                                @svg('heroicon-o-pencil-square', 'w-4 h-4')
                                            </x-nx-button>
                                            <x-nx-button variant="ghost" size="sm" icon
                                                         wire:click="delete({{ $category->id }})"
                                                         wire:confirm="Kategorie '{{ $category->name }}' wirklich löschen? Unterkategorien werden zu Root-Kategorien.">
                                                @svg('heroicon-o-trash', 'w-4 h-4')
                                            </x-nx-button>
                                        </x-slot>
                                    </x-nx-list-item>

                                    {{-- Children --}}
                                    @foreach ($category->children as $child)
                                        <x-nx-list-item :title="$child->name" :meta="$child->transactions_count" class="pl-10">
                                            <x-slot name="leading">
                                                <span class="block w-3 h-3 rounded-full" style="background-color: {{ $child->color ?? 'var(--nx-muted)' }}"></span>
                                            </x-slot>
                                            <x-slot name="trailing">
                                                <x-nx-button variant="ghost" size="sm" icon wire:click="edit({{ $child->id }})">
                                                    @svg('heroicon-o-pencil-square', 'w-4 h-4')
                                                </x-nx-button>
                                                <x-nx-button variant="ghost" size="sm" icon
                                                             wire:click="delete({{ $child->id }})"
                                                             wire:confirm="Kategorie '{{ $child->name }}' wirklich löschen?">
                                                    @svg('heroicon-o-trash', 'w-4 h-4')
                                                </x-nx-button>
                                            </x-slot>
                                        </x-nx-list-item>
                                    @endforeach
                                @endforeach
                            </ul>
                        </x-nx-card>
                    @else
                        <x-nx-card>
                            <x-nx-empty icon="heroicon-o-tag">
                                Noch keine Kategorien — erstelle eine, um Transaktionen zu organisieren.
                            </x-nx-empty>
                        </x-nx-card>
                    @endif
                </x-nx-section>
            </div>

            {{-- Rechts: Formular --}}
            <div class="lg:col-span-1">
                <x-nx-section :title="$editingId ? 'Kategorie bearbeiten' : 'Kategorie erstellen'" icon="heroicon-o-plus-circle">
                    <x-nx-card>
                        <form wire:submit="save" class="space-y-4">
                            <x-nx-input-text
                                name="form.name"
                                label="Name"
                                wire:model="form.name"
                                required
                                placeholder="z.B. Lebensmittel" />

                            <x-nx-input-select
                                name="form.color"
                                label="Farbe"
                                wire:model="form.color"
                                nullable
                                nullLabel="Keine Farbe"
                                :options="[
                                    ['value' => '#6B7280', 'label' => 'Grau'],
                                    ['value' => '#EF4444', 'label' => 'Rot'],
                                    ['value' => '#F97316', 'label' => 'Orange'],
                                    ['value' => '#EAB308', 'label' => 'Gelb'],
                                    ['value' => '#22C55E', 'label' => 'Grün'],
                                    ['value' => '#3B82F6', 'label' => 'Blau'],
                                    ['value' => '#6366F1', 'label' => 'Indigo'],
                                    ['value' => '#A855F7', 'label' => 'Lila'],
                                    ['value' => '#EC4899', 'label' => 'Pink'],
                                ]" />

                            <x-nx-input-select
                                name="form.parent_id"
                                label="Übergeordnete Kategorie"
                                wire:model="form.parent_id"
                                nullable
                                nullLabel="Keine (Root-Kategorie)"
                                optionValue="id"
                                optionLabel="name"
                                :options="$rootCategories->reject(fn ($root) => $root->id === $editingId)->values()" />

                            <div class="flex items-center gap-2 pt-2">
                                <x-nx-button type="submit" variant="primary">
                                    @svg('heroicon-o-check', 'w-4 h-4')
                                    {{ $editingId ? 'Speichern' : 'Erstellen' }}
                                </x-nx-button>
                                @if ($editingId)
                                    <x-nx-button type="button" variant="ghost" wire:click="cancel">
                                        Abbrechen
                                    </x-nx-button>
                                @endif
                            </div>
                        </form>
                    </x-nx-card>
                </x-nx-section>
            </div>

        </div>
    </x-ui-page-container>
</x-ui-page>
