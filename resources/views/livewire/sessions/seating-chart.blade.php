<x-slot name="header">
    <div class="flex items-center justify-between">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Seating') }} &mdash; {{ $examSession->name }}
        </h2>
        <a href="{{ route('sessions.generate', $examSession) }}" wire:navigate class="text-sm text-indigo-600 hover:underline">&larr; Back to Generation</a>
    </div>
</x-slot>

<div class="py-12">
    <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-6">

        @forelse ($bySlotAndRoom as $slotId => $rooms)
            @php $firstSeat = $rooms->first()->first(); @endphp
            <div class="p-4 sm:p-8 bg-white dark:bg-gray-800 shadow sm:rounded-lg">
                <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                    {{ $firstSeat->timeSlot->date->format('d M Y') }} &middot; {{ substr($firstSeat->timeSlot->start_time, 0, 5) }}&ndash;{{ substr($firstSeat->timeSlot->end_time, 0, 5) }}
                    @if ($firstSeat->timeSlot->label)
                        ({{ $firstSeat->timeSlot->label }})
                    @endif
                </h3>

                <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-6">
                    @foreach ($rooms as $roomId => $roomSeats)
                        @php $room = $roomSeats->first()->room; @endphp
                        <div class="border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden">
                            <div class="bg-gray-50 dark:bg-gray-900/50 px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-300 flex items-center justify-between">
                                <span>{{ $room->name }}</span>
                                <span class="text-xs text-gray-400">{{ $roomSeats->count() }} / {{ $room->capacity }} seated</span>
                            </div>
                            <table class="min-w-full text-sm">
                                <thead>
                                    <tr class="text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase border-b border-gray-100 dark:border-gray-700">
                                        <th class="py-1 px-3">Col</th>
                                        <th class="py-1 px-3">Row</th>
                                        <th class="py-1 px-3">Roll No</th>
                                        <th class="py-1 px-3">Subject</th>
                                        <th class="py-1 px-3"></th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                    @foreach ($roomSeats as $seat)
                                        <tr>
                                            <td class="py-1 px-3 text-gray-500 dark:text-gray-400">{{ $seat->column_number }}</td>
                                            <td class="py-1 px-3 text-gray-500 dark:text-gray-400">{{ $seat->row_number }}</td>
                                            <td class="py-1 px-3 text-gray-900 dark:text-gray-100">{{ $seat->enrollment->student->roll_no }}</td>
                                            <td class="py-1 px-3 text-gray-500 dark:text-gray-400">{{ $seat->enrollment->subject->code }}</td>
                                            <td class="py-1 px-3">
                                                @if ($seat->is_locked)
                                                    <span class="text-xs text-amber-600" title="Locked">&#128274;</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endforeach
                </div>
            </div>
        @empty
            <div class="p-4 sm:p-8 bg-white dark:bg-gray-800 shadow sm:rounded-lg text-center text-sm text-gray-500 dark:text-gray-400">
                No seating generated yet.
            </div>
        @endforelse
    </div>
</div>
