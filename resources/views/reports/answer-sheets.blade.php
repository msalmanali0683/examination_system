@php
    $cell = 'border:1px solid #94A3B8;padding:3px;';
    $head = 'font-weight:bold;background:#C6E0B4;border:1px solid #94A3B8;padding:4px;';
@endphp
<table style="border-collapse:collapse;width:100%;font-family:Arial,Helvetica,sans-serif;font-size:11px;">
    <tr>
        <td colspan="7" style="text-align:center;font-weight:bold;padding:4px;background:{{ $session->isReportFinal() ? '#C6EFCE' : '#FFEB9C' }};color:{{ $session->isReportFinal() ? '#006100' : '#9C6500' }};border:1px solid #94A3B8;">
            {{ $session->reportStampLabel() }}
        </td>
    </tr>
    <tr>
        <td style="{{ $head }}">Slot</td>
        <td style="{{ $head }}">Date</td>
        <td style="{{ $head }}">Day</td>
        <td style="{{ $head }}">Time</td>
        <td style="{{ $head }}">Subject</td>
        <td style="{{ $head }}text-align:right;">Students</td>
        <td style="{{ $head }}text-align:right;">Answer Sheets</td>
    </tr>

    @forelse ($report->slots as $slot)
        @foreach ($slot->subjects as $subject)
            <tr>
                <td style="{{ $cell }}">{{ $loop->first ? 'Slot '.$slot->number : '' }}</td>
                <td style="{{ $cell }}">{{ $loop->first ? $slot->date->format('d-m-Y') : '' }}</td>
                <td style="{{ $cell }}">{{ $loop->first ? $slot->day : '' }}</td>
                <td style="{{ $cell }}">{{ $loop->first ? $slot->time : '' }}</td>
                <td style="{{ $cell }}">{{ $subject->title }}</td>
                <td style="{{ $cell }}text-align:right;">{{ $subject->students }}</td>
                <td style="{{ $cell }}text-align:right;">{{ $subject->students }}</td>
            </tr>
        @endforeach
        <tr>
            <td colspan="5" style="{{ $cell }}font-weight:bold;background:#F2F2F2;text-align:right;">Slot {{ $slot->number }} total</td>
            <td style="{{ $cell }}font-weight:bold;background:#F2F2F2;text-align:right;">{{ $slot->students }}</td>
            <td style="{{ $cell }}font-weight:bold;background:#F2F2F2;text-align:right;">{{ $slot->sheets }}</td>
        </tr>
    @empty
        <tr>
            <td colspan="7" style="{{ $cell }}text-align:center;">No subjects are scheduled with enrolled students yet.</td>
        </tr>
    @endforelse

    <tr>
        <td colspan="5" style="{{ $head }}text-align:right;font-size:12px;">GRAND TOTAL ({{ $report->slots->count() }} {{ $report->slots->count() === 1 ? 'slot' : 'slots' }})</td>
        <td style="{{ $head }}text-align:right;font-size:12px;">{{ $report->slots->sum('students') }}</td>
        <td style="{{ $head }}text-align:right;font-size:12px;">{{ $report->grandTotal }}</td>
    </tr>

    @if ($report->unscheduledSubjects > 0)
        <tr>
            <td colspan="7" style="padding:4px;color:#9C0006;">
                Note: {{ $report->unscheduledSubjects }} enrolled {{ $report->unscheduledSubjects === 1 ? 'subject' : 'subjects' }} ({{ $report->unscheduledStudents }} students) {{ $report->unscheduledSubjects === 1 ? 'is' : 'are' }} not on the timetable yet and not included above.
            </td>
        </tr>
    @endif
</table>
