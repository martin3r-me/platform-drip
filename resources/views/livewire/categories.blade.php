<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="Kategorien" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Drip', 'href' => route('drip.dashboard'), 'icon' => 'chart-bar'],
            ['label' => 'Kategorien'],
        ]" />
    </x-slot>

    <x-ui-page-container>
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">

            {{-- Links: Kategorien-Baum --}}
            <div class="lg:col-span-2">
                @if ($categories->count() > 0)
                    <div class="bg-white rounded-2xl shadow-sm overflow-hidden">
                        @foreach ($categories as $category)
                            {{-- Root-Kategorie --}}
                            <div class="flex items-center justify-between px-6 py-4 {{ !$loop->last ? 'border-b border-gray-100' : '' }}">
                                <div class="flex items-center gap-3">
                                    <div class="w-3 h-3 rounded-full" style="background-color: {{ $category->color ?? '#6B7280' }}"></div>
                                    <span class="text-sm font-medium text-gray-900">{{ $category->name }}</span>
                                    <span class="inline-flex items-center px-1.5 py-0.5 rounded-full text-[11px] font-medium bg-gray-100 text-gray-600">
                                        {{ $category->transactions_count }}
                                    </span>
                                </div>
                                <div class="flex items-center gap-1">
                                    <button type="button" wire:click="edit({{ $category->id }})"
                                            class="p-1.5 rounded-md text-gray-400 hover:text-gray-600 hover:bg-gray-100 transition-colors">
                                        @svg('heroicon-o-pencil-square', 'w-4 h-4')
                                    </button>
                                    <button type="button" wire:click="delete({{ $category->id }})"
                                            wire:confirm="Kategorie '{{ $category->name }}' wirklich löschen? Unterkategorien werden zu Root-Kategorien."
                                            class="p-1.5 rounded-md text-gray-400 hover:text-red-600 hover:bg-red-50 transition-colors">
                                        @svg('heroicon-o-trash', 'w-4 h-4')
                                    </button>
                                </div>
                            </div>

                            {{-- Children --}}
                            @foreach ($category->children as $child)
                                <div class="flex items-center justify-between px-6 py-4 pl-14 {{ !($loop->parent->last && $loop->last) ? 'border-b border-gray-100' : '' }} bg-gray-50/50">
                                    <div class="flex items-center gap-3">
                                        <div class="w-3 h-3 rounded-full" style="background-color: {{ $child->color ?? '#6B7280' }}"></div>
                                        <span class="text-sm text-gray-700">{{ $child->name }}</span>
                                        <span class="inline-flex items-center px-1.5 py-0.5 rounded-full text-[11px] font-medium bg-gray-100 text-gray-500">
                                            {{ $child->transactions_count }}
                                        </span>
                                    </div>
                                    <div class="flex items-center gap-1">
                                        <button type="button" wire:click="edit({{ $child->id }})"
                                                class="p-1.5 rounded-md text-gray-400 hover:text-gray-600 hover:bg-gray-100 transition-colors">
                                            @svg('heroicon-o-pencil-square', 'w-4 h-4')
                                        </button>
                                        <button type="button" wire:click="delete({{ $child->id }})"
                                                wire:confirm="Kategorie '{{ $child->name }}' wirklich löschen?"
                                                class="p-1.5 rounded-md text-gray-400 hover:text-red-600 hover:bg-red-50 transition-colors">
                                            @svg('heroicon-o-trash', 'w-4 h-4')
                                        </button>
                                    </div>
                                </div>
                            @endforeach
                        @endforeach
                    </div>
                @else
                    <div class="bg-white rounded-2xl shadow-sm p-12 text-center">
                        <div class="text-gray-400 mb-4">
                            @svg('heroicon-o-tag', 'w-12 h-12 mx-auto')
                        </div>
                        <h3 class="text-xl font-bold text-gray-900 mb-1">Noch keine Kategorien</h3>
                        <p class="text-[13px] text-gray-500">Erstelle eine Kategorie, um Transaktionen zu organisieren.</p>
                    </div>
                @endif
            </div>

            {{-- Rechts: Formular --}}
            <div class="lg:col-span-1">
                <div class="bg-white rounded-2xl shadow-sm p-6">
                    <h3 class="text-xl font-bold text-gray-900 mb-4">
                        {{ $editingId ? 'Kategorie bearbeiten' : 'Kategorie erstellen' }}
                    </h3>

                    <form wire:submit="save" class="space-y-4">
                        {{-- Name --}}
                        <div>
                            <label for="category-name" class="block text-[13px] font-medium text-gray-700 mb-1">Name</label>
                            <input type="text" id="category-name" wire:model="form.name" required
                                   class="w-full px-3 py-1.5 rounded-md border border-gray-200 text-[13px] text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-1 focus:ring-blue-500 focus:border-blue-500"
                                   placeholder="z.B. Lebensmittel">
                            @error('form.name')
                                <p class="mt-1 text-[11px] text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        {{-- Farbe --}}
                        <div>
                            <label for="category-color" class="block text-[13px] font-medium text-gray-700 mb-1">Farbe</label>
                            <select id="category-color" wire:model="form.color"
                                    class="w-full px-3 py-1.5 rounded-md border border-gray-200 text-[13px] text-gray-900 focus:outline-none focus:ring-1 focus:ring-blue-500 focus:border-blue-500">
                                <option value="">Keine Farbe</option>
                                <option value="#6B7280">Grau</option>
                                <option value="#EF4444">Rot</option>
                                <option value="#F97316">Orange</option>
                                <option value="#EAB308">Gelb</option>
                                <option value="#22C55E">Grün</option>
                                <option value="#3B82F6">Blau</option>
                                <option value="#6366F1">Indigo</option>
                                <option value="#A855F7">Lila</option>
                                <option value="#EC4899">Pink</option>
                            </select>
                            @error('form.color')
                                <p class="mt-1 text-[11px] text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        {{-- Parent --}}
                        <div>
                            <label for="category-parent" class="block text-[13px] font-medium text-gray-700 mb-1">Übergeordnete Kategorie</label>
                            <select id="category-parent" wire:model="form.parent_id"
                                    class="w-full px-3 py-1.5 rounded-md border border-gray-200 text-[13px] text-gray-900 focus:outline-none focus:ring-1 focus:ring-blue-500 focus:border-blue-500">
                                <option value="">Keine (Root-Kategorie)</option>
                                @foreach ($rootCategories as $root)
                                    @if ($root->id !== $editingId)
                                        <option value="{{ $root->id }}">{{ $root->name }}</option>
                                    @endif
                                @endforeach
                            </select>
                            @error('form.parent_id')
                                <p class="mt-1 text-[11px] text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        {{-- Buttons --}}
                        <div class="flex items-center gap-2 pt-2">
                            <button type="submit"
                                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md bg-green-600 text-white text-[13px] font-medium hover:bg-green-700 transition-colors">
                                @svg('heroicon-o-check', 'w-4 h-4')
                                {{ $editingId ? 'Speichern' : 'Erstellen' }}
                            </button>
                            @if ($editingId)
                                <button type="button" wire:click="cancel"
                                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md border border-gray-200 text-gray-700 text-[13px] font-medium hover:bg-gray-50 transition-colors">
                                    Abbrechen
                                </button>
                            @endif
                        </div>
                    </form>
                </div>
            </div>

        </div>
    </x-ui-page-container>
</x-ui-page>
