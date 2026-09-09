<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClassRoom;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ClassRoomController extends Controller
{
    /** Teacher: classes they teach. Student: classes they've joined. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $classes = $user->isTeacher()
            ? $user->teachingClasses()->withCount('students')->get()
            : $user->joinedClasses;

        return response()->json(['classes' => $classes->map(fn ($c) => $this->classPayload($c))]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->isTeacher()) {
            return response()->json(['message' => 'Only teachers can create classes'], 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $class = ClassRoom::create([
            'teacher_id' => $user->id,
            'name' => $validator->validated()['name'],
            'join_code' => $this->generateUniqueJoinCode(),
        ]);

        return response()->json(['class' => $this->classPayload($class)], 201);
    }

    public function show(Request $request, ClassRoom $class): JsonResponse
    {
        if (! $this->canAccess($request, $class)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return response()->json(['class' => $this->classPayload($class, withPolicies: true)]);
    }

    /** Student joins a class using the teacher-shared join code. */
    public function join(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->isStudent()) {
            return response()->json(['message' => 'Only students can join classes'], 403);
        }

        $validator = Validator::make($request->all(), [
            'join_code' => ['required', 'string'],
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $class = ClassRoom::where('join_code', strtoupper($validator->validated()['join_code']))->first();
        if (! $class) {
            return response()->json(['message' => 'No class found with that code'], 404);
        }

        $user->joinedClasses()->syncWithoutDetaching([
            $class->id => ['joined_at' => now()],
        ]);

        return response()->json(['class' => $this->classPayload($class)]);
    }

    public function canAccess(Request $request, ClassRoom $class): bool
    {
        $user = $request->user();
        if ($user->isTeacher()) {
            return $class->teacher_id === $user->id;
        }

        return $class->students()->where('users.id', $user->id)->exists();
    }

    private function generateUniqueJoinCode(): string
    {
        do {
            $code = strtoupper(Str::random(6));
        } while (ClassRoom::where('join_code', $code)->exists());

        return $code;
    }

    private function classPayload(ClassRoom $class, bool $withPolicies = false): array
    {
        $payload = [
            'id' => $class->id,
            'name' => $class->name,
            'join_code' => $class->join_code,
            'teacher_id' => $class->teacher_id,
        ];

        if ($withPolicies) {
            $payload['policies'] = $class->policies()->where('active', true)->get()->map(fn ($p) => [
                'id' => $p->id,
                'package_name' => $p->package_name,
                'mode' => $p->mode,
                'version' => $p->version,
            ]);
        }

        return $payload;
    }
}
