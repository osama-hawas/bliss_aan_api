<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

abstract class TestCase extends BaseTestCase
{
    /**
     * Send requests with a real JWT for this user (guard `api`).
     * Clears the guard and the parsed-token cache so several requests in one test can
     * act as different users, like separate HTTP requests do in production.
     */
    protected function actingAsApi(User $user): static
    {
        $this->app['tymon.jwt']->unsetToken();
        $this->app['auth']->forgetGuards();

        return $this->withToken($this->tokenFor($user));
    }

    protected function tokenFor(User $user): string
    {
        return JWTAuth::fromUser($user);
    }
}
