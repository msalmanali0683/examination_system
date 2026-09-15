<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    @page { margin: 1cm; }
    body { font-family: Arial, Helvetica, sans-serif; }
    h1 { text-align: center; font-size: 15px; margin: 0 0 2px; }
    h2 { text-align: center; font-size: 12px; margin: 0 0 12px; font-weight: normal; color: #444; }
</style>
</head>
<body>
    <h1>{{ config('exam.university_name') }} &mdash; {{ $session->effectiveDepartmentName() }}</h1>
    <h2>Invigilation Duty Roster: {{ $session->name }}</h2>
    @include('reports.duty-sheet', ['teacherGroups' => $teacherGroups, 'session' => $session, 'showRoomSubject' => $showRoomSubject ?? true])
</body>
</html>
