@php
    $room = $chart->room;
    $slot = $chart->timeSlot;
    $subjectLine = $chart->subjectsSections->map(fn ($ss) => $ss->subject->title)->unique()->implode(' / ');
    $sectionLine = $chart->subjectsSections->pluck('section')->unique()->implode(', ');
    $span = max(2, $room->columns * 2);
    $half = (int) ceil($span / 2);
@endphp
<table style="border-collapse:collapse;width:100%;font-family:Arial,Helvetica,sans-serif;font-size:12px;">
    <tr>
        <td colspan="{{ $span }}" style="text-align:center;font-weight:bold;padding:3px;background:{{ $session->isReportFinal() ? '#C6EFCE' : '#FFEB9C' }};color:{{ $session->isReportFinal() ? '#006100' : '#9C6500' }};border:1px solid #94A3B8;">
            {{ $session->reportStampLabel() }}
        </td>
    </tr>
    <tr><td colspan="{{ $span }}" style="text-align:center;font-weight:bold;font-size:15px;border:none;padding:2px;">{{ config('exam.university_name') }}</td></tr>
    <tr><td colspan="{{ $span }}" style="text-align:center;font-weight:bold;border:none;padding:2px;">{{ $session->effectiveDepartmentName() }}</td></tr>
    <tr><td colspan="{{ $span }}" style="text-align:center;font-weight:bold;border:none;padding:6px 2px 4px;">Sitting Plan for {{ $subjectLine }}</td></tr>
    <tr>
        <td colspan="{{ $half }}" style="border:none;padding:2px;"><strong>Section:</strong> {{ $sectionLine }}</td>
        <td colspan="{{ $span - $half }}" style="border:none;padding:2px;"><strong>Date:</strong> {{ $slot->date->format('d/m/Y') }}</td>
    </tr>
    <tr>
        <td colspan="{{ $half }}" style="border:none;padding:2px;"><strong>Room #:</strong> {{ $room->name }}</td>
        <td colspan="{{ $span - $half }}" style="border:none;padding:2px;"><strong>Time:</strong> {{ substr($slot->start_time, 0, 5) }} &ndash; {{ substr($slot->end_time, 0, 5) }}</td>
    </tr>
    <tr><td colspan="{{ $span }}" style="border:none;padding:2px 2px 8px;"><strong>Invigilator(s):</strong> {{ $chart->teacherNames->implode(' & ') ?: '—' }}</td></tr>
    <tr>
        @for ($c = 1; $c <= $room->columns; $c++)
            <td colspan="2" style="text-align:center;font-weight:bold;background:#DCE6F1;border:1px solid #94A3B8;padding:3px;">Row # {{ $c }}</td>
        @endfor
    </tr>
    @for ($r = 1; $r <= $room->rows; $r++)
        <tr>
            @for ($c = 1; $c <= $room->columns; $c++)
                @php $seat = $chart->grid[$c][$r] ?? null; @endphp
                <td style="text-align:center;border:1px solid #CBD5E1;padding:2px 4px;width:28px;">{{ $seat ? $r : '' }}</td>
                <td style="text-align:center;border:1px solid #CBD5E1;padding:2px 4px;">{{ $seat?->enrollment?->student?->roll_no }}</td>
            @endfor
        </tr>
    @endfor
</table>
