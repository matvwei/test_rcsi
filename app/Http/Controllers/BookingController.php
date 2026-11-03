<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Room;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class BookingController extends Controller
{
    public function index(Request $request)
    {
        return Booking::where('user_id', $request->user()->id)
            ->orderByRaw('starts_at DESC')
            ->paginate(20);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'room_id' => ['required', 'integer', 'exists:rooms,id'],
            'title' => ['required', 'string', 'max:255'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date'],
        ]);

        $room = Room::with('office')->findOrFail($data['room_id']);
        $startsAt = Carbon::parse($data['starts_at']);
        $endsAt = Carbon::parse($data['ends_at']);
        $localStartsAt = $startsAt->copy()->setTimezone($room->office->timezone);
        $localEndsAt = $endsAt->copy()->setTimezone($room->office->timezone);
        $errors = [];

        if ($endsAt->lessThanOrEqualTo($startsAt)) {
            $errors['ends_at'][] = 'Окончание брони должно быть позже начала.';
        } else {
            $duration = $startsAt->diffInMinutes($endsAt);

            if ($duration < 15 || $duration > 240) {
                $errors['ends_at'][] = 'Длительность брони должна быть от 15 минут до 4 часов.';
            } elseif (fmod($duration, 15) !== 0.0) {
                $errors['ends_at'][] = 'Длительность брони должна быть кратна 15 минутам.';
            }
        }

        if ($localStartsAt->minute % 15 !== 0 || $localStartsAt->second !== 0 || $localStartsAt->micro !== 0) {
            $errors['starts_at'][] = 'Время начала должно быть на сетке 15 минут, без секунд.';
        }

        $workStartsAt = $localStartsAt->copy()->setTime(9, 0);
        $workEndsAt = $localStartsAt->copy()->setTime(20, 0);

        if ($localStartsAt->lessThan($workStartsAt)) {
            $errors['starts_at'][] = 'Бронь не может начинаться раньше 09:00 по местному времени офиса.';
        }

        if ($localEndsAt->greaterThan($workEndsAt)) {
            $errors['ends_at'][] = 'Бронь должна заканчиваться не позже 20:00 по местному времени офиса.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $startsAt->utc();
        $endsAt->utc();

        return DB::transaction(function () use ($request, $data, $startsAt, $endsAt): JsonResponse {
            $room = Room::whereKey($data['room_id'])->lockForUpdate()->firstOrFail();
            $conflict = $room->bookings()
                ->where('starts_at', '<', $endsAt)
                ->where('ends_at', '>', $startsAt)
                ->exists();

            if ($conflict) {
                return response()->json(['error' => 'Комната занята'], 409);
            }

            $booking = Booking::create([
                'room_id' => $room->id,
                'user_id' => $request->user()->id,
                'title' => $data['title'],
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
            ]);

            Log::withContext(['booking_id' => $booking->id]);
            Log::channel('bookings')->info('Booking created');

            return response()->json($booking, 201);
        });
    }

    public function destroy(Request $request, string $id): Response
    {
        $booking = Booking::findOrFail($id);

        abort_if($booking->user_id !== $request->user()->id, 403, 'Отменить бронь может только её автор.');

        $booking->delete();

        return response()->noContent();
    }

    public function today(Room $room)
    {
        return $room->bookings()
            ->whereDate('starts_at', today())
            ->get()
            ->map(fn ($b) => [
                'time' => $b->starts_at.' – '.$b->ends_at,
                'author' => $b->user->name,
            ]);
    }
}
