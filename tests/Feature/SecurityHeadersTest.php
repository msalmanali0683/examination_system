<?php

namespace Tests\Feature;

use App\Models\ExamSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    private function assertHardened($response): void
    {
        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Permissions-Policy');
    }

    public function test_the_login_page_carries_the_hardening_headers(): void
    {
        $this->assertHardened($this->get('/login')->assertOk());
    }

    public function test_signed_in_pages_carry_them_too(): void
    {
        $head = User::factory()->create(['role' => 'head']);

        $this->assertHardened($this->actingAs($head)->get('/dashboard')->assertOk());
        $this->assertHardened($this->actingAs($head)->get(route('sessions.index'))->assertOk());
    }

    public function test_redirects_and_errors_carry_them_too(): void
    {
        $this->assertHardened($this->get('/dashboard')->assertRedirect('/login'));
        $this->assertHardened($this->actingAs(User::factory()->create(['role' => 'head']))->get('/sessions/999999')->assertNotFound());
    }

    public function test_a_url_that_matches_no_route_carries_them_too(): void
    {
        $this->assertHardened($this->get('/no/such/page')->assertNotFound());
    }

    public function test_file_downloads_carry_them_too(): void
    {
        $head = User::factory()->create(['role' => 'head']);
        $session = ExamSession::factory()->create();

        $this->assertHardened($this->actingAs($head)->get(route('sessions.missing-teachers.template', $session))->assertOk());
    }

    public function test_no_strict_transport_security_is_forced_by_the_app(): void
    {
        $this->get('/login')->assertHeaderMissing('Strict-Transport-Security');
    }
}
