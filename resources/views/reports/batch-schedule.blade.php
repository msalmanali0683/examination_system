<table style="border-collapse:collapse;width:100%;font-family:Arial,Helvetica,sans-serif;font-size:11px;">
    @foreach ($sections as $group)
        <tr>
            <td colspan="6" style="font-weight:bold;background:#E2EFDA;border:1px solid #94A3B8;padding:4px;">
                Section {{ $group->section }}
            </td>
        </tr>
        <tr>
            <td style="font-weight:bold;border:1px solid #94A3B8;padding:3px;">Course Code</td>
            <td style="font-weight:bold;border:1px solid #94A3B8;padding:3px;">Course Name</td>
            <td style="font-weight:bold;border:1px solid #94A3B8;padding:3px;">Date</td>
            <td style="font-weight:bold;border:1px solid #94A3B8;padding:3px;">Day</td>
            <td style="font-weight:bold;border:1px solid #94A3B8;padding:3px;">Time</td>
            <td style="font-weight:bold;border:1px solid #94A3B8;padding:3px;">Room / Invigilator</td>
        </tr>
        @foreach ($group->rows as $row)
            <tr>
                <td style="border:1px solid #94A3B8;padding:3px;">{{ $row->code }}</td>
                <td style="border:1px solid #94A3B8;padding:3px;">{{ $row->title }}</td>
                <td style="border:1px solid #94A3B8;padding:3px;">{{ $row->date->format('d-m-Y') }}</td>
                <td style="border:1px solid #94A3B8;padding:3px;">{{ $row->day }}</td>
                <td style="border:1px solid #94A3B8;padding:3px;">{{ substr($row->startTime, 0, 5) }} &ndash; {{ substr($row->endTime, 0, 5) }}</td>
                <td style="border:1px solid #94A3B8;padding:3px;">{{ $row->room }} &mdash; {{ $row->invigilator }}</td>
            </tr>
        @endforeach
        <tr><td colspan="6" style="border:none;padding-top:10px;"></td></tr>
    @endforeach
</table>
