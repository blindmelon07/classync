<?php

namespace App\Services;

use App\Models\ClassRoom;
use App\Models\TeacherEmail;
use App\Models\User;

/**
 * The teacher list and class rosters, shared by the artisan commands and the
 * admin API so both follow the same rules.
 */
class Roster
{
    /**
     * @param  list<string>  $emails
     * @return array{added: int, existing: int, invalid: list<string>}
     */
    public function addTeachers(array $emails): array
    {
        $result = ['added' => 0, 'existing' => 0, 'invalid' => []];

        foreach ($emails as $email) {
            $email = TeacherEmail::normalize($email);

            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $result['invalid'][] = $email;

                continue;
            }

            TeacherEmail::firstOrCreate(['email' => $email])->wasRecentlyCreated ? $result['added']++ : $result['existing']++;
        }

        return $result;
    }

    /**
     * Puts students in [$class] by email. An email with no account yet gets a
     * placeholder (no password, no Google id) that Google sign-in links to by
     * email and fills in the real name.
     *
     * @param  list<string>  $emails
     * @return array{enrolled: int, already: int, created: int, skipped: list<string>}
     */
    public function enrollStudents(ClassRoom $class, array $emails): array
    {
        $result = ['enrolled' => 0, 'already' => 0, 'created' => 0, 'skipped' => []];

        foreach ($emails as $email) {
            $email = TeacherEmail::normalize($email);

            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $result['skipped'][] = "Skipped invalid email: {$email}";

                continue;
            }

            // Google sign-in would make these accounts teachers, and a teacher
            // can't be a class member.
            if (TeacherEmail::isTeacher($email)) {
                $result['skipped'][] = "Skipped {$email}: on the teacher list";

                continue;
            }

            if (User::isAdminEmail($email)) {
                $result['skipped'][] = "Skipped {$email}: is an admin";

                continue;
            }

            $user = User::where('email', $email)->first();

            if ($user && ! $user->isStudent()) {
                $result['skipped'][] = "Skipped {$email}: account is a {$user->role}";

                continue;
            }

            if (! $user) {
                $user = User::create(['name' => $email, 'email' => $email, 'role' => 'student']);
                $result['created']++;
            }

            if ($class->students()->whereKey($user->id)->exists()) {
                $result['already']++;

                continue;
            }

            $class->students()->attach($user->id, ['joined_at' => now()]);
            $result['enrolled']++;
        }

        return $result;
    }
}
