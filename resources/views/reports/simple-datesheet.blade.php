<table style="border-collapse:collapse;width:100%;font-family:Arial,Helvetica,sans-serif;font-size:11px;">
    <tr>
        <td colspan="5" style="text-align:center;font-weight:bold;padding:4px;background:{{ $session->isReportFinal() ? '#C6EFCE' : '#FFEB9C' }};color:{{ $session->isReportFinal() ? '#006100' : '#9C6500' }};border:1px solid #94A3B8;">
            {{ $session->reportStampLabel() }}
        </td>
    </tr>
    <tr>
        <td style="font-weight:bold;background:#C6E0B4;border:1px solid #94A3B8;padding:4px;">Date</td>
        <td style="font-weight:bold;background:#C6E0B4;border:1px solid #94A3B8;padding:4px;">Day</td>
        <td style="font-weight:bold;background:#C6E0B4;border:1px solid #94A3B8;padding:4px;">Subject</td>
        <td style="font-weight:bold;background:#C6E0B4;border:1px solid #94A3B8;padding:4px;">Semester</td>
        <td style="font-weight:bold;background:#C6E0B4;border:1px solid #94A3B8;padding:4px;">Slot</td>
    </tr>
    @foreach ($rowsByDate as $dateKey => $rows)
        <tr>
            <td colspan="5" style="text-align:center;font-weight:bold;background:#C6E0B4;border:1px solid #94A3B8;padding:4px;">
                {{ \Illuminate\Support\Carbon::parse($dateKey)->format('d-m-y') }} ({{ \Illuminate\Support\Carbon::parse($dateKey)->format('l') }})
            </td>
        </tr>
        @foreach ($rows as $row)
            <tr>
                <td style="border:1px solid #94A3B8;padding:3px;">{{ $row->date->format('d-m-Y') }}</td>
                <td style="border:1px solid #94A3B8;padding:3px;">{{ $row->day }}</td>
                <td style="border:1px solid #94A3B8;padding:3px;">{{ $row->title }}</td>
                <td style="border:1px solid #94A3B8;padding:3px;">{{ $row->semester }}</td>
                <td style="border:1px solid #94A3B8;padding:3px;">{{ $row->slot }}</td>
            </tr>
        @endforeach
    @endforeach
</table>
