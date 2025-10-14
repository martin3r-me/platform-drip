{{-- resources/views/vendor/drip/livewire/sidebar-content.blade.php --}}
<div>
    {{-- Modul Header --}}
    <x-sidebar-module-header module-name="Drip" />
    
    {{-- Gruppe: Allgemein (wie in planner/okr) --}}
    <div>
        <h4 x-show="!collapsed" class="p-3 text-sm italic text-secondary uppercase">Allgemein</h4>

        {{-- Link: Dashboard --}}
        <a href="{{ route('drip.dashboard') }}"
           class="relative d-flex items-center p-2 my-1 rounded-md font-medium transition"
           :class="[
               (window.location.pathname === '/' ||
                window.location.pathname === '/drip' ||
                window.location.pathname === '/drip/')
                   ? 'bg-primary text-on-primary shadow-md'
                   : 'text-black hover:bg-primary-10 hover:text-primary hover:shadow-md',
               collapsed ? 'justify-center' : 'gap-3'
           ]"
           wire:navigate>
            <x-heroicon-o-home class="w-6 h-6 flex-shrink-0"/>
            <span x-show="!collapsed" class="truncate">Dashboard</span>
        </a>

        {{-- Link: Banken --}}
        <a href="{{ route('drip.banks') }}"
           class="relative d-flex items-center p-2 my-1 rounded-md font-medium transition"
           :class="[
               (window.location.pathname.startsWith('/drip/banks'))
                   ? 'bg-primary text-on-primary shadow-md'
                   : 'text-black hover:bg-primary-10 hover:text-primary hover:shadow-md',
               collapsed ? 'justify-center' : 'gap-3'
           ]"
           wire:navigate>
            <x-heroicon-o-building-library class="w-6 h-6 flex-shrink-0"/>
            <span x-show="!collapsed" class="truncate">Banken</span>
        </a>
    </div>
</div>