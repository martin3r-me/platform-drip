<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="Kategorien" icon="heroicon-o-tag" />
    </x-slot>

    @php
        $dot = fn ($c) => $c ? (str_starts_with($c, '#') ? $c : 'var(--nx-tone-' . $c . ')') : 'var(--nx-tone-slate)';
        $money = fn ($v) => number_format($v, 0, ',', '.') . ' €';
        $groupMeta = ['credit' => ['Einnahmen', 'heroicon-o-arrow-down-left'], 'debit' => ['Ausgaben', 'heroicon-o-arrow-up-right'], 'both' => ['Beides', 'heroicon-o-arrows-right-left']];
    @endphp

    {{-- LINKS: Kategorie-Baum als Navigation --}}
    <x-slot name="sidebar">
        <x-ui-page-sidebar title="Kategorien" icon="heroicon-o-tag" width="w-72" :defaultOpen="true" side="left" storeKey="sidebarOpen">
            <div class="flex h-full flex-col">
                <div class="flex-1 overflow-y-auto p-2">
                    @foreach ($groupMeta as $dir => [$title, $icon])
                        @php $nodes = $groups[$dir] ?? []; @endphp
                        @if (count($nodes) > 0)
                            <div class="px-2 pb-1 pt-3 text-[10px] font-semibold uppercase tracking-wide text-[color:var(--nx-faint)]">{{ $title }}</div>
                            @foreach ($nodes as $node)
                                @php $cat = $node['cat']; @endphp
                                <button type="button" wire:click="selectCategory({{ $cat->id }})"
                                    class="group flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left transition-colors hover:bg-[color:var(--nx-hover)] {{ $selectedCategoryId === $cat->id ? 'bg-[color:var(--nx-hover)]' : '' }}">
                                    <span class="h-2.5 w-2.5 shrink-0 rounded-full" style="background-color: {{ $dot($cat->color) }}"></span>
                                    <span class="min-w-0 flex-1 truncate text-sm text-[color:var(--nx-text)]">{{ $cat->name }}</span>
                                    <span class="shrink-0 text-[11px] tabular-nums text-[color:var(--nx-faint)]">{{ $node['total_cnt'] }}</span>
                                </button>
                                @foreach ($node['children'] as $child)
                                    @php $cc = $child['cat']; @endphp
                                    <button type="button" wire:click="selectCategory({{ $cc->id }})"
                                        class="group flex w-full items-center gap-2 rounded-md py-1.5 pl-6 pr-2 text-left transition-colors hover:bg-[color:var(--nx-hover)] {{ $selectedCategoryId === $cc->id ? 'bg-[color:var(--nx-hover)]' : '' }}">
                                        <span class="h-2 w-2 shrink-0 rounded-full" style="background-color: {{ $dot($cc->color) }}"></span>
                                        <span class="min-w-0 flex-1 truncate text-sm text-[color:var(--nx-muted)]">{{ $cc->name }}</span>
                                        <span class="shrink-0 text-[11px] tabular-nums text-[color:var(--nx-faint)]">{{ $child['cnt'] }}</span>
                                    </button>
                                @endforeach
                            @endforeach
                        @endif
                    @endforeach
                </div>
                <div class="border-t border-[color:var(--nx-line)] p-2">
                    <x-nx-button variant="secondary" class="w-full justify-center" wire:click="create">
                        @svg('heroicon-o-plus', 'w-4 h-4') Neue Kategorie
                    </x-nx-button>
                </div>
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Drip', 'href' => route('drip.dashboard'), 'icon' => 'chart-bar'],
            ['label' => 'Kategorien'],
        ]">
            <x-slot name="left">
                <div class="flex items-center gap-1 rounded-md bg-[color:var(--nx-bg)] p-0.5">
                    @foreach (['total' => 'Gesamt', 'year' => 'Jahr', 'month' => 'Monat'] as $key => $label)
                        <button wire:click="$set('period', '{{ $key }}')"
                            class="rounded px-2.5 py-1 text-xs font-medium transition-colors {{ $period === $key ? 'bg-[color:var(--nx-surface)] text-[color:var(--nx-text)] shadow-sm' : 'text-[color:var(--nx-muted)] hover:text-[color:var(--nx-text)]' }}">
                            {{ $label }}
                        </button>
                    @endforeach
                </div>
            </x-slot>
        </x-ui-page-actionbar>
    </x-slot>

    {{-- MITTE: Transaktionen der gewählten Kategorie --}}
    <x-ui-page-container width="contained">

        @if (! $selected)
            {{-- Startbild: Deckungsgrad --}}
            <x-nx-stat-grid cols="3">
                <x-nx-stat label="Kategorisiert" :value="$coverage['pct'] . ' %'" :hint="$coverage['categorized'] . ' von ' . $coverage['total'] . ' Transaktionen'" icon="heroicon-o-check-circle" />
                <x-nx-stat label="Unkategorisiert" :value="(string) $coverage['uncategorized']" hint="noch offen" icon="heroicon-o-question-mark-circle" />
                <x-nx-stat label="Kategorien" :value="(string) ($rootCategories->count())" hint="Hauptkategorien" icon="heroicon-o-tag" />
            </x-nx-stat-grid>

            <x-nx-empty icon="heroicon-o-cursor-arrow-rays">
                Wähle links eine Kategorie, um ihre Transaktionen zu sehen.
            </x-nx-empty>
        @else
            {{-- Kopf der gewählten Kategorie --}}
            <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-center gap-3">
                    <span class="h-3.5 w-3.5 shrink-0 rounded-full" style="background-color: {{ $dot($selected->color) }}"></span>
                    <h1 class="text-2xl font-semibold tracking-tight text-[color:var(--nx-text)]">{{ $selected->name }}</h1>
                    <span class="text-sm text-[color:var(--nx-muted)]">{{ $selectedCount }} Transaktionen</span>
                    @if ($selected->default_tax_rate !== null)
                        <x-nx-badge variant="neutral">{{ (int) $selected->default_tax_rate }} % USt</x-nx-badge>
                    @endif
                </div>
                <x-nx-button variant="secondary" wire:click="edit({{ $selected->id }})">
                    @svg('heroicon-o-pencil-square', 'w-4 h-4') Bearbeiten
                </x-nx-button>
            </div>

            {{-- Lern-Feedback --}}
            @if($learnResult)
                <x-nx-callout variant="success" icon="heroicon-o-check-circle">{{ $learnResult }}</x-nx-callout>
            @endif
            @if($learnSuggestion)
                <x-nx-callout variant="info" icon="heroicon-o-sparkles" title="Gleiche Gegenpartei zuordnen?">
                    <span class="font-medium">{{ $learnSuggestion['count'] }}</span> weitere unkategorisierte Transaktion(en) von
                    <span class="font-medium">„{{ \Illuminate\Support\Str::limit($learnSuggestion['counterparty'], 40) }}"</span>
                    könnten ebenfalls <span class="font-medium">{{ $learnSuggestion['category_name'] }}</span> sein.
                    <x-slot name="action">
                        <div class="flex items-center gap-2">
                            <x-nx-button variant="primary" size="sm" wire:click="applyLearnToAll">Alle zuordnen</x-nx-button>
                            <x-nx-button variant="secondary" size="sm" wire:click="applyLearnAndRemember">+ Regel merken</x-nx-button>
                            <x-nx-button variant="ghost" size="sm" wire:click="dismissLearn">Verwerfen</x-nx-button>
                        </div>
                    </x-slot>
                </x-nx-callout>
            @endif

            {{-- Transaktionsliste --}}
            @if (count($transactions) > 0)
                <x-nx-card flush>
                    <x-nx-table>
                        <x-nx-table-header>
                            <x-nx-table-header-cell>Datum</x-nx-table-header-cell>
                            <x-nx-table-header-cell>Gegenpartei</x-nx-table-header-cell>
                            <x-nx-table-header-cell>Konto</x-nx-table-header-cell>
                            <x-nx-table-header-cell>Kategorie</x-nx-table-header-cell>
                            <x-nx-table-header-cell align="right">Betrag</x-nx-table-header-cell>
                        </x-nx-table-header>
                        <x-nx-table-body>
                            @foreach ($transactions as $t)
                                <x-nx-table-row wire:key="tx-{{ $t->id }}">
                                    <x-nx-table-cell>
                                        <a href="{{ route('drip.transactions.show', $t) }}" wire:navigate class="text-[color:var(--nx-muted)] hover:text-[color:var(--nx-text)]">
                                            {{ $t->booked_at?->format('d.m.Y') ?? '-' }}
                                        </a>
                                    </x-nx-table-cell>
                                    <x-nx-table-cell>
                                        <span class="{{ $t->is_disregarded ? 'text-[color:var(--nx-faint)] line-through' : 'text-[color:var(--nx-text)]' }}">{{ $t->counterparty_name ?? '(unbekannt)' }}</span>
                                        @if ($t->is_disregarded)
                                            <x-nx-badge variant="warning">nicht berücksichtigt</x-nx-badge>
                                        @endif
                                    </x-nx-table-cell>
                                    <x-nx-table-cell>
                                        <span class="text-[color:var(--nx-muted)]">{{ $t->bankAccount->name ?? '-' }}</span>
                                    </x-nx-table-cell>
                                    <x-nx-table-cell>
                                        <x-nx-input-select size="sm" :options="$categoryOptions" :value="$t->category_id"
                                            wire:change="updateTransactionCategory({{ $t->id }}, $event.target.value)" />
                                    </x-nx-table-cell>
                                    <x-nx-table-cell align="right">
                                        <span class="font-medium tabular-nums {{ $t->is_disregarded ? 'text-[color:var(--nx-faint)] line-through' : ($t->direction === 'credit' ? 'text-green-600' : 'text-red-600') }}">
                                            {{ $t->direction === 'credit' ? '+' : '-' }}{{ number_format(abs((float) $t->amount), 2, ',', '.') }} {{ $t->currency }}
                                        </span>
                                    </x-nx-table-cell>
                                </x-nx-table-row>
                            @endforeach
                        </x-nx-table-body>
                    </x-nx-table>
                </x-nx-card>

                @if ($selectedCount > count($transactions))
                    <div class="mt-4 text-center">
                        <x-nx-button variant="secondary" wire:click="loadMore">Weitere laden ({{ count($transactions) }} / {{ $selectedCount }})</x-nx-button>
                    </div>
                @endif
            @else
                <x-nx-empty icon="heroicon-o-banknotes">Keine Transaktionen in dieser Kategorie.</x-nx-empty>
            @endif
        @endif

    </x-ui-page-container>

    {{-- RECHTS: Edit-Peek-Panel --}}
    <x-slot name="activity">
        <x-ui-page-sidebar title="Kategorie" icon="heroicon-o-pencil-square" width="w-80" side="right" :defaultOpen="false" storeKey="activityOpen">
            <div class="p-4">
                @if (! $panelOpen)
                    <div class="flex flex-col items-center gap-3 py-10 text-center">
                        @svg('heroicon-o-pencil-square', 'w-8 h-8 text-[color:var(--nx-faint)]')
                        <p class="text-sm text-[color:var(--nx-muted)]">„Bearbeiten" öffnet hier das Formular, oder lege eine neue Kategorie an.</p>
                        <x-nx-button variant="secondary" wire:click="create">
                            @svg('heroicon-o-plus', 'w-4 h-4') Neue Kategorie
                        </x-nx-button>
                    </div>
                @else
                    <div class="space-y-4">
                        <div class="text-xs font-medium uppercase tracking-wide text-[color:var(--nx-faint)]">
                            {{ $editingId ? 'Kategorie bearbeiten' : 'Neue Kategorie' }}
                        </div>

                        <x-nx-input-text name="cat_name" label="Name" wire:model="form.name" placeholder="z. B. Software & Tools" required errorKey="form.name" />

                        <div>
                            <div class="mb-1 text-xs font-medium text-[color:var(--nx-text)]">Farbe</div>
                            <div class="flex flex-wrap items-center gap-2">
                                @foreach ($tones as $tone)
                                    <button type="button" wire:click="$set('form.color', '{{ $tone }}')"
                                        title="{{ ucfirst($tone) }}"
                                        class="h-6 w-6 rounded-full transition-transform hover:scale-110 {{ $form['color'] === $tone ? 'ring-2 ring-offset-2 ring-[color:var(--nx-text)] ring-offset-[color:var(--nx-surface)]' : '' }}"
                                        style="background-color: var(--nx-tone-{{ $tone }})"></button>
                                @endforeach
                            </div>
                        </div>

                        <x-nx-input-select name="cat_direction" label="Richtung" :options="$directionOptions" wire:model="form.direction" :value="$form['direction']" errorKey="form.direction" />

                        <x-nx-input-select name="cat_tax" label="USt-Satz" :options="$taxOptions" nullable nullLabel="— kein Satz —" wire:model="form.default_tax_rate" :value="$form['default_tax_rate']" errorKey="form.default_tax_rate" />

                        <x-nx-input-select name="cat_parent" label="Übergeordnete Kategorie"
                            :options="$rootCategories->reject(fn ($c) => $c->id === $editingId)->map(fn ($c) => ['value' => $c->id, 'label' => $c->name])->values()->all()"
                            nullable nullLabel="Keine (Hauptkategorie)" wire:model="form.parent_id" :value="$form['parent_id']" errorKey="form.parent_id" />

                        <div class="flex items-center gap-2 pt-2">
                            <x-nx-button variant="primary" wire:click="save">Speichern</x-nx-button>
                            <x-nx-button variant="ghost" wire:click="closePanel">Abbrechen</x-nx-button>
                            @if ($editingId)
                                <span class="flex-1"></span>
                                <x-nx-button variant="danger" icon wire:click="delete({{ $editingId }})" wire:confirm="Kategorie wirklich löschen? Unterkategorien werden zu Hauptkategorien.">
                                    @svg('heroicon-o-trash', 'w-4 h-4')
                                </x-nx-button>
                            @endif
                        </div>
                    </div>
                @endif
            </div>
        </x-ui-page-sidebar>
    </x-slot>

</x-ui-page>
