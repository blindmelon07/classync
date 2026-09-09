<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClassRoom;
use App\Models\Policy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

// ARCHITECTURE.md Ch.4: "Server is authoritative for policies. Instructor
// edits go local -> outbox -> server; the server increments `version` and
// the new version propagates on the next pull." That increment happens in
// store() below.
class PolicyController extends Controller
{
    /**
     * Pull policies for a class, optionally only those newer than a cached
     * version the student device already has (?since_version=3).
     */
    public function index(Request $request, ClassRoom $class): JsonResponse
    {
        if (! $this->canAccess($request, $class)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $query = $class->policies()->where('active', true);

        if ($request->filled('since_version')) {
            $query->where('version', '>', (int) $request->query('since_version'));
        }

        return response()->json(['policies' => $query->get()->map(fn ($p) => $this->policyPayload($p))]);
    }

    /** Teacher creates or updates (by package_name) a policy for their class. */
    public function store(Request $request, ClassRoom $class): JsonResponse
    {
        $user = $request->user();
        if (! $user->isTeacher() || $class->teacher_id !== $user->id) {
            return response()->json(['message' => 'Only this class\'s teacher can edit policies'], 403);
        }

        $validator = Validator::make($request->all(), [
            'package_name' => ['required', 'string', 'max:255'],
            'mode' => ['required', 'string', 'in:block,warn'],
            'active' => ['sometimes', 'boolean'],
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }
        $data = $validator->validated();

        $existing = Policy::where('class_id', $class->id)
            ->where('package_name', $data['package_name'])
            ->first();

        if ($existing) {
            $existing->mode = $data['mode'];
            $existing->active = $data['active'] ?? true;
            $existing->version += 1;
            $existing->save();
            $policy = $existing;
        } else {
            $policy = Policy::create([
                'class_id' => $class->id,
                'package_name' => $data['package_name'],
                'mode' => $data['mode'],
                'active' => $data['active'] ?? true,
                'version' => 1,
            ]);
        }

        return response()->json(['policy' => $this->policyPayload($policy)], 201);
    }

    private function canAccess(Request $request, ClassRoom $class): bool
    {
        $user = $request->user();
        if ($user->isTeacher()) {
            return $class->teacher_id === $user->id;
        }

        return $class->students()->where('users.id', $user->id)->exists();
    }

    private function policyPayload(Policy $policy): array
    {
        return [
            'id' => $policy->id,
            'class_id' => $policy->class_id,
            'package_name' => $policy->package_name,
            'mode' => $policy->mode,
            'active' => $policy->active,
            'version' => $policy->version,
        ];
    }
}
