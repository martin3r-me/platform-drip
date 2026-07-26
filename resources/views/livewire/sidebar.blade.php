<div>
    {{-- Modul-Header --}}
    <div x-show="!collapsed" class="p-3 text-sm italic uppercase text-[color:var(--nx-muted)] border-b border-[color:var(--nx-line)] mb-2">
        Drip
    </div>

    <x-ui-sidebar-list label="Übersicht">
        <x-ui-sidebar-item :href="route('drip.dashboard')" :active="request()->routeIs('drip.dashboard')">
            @svg('heroicon-o-chart-bar', 'w-4 h-4 shrink-0')
            <span class="text-sm">Dashboard</span>
        </x-ui-sidebar-item>
    </x-ui-sidebar-list>

    @if($groups->isNotEmpty())
        <x-ui-sidebar-list label="Konten">
            @foreach($groups as $group)
                <x-ui-sidebar-item :href="route('drip.groups.show', $group)" :active="request()->routeIs('drip.groups.show') && request()->route('group')?->is($group)">
                    <span class="w-2 h-2 rounded-full shrink-0" style="background-color: {{ $group->color ?? 'var(--nx-muted)' }}"></span>
                    <span class="text-sm truncate">{{ $group->name }}</span>
                </x-ui-sidebar-item>
            @endforeach
        </x-ui-sidebar-list>
    @endif

    <x-ui-sidebar-list label="Einstellungen">
        <x-ui-sidebar-item :href="route('drip.categories')" :active="request()->routeIs('drip.categories')">
            @svg('heroicon-o-tag', 'w-4 h-4 shrink-0')
            <span class="text-sm">Kategorien</span>
        </x-ui-sidebar-item>
        <x-ui-sidebar-item :href="route('drip.rules')" :active="request()->routeIs('drip.rules')">
            @svg('heroicon-o-funnel', 'w-4 h-4 shrink-0')
            <span class="text-sm">Regeln</span>
        </x-ui-sidebar-item>
        @if(Route::has('drip.invoices'))
            <x-ui-sidebar-item :href="route('drip.invoices')" :active="request()->routeIs('drip.invoices')">
                @svg('heroicon-o-receipt-percent', 'w-4 h-4 shrink-0')
                <span class="text-sm">Offene Belege</span>
            </x-ui-sidebar-item>
        @endif
        <x-ui-sidebar-item :href="route('drip.banks')" :active="request()->routeIs('drip.banks')">
            @svg('heroicon-o-building-library', 'w-4 h-4 shrink-0')
            <span class="text-sm">Banken</span>
        </x-ui-sidebar-item>
    </x-ui-sidebar-list>
</div>
