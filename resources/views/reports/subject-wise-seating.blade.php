<table style="border-collapse:collapse;width:100%;font-family:Arial,Helvetica,sans-serif;font-size:11px;">
    <tr>
        <td colspan="7" style="text-align:center;font-weight:bold;padding:4px;background:{{ $session->isReportFinal() ? '#C6EFCE' : '#FFEB9C' }};color:{{ $session->isReportFinal() ? '#006100' : '#9C6500' }};border:1px solid #94A3B8;">
            {{ $session->reportStampLabel() }}
        </td>
    </tr>
    @foreach ($subjects as $subject)
        <tr>
            <td colspan="7" style="font-weight:bold;background:#DDEBF7;border:1px solid #94A3B8;padding:4px;">
                {{ $subject->code }} &mdash; {{ $subject->title }} ({{ $subject->rows->count() }} student{{ $subject->rows->count() === 1 ? '' : 's' }})
            </td>
        </tr>
        <tr>
            <td style="font-weight:bold;border:1px solid #94A3B8;padding:3px;">Roll No</td>
            <td style="font-weight:bold;border:1px solid #94A3B8;padding:3px;">Student Name</td>
            <td style="font-weight:bold;border:1px solid #94A3B8;padding:3px;">Section</td>
            <td style="font-weight:bold;border:1px solid #94A3B8;padding:3px;">Date / Time</td>
            <td style="font-weight:bold;border:1px solid #94A3B8;padding:3px;">Room</td>
            <td style="font-weight:bold;border:1px solid #94A3B8;padding:3px;">Seat</td>
            <td style="font-weight:bold;border:1px solid #94A3B8;padding:3px;">Invigilator(s)</td>
        </tr>
        @foreach ($subject->rows as $row)
            <tr>
                <td style="border:1px solid #94A3B8;padding:3px;">{{ $row->rollNo }}</td>
                <td style="border:1px solid #94A3B8;padding:3px;">{{ $row->studentName }}</td>
                <td style="border:1px solid #94A3B8;padding:3px;">{{ $row->section }}</td>
                <td style="border:1px solid #94A3B8;padding:3px;">{{ $row->date->format('d-m-Y') }}, {{ substr($row->startTime, 0, 5) }}</td>
                <td style="border:1px solid #94A3B8;padding:3px;">{{ $row->room }}</td>
                <td style="border:1px solid #94A3B8;padding:3px;">{{ $row->seat }}</td>
                <td style="border:1px solid #94A3B8;padding:3px;">{{ $row->invigilator }}</td>
            </tr>
        @endforeach
        <tr><td colspan="7" style="border:none;padding-top:10px;"></td></tr>
    @endforeach
</table>
