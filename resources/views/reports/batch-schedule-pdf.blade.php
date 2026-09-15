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
    <h2>Batch / Section Schedule: {{ $session->name }}</h2>
    @include('reports.batch-schedule', ['sections' => $sections, 'session' => $session])
</body>
</html>
