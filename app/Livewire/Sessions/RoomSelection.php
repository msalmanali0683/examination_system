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

    /**
     * Includes every active room in the catalog into this session at
     * once — a shortcut for checking each one individually, since a
     * session commonly ends up using most or all of them anyway. Rooms
     * already included are left untouched (their capacity override
     * survives).
     */
    public function selectAllRooms(): void
    {
        $this->authorize('manage_sessions');

        if ($this->blockedByFinalization($this->examSession)) {
            return;
        }

        $alreadyIncluded = $this->examSession->sessionRooms()->pluck('room_id');

        $toAdd = Room::where('is_active', true)
            ->whereNotIn('id', $alreadyIncluded)
            ->pluck('id');

        foreach ($toAdd as $roomId) {
            SessionRoom::create([
                'exam_session_id' => $this->examSession->id,
                'room_id' => $roomId,
                'is_active' => true,
            ]);
        }
    }

    /**
     * Removes every room from this session at once — the bulk
     * equivalent of unchecking each one, e.g. to start the room
     * selection over from scratch. Any capacity overrides set on the
     * removed rooms are lost along with them.
     */
    public function deselectAllRooms(): void
    {
        $this->authorize('manage_sessions');

        if ($this->blockedByFinalization($this->examSession)) {
            return;
        }

        $this->examSession->sessionRooms()->delete();
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
