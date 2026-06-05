{{-- resources/views/vendor/drip/livewire/sidebar-content.blade.php --}}
<div>
    {{-- Modul Header --}}
    <div x-show="!collapsed" class="p-3 text-sm italic text-[var(--ui-secondary)] uppercase border-b border-[var(--ui-border)] mb-2">
        Drip
    </div>

    {{-- Expanded --}}
    <x-ui-sidebar-list label="Uebersicht">
        <x-ui-sidebar-item :href="route('drip.dashboard')">
            @svg('heroicon-o-chart-bar', 'w-4 h-4 text-[var(--ui-secondary)]')
            <span class="ml-2 text-sm">Dashboard</span>
        </x-ui-sidebar-item>
    </x-ui-sidebar-list>

    @if($groups->isNotEmpty())
        <x-ui-sidebar-list label="Konten">
            @foreach($groups as $group)
                <x-ui-sidebar-item :href="route('drip.groups.show', $group)">
                    <span class="w-2 h-2 rounded-full shrink-0" style="background-color: {{ $group->color ?? '#6B7280' }}"></span>
                    <span class="ml-2 text-sm truncate">{{ $group->name }}</span>
                </x-ui-sidebar-item>
            @endforeach
        </x-ui-sidebar-list>
    @endif

    <x-ui-sidebar-list label="Analyse">
        <x-ui-sidebar-item :href="route('drip.budgets')">
            @svg('heroicon-o-calculator', 'w-4 h-4 text-[var(--ui-secondary)]')
            <span class="ml-2 text-sm">Budgets</span>
        </x-ui-sidebar-item>
        <x-ui-sidebar-item :href="route('drip.liquidity')">
            @svg('heroicon-o-chart-bar-square', 'w-4 h-4 text-[var(--ui-secondary)]')
            <span class="ml-2 text-sm">Liquiditaet</span>
        </x-ui-sidebar-item>
    </x-ui-sidebar-list>

    <x-ui-sidebar-list label="Einstellungen">
        <x-ui-sidebar-item :href="route('drip.categories')">
            @svg('heroicon-o-tag', 'w-4 h-4 text-[var(--ui-secondary)]')
            <span class="ml-2 text-sm">Kategorien</span>
        </x-ui-sidebar-item>
        <x-ui-sidebar-item :href="route('drip.rules')">
            @svg('heroicon-o-funnel', 'w-4 h-4 text-[var(--ui-secondary)]')
            <span class="ml-2 text-sm">Regeln</span>
        </x-ui-sidebar-item>
        <x-ui-sidebar-item :href="route('drip.banks')">
            @svg('heroicon-o-building-library', 'w-4 h-4 text-[var(--ui-secondary)]')
            <span class="ml-2 text-sm">Banken</span>
        </x-ui-sidebar-item>
    </x-ui-sidebar-list>

    {{-- Collapsed: Icons-only --}}
    <div x-show="collapsed" class="px-2 py-2 border-b border-[var(--ui-border)]">
        <div class="flex flex-col gap-2">
            <a href="{{ route('drip.dashboard') }}" wire:navigate class="flex items-center justify-center p-2 rounded-md text-[var(--ui-secondary)] hover:bg-[var(--ui-muted-5)]">
                @svg('heroicon-o-chart-bar', 'w-5 h-5')
            </a>

            <div class="border-t border-[var(--ui-border)] my-1"></div>

            @foreach($groups as $group)
                <a href="{{ route('drip.groups.show', $group) }}" wire:navigate class="flex items-center justify-center p-2 rounded-md text-[var(--ui-secondary)] hover:bg-[var(--ui-muted-5)]" title="{{ $group->name }}">
                    <span class="w-3 h-3 rounded-full" style="background-color: {{ $group->color ?? '#6B7280' }}"></span>
                </a>
            @endforeach

            <div class="border-t border-[var(--ui-border)] my-1"></div>

            <a href="{{ route('drip.budgets') }}" wire:navigate class="flex items-center justify-center p-2 rounded-md text-[var(--ui-secondary)] hover:bg-[var(--ui-muted-5)]">
                @svg('heroicon-o-calculator', 'w-5 h-5')
            </a>
            <a href="{{ route('drip.liquidity') }}" wire:navigate class="flex items-center justify-center p-2 rounded-md text-[var(--ui-secondary)] hover:bg-[var(--ui-muted-5)]">
                @svg('heroicon-o-chart-bar-square', 'w-5 h-5')
            </a>

            <div class="border-t border-[var(--ui-border)] my-1"></div>

            <a href="{{ route('drip.categories') }}" wire:navigate class="flex items-center justify-center p-2 rounded-md text-[var(--ui-secondary)] hover:bg-[var(--ui-muted-5)]">
                @svg('heroicon-o-tag', 'w-5 h-5')
            </a>
            <a href="{{ route('drip.rules') }}" wire:navigate class="flex items-center justify-center p-2 rounded-md text-[var(--ui-secondary)] hover:bg-[var(--ui-muted-5)]">
                @svg('heroicon-o-funnel', 'w-5 h-5')
            </a>
            <a href="{{ route('drip.banks') }}" wire:navigate class="flex items-center justify-center p-2 rounded-md text-[var(--ui-secondary)] hover:bg-[var(--ui-muted-5)]">
                @svg('heroicon-o-building-library', 'w-5 h-5')
            </a>
        </div>
    </div>
</div>
