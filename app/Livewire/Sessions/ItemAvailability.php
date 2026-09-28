<?php

namespace App\Livewire\Sessions;

use App\Livewire\Concerns\GuardsFinalizedSession;
use App\Models\ExamSession;
use App\Models\Room;
use App\Models\SessionTeacherConstraint;
use App\Models\Teacher;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Marks one room or one teacher unavailable for specific time slots — e.g.
 * free on Monday except that day's 2nd slot. Ticked = can be used in that
 * slot; unticking switches it off. Each click saves straight away.
 * Seating never puts students in a room that's off for a slot, and duty
 * allocation never gives a teacher a duty in a slot they're off for (see
 * SlotAvailability).
 */
class ItemAvailability extends Component
{
    use GuardsFinalizedSession;

    public ExamSession $examSession;

    /** 'rooms' or 'teachers' — locked so it can't be switched to something the user isn't allowed to manage. */
    #[Locked]
    public string $type = 'rooms';

    #[Locked]
    public int $itemId = 0;

    public function mount(ExamSession $examSession, string $type, int $item): void
    {
        $this->type = $type;
        $this->authorize($this->permission());
        $this->examSession = $examSession;
        $this->itemId = $item;
        $this->item(); // 404s for anything that isn't this session's own room/teacher
    }

    private function permission(): string
    {
        return $this->type === 'rooms' ? 'manage_rooms' : 'manage_teachers';
    }

    private function item(): Room|Teacher
    {
        return $this->type === 'rooms'
            ? $this->examSession->rooms()->findOrFail($this->itemId)
            : $this->examSession->teachers()->findOrFail($this->itemId);
    }

    public function toggleSlot(int $slotId): void
    {
        $this->authorize($this->permission());

        if ($this->blockedByFinalization($this->examSession)) {
            return;
        }

        $slot = $this->examSession->timeSlots()->findOrFail($slotId);

        $this->item()->unavailableSlots()->toggle([$slot->id]);
    }

    /**
     * Switches a whole day off — or, if every slot that day is already
     * off, back on.
     */
    public function toggleDay(string $date): void
    {
        $this->authorize($this->permission());

        if ($this->blockedByFinalization($this->examSession)) {
            return;
        }

        $slotIds = $this->examSession->timeSlots()->whereDate('date', $date)->pluck('id')->all();

        if ($slotIds === []) {
            return;
        }

        $item = $this->item();
        $alreadyOff = $item->unavailableSlots()->whereIn('time_slots.id', $slotIds)->pluck('time_slots.id')->all();

        if (count($alreadyOff) === count($slotIds)) {
            $item->unavailableSlots()->detach($slotIds);
        } else {
            $item->unavailableSlots()->syncWithoutDetaching($slotIds);
        }
    }

    public function makeAllAvailable(): void
    {
        $this->authorize($this->permission());

        if ($this->blockedByFinalization($this->examSession)) {
            return;
        }

        $this->item()->unavailableSlots()->detach();
    }

    #[Layout('layouts.app')]
    public function render()
    {
        $item = $this->item();
        $slots = $this->examSession->timeSlots()->orderBy('date')->orderBy('start_time')->get();
        $off = $item->unavailableSlots()->pluck('time_slots.id')->all();

        // A teacher can already be off for whole weekdays (Teachers tab);
        // those days show as off here too, and can't be re-enabled from
        // this page.
        $weekdaysOff = $item instanceof Teacher
            ? (SessionTeacherConstraint::where('exam_session_id', $this->examSession->id)->where('teacher_id', $item->id)->value('unavailable_days') ?? [])
            : [];
        $excluded = $item instanceof Teacher
            && SessionTeacherConstraint::where('exam_session_id', $this->examSession->id)->where('teacher_id', $item->id)->where('is_excluded', true)->exists();

        return view('livewire.sessions.item-availability', [
            'item' => $item,
            'days' => $slots->groupBy(fn ($slot) => $slot->date->format('Y-m-d'))->map(fn ($daySlots) => $daySlots->values()),
            'off' => $off,
            'weekdaysOff' => $weekdaysOff,
            'excluded' => $excluded,
            'slotCount' => $slots->count(),
            'backRoute' => route("sessions.{$this->type}.index", $this->examSession),
            'label' => $this->type === 'rooms' ? 'room' : 'teacher',
        ]);
    }
}
