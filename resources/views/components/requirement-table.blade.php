@props(['requirements'])

@if ($requirements->isEmpty())
    <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">No subjects are assigned to a slot yet — generate the timetable first.</p>
@else
    <div class="mt-4 overflow-x-auto -mx-4 sm:-mx-6">
        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
            <thead>
                <tr class="text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">
                    <th class="py-2 pl-4 sm:pl-6 pr-4">Slot</th>
                    <th class="py-2 pr-4">Seats Needed / Available</th>
                    <th class="py-2 pr-4">Rooms Needed / Active</th>
                    <th class="py-2 pr-4">Teachers Needed / Available</th>
                    <th class="py-2 pr-4 sm:pr-6">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                @foreach ($requirements as $r)
                    <tr>
                        <td class="py-2 pl-4 sm:pl-6 pr-4 text-gray-900 dark:text-gray-100">{{ $r->label }}</td>
                        <td @class(['py-2 pr-4', 'text-red-600 dark:text-red-400 font-medium' => $r->seatsShortfall() > 0, 'text-gray-500 dark:text-gray-400' => $r->seatsShortfall() === 0])>
                            {{ $r->studentCount }} / {{ $r->seatsAvailable }}
                            @if ($r->seatsShortfall() > 0)
                                ({{ $r->seatsShortfall() }} short)
                            @endif
                        </td>
                        <td @class(['py-2 pr-4', 'text-red-600 dark:text-red-400 font-medium' => $r->roomsShortfall() > 0, 'text-gray-500 dark:text-gray-400' => $r->roomsShortfall() === 0])>
                            {{ $r->roomsNeeded }} / {{ $r->roomsAvailable }}
                            @if ($r->roomsShortfall() > 0)
                                ({{ $r->roomsShortfall() }} more needed)
                            @endif
                        </td>
                        <td @class(['py-2 pr-4', 'text-red-600 dark:text-red-400 font-medium' => $r->teachersShortfall() > 0, 'text-gray-500 dark:text-gray-400' => $r->teachersShortfall() === 0])>
                            {{ $r->teachersNeeded }} / {{ $r->teachersAvailable }}
                            @if ($r->teachersShortfall() > 0)
                                ({{ $r->teachersShortfall() }} more needed)
                            @endif
                        </td>
                        <td class="py-2 pr-4 sm:pr-6">
                            @if ($r->isMet())
                                <x-badge color="green">Ready</x-badge>
                            @elseif ($r->hasUnseatedStudents || $r->roomsShortfall() > 0 || $r->teachersShortfall() > 0)
                                <x-badge color="red">Short</x-badge>
                            @else
                                <x-badge color="yellow">Clash</x-badge>
                            @endif
                            @if (! empty($r->clashDetails))
                                <div class="mt-1 space-y-0.5 max-w-xs">
                                    @foreach ($r->clashDetails as $detail)
                                        <p class="text-xs text-yellow-700 dark:text-yellow-400">{{ $detail }}</p>
                                    @endforeach
                                </div>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
