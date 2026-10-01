<?php

namespace App\Http\Controllers;

use App\Exports\CapacitySimulationExport;
use App\Models\ExamSession;
use App\Services\Generation\SeatAllocationService;
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
        [$strategyKey, $strategy] = $this->strategy($request, $examSession);
        $simulator = new SlotCapacitySimulator($strategy);
        $slotRequirements = SlotCapacitySimulator::withDayAndSlotLabels($simulator->simulate($examSession, $min, $max), $this->perDay($request));

        return Excel::download(
            new CapacitySimulationExport($examSession, $simulator->subjectRequirements($examSession), $slotRequirements, $min, $max, $strategyKey),
            $this->filename($examSession, 'xlsx')
        );
    }

    public function pdf(Request $request, ExamSession $examSession): Response
    {
        Gate::authorize('generate_roster');

        [$min, $max] = $this->minMax($request);
        [$strategyKey, $strategy] = $this->strategy($request, $examSession);
        $simulator = new SlotCapacitySimulator($strategy);
        $slotRequirements = SlotCapacitySimulator::withDayAndSlotLabels($simulator->simulate($examSession, $min, $max), $this->perDay($request));

        return Pdf::loadView('reports.capacity-simulation-pdf', [
            'session' => $examSession,
            'subjectRequirements' => $simulator->subjectRequirements($examSession),
            'slotRequirements' => $slotRequirements,
            'min' => $min,
            'max' => $max,
            'strategyLabel' => ExamSession::SEATING_STRATEGIES[$strategyKey] ?? $strategyKey,
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

    /**
     * Falls back to the session's own saved strategy when the query string doesn't name a valid one —
     * matches CapacityCheck's own default, so a download requested without visiting the page first
     * simulates the same way the page would show fresh.
     *
     * @return array{0: string, 1: \App\Services\Generation\Strategies\SeatingStrategy}
     */
    private function strategy(Request $request, ExamSession $examSession): array
    {
        $key = $request->query('strategy');
        $key = is_string($key) && array_key_exists($key, ExamSession::SEATING_STRATEGIES) ? $key : $examSession->seating_strategy;

        $mixedPerRoom = $request->query('mixed_per_room');
        $mixedPerRoom = ($mixedPerRoom !== null && ctype_digit((string) $mixedPerRoom) && (int) $mixedPerRoom >= 2)
            ? (int) $mixedPerRoom
            : $examSession->mixed_subjects_per_room;

        return [$key, (new SeatAllocationService)->strategyFor($key, $mixedPerRoom)];
    }

    /**
     * Matches CapacityCheck's own default (2) so a download requested without visiting the page first
     * labels slots the same way the page would show them fresh.
     */
    private function perDay(Request $request): int
    {
        $perDay = $request->query('per_day');

        return ($perDay !== null && ctype_digit((string) $perDay) && (int) $perDay > 0) ? (int) $perDay : 2;
    }

    private function filename(ExamSession $examSession, string $extension): string
    {
        return Str::slug($examSession->name).'-capacity-simulation.'.$extension;
    }
}
