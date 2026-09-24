<?php

namespace App\Livewire;

use App\Models\ExamSession;
use App\Models\Student;
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
            'finalizedSessionCount' => ExamSession::where('status', 'finalized')->count(),
            'studentCount' => Student::count(),
            'recentSessions' => ExamSession::latest()->take(5)->get(),
        ]);
    }
}
