<?php

namespace Tests\Feature;

use App\Livewire\Teachers\Index;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TeachersManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_can_create_a_teacher(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);

        Livewire::actingAs($staff)
            ->test(Index::class)
            ->set('name', 'Dr Naveed')
            ->set('email', 'naveed@example.com')
            ->call('save');

        $this->assertDatabaseHas('teachers', ['name' => 'Dr Naveed', 'email' => 'naveed@example.com']);
    }

    public function test_teacher_emails_must_be_unique(): void
    {
        Teacher::factory()->create(['email' => 'taken@example.com']);
        $staff = User::factory()->create(['role' => 'staff']);

        Livewire::actingAs($staff)
            ->test(Index::class)
            ->set('name', 'Someone Else')
            ->set('email', 'taken@example.com')
            ->call('save')
            ->assertHasErrors(['email']);
    }

    public function test_toggling_active_flips_status(): void
    {
        $teacher = Teacher::factory()->create(['is_active' => true]);
        $staff = User::factory()->create(['role' => 'staff']);

        Livewire::actingAs($staff)
            ->test(Index::class)
            ->call('toggleActive', $teacher->id);

        $this->assertFalse($teacher->fresh()->is_active);
    }

    public function test_user_without_manage_teachers_permission_is_forbidden(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $staff->permissionOverrides()->create(['permission' => 'manage_teachers', 'granted' => false]);

        $this->actingAs($staff)
            ->get('/teachers')
            ->assertForbidden();
    }
}
