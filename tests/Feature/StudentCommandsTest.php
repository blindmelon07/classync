<?php

namespace Tests\Feature;

use App\Models\ClassRoom;
use App\Models\TeacherEmail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentCommandsTest extends TestCase
{
    use RefreshDatabase;

    private ClassRoom $class;

    protected function setUp(): void
    {
        parent::setUp();

        $this->class = ClassRoom::create([
            'teacher_id' => User::factory()->teacher()->create()->id,
            'name' => 'Algebra 7A',
            'join_code' => 'ABC123',
        ]);
    }

    public function test_enroll_adds_existing_students_and_creates_placeholders(): void
    {
        $existing = User::factory()->create(['email' => 'ana@school.edu']);

        $this->artisan('students:enroll', ['class' => ' abc123 ', 'emails' => ['ANA@school.edu', ' ben@school.edu ']])
            ->expectsOutput('Enrolled 2 student(s) in Algebra 7A (ABC123); 0 already in it.')
            ->expectsOutput("1 of them haven't signed in yet; their account links at their first Google sign-in.")
            ->assertSuccessful();

        $this->assertSame(['ana@school.edu', 'ben@school.edu'], $this->class->students()->orderBy('email')->pluck('email')->all());
        $this->assertDatabaseHas('users', ['email' => 'ben@school.edu', 'role' => 'student', 'google_id' => null, 'password' => null]);
        $this->assertTrue($this->class->students()->whereKey($existing->id)->exists());
    }

    public function test_enroll_skips_students_already_in_the_class(): void
    {
        $this->artisan('students:enroll', ['class' => 'ABC123', 'emails' => ['ana@school.edu']])->assertSuccessful();

        $this->artisan('students:enroll', ['class' => 'ABC123', 'emails' => ['ana@school.edu']])
            ->expectsOutput('Enrolled 0 student(s) in Algebra 7A (ABC123); 1 already in it.')
            ->assertSuccessful();

        $this->assertSame(1, $this->class->students()->count());
    }

    public function test_enroll_skips_teachers_and_invalid_emails_and_fails(): void
    {
        TeacherEmail::create(['email' => 'listed@school.edu']);
        User::factory()->teacher()->create(['email' => 'teacher@school.edu']);

        $this->artisan('students:enroll', ['class' => 'ABC123', 'emails' => ['listed@school.edu', 'teacher@school.edu', 'nope', 'ana@school.edu']])
            ->expectsOutput('Enrolled 1 student(s) in Algebra 7A (ABC123); 0 already in it.')
            ->expectsOutput('Skipped listed@school.edu: on the teacher list')
            ->expectsOutput('Skipped teacher@school.edu: account is a teacher')
            ->expectsOutput('Skipped invalid email: nope')
            ->assertFailed();

        $this->assertSame(['ana@school.edu'], $this->class->students()->pluck('email')->all());
        $this->assertDatabaseMissing('users', ['email' => 'listed@school.edu']);
    }

    public function test_enroll_fails_on_unknown_class(): void
    {
        $this->artisan('students:enroll', ['class' => 'ZZZZZZ', 'emails' => ['ana@school.edu']])
            ->expectsOutput('No class found with join code: ZZZZZZ')
            ->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'ana@school.edu']);
    }

    public function test_import_enrolls_every_email_cell_from_a_csv(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'students');
        file_put_contents($path, "\xEF\xBB\xBFName,Email\nAna Rivera,Ana@School.edu\n\"Ben, Jr.\", ben@school.edu \nNo email,\n");

        $this->artisan('students:import', ['class' => 'ABC123', 'path' => $path])
            ->expectsOutput('Enrolled 2 student(s) in Algebra 7A (ABC123); 0 already in it.')
            ->assertSuccessful();

        unlink($path);

        $this->assertSame(['ana@school.edu', 'ben@school.edu'], $this->class->students()->orderBy('email')->pluck('email')->all());
    }

    public function test_import_fails_on_missing_file(): void
    {
        $this->artisan('students:import', ['class' => 'ABC123', 'path' => 'does-not-exist.csv'])->assertFailed();
    }

    public function test_remove_takes_students_out_but_keeps_their_accounts(): void
    {
        $this->artisan('students:enroll', ['class' => 'ABC123', 'emails' => ['ana@school.edu']])->assertSuccessful();

        $this->artisan('students:remove', ['class' => 'ABC123', 'emails' => ['ANA@school.edu', 'nobody@school.edu']])
            ->expectsOutput('Removed ana@school.edu')
            ->expectsOutput('Not in this class: nobody@school.edu')
            ->assertSuccessful();

        $this->assertSame(0, $this->class->students()->count());
        $this->assertDatabaseHas('users', ['email' => 'ana@school.edu']);
    }

    public function test_list_a_class_and_every_student(): void
    {
        $this->artisan('students:enroll', ['class' => 'ABC123', 'emails' => ['ana@school.edu']])->assertSuccessful();

        $this->artisan('students:list', ['class' => 'ABC123'])
            ->expectsOutputToContain('ana@school.edu')
            ->expectsOutput('1 student(s) in Algebra 7A (ABC123).')
            ->assertSuccessful();

        $this->artisan('students:list')
            ->expectsOutputToContain('ABC123')
            ->expectsOutput('1 student(s).')
            ->assertSuccessful();
    }

    public function test_classes_list_shows_join_codes(): void
    {
        $this->artisan('classes:list')
            ->expectsOutputToContain('ABC123')
            ->expectsOutput('1 class(es).')
            ->assertSuccessful();
    }
}
