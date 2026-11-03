<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BookingDestroyTest extends TestCase
{
    use RefreshDatabase;

    public function test_author_can_delete_booking(): void
    {
        $user = User::factory()->create();
        $booking = Booking::factory()->for($user)->create();
        Sanctum::actingAs($user);

        $this->deleteJson("/api/bookings/{$booking->id}")->assertNoContent();

        $this->assertDatabaseMissing('bookings', ['id' => $booking->id]);
    }

    public function test_user_cannot_delete_another_users_booking(): void
    {
        $author = User::factory()->create();
        $otherUser = User::factory()->create();
        $booking = Booking::factory()->for($author)->create();
        Sanctum::actingAs($otherUser);

        $this->deleteJson("/api/bookings/{$booking->id}")
            ->assertForbidden()
            ->assertJsonPath('message', 'Отменить бронь может только её автор.');

        $this->assertDatabaseHas('bookings', ['id' => $booking->id]);
    }

    public function test_returns_not_found_for_missing_booking(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->deleteJson('/api/bookings/999999')->assertNotFound();
    }
}
