<div class="space-y-6">
    <x-modal name="capacity-check-error" :show="$errors->isNotEmpty()" focusable>
        <div class="p-6">
            <div class="flex items-start gap-3">
                <x-icon name="warning" class="h-6 w-6 text-red-600 shrink-0" />
                <div>
                    <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Error</h2>
                    <div class="mt-2 text-sm text-gray-600 dark:text-gray-400 space-y-1">
                        @foreach ($errors->all() as $message)
                            <p>{{ $message }}</p>
                        @endforeach
                    </div>
                </div>
            </div>
            <div class="mt-6 flex justify-end">
                <x-btn variant="secondary" x-on:click="$dispatch('close')">Close</x-btn>
            </div>
        </div>
    </x-modal>

    <x-card>
        <div class="flex items-center justify-between gap-4 flex-wrap">
            <div>
                <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">Current Strategy</h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    For each slot, using this session's saved seating strategy: students needing seats, rooms needed (from real room capacities) vs. active, and teachers needed (rooms &times; invigilators/room) vs. available.
                </p>
            </div>
            <x-btn wire:click="checkCurrent" wire:loading.attr="disabled" wire:target="checkCurrent" variant="secondary" icon="search">
                <span wire:loading.remove wire:target="checkCurrent">Check Capacity</span>
                <span wire:loading wire:target="checkCurrent">Checking&hellip;</span>
            </x-btn>
        </div>

        @if ($showCurrent)
            <div class="mt-4 flex items-center gap-2 flex-wrap">
                <x-btn :href="route('sessions.current-capacity-check.xlsx', $examSession)" variant="secondary" icon="download">Download Excel</x-btn>
                <x-btn :href="route('sessions.current-capacity-check.pdf', $examSession)" variant="secondary" icon="download">Download PDF</x-btn>
            </div>

            <x-requirement-table :requirements="$currentRequirements" />
        @endif
    </x-card>

    <x-card>
        <div class="flex items-center justify-between gap-4 flex-wrap">
            <div>
                <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">Slot Capacity Simulator</h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    Automatically packs every enrolled subject into as few simultaneous slots as possible &mdash; as full as the active rooms allow, and never two subjects that share a student in the same slot. Works straight from the enrollment sheet &mdash; no timetable required yet. Every section of a subject always lands in the same simulated slot, same as the real timetable.
                </p>
            </div>
        </div>

        <div class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label for="sessions-capacity-check-seatingstrategy" class="block text-xs font-medium text-gray-500 dark:text-gray-400">Seating strategy to simulate with</label>
                <select id="sessions-capacity-check-seatingstrategy" wire:model.live="seatingStrategy" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 shadow-sm text-sm">
                    <optgroup label="Basic">
                        <option value="strict">Strict (one room per subject+section)</option>
                        <option value="combine_sections">Combine sections of the same subject</option>
                        <option value="mixed">Mix different subjects (whole columns alternate)</option>
                    </optgroup>
                    <optgroup label="Fill leftover seats instead of wasting them">
                        <option value="strict_overflow_section">Strict, then fill leftover seats with another section</option>
                        <option value="strict_overflow_subject">Strict, then fill leftover seats with a different subject</option>
                        <option value="strict_overflow_section_then_subject">Strict, then fill leftover seats with another section &mdash; or a different subject if none left</option>
                        <option value="combine_sections_overflow_subject">Combine sections, then fill leftover seats with a different subject</option>
                    </optgroup>
                </select>
                @error('seatingStrategy') <span class="text-sm text-red-600 block">{{ $message }}</span> @enderror
                <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">Defaults to this session's own saved strategy. A strategy that shares rooms between groups (Combine Sections, Mixed, the overflow options) can fit more subjects into fewer simulated slots than Strict.</p>
            </div>
            @if ($seatingStrategy === 'mixed')
                <div>
                    <label for="sessions-capacity-check-mixedsubjectsperroom" class="block text-xs font-medium text-gray-500 dark:text-gray-400">Subjects per room</label>
                    <input id="sessions-capacity-check-mixedsubjectsperroom" type="number" min="2" max="10" wire:model="mixedSubjectsPerRoom" class="mt-1 block w-28 rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 shadow-sm text-sm">
                    @error('mixedSubjectsPerRoom') <span class="text-sm text-red-600 block">{{ $message }}</span> @enderror
                </div>
            @endif
        </div>

        <div class="mt-4 flex items-end gap-4 flex-wrap">
            <div>
                <label for="sessions-capacity-check-slotsperday" class="block text-xs font-medium text-gray-500 dark:text-gray-400">Slots per day</label>
                <input id="sessions-capacity-check-slotsperday" type="number" min="1" wire:model="slotsPerDay" class="mt-1 block w-28 rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 shadow-sm text-sm">
                @error('slotsPerDay') <span class="text-sm text-red-600 block">{{ $message }}</span> @enderror
            </div>
            <div>
                <label for="sessions-capacity-check-minsubjectsperslot" class="block text-xs font-medium text-gray-500 dark:text-gray-400">Min subjects per slot</label>
                <input id="sessions-capacity-check-minsubjectsperslot" type="number" min="1" placeholder="Any" wire:model="minSubjectsPerSlot" class="mt-1 block w-28 rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 shadow-sm text-sm">
                @error('minSubjectsPerSlot') <span class="text-sm text-red-600 block">{{ $message }}</span> @enderror
            </div>
            <div>
                <label for="sessions-capacity-check-maxsubjectsperslot" class="block text-xs font-medium text-gray-500 dark:text-gray-400">Max subjects per slot</label>
                <input id="sessions-capacity-check-maxsubjectsperslot" type="number" min="1" placeholder="Any that fit" wire:model="maxSubjectsPerSlot" class="mt-1 block w-28 rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 shadow-sm text-sm">
                @error('maxSubjectsPerSlot') <span class="text-sm text-red-600 block">{{ $message }}</span> @enderror
            </div>
            <x-btn wire:click="simulateSlots" wire:loading.attr="disabled" wire:target="simulateSlots" icon="search">
                <span wire:loading.remove wire:target="simulateSlots">Simulate</span>
                <span wire:loading wire:target="simulateSlots">Simulating&hellip;</span>
            </x-btn>
        </div>
        <p class="mt-2 text-xs text-gray-400 dark:text-gray-500">Min/Max are both optional. Leave Max blank to pack in as many clash-free subjects as the rooms fit; a Min is a best-effort target &mdash; a subject with nowhere clash-free to join still gets its own slot.</p>

        @if ($showSlotSimulation)
            @if ($slotRequirements->isEmpty())
                <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">No enrollments yet &mdash; upload the enrollment sheet first.</p>
            @else
                <div class="mt-4 grid grid-cols-2 sm:grid-cols-4 gap-4 text-center">
                    <div class="p-3 bg-gray-50 dark:bg-gray-900/50 rounded-lg">
                        <div class="text-2xl font-semibold text-gray-900 dark:text-gray-100">{{ $slotRequirements->count() }}</div>
                        <div class="text-xs text-gray-500 dark:text-gray-400">Slots needed in total</div>
                    </div>
                    <div class="p-3 bg-gray-50 dark:bg-gray-900/50 rounded-lg">
                        <div class="text-2xl font-semibold text-gray-900 dark:text-gray-100">{{ $daysNeeded }}</div>
                        <div class="text-xs text-gray-500 dark:text-gray-400">Days needed at {{ $slotsPerDay }}/day</div>
                    </div>
                    <div class="p-3 bg-primary-50 dark:bg-primary-900/30 rounded-lg">
                        <div class="text-2xl font-semibold text-primary-700 dark:text-primary-300">{{ $peakRoomsNeeded }}</div>
                        <div class="text-xs text-gray-500 dark:text-gray-400">Peak rooms needed (busiest slot)</div>
                    </div>
                    <div class="p-3 bg-primary-50 dark:bg-primary-900/30 rounded-lg">
                        <div class="text-2xl font-semibold text-primary-700 dark:text-primary-300">{{ $peakTeachersNeeded }}</div>
                        <div class="text-xs text-gray-500 dark:text-gray-400">Peak teachers needed (busiest slot)</div>
                    </div>
                </div>

                <div class="mt-4 flex items-center gap-2 flex-wrap">
                    <x-btn :href="route('sessions.capacity-simulation.xlsx', [$examSession, ...$this->simulationQuery()])" variant="secondary" icon="download">Download Excel</x-btn>
                    <x-btn :href="route('sessions.capacity-simulation.pdf', [$examSession, ...$this->simulationQuery()])" variant="secondary" icon="download">Download PDF</x-btn>
                </div>

                <x-requirement-table :requirements="$slotRequirements" />
            @endif
        @endif
    </x-card>

    <x-card>
        <div class="flex items-center justify-between gap-4 flex-wrap">
            <div>
                <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">Slot Sharing Check</h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    For every subject that has a slot all to itself in the current timetable: could it share another slot instead &mdash; with no student in common and the same-semester day rule intact &mdash; and if the rooms can't seat both, how many more seats are missing. Each row is judged on its own against the timetable as it stands; to apply sharing for real, turn on <em>Fill spare rooms by letting subjects share a slot</em> in Generation Settings and regenerate the timetable.
                </p>
            </div>
            <x-btn wire:click="checkSharing" wire:loading.attr="disabled" wire:target="checkSharing" variant="secondary" icon="search">
                <span wire:loading.remove wire:target="checkSharing">Check sharing</span>
                <span wire:loading wire:target="checkSharing">Checking&hellip;</span>
            </x-btn>
        </div>

        @if ($showSharing)
            @if (empty($sharingRows))
                <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">No subject has a slot to itself, or there is no timetable yet &mdash; generate the timetable first.</p>
            @else
                @php
                    $fitCount = collect($sharingRows)->where('status', 'fits')->count();
                    $shortCount = collect($sharingRows)->where('status', 'short')->count();
                    $noneCount = collect($sharingRows)->where('status', 'none')->count();
                @endphp
                <div class="mt-4 grid grid-cols-3 gap-4 text-center">
                    <div class="p-3 bg-gray-50 dark:bg-gray-900/50 rounded-lg">
                        <div class="text-2xl font-semibold text-green-700 dark:text-green-400">{{ $fitCount }}</div>
                        <div class="text-xs text-gray-500 dark:text-gray-400">Can share now (frees a slot each)</div>
                    </div>
                    <div class="p-3 bg-gray-50 dark:bg-gray-900/50 rounded-lg">
                        <div class="text-2xl font-semibold text-yellow-700 dark:text-yellow-400">{{ $shortCount }}</div>
                        <div class="text-xs text-gray-500 dark:text-gray-400">Would share, but need more seats</div>
                    </div>
                    <div class="p-3 bg-gray-50 dark:bg-gray-900/50 rounded-lg">
                        <div class="text-2xl font-semibold text-gray-700 dark:text-gray-300">{{ $noneCount }}</div>
                        <div class="text-xs text-gray-500 dark:text-gray-400">No clash-free slot to share</div>
                    </div>
                </div>

                <div class="mt-4 overflow-x-auto -mx-4 sm:-mx-6">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
                        <thead>
                            <tr class="text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">
                                <th class="py-2 pl-4 sm:pl-6 pr-4">Subject</th>
                                <th class="py-2 pr-4">Semester</th>
                                <th class="py-2 pr-4">Students</th>
                                <th class="py-2 pr-4">Now alone in</th>
                                <th class="py-2 pr-4">Could share</th>
                                <th class="py-2 pr-4 sm:pr-6">Result</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            @foreach ($sharingRows as $row)
                                <tr>
                                    <td class="py-2 pl-4 sm:pl-6 pr-4 text-gray-900 dark:text-gray-100">
                                        <span class="font-medium">{{ $row['code'] }}</span>
                                        <span class="text-gray-500 dark:text-gray-400">&mdash; {{ $row['title'] }}</span>
                                    </td>
                                    <td class="py-2 pr-4 text-gray-500 dark:text-gray-400">{{ $row['semester'] }}</td>
                                    <td class="py-2 pr-4 text-gray-500 dark:text-gray-400">{{ $row['students'] }}</td>
                                    <td class="py-2 pr-4 text-gray-500 dark:text-gray-400 whitespace-nowrap">{{ $row['fromLabel'] }}</td>
                                    <td class="py-2 pr-4 text-gray-500 dark:text-gray-400">
                                        @if ($row['toLabel'])
                                            <span class="whitespace-nowrap">{{ $row['toLabel'] }}</span>
                                            <span class="block text-xs">with {{ $row['with'] }}</span>
                                        @else
                                            &mdash;
                                        @endif
                                    </td>
                                    <td class="py-2 pr-4 sm:pr-6">
                                        @if ($row['status'] === 'fits')
                                            <x-badge color="green">Fits</x-badge>
                                        @elseif ($row['status'] === 'short')
                                            <x-badge color="yellow">Needs {{ $row['seatsShort'] }} more {{ $row['seatsShort'] === 1 ? 'seat' : 'seats' }}</x-badge>
                                            @if ($row['roomsHint'] > 0)
                                                <span class="block mt-1 text-xs text-gray-500 dark:text-gray-400">&asymp; {{ $row['roomsHint'] }} more {{ $row['roomsHint'] === 1 ? 'room' : 'rooms' }} of the largest size</span>
                                            @endif
                                        @else
                                            <x-badge color="gray">No clash-free slot</x-badge>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        @endif
    </x-card>
</div>
