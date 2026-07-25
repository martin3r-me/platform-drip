<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="Regeln" icon="heroicon-o-funnel" />
    </x-slot>

    <x-slot name="sidebar">
        @include('drip::partials.inner-sidebar')
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Drip', 'href' => route('drip.dashboard'), 'icon' => 'chart-bar'],
            ['label' => 'Regeln'],
        ]">
            <x-nx-button variant="primary" wire:click="applyAllRules"
                         wire:confirm="Alle aktiven Regeln auf unkategorisierte Transaktionen anwenden?">
                @svg('heroicon-o-bolt', 'w-4 h-4')
                Alle Regeln anwenden
            </x-nx-button>
        </x-ui-page-actionbar>
    </x-slot>

    <x-ui-page-container width="contained">

        {{-- Test/Apply Feedback --}}
        @if($testResult)
            <x-nx-callout variant="info" icon="heroicon-o-beaker">
                {{ $testResult }}
            </x-nx-callout>
        @endif

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">

            {{-- Links: Regeln-Liste --}}
            <div class="lg:col-span-2">
                <x-nx-section icon="heroicon-o-funnel" title="Kategorisierungsregeln" :hint="$rules->count()">
                    @if ($rules->count() > 0)
                        <x-nx-card flush>
                            @foreach ($rules as $rule)
                                @php
                                    $cat = $rule->category;
                                    $matchers = is_array($rule->matchers) ? $rule->matchers : [];
                                @endphp
                                <div class="flex items-start justify-between gap-3 px-4 py-3 {{ !$loop->last ? 'border-b border-[color:var(--nx-line)]' : '' }} {{ $rule->is_active ? '' : 'opacity-50' }}">
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center gap-2 mb-1">
                                            <span class="text-sm font-medium text-[color:var(--nx-text)]">{{ $rule->name }}</span>
                                            @if($cat)
                                                <x-nx-badge variant="neutral">
                                                    <span class="w-1.5 h-1.5 rounded-full" style="background-color: {{ $cat->color ?? 'var(--nx-muted)' }}"></span>
                                                    {{ $cat->name }}
                                                </x-nx-badge>
                                            @endif
                                            @if(($rule->priority ?? 0) > 0)
                                                <span class="text-[11px] tabular-nums text-[color:var(--nx-faint)]" title="Priorität">P{{ $rule->priority }}</span>
                                            @endif
                                            @unless($rule->is_active)
                                                <x-nx-badge variant="warning">inaktiv</x-nx-badge>
                                            @endunless
                                        </div>
                                        <div class="flex flex-wrap gap-1.5">
                                            @foreach($matchers as $m)
                                                <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[11px] bg-[color:var(--nx-hover)] text-[color:var(--nx-muted)]">
                                                    {{ $m['field'] ?? '?' }} {{ $m['op'] ?? '?' }} "{{ Str::limit($m['value'] ?? '', 20) }}"
                                                </span>
                                            @endforeach
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-1 shrink-0">
                                        <x-nx-button variant="ghost" icon wire:click="toggleActive({{ $rule->id }})" title="{{ $rule->is_active ? 'Deaktivieren' : 'Aktivieren' }}">
                                            @svg($rule->is_active ? 'heroicon-o-eye' : 'heroicon-o-eye-slash', 'w-4 h-4')
                                        </x-nx-button>
                                        <x-nx-button variant="ghost" icon wire:click="testRule({{ $rule->id }})" title="Testen">
                                            @svg('heroicon-o-beaker', 'w-4 h-4')
                                        </x-nx-button>
                                        <x-nx-button variant="ghost" icon wire:click="applyRule({{ $rule->id }})"
                                                     wire:confirm="Regel auf alle unkategorisierten Transaktionen anwenden?" title="Anwenden">
                                            @svg('heroicon-o-play', 'w-4 h-4')
                                        </x-nx-button>
                                        <x-nx-button variant="ghost" icon wire:click="edit({{ $rule->id }})" title="Bearbeiten">
                                            @svg('heroicon-o-pencil-square', 'w-4 h-4')
                                        </x-nx-button>
                                        <x-nx-button variant="danger" icon wire:click="delete({{ $rule->id }})"
                                                     wire:confirm="Regel '{{ $rule->name }}' wirklich löschen?" title="Löschen">
                                            @svg('heroicon-o-trash', 'w-4 h-4')
                                        </x-nx-button>
                                    </div>
                                </div>
                            @endforeach
                        </x-nx-card>
                    @else
                        <x-nx-card>
                            <x-nx-empty icon="heroicon-o-funnel">
                                Noch keine Regeln — erstelle eine Regel, um Transaktionen automatisch zu kategorisieren.
                            </x-nx-empty>
                        </x-nx-card>
                    @endif
                </x-nx-section>
            </div>

            {{-- Rechts: Formular --}}
            <div class="lg:col-span-1">
                <x-nx-card>
                    <x-nx-section :title="$editingId ? 'Regel bearbeiten' : 'Regel erstellen'" icon="heroicon-o-plus-circle">
                        <form wire:submit="save" class="space-y-4">
                            <x-nx-input-text label="Name" errorKey="formName" wire:model="formName" required
                                             placeholder="z.B. DKV Tankkarte" />

                            <x-nx-input-select label="Kategorie" errorKey="formCategoryId" wire:model="formCategoryId" required
                                               nullable nullLabel="Kategorie wählen..."
                                               :options="$categories" optionValue="id" optionLabel="name" />

                            {{-- Matcher Builder --}}
                            <div>
                                <label class="mb-2 block text-xs font-medium text-[color:var(--nx-text)]">Bedingungen (alle müssen zutreffen)</label>

                                <div class="space-y-2">
                                    @foreach ($formMatchers as $index => $matcher)
                                        <div class="flex items-start gap-1.5 rounded-md border border-[color:var(--nx-line)] bg-[color:var(--nx-hover)] p-2" wire:key="matcher-{{ $index }}">
                                            <x-nx-input-select size="sm" wire:model="formMatchers.{{ $index }}.field" :options="[
                                                ['value' => 'counterparty_name', 'label' => 'Gegenpartei'],
                                                ['value' => 'creditor_name', 'label' => 'Kreditor'],
                                                ['value' => 'reference', 'label' => 'Referenz'],
                                                ['value' => 'remittance_information', 'label' => 'Verwendungszweck'],
                                                ['value' => 'counterparty_iban', 'label' => 'IBAN'],
                                                ['value' => 'amount', 'label' => 'Betrag'],
                                            ]" />
                                            <x-nx-input-select size="sm" wire:model="formMatchers.{{ $index }}.op" :options="[
                                                ['value' => 'contains', 'label' => 'enthält'],
                                                ['value' => 'starts_with', 'label' => 'beginnt mit'],
                                                ['value' => 'equals', 'label' => 'ist gleich'],
                                                ['value' => 'gte', 'label' => '≥'],
                                                ['value' => 'lte', 'label' => '≤'],
                                            ]" />
                                            <div class="flex-1 min-w-0">
                                                <x-nx-input-text size="sm" wire:model="formMatchers.{{ $index }}.value" placeholder="Wert..." />
                                            </div>
                                            <x-nx-button variant="ghost" icon wire:click="removeMatcher({{ $index }})">
                                                @svg('heroicon-o-x-mark', 'w-3.5 h-3.5')
                                            </x-nx-button>
                                        </div>
                                    @endforeach
                                </div>

                                <x-nx-button variant="ghost" size="sm" wire:click="addMatcher" class="mt-2">
                                    @svg('heroicon-o-plus', 'w-3.5 h-3.5')
                                    Bedingung hinzufügen
                                </x-nx-button>

                                @error('formMatchers')
                                    <p class="mt-1 text-xs text-[color:var(--nx-danger)]">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="flex items-end gap-3">
                                <div class="w-28">
                                    <x-nx-input-number label="Priorität" errorKey="formPriority" wire:model="formPriority" min="0" max="1000" hint="höher zuerst" />
                                </div>
                                <label class="flex items-center gap-2 pb-2 text-sm text-[color:var(--nx-text)]">
                                    <input type="checkbox" wire:model="formIsActive" class="rounded border-[color:var(--nx-line-strong)]">
                                    Aktiv
                                </label>
                            </div>

                            {{-- Buttons --}}
                            <div class="flex items-center gap-2 pt-2">
                                <x-nx-button type="submit" variant="primary">
                                    @svg('heroicon-o-check', 'w-4 h-4')
                                    {{ $editingId ? 'Speichern' : 'Erstellen' }}
                                </x-nx-button>
                                @if ($editingId)
                                    <x-nx-button variant="ghost" wire:click="cancel">Abbrechen</x-nx-button>
                                @endif
                            </div>
                        </form>
                    </x-nx-section>
                </x-nx-card>
            </div>

        </div>
    </x-ui-page-container>
</x-ui-page>
