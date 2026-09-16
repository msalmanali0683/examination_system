<div>
    <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">
        Check the rooms available for this session. Override capacity only if fewer seats than usual should be used (e.g. social distancing, partial room booking).
    </p>

    @if ($rooms->isNotEmpty())
        <div class="flex items-center gap-3 mb-4">
            <x-btn wire:click="selectAllRooms" variant="secondary" size="sm" icon="door">Select All</x-btn>
            <x-btn wire:click="deselectAllRooms" wire:confirm="Remove every room from this session? Any capacity overrides you've set will be lost." variant="secondary" size="sm" icon="trash">Remove All</x-btn>
        </div>
    @endif

    @if ($rooms->isEmpty())
        <x-empty-state icon="door" title="No active rooms yet" description="Add rooms from the Rooms page first.">
            <x-slot name="actions">
                <x-btn :href="route('rooms.index')" wire:navigate variant="secondary" size="sm" icon="door">Go to Rooms</x-btn>
            </x-slot>
        </x-empty-state>
    @else
        <div class="divide-y divide-gray-100 dark:divide-gray-700">
            @foreach ($rooms as $room)
                @php $sessionRoom = $included->get($room->id); @endphp
                <div class="py-3 flex items-center justify-between gap-4 flex-wrap">
                    <label class="flex items-center gap-3 cursor-pointer">
                        <input type="checkbox" wire:click="toggleRoom({{ $room->id }})" @checked($sessionRoom) class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                        <span>
                            <span class="font-medium text-gray-900 dark:text-gray-100">{{ $room->name }}</span>
                            <span class="text-xs text-gray-400 ml-1">({{ ucfirst($room->room_type) }}, cap. {{ $room->capacity }})</span>
                        </span>
                    </label>

                    @if ($sessionRoom)
                        <div class="flex items-center gap-2">
                            <label class="text-xs text-gray-500 dark:text-gray-400">Capacity override</label>
                            <input
                                type="number" min="1" max="{{ $room->capacity }}"
                                value="{{ $sessionRoom->capacity_override }}"
                                placeholder="{{ $room->capacity }}"
                                wire:change="updateCapacityOverride({{ $room->id }}, $event.target.value)"
                                class="w-24 rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm"
                            >
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</div>
