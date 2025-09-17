<x-filament::page>
    <div class="space-y-4">
        <x-filament::button wire:click="import">
            Start Import
        </x-filament::button>

        <div wire:poll.2s="getProgress">
            @php($p = $this->getProgress())
            <x-filament::section>
                <x-slot name="heading">Progress</x-slot>
                <div class="text-sm">
                    Status: <strong>{{ $p['status'] ?? 'idle' }}</strong><br>
                    Created: {{ $p['created'] ?? 0 }} — Updated: {{ $p['updated'] ?? 0 }}<br>
                    @if(!empty($p['page'])) Page: {{ $p['page'] }} @endif
                    @if(!empty($p['message']))
                        <div class="text-danger-600 mt-2">Error: {{ $p['message'] }}</div>
                    @endif
                </div>
            </x-filament::section>
        </div>
    </div>
</x-filament::page>
