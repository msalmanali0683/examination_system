<?php

namespace Tests\Feature;

use App\Models\ExamSession;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ExceptionHandlingTest extends TestCase
{
    use RefreshDatabase;

    public function test_visiting_a_deleted_sessions_url_redirects_to_the_dashboard_with_a_friendly_message(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        $deletedId = $session->id;
        $session->delete();

        $this->actingAs($staff)
            ->get("/sessions/{$deletedId}")
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('error');
    }

    public function test_visiting_a_deleted_rooms_url_within_a_live_session_also_redirects_to_the_dashboard(): void
    {
        // Not scoped to just ExamSession — any model-bound route whose record has since been
        // deleted (a stale tab, a bookmark) should land somewhere useful instead of a bare 404.
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        $room = Room::factory()->for($session)->create();
        $deletedRoomId = $room->id;
        $room->delete();

        $this->actingAs($staff)
            ->get("/sessions/{$session->id}/availability/rooms/{$deletedRoomId}")
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('error');
    }

    public function test_a_genuinely_unmatched_route_still_gets_a_plain_404(): void
    {
        // Only a bound-but-missing MODEL is redirected — a URL that doesn't match any route at
        // all (a typo, not a deleted record) is a different failure and should stay a plain 404.
        $staff = User::factory()->create(['role' => 'staff']);

        $this->actingAs($staff)
            ->get('/this-route-does-not-exist-anywhere')
            ->assertNotFound();
    }

    public function test_a_token_mismatch_redirects_to_login_with_a_friendly_message(): void
    {
        // CSRF verification itself is skipped while running tests (see
        // Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::runningUnitTests()), so a real 419
        // can't be provoked through the usual POST-with-a-stale-token route. Instead, route a
        // throwing endpoint through the real HTTP kernel — this still exercises the actual
        // registered exception handler exactly as production would hit it, just via a different
        // trigger than CSRF middleware.
        Route::get('/zz-test-token-mismatch', function () {
            throw new TokenMismatchException('CSRF token mismatch.');
        })->middleware('web');

        $this->get('/zz-test-token-mismatch')
            ->assertRedirect(route('login'))
            ->assertSessionHas('status', 'Your session expired — please log in again.');
    }
}
