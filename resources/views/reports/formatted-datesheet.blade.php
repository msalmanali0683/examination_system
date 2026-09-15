@php
    $th = 'font-weight:bold;background:#C0C0C0;border:1px solid #000000;padding:4px;vertical-align:top;';
    $td = 'border:1px solid #000000;padding:3px;text-align:center;vertical-align:top;';
    $maxRooms = max(1, $rows->max(fn ($r) => $r->rooms->count()) ?? 1);
@endphp
<table style="border-collapse:collapse;width:100%;font-family:Arial,Helvetica,sans-serif;font-size:11px;">
    <tr>
        <td style="{{ $th }}">Building/Block Name</td>
        <td style="{{ $th }}">Department Name</td>
        <td style="{{ $th }}">Module (Abbrev.)</td>
        <td style="{{ $th }}">Module (Desc.)</td>
        <td style="{{ $th }}">Semester</td>
        <td style="{{ $th }}">Event Package (Abbrev.)</td>
        <td style="{{ $th }}">Event Package (Description)</td>
        <td style="{{ $th }}">Student Count</td>
        <td style="{{ $th }}">Date</td>
        <td style="{{ $th }}">Day</td>
        <td style="{{ $th }}">Slot</td>
        @for ($i = 1; $i <= $maxRooms; $i++)
            <td style="{{ $th }}">Room {{ $i }}</td>
            <td style="{{ $th }}">Room {{ $i }} Count</td>
            <td style="{{ $th }}">Invigilator Room {{ $i }}</td>
        @endfor
    </tr>
    @foreach ($rows as $row)
        <tr>
            <td style="{{ $td }}">{{ config('exam.datesheet_building_block') }}</td>
            <td style="{{ $td }}">{{ $session->effectiveDepartmentName() }}</td>
            <td style="{{ $td }}">{{ $row->subject->code }}</td>
            <td style="{{ $td }}">{{ $row->subject->title }}</td>
            <td style="{{ $td }}">{{ $row->semester }}</td>
            <td style="{{ $td }}">{{ config('exam.datesheet_event_package') }}</td>
            <td style="{{ $td }}">{{ config('exam.datesheet_event_package') }}</td>
            <td style="{{ $td }}">{{ $row->studentCount }}</td>
            <td style="{{ $td }}">{{ $row->timeSlot->date->format('d-m-Y') }}</td>
            <td style="{{ $td }}">{{ $row->timeSlot->date->format('l') }}</td>
            <td style="{{ $td }}">{{ substr($row->timeSlot->start_time, 0, 5) }} - {{ substr($row->timeSlot->end_time, 0, 5) }}</td>
            @for ($i = 0; $i < $maxRooms; $i++)
                @php $r = $row->rooms->get($i); @endphp
                <td style="{{ $td }}">{{ $r?->room }}</td>
                <td style="{{ $td }}">{{ $r?->count }}</td>
                <td style="{{ $td }}">{{ ($showInvigilators ?? true) ? $r?->invigilator : '' }}</td>
            @endfor
        </tr>
    @endforeach
</table>
