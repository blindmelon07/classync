<?php

namespace App\Console\Commands;

use App\Models\TeacherEmail;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('teachers:remove {emails* : One or more teacher email addresses}')]
#[Description('Remove emails from the teacher list (they become students at their next Google sign-in)')]
class RemoveTeachers extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        foreach ($this->argument('emails') as $email) {
            $email = TeacherEmail::normalize($email);

            TeacherEmail::where('email', $email)->delete()
                ? $this->info("Removed {$email}")
                : $this->warn("Not on the teacher list: {$email}");
        }

        return self::SUCCESS;
    }
}
