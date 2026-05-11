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

            {{-- Links: Tab-Navigation + Listen --}}
            <div class="lg:col-span-2">

                {{-- Tabs --}}
                <div class="flex items-center gap-1 mb-4 border-b border-gray-200">
                    <button wire:click="$set('activeTab', 'suggestions')"
                            class="px-3 py-2 text-[13px] font-medium border-b-2 transition-colors {{ $activeTab === 'suggestions' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700' }}">
                        Vorschlaege
                        @if(count($suggestions) > 0)
                            <span class="ml-1 inline-flex items-center justify-center px-1.5 py-0.5 rounded-full text-[10px] font-medium bg-blue-100 text-blue-700">{{ count($suggestions) }}</span>
                        @endif
                    </button>
                    <button wire:click="$set('activeTab', 'active')"
                            class="px-3 py-2 text-[13px] font-medium border-b-2 transition-colors {{ $activeTab === 'active' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700' }}">
                        Aktiv
                        @if(count($budgets) > 0)
                            <span class="ml-1 inline-flex items-center justify-center px-1.5 py-0.5 rounded-full text-[10px] font-medium bg-gray-100 text-gray-600">{{ count($budgets) }}</span>
                        @endif
                    </button>
                    <button wire:click="$set('activeTab', 'history')"
                            class="px-3 py-2 text-[13px] font-medium border-b-2 transition-colors {{ $activeTab === 'history' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700' }}">
                        Verlauf
                    </button>
                </div>

                {{-- Tab: Vorschlaege --}}
                @if($activeTab === 'suggestions')
                    <div class="flex items-center justify-between mb-3">
                        <h3 class="text-sm font-semibold text-gray-900">Erkannte Muster</h3>
                        <div class="flex items-center gap-2">
                            @if(count($suggestions) > 0)
                                <button wire:click="confirmAllSuggestions" wire:confirm="Alle Vorschlaege bestaetigen?"
                                        class="inline-flex items-center gap-1 px-2.5 py-1 rounded-md text-[12px] font-medium bg-green-50 text-green-700 hover:bg-green-100 transition-colors">
                                    @svg('heroicon-o-check', 'w-3.5 h-3.5')
                                    Alle bestaetigen
                                </button>
                            @endif
                            <button wire:click="runDetection"
                                    class="inline-flex items-center gap-1 px-2.5 py-1 rounded-md text-[12px] font-medium bg-gray-50 text-gray-600 hover:bg-gray-100 transition-colors">
                                @svg('heroicon-o-arrow-path', 'w-3.5 h-3.5')
                                Scannen
                            </button>
                        </div>
                    </div>

                    @if(count($suggestions) > 0)
                        <div class="space-y-2">
                            @foreach($suggestions as $s)
                                <div class="bg-white rounded-lg border border-gray-200 px-4 py-3">
                                    <div class="flex items-center justify-between">
                                        <div class="flex-1 min-w-0 mr-3">
                                            <div class="flex items-center gap-2 mb-1">
                                                <span class="text-sm font-medium text-gray-900 truncate">{{ $s['name'] }}</span>
                                                <span class="inline-flex items-center px-1.5 py-0.5 rounded-full text-[10px] font-medium {{ $s['direction'] === 'debit' ? 'bg-red-50 text-red-600' : 'bg-green-50 text-green-600' }} shrink-0">
                                                    {{ $s['direction'] === 'debit' ? 'Ausgabe' : 'Einnahme' }}
                                                </span>
                                                @if($s['category_name'])
                                                    <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded-full text-[10px] font-medium bg-gray-50 text-gray-600 shrink-0">
                                                        <span class="w-1.5 h-1.5 rounded-full" style="background-color: {{ $s['category_color'] }}"></span>
                                                        {{ $s['category_name'] }}
                                                    </span>
                                                @endif
                                            </div>
                                            <div class="flex items-center gap-3 text-[12px] text-gray-500">
                                                <span class="tabular-nums font-medium">~{{ number_format($s['source_avg_amount'], 2, ',', '.') }} &euro;/Monat</span>
                                                <span>{{ $s['source_month_count'] }} Monate erkannt</span>
                                            </div>
                                        </div>
                                        <div class="flex items-center gap-1 shrink-0">
                                            <button wire:click="confirmSuggestion({{ $s['id'] }})"
                                                    class="p-1.5 rounded-md text-green-500 hover:text-green-700 hover:bg-green-50 transition-colors"
                                                    title="Bestaetigen">
                                                @svg('heroicon-o-check-circle', 'w-5 h-5')
                                            </button>
                                            <button wire:click="dismissSuggestion({{ $s['id'] }})"
                                                    class="p-1.5 rounded-md text-gray-400 hover:text-red-600 hover:bg-red-50 transition-colors"
                                                    title="Ablehnen">
                                                @svg('heroicon-o-x-mark', 'w-5 h-5')
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <div class="bg-white rounded-lg border border-gray-200 p-12 text-center">
                            <div class="text-gray-400 mb-4">
                                @svg('heroicon-o-light-bulb', 'w-12 h-12 mx-auto')
                            </div>
                            <h3 class="text-sm font-semibold text-gray-900 mb-1">Keine Vorschlaege</h3>
                            <p class="text-[13px] text-gray-500">Klicke auf "Scannen", um wiederkehrende Transaktionen zu erkennen.</p>
                        </div>
                    @endif
                @endif

                {{-- Tab: Aktiv --}}
                @if($activeTab === 'active')
                    @if(count($budgets) > 0)
                        <div class="bg-white rounded-lg border border-gray-200">
                            @foreach($budgets as $index => $b)
                                <div class="flex items-center justify-between px-4 py-3 {{ $index < count($budgets) - 1 ? 'border-b border-gray-100' : '' }} {{ $b['status'] === 'paused' ? 'opacity-50' : '' }}">
                                    <div class="flex-1 min-w-0 mr-4">
                                        <div class="flex items-center gap-2 mb-1.5">
                                            <div class="w-2.5 h-2.5 rounded-full shrink-0" style="background-color: {{ $b['category_color'] }}"></div>
                                            <span class="text-sm font-medium text-gray-900 truncate">{{ $b['name'] }}</span>
                                            <span class="inline-flex items-center px-1.5 py-0.5 rounded-full text-[10px] font-medium bg-gray-100 text-gray-500 shrink-0">
                                                {{ match($b['frequency']) { 'weekly' => 'W', 'monthly' => 'M', 'quarterly' => 'Q', 'yearly' => 'J', 'once' => '1x', default => $b['frequency'] } }}
                                            </span>
                                            <span class="inline-flex items-center px-1.5 py-0.5 rounded-full text-[10px] font-medium {{ $b['direction'] === 'debit' ? 'bg-red-50 text-red-600' : 'bg-green-50 text-green-600' }} shrink-0">
                                                {{ $b['direction'] === 'debit' ? 'Ausgabe' : 'Einnahme' }}
                                            </span>
                                            @if($b['status'] === 'paused')
                                                <span class="inline-flex items-center px-1.5 py-0.5 rounded-full text-[10px] font-medium bg-yellow-50 text-yellow-700 shrink-0">Pausiert</span>
                                            @endif
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
                                        <button type="button" wire:click="togglePeriods({{ $b['id'] }})"
                                                class="p-1.5 rounded-md {{ $showPeriodsFor === $b['id'] ? 'text-blue-600 bg-blue-50' : 'text-gray-400 hover:text-gray-600 hover:bg-gray-100' }} transition-colors"
                                                title="Perioden">
                                            @svg('heroicon-o-calendar-days', 'w-4 h-4')
                                        </button>
                                        <button type="button" wire:click="togglePause({{ $b['id'] }})"
                                                class="p-1.5 rounded-md text-gray-400 hover:text-gray-600 hover:bg-gray-100 transition-colors"
                                                title="{{ $b['status'] === 'paused' ? 'Fortsetzen' : 'Pausieren' }}">
                                            @if($b['status'] === 'paused')
                                                @svg('heroicon-o-play', 'w-4 h-4')
                                            @else
                                                @svg('heroicon-o-pause', 'w-4 h-4')
                                            @endif
                                        </button>
                                        <button type="button" wire:click="edit({{ $b['id'] }})"
                                                class="p-1.5 rounded-md text-gray-400 hover:text-gray-600 hover:bg-gray-100 transition-colors">
                                            @svg('heroicon-o-pencil-square', 'w-4 h-4')
                                        </button>
                                        <button type="button" wire:click="archive({{ $b['id'] }})"
                                                wire:confirm="Budget '{{ $b['name'] }}' archivieren?"
                                                class="p-1.5 rounded-md text-gray-400 hover:text-gray-600 hover:bg-gray-100 transition-colors"
                                                title="Archivieren">
                                            @svg('heroicon-o-archive-box', 'w-4 h-4')
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

                        {{-- Periods panel --}}
                        @if($showPeriodsFor && count($periods) > 0)
                            <div class="mt-3 bg-white rounded-lg border border-gray-200">
                                <div class="px-4 py-2 border-b border-gray-100 bg-gray-50 rounded-t-lg">
                                    <h4 class="text-[12px] font-medium text-gray-600 uppercase tracking-wide">Perioden</h4>
                                </div>
                                <div class="divide-y divide-gray-100">
                                    @foreach($periods as $p)
                                        <div class="flex items-center justify-between px-4 py-2 {{ $p['status'] === 'skipped' ? 'opacity-40 line-through' : '' }}">
                                            <div class="flex items-center gap-3 flex-1 min-w-0">
                                                <span class="text-[13px] font-medium text-gray-700 w-20 shrink-0">{{ $p['period_label'] }}</span>
                                                @if($p['expected_date'])
                                                    <span class="text-[11px] text-gray-400">{{ $p['expected_date'] }}</span>
                                                @endif
                                                <span class="inline-flex items-center px-1.5 py-0.5 rounded-full text-[10px] font-medium shrink-0
                                                    {{ match($p['status']) {
                                                        'fulfilled' => 'bg-green-50 text-green-700',
                                                        'partial' => 'bg-yellow-50 text-yellow-700',
                                                        'missed' => 'bg-red-50 text-red-700',
                                                        'skipped' => 'bg-gray-100 text-gray-500',
                                                        default => 'bg-blue-50 text-blue-700',
                                                    } }}">
                                                    {{ match($p['status']) {
                                                        'fulfilled' => 'Erfuellt',
                                                        'partial' => 'Teilweise',
                                                        'missed' => 'Verpasst',
                                                        'skipped' => 'Uebersprungen',
                                                        default => 'Ausstehend',
                                                    } }}
                                                </span>
                                            </div>
                                            <div class="flex items-center gap-2 shrink-0">
                                                @if($editingPeriodId === $p['id'])
                                                    <form wire:submit="adjustPeriod" class="flex items-center gap-1">
                                                        <input type="number" wire:model="editingPeriodAmount" step="0.01" min="0"
                                                               class="w-24 px-2 py-0.5 rounded border border-gray-200 text-[12px] text-right tabular-nums">
                                                        <button type="submit" class="p-1 rounded text-green-600 hover:bg-green-50">
                                                            @svg('heroicon-o-check', 'w-3.5 h-3.5')
                                                        </button>
                                                    </form>
                                                @else
                                                    <span class="text-[12px] tabular-nums text-gray-600 w-28 text-right">
                                                        {{ number_format($p['actual_amount'], 2, ',', '.') }} / {{ number_format($p['planned_amount'], 2, ',', '.') }} &euro;
                                                    </span>
                                                @endif
                                                @if($p['status'] !== 'skipped')
                                                    <button wire:click="startEditPeriod({{ $p['id'] }})"
                                                            class="p-1 rounded text-gray-400 hover:text-gray-600 hover:bg-gray-100" title="Betrag anpassen">
                                                        @svg('heroicon-o-pencil', 'w-3.5 h-3.5')
                                                    </button>
                                                    <button wire:click="skipPeriod({{ $p['id'] }})"
                                                            class="p-1 rounded text-gray-400 hover:text-yellow-600 hover:bg-yellow-50" title="Ueberspringen">
                                                        @svg('heroicon-o-forward', 'w-3.5 h-3.5')
                                                    </button>
                                                @endif
                                                <button wire:click="deletePeriod({{ $p['id'] }})"
                                                        wire:confirm="Periode loeschen?"
                                                        class="p-1 rounded text-gray-400 hover:text-red-600 hover:bg-red-50" title="Loeschen">
                                                    @svg('heroicon-o-trash', 'w-3.5 h-3.5')
                                                </button>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @elseif($showPeriodsFor && count($periods) === 0)
                            <div class="mt-3 bg-white rounded-lg border border-gray-200 p-6 text-center">
                                <p class="text-[13px] text-gray-500">Keine Perioden vorhanden.</p>
                            </div>
                        @endif
                    @else
                        <div class="bg-white rounded-lg border border-gray-200 p-12 text-center">
                            <div class="text-gray-400 mb-4">
                                @svg('heroicon-o-calculator', 'w-12 h-12 mx-auto')
                            </div>
                            <h3 class="text-sm font-semibold text-gray-900 mb-1">Noch keine Budgets</h3>
                            <p class="text-[13px] text-gray-500">Erstelle ein Budget oder uebernimm einen Vorschlag.</p>
                        </div>
                    @endif
                @endif

                {{-- Tab: Verlauf --}}
                @if($activeTab === 'history')
                    <div class="flex items-center justify-between mb-3">
                        <div class="flex items-center gap-2">
                            <button wire:click="previousMonth"
                                    class="p-1 rounded-md text-gray-400 hover:text-gray-600 hover:bg-gray-100 transition-colors">
                                @svg('heroicon-o-chevron-left', 'w-4 h-4')
                            </button>
                            <span class="text-sm font-semibold text-gray-900 w-36 text-center">{{ $historyMonthLabel }}</span>
                            <button wire:click="nextMonth"
                                    class="p-1 rounded-md text-gray-400 hover:text-gray-600 hover:bg-gray-100 transition-colors">
                                @svg('heroicon-o-chevron-right', 'w-4 h-4')
                            </button>
                        </div>
                    </div>

                    @if(count($historyBudgets) > 0)
                        <div class="bg-white rounded-lg border border-gray-200">
                            @foreach($historyBudgets as $index => $b)
                                @php
                                    if ($b['percent'] >= 80 && $b['percent'] <= 120) {
                                        $histBarColor = 'bg-green-500';
                                    } elseif (($b['percent'] >= 50 && $b['percent'] < 80) || ($b['percent'] > 120 && $b['percent'] <= 150)) {
                                        $histBarColor = 'bg-yellow-500';
                                    } else {
                                        $histBarColor = 'bg-red-500';
                                    }
                                    $histBarWidth = min($b['percent'], 100);
                                @endphp
                                <div class="flex items-center justify-between px-4 py-3 {{ $index < count($historyBudgets) - 1 ? 'border-b border-gray-100' : '' }}">
                                    <div class="flex-1 min-w-0 mr-4">
                                        <div class="flex items-center gap-2 mb-1.5">
                                            <div class="w-2.5 h-2.5 rounded-full shrink-0" style="background-color: {{ $b['category_color'] }}"></div>
                                            <span class="text-sm font-medium text-gray-900 truncate">{{ $b['name'] }}</span>
                                            <span class="inline-flex items-center px-1.5 py-0.5 rounded-full text-[10px] font-medium {{ $b['direction'] === 'debit' ? 'bg-red-50 text-red-600' : 'bg-green-50 text-green-600' }} shrink-0">
                                                {{ $b['direction'] === 'debit' ? 'Ausgabe' : 'Einnahme' }}
                                            </span>
                                            @if($b['status'] === 'archived')
                                                <span class="inline-flex items-center px-1.5 py-0.5 rounded-full text-[10px] font-medium bg-gray-100 text-gray-500 shrink-0">Archiviert</span>
                                            @endif
                                        </div>
                                        <div class="flex items-center gap-3">
                                            <div class="flex-1 h-2 bg-gray-100 rounded-full overflow-hidden">
                                                <div class="{{ $histBarColor }} h-2 rounded-full transition-all" style="width: {{ $histBarWidth }}%"></div>
                                            </div>
                                            <span class="text-[12px] tabular-nums text-gray-600 shrink-0 w-36 text-right">
                                                {{ number_format($b['actual'], 2, ',', '.') }} / {{ number_format($b['budget'], 2, ',', '.') }} &euro;
                                            </span>
                                            <span class="text-[11px] tabular-nums font-medium shrink-0 w-12 text-right {{ $b['percent'] >= 80 && $b['percent'] <= 120 ? 'text-green-600' : ($b['percent'] >= 50 && $b['percent'] <= 150 ? 'text-yellow-600' : 'text-red-600') }}">
                                                {{ $b['percent'] }}%
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            @endforeach

                            {{-- Summary --}}
                            <div class="px-4 py-3 border-t border-gray-200 bg-gray-50 rounded-b-lg">
                                <div class="flex items-center justify-between">
                                    <span class="text-[13px] font-medium text-gray-700">Gesamt</span>
                                    <span class="text-[13px] tabular-nums font-medium text-gray-900">
                                        {{ number_format($historyTotalActual, 2, ',', '.') }} / {{ number_format($historyTotalBudget, 2, ',', '.') }} &euro;
                                        @if($historyTotalBudget > 0)
                                            <span class="text-gray-500">({{ round($historyTotalActual / $historyTotalBudget * 100, 1) }}%)</span>
                                        @endif
                                    </span>
                                </div>
                            </div>
                        </div>
                    @else
                        <div class="bg-white rounded-lg border border-gray-200 p-12 text-center">
                            <div class="text-gray-400 mb-4">
                                @svg('heroicon-o-clock', 'w-12 h-12 mx-auto')
                            </div>
                            <h3 class="text-sm font-semibold text-gray-900 mb-1">Keine Daten</h3>
                            <p class="text-[13px] text-gray-500">Fuer diesen Monat gibt es keine Budget-Daten.</p>
                        </div>
                    @endif
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
                                <option value="once">Einmalig</option>
                            </select>
                            @error('formFrequency')
                                <p class="mt-1 text-[11px] text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        {{-- Geplantes Datum (nur bei Einmalig) --}}
                        @if($formFrequency === 'once')
                            <div>
                                <label for="budget-planned-date" class="block text-[13px] font-medium text-gray-700 mb-1">Geplantes Datum</label>
                                <input type="date" id="budget-planned-date" wire:model="formPlannedDate" required
                                       class="w-full px-3 py-1.5 rounded-md border border-gray-200 text-[13px] text-gray-900 focus:outline-none focus:ring-1 focus:ring-blue-500 focus:border-blue-500">
                                @error('formPlannedDate')
                                    <p class="mt-1 text-[11px] text-red-600">{{ $message }}</p>
                                @enderror
                            </div>
                        @endif

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

                        {{-- Startdatum --}}
                        <div>
                            <label for="budget-start" class="block text-[13px] font-medium text-gray-700 mb-1">Startdatum (optional)</label>
                            <input type="date" id="budget-start" wire:model="formStartDate"
                                   class="w-full px-3 py-1.5 rounded-md border border-gray-200 text-[13px] text-gray-900 focus:outline-none focus:ring-1 focus:ring-blue-500 focus:border-blue-500">
                            @error('formStartDate')
                                <p class="mt-1 text-[11px] text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        {{-- Enddatum --}}
                        <div>
                            <label for="budget-end" class="block text-[13px] font-medium text-gray-700 mb-1">Enddatum (optional)</label>
                            <input type="date" id="budget-end" wire:model="formEndDate"
                                   class="w-full px-3 py-1.5 rounded-md border border-gray-200 text-[13px] text-gray-900 focus:outline-none focus:ring-1 focus:ring-blue-500 focus:border-blue-500">
                            @error('formEndDate')
                                <p class="mt-1 text-[11px] text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        {{-- Notizen --}}
                        <div>
                            <label for="budget-notes" class="block text-[13px] font-medium text-gray-700 mb-1">Notizen (optional)</label>
                            <textarea id="budget-notes" wire:model="formNotes" rows="2"
                                      class="w-full px-3 py-1.5 rounded-md border border-gray-200 text-[13px] text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-1 focus:ring-blue-500 focus:border-blue-500"
                                      placeholder="Anmerkungen..."></textarea>
                            @error('formNotes')
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
