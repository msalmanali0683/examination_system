<table style="border-collapse:collapse;width:100%;font-family:'Times New Roman',Times,serif;font-size:11px;">
    <tr>
        <td colspan="4" style="text-align:center;font-weight:bold;font-size:14px;padding:6px;border:1px solid #000000;">
            Attendance Sheet {{ $session->name }}<br>
            {{ \Illuminate\Support\Carbon::parse($dateKey)->format('l') }} ({{ \Illuminate\Support\Carbon::parse($dateKey)->format('d-m-Y') }})
        </td>
    </tr>
    <tr>
        <td style="font-weight:bold;text-align:center;border:1px solid #000000;padding:4px;">Teacher Name</td>
        <td style="font-weight:bold;text-align:center;border:1px solid #000000;padding:4px;">Time</td>
        <td style="font-weight:bold;text-align:center;border:1px solid #000000;padding:4px;">Room #</td>
        <td style="font-weight:bold;text-align:center;border:1px solid #000000;padding:4px;">Signature</td>
    </tr>
    @foreach ($rows as $row)
        <tr>
            <td style="border:1px solid #000000;padding:4px;">{{ $row->teacherName }}</td>
            <td style="text-align:center;border:1px solid #000000;padding:4px;">{{ substr($row->startTime, 0, 5) }} &ndash; {{ substr($row->endTime, 0, 5) }}</td>
            <td style="text-align:center;border:1px solid #000000;padding:4px;">{{ $row->room }}</td>
            <td style="border:1px solid #000000;padding:16px;">&nbsp;</td>
        </tr>
    @endforeach
</table>
