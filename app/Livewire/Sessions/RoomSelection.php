<?php

namespace App\Livewire\Sessions;

use App\Livewire\Concerns\GuardsFinalizedSession;
use App\Models\ExamSession;
use App\Models\Room;
use App\Models\SessionRoom;
use Livewire\Component;

class RoomSelection extends Component
{
    use GuardsFinalizedSession;

    public ExamSession $examSession;

    public function mount(ExamSession $examSession): void
    {
        $this->authorize('manage_sessions');
        $this->examSession = $examSession;
    }

    public function toggleRoom(int $roomId): void
    {
        $this->authorize('manage_sessions');

        if ($this->blockedByFinalization($this->examSession)) {
            return;
        }

        $existing = SessionRoom::where('exam_session_id', $this->examSession->id)
            ->where('room_id', $roomId)
            ->first();

        if ($existing) {
            $existing->delete();
        } else {
            SessionRoom::create([
                'exam_session_id' => $this->examSession->id,
                'room_id' => $roomId,
                'is_active' => true,
            ]);
        }
    }

    public function updateCapacityOverride(int $roomId, string $value): void
    {
        $this->authorize('manage_sessions');

        if ($this->blockedByFinalization($this->examSession)) {
            return;
        }

        $room = Room::findOrFail($roomId);
        $override = $value === '' ? null : max(1, min((int) $value, $room->capacity));

        SessionRoom::where('exam_session_id', $this->examSession->id)
            ->where('room_id', $roomId)
            ->update(['capacity_override' => $override]);
    }

    public function render()
    {
        return view('livewire.sessions.room-selection', [
            'rooms' => Room::where('is_active', true)->orderBy('name')->get(),
            'included' => $this->examSession->sessionRooms()->get()->keyBy('room_id'),
        ]);
    }
}
