<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Office;
use App\Models\Room;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BookingStoreTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
    }

    public function test_creates_booking_for_authenticated_user(): void
    {
        $room = $this->createRoom();
        $otherUser = User::factory()->create();

        $this->postJson('/api/bookings', [
            ...$this->payload($room),
            'user_id' => $otherUser->id,
        ])->assertCreated()->assertJsonPath('user_id', $this->user->id);

        $booking = Booking::sole();
        $this->assertSame($this->user->id, $booking->user_id);
        $this->assertSame($room->id, $booking->room_id);
        $this->assertSame('Планёрка', $booking->title);
        $this->assertSame('2026-11-02 07:00:00', $booking->starts_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-02 08:00:00', $booking->ends_at->format('Y-m-d H:i:s'));
    }

    #[DataProvider('overlappingIntervals')]
    public function test_rejects_overlapping_bookings(string $startsAt, string $endsAt): void
    {
        $room = $this->createRoom();
        $this->createBooking($room, '2026-11-02T10:00:00+03:00', '2026-11-02T12:00:00+03:00');

        $this->postJson('/api/bookings', $this->payload($room, $startsAt, $endsAt))
            ->assertStatus(409)
            ->assertJsonPath('error', 'Комната занята');

        $this->assertDatabaseCount('bookings', 1);
    }

    public static function overlappingIntervals(): iterable
    {
        yield 'same interval' => ['2026-11-02T10:00:00+03:00', '2026-11-02T12:00:00+03:00'];
        yield 'overlaps start' => ['2026-11-02T09:00:00+03:00', '2026-11-02T11:00:00+03:00'];
        yield 'overlaps end' => ['2026-11-02T11:00:00+03:00', '2026-11-02T13:00:00+03:00'];
        yield 'contains existing' => ['2026-11-02T09:00:00+03:00', '2026-11-02T13:00:00+03:00'];
        yield 'inside existing' => ['2026-11-02T10:30:00+03:00', '2026-11-02T11:30:00+03:00'];
    }

    #[DataProvider('adjacentIntervals')]
    public function test_allows_adjacent_bookings(string $startsAt, string $endsAt): void
    {
        $room = $this->createRoom();
        $this->createBooking($room, '2026-11-02T10:00:00+03:00', '2026-11-02T11:00:00+03:00');

        $this->postJson('/api/bookings', $this->payload($room, $startsAt, $endsAt))->assertCreated();

        $this->assertDatabaseCount('bookings', 2);
    }

    public static function adjacentIntervals(): iterable
    {
        yield 'before existing' => ['2026-11-02T09:00:00+03:00', '2026-11-02T10:00:00+03:00'];
        yield 'after existing' => ['2026-11-02T11:00:00+03:00', '2026-11-02T12:00:00+03:00'];
    }

    public function test_allows_same_interval_in_another_room(): void
    {
        $room = $this->createRoom();
        $otherRoom = $this->createRoom();
        $this->createBooking($room);

        $this->postJson('/api/bookings', $this->payload($otherRoom))->assertCreated();

        $this->assertDatabaseCount('bookings', 2);
    }

    #[DataProvider('validDurations')]
    public function test_allows_duration_boundaries(string $endsAt): void
    {
        $room = $this->createRoom();

        $this->postJson('/api/bookings', $this->payload($room, endsAt: $endsAt))->assertCreated();

        $this->assertDatabaseCount('bookings', 1);
    }

    public static function validDurations(): iterable
    {
        yield '15 minutes' => ['2026-11-02T10:15:00+03:00'];
        yield '4 hours' => ['2026-11-02T14:00:00+03:00'];
    }

    #[DataProvider('invalidDurations')]
    public function test_rejects_invalid_duration(string $startsAt, string $endsAt): void
    {
        $room = $this->createRoom();

        $this->postJson('/api/bookings', $this->payload($room, $startsAt, $endsAt))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ends_at');

        $this->assertDatabaseCount('bookings', 0);
    }

    public static function invalidDurations(): iterable
    {
        yield 'less than 15 minutes' => ['2026-11-02T10:00:00+03:00', '2026-11-02T10:10:00+03:00'];
        yield 'more than 4 hours' => ['2026-11-02T10:00:00+03:00', '2026-11-02T14:15:00+03:00'];
        yield 'not divisible by 15' => ['2026-11-02T10:00:00+03:00', '2026-11-02T11:10:00+03:00'];
        yield 'zero duration' => ['2026-11-02T10:00:00+03:00', '2026-11-02T10:00:00+03:00'];
        yield 'end before start' => ['2026-11-02T10:00:00+03:00', '2026-11-02T09:00:00+03:00'];
    }

    public function test_allows_start_on_quarter_hour(): void
    {
        $room = $this->createRoom();

        $this->postJson('/api/bookings', $this->payload(
            $room,
            '2026-11-02T10:15:00+03:00',
            '2026-11-02T11:15:00+03:00',
        ))->assertCreated();
    }

    #[DataProvider('invalidStartTimes')]
    public function test_rejects_start_off_quarter_hour_grid(string $startsAt, string $endsAt): void
    {
        $room = $this->createRoom();

        $this->postJson('/api/bookings', $this->payload($room, $startsAt, $endsAt))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('starts_at');
    }

    public static function invalidStartTimes(): iterable
    {
        yield 'minutes off grid' => ['2026-11-02T10:10:00+03:00', '2026-11-02T11:10:00+03:00'];
        yield 'non-zero seconds' => ['2026-11-02T10:00:30+03:00', '2026-11-02T11:00:30+03:00'];
    }

    #[DataProvider('workingHours')]
    public function test_validates_full_interval_in_office_working_hours(
        string $timezone,
        string $startsAt,
        string $endsAt,
        bool $valid,
        ?string $errorField = null,
    ): void {
        $room = $this->createRoom($timezone);

        $response = $this->postJson('/api/bookings', $this->payload(
            $room,
            CarbonImmutable::parse($startsAt, $timezone)->toIso8601String(),
            CarbonImmutable::parse($endsAt, $timezone)->toIso8601String(),
        ));

        if ($valid) {
            $response->assertCreated();
            $this->assertDatabaseCount('bookings', 1);

            return;
        }

        $response->assertUnprocessable()->assertJsonValidationErrors($errorField);
        $this->assertDatabaseCount('bookings', 0);
    }

    public static function workingHours(): iterable
    {
        foreach (['Europe/Moscow', 'Asia/Novosibirsk'] as $timezone) {
            yield "$timezone opening boundary" => [$timezone, '2026-11-02 09:00', '2026-11-02 10:00', true];
            yield "$timezone closing boundary" => [$timezone, '2026-11-02 19:00', '2026-11-02 20:00', true];
            yield "$timezone before opening" => [$timezone, '2026-11-02 08:45', '2026-11-02 10:00', false, 'starts_at'];
            yield "$timezone after closing" => [$timezone, '2026-11-02 19:00', '2026-11-02 20:15', false, 'ends_at'];
        }
    }

    #[DataProvider('equivalentIsoOffsets')]
    public function test_accepts_equivalent_iso_offsets(string $startsAt, string $endsAt): void
    {
        $room = $this->createRoom();

        $this->postJson('/api/bookings', $this->payload($room, $startsAt, $endsAt))->assertCreated();

        $booking = Booking::sole();
        $this->assertSame('2026-11-02 09:00:00', $booking->starts_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-02 10:00:00', $booking->ends_at->format('Y-m-d H:i:s'));
    }

    public static function equivalentIsoOffsets(): iterable
    {
        yield 'UTC' => ['2026-11-02T09:00:00Z', '2026-11-02T10:00:00Z'];
        yield 'positive non-hour offset' => ['2026-11-02T14:30:00+05:30', '2026-11-02T15:30:00+05:30'];
        yield 'negative offset' => ['2026-11-02T04:00:00-05:00', '2026-11-02T05:00:00-05:00'];
    }

    public function test_detects_overlap_between_different_iso_offsets(): void
    {
        $room = $this->createRoom();
        $this->createBooking($room, '2026-11-02T10:00:00+03:00', '2026-11-02T12:00:00+03:00');

        $this->postJson('/api/bookings', $this->payload(
            $room,
            '2026-11-02T08:30:00+01:00',
            '2026-11-02T09:30:00+01:00',
        ))->assertStatus(409);

        $this->assertDatabaseCount('bookings', 1);
    }

    #[DataProvider('unrestrictedDates')]
    public function test_allows_past_future_and_weekend_dates(string $startsAt, string $endsAt): void
    {
        $room = $this->createRoom();

        $this->postJson('/api/bookings', $this->payload($room, $startsAt, $endsAt))->assertCreated();
    }

    public static function unrestrictedDates(): iterable
    {
        yield 'past' => ['2020-01-06T10:00:00+03:00', '2020-01-06T11:00:00+03:00'];
        yield 'weekend' => ['2026-11-01T10:00:00+03:00', '2026-11-01T11:00:00+03:00'];
        yield 'far future' => ['2035-11-02T10:00:00+03:00', '2035-11-02T11:00:00+03:00'];
    }

    #[DataProvider('requiredFields')]
    public function test_requires_fields(string $field): void
    {
        $room = $this->createRoom();
        $payload = $this->payload($room);
        unset($payload[$field]);

        $this->postJson('/api/bookings', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);
    }

    public static function requiredFields(): iterable
    {
        yield 'room' => ['room_id'];
        yield 'title' => ['title'];
        yield 'start' => ['starts_at'];
        yield 'end' => ['ends_at'];
    }

    #[DataProvider('invalidDates')]
    public function test_rejects_invalid_dates(string $field): void
    {
        $room = $this->createRoom();
        $payload = $this->payload($room);
        $payload[$field] = 'not-a-date';

        $this->postJson('/api/bookings', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);
    }

    public static function invalidDates(): iterable
    {
        yield 'start' => ['starts_at'];
        yield 'end' => ['ends_at'];
    }

    public function test_rejects_nonexistent_room(): void
    {
        $room = $this->createRoom();
        $payload = $this->payload($room);
        $payload['room_id'] = PHP_INT_MAX;

        $this->postJson('/api/bookings', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('room_id');
    }

    private function createRoom(string $timezone = 'Europe/Moscow'): Room
    {
        $office = Office::factory()->create(['timezone' => $timezone]);

        return Room::factory()->for($office)->create();
    }

    private function createBooking(
        Room $room,
        string $startsAt = '2026-11-02T10:00:00+03:00',
        string $endsAt = '2026-11-02T11:00:00+03:00',
    ): Booking {
        return Booking::factory()->for($room)->for($this->user)->create([
            'starts_at' => CarbonImmutable::parse($startsAt)->utc(),
            'ends_at' => CarbonImmutable::parse($endsAt)->utc(),
        ]);
    }

    private function payload(
        Room $room,
        string $startsAt = '2026-11-02T10:00:00+03:00',
        string $endsAt = '2026-11-02T11:00:00+03:00',
    ): array {
        return [
            'room_id' => $room->id,
            'title' => 'Планёрка',
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ];
    }
}
