<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BlockEvent;
use App\Models\ClassSession;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

// Matches how the local `outbox` table drains (ARCHITECTURE.md Ch.4/Ch.7
// step 7): a student device accumulates block_events locally while
// offline, then pushes them here as a batch once SyncService can reach the
// LAN server. Accepting an array here, not one row per request, is what
// makes that batch drain efficient instead of one HTTP round-trip per event.
class BlockEventController extends Controller
{
    public function store(Request $request, ClassSession $session): JsonResponse
    {
        $user = $request->user();
        if (! $user->isStudent()) {
            return response()->json(['message' => 'Only students push block events'], 403);
        }

        $isMember = $session->classRoom->students()->where('users.id', $user->id)->exists();
        if (! $isMember) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validator = Validator::make($request->all(), [
            'events' => ['required', 'array', 'min:1'],
            'events.*.package_name' => ['required', 'string', 'max:255'],
            'events.*.at' => ['required', 'date'],
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        // A raw insert() (needed for a real batch, one round-trip for the
        // whole outbox drain) skips Eloquent's datetime casting -- so unlike
        // create(), this has to format `at` for MySQL itself, not just pass
        // through whatever ISO 8601 string the client sent.
        $rows = collect($validator->validated()['events'])->map(fn ($e) => [
            'session_id' => $session->id,
            'student_id' => $user->id,
            'package_name' => $e['package_name'],
            'at' => Carbon::parse($e['at'])->format('Y-m-d H:i:s'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        BlockEvent::insert($rows->all());

        return response()->json(['inserted' => $rows->count()], 201);
    }
}
