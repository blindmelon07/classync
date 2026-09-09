<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClassRoom;
use App\Models\ClassSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// These endpoints are what SyncService (ARCHITECTURE.md Ch.7 step 7) will
// call once it exists -- the classroom enforcement loop itself (Ch.5) never
// calls these directly, it runs entirely off the local BLE beacon and
// cached policy. This is the "opportunistic, LAN-only" sync target, not
// something the live loop depends on.
class ClassSessionController extends Controller
{
    /** Teacher starts a session for their class. Ends any other active one first. */
    public function store(Request $request, ClassRoom $class): JsonResponse
    {
        $user = $request->user();
        if (! $user->isTeacher() || $class->teacher_id !== $user->id) {
            return response()->json(['message' => 'Only this class\'s teacher can start a session'], 403);
        }

        $class->sessions()->where('active', true)->update([
            'active' => false,
            'ended_at' => now(),
        ]);

        $session = $class->sessions()->create([
            'started_at' => now(),
            'active' => true,
        ]);

        return response()->json(['session' => $this->sessionPayload($session)], 201);
    }

    public function end(Request $request, ClassSession $session): JsonResponse
    {
        $user = $request->user();
        if (! $user->isTeacher() || $session->classRoom->teacher_id !== $user->id) {
            return response()->json(['message' => 'Only this class\'s teacher can end this session'], 403);
        }

        $session->update(['active' => false, 'ended_at' => now()]);

        return response()->json(['session' => $this->sessionPayload($session)]);
    }

    /** The currently active session for a class, if any -- null otherwise. */
    public function active(Request $request, ClassRoom $class): JsonResponse
    {
        $user = $request->user();
        $isMember = $user->isTeacher()
            ? $class->teacher_id === $user->id
            : $class->students()->where('users.id', $user->id)->exists();

        if (! $isMember) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $session = $class->sessions()->where('active', true)->latest('started_at')->first();

        return response()->json(['session' => $session ? $this->sessionPayload($session) : null]);
    }

    private function sessionPayload(ClassSession $session): array
    {
        return [
            'id' => $session->id,
            'class_id' => $session->class_id,
            'started_at' => $session->started_at?->toIso8601String(),
            'ended_at' => $session->ended_at?->toIso8601String(),
            'active' => $session->active,
        ];
    }
}
