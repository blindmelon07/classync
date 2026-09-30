<?php

namespace App\Console\Commands;

use App\Models\ClassRoom;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('classes:list')]
#[Description('List every class with its join code (the code the students:* commands take)')]
class ListClasses extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $classes = ClassRoom::with('teacher')->withCount('students')->orderBy('name')->get();

        if ($classes->isEmpty()) {
            $this->info('No classes yet. Teachers create them in the app.');

            return self::SUCCESS;
        }

        $this->table(
            ['Join code', 'Name', 'Teacher', 'Students'],
            $classes->map(fn (ClassRoom $class) => [
                $class->join_code,
                $class->name,
                $class->teacher?->email,
                $class->students_count,
            ]),
        );
        $this->info("{$classes->count()} class(es).");

        return self::SUCCESS;
    }
}
