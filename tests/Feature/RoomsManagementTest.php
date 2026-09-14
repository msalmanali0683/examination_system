<?php

namespace Tests\Feature;

use App\Livewire\Rooms\Index;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RoomsManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_can_create_a_room(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);

        Livewire::actingAs($staff)
            ->test(Index::class)
            ->set('name', 'ITC-310')
            ->set('rows', 20)
            ->set('columns', 5)
            ->set('capacity', 100)
            ->set('room_type', 'regular')
            ->call('save');

        $this->assertDatabaseHas('rooms', ['name' => 'ITC-310', 'capacity' => 100]);
    }

    public function test_capacity_cannot_exceed_rows_times_columns(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);

        Livewire::actingAs($staff)
            ->test(Index::class)
            ->set('name', 'ITC-311')
            ->set('rows', 5)
            ->set('columns', 5)
            ->set('capacity', 999)
            ->set('room_type', 'regular')
            ->call('save')
            ->assertHasErrors(['capacity']);

        $this->assertDatabaseMissing('rooms', ['name' => 'ITC-311']);
    }

    public function test_room_names_must_be_unique(): void
    {
        Room::factory()->create(['name' => 'ITC-310']);
        $staff = User::factory()->create(['role' => 'staff']);

        Livewire::actingAs($staff)
            ->test(Index::class)
            ->set('name', 'ITC-310')
            ->set('rows', 5)
            ->set('columns', 5)
            ->set('capacity', 25)
            ->call('save')
            ->assertHasErrors(['name']);
    }

    public function test_user_without_manage_rooms_permission_is_forbidden(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $staff->permissionOverrides()->create(['permission' => 'manage_rooms', 'granted' => false]);

        $this->actingAs($staff)
            ->get('/rooms')
            ->assertForbidden();
    }

    public function test_per_page_selector_controls_how_many_rooms_are_shown(): void
    {
        Room::factory()->count(15)->create();
        $staff = User::factory()->create(['role' => 'staff']);

        Livewire::actingAs($staff)
            ->test(Index::class)
            ->set('perPage', 10)
            ->assertViewHas('rooms', fn ($rooms) => $rooms->count() === 10 && $rooms->total() === 15);
    }
}
