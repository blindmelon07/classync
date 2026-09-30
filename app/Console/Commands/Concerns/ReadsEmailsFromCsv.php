<?php

namespace App\Console\Commands\Concerns;

trait ReadsEmailsFromCsv
{
    /**
     * Every cell that looks like an email, or null (after reporting why)
     * when the file can't be read or holds no emails.
     *
     * @return list<string>|null
     */
    protected function emailsFromCsv(string $path): ?array
    {
        if (! is_file($path) || ! is_readable($path)) {
            $this->error("Cannot read file: {$path}");

            return null;
        }

        $handle = fopen($path, 'r');
        $emails = [];

        while (($row = fgetcsv($handle, escape: '')) !== false) {
            foreach ($row as $cell) {
                // Strip a UTF-8 BOM (Excel adds one) and surrounding space.
                $cell = trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $cell));

                // Anything with an @ is meant to be an email; the caller
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

            return null;
        }

        return $emails;
    }
}
