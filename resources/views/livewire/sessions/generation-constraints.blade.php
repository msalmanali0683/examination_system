<x-slot name="header">
    <div class="flex items-center justify-between">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Generation Constraints') }} &mdash; {{ $examSession->name }}
        </h2>
        <a href="{{ route('sessions.show', $examSession) }}" wire:navigate class="text-sm text-indigo-600 hover:underline">&larr; Back to Session</a>
    </div>
</x-slot>

<div class="py-12">
    <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-6">

        @if (session('status'))
            <div class="p-4 bg-green-50 dark:bg-green-900/40 text-green-700 dark:text-green-300 rounded-lg">
                {{ session('status') }}
            </div>
        @endif

        @if (session('error'))
            <div class="p-4 bg-yellow-50 dark:bg-yellow-900/40 text-yellow-700 dark:text-yellow-300 rounded-lg">
                {{ session('error') }}
            </div>
        @endif

        <div class="p-4 sm:p-8 bg-white dark:bg-gray-800 shadow sm:rounded-lg">
            <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">Generation Settings</h3>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">These apply to seating and duty generation (later steps).</p>

            <form wire:submit="saveSettings" class="mt-4 grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Seating Strategy</label>
                    <select wire:model="seating_strategy" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 shadow-sm">
                        <option value="strict">Strict (one room per subject+section)</option>
                        <option value="combine_sections">Combine sections of the same subject</option>
                        <option value="mixed">Mix different subjects (with adjacency avoidance)</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Invigilators per Room</label>
                    <input type="number" min="1" max="10" wire:model="invigilators_per_room" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 shadow-sm">
                </div>
                <div class="flex items-end">
                    <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300 mb-2">
                        <input type="checkbox" wire:model="teacher_subject_exclusion" class="rounded border-gray-300">
                        Avoid assigning a teacher to invigilate their own subject
                    </label>
                </div>
                <div class="sm:col-span-3">
                    <button type="submit" class="px-4 py-2 bg-gray-800 text-white text-sm rounded-md hover:bg-gray-700">
                        Save Settings
                    </button>
                </div>
            </form>
        </div>

        <div class="p-4 sm:p-8 bg-white dark:bg-gray-800 shadow sm:rounded-lg">
            <div class="flex items-center justify-between">
                <div>
                    <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">Pin Subjects to Slots</h3>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Optional. Everything else is placed automatically, clash-free where possible.</p>
                </div>
                <button type="button" wire:click="generateTimetable" wire:loading.attr="disabled" wire:confirm="Regenerate the timetable? Pinned subjects are left untouched; everything else will be recomputed." class="px-4 py-2 bg-indigo-600 text-white text-sm rounded-md hover:bg-indigo-500 disabled:opacity-50 whitespace-nowrap">
                    <span wire:loading.remove wire:target="generateTimetable">Generate Timetable</span>
                    <span wire:loading wire:target="generateTimetable">Generating&hellip;</span>
                </button>
            </div>

            @if ($timeSlots->isEmpty())
                <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">No time slots yet &mdash; add some on the session's Time Slots tab first.</p>
            @elseif ($subjects->isEmpty())
                <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">No enrollments yet &mdash; import enrollments first.</p>
            @else
                <div class="mt-4 overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead>
                            <tr class="text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">
                                <th class="py-2 pr-4">Subject</th>
                                <th class="py-2 pr-4">Students</th>
                                <th class="py-2 pr-4">Assigned Slot</th>
                                <th class="py-2 pr-4">Pin</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            @foreach ($subjects as $subject)
                                @php $assignment = $assignments->get($subject->id); @endphp
                                <tr>
                                    <td class="py-2 pr-4 text-gray-900 dark:text-gray-100">{{ $subject->code }} &mdash; {{ $subject->title }}</td>
                                    <td class="py-2 pr-4 text-gray-500 dark:text-gray-400">{{ $subject->enrollments_count }}</td>
                                    <td class="py-2 pr-4 text-gray-500 dark:text-gray-400">
                                        @if ($assignment?->timeSlot)
                                            {{ $assignment->timeSlot->date->format('d M') }} {{ substr($assignment->timeSlot->start_time, 0, 5) }}
                                            @if ($assignment->conflict_note)
                                                <span class="text-yellow-600 dark:text-yellow-400" title="{{ $assignment->conflict_note }}">&#9888;</span>
                                            @endif
                                        @else
                                            <span class="text-gray-400">Not yet generated</span>
                                        @endif
                                    </td>
                                    <td class="py-2 pr-4">
                                        <select wire:change="updatePin({{ $subject->id }}, $event.target.value)" class="rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm">
                                            <option value="">Auto</option>
                                            @foreach ($timeSlots as $slot)
                                                <option value="{{ $slot->id }}" @selected($assignment?->is_pinned && $assignment->time_slot_id === $slot->id)>
                                                    {{ $slot->date->format('d M') }} {{ substr($slot->start_time, 0, 5) }} {{ $slot->label ? "({$slot->label})" : '' }}
                                                </option>
                                            @endforeach
                                        </select>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        @if ($conflicted->isNotEmpty())
            <div class="p-4 sm:p-8 bg-white dark:bg-gray-800 shadow sm:rounded-lg">
                <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">Unavoidable Clashes</h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    The algorithm couldn't find a fully clash-free slot for these — resolve manually by adding a time slot or pinning one of the subjects elsewhere.
                </p>
                <ul class="mt-4 space-y-2">
                    @foreach ($conflicted as $assignment)
                        <li class="text-sm text-yellow-700 dark:text-yellow-400">{{ $assignment->conflict_note }}</li>
                    @endforeach
                </ul>
            </div>
        @endif
    </div>
</div>
