<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TeacherEmail;
use App\Models\User;
use DomainException;
use Google\Client as GoogleClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use LogicException;
use Psr\Http\Client\ClientExceptionInterface;
use UnexpectedValueException;

// This is the ONE point of contact between the app and the network
// (ARCHITECTURE.md Ch.4): the app logs in here once, caches the token
// locally, and never needs this endpoint again until the token expires
// (semester-length, per Ch.4). Everything else stays local-first.
class AuthController extends Controller
{
    private const MOBILE_TOKEN_NAME = 'classsync-mobile';

    /** About a semester: the app runs offline on this token. */
    private const GOOGLE_TOKEN_LIFETIME_MONTHS = 6;

    public function register(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', Rule::in(['teacher', 'student'])],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'role' => $data['role'],
        ]);

        $token = $user->createToken(self::MOBILE_TOKEN_NAME)->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $this->userPayload($user),
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();
        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        $token = $user->createToken(self::MOBILE_TOKEN_NAME)->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $this->userPayload($user),
        ]);
    }

    /**
     * Google sign-in: the app sends a Google ID token, we verify it with
     * Google's public certs and decide the role from the teacher_emails
     * allow-list. The client never sends a role, and nothing in the request
     * besides the signed token is trusted.
     */
    public function google(Request $request, GoogleClient $google): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'id_token' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Google sign-in did not complete. Try again.',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Without a client ID the library skips the audience check, which
        // would accept tokens minted for any app. Refuse instead.
        if (blank(config('services.google.client_id'))) {
            Log::error('Google sign-in attempted but GOOGLE_CLIENT_ID is not set.');

            return response()->json(['message' => 'Google sign-in is not available right now. Please try again later.'], 503);
        }

        try {
            $claims = $google->verifyIdToken($validator->validated()['id_token']);
        } catch (ClientExceptionInterface $e) {
            Log::warning('Could not fetch Google signing certificates.', ['error' => $e->getMessage()]);

            return response()->json(['message' => 'Could not reach Google to check your sign-in. Try again in a moment.'], 503);
        } catch (UnexpectedValueException|DomainException|InvalidArgumentException|LogicException) {
            $claims = false;
        }

        if (! is_array($claims) || blank($claims['sub'] ?? null) || blank($claims['email'] ?? null)) {
            return response()->json(['message' => 'Google sign-in could not be verified. Try again.'], 401);
        }

        if (! filter_var($claims['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return response()->json(['message' => 'Your Google account email is not verified. Verify it with Google, then try again.'], 403);
        }

        // Comma-separated, e.g. "school.edu,students.school.edu". `hd` is the
        // account's Workspace primary domain; personal Gmail has none.
        $allowedDomains = array_filter(array_map(
            fn (string $domain) => mb_strtolower(trim($domain)),
            explode(',', (string) config('services.google.allowed_domain')),
        ));

        if ($allowedDomains !== [] && ! in_array(mb_strtolower((string) ($claims['hd'] ?? '')), $allowedDomains, true)) {
            return response()->json(['message' => 'Use your school Google account.'], 403);
        }

        $googleId = (string) $claims['sub'];
        $email = TeacherEmail::normalize($claims['email']);
        $name = filled($claims['name'] ?? null) ? $claims['name'] : $email;
        // Admins sign in as teachers: their phone must never lock.
        $role = TeacherEmail::isTeacher($email) || User::isAdminEmail($email) ? 'teacher' : 'student';

        $user = DB::transaction(function () use ($googleId, $email, $name, $role) {
            $user = User::where('google_id', $googleId)->first();

            if ($user) {
                // The Google account's address changed; follow it unless
                // another account already owns the new address.
                if ($user->email !== $email && ! User::where('email', $email)->exists()) {
                    $user->email = $email;
                }
            } else {
                $user = User::where('email', $email)->first() ?? new User(['email' => $email]);

                // An existing user already linked to a *different* Google
                // account (e.g. a recycled school address) is not taken over.
                if ($user->google_id !== null) {
                    return null;
                }

                $user->google_id = $googleId;
                $user->email_verified_at ??= now();
            }

            $user->name = $name;
            $user->role = $role;
            $user->save();

            // One live token per user for this app; the new one lasts the
            // semester the app runs offline on it.
            $user->tokens()->where('name', self::MOBILE_TOKEN_NAME)->delete();

            return $user;
        });

        if (! $user) {
            return response()->json(['message' => 'This email is already linked to a different Google account. Ask your school admin for help.'], 409);
        }

        $token = $user->createToken(self::MOBILE_TOKEN_NAME, ['*'], now()->addMonths(self::GOOGLE_TOKEN_LIFETIME_MONTHS))->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $this->userPayload($user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->userPayload($request->user())]);
    }

    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'is_admin' => $user->isAdmin(),
        ];
    }
}
