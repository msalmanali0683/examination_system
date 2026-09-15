<table style="border-collapse:collapse;width:100%;font-family:Arial,Helvetica,sans-serif;font-size:11px;">
    <tr>
        <td colspan="5" style="text-align:center;font-weight:bold;padding:4px;background:{{ $session->isReportFinal() ? '#C6EFCE' : '#FFEB9C' }};color:{{ $session->isReportFinal() ? '#006100' : '#9C6500' }};border:1px solid #94A3B8;">
            {{ $session->reportStampLabel() }}
        </td>
    </tr>
    @foreach ($teacherGroups as $group)
        <tr>
            <td colspan="5" style="font-weight:bold;background:#DDEBF7;border:1px solid #94A3B8;padding:4px;">
                {{ $group->teacher->name }}{{ $group->teacher->designation ? ' ('.$group->teacher->designation.')' : '' }} &mdash; {{ $group->duties->count() }} {{ Str::plural('duty', $group->duties->count()) }}
            </td>
        </tr>
        <tr>
            <td style="font-weight:bold;border:1px solid #94A3B8;padding:3px;">Date</td>
            <td style="font-weight:bold;border:1px solid #94A3B8;padding:3px;">Day</td>
            <td style="font-weight:bold;border:1px solid #94A3B8;padding:3px;">Time</td>
            <td style="font-weight:bold;border:1px solid #94A3B8;padding:3px;">Room</td>
            <td style="font-weight:bold;border:1px solid #94A3B8;padding:3px;">Subject(s)</td>
        </tr>
        @foreach ($group->duties as $duty)
            <tr>
                <td style="border:1px solid #94A3B8;padding:3px;">{{ $duty->date->format('d-m-Y') }}</td>
                <td style="border:1px solid #94A3B8;padding:3px;">{{ $duty->day }}</td>
                <td style="border:1px solid #94A3B8;padding:3px;">{{ substr($duty->startTime, 0, 5) }} &ndash; {{ substr($duty->endTime, 0, 5) }}</td>
                <td style="border:1px solid #94A3B8;padding:3px;">{{ $duty->room }}</td>
                <td style="border:1px solid #94A3B8;padding:3px;">{{ $duty->subjects }}</td>
            </tr>
        @endforeach
        <tr><td colspan="5" style="border:none;padding-top:10px;"></td></tr>
    @endforeach
</table>
