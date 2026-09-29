<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;

#[Signature('teachers:import {path : CSV file; every cell that looks like an email is imported}')]
#[Description('Add teacher emails from a CSV file (header rows and name columns are ignored)')]
class ImportTeachers extends AddTeachers
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $path = $this->argument('path');

        if (! is_file($path) || ! is_readable($path)) {
            $this->error("Cannot read file: {$path}");

            return self::FAILURE;
        }

        $handle = fopen($path, 'r');
        $emails = [];

        while (($row = fgetcsv($handle, escape: '')) !== false) {
            foreach ($row as $cell) {
                // Strip a UTF-8 BOM (Excel adds one) and surrounding space.
                $cell = trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $cell));

                // Anything with an @ is meant to be an email; addEmails()
                // reports the ones that don't validate. Headers like "Email"
                // or name columns are skipped silently.
                if (str_contains($cell, '@')) {
                    $emails[] = $cell;
                }
            }
        }

        fclose($handle);

        if ($emails === []) {
            $this->warn('No email addresses found in the file.');

            return self::FAILURE;
        }

        return $this->addEmails($emails);
    }
}
