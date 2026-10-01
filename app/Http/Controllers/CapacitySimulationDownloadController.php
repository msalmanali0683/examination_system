<?php

namespace App\Http\Controllers;

use App\Exports\CapacitySimulationExport;
use App\Models\ExamSession;
use App\Services\Generation\SlotCapacitySimulator;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\Response;

/**
 * Downloads for the Check Capacity page's slot simulation — built fresh on every request straight
 * from enrollment data and the given min/max subjects-per-slot inputs, never cached, since (unlike
 * every report under App\Services\Reports) its "filters" are what-if packing parameters rather than
 * a date/slot narrowing of real generated data.
 */
class CapacitySimulationDownloadController extends Controller
{
    public function excel(Request $request, ExamSession $examSession): Response
    {
        Gate::authorize('generate_roster');

        [$min, $max] = $this->minMax($request);
        $simulator = new SlotCapacitySimulator;

        return Excel::download(
            new CapacitySimulationExport($examSession, $simulator->subjectRequirements($examSession), $simulator->simulate($examSession, $min, $max), $min, $max),
            $this->filename($examSession, 'xlsx')
        );
    }

    public function pdf(Request $request, ExamSession $examSession): Response
    {
        Gate::authorize('generate_roster');

        [$min, $max] = $this->minMax($request);
        $simulator = new SlotCapacitySimulator;

        return Pdf::loadView('reports.capacity-simulation-pdf', [
            'session' => $examSession,
            'subjectRequirements' => $simulator->subjectRequirements($examSession),
            'slotRequirements' => $simulator->simulate($examSession, $min, $max),
            'min' => $min,
            'max' => $max,
        ])->setPaper('a4', 'portrait')->download($this->filename($examSession, 'pdf'));
    }

    /**
     * @return array{0: ?int, 1: ?int}
     */
    private function minMax(Request $request): array
    {
        $min = $request->query('min');
        $max = $request->query('max');

        return [
            ($min !== null && ctype_digit((string) $min)) ? (int) $min : null,
            ($max !== null && ctype_digit((string) $max)) ? (int) $max : null,
        ];
    }

    private function filename(ExamSession $examSession, string $extension): string
    {
        return Str::slug($examSession->name).'-capacity-simulation.'.$extension;
    }
}
