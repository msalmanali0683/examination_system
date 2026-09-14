<?php

namespace App\Livewire;

use App\Models\ExamSession;
use App\Models\Room;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use Livewire\Attributes\Layout;
use Livewire\Component;

class Dashboard extends Component
{
    #[Layout('layouts.app')]
    public function render()
    {
        return view('livewire.dashboard', [
            'sessionCount' => ExamSession::count(),
            'activeSessionCount' => ExamSession::whereIn('status', ['draft', 'generated'])->count(),
            'roomCount' => Room::where('is_active', true)->count(),
            'teacherCount' => Teacher::where('is_active', true)->count(),
            'studentCount' => Student::count(),
            'subjectCount' => Subject::count(),
            'recentSessions' => ExamSession::latest()->take(5)->get(),
        ]);
    }
}
