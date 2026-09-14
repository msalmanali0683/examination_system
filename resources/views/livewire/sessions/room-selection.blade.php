<div>
    <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">
        Check the rooms available for this session. Override capacity only if fewer seats than usual should be used (e.g. social distancing, partial room booking).
    </p>

    @if ($rooms->isEmpty())
        <p class="text-sm text-gray-500 dark:text-gray-400">No active rooms yet &mdash; add some from the <a href="{{ route('rooms.index') }}" wire:navigate class="text-indigo-600 hover:underline">Rooms</a> page.</p>
    @else
        <div class="divide-y divide-gray-100 dark:divide-gray-700">
            @foreach ($rooms as $room)
                @php $sessionRoom = $included->get($room->id); @endphp
                <div class="py-3 flex items-center justify-between gap-4">
                    <label class="flex items-center gap-3">
                        <input type="checkbox" wire:click="toggleRoom({{ $room->id }})" @checked($sessionRoom) class="rounded border-gray-300">
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
                                class="w-24 rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm"
                            >
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</div>
