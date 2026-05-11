<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="Budgets" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Drip', 'href' => route('drip.dashboard'), 'icon' => 'chart-bar'],
            ['label' => 'Budgets'],
        ]" />
    </x-slot>

    <x-ui-page-container>
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

            {{-- Links: Budget-Liste --}}
            <div class="lg:col-span-2">
                @if (count($budgets) > 0)
                    <div class="bg-white rounded-lg border border-gray-200">
                        @foreach ($budgets as $index => $b)
                            <div class="flex items-center justify-between px-4 py-3 {{ $index < count($budgets) - 1 ? 'border-b border-gray-100' : '' }} {{ !$b['is_active'] ? 'opacity-50' : '' }}">
                                <div class="flex-1 min-w-0 mr-4">
                                    <div class="flex items-center gap-2 mb-1.5">
                                        <div class="w-2.5 h-2.5 rounded-full shrink-0" style="background-color: {{ $b['category_color'] }}"></div>
                                        <span class="text-sm font-medium text-gray-900 truncate">{{ $b['name'] }}</span>
                                        <span class="inline-flex items-center px-1.5 py-0.5 rounded-full text-[10px] font-medium bg-gray-100 text-gray-500 shrink-0">
                                            {{ match($b['frequency']) { 'weekly' => 'W', 'monthly' => 'M', 'quarterly' => 'Q', 'yearly' => 'J', default => $b['frequency'] } }}
                                        </span>
                                        <span class="inline-flex items-center px-1.5 py-0.5 rounded-full text-[10px] font-medium {{ $b['direction'] === 'debit' ? 'bg-red-50 text-red-600' : 'bg-green-50 text-green-600' }} shrink-0">
                                            {{ $b['direction'] === 'debit' ? 'Ausgabe' : 'Einnahme' }}
                                        </span>
                                    </div>
                                    {{-- Progress bar --}}
                                    <div class="flex items-center gap-3">
                                        <div class="flex-1 h-2 bg-gray-100 rounded-full overflow-hidden">
                                            @php
                                                $barColor = $b['percent'] <= 100 ? 'bg-green-500' : ($b['percent'] <= 120 ? 'bg-yellow-500' : 'bg-red-500');
                                                $barWidth = min($b['percent'], 100);
                                            @endphp
                                            <div class="{{ $barColor }} h-2 rounded-full transition-all" style="width: {{ $barWidth }}%"></div>
                                        </div>
                                        <span class="text-[12px] tabular-nums text-gray-600 shrink-0 w-36 text-right">
                                            {{ number_format($b['actual'], 2, ',', '.') }} / {{ number_format($b['budget'], 2, ',', '.') }} &euro;
                                        </span>
                                    </div>
                                </div>
                                <div class="flex items-center gap-1 shrink-0">
                                    <button type="button" wire:click="edit({{ $b['id'] }})"
                                            class="p-1.5 rounded-md text-gray-400 hover:text-gray-600 hover:bg-gray-100 transition-colors">
                                        @svg('heroicon-o-pencil-square', 'w-4 h-4')
                                    </button>
                                    <button type="button" wire:click="delete({{ $b['id'] }})"
                                            wire:confirm="Budget '{{ $b['name'] }}' wirklich loeschen?"
                                            class="p-1.5 rounded-md text-gray-400 hover:text-red-600 hover:bg-red-50 transition-colors">
                                        @svg('heroicon-o-trash', 'w-4 h-4')
                                    </button>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="bg-white rounded-lg border border-gray-200 p-12 text-center">
                        <div class="text-gray-400 mb-4">
                            @svg('heroicon-o-calculator', 'w-12 h-12 mx-auto')
                        </div>
                        <h3 class="text-sm font-semibold text-gray-900 mb-1">Noch keine Budgets</h3>
                        <p class="text-[13px] text-gray-500">Erstelle ein Budget, um Soll/Ist-Vergleiche zu sehen.</p>
                    </div>
                @endif
            </div>

            {{-- Rechts: Formular --}}
            <div class="lg:col-span-1">
                <div class="bg-white rounded-lg border border-gray-200 p-5">
                    <h3 class="text-sm font-semibold text-gray-900 mb-4">
                        {{ $editingId ? 'Budget bearbeiten' : 'Budget erstellen' }}
                    </h3>

                    <form wire:submit="save" class="space-y-4">
                        {{-- Name --}}
                        <div>
                            <label for="budget-name" class="block text-[13px] font-medium text-gray-700 mb-1">Name</label>
                            <input type="text" id="budget-name" wire:model="formName" required
                                   class="w-full px-3 py-1.5 rounded-md border border-gray-200 text-[13px] text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-1 focus:ring-blue-500 focus:border-blue-500"
                                   placeholder="z.B. Tankkarte DKV">
                            @error('formName')
                                <p class="mt-1 text-[11px] text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        {{-- Kategorie --}}
                        <div>
                            <label for="budget-category" class="block text-[13px] font-medium text-gray-700 mb-1">Kategorie</label>
                            <select id="budget-category" wire:model="formCategoryId"
                                    class="w-full px-3 py-1.5 rounded-md border border-gray-200 text-[13px] text-gray-900 focus:outline-none focus:ring-1 focus:ring-blue-500 focus:border-blue-500">
                                <option value="">Keine Kategorie</option>
                                @foreach ($categories as $cat)
                                    <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                                @endforeach
                            </select>
                            @error('formCategoryId')
                                <p class="mt-1 text-[11px] text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        {{-- Richtung --}}
                        <div>
                            <label for="budget-direction" class="block text-[13px] font-medium text-gray-700 mb-1">Richtung</label>
                            <select id="budget-direction" wire:model="formDirection"
                                    class="w-full px-3 py-1.5 rounded-md border border-gray-200 text-[13px] text-gray-900 focus:outline-none focus:ring-1 focus:ring-blue-500 focus:border-blue-500">
                                <option value="debit">Ausgabe</option>
                                <option value="credit">Einnahme</option>
                            </select>
                            @error('formDirection')
                                <p class="mt-1 text-[11px] text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        {{-- Betrag --}}
                        <div>
                            <label for="budget-amount" class="block text-[13px] font-medium text-gray-700 mb-1">Betrag</label>
                            <input type="number" id="budget-amount" wire:model="formAmount" required step="0.01" min="0.01"
                                   class="w-full px-3 py-1.5 rounded-md border border-gray-200 text-[13px] text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-1 focus:ring-blue-500 focus:border-blue-500"
                                   placeholder="500.00">
                            @error('formAmount')
                                <p class="mt-1 text-[11px] text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        {{-- Frequenz --}}
                        <div>
                            <label for="budget-frequency" class="block text-[13px] font-medium text-gray-700 mb-1">Frequenz</label>
                            <select id="budget-frequency" wire:model="formFrequency"
                                    class="w-full px-3 py-1.5 rounded-md border border-gray-200 text-[13px] text-gray-900 focus:outline-none focus:ring-1 focus:ring-blue-500 focus:border-blue-500">
                                <option value="weekly">Woechentlich</option>
                                <option value="monthly">Monatlich</option>
                                <option value="quarterly">Quartalsweise</option>
                                <option value="yearly">Jaehrlich</option>
                            </select>
                            @error('formFrequency')
                                <p class="mt-1 text-[11px] text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        {{-- Tag im Monat --}}
                        <div>
                            <label for="budget-day" class="block text-[13px] font-medium text-gray-700 mb-1">Tag im Monat (optional)</label>
                            <input type="number" id="budget-day" wire:model="formDayOfMonth" min="1" max="31"
                                   class="w-full px-3 py-1.5 rounded-md border border-gray-200 text-[13px] text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-1 focus:ring-blue-500 focus:border-blue-500"
                                   placeholder="z.B. 15">
                            @error('formDayOfMonth')
                                <p class="mt-1 text-[11px] text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        {{-- Aktiv --}}
                        <div class="flex items-center gap-2">
                            <input type="checkbox" id="budget-active" wire:model="formIsActive"
                                   class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                            <label for="budget-active" class="text-[13px] text-gray-700">Aktiv</label>
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
