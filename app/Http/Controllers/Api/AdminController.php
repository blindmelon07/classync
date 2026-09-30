<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClassRoom;
use App\Models\TeacherEmail;
use App\Models\User;
use App\Services\Roster;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * The app's Admin screens: the teacher list and class rosters. Same rules as
 * the teachers:* and students:* artisan commands (both go through Roster).
 * Every route here sits behind the `admin` middleware.
 */
class AdminController extends Controller
{
    public function __construct(private readonly Roster $roster) {}

    public function teachers(): JsonResponse
    {
        $teachers = TeacherEmail::orderBy('email')->get()->map(fn (TeacherEmail $teacher) => [
            'email' => $teacher->email,
            'added_at' => $teacher->created_at?->toIso8601String(),
        ]);

        return response()->json(['teachers' => $teachers]);
    }

    public function addTeachers(Request $request): JsonResponse
    {
        $emails = $this->validatedEmails($request);
        if ($emails instanceof JsonResponse) {
            return $emails;
        }

        return response()->json($this->roster->addTeachers($emails));
    }

    public function removeTeacher(string $email): JsonResponse
    {
        $deleted = TeacherEmail::where('email', TeacherEmail::normalize($email))->delete();

        return $deleted
            ? response()->json(['message' => 'Removed'])
            : response()->json(['message' => 'Not on the teacher list'], 404);
    }

    public function classes(): JsonResponse
    {
        $classes = ClassRoom::with('teacher')->withCount('students')->orderBy('name')->get()
            ->map(fn (ClassRoom $class) => [
                'id' => $class->id,
                'name' => $class->name,
                'join_code' => $class->join_code,
                'teacher_name' => $class->teacher?->name,
                'teacher_email' => $class->teacher?->email,
                'students_count' => $class->students_count,
            ]);

        return response()->json(['classes' => $classes]);
    }

    public function students(ClassRoom $class): JsonResponse
    {
        $students = $class->students()->orderBy('email')->get()->map(fn (User $student) => [
            'id' => $student->id,
            'name' => $student->name,
            'email' => $student->email,
            // False for a placeholder added by email that hasn't signed in.
            'signed_in' => $student->google_id !== null,
        ]);

        return response()->json(['students' => $students]);
    }

    public function enroll(Request $request, ClassRoom $class): JsonResponse
    {
        $emails = $this->validatedEmails($request);
        if ($emails instanceof JsonResponse) {
            return $emails;
        }

        return response()->json($this->roster->enrollStudents($class, $emails));
    }

    public function unenroll(ClassRoom $class, User $student): JsonResponse
    {
        return $class->students()->detach($student->id)
            ? response()->json(['message' => 'Removed'])
            : response()->json(['message' => 'Not in this class'], 404);
    }

    /**
     * @return list<string>|JsonResponse
     */
    private function validatedEmails(Request $request): array|JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'emails' => ['required', 'array', 'min:1', 'max:500'],
            'emails.*' => ['required', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        return array_values($validator->validated()['emails']);
    }
}
