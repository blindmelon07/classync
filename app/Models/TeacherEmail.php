<?php

namespace App\Models;

use Database\Factories\TeacherEmailFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * An email on the teacher allow-list. The server decides roles from this
 * table on every Google sign-in; the client never sends a role.
 */
#[Fillable(['email'])]
class TeacherEmail extends Model
{
    /** @use HasFactory<TeacherEmailFactory> */
    use HasFactory;

    /**
     * The one canonical form emails are stored and compared in.
     */
    public static function normalize(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public static function isTeacher(string $email): bool
    {
        return static::where('email', static::normalize($email))->exists();
    }

    /**
     * @return Attribute<string, string>
     */
    protected function email(): Attribute
    {
        return Attribute::make(set: fn (string $value) => static::normalize($value));
    }
}
