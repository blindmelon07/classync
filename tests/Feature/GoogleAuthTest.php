<?php

namespace Tests\Feature;

use App\Models\ClassRoom;
use App\Models\TeacherEmail;
use App\Models\User;
use App\Services\Roster;
use Firebase\JWT\JWT;
use Google\Auth\Cache\MemoryCacheItemPool;
use Google\Client as GoogleClient;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Runs the real google/apiclient verification (signature, aud, iss, exp)
 * against tokens signed with a throwaway key. Only the HTTP call for
 * Google's public certs is faked, so nothing touches the network.
 */
class GoogleAuthTest extends TestCase
{
    use RefreshDatabase;

    private const CLIENT_ID = 'test-web-client.apps.googleusercontent.com';

    private const KEY_ID = 'test-kid';

    /** Test-only RSA key standing in for Google's signing key. Not a secret. */
    private const PRIVATE_KEY = <<<'PEM'
        -----BEGIN PRIVATE KEY-----
        MIIEvAIBADANBgkqhkiG9w0BAQEFAASCBKYwggSiAgEAAoIBAQCEyNURPaO5m7Sn
        cEO4YA0tfOXPi3pWysXCqre6wSs+04nrkQiT0BuMMayB8JfE7sLv1SHt0Ydhyf0A
        P7WjCOi2zZGQAaAXX27ps3qDFYJv6+ARonee+wNPyYTvNKooSUO3bvmdg+F8uTtF
        vvmjzA8MewLOTH2zSYwUX/IWSZ83oYLTL4m5Q8xWYguu+0KJ4Wdz3FsUXuOCn2qb
        jbsE844yN+SUfA7HeqjPWlgUb+m21DfTjF56KAZr1gQgK7vTdsSs6lcyHm3GBAcj
        I+2GQ/dPLArpxd7aF0q949jITvndk661M29bEvgygRTnLq/nCfWXmYb5ZLvvC2TA
        Uy9KZb5JAgMBAAECggEAAn8EAl72RPb4Xm1t2Hl1xfUjHNOyaQ9GyHINIiWfen7f
        iN4hGyY+XlRQueCb+cfDQl7vVFksAlqZtvd7oqT4OveCVQKyz72riBknpG8zFIeK
        nso/FW4Cke1n4ldLG5bE/x3G838XLhENXDJK3xlm7wUg+F/XvRcU2w0yr9iSdak8
        rYejPEfOBulqZzdchW0JA4gh+eYy9+QVqEn0wiCdKn53drffLwX/M9Y8SMmf+SMz
        tAiJ0QYmLxdYo7ojI3q44XseIpqnxLugzZhznVKDalG6lEErHty8EDe0JhYDV/rQ
        mgrZB2qJ8RQsDAHvp4aNahkzor4kColikqETbJjLQQKBgQC7gssSrOUvS3PGLA7/
        jVMco7Ue1LnbEloqqXZhFtQ3VvJItiqp8y4Cmeixt3JpJM//yPzQC2V0iKVfNnlU
        4Hp4yxtLR8+hMiP/Tfvvuidf7CbtajZyrLNe4Wkiho91JeHWGEQFsIyLQj43WNhl
        B5PvOUY1yBQn0Q4JoraYjBu3QwKBgQC1SNirbdDV1DB95kvmkC4zbCy9fR1fRuir
        Fr4y2Q7c3C3t6G4W7dIBQZzYb7XdFlf+BLE/+mdOThfD29I45iGfybSHZtGhe+WH
        lBB3nEr7yQQY7ih8y1etU2KIK7BCpCEaGOTM75oXaFdpJ7D1yjknet9sofwNU7gj
        bKVOFYQ9gwKBgFSqwjc0imfIkgYxbrRFg/mykd3R//nDV6Nb0XAVds1mHRBn8Ou8
        OlJCXKeiRa7kSGcewcjO3Ii6CrHrTu3cTnCshS6Axmfq1AY7mD6ut4jAgPNCukMd
        aAC3l1lXmP80k7ywSEapaUyYJK+pFkzIFyw1mFZAeZlg9A21wu0ulnUxAoGAdGV8
        cL2Gy/R82ilm7HgAohW/uD7AAC/ILinhH0bMyzQ37TxCi0hRgWr+aN15GKZDAx9C
        K4D8mYN8sM3QcaYZSr44woNa7+NcIawI0rOwVW/gyJ0Js+7fsbMLXcEnX/KAKoB3
        T7o75vGgxiys63PXNKkpEVgEPQ5W+a/Fh5g7Gz8CgYAcE59rnNV5ZFjhuaD2/Kjd
        uqi6pCV7hk53WT4fNSjM2N1T/MRxm3D00rEnT9QcD/00tHxMuEldWqatXdNecvMI
        Dtp0m0sVZGnevRI2Hta2Bw6+/Ybnx6BmhsKkplaB9rlWBMU70kKSNw+105+vUIai
        s+mxtOEx27AIa8EVPim6Gg==
        -----END PRIVATE KEY-----
        PEM;

    /** How many times the fake Google cert endpoint was hit. */
    private int $certFetches = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.google.client_id' => self::CLIENT_ID,
            'services.google.allowed_domain' => null,
        ]);

        $rsa = openssl_pkey_get_details(openssl_pkey_get_private(self::PRIVATE_KEY))['rsa'];
        $jwks = json_encode(['keys' => [[
            'kty' => 'RSA',
            'alg' => 'RS256',
            'use' => 'sig',
            'kid' => self::KEY_ID,
            'n' => JWT::urlsafeB64Encode($rsa['n']),
            'e' => JWT::urlsafeB64Encode($rsa['e']),
        ]]]);

        // One cache shared across requests, like the file cache in production.
        $certCache = new MemoryCacheItemPool;

        $this->app->extend(GoogleClient::class, function (GoogleClient $client) use ($jwks, $certCache) {
            $client->setCache($certCache);
            $client->setHttpClient(new HttpClient(['handler' => HandlerStack::create(function () use ($jwks) {
                $this->certFetches++;

                return Create::promiseFor(new Response(200, ['Content-Type' => 'application/json'], $jwks));
            })]));

            return $client;
        });
    }

    public function test_email_on_the_teacher_list_signs_in_as_teacher(): void
    {
        TeacherEmail::create(['email' => 'Ms.Rivera@School.edu']);

        $response = $this->postJson('/api/auth/google', [
            'id_token' => $this->idToken(['email' => 'ms.rivera@school.edu', 'name' => 'Ana Rivera']),
        ]);

        $response->assertOk()
            ->assertJsonPath('user.name', 'Ana Rivera')
            ->assertJsonPath('user.role', 'teacher')
            ->assertJsonStructure(['token', 'user' => ['name', 'role']]);

        $this->withToken($response->json('token'))
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('user.role', 'teacher');
    }

    public function test_any_other_email_signs_in_as_student(): void
    {
        TeacherEmail::create(['email' => 'teacher@school.edu']);

        $this->postJson('/api/auth/google', ['id_token' => $this->idToken()])
            ->assertOk()
            ->assertJsonPath('user.name', 'Sam Student')
            ->assertJsonPath('user.role', 'student');

        $this->assertDatabaseHas('users', [
            'email' => 'student@example.com',
            'google_id' => '1001',
            'role' => 'student',
            'password' => null,
        ]);
    }

    public function test_admin_email_signs_in_as_teacher_with_admin_flag(): void
    {
        config(['services.google.admin_emails' => 'other@school.edu, Student@Example.com']);

        $this->postJson('/api/auth/google', ['id_token' => $this->idToken()])
            ->assertOk()
            ->assertJsonPath('user.role', 'teacher')
            ->assertJsonPath('user.is_admin', true);
    }

    public function test_student_enrolled_before_signing_in_is_linked_and_stays_in_the_class(): void
    {
        $teacher = User::factory()->teacher()->create();
        $class = ClassRoom::create(['teacher_id' => $teacher->id, 'name' => 'Algebra 7A', 'join_code' => 'ABC123']);
        app(Roster::class)->enrollStudents($class, ['student@example.com']);

        $this->postJson('/api/auth/google', ['id_token' => $this->idToken()])
            ->assertOk()
            ->assertJsonPath('user.name', 'Sam Student')
            ->assertJsonPath('user.role', 'student')
            ->assertJsonPath('user.is_admin', false);

        $this->assertDatabaseCount('users', 2);
        $this->assertSame(['Sam Student'], $class->students()->pluck('name')->all());
    }

    public function test_token_lasts_about_a_semester(): void
    {
        $this->postJson('/api/auth/google', ['id_token' => $this->idToken()])->assertOk();

        $expiresAt = User::firstWhere('google_id', '1001')->tokens()->sole()->expires_at;

        $this->assertTrue($expiresAt->between(now()->addMonths(6)->subMinute(), now()->addMonths(6)->addMinute()));
    }

    public function test_name_falls_back_to_email(): void
    {
        $this->postJson('/api/auth/google', ['id_token' => $this->idToken(['name' => null])])
            ->assertOk()
            ->assertJsonPath('user.name', 'student@example.com');
    }

    public function test_garbage_token_is_rejected(): void
    {
        $this->postJson('/api/auth/google', ['id_token' => 'not-a-jwt'])
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Google sign-in could not be verified. Try again.']);
    }

    public function test_tampered_token_is_rejected(): void
    {
        [$header, , $signature] = explode('.', $this->idToken());
        $forgedClaims = JWT::urlsafeB64Encode(json_encode(
            $this->claims(['email' => 'teacher@school.edu']),
        ));

        TeacherEmail::create(['email' => 'teacher@school.edu']);

        $this->postJson('/api/auth/google', ['id_token' => "{$header}.{$forgedClaims}.{$signature}"])
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Google sign-in could not be verified. Try again.');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_token_for_another_app_is_rejected(): void
    {
        $this->postJson('/api/auth/google', ['id_token' => $this->idToken(['aud' => 'some-other-app.apps.googleusercontent.com'])])
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Google sign-in could not be verified. Try again.');
    }

    public function test_token_from_another_issuer_is_rejected(): void
    {
        $this->postJson('/api/auth/google', ['id_token' => $this->idToken(['iss' => 'https://evil.example.com'])])
            ->assertUnauthorized();
    }

    public function test_expired_token_is_rejected(): void
    {
        $this->postJson('/api/auth/google', ['id_token' => $this->idToken(['iat' => time() - 7200, 'exp' => time() - 3600])])
            ->assertUnauthorized();
    }

    public function test_unverified_email_is_rejected(): void
    {
        $this->postJson('/api/auth/google', ['id_token' => $this->idToken(['email_verified' => false])])
            ->assertForbidden()
            ->assertJsonPath('message', 'Your Google account email is not verified. Verify it with Google, then try again.');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_wrong_domain_is_rejected_when_domain_restriction_is_on(): void
    {
        config(['services.google.allowed_domain' => 'school.edu']);

        $this->postJson('/api/auth/google', ['id_token' => $this->idToken(['email' => 'kid@other.edu', 'hd' => 'other.edu'])])
            ->assertForbidden()
            ->assertExactJson(['message' => 'Use your school Google account.']);

        // A personal Gmail account has no `hd` claim at all.
        $this->postJson('/api/auth/google', ['id_token' => $this->idToken(['email' => 'kid@gmail.com'])])
            ->assertForbidden();

        $this->postJson('/api/auth/google', ['id_token' => $this->idToken(['email' => 'kid@school.edu', 'hd' => 'school.edu'])])
            ->assertOk();

        $this->assertDatabaseCount('users', 1);
    }

    public function test_domain_restriction_accepts_a_list_of_domains(): void
    {
        config(['services.google.allowed_domain' => 'school.edu, Students.School.edu']);

        $this->postJson('/api/auth/google', ['id_token' => $this->idToken(['email' => 'kid@students.school.edu', 'hd' => 'students.school.edu'])])
            ->assertOk();

        $this->postJson('/api/auth/google', ['id_token' => $this->idToken(['sub' => '1002', 'email' => 'ana@school.edu', 'hd' => 'school.edu'])])
            ->assertOk();

        $this->postJson('/api/auth/google', ['id_token' => $this->idToken(['sub' => '1003', 'email' => 'kid@other.edu', 'hd' => 'other.edu'])])
            ->assertForbidden();
    }

    public function test_missing_id_token_is_a_validation_error(): void
    {
        $this->postJson('/api/auth/google', [])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Google sign-in did not complete. Try again.');
    }

    public function test_refuses_to_run_without_a_client_id(): void
    {
        config(['services.google.client_id' => null]);

        $this->postJson('/api/auth/google', ['id_token' => $this->idToken()])
            ->assertServiceUnavailable()
            ->assertJsonStructure(['message']);
    }

    public function test_signing_in_twice_reuses_one_user_and_revokes_the_old_token(): void
    {
        $first = $this->postJson('/api/auth/google', ['id_token' => $this->idToken()])->assertOk();
        $second = $this->postJson('/api/auth/google', ['id_token' => $this->idToken(['name' => 'Samantha Student'])])->assertOk();

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseHas('users', ['google_id' => '1001', 'name' => 'Samantha Student']);
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertNotSame($first->json('token'), $second->json('token'));

        // Google's certs were fetched once and served from cache after that.
        $this->assertSame(1, $this->certFetches);
    }

    public function test_links_an_existing_account_with_the_same_email(): void
    {
        $existing = User::factory()->create(['email' => 'student@example.com']);

        $this->postJson('/api/auth/google', ['id_token' => $this->idToken()])->assertOk();

        $this->assertDatabaseCount('users', 1);
        $this->assertSame('1001', $existing->fresh()->google_id);
    }

    public function test_does_not_take_over_an_email_linked_to_another_google_account(): void
    {
        User::factory()->create(['email' => 'student@example.com', 'google_id' => '999']);

        $this->postJson('/api/auth/google', ['id_token' => $this->idToken()])
            ->assertConflict()
            ->assertJsonStructure(['message']);

        $this->assertDatabaseMissing('users', ['google_id' => '1001']);
    }

    public function test_removing_an_email_from_the_list_demotes_the_user_on_next_sign_in(): void
    {
        TeacherEmail::create(['email' => 'teacher@school.edu']);
        $token = $this->idToken(['email' => 'teacher@school.edu']);

        $this->postJson('/api/auth/google', ['id_token' => $token])->assertJsonPath('user.role', 'teacher');

        $this->artisan('teachers:remove', ['emails' => ['teacher@school.edu']])->assertSuccessful();

        $this->postJson('/api/auth/google', ['id_token' => $token])->assertJsonPath('user.role', 'student');
        $this->assertDatabaseHas('users', ['email' => 'teacher@school.edu', 'role' => 'student']);
    }

    /**
     * @param  array<string, mixed>  $overrides  A null value removes the claim.
     * @return array<string, mixed>
     */
    private function claims(array $overrides = []): array
    {
        $claims = array_merge([
            'iss' => 'https://accounts.google.com',
            'aud' => self::CLIENT_ID,
            'sub' => '1001',
            'email' => 'student@example.com',
            'email_verified' => true,
            'name' => 'Sam Student',
            'iat' => time(),
            'exp' => time() + 3600,
        ], $overrides);

        return array_filter($claims, fn (mixed $value) => $value !== null);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function idToken(array $overrides = []): string
    {
        return JWT::encode($this->claims($overrides), self::PRIVATE_KEY, 'RS256', self::KEY_ID);
    }
}
