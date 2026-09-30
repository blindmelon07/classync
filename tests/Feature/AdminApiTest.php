<?php

namespace Tests\Feature;

use App\Models\ClassRoom;
use App\Models\TeacherEmail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminApiTest extends TestCase
{
    use RefreshDatabase;

    private ClassRoom $class;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.google.admin_emails' => 'boss@school.edu']);

        $this->class = ClassRoom::create([
            'teacher_id' => User::factory()->teacher()->create(['email' => 'maria@school.edu', 'name' => 'Maria'])->id,
            'name' => 'Algebra 7A',
            'join_code' => 'ABC123',
        ]);
    }

    private function actingAsAdmin(): void
    {
        Sanctum::actingAs(User::factory()->teacher()->create(['email' => 'Boss@School.edu']));
    }

    public function test_non_admins_are_refused(): void
    {
        Sanctum::actingAs(User::factory()->teacher()->create());

        $this->getJson('/api/admin/teachers')->assertForbidden();
        $this->postJson('/api/admin/teachers', ['emails' => ['x@school.edu']])->assertForbidden();
        $this->getJson('/api/admin/classes')->assertForbidden();

        $this->assertDatabaseCount('teacher_emails', 0);
    }

    public function test_guests_are_refused(): void
    {
        $this->getJson('/api/admin/teachers')->assertUnauthorized();
    }

    public function test_removing_an_admin_email_locks_them_out_immediately(): void
    {
        $this->actingAsAdmin();
        $this->getJson('/api/admin/teachers')->assertOk();

        config(['services.google.admin_emails' => '']);

        $this->getJson('/api/admin/teachers')->assertForbidden();
    }

    public function test_me_reports_admin_flag(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/me')->assertOk()->assertJsonPath('user.is_admin', true);
    }

    public function test_manage_the_teacher_list(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/admin/teachers', ['emails' => ['Ana@School.edu', 'ana@school.edu', 'bad']])
            ->assertOk()
            ->assertExactJson(['added' => 1, 'existing' => 1, 'invalid' => ['bad']]);

        $this->getJson('/api/admin/teachers')
            ->assertOk()
            ->assertJsonCount(1, 'teachers')
            ->assertJsonPath('teachers.0.email', 'ana@school.edu');

        $this->deleteJson('/api/admin/teachers/ANA@school.edu')->assertOk();
        $this->deleteJson('/api/admin/teachers/ana@school.edu')->assertNotFound();
        $this->assertDatabaseCount('teacher_emails', 0);
    }

    public function test_add_teachers_validates_input(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/admin/teachers', ['emails' => []])->assertUnprocessable();
        $this->postJson('/api/admin/teachers', ['emails' => 'ana@school.edu'])->assertUnprocessable();
    }

    public function test_list_classes(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/admin/classes')
            ->assertOk()
            ->assertJsonPath('classes.0.join_code', 'ABC123')
            ->assertJsonPath('classes.0.teacher_email', 'maria@school.edu')
            ->assertJsonPath('classes.0.students_count', 0);
    }

    public function test_manage_a_class_roster(): void
    {
        $this->actingAsAdmin();
        TeacherEmail::create(['email' => 'listed@school.edu']);

        $this->postJson("/api/admin/classes/{$this->class->id}/students", ['emails' => ['juan@school.edu', 'listed@school.edu']])
            ->assertOk()
            ->assertJsonPath('enrolled', 1)
            ->assertJsonPath('created', 1)
            ->assertJsonPath('skipped', ['Skipped listed@school.edu: on the teacher list']);

        $response = $this->getJson("/api/admin/classes/{$this->class->id}/students")
            ->assertOk()
            ->assertJsonPath('students.0.email', 'juan@school.edu')
            ->assertJsonPath('students.0.signed_in', false);

        $studentId = $response->json('students.0.id');

        $this->deleteJson("/api/admin/classes/{$this->class->id}/students/{$studentId}")->assertOk();
        $this->deleteJson("/api/admin/classes/{$this->class->id}/students/{$studentId}")->assertNotFound();
        $this->assertSame(0, $this->class->students()->count());
    }

    public function test_admin_emails_are_never_enrolled(): void
    {
        $this->actingAsAdmin();

        $this->postJson("/api/admin/classes/{$this->class->id}/students", ['emails' => ['boss@school.edu']])
            ->assertOk()
            ->assertJsonPath('enrolled', 0)
            ->assertJsonPath('skipped', ['Skipped boss@school.edu: is an admin']);
    }
}
