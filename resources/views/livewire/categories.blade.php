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

            <x-nx-button variant="primary" wire:click="create">
                @svg('heroicon-o-plus', 'w-4 h-4')
                Neue Kategorie
            </x-nx-button>
        </x-ui-page-actionbar>
    </x-slot>

    @php
        $dot = fn ($c) => $c ? (str_starts_with($c, '#') ? $c : 'var(--nx-tone-' . $c . ')') : 'var(--nx-tone-slate)';
        $money = fn ($v) => number_format($v, 0, ',', '.') . ' €';
        $groupMeta = ['credit' => ['Einnahmen', 'heroicon-o-arrow-down-left'], 'debit' => ['Ausgaben', 'heroicon-o-arrow-up-right'], 'both' => ['Beides', 'heroicon-o-arrows-right-left']];
    @endphp

    <x-ui-page-container width="contained">

        {{-- Deckungsgrad --}}
        <x-nx-stat-grid cols="3">
            <x-nx-stat label="Kategorisiert" :value="$coverage['pct'] . ' %'" :hint="$coverage['categorized'] . ' von ' . $coverage['total'] . ' Transaktionen'" icon="heroicon-o-check-circle" />
            <x-nx-stat label="Unkategorisiert" :value="(string) $coverage['uncategorized']" hint="noch offen" icon="heroicon-o-question-mark-circle" />
            <x-nx-stat label="Kategorien" :value="(string) ($rootCategories->count())" hint="Hauptkategorien" icon="heroicon-o-tag" />
        </x-nx-stat-grid>

        {{-- Gruppen nach Richtung --}}
        @php $hasAny = collect($groups)->flatten(1)->isNotEmpty(); @endphp

        @if (! $hasAny)
            <x-nx-empty icon="heroicon-o-tag">
                Noch keine Kategorien angelegt.
                <x-slot name="action">
                    <x-nx-button variant="primary" wire:click="create">Erste Kategorie anlegen</x-nx-button>
                </x-slot>
            </x-nx-empty>
        @endif

        @foreach ($groupMeta as $dir => [$title, $icon])
            @php $nodes = $groups[$dir] ?? []; @endphp
            @if (count($nodes) > 0)
                @php $groupVol = collect($nodes)->sum('total_vol'); @endphp
                <x-nx-section :title="$title" :icon="$icon" :hint="$money($groupVol)">
                    <x-nx-card flush>
                        <ul class="divide-y divide-[color:var(--nx-line)]">
                            @foreach ($nodes as $node)
                                @php $cat = $node['cat']; @endphp
                                {{-- Hauptkategorie --}}
                                <li>
                                    <button type="button" wire:click="edit({{ $cat->id }})"
                                        class="group flex w-full items-center gap-3 px-4 py-2.5 text-left transition-colors hover:bg-[color:var(--nx-hover)] {{ $editingId === $cat->id ? 'bg-[color:var(--nx-hover)]' : '' }}">
                                        <span class="h-2.5 w-2.5 shrink-0 rounded-full" style="background-color: {{ $dot($cat->color) }}"></span>
                                        <span class="min-w-0 flex-1 truncate text-sm font-medium text-[color:var(--nx-text)]">{{ $cat->name }}</span>
                                        @if ($cat->default_tax_rate !== null)
                                            <x-nx-badge variant="neutral">{{ (int) $cat->default_tax_rate }} % USt</x-nx-badge>
                                        @endif
                                        <span class="shrink-0 text-xs tabular-nums text-[color:var(--nx-faint)]">{{ $node['total_cnt'] }}×</span>
                                        <span class="w-28 shrink-0 text-right text-sm font-medium tabular-nums text-[color:var(--nx-text)]">{{ $money($node['total_vol']) }}</span>
                                    </button>
                                </li>
                                {{-- Unterkategorien --}}
                                @foreach ($node['children'] as $child)
                                    @php $cc = $child['cat']; @endphp
                                    <li>
                                        <button type="button" wire:click="edit({{ $cc->id }})"
                                            class="group flex w-full items-center gap-3 py-2 pl-10 pr-4 text-left transition-colors hover:bg-[color:var(--nx-hover)] {{ $editingId === $cc->id ? 'bg-[color:var(--nx-hover)]' : '' }}">
                                            <span class="h-2 w-2 shrink-0 rounded-full" style="background-color: {{ $dot($cc->color) }}"></span>
                                            <span class="min-w-0 flex-1 truncate text-sm text-[color:var(--nx-muted)]">{{ $cc->name }}</span>
                                            @if ($cc->default_tax_rate !== null)
                                                <x-nx-badge variant="neutral">{{ (int) $cc->default_tax_rate }} % USt</x-nx-badge>
                                            @endif
                                            <span class="shrink-0 text-xs tabular-nums text-[color:var(--nx-faint)]">{{ $child['cnt'] }}×</span>
                                            <span class="w-28 shrink-0 text-right text-sm tabular-nums text-[color:var(--nx-muted)]">{{ $money($child['vol']) }}</span>
                                        </button>
                                    </li>
                                @endforeach
                            @endforeach
                        </ul>
                    </x-nx-card>
                </x-nx-section>
            @endif
        @endforeach

    </x-ui-page-container>

    {{-- Peek-Panel rechts: Anlegen/Bearbeiten --}}
    <x-slot name="activity">
        <x-ui-page-sidebar title="Kategorie" icon="heroicon-o-pencil-square" width="w-80" side="right" :defaultOpen="true" storeKey="dripCategoryPanel">
            <div class="p-4">
                @if (! $panelOpen)
                    <div class="flex flex-col items-center gap-3 py-10 text-center">
                        @svg('heroicon-o-cursor-arrow-rays', 'w-8 h-8 text-[color:var(--nx-faint)]')
                        <p class="text-sm text-[color:var(--nx-muted)]">Wähle eine Kategorie zum Bearbeiten oder lege eine neue an.</p>
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

                        {{-- Tone-Farbwähler --}}
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
