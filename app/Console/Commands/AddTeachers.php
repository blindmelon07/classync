<?php

namespace App\Console\Commands;

use App\Services\Roster;
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
        $result = app(Roster::class)->addTeachers($emails);

        $this->info("Added {$result['added']} teacher email(s); {$result['existing']} already on the list.");

        foreach ($result['invalid'] as $email) {
            $this->warn("Skipped invalid email: {$email}");
        }

        return $result['invalid'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
