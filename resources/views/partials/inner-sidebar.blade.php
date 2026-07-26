{{--
    Drip – generische innere (kontextuelle) Sidebar, gerendert im x-slot "sidebar".
    Getrennt von der Haupt-Nav-Leiste (drip.sidebar). Standard: Schnellzugriff.
--}}
<x-ui-page-sidebar title="Schnellzugriff" icon="heroicon-o-bolt" width="w-72" :defaultOpen="true" side="left">
    <div class="flex flex-col gap-2 p-4">
        <span class="px-1 pb-1 text-xs font-medium uppercase tracking-wide text-[color:var(--nx-faint)]">Aktionen</span>
        <x-nx-button variant="secondary" class="w-full justify-start" :href="route('drip.dashboard')" wire:navigate>
            @svg('heroicon-o-chart-bar', 'w-4 h-4') Dashboard
        </x-nx-button>
        <x-nx-button variant="ghost" class="w-full justify-start" :href="route('drip.banks')" wire:navigate>
            @svg('heroicon-o-building-library', 'w-4 h-4') Banken verwalten
        </x-nx-button>
        <x-nx-button variant="ghost" class="w-full justify-start" :href="route('drip.categories')" wire:navigate>
            @svg('heroicon-o-tag', 'w-4 h-4') Kategorien
        </x-nx-button>
        <x-nx-button variant="ghost" class="w-full justify-start" :href="route('drip.rules')" wire:navigate>
            @svg('heroicon-o-funnel', 'w-4 h-4') Regeln
        </x-nx-button>
        @if(Route::has('drip.invoices'))
            <x-nx-button variant="ghost" class="w-full justify-start" :href="route('drip.invoices')" wire:navigate>
                @svg('heroicon-o-receipt-percent', 'w-4 h-4') Belege
            </x-nx-button>
        @endif
    </div>
</x-ui-page-sidebar>
