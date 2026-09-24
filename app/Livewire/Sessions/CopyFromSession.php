<?php

namespace App\Livewire\Sessions;

use App\Livewire\Concerns\GuardsFinalizedSession;
use App\Models\ActivityLog;
use App\Models\ExamSession;
use App\Services\SessionDataCopier;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Imports rooms or teachers from another session into this one, in two
 * steps: pick a session from the list, then tick which of its rooms or
 * teachers to bring over. Rooms and teachers belong to a single session,
 * so this makes brand-new copies owned by the current session — the
 * source is never touched or shared.
 */
class CopyFromSession extends Component
{
    use GuardsFinalizedSession;

    public ExamSession $examSession;

    /** 'rooms' or 'teachers' — locked so the browser can't switch it to something the user isn't allowed to manage. */
    #[Locked]
    public string $type = 'rooms';

    public string $sourceSessionId = '';

    /**
     * Checked row IDs — bound to each row's checkbox, so they arrive as
     * strings.
     *
     * @var array<int, string|int>
     */
    public array $selected = [];

    public bool $withConstraints = true;

    public function mount(ExamSession $examSession, string $type): void
    {
        $this->type = $type;
        $this->authorize($this->permission());
        $this->examSession = $examSession;
    }

    private function permission(): string
    {
        return $this->type === 'rooms' ? 'manage_rooms' : 'manage_teachers';
    }

    private function indexRoute(): string
    {
        return route("sessions.{$this->type}.index", $this->examSession);
    }

    private function label(): string
    {
        return $this->type === 'rooms' ? 'room' : 'teacher';
    }

    private function sourceSession(): ?ExamSession
    {
        if ($this->sourceSessionId === '') {
            return null;
        }

        return ExamSession::whereKeyNot($this->examSession->id)->find((int) $this->sourceSessionId);
    }

    /**
     * Every row of the chosen session, and which of them this session
     * already has (those can't be imported again).
     *
     * @return array{0: Collection, 1: Collection}
     */
    private function sourceRows(?ExamSession $source): array
    {
        if (! $source) {
            return [collect(), collect()];
        }

        $copier = new SessionDataCopier;

        return $this->type === 'rooms'
            ? [$source->rooms()->orderBy('name')->get(), $copier->roomIdsAlreadyIn($source, $this->examSession)]
            : [$source->teachers()->orderBy('name')->get(), $copier->teacherIdsAlreadyIn($source, $this->examSession)];
    }

    /**
     * IDs of the chosen session's rows that can still be imported.
     *
     * @return Collection<int, int>
     */
    private function availableIds(): Collection
    {
        [$rows, $alreadyThere] = $this->sourceRows($this->sourceSession());

        return $rows->pluck('id')->diff($alreadyThere)->values();
    }

    public function chooseSession(int $sessionId): void
    {
        $this->authorize($this->permission());

        $this->sourceSessionId = (string) $sessionId;
        $this->selected = [];
        $this->resetErrorBag();
    }

    public function chooseAnother(): void
    {
        $this->sourceSessionId = '';
        $this->selected = [];
        $this->resetErrorBag();
    }

    /**
     * The header checkbox: ticks every importable row, or clears them all
     * if they're already all ticked.
     */
    public function toggleSelectAll(): void
    {
        $available = $this->availableIds();
        $selected = collect($this->selected)->map(fn ($id) => (int) $id);

        $this->selected = $available->isNotEmpty() && $available->diff($selected)->isEmpty()
            ? []
            : $available->map(fn ($id) => (string) $id)->all();
    }

    public function importSelected(): void
    {
        $this->authorize($this->permission());

        if ($this->blockedByFinalization($this->examSession)) {
            return;
        }

        $source = $this->sourceSession();

        if (! $source) {
            $this->addError('sourceSessionId', 'Pick a session to import from.');

            return;
        }

        $ids = array_values(array_unique(array_map('intval', $this->selected)));

        if ($ids === []) {
            session()->flash('error', "Select at least one {$this->label()} to import.");

            return;
        }

        $copier = new SessionDataCopier;
        $result = $this->type === 'rooms'
            ? $copier->copyRooms($source, $this->examSession, $ids)
            : $copier->copyTeachers($source, $this->examSession, $ids, $this->withConstraints);

        $message = "Imported {$result['copied']} {$this->label()}(s) from \"{$source->name}\".";

        if ($result['skipped'] > 0) {
            $message .= " {$result['skipped']} skipped — already in this session.";
        }

        ActivityLog::record($this->examSession, "{$this->type}.copied", $message);
        session()->flash('status', $message);

        $this->redirect($this->indexRoute(), navigate: true);
    }

    #[Layout('layouts.app')]
    public function render()
    {
        $source = $this->sourceSession();
        [$rows, $alreadyThere] = $this->sourceRows($source);
        $available = $rows->pluck('id')->diff($alreadyThere);
        $selected = collect($this->selected)->map(fn ($id) => (int) $id);

        return view('livewire.sessions.copy-from-session', [
            'sessions' => ExamSession::whereKeyNot($this->examSession->id)
                ->withCount(['rooms', 'teachers'])
                ->orderByDesc('start_date')
                ->get(),
            'source' => $source,
            'rows' => $rows,
            'alreadyThere' => $alreadyThere->flip(),
            'availableCount' => $available->count(),
            'allSelected' => $available->isNotEmpty() && $available->diff($selected)->isEmpty(),
            'label' => $this->label(),
            'indexRoute' => $this->indexRoute(),
        ]);
    }
}
