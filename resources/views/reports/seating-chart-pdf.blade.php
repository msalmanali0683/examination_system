<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    @page { margin: 1.2cm; }
    body { font-family: Arial, Helvetica, sans-serif; }
    .chart { page-break-after: always; }
    .chart:last-child { page-break-after: auto; }
</style>
</head>
<body>
    @foreach ($charts as $chart)
        <div class="chart">
            @include('reports.seating-chart-sheet', ['chart' => $chart, 'session' => $session, 'showInvigilators' => $showInvigilators ?? true])
        </div>
    @endforeach
</body>
</html>
