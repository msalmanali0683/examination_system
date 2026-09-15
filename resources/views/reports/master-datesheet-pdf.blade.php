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
    <h2>Exam Datesheet: {{ $session->name }}</h2>
    @include('reports.master-datesheet', ['rowsByDate' => $rowsByDate, 'session' => $session])
</body>
</html>
