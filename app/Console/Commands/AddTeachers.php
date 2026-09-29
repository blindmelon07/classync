<?php

namespace App\Console\Commands;

use App\Models\TeacherEmail;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('teachers:add {emails* : One or more teacher email addresses}')]
#[Description('Add emails to the teacher list (takes effect at their next Google sign-in)')]
class AddTeachers extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        return $this->addEmails($this->argument('emails'));
    }

    /**
     * @param  list<string>  $emails
     */
    protected function addEmails(array $emails): int
    {
        $added = 0;
        $existing = 0;
        $invalid = [];

        foreach ($emails as $email) {
            $email = TeacherEmail::normalize($email);

            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $invalid[] = $email;

                continue;
            }

            TeacherEmail::firstOrCreate(['email' => $email])->wasRecentlyCreated ? $added++ : $existing++;
        }

        $this->info("Added {$added} teacher email(s); {$existing} already on the list.");

        foreach ($invalid as $email) {
            $this->warn("Skipped invalid email: {$email}");
        }

        return $invalid === [] ? self::SUCCESS : self::FAILURE;
    }
}
