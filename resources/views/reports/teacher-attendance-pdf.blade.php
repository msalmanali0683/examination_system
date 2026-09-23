<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    @page { margin: 1.2cm; }
    body { font-family: 'Times New Roman', Times, serif; }
</style>
</head>
<body>
    @foreach ($rowsByDate as $dateKey => $rows)
        <div @if (! $loop->last) style="page-break-after: always;" @endif>
            @include('reports.teacher-attendance', ['session' => $session, 'dateKey' => $dateKey, 'rows' => $rows])
        </div>
    @endforeach
</body>
</html>
