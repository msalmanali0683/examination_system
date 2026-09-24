<?php

namespace Tests\Feature;

use App\Livewire\Sessions\Show;
use App\Models\Enrollment;
use App\Models\ExamSession;
use App\Models\Room;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Rooms, teachers, students and subjects belong to exactly one session:
 * managed from inside it, invisible to every other session, and deleted
 * with it.
 */
class SessionOwnedDataTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'head']);
    }

    public function test_every_session_scoped_page_renders_with_the_sessions_own_navigation(): void
    {
        $session = ExamSession::factory()->create(['name' => 'Software Engineering Midterm']);

        foreach ([
            'sessions.show',
            'sessions.rooms.index',
            'sessions.rooms.import',
            'sessions.teachers.index',
            'sessions.teachers.import',
            'sessions.subjects.index',
            'sessions.students.index',
        ] as $routeName) {
            $this->actingAs($this->admin())
                ->get(route($routeName, $session))
                ->assertOk()
                ->assertSee('Software Engineering Midterm')
                // The sidebar's "this session" section links straight to each manager.
                ->assertSee(route('sessions.rooms.index', $session), false)
                ->assertSee(route('sessions.teachers.index', $session), false)
                ->assertSee(route('sessions.subjects.index', $session), false)
                ->assertSee(route('sessions.students.index', $session), false);
        }
    }

    public function test_the_old_global_catalog_pages_no_longer_exist(): void
    {
        foreach (['/rooms', '/rooms/import', '/teachers', '/teachers/import', '/subjects', '/students'] as $path) {
            $this->actingAs($this->admin())->get($path)->assertNotFound();
        }
    }

    public function test_the_global_sidebar_has_no_shared_rooms_or_teachers_links(): void
    {
        $this->actingAs($this->admin())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('>Rooms<', false)
            ->assertDontSee('>Teachers<', false);
    }

    public function test_the_session_page_summarises_only_its_own_rooms_and_teachers(): void
    {
        $session = ExamSession::factory()->create();
        $other = ExamSession::factory()->create();
        Room::factory()->for($session)->create(['capacity' => 30, 'is_active' => true]);
        Room::factory()->for($session)->create(['capacity' => 20, 'is_active' => false]);
        Room::factory()->for($other)->count(5)->create(['capacity' => 99]);
        Teacher::factory()->for($session)->count(2)->create();
        Teacher::factory()->for($other)->count(7)->create();

        Livewire::actingAs($this->admin())
            ->test(Show::class, ['examSession' => $session])
            ->assertViewHas('roomCount', 2)
            ->assertViewHas('activeRoomCount', 1)
            ->assertViewHas('seatCount', 30)
            ->assertViewHas('teacherCount', 2);
    }

    public function test_deleting_a_session_removes_everything_it_owned_and_nothing_else(): void
    {
        $session = ExamSession::factory()->create();
        $other = ExamSession::factory()->create();

        foreach ([$session, $other] as $s) {
            Room::factory()->for($s)->create();
            Teacher::factory()->for($s)->create();
            Enrollment::factory()->create([
                'exam_session_id' => $s->id,
                'student_id' => Student::factory()->for($s),
                'subject_id' => Subject::factory()->for($s),
            ]);
        }

        $session->delete();

        $this->assertSame(0, Room::where('exam_session_id', $session->id)->count());
        $this->assertSame(0, Teacher::where('exam_session_id', $session->id)->count());
        $this->assertSame(0, Student::where('exam_session_id', $session->id)->count());
        $this->assertSame(0, Subject::where('exam_session_id', $session->id)->count());
        $this->assertSame(0, Enrollment::where('exam_session_id', $session->id)->count());

        $this->assertSame(1, Room::where('exam_session_id', $other->id)->count());
        $this->assertSame(1, Teacher::where('exam_session_id', $other->id)->count());
        $this->assertSame(1, Student::where('exam_session_id', $other->id)->count());
        $this->assertSame(1, Subject::where('exam_session_id', $other->id)->count());
        $this->assertSame(1, Enrollment::where('exam_session_id', $other->id)->count());
    }
}
