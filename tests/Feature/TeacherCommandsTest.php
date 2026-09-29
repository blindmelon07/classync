<?php

namespace Tests\Feature;

use App\Models\TeacherEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeacherCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_add_normalizes_and_skips_duplicates(): void
    {
        $this->artisan('teachers:add', ['emails' => ['  Ana@School.EDU ', 'ana@school.edu', 'ben@school.edu']])
            ->expectsOutput('Added 2 teacher email(s); 1 already on the list.')
            ->assertSuccessful();

        $this->assertSame(['ana@school.edu', 'ben@school.edu'], TeacherEmail::orderBy('email')->pluck('email')->all());
    }

    public function test_add_reports_invalid_emails_and_fails(): void
    {
        $this->artisan('teachers:add', ['emails' => ['ana@school.edu', 'not-an-email']])
            ->expectsOutput('Skipped invalid email: not-an-email')
            ->assertFailed();

        $this->assertDatabaseHas('teacher_emails', ['email' => 'ana@school.edu']);
        $this->assertDatabaseCount('teacher_emails', 1);
    }

    public function test_remove(): void
    {
        TeacherEmail::create(['email' => 'ana@school.edu']);

        $this->artisan('teachers:remove', ['emails' => ['ANA@school.edu', 'nobody@school.edu']])
            ->expectsOutput('Removed ana@school.edu')
            ->expectsOutput('Not on the teacher list: nobody@school.edu')
            ->assertSuccessful();

        $this->assertDatabaseCount('teacher_emails', 0);
    }

    public function test_list(): void
    {
        TeacherEmail::create(['email' => 'ana@school.edu']);

        $this->artisan('teachers:list')
            ->expectsOutputToContain('ana@school.edu')
            ->expectsOutput('1 teacher email(s).')
            ->assertSuccessful();
    }

    public function test_import_reads_every_email_cell_from_a_csv(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'teachers');
        file_put_contents($path, "\xEF\xBB\xBFName,Email\nAna Rivera,Ana@School.edu\n\"Ben, Jr.\", ben@school.edu \nNo email,\n");

        $this->artisan('teachers:import', ['path' => $path])
            ->expectsOutput('Added 2 teacher email(s); 0 already on the list.')
            ->assertSuccessful();

        unlink($path);

        $this->assertSame(['ana@school.edu', 'ben@school.edu'], TeacherEmail::orderBy('email')->pluck('email')->all());
    }

    public function test_import_fails_on_missing_file(): void
    {
        $this->artisan('teachers:import', ['path' => 'does-not-exist.csv'])->assertFailed();
    }
}
