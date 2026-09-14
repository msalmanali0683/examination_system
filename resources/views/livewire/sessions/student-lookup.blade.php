<div class="space-y-4">
    <div class="relative max-w-sm">
        <x-icon name="search" class="h-4 w-4 text-gray-400 absolute left-3 top-1/2 -translate-y-1/2" />
        <input type="text" wire:model.live.debounce.300ms="query" placeholder="Roll number or student name&hellip;"
            class="pl-9 w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm">
    </div>

    @if (mb_strlen(trim($query)) < 2)
        <p class="text-sm text-gray-400">Type at least 2 characters to search this session's students.</p>
    @elseif ($groups->isEmpty())
        <x-empty-state icon="search" title="No matching students" description="Try a different roll number or name." />
    @else
        <div class="space-y-4">
            @foreach ($groups as $group)
                <div class="rounded-xl ring-1 ring-gray-200 dark:ring-gray-700/60 overflow-hidden">
                    <div class="bg-gray-50 dark:bg-gray-900/50 px-4 py-2.5 border-b border-gray-100 dark:border-gray-700">
                        <span class="font-medium text-gray-900 dark:text-gray-100">{{ $group->student->name }}</span>
                        <span class="text-xs text-gray-400 ml-2">Roll No: {{ $group->student->roll_no }}</span>
                        @if ($group->student->program)
                            <span class="text-xs text-gray-400 ml-2">{{ $group->student->program }}</span>
                        @endif
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr class="text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">
                                    <th class="py-2 px-4">Subject</th>
                                    <th class="py-2 px-4">Section</th>
                                    <th class="py-2 px-4">Date / Time</th>
                                    <th class="py-2 px-4">Room</th>
                                    <th class="py-2 px-4">Seat</th>
                                    <th class="py-2 px-4">Invigilator(s)</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                @foreach ($group->enrollments as $enrollment)
                                    <tr>
                                        <td class="py-2 px-4 text-gray-900 dark:text-gray-100">{{ $enrollment->subject->code }} &mdash; {{ $enrollment->subject->title }}</td>
                                        <td class="py-2 px-4 text-gray-500 dark:text-gray-400">{{ $enrollment->section }}</td>
                                        <td class="py-2 px-4 text-gray-500 dark:text-gray-400">
                                            @if ($enrollment->seatAssignment?->timeSlot)
                                                {{ $enrollment->seatAssignment->timeSlot->date->format('d M Y') }}, {{ substr($enrollment->seatAssignment->timeSlot->start_time, 0, 5) }}
                                            @else
                                                <span class="text-gray-400">Not scheduled yet</span>
                                            @endif
                                        </td>
                                        <td class="py-2 px-4 text-gray-500 dark:text-gray-400">{{ $enrollment->seatAssignment?->room?->name ?? '—' }}</td>
                                        <td class="py-2 px-4 text-gray-500 dark:text-gray-400">
                                            @if ($enrollment->seatAssignment)
                                                Row {{ $enrollment->seatAssignment->row_number }}, Col {{ $enrollment->seatAssignment->column_number }}
                                            @else
                                                —
                                            @endif
                                        </td>
                                        <td class="py-2 px-4 text-gray-500 dark:text-gray-400">{{ $enrollment->invigilators ?: '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
