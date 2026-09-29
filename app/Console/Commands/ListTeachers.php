<?php

namespace App\Console\Commands;

use App\Models\TeacherEmail;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('teachers:list')]
#[Description('List the emails on the teacher list')]
class ListTeachers extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $teachers = TeacherEmail::orderBy('email')->get(['email', 'created_at']);

        if ($teachers->isEmpty()) {
            $this->info('The teacher list is empty. Everyone who signs in is a student.');

            return self::SUCCESS;
        }

        $this->table(
            ['Email', 'Added'],
            $teachers->map(fn (TeacherEmail $teacher) => [$teacher->email, $teacher->created_at?->toDateTimeString()]),
        );
        $this->info("{$teachers->count()} teacher email(s).");

        return self::SUCCESS;
    }
}
