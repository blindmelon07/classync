<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;

#[Signature('students:list {class? : A class join code; omit to list every student}')]
#[Description('List the students in a class, or every student')]
class ListStudents extends EnrollStudents
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if ($this->argument('class') === null) {
            return $this->listEveryStudent();
        }

        $class = $this->findClass();

        if (! $class) {
            return self::FAILURE;
        }

        $students = $class->students()->orderBy('email')->get();

        if ($students->isEmpty()) {
            $this->info("No students in {$class->name} ({$class->join_code}).");

            return self::SUCCESS;
        }

        $this->table(
            ['Email', 'Name', 'Signed in', 'Joined'],
            $students->map(fn (User $student) => [
                $student->email,
                $student->name,
                $student->google_id ? 'yes' : 'not yet',
                $student->pivot->joined_at,
            ]),
        );
        $this->info("{$students->count()} student(s) in {$class->name} ({$class->join_code}).");

        return self::SUCCESS;
    }

    private function listEveryStudent(): int
    {
        $students = User::where('role', 'student')->with('joinedClasses')->orderBy('email')->get();

        if ($students->isEmpty()) {
            $this->info('No students yet.');

            return self::SUCCESS;
        }

        $this->table(
            ['Email', 'Name', 'Signed in', 'Classes'],
            $students->map(fn (User $student) => [
                $student->email,
                $student->name,
                $student->google_id ? 'yes' : 'not yet',
                $student->joinedClasses->pluck('join_code')->implode(', '),
            ]),
        );
        $this->info("{$students->count()} student(s).");

        return self::SUCCESS;
    }
}
