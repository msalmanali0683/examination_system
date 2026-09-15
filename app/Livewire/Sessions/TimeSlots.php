<?php

namespace App\Livewire\Sessions;

use App\Livewire\Concerns\GuardsFinalizedSession;
use App\Models\ActivityLog;
use App\Models\ExamSession;
use App\Models\SubjectSlotAssignment;
use App\Models\TimeSlot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class TimeSlots extends Component
{
    use GuardsFinalizedSession;

    private const DAYS = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];

    public ExamSession $examSession;

    public bool $showForm = false;

    public ?int $editingId = null;

    public ?string $date = null;

    public ?string $start_time = null;

    public ?string $end_time = null;

    public string $label = '';

    // Bulk generator: choose how many slots run each day and their
    // timings, then how many exam dates to spread them across.
    public bool $showBulkForm = false;

    public int $bulkSlotsPerDay = 2;

    public array $bulkSlotTimes = [];

    public ?string $bulkStartDate = null;

    public int $bulkDateCount = 5;

    public array $bulkSkipDays = [];

    public function days(): array
    {
        return self::DAYS;
    }

    public function mount(ExamSession $examSession): void
    {
        $this->authorize('manage_sessions');
        $this->examSession = $examSession;
    }

    public function addSlot(): void
    {
        $this->authorize('manage_sessions');
        $this->resetForm();
        $this->showForm = true;
        $this->showBulkForm = false;
    }

    public function editSlot(int $id): void
    {
        $this->authorize('manage_sessions');
        $slot = $this->examSession->timeSlots()->findOrFail($id);

        $this->editingId = $slot->id;
        $this->date = $slot->date->toDateString();
        $this->start_time = substr($slot->start_time, 0, 5);
        $this->end_time = substr($slot->end_time, 0, 5);
        $this->label = (string) $slot->label;
        $this->showForm = true;
        $this->showBulkForm = false;
    }

    public function save(): void
    {
        $this->authorize('manage_sessions');

        if ($this->blockedByFinalization($this->examSession)) {
            return;
        }

        $validated = $this->validate([
            'date' => ['required', 'date'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'label' => ['nullable', 'string', 'max:255'],
        ]);
        $validated['label'] = $validated['label'] ?: null;

        $this->examSession->timeSlots()->updateOrCreate(['id' => $this->editingId], $validated);

        $this->resetForm();
        $this->showForm = false;
    }

    public function deleteSlot(int $id): void
    {
        $this->authorize('manage_sessions');

        if ($this->blockedByFinalization($this->examSession)) {
            return;
        }

        $this->examSession->timeSlots()->findOrFail($id)->delete();
    }

    public function cancel(): void
    {
        $this->resetForm();
        $this->showForm = false;
    }

    /**
     * Opens the bulk generator with sane defaults: two slots a day at the
     * usual times, spanning the session's existing date range.
     */
    public function startBulkGenerate(): void
    {
        $this->authorize('manage_sessions');

        $this->bulkSlotsPerDay = 2;
        $this->bulkSlotTimes = [
            ['start' => '09:00', 'end' => '10:30'],
            ['start' => '11:30', 'end' => '13:00'],
        ];
        $this->bulkStartDate = $this->examSession->start_date->toDateString();
        $this->bulkDateCount = max(1, (int) $this->examSession->start_date->diffInDays($this->examSession->end_date) + 1);
        $this->bulkSkipDays = [];
        $this->showBulkForm = true;
        $this->showForm = false;
    }

    /**
     * Livewire hook: resizes bulkSlotTimes to match the new count, keeping
     * whatever timings were already typed in for slots that still exist.
     */
    public function updatedBulkSlotsPerDay($value): void
    {
        $count = max(1, min(10, (int) $value));
        $this->bulkSlotsPerDay = $count;

        $current = $this->bulkSlotTimes;
        $this->bulkSlotTimes = [];

        for ($i = 0; $i < $count; $i++) {
            $this->bulkSlotTimes[$i] = $current[$i] ?? ['start' => '', 'end' => ''];
        }
    }

    public function toggleBulkSkipDay(int $day): void
    {
        $this->bulkSkipDays = in_array($day, $this->bulkSkipDays, true)
            ? array_values(array_diff($this->bulkSkipDays, [$day]))
            : array_values([...$this->bulkSkipDays, $day]);
    }

    public function cancelBulkGenerate(): void
    {
        $this->showBulkForm = false;
    }

    /**
     * Replaces every time slot in the session with a freshly generated set:
     * the same N slot timings repeated across the chosen number of exam
     * dates (skipping any selected weekdays). Any existing slots are wiped
     * first — cascading to their seat/duty assignments — since a changed
     * timetable makes previously generated seating/duties meaningless.
     */
    public function generateBulkSlots(): void
    {
        $this->authorize('manage_sessions');

        if ($this->blockedByFinalization($this->examSession)) {
            return;
        }

        $validated = $this->validate([
            'bulkSlotsPerDay' => ['required', 'integer', 'min:1', 'max:10'],
            'bulkSlotTimes' => ['required', 'array', 'size:'.$this->bulkSlotsPerDay],
            'bulkSlotTimes.*.start' => ['required', 'date_format:H:i'],
            'bulkSlotTimes.*.end' => ['required', 'date_format:H:i', 'after:bulkSlotTimes.*.start'],
            'bulkStartDate' => ['required', 'date'],
            'bulkDateCount' => ['required', 'integer', 'min:1', 'max:60'],
            'bulkSkipDays' => ['array'],
            'bulkSkipDays.*' => ['integer', 'between:1,7'],
        ]);

        if (count($validated['bulkSkipDays']) >= 7) {
            session()->flash('error', 'At least one day of the week must stay available to generate exam dates from.');

            return;
        }

        $dates = $this->generateDates($validated['bulkStartDate'], $validated['bulkDateCount'], $validated['bulkSkipDays']);
        $existingCount = $this->examSession->timeSlots()->count();

        DB::transaction(function () use ($validated, $dates) {
            if ($this->examSession->timeSlots()->exists()) {
                SubjectSlotAssignment::where('exam_session_id', $this->examSession->id)
                    ->update(['time_slot_id' => null, 'is_pinned' => false, 'conflict_note' => null]);

                $this->examSession->timeSlots()->delete();

                if ($this->examSession->status !== 'draft') {
                    $this->examSession->update(['status' => 'draft']);
                }
            }

            foreach ($dates as $date) {
                foreach ($validated['bulkSlotTimes'] as $slot) {
                    TimeSlot::create([
                        'exam_session_id' => $this->examSession->id,
                        'date' => $date,
                        'start_time' => $slot['start'],
                        'end_time' => $slot['end'],
                    ]);
                }
            }
        });

        $totalCreated = count($dates) * count($validated['bulkSlotTimes']);

        ActivityLog::record(
            $this->examSession,
            'time_slots.bulk_generated',
            "Generated {$totalCreated} time slot(s) across ".count($dates)." date(s) at {$validated['bulkSlotsPerDay']} slot(s)/day"
                .($existingCount ? ", replacing {$existingCount} existing slot(s)." : '.')
        );

        $this->showBulkForm = false;
        session()->flash('status', "Generated {$totalCreated} time slot(s) across ".count($dates).' date(s).');
    }

    /**
     * @return string[] consecutive calendar dates from $startDate, skipping
     *                   any weekday in $skipDays (1=Mon..7=Sun), until $count is reached
     */
    private function generateDates(string $startDate, int $count, array $skipDays): array
    {
        $skip = array_flip($skipDays);
        $dates = [];
        $cursor = Carbon::parse($startDate);

        while (count($dates) < $count) {
            if (! isset($skip[$cursor->dayOfWeekIso])) {
                $dates[] = $cursor->toDateString();
            }

            $cursor->addDay();
        }

        return $dates;
    }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'date', 'start_time', 'end_time', 'label']);
    }

    public function render()
    {
        return view('livewire.sessions.time-slots', [
            'slots' => $this->examSession->timeSlots()->orderBy('date')->orderBy('start_time')->get(),
        ]);
    }
}
