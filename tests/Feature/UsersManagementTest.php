<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UsersManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_head_can_view_users_page(): void
    {
        $head = User::factory()->create(['role' => 'head']);

        $this->actingAs($head)
            ->get('/users')
            ->assertOk()
            ->assertSee('Users & Permissions');
    }

    public function test_staff_cannot_view_users_page(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);

        $this->actingAs($staff)
            ->get('/users')
            ->assertForbidden();
    }

    public function test_head_can_create_a_user(): void
    {
        $head = User::factory()->create(['role' => 'head']);

        \Livewire\Livewire::actingAs($head)
            ->test(\App\Livewire\Users\Index::class)
            ->set('name', 'New Staffer')
            ->set('email', 'staffer@example.com')
            ->set('password', 'password123')
            ->set('role', 'staff')
            ->call('createUser');

        $this->assertDatabaseHas('users', [
            'email' => 'staffer@example.com',
            'role' => 'staff',
        ]);
    }

    public function test_staff_default_permissions_can_be_overridden_per_user(): void
    {
        $head = User::factory()->create(['role' => 'head']);
        $staff = User::factory()->create(['role' => 'staff']);

        $this->assertFalse($staff->hasPermission('finalize_sessions'));

        \Livewire\Livewire::actingAs($head)
            ->test(\App\Livewire\Users\Index::class)
            ->call('toggleExpand', $staff->id)
            ->call('setOverride', 'finalize_sessions', 'granted');

        $this->assertTrue($staff->fresh()->hasPermission('finalize_sessions'));
    }

    public function test_last_remaining_head_cannot_be_demoted(): void
    {
        $head = User::factory()->create(['role' => 'head']);

        \Livewire\Livewire::actingAs($head)
            ->test(\App\Livewire\Users\Index::class)
            ->call('updateRole', $head->id, 'staff');

        $this->assertSame('head', $head->fresh()->role);
    }

    public function test_last_remaining_head_cannot_be_deleted(): void
    {
        $head = User::factory()->create(['role' => 'head']);

        \Livewire\Livewire::actingAs($head)
            ->test(\App\Livewire\Users\Index::class)
            ->call('deleteUser', $head->id);

        $this->assertDatabaseHas('users', ['id' => $head->id]);
    }
}
