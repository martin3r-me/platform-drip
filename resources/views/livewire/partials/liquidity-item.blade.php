<div class="px-6 py-2.5 flex items-center justify-between group">
    <div class="flex items-center gap-2 min-w-0 mr-3">
        {{-- Name (linked for signals with URL) --}}
        @if($item['source'] === 'signal' && $item['url'])
            <a href="{{ $item['url'] }}" target="_blank" class="text-[13px] font-medium text-blue-600 hover:text-blue-700 truncate">{{ $item['name'] }}</a>
        @elseif($item['source'] === 'vat')
            <span class="text-[13px] font-medium text-purple-700 truncate">{{ $item['name'] }}</span>
        @else
            <span class="text-[13px] font-medium text-gray-800 truncate">{{ $item['name'] }}</span>
        @endif

        {{-- VAT source badge --}}
        @if($item['source'] === 'vat')
            <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[9px] font-medium bg-purple-50 text-purple-700 shrink-0">USt</span>
        @endif

        {{-- Confidence badge for signals --}}
        @if($item['source'] === 'signal')
            @php
                $badgeColor = match($item['confidence_level'] ?? 'expected') {
                    'confirmed' => 'bg-green-50 text-green-700',
                    'speculative' => 'bg-amber-50 text-amber-700',
                    default => 'bg-blue-50 text-blue-700',
                };
                $badgeLabel = match($item['confidence_level'] ?? 'expected') {
                    'confirmed' => 'Sicher',
                    'speculative' => 'Spekulativ',
                    default => 'Erwartet',
                };
            @endphp
            <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[9px] font-medium {{ $badgeColor }} shrink-0">{{ $badgeLabel }}</span>
        @endif

        {{-- Tax rate label for budget items --}}
        @if($item['source'] === 'budget' && isset($item['tax_rate']))
            @if($item['tax_rate'] > 0)
                <span class="text-[9px] text-gray-400 shrink-0">inkl. {{ number_format($item['tax_rate'], 0) }}% USt</span>
            @elseif($item['tax_rate'] === 0 || $item['tax_rate'] === 0.0)
                <span class="text-[9px] text-gray-400 shrink-0">USt-frei</span>
            @endif
        @endif

        {{-- Category --}}
        @if($item['category'])
            <span class="text-[10px] text-gray-400 shrink-0 hidden sm:inline">{{ $item['category'] }}</span>
        @endif
    </div>

    <div class="flex items-center gap-3 shrink-0">
        {{-- Date --}}
        <span class="text-[11px] text-gray-400 tabular-nums">{{ \Illuminate\Support\Carbon::parse($item['date'])->format('d.m.') }}</span>

        {{-- Amount --}}
        <span class="text-[13px] tabular-nums font-medium w-28 text-right {{ $item['source'] === 'vat' ? 'text-purple-600' : ($item['direction'] === 'credit' ? 'text-green-600' : 'text-red-600') }}">
            {{ $item['direction'] === 'credit' ? '+' : '-' }}{{ number_format($item['amount'], 2, ',', '.') }} &euro;
        </span>

        {{-- Signal actions (not for VAT items) --}}
        @if($item['source'] === 'signal' && $item['signal_id'])
            <div class="flex items-center gap-0.5 opacity-0 group-hover:opacity-100 transition-opacity">
                <button wire:click="pinSignalToBudget({{ $item['signal_id'] }})"
                        wire:confirm="Signal als Budget-Posten uebernehmen?"
                        class="p-1 rounded text-gray-400 hover:text-blue-600 hover:bg-blue-50 transition-colors"
                        title="Als Budget uebernehmen">
                    @svg('heroicon-o-bookmark', 'w-3.5 h-3.5')
                </button>
                <button wire:click="dismissSignal({{ $item['signal_id'] }})"
                        class="p-1 rounded text-gray-400 hover:text-red-600 hover:bg-red-50 transition-colors"
                        title="Ausblenden">
                    @svg('heroicon-o-eye-slash', 'w-3.5 h-3.5')
                </button>
            </div>
        @endif
    </div>
</div>
