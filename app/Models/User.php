<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'google_id', 'password', 'role'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public function isTeacher(): bool
    {
        return $this->role === 'teacher';
    }

    public function isStudent(): bool
    {
        return $this->role === 'student';
    }

    /**
     * Admins are the ADMIN_EMAILS in .env, checked on every request so
     * removing an email takes effect immediately.
     */
    public function isAdmin(): bool
    {
        return static::isAdminEmail($this->email);
    }

    public static function isAdminEmail(string $email): bool
    {
        $admins = array_map(
            fn (string $admin) => TeacherEmail::normalize($admin),
            explode(',', (string) config('services.google.admin_emails')),
        );

        return in_array(TeacherEmail::normalize($email), array_filter($admins), true);
    }

    /** Classes this user teaches. */
    public function teachingClasses(): HasMany
    {
        return $this->hasMany(ClassRoom::class, 'teacher_id');
    }

    /** Classes this user has joined as a student. */
    public function joinedClasses(): BelongsToMany
    {
        return $this->belongsToMany(ClassRoom::class, 'class_student', 'student_id', 'class_id')
            ->withPivot('joined_at');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
