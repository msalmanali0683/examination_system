<table style="border-collapse:collapse;width:100%;font-family:Arial,Helvetica,sans-serif;font-size:11px;">
    <tr>
        <td colspan="4" style="text-align:center;font-weight:bold;padding:4px;background:{{ $session->isReportFinal() ? '#C6EFCE' : '#FFEB9C' }};color:{{ $session->isReportFinal() ? '#006100' : '#9C6500' }};border:1px solid #94A3B8;">
            {{ $session->reportStampLabel() }}
        </td>
    </tr>
    <tr>
        <td colspan="4" style="text-align:center;font-style:italic;padding:3px;border:1px solid #94A3B8;">
            A what-if preview from the current enrollment data — not the real timetable or seating plan.
            @if ($min || $max)
                Simulated with
                @if ($min) a minimum of {{ $min }} @endif
                @if ($min && $max) and @endif
                @if ($max) a maximum of {{ $max }} @endif
                subject(s) per slot.
            @endif
        </td>
    </tr>

    <tr>
        <td colspan="4" style="text-align:center;font-weight:bold;background:#C6E0B4;border:1px solid #94A3B8;padding:4px;">Seats Required Per Subject</td>
    </tr>
    <tr>
        <td style="font-weight:bold;background:#DCE6F1;border:1px solid #94A3B8;padding:4px;">Code</td>
        <td style="font-weight:bold;background:#DCE6F1;border:1px solid #94A3B8;padding:4px;">Subject</td>
        <td style="font-weight:bold;background:#DCE6F1;border:1px solid #94A3B8;padding:4px;">Section(s)</td>
        <td style="font-weight:bold;background:#DCE6F1;border:1px solid #94A3B8;padding:4px;">Seats Required</td>
    </tr>
    @forelse ($subjectRequirements as $row)
        <tr>
            <td style="border:1px solid #94A3B8;padding:3px;">{{ $row['code'] }}</td>
            <td style="border:1px solid #94A3B8;padding:3px;">{{ $row['title'] }}</td>
            <td style="border:1px solid #94A3B8;padding:3px;">{{ $row['sections'] }}</td>
            <td style="border:1px solid #94A3B8;padding:3px;text-align:center;">{{ $row['count'] }}</td>
        </tr>
    @empty
        <tr>
            <td colspan="4" style="border:1px solid #94A3B8;padding:4px;text-align:center;">No enrollments yet.</td>
        </tr>
    @endforelse

    <tr><td colspan="4">&nbsp;</td></tr>

    <tr>
        <td colspan="4" style="text-align:center;font-weight:bold;background:#C6E0B4;border:1px solid #94A3B8;padding:4px;">Simulated Room Allocation</td>
    </tr>
    @forelse ($slotRequirements as $slot)
        <tr>
            <td colspan="4" style="font-weight:bold;background:#FCE4D6;border:1px solid #94A3B8;padding:4px;">
                {{ $slot->label }} &mdash; {{ $slot->studentCount }} student(s), {{ $slot->roomsNeeded }} room(s) needed
                @if ($slot->hasUnseatedStudents)
                    &mdash; SHORT ON SEATS
                @endif
            </td>
        </tr>
        @if (empty($slot->roomBreakdown))
            <tr>
                <td colspan="4" style="border:1px solid #94A3B8;padding:4px;text-align:center;">No active rooms could seat this slot.</td>
            </tr>
        @else
            <tr>
                <td style="font-weight:bold;background:#DCE6F1;border:1px solid #94A3B8;padding:4px;">Room</td>
                <td style="font-weight:bold;background:#DCE6F1;border:1px solid #94A3B8;padding:4px;">Capacity</td>
                <td style="font-weight:bold;background:#DCE6F1;border:1px solid #94A3B8;padding:4px;">Filled By (Section: Count)</td>
                <td style="font-weight:bold;background:#DCE6F1;border:1px solid #94A3B8;padding:4px;">Filled / Remaining</td>
            </tr>
            @foreach ($slot->roomBreakdown as $room)
                <tr>
                    <td style="border:1px solid #94A3B8;padding:3px;">{{ $room['roomName'] }}</td>
                    <td style="border:1px solid #94A3B8;padding:3px;text-align:center;">{{ $room['capacity'] }}</td>
                    <td style="border:1px solid #94A3B8;padding:3px;">
                        @foreach ($room['sections'] as $sec)
                            {{ $sec['subjectCode'] }} &ndash; {{ $sec['subjectTitle'] }} ({{ $sec['section'] }}): {{ $sec['count'] }}@if (! $loop->last)<br>@endif
                        @endforeach
                    </td>
                    <td style="border:1px solid #94A3B8;padding:3px;text-align:center;">{{ $room['filled'] }} / {{ $room['remaining'] }} free</td>
                </tr>
            @endforeach
        @endif
        @if (! empty($slot->unseatedBreakdown))
            <tr>
                <td colspan="2" style="border:1px solid #94A3B8;padding:3px;font-weight:bold;color:#C00000;">No room left &mdash; not seated</td>
                <td colspan="2" style="border:1px solid #94A3B8;padding:3px;font-weight:bold;color:#C00000;">
                    @foreach ($slot->unseatedBreakdown as $sec)
                        {{ $sec['subjectCode'] }} &ndash; {{ $sec['subjectTitle'] }} ({{ $sec['section'] }}): {{ $sec['count'] }} student{{ $sec['count'] === 1 ? '' : 's' }}@if (! $loop->last)<br>@endif
                    @endforeach
                </td>
            </tr>
        @endif
    @empty
        <tr>
            <td colspan="4" style="border:1px solid #94A3B8;padding:4px;text-align:center;">No enrollments yet to simulate.</td>
        </tr>
    @endforelse
</table>
