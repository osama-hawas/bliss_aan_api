<?php

namespace Tests\Feature\Database;

use App\Enums\AdminRole;
use App\Enums\OtpPurpose;
use App\Enums\UserStatus;
use App\Models\User;
use App\Models\UserDevice;
use Symfony\Component\Process\Process;
use Tests\Concerns\BuildsApplications;
use Tests\Concerns\InteractsWithOtp;
use Tests\Concerns\UsesCommittedRows;
use Tests\TestCase;

/**
 * D173 (review M1, owner requirement 2026-11-16): login and suspension serialize on the same `users` row, so no session
 * and no device token survive a suspension race. A real second MySQL session (tests/Support/sql_session_worker.php)
 * plays the other side and holds the user-row lock for two seconds:
 *
 * - suspension first: the worker runs the locked section of a suspension (lock the row → status suspended → revoke every
 *   open device row) while the real login (`POST auth/otp/verify`) runs → login waits, then answers 403;
 * - login first: the worker runs the locked section of `AuthService::login` (lock the row → last_login_at → insert an
 *   open device row with an FCM token) while the real client suspension (`POST admin/clients/{id}/suspend`) runs →
 *   suspension waits, then revokes the new session.
 */
class LoginSuspensionRaceTest extends TestCase
{
    use BuildsApplications, InteractsWithOtp, UsesCommittedRows;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCommittedRows();
        $this->configureOtp();
    }

    protected function tearDown(): void
    {
        $this->tearDownCommittedRows();
        parent::tearDown();
    }

    /**
     * Starts the worker and waits until it holds the user row (it then sleeps before committing).
     *
     * @param  list<array<int, mixed>>  $steps
     */
    private function startWorker(array $steps): Process
    {
        $file = tempnam(sys_get_temp_dir(), 'sql');
        file_put_contents($file, json_encode($steps));
        $connection = config('database.connections.'.config('database.default'));
        $process = new Process([PHP_BINARY, base_path('tests/Support/sql_session_worker.php'), $file], base_path(), [
            'DB_HOST' => (string) $connection['host'], 'DB_PORT' => (string) $connection['port'], 'DB_DATABASE' => (string) $connection['database'],
            'DB_USERNAME' => (string) $connection['username'], 'DB_PASSWORD' => (string) ($connection['password'] ?? ''),
        ], null, 60);
        $process->start();
        usleep(700_000); // the worker has taken the row lock

        return $process;
    }

    private function finish(Process $process): void
    {
        $process->wait();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $this->assertTrue(json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR)['ok'], $process->getOutput());
    }

    /**
     * The locked section of a suspension (client: `ClientAccounts::suspend`; staff: S14b `StaffManagement::suspend`).
     *
     * @return list<array<int, mixed>>
     */
    private function suspensionHoldingTheRow(User $user): array
    {
        return [
            ['sql', 'start transaction'],
            ['sql', 'select id from users where id = ? for update', [$user->id]],
            ['sql', "update users set status = 'suspended', status_reason = 'Fraud review' where id = ?", [$user->id]],
            ['sql', 'update user_devices set revoked_at = now(), fcm_token = null where user_id = ? and revoked_at is null', [$user->id]],
            ['sleep', 2],
            ['sql', 'commit'],
        ];
    }

    private function assertNoSessionSurvives(User $user): void
    {
        $this->assertSame(0, UserDevice::query()->where('user_id', $user->id)->whereNull('revoked_at')->count(), 'an open session survived');
        $this->assertSame(0, UserDevice::query()->where('user_id', $user->id)->whereNotNull('fcm_token')->count(), 'a device token survived');
        $this->assertSame(UserStatus::Suspended, $user->refresh()->status);
    }

    private function verify(User $user, string $app)
    {
        $this->plantOtp($user->phone, OtpPurpose::Login);

        return $this->postJson('/api/v1/auth/otp/verify', [
            'phone' => $user->phone, 'purpose' => 'login', 'code' => '1234', 'app' => $app,
            'device' => ['platform' => 'ios', 'device_name' => 'race', 'fcm_token' => 'fcm-race'],
        ]);
    }

    public function test_client_login_during_a_suspension_waits_then_is_refused(): void
    {
        $client = User::factory()->client()->create();
        $this->tokenFor($client); // an existing session, revoked by the suspension

        $worker = $this->startWorker($this->suspensionHoldingTheRow($client));
        $started = microtime(true);
        $this->verify($client, 'client')
            ->assertForbidden()
            ->assertJsonPath('code', 'ACCOUNT_SUSPENDED')
            ->assertJsonPath('errors.reason', 'Fraud review');
        $this->assertGreaterThan(0.8, microtime(true) - $started, 'login did not wait for the suspension');
        $this->finish($worker);

        $this->assertNoSessionSurvives($client);
    }

    public function test_staff_dashboard_login_during_a_suspension_waits_then_is_refused(): void
    {
        $staff = User::factory()->admin()->create();
        $this->tokenFor($staff);

        $worker = $this->startWorker($this->suspensionHoldingTheRow($staff));
        $this->verify($staff, 'dashboard')
            ->assertForbidden()
            ->assertJsonPath('code', 'ACCOUNT_SUSPENDED');
        $this->finish($worker);

        $this->assertNoSessionSurvives($staff);
    }

    public function test_a_client_suspension_after_a_login_holding_the_row_revokes_the_new_session(): void
    {
        $admin = $this->staff(AdminRole::AccountsOfficer);
        $client = User::factory()->client()->create();

        // The worker is the login: it holds her row and opens a session with a push token (`AuthService::login`).
        $worker = $this->startWorker([
            ['sql', 'start transaction'],
            ['sql', 'select id from users where id = ? for update', [$client->id]],
            ['sql', 'update users set last_login_at = now() where id = ?', [$client->id]],
            ['sql', "insert into user_devices (user_id, jti, app, platform, fcm_token, last_seen_at, created_at, updated_at) values (?, ?, 'client', 'ios', 'fcm-new', now(), now(), now())", [$client->id, 'race-'.uniqid()]],
            ['sleep', 2],
            ['sql', 'commit'],
        ]);
        $started = microtime(true);
        $this->actingAsApi($admin)->postJson("/api/v1/admin/clients/{$client->id}/suspend", ['reason' => 'Fraud review'])
            ->assertOk()
            ->assertJsonPath('data.status', 'suspended')
            ->assertJsonPath('data.active_sessions', 0)
            ->assertJsonPath('data.status_logs.0.sessions_revoked', 1);
        $this->assertGreaterThan(0.8, microtime(true) - $started, 'suspension did not wait for the login');
        $this->finish($worker);

        $this->assertNoSessionSurvives($client);
        $this->assertNotNull(UserDevice::query()->where('user_id', $client->id)->sole()->revoked_at);
    }
}
