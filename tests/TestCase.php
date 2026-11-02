<?php

namespace Tests;

use App\Enums\DeviceApp;
use App\Enums\UserType;
use App\Models\User;
use App\Services\Auth\DeviceSessionService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Send requests with a real JWT for this user (guard `api`).
     * Clears the guard and the parsed-token cache so several requests in one test can
     * act as different users, like separate HTTP requests do in production.
     */
    protected function actingAsApi(User $user): static
    {
        return $this->withApiToken($this->tokenFor($user));
    }

    /**
     * Send the next requests with this raw token, starting from a fresh guard.
     */
    protected function withApiToken(string $token): static
    {
        $this->app['tymon.jwt']->unsetToken();
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    /**
     * Issues a token the way login does, with its user_devices session row.
     */
    protected function tokenFor(User $user): string
    {
        $app = match ($user->type) {
            UserType::Provider => DeviceApp::Provider,
            UserType::Admin => DeviceApp::Dashboard,
            default => DeviceApp::Client,
        };

        return app(DeviceSessionService::class)->issue($user, $app)['access_token'];
    }
}
