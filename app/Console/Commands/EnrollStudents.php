<?php

namespace App\Console\Commands;

use App\Models\ClassRoom;
use App\Services\Roster;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('students:enroll {class : The class join code} {emails* : One or more student email addresses}')]
#[Description('Put students in a class by email (students who have not signed in yet are linked at their first Google sign-in)')]
class EnrollStudents extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $class = $this->findClass();

        return $class ? $this->enroll($class, $this->argument('emails')) : self::FAILURE;
    }

    protected function findClass(): ?ClassRoom
    {
        $code = strtoupper(trim($this->argument('class')));
        $class = ClassRoom::where('join_code', $code)->first();

        if (! $class) {
            $this->error("No class found with join code: {$code}");
        }

        return $class;
    }

    /**
     * @param  list<string>  $emails
     */
    protected function enroll(ClassRoom $class, array $emails): int
    {
        $result = app(Roster::class)->enrollStudents($class, $emails);

        $this->info("Enrolled {$result['enrolled']} student(s) in {$class->name} ({$class->join_code}); {$result['already']} already in it.");

        if ($result['created'] > 0) {
            $this->info("{$result['created']} of them haven't signed in yet; their account links at their first Google sign-in.");
        }

        foreach ($result['skipped'] as $message) {
            $this->warn($message);
        }

        return $result['skipped'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
