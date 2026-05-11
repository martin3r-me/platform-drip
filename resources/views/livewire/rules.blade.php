<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="Regeln" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Drip', 'href' => route('drip.dashboard'), 'icon' => 'chart-bar'],
            ['label' => 'Regeln'],
        ]" />
    </x-slot>

    <x-ui-page-container>

        {{-- Test/Apply Feedback --}}
        @if($testResult)
            <div class="mb-4 px-4 py-3 rounded-xl bg-blue-50 border border-blue-200 text-[13px] text-blue-800">
                {{ $testResult }}
            </div>
        @endif

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">

            {{-- Links: Regeln-Liste --}}
            <div class="lg:col-span-2">
                @if ($rules->count() > 0)
                    <div class="bg-white rounded-xl border border-gray-200">
                        @foreach ($rules as $rule)
                            @php
                                $cat = $rule->category;
                                $matchers = is_array($rule->matchers) ? $rule->matchers : [];
                                $defaults = is_array($rule->defaults) ? $rule->defaults : [];
                            @endphp
                            <div class="px-5 py-3.5 {{ !$loop->last ? 'border-b border-gray-100' : '' }}">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center gap-2 mb-1">
                                            <span class="text-sm font-medium text-gray-900">{{ $rule->name }}</span>
                                            @if($cat)
                                                <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded-full text-[11px] font-medium"
                                                      style="background-color: {{ $cat->color ?? '#6B7280' }}20; color: {{ $cat->color ?? '#6B7280' }}">
                                                    <span class="w-1.5 h-1.5 rounded-full" style="background-color: {{ $cat->color ?? '#6B7280' }}"></span>
                                                    {{ $cat->name }}
                                                </span>
                                            @endif
                                        </div>
                                        <div class="text-[11px] text-gray-500 space-x-2">
                                            @foreach($matchers as $m)
                                                <span class="inline-flex items-center px-1.5 py-0.5 rounded bg-gray-100 text-gray-600">
                                                    {{ $m['field'] ?? '?' }} {{ $m['op'] ?? '?' }} "{{ Str::limit($m['value'] ?? '', 20) }}"
                                                </span>
                                            @endforeach
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-1 shrink-0">
                                        <button type="button" wire:click="testRule({{ $rule->id }})"
                                                class="p-1.5 rounded-md text-gray-400 hover:text-blue-600 hover:bg-blue-50 transition-colors"
                                                title="Testen">
                                            @svg('heroicon-o-beaker', 'w-4 h-4')
                                        </button>
                                        <button type="button" wire:click="applyRule({{ $rule->id }})"
                                                wire:confirm="Regel auf alle unkategorisierten Transaktionen anwenden?"
                                                class="p-1.5 rounded-md text-gray-400 hover:text-green-600 hover:bg-green-50 transition-colors"
                                                title="Anwenden">
                                            @svg('heroicon-o-play', 'w-4 h-4')
                                        </button>
                                        <button type="button" wire:click="edit({{ $rule->id }})"
                                                class="p-1.5 rounded-md text-gray-400 hover:text-gray-600 hover:bg-gray-100 transition-colors"
                                                title="Bearbeiten">
                                            @svg('heroicon-o-pencil-square', 'w-4 h-4')
                                        </button>
                                        <button type="button" wire:click="delete({{ $rule->id }})"
                                                wire:confirm="Regel '{{ $rule->name }}' wirklich löschen?"
                                                class="p-1.5 rounded-md text-gray-400 hover:text-red-600 hover:bg-red-50 transition-colors"
                                                title="Löschen">
                                            @svg('heroicon-o-trash', 'w-4 h-4')
                                        </button>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="bg-white rounded-xl border border-gray-200 p-12 text-center">
                        <div class="text-gray-400 mb-4">
                            @svg('heroicon-o-funnel', 'w-12 h-12 mx-auto')
                        </div>
                        <h3 class="text-base font-semibold text-gray-900 mb-1">Noch keine Regeln</h3>
                        <p class="text-sm text-gray-500">Erstelle eine Regel, um Transaktionen automatisch zu kategorisieren.</p>
                    </div>
                @endif
            </div>

            {{-- Rechts: Formular --}}
            <div class="lg:col-span-1">
                <div class="bg-white rounded-xl border border-gray-200 p-5">
                    <h3 class="text-base font-semibold text-gray-900 mb-4">
                        {{ $editingId ? 'Regel bearbeiten' : 'Regel erstellen' }}
                    </h3>

                    <form wire:submit="save" class="space-y-4">
                        {{-- Name --}}
                        <div>
                            <label for="rule-name" class="block text-[13px] font-medium text-gray-700 mb-1">Name</label>
                            <input type="text" id="rule-name" wire:model="formName" required
                                   class="w-full px-3 py-1.5 rounded-md border border-gray-200 text-[13px] text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-1 focus:ring-blue-500 focus:border-blue-500"
                                   placeholder="z.B. DKV Tankkarte">
                            @error('formName')
                                <p class="mt-1 text-[11px] text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        {{-- Kategorie --}}
                        <div>
                            <label for="rule-category" class="block text-[13px] font-medium text-gray-700 mb-1">Kategorie</label>
                            <select id="rule-category" wire:model="formCategoryId" required
                                    class="w-full px-3 py-1.5 rounded-md border border-gray-200 text-[13px] text-gray-900 focus:outline-none focus:ring-1 focus:ring-blue-500 focus:border-blue-500">
                                <option value="">Kategorie wählen...</option>
                                @foreach ($categories as $cat)
                                    <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                                @endforeach
                            </select>
                            @error('formCategoryId')
                                <p class="mt-1 text-[11px] text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        {{-- Matcher Builder --}}
                        <div>
                            <label class="block text-[13px] font-medium text-gray-700 mb-2">Bedingungen (alle müssen zutreffen)</label>

                            <div class="space-y-2">
                                @foreach ($formMatchers as $index => $matcher)
                                    <div class="flex items-start gap-1.5 p-2 rounded-md bg-gray-50 border border-gray-100" wire:key="matcher-{{ $index }}">
                                        <select wire:model="formMatchers.{{ $index }}.field"
                                                class="px-2 py-1 rounded border border-gray-200 text-[12px] text-gray-700 focus:outline-none focus:ring-1 focus:ring-blue-500">
                                            <option value="counterparty_name">Gegenpartei</option>
                                            <option value="creditor_name">Kreditor</option>
                                            <option value="reference">Referenz</option>
                                            <option value="remittance_information">Verwendungszweck</option>
                                            <option value="counterparty_iban">IBAN</option>
                                            <option value="amount">Betrag</option>
                                        </select>
                                        <select wire:model="formMatchers.{{ $index }}.op"
                                                class="px-2 py-1 rounded border border-gray-200 text-[12px] text-gray-700 focus:outline-none focus:ring-1 focus:ring-blue-500">
                                            <option value="contains">enthält</option>
                                            <option value="starts_with">beginnt mit</option>
                                            <option value="equals">ist gleich</option>
                                            <option value="gte">≥</option>
                                            <option value="lte">≤</option>
                                        </select>
                                        <input type="text" wire:model="formMatchers.{{ $index }}.value"
                                               class="flex-1 min-w-0 px-2 py-1 rounded border border-gray-200 text-[12px] text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-1 focus:ring-blue-500"
                                               placeholder="Wert...">
                                        <button type="button" wire:click="removeMatcher({{ $index }})"
                                                class="p-1 rounded text-gray-400 hover:text-red-600 hover:bg-red-50 transition-colors shrink-0">
                                            @svg('heroicon-o-x-mark', 'w-3.5 h-3.5')
                                        </button>
                                    </div>
                                @endforeach
                            </div>

                            <button type="button" wire:click="addMatcher"
                                    class="mt-2 inline-flex items-center gap-1 px-2.5 py-1 rounded-md border border-dashed border-gray-300 text-[12px] text-gray-500 hover:text-gray-700 hover:border-gray-400 transition-colors">
                                @svg('heroicon-o-plus', 'w-3.5 h-3.5')
                                Bedingung hinzufügen
                            </button>

                            @error('formMatchers')
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
