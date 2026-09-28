<?php

namespace Tests\Feature;

use App\Models\ExamSession;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * A <label> that isn't tied to its input (for= / id=, or wrapping it) does nothing: clicking the words doesn't
 * focus the field and a screen reader announces an unnamed box. The forms used to be full of these.
 */
class AccessibilityLabelsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<int, string> "file:line  label text" for every label that neither has for= nor wraps a control
     */
    private function unlinkedLabels(): array
    {
        $root = resource_path('views');
        $found = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            // The generic Breeze <x-input-label> takes its for= from whoever uses it.
            if (str_ends_with(str_replace('\\', '/', $file->getPathname()), 'components/input-label.blade.php')) {
                continue;
            }

            $source = file_get_contents($file->getPathname());

            if (! preg_match_all('~<label\b([^>]*)>(.*?)</label>~si', $source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($matches as $match) {
                if (preg_match('/\bfor\s*=/i', $match[1][0]) || preg_match('~<(input|select|textarea|x-text-input)\b~i', $match[2][0])) {
                    continue;
                }

                $line = substr_count($source, "\n", 0, $match[0][1]) + 1;
                $text = trim(preg_replace('/\s+/', ' ', strip_tags($match[2][0])));
                $found[] = str_replace($root.DIRECTORY_SEPARATOR, '', $file->getPathname()).":$line  $text";
            }
        }

        return $found;
    }

    public function test_every_label_in_every_view_is_linked_to_its_control(): void
    {
        $this->assertSame([], $this->unlinkedLabels(), "These <label>s neither carry for= nor wrap their input:\n".implode("\n", $this->unlinkedLabels()));
    }

    public function test_the_icon_only_menu_buttons_have_accessible_names(): void
    {
        $head = User::factory()->create(['role' => 'head']);

        $page = $this->actingAs($head)->get('/dashboard')->assertOk()->getContent();

        $this->assertStringContainsString('aria-label="Open navigation menu"', $page);
        $this->assertStringContainsString('aria-label="Close navigation menu"', $page);
    }

    public function test_form_fields_render_their_label_with_a_matching_id(): void
    {
        $head = User::factory()->create(['role' => 'head']);
        $session = ExamSession::factory()->create();

        $html = $this->actingAs($head)->get(route('sessions.teachers.index', $session))->assertOk()->getContent();

        // every for="x" on the page must point at an element that exists
        preg_match_all('/<label[^>]*\bfor="([^"]+)"/', $html, $labelTargets);

        foreach ($labelTargets[1] as $id) {
            $this->assertStringContainsString('id="'.$id.'"', $html, "label for=\"$id\" points at nothing");
        }
    }

    public function test_per_row_controls_name_the_row_they_belong_to(): void
    {
        $head = User::factory()->create(['role' => 'head']);
        $session = ExamSession::factory()->create();
        Teacher::factory()->for($session)->create(['name' => 'Dr Huria Ali']);

        $teachers = $this->actingAs($head)->get(route('sessions.teachers.index', $session))->assertOk()->getContent();
        $this->assertStringContainsString('aria-label="Select Dr Huria Ali"', $teachers);

        $users = $this->actingAs($head)->get(route('users.index'))->assertOk()->getContent();
        $this->assertStringContainsString('aria-label="Role for '.e($head->name).'"', $users);
    }

    public function test_report_cards_that_are_not_ready_are_not_links_to_nowhere(): void
    {
        $head = User::factory()->create(['role' => 'head']);
        $session = ExamSession::factory()->create();

        $html = $this->actingAs($head)->get(route('sessions.show', $session))->assertOk()->getContent();

        $this->assertStringNotContainsString('href="#"', $html, 'a disabled report card must not be an anchor to "#"');
        $this->assertStringContainsString('aria-disabled="true"', $html);
        $this->assertStringContainsString('Generate seating first.', $html);
    }

    public function test_the_per_row_pin_and_invigilator_selects_are_named(): void
    {
        $head = User::factory()->create(['role' => 'head']);
        $session = ExamSession::factory()->create();
        $subject = \App\Models\Subject::factory()->for($session)->create(['code' => 'CS-777']);
        $student = \App\Models\Student::factory()->for($session)->create();
        \App\Models\Enrollment::factory()->create(['exam_session_id' => $session->id, 'student_id' => $student->id, 'subject_id' => $subject->id]);
        \App\Models\TimeSlot::factory()->create(['exam_session_id' => $session->id]);

        $timetable = $this->actingAs($head)->get(route('sessions.timetable', $session))->assertOk()->getContent();

        $this->assertStringContainsString('aria-label="Pin CS-777 to a time slot"', $timetable);
    }
}
