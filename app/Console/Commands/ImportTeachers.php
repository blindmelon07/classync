<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ReadsEmailsFromCsv;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;

#[Signature('teachers:import {path : CSV file; every cell that looks like an email is imported}')]
#[Description('Add teacher emails from a CSV file (header rows and name columns are ignored)')]
class ImportTeachers extends AddTeachers
{
    use ReadsEmailsFromCsv;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $emails = $this->emailsFromCsv($this->argument('path'));

        return $emails === null ? self::FAILURE : $this->addEmails($emails);
    }
}
