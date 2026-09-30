<?php

namespace App\Console\Commands;

use App\Models\TeacherEmail;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;

#[Signature('students:remove {class : The class join code} {emails* : One or more student email addresses}')]
#[Description('Take students out of a class (their accounts are kept)')]
class RemoveStudents extends EnrollStudents
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $class = $this->findClass();

        if (! $class) {
            return self::FAILURE;
        }

        foreach ($this->argument('emails') as $email) {
            $email = TeacherEmail::normalize($email);
            $student = $class->students()->where('email', $email)->first();

            if ($student) {
                $class->students()->detach($student->id);
                $this->info("Removed {$email}");
            } else {
                $this->warn("Not in this class: {$email}");
            }
        }

        return self::SUCCESS;
    }
}
