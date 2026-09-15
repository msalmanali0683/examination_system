@php
    $palette = ['#FCE4D6', '#DDEBF7', '#E2EFDA', '#FFF2CC', '#EAD1DC', '#D9E1F2', '#FCE9DA'];
    $colorFor = fn ($code) => $palette[crc32($code) % count($palette)];
@endphp
<table style="border-collapse:collapse;width:100%;font-family:Arial,Helvetica,sans-serif;font-size:11px;">
    <tr>
        <td colspan="10" style="text-align:center;font-weight:bold;padding:4px;background:{{ $session->isReportFinal() ? '#C6EFCE' : '#FFEB9C' }};color:{{ $session->isReportFinal() ? '#006100' : '#9C6500' }};border:1px solid #94A3B8;">
            {{ $session->reportStampLabel() }}
        </td>
    </tr>
    <tr>
        <td style="font-weight:bold;background:#C6E0B4;border:1px solid #94A3B8;padding:4px;">Department</td>
        <td style="font-weight:bold;background:#C6E0B4;border:1px solid #94A3B8;padding:4px;">Course Code</td>
        <td style="font-weight:bold;background:#C6E0B4;border:1px solid #94A3B8;padding:4px;">Course Name</td>
        <td style="font-weight:bold;background:#C6E0B4;border:1px solid #94A3B8;padding:4px;">Section Name</td>
        <td style="font-weight:bold;background:#C6E0B4;border:1px solid #94A3B8;padding:4px;">Date</td>
        <td style="font-weight:bold;background:#C6E0B4;border:1px solid #94A3B8;padding:4px;">Day</td>
        <td style="font-weight:bold;background:#C6E0B4;border:1px solid #94A3B8;padding:4px;">Start time</td>
        <td style="font-weight:bold;background:#C6E0B4;border:1px solid #94A3B8;padding:4px;">End time</td>
        <td style="font-weight:bold;background:#C6E0B4;border:1px solid #94A3B8;padding:4px;">Room Description</td>
        <td style="font-weight:bold;background:#C6E0B4;border:1px solid #94A3B8;padding:4px;">Invigilator</td>
    </tr>
    @foreach ($rowsByDate as $dateKey => $rows)
        <tr>
            <td colspan="10" style="text-align:center;font-weight:bold;background:#C6E0B4;border:1px solid #94A3B8;padding:4px;">
                {{ \Illuminate\Support\Carbon::parse($dateKey)->format('d-m-y') }} ({{ \Illuminate\Support\Carbon::parse($dateKey)->format('l') }})
            </td>
        </tr>
        @foreach ($rows as $row)
            <tr style="background-color: {{ $colorFor($row->code) }};">
                <td style="border:1px solid #94A3B8;padding:3px;">{{ $session->effectiveDepartmentName() }}</td>
                <td style="border:1px solid #94A3B8;padding:3px;">{{ $row->code }}</td>
                <td style="border:1px solid #94A3B8;padding:3px;">{{ $row->title }}</td>
                <td style="border:1px solid #94A3B8;padding:3px;">{{ $row->section }}</td>
                <td style="border:1px solid #94A3B8;padding:3px;">{{ $row->date->format('d-m-Y') }}</td>
                <td style="border:1px solid #94A3B8;padding:3px;">{{ $row->day }}</td>
                <td style="border:1px solid #94A3B8;padding:3px;">{{ substr($row->startTime, 0, 5) }}</td>
                <td style="border:1px solid #94A3B8;padding:3px;">{{ substr($row->endTime, 0, 5) }}</td>
                <td style="border:1px solid #94A3B8;padding:3px;">{{ $row->room }}</td>
                <td style="border:1px solid #94A3B8;padding:3px;">{{ $row->invigilator }}</td>
            </tr>
        @endforeach
    @endforeach
</table>
