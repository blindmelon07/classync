<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ReadsEmailsFromCsv;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;

#[Signature('students:import {class : The class join code} {path : CSV file; every cell that looks like an email is enrolled}')]
#[Description('Enroll students in a class from a CSV file (header rows and name columns are ignored)')]
class ImportStudents extends EnrollStudents
{
    use ReadsEmailsFromCsv;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $class = $this->findClass();

        if (! $class) {
            return self::FAILURE;
        }

        $emails = $this->emailsFromCsv($this->argument('path'));

        return $emails === null ? self::FAILURE : $this->enroll($class, $emails);
    }
}
