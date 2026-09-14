<x-mail::message>
# Invigilation Duty — {{ $examSession->name }}

Dear {{ $teacher->name }},

You have been assigned **{{ $dutyCount }}** invigilation {{ Str::plural('duty', $dutyCount) }} for **{{ $examSession->name }}**. Your full schedule — dates, times, rooms and subjects — is attached as a PDF.

Please review it and contact the exam coordinator if you notice a conflict.

Thanks,<br>
{{ config('exam.department_name') }}
</x-mail::message>
